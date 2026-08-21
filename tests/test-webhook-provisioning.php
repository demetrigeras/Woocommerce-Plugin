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
    SP_Webhook_Provisioner::callback_url() === 'https://shop.example.com/wp-json/stablecoin/v1/webhook',
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
            array('webhook_id' => 123, 'url' => 'https://shop.example.com/wp-json/stablecoin/v1/webhook', 'status' => 'active'),
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
            array('webhook_id' => 123, 'url' => 'https://oldshop.example.com/wp-json/stablecoin/v1/webhook', 'status' => 'active'),
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
            array('webhook_id' => 77, 'url' => 'https://shop.example.com/wp-json/stablecoin/v1/webhook', 'status' => 'active'),
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

// ===================================================================== summary
echo "\n" . str_repeat('=', 62) . "\n";
printf("  %d passed, %d failed\n", $pass, $fail);
echo str_repeat('=', 62) . "\n";
exit($fail > 0 ? 1 : 0);
