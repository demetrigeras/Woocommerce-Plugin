<?php
/**
 * Standalone harness for webhook auto-provisioning + signature verification.
 *
 * Stubs just enough WordPress to load the two real plugin classes, then drives
 * them against a fake API. Run with:
 *   ./tests/run.sh                              (Docker; no local PHP needed)
 *   php tests/test-webhook-provisioning.php     (if PHP is installed)
 */

define('ABSPATH', dirname(__DIR__) . '/');
define('DAY_IN_SECONDS', 86400);
define('MINUTE_IN_SECONDS', 60);

// ---------------------------------------------------------------- WP test double

$GLOBALS['options']    = array();
$GLOBALS['transients'] = array();
$GLOBALS['http_log']   = array();
$GLOBALS['api']        = null; // callable($method, $url, $args) => array(code, body)

function get_option($k, $d = false)            { return array_key_exists($k, $GLOBALS['options']) ? $GLOBALS['options'][$k] : $d; }
function update_option($k, $v, $a = true)      { $GLOBALS['options'][$k] = $v; return true; }
function delete_option($k)                     { unset($GLOBALS['options'][$k]); return true; }
function get_transient($k)                     { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $t = 0)         { $GLOBALS['transients'][$k] = $v; return true; }
function apply_filters($tag, $value)           { return $value; }
function wp_json_encode($d)                    { return json_encode($d); }
function wp_parse_url($u)                      { return parse_url($u); }
function esc_html($s)                          { return htmlspecialchars((string) $s, ENT_QUOTES); }
function sanitize_text_field($s)               { return trim(strip_tags((string) $s)); }
function get_rest_url($b, $path)               { return rtrim($GLOBALS['site_url'], '/') . '/wp-json/' . ltrim($path, '/'); }
function is_wp_error($t)                       { return $t instanceof WP_Error; }
function add_action(...$a)                     {}
function wp_remote_retrieve_response_code($r)  { return $r['code']; }
function wp_remote_retrieve_body($r)           { return $r['body']; }

function wp_remote_request($url, $args) {
    $GLOBALS['http_log'][] = array('method' => $args['method'], 'url' => $url, 'body' => $args['body'] ?? null);
    return call_user_func($GLOBALS['api'], $args['method'], $url, $args);
}

class WP_Error {
    private $code, $message, $data;
    public function __construct($c = '', $m = '', $d = '') { $this->code = $c; $this->message = $m; $this->data = $d; }
    public function get_error_message() { return $this->message; }
    public function get_error_code()    { return $this->code; }
    public function get_error_data()    { return $this->data; }
}

class WP_REST_Response {
    public $data, $status;
    public function __construct($d, $s = 200) { $this->data = $d; $this->status = $s; }
}

/** Minimal WP_REST_Request stand-in. */
class FakeRequest {
    private $headers, $body, $params;
    public function __construct($headers = array(), $body = '', $params = array()) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->body = $body;
        $this->params = $params;
    }
    public function get_header($k)  { return $this->headers[strtolower($k)] ?? null; }
    public function get_headers()   { $o = array(); foreach ($this->headers as $k => $v) { $o[$k] = array($v); } return $o; }
    public function get_body()      { return $this->body; }
    public function get_param($k)   { return $this->params[$k] ?? null; }
    public function get_json_params() { return json_decode($this->body, true); }
}

// Branding override hook the provisioner consults for the API host.
class SP_Whitelabel_Branding {
    public static $override = null;
    public static function get_api_base_url_override() { return self::$override; }
}

require_once dirname(__DIR__) . '/includes/class-sp-webhook-provisioner.php';
require_once dirname(__DIR__) . '/includes/class-sp-webhook-handler.php';
require_once dirname(__DIR__) . '/includes/class-sp-api-client.php';

// ------------------------------------------------------------------- assertions

$pass = 0; $fail = 0;
function check($label, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  \033[32mPASS\033[0m  $label\n"; }
    else       { $fail++; echo "  \033[31mFAIL\033[0m  $label" . ($detail ? " -- $detail" : "") . "\n"; }
}
function section($t) { echo "\n\033[1m$t\033[0m\n"; }

function reset_state($site = 'https://shop.example.com') {
    $GLOBALS['options'] = array(
        'woocommerce_sp_settings' => array(
            'merchant_id' => '3f8b21c4-9d0e-4a71-b2c5-6e7d8f9a0b1c',
            'api_key'     => 'sk_test_key',
        ),
    );
    $GLOBALS['transients'] = array();
    $GLOBALS['http_log']   = array();
    $GLOBALS['site_url']   = $site;
    SP_Whitelabel_Branding::$override = null;
}

function json_reply($code, $data) { return array('code' => $code, 'body' => json_encode($data)); }

function invoke($obj, $method, $args) {
    $r = new ReflectionMethod($obj, $method);
    $r->setAccessible(true);
    return $r->invokeArgs($obj, $args);
}

// =============================================================== 1. URL derivation
section('1. Callback URL is derived from the site, not the merchant');
reset_state();
check('uses the plugin\'s real REST namespace (stablecoin/v1, not coinsub/v1)',
    SP_Webhook_Provisioner::callback_url() === 'https://shop.example.com/wp-json/woowh/v1/webhook',
    SP_Webhook_Provisioner::callback_url());

// ============================================================= 2. Fresh create
section('2. First save with no existing webhook -> creates one');
reset_state();
$GLOBALS['api'] = function ($method, $url) {
    if ($method === 'GET')  { return json_reply(200, array('webhooks' => array())); }
    if ($method === 'POST') {
        return json_reply(200, array(
            'message' => 'Webhook created', 'webhook_id' => 123, 'status' => 'active',
            'signing_secret' => 'whsec_abc123', 'subscribed_event_types' => array('payment'),
        ));
    }
    return json_reply(500, array('message' => 'unexpected'));
};
$r = SP_Webhook_Provisioner::sync();
check('reports success', ($r['state'] ?? '') === 'ok', json_encode($r));
check('stored webhook_id', SP_Webhook_Provisioner::webhook_id() === 123);
check('stored signing secret', SP_Webhook_Provisioner::signing_secret() === 'whsec_abc123');
check('listed before creating (idempotency check)', $GLOBALS['http_log'][0]['method'] === 'GET');
check('POST body carries the derived URL',
    strpos($GLOBALS['http_log'][1]['body'], 'shop.example.com') !== false);
check('hits /v1/merchants/{id}/webhooks without doubling /v1',
    substr_count($GLOBALS['http_log'][1]['url'], '/v1/') === 1, $GLOBALS['http_log'][1]['url']);

// ================================================ 3. Saving twice is idempotent
section('3. Saving settings twice does NOT create a second webhook');
$GLOBALS['http_log'] = array();
$GLOBALS['api'] = function ($method, $url) {
    if ($method === 'GET') {
        return json_reply(200, array('webhooks' => array(
            array('webhook_id' => 123, 'url' => 'https://shop.example.com/wp-json/woowh/v1/webhook', 'status' => 'active'),
        )));
    }
    return json_reply(500, array('message' => 'should not have been called'));
};
$r = SP_Webhook_Provisioner::sync();
$methods = array_column($GLOBALS['http_log'], 'method');
check('succeeds', ($r['state'] ?? '') === 'ok', json_encode($r));
check('made no POST (no duplicate webhook)', !in_array('POST', $methods, true), implode(',', $methods));
check('kept the same webhook_id', SP_Webhook_Provisioner::webhook_id() === 123);

// ===================================================== 4. Site moved -> PUT update
section('4. Site URL changed (staging -> production) -> updates in place');
reset_state('https://newshop.example.com');
$GLOBALS['options']['sp_webhook_id'] = 123;
$GLOBALS['options']['sp_webhook_signing_secret'] = 'whsec_abc123';
$GLOBALS['api'] = function ($method, $url) {
    if ($method === 'GET') {
        return json_reply(200, array('webhooks' => array(
            array('webhook_id' => 123, 'url' => 'https://oldshop.example.com/wp-json/woowh/v1/webhook', 'status' => 'active'),
        )));
    }
    if ($method === 'PUT') { return json_reply(200, array('message' => 'Webhook updated')); }
    return json_reply(500, array('message' => 'unexpected ' . $method));
};
$r = SP_Webhook_Provisioner::sync();
$methods = array_column($GLOBALS['http_log'], 'method');
check('succeeds', ($r['state'] ?? '') === 'ok', json_encode($r));
check('used PUT, not POST', in_array('PUT', $methods, true) && !in_array('POST', $methods, true), implode(',', $methods));
check('PUT carries the new URL', strpos(end($GLOBALS['http_log'])['body'], 'newshop.example.com') !== false);

// ======================================== 5. Adopting a webhook with no local secret
section('5. Reinstall: webhook exists but secret was lost -> rotates to recover');
reset_state();
$GLOBALS['api'] = function ($method, $url) {
    if ($method === 'GET') {
        return json_reply(200, array('webhooks' => array(
            array('webhook_id' => 77, 'url' => 'https://shop.example.com/wp-json/woowh/v1/webhook', 'status' => 'active'),
        )));
    }
    if ($method === 'POST' && str_ends_with($url, '/rotate-secret')) {
        return json_reply(200, array('signing_secret' => 'whsec_rotated'));
    }
    return json_reply(500, array('message' => 'unexpected'));
};
$r = SP_Webhook_Provisioner::sync();
check('succeeds', ($r['state'] ?? '') === 'ok', json_encode($r));
check('adopted existing id', SP_Webhook_Provisioner::webhook_id() === 77);
check('recovered a usable secret via rotate', SP_Webhook_Provisioner::signing_secret() === 'whsec_rotated');

// ================================================================ 6. Local / staging
section('6. Local and private hosts are refused with a clear message');
foreach (array('http://localhost:8080', 'https://mysite.local', 'http://192.168.1.50', 'https://127.0.0.1') as $host) {
    reset_state($host);
    $GLOBALS['api'] = function () { return json_reply(500, array('message' => 'MUST NOT BE CALLED')); };
    $r = SP_Webhook_Provisioner::sync();
    check("refuses $host without calling the API",
        ($r['state'] ?? '') === 'unreachable' && count($GLOBALS['http_log']) === 0,
        json_encode($r));
}
reset_state('http://plain-http.example.com');
$GLOBALS['api'] = function () { return json_reply(500, array('message' => 'MUST NOT BE CALLED')); };
$r = SP_Webhook_Provisioner::sync();
check('refuses non-HTTPS public host', ($r['state'] ?? '') === 'unreachable', json_encode($r));

// ============================================================== 7. Error surfacing
section('7. API failures produce actionable messages, not generic ones');
reset_state();
$GLOBALS['api'] = function () { return json_reply(403, array('message' => 'missing required scope: webhooks')); };
$r = SP_Webhook_Provisioner::sync();
check('403 missing scope -> mentions webhook permissions',
    stripos($r['message'], 'webhook permissions') !== false, $r['message']);

reset_state();
$GLOBALS['api'] = function () { return json_reply(500, array('message' => 'nil pointer dereference in webhook svc')); };
$r = SP_Webhook_Provisioner::sync();
check('500 -> surfaces body and says contact support',
    stripos($r['message'], 'nil pointer') !== false && stripos($r['message'], 'support') !== false, $r['message']);

reset_state();
$GLOBALS['options']['woocommerce_sp_settings']['merchant_id'] = 'not-a-uuid';
$GLOBALS['api'] = function () { return json_reply(500, array('message' => 'MUST NOT BE CALLED')); };
$r = SP_Webhook_Provisioner::sync();
check('non-UUID merchant id rejected locally, no API call',
    stripos($r['message'], 'UUID') !== false && count($GLOBALS['http_log']) === 0, $r['message']);

reset_state();
$GLOBALS['options']['woocommerce_sp_settings']['api_key'] = '';
$r = SP_Webhook_Provisioner::sync();
check('missing credentials -> "incomplete", not an error', ($r['state'] ?? '') === 'incomplete', json_encode($r));

// ============================================== 8. Provisioning never throws upward
section('8. Provisioning failure never escalates (checkout must keep working)');
reset_state();
$GLOBALS['api'] = function () { throw new RuntimeException('network exploded'); };
$threw = false;
try { $r = SP_Webhook_Provisioner::sync(); } catch (Throwable $e) { $threw = true; }
check('swallows exceptions from the transport', !$threw);
check('records the failure for the admin notice', ($r['state'] ?? '') === 'error', json_encode($r ?? array()));

// ============================================================ 9. Signature verify
section('9. Inbound signature verification');
reset_state();
$secret = 'whsec_test_secret';
$GLOBALS['options']['sp_webhook_signing_secret'] = $secret;
$handler = new SP_Webhook_Handler();

$body = '{"type":"payment","origin_id":"abc-123","amount":"42.00"}';
$ts   = (string) time();
$sig  = base64_encode(hash_hmac('sha256', $ts . '.' . $body, $secret, true));

$req = new FakeRequest(array(
    'X-Webhook-Signature' => $sig, 'X-Webhook-Timestamp' => $ts,
    'X-Webhook-Signature-Version' => 'v1', 'X-Event-ID' => '9001',
), $body);
check('accepts a correctly signed delivery', invoke($handler, 'verify_delivery', array($req, $body)) === true);

// Tampered body
$bad = new FakeRequest(array(
    'X-Webhook-Signature' => $sig, 'X-Webhook-Timestamp' => $ts, 'X-Webhook-Signature-Version' => 'v1',
), '{"type":"payment","origin_id":"abc-123","amount":"9999.00"}');
$res = invoke($handler, 'verify_delivery', array($bad, '{"type":"payment","origin_id":"abc-123","amount":"9999.00"}'));
check('rejects a tampered body', $res instanceof WP_Error && $res->get_error_code() === 'sp_invalid_signature');

// Re-encoded body: the classic mistake this guards against. Use a payload whose
// bytes genuinely change on a decode/encode round trip (pretty-printing, unicode
// escaping) so this asserts something real rather than round-tripping identically.
$pretty   = "{\n  \"type\": \"payment\",\n  \"note\": \"caf\u{00e9}\",\n  \"amount\": \"42.00\"\n}";
$prettySig = base64_encode(hash_hmac('sha256', $ts . '.' . $pretty, $secret, true));
$reencoded = json_encode(json_decode($pretty, true));
if ($reencoded === $pretty) {
    check('re-encode test precondition (bytes must differ)', false, 'payload round-tripped unchanged');
} else {
    // Signature was made over $pretty; handing the handler the re-encoded bytes
    // must fail, proving verification uses the raw body and not a normalised form.
    $res = invoke($handler, 'verify_delivery', array(new FakeRequest(array(
        'X-Webhook-Signature' => $prettySig, 'X-Webhook-Timestamp' => $ts,
    ), $reencoded), $reencoded));
    check('rejects a re-encoded body (proves raw bytes are used)', $res instanceof WP_Error,
        is_object($res) ? get_class($res) : var_export($res, true));

    // ...and the same signature still validates against the original bytes.
    $res = invoke($handler, 'verify_delivery', array(new FakeRequest(array(
        'X-Webhook-Signature' => $prettySig, 'X-Webhook-Timestamp' => $ts,
    ), $pretty), $pretty));
    check('accepts the same payload in its original byte form', $res === true);
}

// Wrong secret
$wrong = base64_encode(hash_hmac('sha256', $ts . '.' . $body, 'whsec_other', true));
$res = invoke($handler, 'verify_delivery', array(new FakeRequest(array(
    'X-Webhook-Signature' => $wrong, 'X-Webhook-Timestamp' => $ts,
), $body), $body));
check('rejects a signature made with the wrong secret', $res instanceof WP_Error);

// Replay: stale timestamp
$old   = (string) (time() - 900);
$oldsig = base64_encode(hash_hmac('sha256', $old . '.' . $body, $secret, true));
$res = invoke($handler, 'verify_delivery', array(new FakeRequest(array(
    'X-Webhook-Signature' => $oldsig, 'X-Webhook-Timestamp' => $old,
), $body), $body));
check('rejects a validly-signed but stale delivery (replay window)',
    $res instanceof WP_Error && $res->get_error_code() === 'sp_stale_timestamp');

// Signature required once provisioned
$res = invoke($handler, 'verify_delivery', array(new FakeRequest(array(), $body), $body));
check('rejects unsigned delivery when a signing secret exists',
    $res instanceof WP_Error && $res->get_error_code() === 'sp_signature_required');

// Unsupported version
$res = invoke($handler, 'verify_delivery', array(new FakeRequest(array(
    'X-Webhook-Signature' => $sig, 'X-Webhook-Timestamp' => $ts, 'X-Webhook-Signature-Version' => 'v2',
), $body), $body));
check('rejects an unknown signature version', $res instanceof WP_Error);

// ==================================================== 10. Legacy compat + dedupe
section('10. Legacy manual webhooks still work, and retries are deduplicated');
reset_state();
$GLOBALS['options']['sp_webhook_secret'] = 'legacy_shared_secret';
$handler = new SP_Webhook_Handler();
$res = invoke($handler, 'verify_delivery', array(
    new FakeRequest(array(), $body, array('secret' => 'legacy_shared_secret')), $body));
check('accepts legacy ?secret= delivery when no signing secret is stored', $res === true);

$res = invoke($handler, 'verify_delivery', array(
    new FakeRequest(array('x-coinsub-secret' => 'legacy_shared_secret'), $body), $body));
check('accepts legacy x-coinsub-secret header', $res === true);

$res = invoke($handler, 'verify_delivery', array(
    new FakeRequest(array(), $body, array('secret' => 'wrong')), $body));
check('rejects a wrong legacy secret', $res instanceof WP_Error);

check('first delivery of an event is claimed', invoke($handler, 'claim_event', array('9001')) === true);
check('retry of the same event is rejected as duplicate', invoke($handler, 'claim_event', array('9001')) === false);
check('a different event still processes', invoke($handler, 'claim_event', array('9002')) === true);

// ==================================================== 11. Tolerant response shapes
section('11. Real-world response shapes (the live API disagreed with the spec)');

// Envelope: {"data": {...}}
reset_state();
$GLOBALS['api'] = function ($method, $url) {
    if ($method === 'GET') { return json_reply(200, array('webhooks' => array())); }
    return json_reply(200, array('data' => array('webhook_id' => 55, 'signing_secret' => 'whsec_env')));
};
$r = SP_Webhook_Provisioner::sync();
check('create response nested under "data"', ($r['state'] ?? '') === 'ok', json_encode($r));
check('  -> id read from envelope', SP_Webhook_Provisioner::webhook_id() === 55);
check('  -> secret read from envelope', SP_Webhook_Provisioner::signing_secret() === 'whsec_env');

// Alternate key names: id / secret
reset_state();
$GLOBALS['api'] = function ($method, $url) {
    if ($method === 'GET') { return json_reply(200, array()); }
    return json_reply(200, array('id' => 56, 'secret' => 'whsec_alt'));
};
$r = SP_Webhook_Provisioner::sync();
check('create response using "id"/"secret" key names', ($r['state'] ?? '') === 'ok', json_encode($r));
check('  -> id 56', SP_Webhook_Provisioner::webhook_id() === 56);
check('  -> secret recovered', SP_Webhook_Provisioner::signing_secret() === 'whsec_alt');

// Created, but the secret arrives only via rotate.
reset_state();
$GLOBALS['api'] = function ($method, $url) {
    if ($method === 'GET') { return json_reply(200, array('webhooks' => array())); }
    if (str_ends_with($url, '/rotate-secret')) { return json_reply(200, array('signing_secret' => 'whsec_late')); }
    return json_reply(200, array('webhook_id' => 57, 'status' => 'active')); // no secret inline
};
$r = SP_Webhook_Provisioner::sync();
check('create without an inline secret recovers via rotate', ($r['state'] ?? '') === 'ok', json_encode($r));
check('  -> keeps the created id (no orphan)', SP_Webhook_Provisioner::webhook_id() === 57);
check('  -> obtained a usable secret', SP_Webhook_Provisioner::signing_secret() === 'whsec_late');

// THE DUPLICATE GUARD: unreadable list must never lead to a create.
reset_state();
$GLOBALS['api'] = function ($method, $url) {
    if ($method === 'GET') { return json_reply(200, array('unexpected' => 'shape')); }
    return json_reply(200, array('webhook_id' => 999, 'signing_secret' => 'nope'));
};
$r = SP_Webhook_Provisioner::sync();
$methods = array_column($GLOBALS['http_log'], 'method');
check('unreadable webhook list -> refuses to create', ($r['state'] ?? '') === 'error', json_encode($r));
check('  -> made NO POST (this is what prevents duplicates)',
    !in_array('POST', $methods, true), implode(',', $methods));
check('  -> error names the offending shape', strpos($r['message'], 'unexpected') !== false, $r['message']);

// Explicit null list means "none", so creating is correct.
reset_state();
$GLOBALS['api'] = function ($method, $url) {
    if ($method === 'GET') { return json_reply(200, array('webhooks' => null)); }
    return json_reply(200, array('webhook_id' => 58, 'signing_secret' => 'whsec_null'));
};
$r = SP_Webhook_Provisioner::sync();
check('"webhooks": null is treated as empty, so it creates', ($r['state'] ?? '') === 'ok', json_encode($r));

// A single webhook object returned instead of a list.
reset_state();
$GLOBALS['api'] = function ($method, $url) {
    if ($method === 'GET') {
        return json_reply(200, array('webhook_id' => 9, 'url' => 'https://shop.example.com/wp-json/woowh/v1/webhook', 'status' => 'active'));
    }
    if (str_ends_with($url, '/rotate-secret')) { return json_reply(200, array('signing_secret' => 'whsec_single')); }
    return json_reply(500, array('message' => 'MUST NOT CREATE'));
};
$r = SP_Webhook_Provisioner::sync();
$methods = array_column($GLOBALS['http_log'], 'method');
check('a lone webhook object is understood as a list of one', ($r['state'] ?? '') === 'ok', json_encode($r));
check('  -> adopted it rather than creating', SP_Webhook_Provisioner::webhook_id() === 9);

// Shape logging must not leak the secret.
$describe = new ReflectionMethod('SP_Webhook_Provisioner', 'describe_shape');
$describe->setAccessible(true);
$shape = $describe->invokeArgs(null, array(array(
    'webhook_id' => 42, 'signing_secret' => 'SUPERSECRETVALUE',
    'status' => 'active', 'nested' => array('secret' => 'ALSOSECRET'),
), 0));
check('shape logging shows structure', strpos($shape, 'webhook_id:int(42)') !== false, $shape);
check('shape logging does NOT leak the signing secret',
    strpos($shape, 'SUPERSECRETVALUE') === false && strpos($shape, 'ALSOSECRET') === false, $shape);
check('shape logging keeps diagnostic fields readable', strpos($shape, '"active"') !== false, $shape);

// ========================================= 12. Create succeeded but we couldn't tell
section('12. Create landed server-side but the reply was unusable');

// Exactly the reported production case: POST returns 2xx with a body carrying no
// id we recognise, yet the webhook really was created.
reset_state();
$created = false;
$GLOBALS['api'] = function ($method, $url) use (&$created) {
    if ($method === 'GET') {
        return $created
            ? json_reply(200, array('webhooks' => array(array(
                'webhook_id' => 321,
                'url' => 'https://shop.example.com/wp-json/woowh/v1/webhook',
                'status' => 'active',
              ))))
            : json_reply(200, array('webhooks' => array()));
    }
    if (str_ends_with($url, '/rotate-secret')) {
        return json_reply(200, array('signing_secret' => 'whsec_recovered'));
    }
    // Create works, but answers with a shape we cannot read.
    $created = true;
    return json_reply(201, array('message' => 'Webhook created'));
};
$r = SP_Webhook_Provisioner::sync();
check('recovers instead of reporting a false failure', ($r['state'] ?? '') === 'ok', json_encode($r));
check('  -> adopted the webhook that was actually created', SP_Webhook_Provisioner::webhook_id() === 321);
check('  -> obtained a signing secret for it', SP_Webhook_Provisioner::signing_secret() === 'whsec_recovered');
$methods = array_column($GLOBALS['http_log'], 'method');
check('  -> created exactly once', count(array_keys($methods, 'POST')) >= 1
    && count(array_filter($GLOBALS['http_log'], function ($c) {
        return $c['method'] === 'POST' && !str_ends_with($c['url'], '/rotate-secret');
    })) === 1, implode(',', $methods));

// Transport blew up (timeout / 502) but the create had already landed.
reset_state();
$landed = false;
$GLOBALS['api'] = function ($method, $url) use (&$landed) {
    if ($method === 'GET') {
        return $landed
            ? json_reply(200, array('webhooks' => array(array(
                'webhook_id' => 654,
                'url' => 'https://shop.example.com/wp-json/woowh/v1/webhook',
                'status' => 'active',
              ))))
            : json_reply(200, array('webhooks' => array()));
    }
    if (str_ends_with($url, '/rotate-secret')) {
        return json_reply(200, array('signing_secret' => 'whsec_after_timeout'));
    }
    $landed = true;
    return json_reply(504, array('message' => 'gateway timeout'));
};
$r = SP_Webhook_Provisioner::sync();
check('a 5xx on create still recovers if the webhook exists', ($r['state'] ?? '') === 'ok', json_encode($r));
check('  -> adopted id 654', SP_Webhook_Provisioner::webhook_id() === 654);

// Genuine failure: create errored AND nothing exists. Must still report an error.
reset_state();
$GLOBALS['api'] = function ($method, $url) {
    if ($method === 'GET') { return json_reply(200, array('webhooks' => array())); }
    return json_reply(500, array('message' => 'database is down'));
};
$r = SP_Webhook_Provisioner::sync();
check('a real failure is still reported as an error', ($r['state'] ?? '') === 'error', json_encode($r));
check('  -> and still says contact support', stripos($r['message'], 'support') !== false, $r['message']);

// Unreadable create AND the follow-up shows nothing -> honest failure.
reset_state();
$GLOBALS['api'] = function ($method, $url) {
    if ($method === 'GET') { return json_reply(200, array('webhooks' => array())); }
    return json_reply(200, array('message' => 'ok but useless'));
};
$r = SP_Webhook_Provisioner::sync();
check('unreadable create with nothing to adopt -> error, not a false success',
    ($r['state'] ?? '') === 'error', json_encode($r));

// ================================================= 13. Legacy namespace migration
section('13. Migrating merchants off the retired callback namespace');

check('canonical namespace is generic (no product or company name)',
    SP_Webhook_Provisioner::NAMESPACE_CURRENT === 'woowh/v1',
    SP_Webhook_Provisioner::NAMESPACE_CURRENT);
check('the old namespace is still served for compatibility',
    in_array('stablecoin/v1', SP_Webhook_Provisioner::NAMESPACES_LEGACY, true));
check('handler registers canonical first, then legacy',
    SP_Webhook_Provisioner::all_namespaces() === array('woowh/v1', 'stablecoin/v1'),
    implode(',', SP_Webhook_Provisioner::all_namespaces()));

// A merchant registered before the rename: their webhook points at the old path.
// It must be REPOINTED, not duplicated.
reset_state();
$GLOBALS['api'] = function ($method, $url) {
    if ($method === 'GET') {
        return json_reply(200, array('webhooks' => array(array(
            'webhook_id' => 700,
            'url' => 'https://shop.example.com/wp-json/stablecoin/v1/webhook',
            'status' => 'active',
        ))));
    }
    if ($method === 'PUT') { return json_reply(200, array('message' => 'Webhook updated')); }
    if (str_ends_with($url, '/rotate-secret')) { return json_reply(200, array('signing_secret' => 'whsec_migrated')); }
    return json_reply(500, array('message' => 'MUST NOT CREATE A SECOND WEBHOOK'));
};
$r = SP_Webhook_Provisioner::sync();
$methods = array_column($GLOBALS['http_log'], 'method');
$creates = array_filter($GLOBALS['http_log'], function ($c) {
    return $c['method'] === 'POST' && !str_ends_with($c['url'], '/rotate-secret');
});
check('legacy-path webhook is migrated', ($r['state'] ?? '') === 'ok', json_encode($r));
check('  -> used PUT to repoint it', in_array('PUT', $methods, true), implode(',', $methods));
check('  -> did NOT create a duplicate', count($creates) === 0, implode(',', $methods));
check('  -> kept the same webhook id', SP_Webhook_Provisioner::webhook_id() === 700);
$put = null;
foreach ($GLOBALS['http_log'] as $call) { if ($call['method'] === 'PUT') { $put = $call; } }
// Decode rather than substring-match: json_encode escapes forward slashes.
$put_url = $put ? (json_decode($put['body'], true)['url'] ?? '') : '';
check('  -> PUT targets the new woowh path',
    $put_url === 'https://shop.example.com/wp-json/woowh/v1/webhook', $put_url);
check('  -> no longer points at the old path', $put && strpos($put['body'], 'stablecoin') === false,
    $put ? $put['body'] : 'no PUT');

// Already migrated: an exact match on the canonical URL wins, nothing is changed.
reset_state();
$GLOBALS['options']['sp_webhook_signing_secret'] = 'whsec_existing';
$GLOBALS['api'] = function ($method, $url) {
    if ($method === 'GET') {
        return json_reply(200, array('webhooks' => array(array(
            'webhook_id' => 701,
            'url' => 'https://shop.example.com/wp-json/woowh/v1/webhook',
            'status' => 'active',
        ))));
    }
    return json_reply(500, array('message' => 'MUST NOT MODIFY ANYTHING'));
};
$r = SP_Webhook_Provisioner::sync();
$methods = array_column($GLOBALS['http_log'], 'method');
check('an already-migrated webhook is left alone', ($r['state'] ?? '') === 'ok', json_encode($r));
check('  -> no PUT, no POST', $methods === array('GET'), implode(',', $methods));

// Both an old and a new webhook exist: prefer the canonical one, do not touch the old.
reset_state();
$GLOBALS['options']['sp_webhook_signing_secret'] = 'whsec_existing';
$GLOBALS['api'] = function ($method, $url) {
    if ($method === 'GET') {
        return json_reply(200, array('webhooks' => array(
            array('webhook_id' => 800, 'url' => 'https://shop.example.com/wp-json/stablecoin/v1/webhook', 'status' => 'active'),
            array('webhook_id' => 801, 'url' => 'https://shop.example.com/wp-json/woowh/v1/webhook', 'status' => 'active'),
        )));
    }
    return json_reply(500, array('message' => 'MUST NOT MODIFY ANYTHING'));
};
$r = SP_Webhook_Provisioner::sync();
check('with both present, the canonical one is adopted', SP_Webhook_Provisioner::webhook_id() === 801,
    (string) SP_Webhook_Provisioner::webhook_id());
check('  -> and nothing is created or repointed',
    array_column($GLOBALS['http_log'], 'method') === array('GET'));

// Deliveries to the legacy path must still verify - the route stays alive.
reset_state();
$GLOBALS['options']['sp_webhook_signing_secret'] = 'whsec_legacy_path';
$handler = new SP_Webhook_Handler();
$lbody = '{"type":"payment","origin_id":"legacy-1"}';
$lts   = (string) time();
$lsig  = base64_encode(hash_hmac('sha256', $lts . '.' . $lbody, 'whsec_legacy_path', true));
$res = invoke($handler, 'verify_delivery', array(new FakeRequest(array(
    'X-Webhook-Signature' => $lsig, 'X-Webhook-Timestamp' => $lts,
), $lbody), $lbody));
check('a delivery arriving on the legacy route still verifies', $res === true);

// ============================================ 14. Stale notices must not linger
section('14. A stored failure is re-validated, not shown forever');

// The exact reported case: an error written by an OLDER build (no schema stamp),
// still sitting in the options table after a later save succeeded.
reset_state();
$GLOBALS['options']['sp_webhook_provision_status'] = array(
    'state'   => 'error',
    'message' => 'The API did not return a webhook id. Contact support.',
    'time'    => time() - 3600,
);
$GLOBALS['options']['sp_webhook_id'] = 321;
$GLOBALS['options']['sp_webhook_signing_secret'] = 'whsec_live';

$s = SP_Webhook_Provisioner::status();
check('an old-build error is not shown once a webhook is registered',
    $s === array(), json_encode($s));
check('  -> the stale wording is gone',
    strpos(json_encode($s), 'did not return a webhook id') === false, json_encode($s));
check('  -> and the row is deleted, not rewritten',
    !isset($GLOBALS['options']['sp_webhook_provision_status']));

// Old-build error, and nothing is actually registered -> stay silent rather than
// showing wording from a previous version.
reset_state();
$GLOBALS['options']['sp_webhook_provision_status'] = array(
    'state' => 'error', 'message' => 'Some ancient message.', 'time' => time() - 3600,
);
$s = SP_Webhook_Provisioner::status();
check('old-build error with nothing registered -> no notice at all', $s === array(), json_encode($s));
check('  -> and the stale option is cleared',
    !isset($GLOBALS['options']['sp_webhook_provision_status']));

// A CURRENT-build failure with nothing registered must still be reported.
reset_state();
$GLOBALS['api'] = function ($method, $url) {
    if ($method === 'GET') { return json_reply(200, array('webhooks' => array())); }
    return json_reply(500, array('message' => 'database is down'));
};
SP_Webhook_Provisioner::sync();
$s = SP_Webhook_Provisioner::status();
check('a genuine current failure is still surfaced', ($s['state'] ?? '') === 'error', json_encode($s));
check('  -> and survives repeated reads', (SP_Webhook_Provisioner::status()['state'] ?? '') === 'error');

// A failure recorded while a webhook IS registered is treated as stale.
reset_state();
$GLOBALS['options']['sp_webhook_id'] = 55;
$GLOBALS['options']['sp_webhook_signing_secret'] = 'whsec_ok';
$GLOBALS['options']['sp_webhook_provision_status'] = array(
    'state' => 'error', 'message' => 'Transient blip.', 'time' => time() - 10,
    'schema' => SP_Webhook_Provisioner::STATUS_SCHEMA,
);
$s = SP_Webhook_Provisioner::status();
check('a failure contradicted by a live registration is discarded',
    $s === array(), json_encode($s));

// is_registered needs BOTH id and secret - an id alone cannot verify deliveries.
reset_state();
$GLOBALS['options']['sp_webhook_id'] = 12;
check('id without a signing secret is not "registered"', SP_Webhook_Provisioner::is_registered() === false);
$GLOBALS['options']['sp_webhook_signing_secret'] = 'whsec_x';
check('id plus secret is registered', SP_Webhook_Provisioner::is_registered() === true);

// Dismissal hides the current message but not a later, different one.
reset_state();
$GLOBALS['api'] = function ($method, $url) {
    if ($method === 'GET') { return json_reply(200, array('webhooks' => array())); }
    return json_reply(500, array('message' => 'still broken'));
};
SP_Webhook_Provisioner::sync();
SP_Webhook_Provisioner::dismiss_status();
check('dismissing flags the stored status', !empty(SP_Webhook_Provisioner::status()['dismissed']));
SP_Webhook_Provisioner::sync(); // fails again -> fresh record
check('a subsequent failure clears the dismissal and shows again',
    empty(SP_Webhook_Provisioner::status()['dismissed']));

// ================================= 15. Success is silent and leaves nothing behind
section('15. Nothing is persisted or shown on the happy path');

// A clean registration must leave no row in the options table at all.
reset_state();
$GLOBALS['api'] = function ($method, $url) {
    if ($method === 'GET') { return json_reply(200, array('webhooks' => array())); }
    return json_reply(200, array('webhook_id' => 900, 'signing_secret' => 'whsec_quiet'));
};
$r = SP_Webhook_Provisioner::sync();
check('sync still reports success to its caller', ($r['state'] ?? '') === 'ok', json_encode($r));
check('  -> but stores NO status row', !isset($GLOBALS['options']['sp_webhook_provision_status']),
    json_encode($GLOBALS['options']['sp_webhook_provision_status'] ?? null));
check('  -> and status() is empty, so no notice renders', SP_Webhook_Provisioner::status() === array());
check('  -> the webhook itself is still recorded', SP_Webhook_Provisioner::webhook_id() === 900);

// "Credentials not entered yet" is a normal state on a fresh install, not a problem.
reset_state();
$GLOBALS['options']['woocommerce_sp_settings']['api_key'] = '';
$r = SP_Webhook_Provisioner::sync();
check('missing credentials is reported to the caller', ($r['state'] ?? '') === 'incomplete');
check('  -> but nothing is stored for a fresh install',
    !isset($GLOBALS['options']['sp_webhook_provision_status']));
check('  -> so a new download shows no banner', SP_Webhook_Provisioner::status() === array());

// A failure that later succeeds must stop showing, with no dismissal needed.
reset_state();
$fail = true;
$GLOBALS['api'] = function ($method, $url) use (&$fail) {
    if ($method === 'GET') { return json_reply(200, array('webhooks' => array())); }
    if ($fail) { return json_reply(500, array('message' => 'temporarily down')); }
    return json_reply(200, array('webhook_id' => 901, 'signing_secret' => 'whsec_after'));
};
SP_Webhook_Provisioner::sync();
check('a failure is stored while it is real', (SP_Webhook_Provisioner::status()['state'] ?? '') === 'error');
$fail = false;
SP_Webhook_Provisioner::sync();
check('  -> and clears itself once the retry succeeds',
    SP_Webhook_Provisioner::status() === array(), json_encode(SP_Webhook_Provisioner::status()));
check('  -> leaving no row behind', !isset($GLOBALS['options']['sp_webhook_provision_status']));

// The reported case: a stale success row from an older build must vanish, not show.
reset_state();
$GLOBALS['options']['sp_webhook_provision_status'] = array(
    'state' => 'ok', 'message' => 'Registered #176', 'time' => time() - 60, 'schema' => 1,
);
$GLOBALS['options']['sp_webhook_id'] = 176;
$GLOBALS['options']['sp_webhook_signing_secret'] = 'whsec_old';
check('a leftover success row from an older build is not rendered',
    SP_Webhook_Provisioner::status() === array());
check('  -> and is deleted rather than left lying around',
    !isset($GLOBALS['options']['sp_webhook_provision_status']));

// Only genuine, current problems survive.
reset_state();
$GLOBALS['api'] = function ($method, $url) {
    if ($method === 'GET') { return json_reply(200, array('webhooks' => array())); }
    return json_reply(500, array('message' => 'still broken'));
};
SP_Webhook_Provisioner::sync();
$s = SP_Webhook_Provisioner::status();
check('a real, unresolved failure is still shown', ($s['state'] ?? '') === 'error', json_encode($s));
check('  -> and is stamped with the current schema',
    ($s['schema'] ?? 0) === SP_Webhook_Provisioner::STATUS_SCHEMA);

// ============================================== 16. Never register the same site twice
section('16. Duplicate prevention across credential changes and odd list shapes');

function creates_in_log() {
    return array_values(array_filter($GLOBALS['http_log'], function ($c) {
        return $c['method'] === 'POST' && !str_ends_with($c['url'], '/rotate-secret')
            && !str_ends_with($c['url'], '/disable');
    }));
}

// THE REPORTED BUG: changing the API key and saving again must not create a twin.
reset_state();
$store = array();
$GLOBALS['api'] = function ($method, $url) use (&$store) {
    if ($method === 'GET') { return json_reply(200, array('webhooks' => array_values($store))); }
    if (str_ends_with($url, '/rotate-secret')) { return json_reply(200, array('signing_secret' => 'whsec_r')); }
    if ($method === 'PUT') { return json_reply(200, array('message' => 'updated')); }
    $store[] = array('webhook_id' => 500 + count($store),
        'url' => 'https://shop.example.com/wp-json/woowh/v1/webhook', 'status' => 'active');
    return json_reply(200, array('webhook_id' => 500, 'signing_secret' => 'whsec_first'));
};
SP_Webhook_Provisioner::sync();                                   // initial registration
$GLOBALS['options']['woocommerce_sp_settings']['api_key'] = 'sk_rotated_key';  // merchant rotates their key
$GLOBALS['http_log'] = array();
$r = SP_Webhook_Provisioner::sync();                              // save again
check('changing only the API key does not create a second webhook',
    count(creates_in_log()) === 0, json_encode(array_column($GLOBALS['http_log'], 'method')));
check('  -> and the save still succeeds', ($r['state'] ?? '') === 'ok', json_encode($r));

// A list that names the URL field something other than "url" must still match.
foreach (array('endpoint', 'callback_url', 'target_url', 'webhookUrl') as $url_key) {
    reset_state();
    $GLOBALS['api'] = function ($method, $url) use ($url_key) {
        if ($method === 'GET') {
            return json_reply(200, array('webhooks' => array(array(
                'webhook_id' => 610,
                $url_key => 'https://shop.example.com/wp-json/woowh/v1/webhook',
                'status' => 'active',
            ))));
        }
        if (str_ends_with($url, '/rotate-secret')) { return json_reply(200, array('signing_secret' => 'whsec_k')); }
        return json_reply(200, array('webhook_id' => 999, 'signing_secret' => 'DUPLICATE'));
    };
    SP_Webhook_Provisioner::sync();
    check("list using \"$url_key\" instead of \"url\" is still matched",
        count(creates_in_log()) === 0 && SP_Webhook_Provisioner::webhook_id() === 610,
        'id=' . SP_Webhook_Provisioner::webhook_id());
}

// We own a webhook the listing does not show: update it rather than create.
reset_state();
$GLOBALS['options']['sp_webhook_id'] = 720;
$GLOBALS['options']['sp_webhook_signing_secret'] = 'whsec_have';
$GLOBALS['options']['sp_webhook_merchant_id'] = '3f8b21c4-9d0e-4a71-b2c5-6e7d8f9a0b1c';
$GLOBALS['api'] = function ($method, $url) {
    if ($method === 'GET') { return json_reply(200, array('webhooks' => array())); }
    if ($method === 'PUT') { return json_reply(200, array('message' => 'updated')); }
    return json_reply(200, array('webhook_id' => 999, 'signing_secret' => 'DUPLICATE'));
};
$r = SP_Webhook_Provisioner::sync();
check('a stored webhook missing from the listing is updated, not duplicated',
    count(creates_in_log()) === 0 && ($r['state'] ?? '') === 'ok', json_encode($r));
check('  -> keeps the id we already had', SP_Webhook_Provisioner::webhook_id() === 720);

// ...unless it is genuinely gone, in which case creating is correct.
reset_state();
$GLOBALS['options']['sp_webhook_id'] = 721;
$GLOBALS['options']['sp_webhook_signing_secret'] = 'whsec_stale';
$GLOBALS['options']['sp_webhook_merchant_id'] = '3f8b21c4-9d0e-4a71-b2c5-6e7d8f9a0b1c';
$GLOBALS['api'] = function ($method, $url) {
    if ($method === 'GET') { return json_reply(200, array('webhooks' => array())); }
    if ($method === 'PUT') { return json_reply(404, array('message' => 'webhook not found')); }
    return json_reply(200, array('webhook_id' => 722, 'signing_secret' => 'whsec_new'));
};
$r = SP_Webhook_Provisioner::sync();
check('a deleted stored webhook falls through to create', ($r['state'] ?? '') === 'ok', json_encode($r));
check('  -> with the new id', SP_Webhook_Provisioner::webhook_id() === 722);

// Switching to a DIFFERENT merchant must not touch the old account's webhook.
reset_state();
$GLOBALS['options']['sp_webhook_id'] = 800;
$GLOBALS['options']['sp_webhook_signing_secret'] = 'whsec_old_account';
$GLOBALS['options']['sp_webhook_merchant_id'] = '11111111-1111-1111-1111-111111111111';
$GLOBALS['api'] = function ($method, $url) {
    if ($method === 'GET') { return json_reply(200, array('webhooks' => array())); }
    if ($method === 'PUT') { return json_reply(200, array('message' => 'MUST NOT PUT ACROSS ACCOUNTS')); }
    return json_reply(200, array('webhook_id' => 801, 'signing_secret' => 'whsec_new_account'));
};
$r = SP_Webhook_Provisioner::sync();
$methods = array_column($GLOBALS['http_log'], 'method');
check('a merchant change discards the old registration', ($r['state'] ?? '') === 'ok', json_encode($r));
check('  -> no PUT into the previous account', !in_array('PUT', $methods, true), implode(',', $methods));
check('  -> a fresh webhook is created under the new account',
    SP_Webhook_Provisioner::webhook_id() === 801);
check('  -> and the new owner is recorded',
    ($GLOBALS['options']['sp_webhook_merchant_id'] ?? '') === '3f8b21c4-9d0e-4a71-b2c5-6e7d8f9a0b1c');

// Same merchant, id collision safety: stored id is only reused for the same account.
reset_state();
$GLOBALS['api'] = function ($method, $url) {
    if ($method === 'GET') { return json_reply(200, array('webhooks' => array())); }
    return json_reply(200, array('webhook_id' => 900, 'signing_secret' => 'whsec_owner'));
};
SP_Webhook_Provisioner::sync();
check('the owning merchant is recorded on a normal create',
    ($GLOBALS['options']['sp_webhook_merchant_id'] ?? '') === '3f8b21c4-9d0e-4a71-b2c5-6e7d8f9a0b1c');

// ===================================================================== summary

// ================================= 9. Documented wallet-transfer payload handling
section('9. Wallet Transfer webhook, per the published payload');

// The exact example from the docs.
$doc_transfer = array(
    'type' => 'transfer',
    'merchant_id' => 'mrch_053daf5f-7de6-491e-8096-5c8a8612f334',
    'amount_in_usd' => '0.985000',
    'hash' => '0x0924b6a3cc49d2ba216452358271533bc8190826b6cae395747be86b91a6ea98',
    'transfer_id' => '7',
    'wallet_id' => 'wa-tffvk-1nj19-1qqbt0j5ieophqg',
    'network' => 'PolygonAmoy',
    'from_address' => '0xd0cbe3ab3a241f6c4d5f2c0e2bfe37ec03fe7f04',
    'to_address' => '0x1C337aBF69aB1DC1F9388e97bBd4AAD57059D8Eb',
    'status' => 'success',
    'status_confirmed_at' => '2025-08-15T11:43:19.641041+02:00',
);

$types = SP_Webhook_Handler::transfer_event_types();
check('transfer is treated as a transfer event', in_array('transfer', $types, true));
check('embed_transfer is too (it REPLACES transfer for embedded sends)',
    in_array('embed_transfer', $types, true));
check('wallet_transfer is too', in_array('wallet_transfer', $types, true));
check('failed_transfer is too', in_array('failed_transfer', $types, true));
check('failed_wallet_transfer is too', in_array('failed_wallet_transfer', $types, true));

// merchant_id: payload prefixes it, our setting does not.
$m = new ReflectionMethod('SP_Webhook_Handler', 'merchant_id_matches');
$m->setAccessible(true);
$h = new SP_Webhook_Handler();
$GLOBALS['options']['woocommerce_sp_settings'] = array(
    'merchant_id' => '053daf5f-7de6-491e-8096-5c8a8612f334', 'api_key' => 'k',
);
check('accepts the docs\' mrch_-prefixed id against a bare UUID setting',
    $m->invoke($h, $doc_transfer['merchant_id']) === true);
check('accepts an exact bare-UUID match',
    $m->invoke($h, '053daf5f-7de6-491e-8096-5c8a8612f334') === true);
check('is case-insensitive', $m->invoke($h, 'MRCH_053DAF5F-7DE6-491E-8096-5C8A8612F334') === true);
check('rejects another merchant\'s event',
    $m->invoke($h, 'mrch_99999999-0000-0000-0000-000000000000') === false);
check('passes through when the payload omits merchant_id', $m->invoke($h, null) === true);
$GLOBALS['options']['woocommerce_sp_settings'] = array('merchant_id' => '', 'api_key' => '');
check('passes through when the store is unconfigured', $m->invoke($h, 'mrch_anything') === true);

// Signature scheme, verified against the documented formula verbatim.
$GLOBALS['options']['sp_webhook_signing_secret'] = 'whsec_doc';
$raw = json_encode($doc_transfer);
$ts = (string) time();
$sig = base64_encode(hash_hmac('sha256', $ts . '.' . $raw, 'whsec_doc', true));
$v = new ReflectionMethod('SP_Webhook_Handler', 'verify_delivery');
$v->setAccessible(true);
check('base64(HMAC_SHA256(secret, timestamp + "." + raw_body)) verifies',
    $v->invokeArgs($h, array(new FakeRequest(array(
        'X-Webhook-Signature' => $sig,
        'X-Webhook-Timestamp' => $ts,
        'X-Webhook-Signature-Version' => 'v1',
    ), $raw), $raw)) === true);

// transfer_id is a string like "7" and is what de-duplicates deliveries.
check('a small numeric-string transfer_id is usable as an id',
    SP_API_Client::extract_transfer_id($doc_transfer) === '7');
check('the docs hash is read as the transaction hash',
    SP_API_Client::extract_transaction_hash($doc_transfer) === $doc_transfer['hash']);

// status: only "success" means funds moved; "pending" must NOT read as settled,
// and must NOT be mistaken for a failure either.
check('documented status "success" is not treated as awaiting signature',
    !SP_API_Client::transfer_awaits_signature($doc_transfer));
check('documented status "pending" IS treated as not-yet-settled',
    SP_API_Client::transfer_awaits_signature(array('status' => 'pending')));

// failed_transfer may carry an empty hash (failed before broadcast).
$doc_failed = array_merge($doc_transfer, array(
    'type' => 'failed_transfer', 'status' => 'failed', 'hash' => '',
));
check('an empty hash on a failed transfer yields no hash',
    SP_API_Client::extract_transaction_hash($doc_failed) === '');
check('a failed transfer is not read as awaiting signature',
    !SP_API_Client::transfer_awaits_signature($doc_failed));

echo "\n" . str_repeat('=', 62) . "\n";
printf("  %d passed, %d failed\n", $pass, $fail);
echo str_repeat('=', 62) . "\n";
exit($fail > 0 ? 1 : 0);
