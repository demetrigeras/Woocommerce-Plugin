<?php
/** Payload redaction for logs: no PII in debug.log, debugging value preserved. */
define('ABSPATH', dirname(__DIR__) . '/');
function get_option($k, $d = false) { return $d; }
function apply_filters($t, $v) { return $v; }
class SP_Whitelabel_Branding { public static function get_api_base_url_override() { return null; } }
require_once dirname(__DIR__) . '/includes/class-sp-api-client.php';

$pass = 0; $fail = 0;
function check($l, $c, $d = '') { global $pass,$fail; if($c){$pass++; echo "  \033[32mPASS\033[0m  $l\n";} else {$fail++; echo "  \033[31mFAIL\033[0m  $l".($d?" -- $d":"")."\n";} }
function section($t) { echo "\n\033[1m$t\033[0m\n"; }

section('Checkout payload: customer data is stripped');
$payload = array(
    'name' => 'Order #1234', 'currency' => 'USD', 'amount' => 84.50,
    'metadata' => array(
        'source' => 'woocommerce_plugin', 'site_domain' => 'shop.example.com',
        'woocommerce_order_id' => 1234,
        'customer_email' => 'jane.doe@example.com', 'customer_name' => 'Jane Doe',
        'billing_phone' => '+1 555 0100', 'billing_address_1' => '12 Elm Street',
        'billing_postcode' => 'SW1A 1AA', 'billing_country' => 'GB',
        'products' => array(array('name' => 'Widget', 'qty' => 2, 'price' => 20.00)),
        'order_breakdown' => array('subtotal' => 40.00, 'tax' => 4.50),
    ),
);
$safe = SP_API_Client::redact_for_log($payload);
$json = json_encode($safe);
foreach (array('jane.doe@example.com','Jane Doe','+1 555 0100','12 Elm Street','SW1A 1AA') as $secret) {
    check('absent from log: ' . $secret, strpos($json, $secret) === false);
}

section('Fields the logs are actually read for survive');
check('amount', $safe['amount'] === 84.50);
check('currency', $safe['currency'] === 'USD');
check('order id', $safe['metadata']['woocommerce_order_id'] === 1234);
check('source', $safe['metadata']['source'] === 'woocommerce_plugin');
check('site domain', $safe['metadata']['site_domain'] === 'shop.example.com');
check('order breakdown', $safe['metadata']['order_breakdown']['subtotal'] === 40.00);
check('redacted keys remain visible as present', $safe['metadata']['customer_email'] === '[redacted]');
// A product name is not personal data, and hiding it makes checkout problems
// impossible to diagnose from the log. Customer names arrive as first_name /
// last_name / customer_name and are still masked - asserted below.
check('nested structure and product names kept',
    $safe['metadata']['products'][0]['qty'] === 2 && $safe['metadata']['products'][0]['name'] === 'Widget',
    json_encode($safe['metadata']['products'][0]));

section('Webhook payload');
$wh = SP_API_Client::redact_for_log(array(
    'type' => 'transfer', 'transfer_id' => '7', 'amount_in_usd' => '0.98', 'status' => 'success',
    'network' => 'Ink', 'to_address' => '0x1C337aBF', 'from_address' => '0xd0cbe3ab',
));
check('wallet addresses redacted', strpos(json_encode($wh), '0x1C337') === false);
check('transfer_id kept (matches the order)', $wh['transfer_id'] === '7');
check('status kept (drives refund state)', $wh['status'] === 'success');

section('Edge cases');
check('non-array passes through', SP_API_Client::redact_for_log('plain') === 'plain');
check('empty array survives', SP_API_Client::redact_for_log(array()) === array());
check('empty sensitive value marked [empty]',
    SP_API_Client::redact_for_log(array('customer_email' => ''))['customer_email'] === '[empty]');
$deep = array('a'=>array('b'=>array('c'=>array('d'=>array('e'=>array('f'=>array('email'=>'x@y.z')))))));
check('deep nesting does not error', is_array(SP_API_Client::redact_for_log($deep)));


section('Payer-visible fields stay readable; customer names do not');
$r = SP_API_Client::redact_for_log(array(
    'name' => 'WooCommerce Order: Widget',
    'details' => 'Payment for WooCommerce order #1234 with 2 product(s)',
    'metadata' => array(
        'customer_name' => 'Jane Doe',
        'firstName' => 'Jane',
        'lastName' => 'Doe',
        'email' => 'jane@example.com',
        'products' => array(array('name' => 'Widget', 'qty' => 2)),
        'billing_address' => array('first_name' => 'Jane', 'last_name' => 'Doe', 'city' => 'London'),
        'order_breakdown' => array('shipping' => array('method' => 'Flat rate')),
    ),
));
check('order name is visible (needed to debug what the payer sees)',
    $r['name'] === 'WooCommerce Order: Widget', var_export($r['name'], true));
check('details is visible', strpos($r['details'], 'order #1234') !== false, var_export($r['details'], true));
check('product name is visible', $r['metadata']['products'][0]['name'] === 'Widget');
check('customer_name IS still redacted', $r['metadata']['customer_name'] === '[redacted]');
check('firstName IS still redacted', $r['metadata']['firstName'] === '[redacted]');
check('lastName IS still redacted', $r['metadata']['lastName'] === '[redacted]');
check('email IS still redacted', $r['metadata']['email'] === '[redacted]');
// The whole address block is masked at its key, so nothing inside it survives.
check('the entire billing_address block is redacted',
    is_string($r['metadata']['billing_address']) && strpos($r['metadata']['billing_address'], 'redacted') !== false,
    var_export($r['metadata']['billing_address'], true));
$j = json_encode($r);
check('no customer PII anywhere in the log line',
    strpos($j, 'Jane') === false && strpos($j, 'jane@example.com') === false && strpos($j, 'London') === false, $j);


echo "\n" . str_repeat('=', 58) . "\n  $pass passed, $fail failed\n" . str_repeat('=', 58) . "\n";
exit($fail ? 1 : 0);
