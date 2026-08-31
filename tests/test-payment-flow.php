<?php
/**
 * End-to-end: purchase session -> stored on order -> webhook -> pending becomes processing.
 *
 * Drives the real SP_Webhook_Handler against a fake WooCommerce order.
 */
define('ABSPATH', dirname(__DIR__) . '/');
define('DAY_IN_SECONDS', 86400);

$GLOBALS['options'] = array();
$GLOBALS['transients'] = array();
$GLOBALS['orders_by_session'] = array();

function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v, $a = true) { $GLOBALS['options'][$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); return true; }
function __($s, $d = null) { return $s; }
function esc_html($s) { return $s; }
function current_time($f) { return '2026-08-31 09:00:00'; }
function add_action() {} function add_filter() {} function apply_filters($t, $v) { return $v; }
function sanitize_text_field($s) { return $s; }
function wc_get_orders($args) {
    if (($args['meta_key'] ?? '') === '_sp_purchase_session_id') {
        $v = $args['meta_value'];
        return isset($GLOBALS['orders_by_session'][$v]) ? array($GLOBALS['orders_by_session'][$v]) : array();
    }
    return array();
}
function wc_get_order($id) { return false; }
function get_user_by($f, $v) { return false; }
function wp_update_user($a) { return 1; }
function get_userdata($id) { return false; }
function wc_create_order($a = array()) { return new FakeOrder(999); }
function wp_mail() { return true; }
function get_bloginfo($k) { return "Shop"; }
function home_url($p = "") { return "https://shop.example.com" . $p; }
function wc_get_payment_gateway_by_order($o) { return null; }
function wc_price($a) { return "$" . $a; }
function absint($v) { return abs((int) $v); }
function is_wp_error($t) { return $t instanceof WP_Error; }
class WP_Error { public function __construct($c='',$m='',$d=''){} public function get_error_message(){return '';} }
class WP_REST_Response { public function __construct($d,$s=200){} }
class SP_API_Client {
    public static function redact_for_log($d){ return $d; }
    public static function describe_shape($d){ return '{...}'; }
    public function update_settings($a,$b,$c){}
    public function get_payment_details($id){ return array('status' => 'completed'); }
    public function __call($n,$a){ return null; }
}
class SP_Webhook_Provisioner {
    public static function all_namespaces(){ return array('woowh/v1'); }
    public static function signing_secret(){ return ''; }
    const CALLBACK_ROUTE='woowh/v1/webhook';
}

/** Records what the handler does to it. */
class FakeOrder {
    public $id; public $status = 'pending'; public $meta = array();
    public $status_history = array(); public $notes = array(); public $saved = 0;
    public function __construct($id, $meta = array()) { $this->id = $id; $this->meta = $meta; }
    public function get_id() { return $this->id; }
    public function get_status() { return $this->status; }
    public function update_status($s, $note = '') { $this->status = $s; $this->status_history[] = $s; return true; }
    public function get_meta($k, $single = true) { return $this->meta[$k] ?? ''; }
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function add_order_note($n, $c = false) { $this->notes[] = $n; }
    public function save() { $this->saved++; return $this->id; }
    public function get_payment_method() { return 'sp'; }
    public function get_billing_email() { return 'jane@example.com'; }
    public function get_total() { return '0.10'; }
    public function get_currency() { return 'USD'; }
    public function set_payment_method($m) {}
    public function set_payment_method_title($t) {}
    public function payment_complete($tx = '') { $this->status = 'processing'; return true; }
    public function __call($n, $a) { return null; }
}

require_once dirname(__DIR__) . '/includes/class-sp-webhook-handler.php';

$pass = 0; $fail = 0;
function check($l, $c, $d = '') { global $pass,$fail; if($c){$pass++; echo "  \033[32mPASS\033[0m  $l\n";} else {$fail++; echo "  \033[31mFAIL\033[0m  $l".($d?" -- $d":"")."\n";} }
function section($t) { echo "\n\033[1m$t\033[0m\n"; }

$h = new SP_Webhook_Handler();
$lookup = new ReflectionMethod('SP_Webhook_Handler', 'find_order_by_purchase_session_id');
$lookup->setAccessible(true);
$process = new ReflectionMethod('SP_Webhook_Handler', 'process_webhook');
$process->setAccessible(true);

section('Session id stored at checkout is found by the webhook');
$GLOBALS['orders_by_session']['abc-session-123'] = new FakeOrder(501, array('_sp_purchase_session_id' => 'abc-session-123'));
check('found by the bare session id', $lookup->invoke($h, 'abc-session-123') !== null);
check('found when the webhook prefixes it with sess_', $lookup->invoke($h, 'sess_abc-session-123') !== null);
check('unknown session finds nothing', $lookup->invoke($h, 'nope') === null);

section('payment webhook flips the order from pending to processing');
$GLOBALS['options']['woocommerce_sp_settings'] = array('merchant_id' => '053daf5f-7de6-491e-8096-5c8a8612f334', 'api_key' => 'k');
$order = new FakeOrder(502, array('_sp_purchase_session_id' => 'sess-502', '_sp_is_subscription' => 'no'));
$GLOBALS['orders_by_session']['sess-502'] = $order;
check('order starts pending', $order->get_status() === 'pending');

$process->invoke($h, array(
    'type' => 'payment',
    'merchant_id' => 'mrch_053daf5f-7de6-491e-8096-5c8a8612f334',
    'origin_id' => 'sess-502',
    'payment_id' => 'pay_777',
    'amount' => '0.10',
));
check('order moved to processing', $order->get_status() === 'processing', $order->get_status());
check('order was saved', $order->saved > 0);
check('payment id stored for later matching', ($order->meta['_sp_payment_id'] ?? '') === 'pay_777',
    var_export($order->meta['_sp_payment_id'] ?? null, true));

section('A merchant_id the store does not recognise must NOT discard the payment');
$order2 = new FakeOrder(503, array('_sp_purchase_session_id' => 'sess-503', '_sp_is_subscription' => 'no'));
$GLOBALS['orders_by_session']['sess-503'] = $order2;
$process->invoke($h, array(
    'type' => 'payment',
    'merchant_id' => 'some-completely-different-id',
    'origin_id' => 'sess-503',
    'payment_id' => 'pay_778',
));
check('still processed despite the id mismatch', $order2->get_status() === 'processing', $order2->get_status());

echo "\n" . str_repeat('=', 58) . "\n  $pass passed, $fail failed\n" . str_repeat('=', 58) . "\n";
exit($fail ? 1 : 0);
