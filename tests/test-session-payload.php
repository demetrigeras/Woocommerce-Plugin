<?php
/** The outgoing purchase-session request must not carry a `details` key. */
define('ABSPATH', dirname(__DIR__) . '/');
$GLOBALS['options'] = array(); $GLOBALS['sent'] = null;
function get_option($k,$d=false){ return $GLOBALS['options'][$k] ?? $d; }
function apply_filters($t,$v){ return $v; }
function is_wp_error($t){ return $t instanceof WP_Error; }
function wp_remote_retrieve_response_code($r){ return $r['code']; }
function wp_remote_retrieve_body($r){ return $r['body']; }
function wp_remote_post($url,$args){ $GLOBALS['sent'] = json_decode($args['body'], true);
    return array('code'=>200,'body'=>json_encode(array('data'=>array('purchase_session_id'=>'s1','url'=>'https://buy/x')))); }
function wp_remote_get($u,$a){ return wp_remote_post($u,$a); }
class WP_Error { public function __construct($c='',$m='',$d=''){} public function get_error_message(){return '';} }
class SP_Whitelabel_Branding { public static function get_api_base_url_override(){ return null; } }
require_once dirname(__DIR__) . '/includes/class-sp-api-client.php';

$pass=0;$fail=0;
function check($l,$c,$d=''){ global $pass,$fail; if($c){$pass++; echo "  \033[32mPASS\033[0m  $l\n";} else {$fail++; echo "  \033[31mFAIL\033[0m  $l".($d?" -- $d":"")."\n";} }
function section($t){ echo "\n\033[1m$t\033[0m\n"; }

$base = array('name'=>'WooCommerce Order: Widget','currency'=>'USD','amount'=>10.0,
    'metadata'=>array('woocommerce_order_id'=>1),'success_url'=>'https://s','cancel_url'=>'https://c');

section('details is ALWAYS sent - the API rejects the payload without it');
$c = new SP_API_Client();
$c->create_purchase_session($base + array('details' => ''));
check('empty details -> key still present', array_key_exists('details', $GLOBALS['sent']),
    json_encode(array_keys($GLOBALS['sent'])));
check('  -> and non-empty (empty is rejected as Invalid request payload)',
    trim((string) $GLOBALS['sent']['details']) !== '', var_export($GLOBALS['sent']['details'] ?? null, true));

$GLOBALS['sent'] = null;
$c->create_purchase_session($base);   // key not supplied at all
check('missing details key -> a fallback is still sent',
    array_key_exists('details', $GLOBALS['sent']) && trim((string) $GLOBALS['sent']['details']) !== '',
    var_export($GLOBALS['sent']['details'] ?? null, true));

section('Everything else still goes out');
foreach (array('name','currency','amount','metadata','success_url','cancel_url') as $k) {
    check("$k present", array_key_exists($k, $GLOBALS['sent']));
}

section('A supplied value is used verbatim');
$GLOBALS['sent'] = null;
$c->create_purchase_session($base + array('details' => 'Order #1234'));
check('a non-empty details IS sent', ($GLOBALS['sent']['details'] ?? '') === 'Order #1234',
    json_encode($GLOBALS['sent']['details'] ?? null));

echo "\n" . str_repeat('=',58) . "\n  $pass passed, $fail failed\n" . str_repeat('=',58) . "\n";
exit($fail?1:0);
