<?php
/**
 * Buyer prefill metadata: email + firstName + lastName let hosted checkout skip
 * its personal-information step.
 */
define('ABSPATH', dirname(__DIR__) . '/');
function __($s, $d = null) { return $s; }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
function apply_filters($t, $v) { return $v; }

class FakeOrder {
    private $e, $f, $l, $uid;
    public function __construct($e, $f, $l, $uid = 0) { $this->e=$e; $this->f=$f; $this->l=$l; $this->uid=$uid; }
    public function get_billing_email() { return $this->e; }
    public function get_billing_first_name() { return $this->f; }
    public function get_billing_last_name() { return $this->l; }
    public function get_customer_id() { return $this->uid; }
    public function get_order_number() { return '1234'; }
}

class FakeUser {
    public $first_name, $last_name, $user_email, $display_name;
    public function __construct($f='',$l='',$e='',$d=''){ $this->first_name=$f; $this->last_name=$l; $this->user_email=$e; $this->display_name=$d; }
}
$GLOBALS['users'] = array();
function get_user_by($field, $id) { return $GLOBALS['users'][$id] ?? false; }

// Exercise the real method from the shipping file.
$src = file_get_contents(dirname(__DIR__) . '/includes/class-sp-payment-gateway.php');
$san = strpos($src, 'private function sanitize_prefill_name($name) {');
$sd = 0; $sj = strpos($src, '{', $san); $send = -1;
for ($k = $sj; $k < strlen($src); $k++) {
    if ($src[$k] === '{') $sd++;
    elseif ($src[$k] === '}') { $sd--; if ($sd === 0) { $send = $k + 1; break; } }
}
$san_src = substr($src, $san, $send - $san);

$val = strpos($src, 'private function is_prefillable_name($name) {');
$vd = 0; $vj = strpos($src, '{', $val); $vend = -1;
for ($k = $vj; $k < strlen($src); $k++) {
    if ($src[$k] === '{') $vd++;
    elseif ($src[$k] === '}') { $vd--; if ($vd === 0) { $vend = $k + 1; break; } }
}
$val_src = substr($src, $val, $vend - $val);

$acct = strpos($src, 'private function get_order_account($order) {');
$ad = 0; $aj = strpos($src, '{', $acct); $aend = -1;
for ($k = $aj; $k < strlen($src); $k++) {
    if ($src[$k] === '{') $ad++;
    elseif ($src[$k] === '}') { $ad--; if ($ad === 0) { $aend = $k + 1; break; } }
}
$acct_src = substr($src, $acct, $aend - $acct);

$i = strpos($src, 'private function get_buyer_prefill_metadata($order) {');
if ($i === false) { echo "method not found\n"; exit(1); }
$d = 0; $j = strpos($src, '{', $i); $end = -1;
for ($k = $j; $k < strlen($src); $k++) {
    if ($src[$k] === '{') $d++;
    elseif ($src[$k] === '}') { $d--; if ($d === 0) { $end = $k + 1; break; } }
}
eval('class Probe { ' . $san_src . $val_src . $acct_src . substr($src, $i, $end - $i) . '
    public function run($o) { return $this->get_buyer_prefill_metadata($o); } }');

$pass = 0; $fail = 0;
function check($l, $c, $d = '') { global $pass,$fail; if($c){$pass++; echo "  \033[32mPASS\033[0m  $l\n";} else {$fail++; echo "  \033[31mFAIL\033[0m  $l".($d?" -- $d":"")."\n";} }
function section($t) { echo "\n\033[1m$t\033[0m\n"; }
$p = new Probe();

section('All three present -> checkout skips the personal-info step');
$m = $p->run(new FakeOrder('jane@example.com', 'Jane', 'Doe'));
check('email passed', ($m['email'] ?? '') === 'jane@example.com');
check('firstName passed (camelCase, as the API expects)', ($m['firstName'] ?? '') === 'Jane');
check('lastName passed', ($m['lastName'] ?? '') === 'Doe');
check('exactly the three prefill keys', count($m) === 3, json_encode($m));

section('Partial data is still sent - the step simply stays in the flow');
$m = $p->run(new FakeOrder('jane@example.com', '', ''));
check('email only', $m === array('email' => 'jane@example.com'), json_encode($m));
$m = $p->run(new FakeOrder('', 'Jane', 'Doe'));
check('names without email', $m === array('firstName' => 'Jane', 'lastName' => 'Doe'), json_encode($m));

section('Empty values are omitted, never sent blank');
$m = $p->run(new FakeOrder('', '', ''));
check('nothing to send -> empty array', $m === array(), json_encode($m));
$m = $p->run(new FakeOrder('   ', '   ', '   '));
check('whitespace-only is treated as empty', $m === array(), json_encode($m));
check('no empty-string values ever emitted',
    !in_array('', array_values($p->run(new FakeOrder('a@b.co', '', 'Doe'))), true));

section('An invalid email is omitted rather than sent as invalid');
$m = $p->run(new FakeOrder('not-an-email', 'Jane', 'Doe'));
check('bad email dropped', !isset($m['email']), json_encode($m));
check('  -> names still sent', ($m['firstName'] ?? '') === 'Jane' && ($m['lastName'] ?? '') === 'Doe');

section('Values are trimmed');
$m = $p->run(new FakeOrder('  jane@example.com  ', '  Jane  ', '  Doe  '));
check('email trimmed', ($m['email'] ?? '') === 'jane@example.com');
check('names trimmed', ($m['firstName'] ?? '') === 'Jane' && ($m['lastName'] ?? '') === 'Doe');


section('Falls back to the WordPress account when billing has no name');
$GLOBALS['users'][7] = new FakeUser('Jane', 'Doe', 'acct@example.com', 'Jane Doe');

$m = $p->run(new FakeOrder('billing@example.com', '', '', 7));
check('missing both names -> taken from the account',
    ($m['firstName'] ?? '') === 'Jane' && ($m['lastName'] ?? '') === 'Doe', json_encode($m));
check('  -> billing email still wins over the account email',
    ($m['email'] ?? '') === 'billing@example.com', json_encode($m));

$m = $p->run(new FakeOrder('billing@example.com', 'Bob', '', 7));
check('only last name missing -> only that is filled from the account',
    ($m['firstName'] ?? '') === 'Bob' && ($m['lastName'] ?? '') === 'Doe', json_encode($m));

$m = $p->run(new FakeOrder('', '', '', 7));
check('missing email too -> account email used',
    ($m['email'] ?? '') === 'acct@example.com', json_encode($m));

section('Billing always wins when it has a value');
$m = $p->run(new FakeOrder('billing@example.com', 'Alice', 'Smith', 7));
check('account is not consulted', ($m['firstName'] ?? '') === 'Alice' && ($m['lastName'] ?? '') === 'Smith');

section('display_name is the last resort');
$GLOBALS['users'][8] = new FakeUser('', '', 'd@example.com', 'Mary Jane Watson');
$m = $p->run(new FakeOrder('b@example.com', '', '', 8));
check('display_name split on the first space',
    ($m['firstName'] ?? '') === 'Mary' && ($m['lastName'] ?? '') === 'Jane Watson', json_encode($m));

$GLOBALS['users'][9] = new FakeUser('', '', 'd@example.com', 'Cher');
$m = $p->run(new FakeOrder('b@example.com', '', '', 9));
check('a single-word display_name is NOT split into a fake surname',
    !isset($m['firstName']) && !isset($m['lastName']), json_encode($m));

section('Nothing to fall back on -> nothing invented');
$m = $p->run(new FakeOrder('b@example.com', '', '', 0));         // guest checkout
check('guest order sends only the email', $m === array('email' => 'b@example.com'), json_encode($m));
$GLOBALS['users'][10] = new FakeUser('', '', '', '');
$m = $p->run(new FakeOrder('', '', '', 10));
check('empty account + empty billing -> nothing sent', $m === array(), json_encode($m));
$m = $p->run(new FakeOrder('', '', '', 999));                    // customer id with no user row
check('missing user row is handled', $m === array(), json_encode($m));



section('Server contract: names must match ^[\p{L} ]+$ (letters and spaces only)');

// Accepted by the server -> we must send them.
$ok_names = array(
    'Jane'            => 'plain ASCII',
    'Mary Jane'       => 'space is allowed',
    'José'            => 'accented letters are \p{L}',
    'Ægir'            => 'ligatures',
    'Müller'          => 'umlaut',
    'Ольга'           => 'Cyrillic',
    '李'              => 'CJK',
);
foreach ($ok_names as $name => $why) {
    $m = $p->run(new FakeOrder('a@b.co', $name, 'Doe'));
    check("accepted: \"$name\" ($why)", ($m['firstName'] ?? '') === $name, json_encode($m));
}

// Reshaped to pass validation rather than dropped - an approximate name is a
// fair trade for skipping the step. Email is never touched.
$normalised = array(
    "O'Brien"   => array("OBrien",    "apostrophe removed"),
    "Mary-Jane" => array("Mary Jane", "hyphen becomes a space"),
    "J.R."      => array("JR",        "periods removed"),
    "Jane2"     => array("Jane",      "digit removed"),
    "Jane_Doe"  => array("Jane Doe",  "underscore becomes a space"),
    "@Jane"     => array("Jane",      "symbol removed"),
    "  Ana   Maria  " => array("Ana Maria", "whitespace collapsed"),
);
foreach ($normalised as $raw => $exp) {
    $m = $p->run(new FakeOrder("a@b.co", $raw, "Doe"));
    check("normalised: \"$raw\" -> \"{$exp[0]}\" ({$exp[1]})",
        ($m["firstName"] ?? "") === $exp[0], json_encode($m));
}

section("A name with nothing usable is still omitted");
foreach (array("123", "!!!", "   ") as $junk) {
    $m = $p->run(new FakeOrder("a@b.co", $junk, "Doe"));
    check("omitted: " . var_export($junk, true), !isset($m["firstName"]), json_encode($m));
}

section('An unusable name does not suppress the valid fields');
$m = $p->run(new FakeOrder('a@b.co', '123', 'Doe'));
check('email still sent', ($m['email'] ?? '') === 'a@b.co');
check('valid lastName still sent', ($m['lastName'] ?? '') === 'Doe');
check('prefill is incomplete, so the step stays', count($m) < 3, json_encode($m));

section('A punctuated name now COMPLETES the prefill');
$m = $p->run(new FakeOrder('a@b.co', "O'Brien", 'Mary-Jane'));
check('all three present -> step is skipped', count($m) === 3, json_encode($m));
check('  -> names normalised', ($m['firstName'] ?? '') === 'OBrien' && ($m['lastName'] ?? '') === 'Mary Jane');

section('Multi-word names from display_name still validate');
$GLOBALS['users'][20] = new FakeUser('', '', 'd@e.co', 'Mary Jane Watson');
$m = $p->run(new FakeOrder('b@c.co', '', '', 20));
check('"Jane Watson" passes (spaces allowed)', ($m['lastName'] ?? '') === 'Jane Watson', json_encode($m));

$GLOBALS['users'][21] = new FakeUser('', '', 'd@e.co', "Mary O'Brien");
$m = $p->run(new FakeOrder('b@c.co', '', '', 21));
// Normalised rather than dropped, so an account name with punctuation now
// yields a complete prefill instead of half of one.
check('a punctuated surname from display_name is normalised',
    ($m['firstName'] ?? '') === 'Mary' && ($m['lastName'] ?? '') === 'OBrien', json_encode($m));
check('  -> and that makes the prefill complete', count($m) === 3, json_encode($m));

section('Values are sent as JSON strings (metadataString only accepts strings)');
$m = $p->run(new FakeOrder('a@b.co', 'Jane', 'Doe'));
foreach ($m as $k => $v) { check("$k is a string", is_string($v), gettype($v)); }
$decoded = json_decode(json_encode($m), true);
check('survives a JSON round trip as strings',
    is_string($decoded['firstName']) && is_string($decoded['lastName']) && is_string($decoded['email']));


echo "\n" . str_repeat('=', 58) . "\n  $pass passed, $fail failed\n" . str_repeat('=', 58) . "\n";
exit($fail ? 1 : 0);
