<?php
/**
 * Checkout URL normalisation: purchase sessions live at /checkout/{code}.
 * The bare /{code} route is the subscription-product route and 404s.
 */
define('ABSPATH', dirname(__DIR__) . '/');
function wp_parse_url($u, $c = -1) { return $c === -1 ? parse_url($u) : parse_url($u, $c); }
function apply_filters($t, $v) { return $v; }

$src = file_get_contents(dirname(__DIR__) . '/includes/class-sp-payment-gateway.php');
$i = strpos($src, 'private function normalize_checkout_url($url) {');
if ($i === false) { echo "method not found\n"; exit(1); }
$d = 0; $j = strpos($src, '{', $i); $end = -1;
for ($k = $j; $k < strlen($src); $k++) {
    if ($src[$k] === '{') $d++;
    elseif ($src[$k] === '}') { $d--; if ($d === 0) { $end = $k + 1; break; } }
}
eval('class Probe { ' . substr($src, $i, $end - $i) . '
    public function run($u) { return $this->normalize_checkout_url($u); } }');
$p = new Probe();

$pass = 0; $fail = 0;
function check($l, $c, $d = '') { global $pass,$fail; if($c){$pass++; echo "  \033[32mPASS\033[0m  $l\n";} else {$fail++; echo "  \033[31mFAIL\033[0m  $l".($d?" -- $d":"")."\n";} }
function section($t) { echo "\n\033[1m$t\033[0m\n"; }

section('Repairs a bare /{code} URL');
check('the reported failure is fixed',
    $p->run('https://buy.syncharge.io/6421479fa9') === 'https://buy.syncharge.io/checkout/6421479fa9',
    $p->run('https://buy.syncharge.io/6421479fa9'));
check('the other reported code too',
    $p->run('https://buy.syncharge.io/ca02ec14b0') === 'https://buy.syncharge.io/checkout/ca02ec14b0');
check('query string preserved',
    $p->run('https://buy.x.io/abc123def?utm=1') === 'https://buy.x.io/checkout/abc123def?utm=1',
    $p->run('https://buy.x.io/abc123def?utm=1'));
check('fragment preserved',
    $p->run('https://buy.x.io/abc123def#step') === 'https://buy.x.io/checkout/abc123def#step');
check('non-standard port preserved',
    $p->run('https://buy.x.io:8443/abc123def') === 'https://buy.x.io:8443/checkout/abc123def');

section('Leaves a correct URL untouched');
foreach (array(
    'https://buy.syncharge.io/checkout/6421479fa9',
    'https://buy.coinsub.io/checkout/abc123def?x=1',
) as $u) {
    check('unchanged: ' . $u, $p->run($u) === $u, $p->run($u));
}

section('Does not touch routes it does not understand');
$leave = array(
    'https://buy.x.io/'                        => 'no code at all',
    'https://buy.x.io'                         => 'no path',
    'https://buy.x.io/foo/bar'                 => 'multi-segment path',
    'https://buy.x.io/products/abc123def'      => 'a different named route',
    'https://buy.x.io/ab'                      => 'too short to be a code',
    'https://buy.x.io/has.dot'                 => 'not a code shape',
);
foreach ($leave as $u => $why) {
    check("unchanged ($why): $u", $p->run($u) === $u, $p->run($u));
}

section('Malformed input is returned as-is, never fataled');
foreach (array('', 'not a url', '/relative/path', 'buy.x.io/abc123def') as $u) {
    $out = $p->run($u);
    check('safe: ' . var_export($u, true), $out === $u, var_export($out, true));
}
check('null is returned unchanged', $p->run(null) === null);

echo "\n" . str_repeat('=', 58) . "\n  $pass passed, $fail failed\n" . str_repeat('=', 58) . "\n";
exit($fail ? 1 : 0);
