<?php
/**
 * Refund transfer-request tests.
 *
 * Stubs WordPress and drives the real SP_API_Client against a fake
 * /merchants/transfer/request endpoint.
 */

define('ABSPATH', dirname(__DIR__) . '/');

$GLOBALS['options'] = array();
$GLOBALS['http'] = null;
$GLOBALS['last_request'] = null;

function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['options']) ? $GLOBALS['options'][$k] : $d; }
function update_option($k, $v, $a = true) { $GLOBALS['options'][$k] = $v; return true; }
function is_wp_error($t) { return $t instanceof WP_Error; }
function __($s, $d = null) { return $s; }
function esc_url($s) { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function wp_parse_url($u, $c = -1) { return $c === -1 ? parse_url($u) : parse_url($u, $c); }
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function wp_remote_post($url, $args) {
    $GLOBALS['last_request'] = array('url' => $url, 'args' => $args);
    return call_user_func($GLOBALS['http'], $url, $args);
}
function wp_remote_get($url, $args) { return wp_remote_post($url, $args); }

class WP_Error {
    private $code, $message, $data;
    public function __construct($c = '', $m = '', $d = '') { $this->code = $c; $this->message = $m; $this->data = $d; }
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}

class SP_Whitelabel_Branding {
    public static function get_api_base_url_override() { return null; }
}

$GLOBALS['filters'] = array();
function apply_filters($tag, $value) { return array_key_exists($tag, $GLOBALS['filters']) ? $GLOBALS['filters'][$tag] : $value; }

require_once dirname(__DIR__) . '/includes/class-sp-api-client.php';

class RefundMathProbe {
public function get_refund_signing_limit() {
        return (float) apply_filters('sp_refund_signing_limit', 100.0);
    }

public function get_refund_gas_headroom() {
        return (float) apply_filters('sp_refund_gas_headroom', 0.1);
    }

public function get_refund_merchant_fee($amount) {
        return (float) apply_filters('sp_refund_merchant_fee', 0.0, (float) $amount);
    }

public function get_refund_required_balance($amount) {
        return round(
            (float) $amount + $this->get_refund_gas_headroom() + $this->get_refund_merchant_fee($amount),
            6
        );
    }

private function describe_required_balance($amount, $token) {
        $required = $this->get_refund_required_balance($amount);
        $fee      = $this->get_refund_merchant_fee($amount);

        if ($fee > 0) {
            return sprintf(
                /* translators: 1: total, 2: token, 3: refund amount, 4: gas, 5: merchant fee */
                __('%1$s %2$s (%3$s refund + %4$s gas + %5$s merchant fee)', 'stablecoin-pay'),
                $required, $token, $amount, $this->get_refund_gas_headroom(), $fee
            );
        }

        // Fee is charged but not quantified here, so do not imply a precise total.
        return sprintf(
            /* translators: 1: subtotal, 2: token, 3: refund amount, 4: gas */
            __('more than %1$s %2$s (%3$s refund + %4$s gas), plus the merchant fee the transfer API charges to send it', 'stablecoin-pay'),
            $required, $token, $amount, $this->get_refund_gas_headroom()
        );
    }
}
function probe_merchant_fee($a){ return (new RefundMathProbe())->get_refund_merchant_fee($a); }
function probe_describe($a,$tok){ $r=new ReflectionMethod('RefundMathProbe','describe_required_balance'); $r->setAccessible(true); return $r->invokeArgs(new RefundMathProbe(), array($a,$tok)); }
function probe_signing_limit(){ return (new RefundMathProbe())->get_refund_signing_limit(); }
function probe_gas_headroom(){ return (new RefundMathProbe())->get_refund_gas_headroom(); }
function probe_required_balance($a){ return (new RefundMathProbe())->get_refund_required_balance($a); }


$pass = 0; $fail = 0;
function check($label, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  \033[32mPASS\033[0m  $label\n"; }
    else { $fail++; echo "  \033[31mFAIL\033[0m  $label" . ($detail ? " -- $detail" : '') . "\n"; }
}
function section($t) { echo "\n\033[1m$t\033[0m\n"; }
function reply($code, $data) { return array('code' => $code, 'body' => is_string($data) ? $data : json_encode($data)); }

function client() {
    $GLOBALS['options']['woocommerce_sp_settings'] = array(
        'merchant_id' => 'f0f530f6-305e-4902-b9df-abc76919396c',
        'api_key' => 'sk_test',
    );
    return new SP_API_Client();
}

// ============================================================ endpoint + payload
section('1. Request shape');
$GLOBALS['http'] = function () { return reply(200, array('transfer_id' => 'tr_1')); };
$c = client();
$c->refund_transfer_request('buyer@example.com', 25.50, 137, 'USDC');
$req = $GLOBALS['last_request'];
check('posts to /merchants/transfer/request',
    substr($req['url'], -strlen('/merchants/transfer/request')) === '/merchants/transfer/request', $req['url']);
$body = json_decode($req['args']['body'], true);
check('sends to_address', ($body['to_address'] ?? '') === 'buyer@example.com');
check('sends numeric amount', ($body['amount'] ?? null) === 25.5, var_export($body['amount'] ?? null, true));
check('sends chainId as int', ($body['chainId'] ?? null) === 137, var_export($body['chainId'] ?? null, true));
check('sends token symbol', ($body['token'] ?? '') === 'USDC');
check('authenticates with Merchant-ID + API-Key',
    ($req['args']['headers']['Merchant-ID'] ?? '') !== '' && ($req['args']['headers']['API-Key'] ?? '') !== '');

// ==================================================== async success codes (BUG 1)
section('2. Asynchronous success codes are accepted');
foreach (array(200, 201, 202) as $code) {
    $GLOBALS['http'] = function () use ($code) { return reply($code, array('transfer_id' => 'tr_' . $code)); };
    $r = client()->refund_transfer_request('buyer@example.com', 10, 137, 'USDC');
    check("HTTP $code is treated as success", !is_wp_error($r),
        is_wp_error($r) ? $r->get_error_message() : '');
}
foreach (array(400, 401, 403, 422, 500) as $code) {
    $GLOBALS['http'] = function () use ($code) { return reply($code, array('message' => 'nope')); };
    $r = client()->refund_transfer_request('buyer@example.com', 10, 137, 'USDC');
    check("HTTP $code is still an error", is_wp_error($r));
}

// ================================================= error message surfacing (BUG 2)
section('3. Failure reasons survive, including insufficient funds');
foreach (array('message', 'error', 'detail', 'description', 'reason') as $key) {
    $GLOBALS['http'] = function () use ($key) { return reply(400, array($key => 'insufficient balance for transfer')); };
    $r = client()->refund_transfer_request('buyer@example.com', 10, 137, 'USDC');
    check("reads the reason from \"$key\"",
        is_wp_error($r) && stripos($r->get_error_message(), 'insufficient balance') !== false,
        is_wp_error($r) ? $r->get_error_message() : 'not an error');
}
$GLOBALS['http'] = function () { return reply(400, array('error' => array('message' => 'insufficient funds'))); };
$r = client()->refund_transfer_request('buyer@example.com', 10, 137, 'USDC');
check('reads a nested error envelope',
    is_wp_error($r) && stripos($r->get_error_message(), 'insufficient funds') !== false,
    is_wp_error($r) ? $r->get_error_message() : 'not an error');

// The refund flow keys its top-up guidance off this text, so it must match.
$GLOBALS['http'] = function () { return reply(402, array('message' => 'Insufficient balance in merchant wallet')); };
$r = client()->refund_transfer_request('buyer@example.com', 10, 137, 'USDC');
$msg = strtolower($r->get_error_message());
check('the message still triggers the insufficient-funds branch',
    strpos($msg, 'insufficient') !== false || strpos($msg, 'balance') !== false, $msg);

$GLOBALS['http'] = function () { return reply(500, 'not json at all'); };
$r = client()->refund_transfer_request('buyer@example.com', 10, 137, 'USDC');
check('an unparseable error still reports the status code',
    is_wp_error($r) && strpos($r->get_error_message(), '500') !== false, $r->get_error_message());

// ================================================ identifier extraction (BUGS 3+4)
section('4. Transfer id is read tolerantly (it is what confirms the refund)');
$cases = array(
    'transfer_id at top level'   => array(array('transfer_id' => 'tr_a'), 'tr_a'),
    'refund_id instead'          => array(array('refund_id' => 'rf_b'), 'rf_b'),
    'camelCase transferId'       => array(array('transferId' => 'tr_c'), 'tr_c'),
    'bare id'                    => array(array('id' => 'id_d'), 'id_d'),
    'nested under data'          => array(array('data' => array('transfer_id' => 'tr_e')), 'tr_e'),
    'nested under transfer'      => array(array('transfer' => array('id' => 'tr_f')), 'tr_f'),
    'numeric id'                 => array(array('transfer_id' => 4321), '4321'),
);
foreach ($cases as $label => $case) {
    check("extracts id: $label", SP_API_Client::extract_transfer_id($case[0]) === $case[1],
        var_export(SP_API_Client::extract_transfer_id($case[0]), true));
}
check('returns EMPTY (never "N/A") when absent',
    SP_API_Client::extract_transfer_id(array('message' => 'Accepted')) === '');
check('empty response yields empty id', SP_API_Client::extract_transfer_id(array()) === '');
check('non-array yields empty id', SP_API_Client::extract_transfer_id(null) === '');

check('extracts transaction_hash', SP_API_Client::extract_transaction_hash(array('transaction_hash' => '0xabc')) === '0xabc');
check('extracts nested hash', SP_API_Client::extract_transaction_hash(array('data' => array('hash' => '0xdef'))) === '0xdef');
check('absent hash is empty', SP_API_Client::extract_transaction_hash(array('transfer_id' => 'x')) === '');

// The regression that silently broke confirmation: 'N/A' is non-empty, so the old
// code stored it as a real id and no incoming transfer webhook could ever match.
$stored = SP_API_Client::extract_transfer_id(array('message' => 'Transfer requested'));
check('a missing id cannot be mistaken for a stored one', $stored === '' && empty($stored));

// ================================== 5. Signing limit: accepted but not broadcast
section('5. Transfers parked awaiting a merchant signature');

// A 2xx whose status says the transfer has not been broadcast. This is the case
// that made a refund "succeed" while nothing happened on-chain.
$parked = array(
    'pending', 'pending_signature', 'awaiting_signature', 'awaiting-signature',
    'requires_signature', 'signature_required', 'unsigned', 'needs_signature', 'queued',
);
foreach ($parked as $status) {
    check("status \"$status\" is recognised as awaiting signature",
        SP_API_Client::transfer_awaits_signature(array('transfer_id' => 'tr_1', 'status' => $status)));
}
check('status is matched case-insensitively',
    SP_API_Client::transfer_awaits_signature(array('status' => 'AWAITING_SIGNATURE')));
check('status read from a data envelope',
    SP_API_Client::transfer_awaits_signature(array('data' => array('status' => 'pending_signature'))));

// Prose variants, for APIs that explain rather than flag it.
$prose = array(
    'A signature is required for transfers over your limit',
    'Transfer exceeds signing limit of 100 USD',
    'Transfer created, awaiting signature',
    'This transfer needs to be signed in your dashboard',
);
foreach ($prose as $message) {
    check('prose detected: "' . substr($message, 0, 38) . '..."',
        SP_API_Client::transfer_awaits_signature(array('message' => $message)), $message);
}

// Must NOT false-positive on genuinely completed transfers.
$done = array(
    array('transfer_id' => 'tr_1', 'status' => 'completed'),
    array('transfer_id' => 'tr_1', 'status' => 'success'),
    array('transfer_id' => 'tr_1', 'status' => 'sent'),
    array('transfer_id' => 'tr_1', 'status' => 'confirmed'),
    array('transfer_id' => 'tr_1', 'transaction_hash' => '0xabc'),
    array('transfer_id' => 'tr_1', 'message' => 'Transfer requested'),
    array('transfer_id' => 'tr_1'),
);
foreach ($done as $i => $resp) {
    check('completed transfer #' . ($i + 1) . ' is NOT flagged as pending',
        !SP_API_Client::transfer_awaits_signature($resp), json_encode($resp));
}
check('empty response is not flagged', !SP_API_Client::transfer_awaits_signature(array()));
check('non-array is not flagged', !SP_API_Client::transfer_awaits_signature(null));

check('extract_transfer_status lower-cases', SP_API_Client::extract_transfer_status(array('status' => 'PENDING')) === 'pending');
check('extract_transfer_status reads "state"', SP_API_Client::extract_transfer_status(array('state' => 'queued')) === 'queued');
check('extract_transfer_status empty when absent', SP_API_Client::extract_transfer_status(array('transfer_id' => 'x')) === '');

// ============================================ 6. Balance must cover amount + gas
section('6. Gas headroom on top of the refund amount');

check('required balance adds the gas headroom', probe_required_balance(25.00) === 25.10,
    var_export(probe_required_balance(25.00), true));
check('works for whole amounts', probe_required_balance(100.0) === 100.10,
    var_export(probe_required_balance(100.0), true));
check('works for awkward decimals', probe_required_balance(19.99) === 20.09,
    var_export(probe_required_balance(19.99), true));
check('a refund of exactly the wallet balance is short by the gas headroom',
    probe_required_balance(50.0) > 50.0);
check('default signing limit is 100', probe_signing_limit() === 100.0, var_export(probe_signing_limit(), true));
check('default gas headroom is 0.1', probe_gas_headroom() === 0.1, var_export(probe_gas_headroom(), true));

// Both are filterable, so a merchant on a raised limit is not nagged.
$GLOBALS['filters']['sp_refund_signing_limit'] = 500.0;
$GLOBALS['filters']['sp_refund_gas_headroom'] = 0.25;
check('signing limit is filterable', probe_signing_limit() === 500.0, var_export(probe_signing_limit(), true));
check('gas headroom is filterable', probe_gas_headroom() === 0.25, var_export(probe_gas_headroom(), true));
check('required balance follows the filtered headroom', probe_required_balance(10.0) === 10.25,
    var_export(probe_required_balance(10.0), true));
$GLOBALS['filters'] = array();

// =============================================== 7. Shape logging withholds values
section('7. Diagnostic logging does not leak response values');
$shape = SP_API_Client::describe_shape(array(
    'transfer_id' => 'tr_secret_value', 'status' => 'pending_signature',
    'amount' => 25.5, 'nested' => array('api_key' => 'SUPERSECRET'),
));
check('shape shows the status verbatim (needed to diagnose)', strpos($shape, '"pending_signature"') !== false, $shape);
check('shape withholds opaque string values', strpos($shape, 'tr_secret_value') === false, $shape);
check('shape withholds nested secrets', strpos($shape, 'SUPERSECRET') === false, $shape);
check('shape still names the keys', strpos($shape, 'transfer_id:') !== false, $shape);



// ============================================ 8. Merchant fee on top of gas
section('8. Wallet must cover refund + gas + merchant fee');

$GLOBALS['filters'] = array();
check('fee defaults to 0 while the amount is unconfirmed', probe_merchant_fee(50.0) === 0.0);
check('required balance without a known fee is amount + gas', probe_required_balance(50.0) === 50.10);
$msg = probe_describe(50.0, 'USDC');
check('  -> wording does NOT quote a precise total', strpos($msg, 'more than') !== false, $msg);
check('  -> and still warns a merchant fee applies', stripos($msg, 'merchant fee') !== false, $msg);

$GLOBALS['filters']['sp_refund_merchant_fee'] = 1.5;
check('flat merchant fee is applied', probe_merchant_fee(50.0) === 1.5);
check('required balance includes gas AND fee', probe_required_balance(50.0) === 51.60,
    var_export(probe_required_balance(50.0), true));
$msg = probe_describe(50.0, 'USDC');
check('  -> wording quotes the exact total once the fee is known',
    strpos($msg, '51.6') !== false && strpos($msg, 'more than') === false, $msg);
check('  -> and itemises refund, gas and fee',
    stripos($msg, 'gas') !== false && stripos($msg, 'merchant fee') !== false, $msg);

$GLOBALS['filters'] = array();

echo "\n" . str_repeat('=', 62) . "\n";
printf("  %d passed, %d failed\n", $pass, $fail);
echo str_repeat('=', 62) . "\n";
exit($fail > 0 ? 1 : 0);
