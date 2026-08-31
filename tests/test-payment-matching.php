<?php
/**
 * Payments table -> WooCommerce order matching.
 *
 * Extracts the real matching logic from class-sp-admin-payments.php and drives it
 * against the payment record shapes the platform actually sends.
 */
define('ABSPATH', dirname(__DIR__) . '/');

$GLOBALS['orders_by_session'] = array();
$GLOBALS['orders_by_payment'] = array();
$GLOBALS['orders_by_id']      = array();
$GLOBALS['queries']           = array();

function absint($v) { return abs((int) $v); }
function wc_get_order($id) { return $GLOBALS['orders_by_id'][$id] ?? false; }
function wc_get_orders($args) {
    $GLOBALS['queries'][] = $args;
    $k = $args['meta_key']; $v = $args['meta_value'];
    if ($k === '_sp_purchase_session_id' && isset($GLOBALS['orders_by_session'][$v])) return array($GLOBALS['orders_by_session'][$v]);
    if ($k === '_sp_payment_id' && isset($GLOBALS['orders_by_payment'][$v])) return array($GLOBALS['orders_by_payment'][$v]);
    return array();
}
class FakeOrder { public $id; public function __construct($id){ $this->id=$id; } public function get_id(){ return $this->id; } }
class SP_API_Client { public static function describe_shape($v){ return '{...}'; } }

// Pull the matching block out of the shipping file so the test exercises real code.
$src = file_get_contents(dirname(__DIR__) . '/includes/class-sp-admin-payments.php');
$start = strpos($src, '// Try to match by order ID in payment metadata');
$end   = strpos($src, '// Get customer info');
if ($start === false || $end === false) { echo "could not locate matching block\n"; exit(1); }
$block = substr($src, $start, $end - $start);

function match_order($payment) {
    global $block;
    $order = null;
    eval($block);
    return $order;
}

$pass = 0; $fail = 0;
function check($l, $c, $d = '') { global $pass,$fail; if($c){$pass++; echo "  \033[32mPASS\033[0m  $l\n";} else {$fail++; echo "  \033[31mFAIL\033[0m  $l".($d?" -- $d":"")."\n";} }
function section($t) { echo "\n\033[1m$t\033[0m\n"; }
function reset_orders() {
    $GLOBALS['orders_by_session'] = array(); $GLOBALS['orders_by_payment'] = array();
    $GLOBALS['orders_by_id'] = array(); $GLOBALS['queries'] = array();
}

section('1. Match by metadata order id');
reset_orders();
$GLOBALS['orders_by_id'][4321] = new FakeOrder(4321);
$o = match_order(array('metadata' => array('woocommerce_order_id' => 4321)));
check('object metadata matches', $o && $o->get_id() === 4321);
$o = match_order(array('metadata' => json_encode(array('woocommerce_order_id' => 4321))));
check('JSON-STRING metadata matches too', $o && $o->get_id() === 4321);

section('2. Match by session id, whatever the platform calls it');
foreach (array('purchase_session_id','origin_id','session_id','purchaseSessionId','originId') as $key) {
    reset_orders();
    $GLOBALS['orders_by_session']['abc-123'] = new FakeOrder(77);
    $o = match_order(array($key => 'abc-123'));
    check("field \"$key\" matches", $o && $o->get_id() === 77);
}
reset_orders();
$GLOBALS['orders_by_session']['abc-123'] = new FakeOrder(77);
$o = match_order(array('origin_id' => 'sess_abc-123'));
check('sess_-prefixed id falls back to the bare id', $o && $o->get_id() === 77);
reset_orders();
$GLOBALS['orders_by_session']['abc-123'] = new FakeOrder(78);
$o = match_order(array('metadata' => array('origin_id' => 'abc-123')));
check('session id nested in metadata matches', $o && $o->get_id() === 78);

section('3. Match by payment id');
foreach (array('payment_id','id','paymentId') as $key) {
    reset_orders();
    $GLOBALS['orders_by_payment']['pay_9'] = new FakeOrder(99);
    $o = match_order(array($key => 'pay_9'));
    check("field \"$key\" matches", $o && $o->get_id() === 99);
}

section('4. No false positives');
reset_orders();
check('unknown payment matches nothing', match_order(array('foo' => 'bar')) === null);
check('empty record matches nothing', match_order(array()) === null);
reset_orders();
$GLOBALS['orders_by_id'][1] = new FakeOrder(1);
check('empty order id is not treated as a match', match_order(array('metadata' => array('woocommerce_order_id' => 0))) === null);

echo "\n" . str_repeat('=', 58) . "\n  $pass passed, $fail failed\n" . str_repeat('=', 58) . "\n";
exit($fail ? 1 : 0);
