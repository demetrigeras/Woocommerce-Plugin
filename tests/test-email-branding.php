<?php
/** Payment method title shown in WooCommerce emails. */
define('ABSPATH', dirname(__DIR__) . '/');

function __($s, $d = null) { return $s; }
function add_action() {} function add_filter() {} function apply_filters($t, $v) { return $v; }
function wc_get_orders() { return array(); }

class WC_Order {
    private $method, $title;
    public function __construct($method, $title) { $this->method = $method; $this->title = $title; }
    public function get_payment_method() { return $this->method; }
    public function get_id() { return 1; }
}

class SP_Whitelabel_Branding {
    public static $name = null;
    public static function get_whitelabel_plugin_name_from_config() { return self::$name; }
}

require_once dirname(__DIR__) . '/includes/class-sp-order-manager.php';

$pass = 0; $fail = 0;
function check($l, $c, $d = '') {
    global $pass, $fail;
    if ($c) { $pass++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else { $fail++; echo "  \033[31mFAIL\033[0m  $l" . ($d ? " -- $d" : '') . "\n"; }
}
echo "\n\033[1mWooCommerce email: payment method title\033[0m\n";

$m = new SP_Order_Manager();

// A partner build must never surface the underlying provider's name.
SP_Whitelabel_Branding::$name = 'Syncharge';
$stale = new WC_Order('sp', 'CoinSub');
$out = $m->whitelabel_payment_method_title('CoinSub', $stale);
check('an order stored as "CoinSub" renders as the partner name', $out === 'Pay with Syncharge', $out);

$out = $m->whitelabel_payment_method_title('Pay with CoinSub', new WC_Order('sp', 'Pay with CoinSub'));
check('"Pay with CoinSub" is replaced too', $out === 'Pay with Syncharge', $out);

// Orders recorded under the pre-rename gateway id are the likeliest offenders.
$out = $m->whitelabel_payment_method_title('CoinSub', new WC_Order('coinsub', 'CoinSub'));
check('legacy gateway id "coinsub" is covered', $out === 'Pay with Syncharge', $out);

// A previous partner's name must not survive a rebrand either.
$out = $m->whitelabel_payment_method_title('Pay with Payment Servers', new WC_Order('sp', 'Pay with Payment Servers'));
check('a previous partner name is replaced', $out === 'Pay with Syncharge', $out);

// Other gateways must be left completely alone.
foreach (array('stripe', 'paypal', 'bacs', 'cod') as $other) {
    $out = $m->whitelabel_payment_method_title('Credit Card', new WC_Order($other, 'Credit Card'));
    check("$other order is untouched", $out === 'Credit Card', $out);
}

// No partner configured -> the plugin's own name, never the stored value.
SP_Whitelabel_Branding::$name = null;
$out = $m->whitelabel_payment_method_title('CoinSub', new WC_Order('sp', 'CoinSub'));
check('with no partner set it falls back to the plugin name, not the stored one',
    $out === 'Pay with Stablecoin Pay', $out);

// Switching partner updates historical orders with no migration.
SP_Whitelabel_Branding::$name = 'Payment Servers';
$out = $m->whitelabel_payment_method_title('Pay with Syncharge', new WC_Order('sp', 'Pay with Syncharge'));
check('rebranding updates past orders immediately', $out === 'Pay with Payment Servers', $out);

// Defensive: non-order input must not fatal inside an email render.
$out = $m->whitelabel_payment_method_title('Something', null);
check('a non-order argument is passed through untouched', $out === 'Something', var_export($out, true));

echo "\n" . str_repeat('=', 62) . "\n";
printf("  %d passed, %d failed\n", $pass, $fail);
echo str_repeat('=', 62) . "\n";
exit($fail > 0 ? 1 : 0);
