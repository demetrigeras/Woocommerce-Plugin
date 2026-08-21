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

    /** Last provisioning outcome, surfaced as an admin notice. */
    const OPTION_STATUS = 'sp_webhook_provision_status';

    /** REST namespace/route this plugin actually registers. */
    const CALLBACK_ROUTE = 'stablecoin/v1/webhook';

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

        // 4. Nothing to reuse - create.
        return self::create($callback_url, $credentials);
    }

    /**
     * Look through the merchant's webhooks for one pointing at this site, and for
     * the one we previously created (by stored id) whose URL may have drifted.
     */
    private static function find_existing($callback_url, $credentials) {
        $result = array('match' => null, 'ours' => null);

        $response = self::request('GET', 'webhooks', null, $credentials);
        $webhooks = self::extract_list($response);

        $stored_id = (int) get_option(self::OPTION_WEBHOOK_ID, 0);

        foreach ($webhooks as $webhook) {
            if (!is_array($webhook)) {
                continue;
            }
            $id  = isset($webhook['webhook_id']) ? (int) $webhook['webhook_id'] : (isset($webhook['id']) ? (int) $webhook['id'] : 0);
            $url = isset($webhook['url']) ? (string) $webhook['url'] : '';

            if ($url !== '' && self::same_url($url, $callback_url)) {
                $result['match'] = $webhook;
            }
            if ($stored_id && $id === $stored_id) {
                $result['ours'] = $webhook;
            }
        }

        return $result;
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
        $webhook_id = isset($webhook['webhook_id']) ? (int) $webhook['webhook_id'] : (int) ($webhook['id'] ?? 0);
        if (!$webhook_id) {
            return self::fail('error', 'The API returned a webhook without an id. Contact support.');
        }

        update_option(self::OPTION_WEBHOOK_ID, $webhook_id, false);
        update_option(self::OPTION_REGISTERED_URL, $callback_url, false);

        // Re-enable a previously disabled record so disconnect -> reconnect works.
        $status = isset($webhook['status']) ? (string) $webhook['status'] : '';
        if ($status !== '' && strtolower($status) !== 'active') {
            self::request('PUT', 'webhooks/' . $webhook_id, array('status' => 'active'), $credentials);
        }

        if (self::signing_secret() === '') {
            $rotated = self::request('POST', 'webhooks/' . $webhook_id . '/rotate-secret', null, $credentials);
            $secret  = isset($rotated['signing_secret']) ? (string) $rotated['signing_secret'] : '';
            if ($secret === '') {
                return self::fail('error', 'Reused an existing webhook but could not obtain a signing secret. Contact support.');
            }
            self::store_secret($secret);
            return self::ok('Reused the existing webhook for this site and issued a new signing secret.');
        }

        return self::ok('Webhook already registered for this site.');
    }

    private static function update_url($webhook, $callback_url, $credentials) {
        $webhook_id = isset($webhook['webhook_id']) ? (int) $webhook['webhook_id'] : (int) ($webhook['id'] ?? 0);
        if (!$webhook_id) {
            return self::create($callback_url, $credentials);
        }

        // Partial update: omitted fields keep their current values.
        self::request('PUT', 'webhooks/' . $webhook_id, array('url' => $callback_url), $credentials);

        update_option(self::OPTION_WEBHOOK_ID, $webhook_id, false);
        update_option(self::OPTION_REGISTERED_URL, $callback_url, false);

        // A URL change does not reissue the secret, so an install that has the id
        // but never captured a secret still needs one.
        if (self::signing_secret() === '') {
            $rotated = self::request('POST', 'webhooks/' . $webhook_id . '/rotate-secret', null, $credentials);
            if (!empty($rotated['signing_secret'])) {
                self::store_secret((string) $rotated['signing_secret']);
            }
        }

        return self::ok('Webhook URL updated to this site.');
    }

    private static function create($callback_url, $credentials) {
        $response = self::request('POST', 'webhooks', array(
            'url'                    => $callback_url,
            'subscribed_event_types' => self::event_types(),
        ), $credentials);

        $webhook_id = isset($response['webhook_id']) ? (int) $response['webhook_id'] : 0;
        $secret     = isset($response['signing_secret']) ? (string) $response['signing_secret'] : '';

        if (!$webhook_id) {
            return self::fail('error', 'The API did not return a webhook id. Contact support.');
        }

        // This is the only response that will ever carry the secret.
        if ($secret === '') {
            return self::fail('error', 'The API did not return a signing secret. Contact support.');
        }

        update_option(self::OPTION_WEBHOOK_ID, $webhook_id, false);
        update_option(self::OPTION_REGISTERED_URL, $callback_url, false);
        self::store_secret($secret);

        return self::ok('Webhook registered automatically. No dashboard setup needed.');
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
    private static function extract_list($response) {
        if (!is_array($response)) {
            return array();
        }
        if (isset($response[0])) {
            return $response;
        }
        foreach (array('webhooks', 'data', 'results', 'items') as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return $response[$key];
            }
        }
        return array();
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

    public static function status() {
        $status = get_option(self::OPTION_STATUS, array());
        return is_array($status) ? $status : array();
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
            'state'   => $state,
            'message' => $message,
            'time'    => time(),
        );
        update_option(self::OPTION_STATUS, $status, false);
        return $status;
    }
}
