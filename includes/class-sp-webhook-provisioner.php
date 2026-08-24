<?php
/**
 * Webhook auto-provisioning.
 *
 * Registers this site's callback URL with the payment API so the merchant never
 * has to create a webhook by hand, copy the callback URL out of the plugin, or
 * paste a signing secret back in.
 *
 * The reconcile in sync() is deliberately idempotent: saving settings twice must
 * not produce two webhooks. It lists what already exists, adopts or updates a
 * matching record, and only creates one when there is nothing to reuse.
 *
 * Nothing in here may block checkout. Every public entry point swallows its
 * failures, records them for an admin notice, and returns - payments keep working
 * even when provisioning is broken.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SP_Webhook_Provisioner {

    /** Webhook id returned by the API. */
    const OPTION_WEBHOOK_ID = 'sp_webhook_id';

    /** Signing secret. Issued once at creation (or rotate) and never readable again. */
    const OPTION_SIGNING_SECRET = 'sp_webhook_signing_secret';

    /** Callback URL we last successfully registered, so we can detect site moves. */
    const OPTION_REGISTERED_URL = 'sp_webhook_registered_url';

    /**
     * Merchant account the stored registration belongs to. Credentials can be
     * repointed at a different account; without this we would keep acting on a
     * webhook we no longer own.
     */
    const OPTION_MERCHANT_ID = 'sp_webhook_merchant_id';

    /** Last provisioning outcome, surfaced as an admin notice. */
    const OPTION_STATUS = 'sp_webhook_provision_status';

    /**
     * Status-record version. Bump whenever the stored message wording or the set of
     * states changes, so notices written by an older build are re-derived instead of
     * being shown verbatim forever.
     */
    const STATUS_SCHEMA = 2;

    /**
     * Canonical REST namespace for the callback.
     *
     * "woowh" = WooCommerce webhook. Deliberately generic: this path is visible to
     * merchants and partners, so it carries no company or product name.
     */
    const NAMESPACE_CURRENT = 'woowh/v1';

    /**
     * Namespaces kept alive purely for compatibility.
     *
     * A merchant's dashboard holds whatever callback URL was registered at the
     * time, so retiring one of these silently 404s their deliveries. sync() moves
     * them onto the canonical namespace via PUT; only remove an entry once nothing
     * points at it any more.
     */
    const NAMESPACES_LEGACY = array('stablecoin/v1');

    /** Canonical route, relative to /wp-json/. */
    const CALLBACK_ROUTE = self::NAMESPACE_CURRENT . '/webhook';

    /**
     * Canonical namespace first, then legacy ones. The handler registers each.
     *
     * @return string[]
     */
    public static function all_namespaces() {
        return array_merge(array(self::NAMESPACE_CURRENT), self::NAMESPACES_LEGACY);
    }

    /**
     * Callback URLs this site answers on but no longer advertises.
     *
     * @return string[]
     */
    public static function legacy_callback_urls() {
        $urls = array();
        foreach (self::NAMESPACES_LEGACY as $namespace) {
            $urls[] = get_rest_url(null, $namespace . '/webhook');
        }
        return $urls;
    }

    const HTTP_TIMEOUT = 15;

    /**
     * Event types this plugin actually acts on, matching the switch in
     * SP_Webhook_Handler::process_webhook().
     *
     * The API treats an EMPTY array as "all events, including ones added later".
     * We send an explicit list instead because the handler ignores everything
     * else, and unhandled deliveries are just wasted requests and log noise.
     * Filter this if the handler grows to cover more.
     */
    private static function event_types() {
        return apply_filters('sp_webhook_event_types', array(
            'payment',
            'failed_payment',
            'cancellation',
            'transfer',
            'failed_transfer',
        ));
    }

    // ------------------------------------------------------------ entry points

    /**
     * Reconcile this site's webhook registration with the API.
     *
     * Safe to call on every settings save.
     *
     * @return array Status array (also persisted for the admin notice).
     */
    public static function sync() {
        try {
            return self::do_sync();
        } catch (Exception $e) {
            return self::fail('error', 'Webhook setup failed: ' . $e->getMessage());
        } catch (Error $e) {
            // Never let a fatal in provisioning take down the settings save.
            return self::fail('error', 'Webhook setup failed unexpectedly: ' . $e->getMessage());
        }
    }

    /**
     * Teardown path. The API has no DELETE by design - webhooks are disabled and
     * can be revived later by updating the existing record.
     */
    public static function disable() {
        $webhook_id = (int) get_option(self::OPTION_WEBHOOK_ID, 0);
        if (!$webhook_id) {
            return;
        }

        $credentials = self::credentials();
        if (!$credentials) {
            return;
        }

        try {
            self::request('POST', 'webhooks/' . $webhook_id . '/disable', null, $credentials);
            error_log('PP Webhook Provisioner: disabled webhook #' . $webhook_id);
        } catch (Exception $e) {
            // Deactivation must not be blocked by a failed API call.
            error_log('PP Webhook Provisioner: disable failed - ' . $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- reconcile

    private static function do_sync() {
        $credentials = self::credentials();
        if (!$credentials) {
            return self::fail('incomplete', 'Enter your Merchant ID and API Key to register the webhook automatically.');
        }

        if (!self::is_valid_uuid($credentials['merchant_id'])) {
            return self::fail('error', 'Merchant ID must be a valid UUID. Check the value copied from your dashboard.');
        }

        $callback_url = self::callback_url();

        // A private or local callback can never receive a delivery. Registering it
        // would only produce a webhook that fails forever, so stop with a notice.
        $unreachable = self::unreachable_reason($callback_url);
        if ($unreachable) {
            return self::fail('unreachable', $unreachable);
        }

        // 0. If these credentials point at a different merchant than the stored
        //    registration, that registration is no longer ours to touch.
        self::forget_if_merchant_changed($credentials);

        // 1. What already exists for this merchant?
        $existing = self::find_existing($callback_url, $credentials);

        // 2. A webhook already points here. Adopt it rather than creating a twin.
        if ($existing['match']) {
            return self::adopt($existing['match'], $callback_url, $credentials);
        }

        // 3. We own a webhook whose URL has drifted (site moved, staging -> prod,
        //    http -> https). Update it in place instead of creating a second one.
        if ($existing['ours']) {
            return self::update_url($existing['ours'], $callback_url, $credentials);
        }

        // 4. A webhook still points at a retired namespace on this same site.
        //    Repoint it, so the merchant migrates without any manual step and
        //    without ending up with two webhooks delivering the same events.
        if ($existing['legacy']) {
            error_log('PP Webhook Provisioner: migrating webhook from a legacy callback path to ' . $callback_url);
            return self::update_url($existing['legacy'], $callback_url, $credentials);
        }

        // 5. Nothing in the listing matched, but we may still own a webhook the
        //    listing did not show. Reuse beats creating a duplicate.
        $reused = self::try_stored_webhook($callback_url, $credentials);
        if ($reused) {
            return $reused;
        }

        // 6. Genuinely nothing to reuse - create.
        return self::create($callback_url, $credentials);
    }

    /**
     * Look through the merchant's webhooks for one pointing at this site, and for
     * the one we previously created (by stored id) whose URL may have drifted.
     */
    private static function find_existing($callback_url, $credentials) {
        $result = array('match' => null, 'ours' => null, 'legacy' => null);
        $legacy_urls = self::legacy_callback_urls();

        $response = self::request('GET', 'webhooks', null, $credentials);
        $webhooks = self::extract_list($response);

        if ($webhooks === null) {
            // We cannot tell what already exists, so creating would risk a duplicate
            // on every save. Stop instead, and report the shape so it can be fixed.
            throw new Exception(
                'Could not read the webhook list from the API, so no webhook was created (creating blindly risks duplicates). '
                . 'Contact support with this shape: ' . self::describe_shape($response)
            );
        }

        $stored_id = (int) get_option(self::OPTION_WEBHOOK_ID, 0);

        foreach ($webhooks as $webhook) {
            if (!is_array($webhook)) {
                continue;
            }
            $id  = self::extract_id($webhook);
            $url = self::extract_url($webhook);

            if ($url !== '' && self::same_url($url, $callback_url)) {
                $result['match'] = $webhook;
            }
            if ($stored_id && $id === $stored_id) {
                $result['ours'] = $webhook;
            }

            // Registered against a namespace this site still answers on, but no
            // longer advertises. Move it rather than leaving a second webhook
            // delivering to the old path.
            if ($url !== '' && $result['legacy'] === null) {
                foreach ($legacy_urls as $legacy_url) {
                    if (self::same_url($url, $legacy_url)) {
                        $result['legacy'] = $webhook;
                        break;
                    }
                }
            }
        }

        // Existing webhooks, none of which we could tie to this site. That is the
        // shape of a duplicate about to be created, so record why: usually a field
        // name we do not read yet. describe_shape() shows url/status values.
        if (!$result['match'] && !$result['ours'] && !$result['legacy'] && !empty($webhooks)) {
            error_log(sprintf(
                'PP Webhook Provisioner: %d existing webhook(s) but none matched %s. First record: %s',
                count($webhooks),
                $callback_url,
                self::describe_shape(reset($webhooks))
            ));
        }

        return $result;
    }

    /**
     * Forget a registration that belongs to a different merchant account.
     *
     * Credentials can be repointed at another account. Anything stored then refers
     * to a webhook we can no longer see or authenticate against, and worse, its id
     * could coincide with an unrelated webhook under the new account - a PUT would
     * then quietly rewrite a stranger's record.
     */
    private static function forget_if_merchant_changed($credentials) {
        $stored = (string) get_option(self::OPTION_MERCHANT_ID, '');

        if ($stored === '' || $stored === $credentials['merchant_id']) {
            return;
        }

        error_log(
            'PP Webhook Provisioner: merchant changed from ' . $stored . ' to ' . $credentials['merchant_id']
            . ' - discarding the stored registration. The webhook under the previous account is still active '
            . 'and should be disabled there.'
        );

        self::forget_registration();
    }

    private static function forget_registration() {
        delete_option(self::OPTION_WEBHOOK_ID);
        delete_option(self::OPTION_SIGNING_SECRET);
        delete_option(self::OPTION_REGISTERED_URL);
        delete_option(self::OPTION_MERCHANT_ID);
    }

    /**
     * Last line of defence before creating.
     *
     * We hold an id for this merchant but the listing did not surface it. Creating
     * now is what produces duplicates, so try to update the record we already own;
     * only fall through to create if the API says it is really gone.
     *
     * @return array|null Status array when the existing webhook was reused.
     */
    private static function try_stored_webhook($callback_url, $credentials) {
        $stored_id = self::webhook_id();
        if (!$stored_id) {
            return null;
        }

        error_log('PP Webhook Provisioner: webhook #' . $stored_id . ' not present in the listing - trying to update it before creating');

        try {
            return self::update_url(array('webhook_id' => $stored_id), $callback_url, $credentials);
        } catch (Exception $e) {
            error_log('PP Webhook Provisioner: stored webhook #' . $stored_id . ' is unusable (' . $e->getMessage() . ') - creating a new one');
            self::forget_registration();
            return null;
        }
    }

    /**
     * Reuse a webhook that already points at this site.
     *
     * The signing secret is only ever returned at creation, so if we are adopting
     * a record this install did not create (a reinstall, a restored database, a
     * merchant who set it up by hand) we have no way to read its secret back and
     * must rotate to get a usable one.
     */
    private static function adopt($webhook, $callback_url, $credentials) {
        $webhook_id = self::extract_id($webhook);
        if (!$webhook_id) {
            return self::fail('error', 'The API returned a webhook without an id. Contact support.');
        }

        update_option(self::OPTION_WEBHOOK_ID, $webhook_id, false);
        update_option(self::OPTION_REGISTERED_URL, $callback_url, false);
        update_option(self::OPTION_MERCHANT_ID, $credentials['merchant_id'], false);

        // Re-enable a previously disabled record so disconnect -> reconnect works.
        $status = isset($webhook['status']) ? (string) $webhook['status'] : '';
        if ($status !== '' && strtolower($status) !== 'active') {
            self::request('PUT', 'webhooks/' . $webhook_id, array('status' => 'active'), $credentials);
        }

        if (self::signing_secret() === '') {
            $rotated = self::request('POST', 'webhooks/' . $webhook_id . '/rotate-secret', null, $credentials);
            $secret  = self::extract_secret($rotated);
            if ($secret === '') {
                return self::fail('error', 'Reused an existing webhook but could not obtain a signing secret. Contact support.');
            }
            self::store_secret($secret);
            return self::ok('Reused the existing webhook for this site and issued a new signing secret.');
        }

        return self::ok('Webhook already registered for this site.');
    }

    private static function update_url($webhook, $callback_url, $credentials) {
        $webhook_id = self::extract_id($webhook);
        if (!$webhook_id) {
            return self::create($callback_url, $credentials);
        }

        // Partial update: omitted fields keep their current values.
        self::request('PUT', 'webhooks/' . $webhook_id, array('url' => $callback_url), $credentials);

        update_option(self::OPTION_WEBHOOK_ID, $webhook_id, false);
        update_option(self::OPTION_REGISTERED_URL, $callback_url, false);
        update_option(self::OPTION_MERCHANT_ID, $credentials['merchant_id'], false);

        // A URL change does not reissue the secret, so an install that has the id
        // but never captured a secret still needs one.
        if (self::signing_secret() === '') {
            $rotated = self::request('POST', 'webhooks/' . $webhook_id . '/rotate-secret', null, $credentials);
            $recovered = self::extract_secret($rotated);
            if ($recovered !== '') {
                self::store_secret($recovered);
            }
        }

        return self::ok('Webhook URL updated to this site.');
    }

    private static function create($callback_url, $credentials) {
        try {
            $response = self::request('POST', 'webhooks', array(
                'url'                    => $callback_url,
                'subscribed_event_types' => self::event_types(),
            ), $credentials);
        } catch (Exception $e) {
            // The create may well have landed server-side even though we never saw a
            // usable reply - a read timeout, a dropped connection, a proxy 502. Ask
            // the API what exists before declaring failure, so we neither lose a
            // webhook that was created nor create a second one on the next save.
            $recovered = self::recover_created($callback_url, $credentials, 'create failed: ' . $e->getMessage());
            if ($recovered) {
                return $recovered;
            }
            throw $e;
        }

        $webhook_id = self::extract_id($response);
        $secret     = self::extract_secret($response);

        if (!$webhook_id) {
            // A 2xx we cannot read - an empty 201 body, or a shape the spec did not
            // describe. The webhook is very likely to exist, so confirm rather than
            // reporting a failure the merchant cannot act on.
            $recovered = self::recover_created(
                $callback_url,
                $credentials,
                'create returned no readable id; shape was ' . self::describe_shape($response)
            );
            if ($recovered) {
                return $recovered;
            }

            return self::fail(
                'error',
                'The API accepted the webhook but returned no id we recognise, and no matching webhook was found afterwards. '
                . 'Check your dashboard before retrying. Contact support with this shape: '
                . self::describe_shape($response)
            );
        }

        update_option(self::OPTION_WEBHOOK_ID, $webhook_id, false);
        update_option(self::OPTION_REGISTERED_URL, $callback_url, false);
        update_option(self::OPTION_MERCHANT_ID, $credentials['merchant_id'], false);

        // The create response is normally the only one that carries the secret. If
        // this API returns it separately, recover it rather than failing and
        // leaving an orphaned webhook behind.
        if ($secret === '') {
            error_log('PP Webhook Provisioner: create returned no signing secret; shape was ' . self::describe_shape($response));
            $rotated = self::request('POST', 'webhooks/' . $webhook_id . '/rotate-secret', null, $credentials);
            $secret  = self::extract_secret($rotated);
            if ($secret === '') {
                return self::fail(
                    'error',
                    'Webhook #' . $webhook_id . ' was created but no signing secret could be obtained, so deliveries cannot be verified. Contact support.'
                );
            }
        }

        self::store_secret($secret);

        return self::ok('Webhook registered automatically. No dashboard setup needed.');
    }

    /**
     * After a create whose outcome we could not read, ask the API what actually
     * exists and adopt the webhook if it is there.
     *
     * This is what turns "the webhook was created but the plugin reported an
     * error" into a clean success, and it is also what stops a retry from creating
     * a duplicate.
     *
     * @return array|null Status array when recovered, null when there is nothing
     *                    to adopt (caller then reports the original failure).
     */
    private static function recover_created($callback_url, $credentials, $why) {
        error_log('PP Webhook Provisioner: verifying after unreadable create - ' . $why);

        try {
            $existing = self::find_existing($callback_url, $credentials);
        } catch (Exception $e) {
            error_log('PP Webhook Provisioner: verification lookup failed - ' . $e->getMessage());
            return null;
        }

        if (empty($existing['match'])) {
            return null;
        }

        error_log('PP Webhook Provisioner: webhook does exist after all - adopting it');
        return self::adopt($existing['match'], $callback_url, $credentials);
    }

    // -------------------------------------------------------------------- HTTP

    /**
     * @throws Exception with a merchant-readable message.
     */
    private static function request($method, $path, $body, $credentials) {
        $url = self::api_root()
             . '/v1/merchants/' . rawurlencode($credentials['merchant_id'])
             . '/' . ltrim($path, '/');

        $args = array(
            'method'  => $method,
            'timeout' => self::HTTP_TIMEOUT,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Merchant-ID'  => $credentials['merchant_id'],
                'API-Key'      => $credentials['api_key'],
                'Accept'       => 'application/json',
            ),
        );

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        // Never log credentials or response bodies here - a create response
        // carries the signing secret.
        error_log('PP Webhook Provisioner: ' . $method . ' ' . $url);

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new Exception('Could not reach the payment API (' . $response->get_error_message() . '). Check the site can make outbound HTTPS requests, then retry.');
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);

        if ($code >= 200 && $code < 300) {
            return is_array($json) ? $json : array();
        }

        throw new Exception(self::explain_error($code, $raw, $json));
    }

    /**
     * Turn an API failure into something a merchant can act on.
     */
    private static function explain_error($code, $raw, $json) {
        $api_message = '';
        if (is_array($json)) {
            foreach (array('message', 'error', 'detail') as $key) {
                if (!empty($json[$key]) && is_string($json[$key])) {
                    $api_message = $json[$key];
                    break;
                }
            }
        }

        $haystack = strtolower($api_message . ' ' . $raw);

        if ($code === 401 || $code === 403) {
            // 403 is either a missing scope or a Merchant-ID that disagrees with
            // the path - both are fixable by the merchant, but not the same fix.
            if (strpos($haystack, 'scope') !== false || strpos($haystack, 'permission') !== false || strpos($haystack, 'webhook') !== false) {
                return 'Your API key needs webhook permissions. Regenerate it in your dashboard with the "webhooks" scope set to write, then save again.';
            }
            if (strpos($haystack, 'mismatch') !== false || strpos($haystack, 'merchant') !== false) {
                return 'The API key does not belong to this Merchant ID. Re-copy both values from your dashboard.';
            }
            return 'The payment API rejected these credentials (HTTP ' . $code . '). Check the Merchant ID and API Key, and that the key has the "webhooks" write scope.';
        }

        if ($code === 404) {
            return 'The payment API did not recognise this merchant (HTTP 404). Check the Merchant ID.';
        }

        if ($code === 429) {
            return 'The payment API is rate limiting requests. Wait a moment and use "Retry webhook setup".';
        }

        if ($code >= 500) {
            // Likely a server-side problem rather than anything the merchant did.
            // Surface the body verbatim and stop, rather than retrying in a loop.
            $detail = $api_message !== '' ? $api_message : trim(substr($raw, 0, 300));
            return 'The payment API returned a server error (HTTP ' . $code . ')'
                 . ($detail !== '' ? ': ' . $detail : '')
                 . '. This is usually a configuration problem on our side - please contact support rather than retrying repeatedly.';
        }

        return 'Webhook registration failed (HTTP ' . $code . ')'
             . ($api_message !== '' ? ': ' . $api_message : '') . '.';
    }

    /**
     * The list endpoint may return a bare array or wrap it in a key.
     */
    /**
     * @return array|null Array of webhooks, or NULL when the shape is unrecognised.
     *
     * The null case matters: "no webhooks" and "I could not read the response" must
     * not look the same, because the first means create one and the second means we
     * have no idea what exists. Treating them alike would POST a fresh webhook on
     * every settings save.
     */
    private static function extract_list($response) {
        if (!is_array($response)) {
            return null;
        }

        // Already a bare list.
        if ($response === array()) {
            return array();
        }
        if (array_keys($response) === range(0, count($response) - 1)) {
            return $response;
        }

        foreach (array('webhooks', 'data', 'results', 'items', 'records') as $key) {
            if (array_key_exists($key, $response)) {
                $inner = $response[$key];
                if (is_array($inner)) {
                    // A single object rather than a list.
                    if ($inner !== array() && array_keys($inner) !== range(0, count($inner) - 1)) {
                        return array($inner);
                    }
                    return $inner;
                }
                if ($inner === null) {
                    return array(); // Explicit "none".
                }
            }
        }

        // A lone webhook object returned directly.
        if (self::extract_id($response)) {
            return array($response);
        }

        return null;
    }

    /**
     * Pull a webhook id out of a response, tolerating envelopes and id naming.
     */
    private static function extract_id($response) {
        foreach (self::candidates($response) as $node) {
            foreach (array('webhook_id', 'webhookId', 'id', 'ID') as $key) {
                if (isset($node[$key]) && (is_int($node[$key]) || is_string($node[$key])) && (int) $node[$key] > 0) {
                    return (int) $node[$key];
                }
            }
        }
        return 0;
    }

    /**
     * Pull a callback URL out of a webhook record.
     *
     * Tolerant of field naming for the same reason extract_id() is: if this returns
     * an empty string the record cannot be matched against this site, and an
     * unmatched record means a duplicate gets created on the next save.
     */
    private static function extract_url($webhook) {
        $keys = array('url', 'endpoint', 'callback_url', 'callbackUrl', 'target_url', 'targetUrl', 'webhook_url', 'webhookUrl');

        foreach (self::candidates($webhook) as $node) {
            foreach ($keys as $key) {
                if (!empty($node[$key]) && is_string($node[$key])) {
                    return $node[$key];
                }
            }
        }
        return '';
    }

    private static function extract_secret($response) {
        foreach (self::candidates($response) as $node) {
            foreach (array('signing_secret', 'signingSecret', 'secret') as $key) {
                if (!empty($node[$key]) && is_string($node[$key])) {
                    return $node[$key];
                }
            }
        }
        return '';
    }

    /**
     * The response itself plus one level of common envelopes, so a payload nested
     * under data/webhook/result is still readable.
     */
    private static function candidates($response) {
        if (!is_array($response)) {
            return array();
        }

        $nodes = array($response);
        foreach (array('data', 'webhook', 'result', 'payload') as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                $nodes[] = $response[$key];
            }
        }
        return $nodes;
    }

    /**
     * Render a response's structure for logging, without its values.
     *
     * Create and rotate responses carry the signing secret, so values are shown
     * only for a small allow-list of diagnostic keys; everything else reports its
     * type and length.
     */
    private static function describe_shape($value, $depth = 0) {
        if ($depth > 4) {
            return '...';
        }

        if (is_array($value)) {
            if ($value === array()) {
                return '[]';
            }
            if (array_keys($value) === range(0, count($value) - 1)) {
                return '[' . count($value) . ' x ' . self::describe_shape($value[0], $depth + 1) . ']';
            }
            $parts = array();
            foreach ($value as $key => $item) {
                $parts[] = $key . ':' . self::describe_shape($item, $depth + 1, $key);
            }
            return '{' . implode(', ', $parts) . '}';
        }

        if (is_bool($value))  { return $value ? 'true' : 'false'; }
        if (is_int($value))   { return 'int(' . $value . ')'; }
        if (is_float($value)) { return 'float'; }
        if ($value === null)  { return 'null'; }

        if (is_string($value)) {
            $safe_keys = array('message', 'status', 'url', 'error', 'detail', 'type', 'state');
            $key = func_num_args() > 2 ? func_get_arg(2) : null;
            if ($key !== null && in_array(strtolower((string) $key), $safe_keys, true)) {
                return '"' . substr($value, 0, 80) . '"';
            }
            return 'string(' . strlen($value) . ')';
        }

        return gettype($value);
    }

    // ----------------------------------------------------------------- helpers

    /**
     * API host root, without the /v1 the rest of the plugin's base URL carries.
     * Honours the whitelabel/staging override so partner builds hit their own host.
     */
    private static function api_root() {
        $override = class_exists('SP_Whitelabel_Branding')
            ? SP_Whitelabel_Branding::get_api_base_url_override()
            : null;

        $base = $override ? $override : 'https://api.coinsub.io/v1';

        // Paths below are absolute from the host (/v1/merchants/...), so strip a
        // trailing /v1 rather than producing /v1/v1/merchants/...
        return preg_replace('#/v1/?$#', '', rtrim(trim($base), '/'));
    }

    /**
     * Derived from the site itself - never asked of the merchant.
     */
    public static function callback_url() {
        return get_rest_url(null, self::CALLBACK_ROUTE);
    }

    private static function credentials() {
        $settings    = get_option('woocommerce_sp_settings', array());
        $merchant_id = isset($settings['merchant_id']) ? trim((string) $settings['merchant_id']) : '';
        $api_key     = isset($settings['api_key']) ? trim((string) $settings['api_key']) : '';

        if ($merchant_id === '' || $api_key === '') {
            return null;
        }

        return array('merchant_id' => $merchant_id, 'api_key' => $api_key);
    }

    private static function is_valid_uuid($value) {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    /**
     * Compare callback URLs ignoring trailing-slash and case-of-host differences.
     */
    private static function same_url($a, $b) {
        $normalise = function ($url) {
            $url   = trim((string) $url);
            $parts = wp_parse_url($url);
            if (!$parts || empty($parts['host'])) {
                return rtrim(strtolower($url), '/');
            }
            $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'https';
            $host   = strtolower($parts['host']);
            $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
            $path   = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
            // Query intentionally dropped: the legacy URL carried ?secret=...
            return $scheme . '://' . $host . $port . $path;
        };

        return $normalise($a) === $normalise($b);
    }

    /**
     * @return string Empty when the URL looks deliverable, otherwise the reason.
     */
    private static function unreachable_reason($url) {
        $parts = wp_parse_url($url);
        $host  = isset($parts['host']) ? strtolower($parts['host']) : '';

        if ($host === '') {
            return 'Could not work out this site\'s public URL, so the webhook was not registered.';
        }

        $local_names = array('localhost', '127.0.0.1', '::1', '[::1]');
        $local_tlds  = array('.local', '.test', '.localhost', '.invalid', '.example', '.internal');

        $is_local = in_array($host, $local_names, true);
        foreach ($local_tlds as $tld) {
            if (substr($host, -strlen($tld)) === $tld) {
                $is_local = true;
                break;
            }
        }

        // RFC1918 / loopback / link-local ranges.
        if (!$is_local && filter_var($host, FILTER_VALIDATE_IP)) {
            $is_public = filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if ($is_public === false) {
                $is_local = true;
            }
        }

        if ($is_local) {
            return sprintf(
                'This site is at %s, which the payment API cannot reach, so no webhook was registered. Payments still work - deploy to a public HTTPS URL and save these settings again to register it.',
                esc_html($host)
            );
        }

        if (empty($parts['scheme']) || strtolower($parts['scheme']) !== 'https') {
            return 'Webhook deliveries require HTTPS. Enable SSL on this site, then save these settings again.';
        }

        return '';
    }

    // ------------------------------------------------------------------ storage

    /**
     * The signing secret is a credential. It is stored with autoload off and is
     * never rendered into settings HTML, logged, or exposed over REST.
     */
    private static function store_secret($secret) {
        update_option(self::OPTION_SIGNING_SECRET, $secret, false);
    }

    public static function signing_secret() {
        return (string) get_option(self::OPTION_SIGNING_SECRET, '');
    }

    public static function webhook_id() {
        return (int) get_option(self::OPTION_WEBHOOK_ID, 0);
    }

    /**
     * True when this site holds everything needed to receive and verify deliveries.
     *
     * This is the ground truth. A stored status message is only a record of what
     * happened last time - if it disagrees with this, it is out of date.
     */
    public static function is_registered() {
        return self::webhook_id() > 0 && self::signing_secret() !== '';
    }

    /**
     * Last provisioning outcome, re-validated before it is trusted.
     *
     * A stored failure is not evidence of a current problem. It can outlive the
     * thing it described: a later attempt succeeded, or the message was written by
     * an older build whose wording and semantics no longer apply. Rendering it
     * anyway leaves merchants staring at an error for a webhook that works, with no
     * way to clear it. So a failure that is contradicted by an actual registration
     * is discarded rather than shown.
     */
    /**
     * The current problem, if there is one.
     *
     * Only failures are ever stored, so this returns an empty array whenever things
     * are working. Success is silence: registering the webhook is the expected
     * outcome and does not deserve a banner, and anything persisted on the happy
     * path would linger in the options table of every site that installs this.
     */
    public static function status() {
        $status = get_option(self::OPTION_STATUS, array());

        if (!is_array($status) || empty($status['state'])) {
            return array();
        }

        // Written by a build with different status semantics, or contradicted by an
        // actual registration. Either way the stored text is no longer evidence of
        // a live problem, so drop it rather than showing it forever.
        $stale_schema = (int) (isset($status['schema']) ? $status['schema'] : 0) !== self::STATUS_SCHEMA;

        if ($stale_schema || self::is_registered()) {
            delete_option(self::OPTION_STATUS);
            return array();
        }

        return $status;
    }

    /**
     * Hide the current notice until something changes.
     *
     * Only suppresses the message that is showing now: any later record() writes a
     * fresh status without the flag, so a new problem is surfaced again.
     */
    public static function dismiss_status() {
        $status = get_option(self::OPTION_STATUS, array());
        if (!is_array($status) || empty($status['state'])) {
            return;
        }
        $status['dismissed'] = true;
        update_option(self::OPTION_STATUS, $status, false);
    }

    private static function ok($message) {
        return self::record('ok', $message);
    }

    private static function fail($state, $message) {
        error_log('PP Webhook Provisioner: ' . $state . ' - ' . $message);
        return self::record($state, $message);
    }

    private static function record($state, $message) {
        $status = array(
            'state'      => $state,
            'message'    => $message,
            'time'       => time(),
            'schema'     => self::STATUS_SCHEMA,
            'webhook_id' => self::webhook_id(),
        );

        // Success, and "credentials not entered yet", are normal states. Storing
        // them would leave a row behind on every install and risk it being rendered
        // later as though it still meant something, so clear instead of persisting.
        // The value is still returned for the caller that triggered the sync.
        if ($state === 'ok' || $state === 'incomplete') {
            delete_option(self::OPTION_STATUS);
            return $status;
        }

        update_option(self::OPTION_STATUS, $status, false);
        return $status;
    }
}
