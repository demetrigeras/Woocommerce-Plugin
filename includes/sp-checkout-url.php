<?php
/**
 * Checkout URL helpers.
 *
 * Standalone functions, not gateway methods, because a checkout URL is used in
 * several places that never touch the gateway object: the AJAX payment handler,
 * the iframe page shortcode, and URLs replayed from order meta or the session.
 * Normalising only where the URL is created misses all of those.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('sp_checkout_host_environment')) {
    /**
     * The environment a host belongs to, ignoring its subdomain.
     *
     *   api.coinsub.io   -> coinsub.io
     *   buy.syncharge.io -> syncharge.io
     *
     * @param string $host
     * @return string Empty when the host cannot be read.
     */
    function sp_checkout_host_environment($host) {
        $host = strtolower(trim((string) $host));
        if ($host === '') {
            return '';
        }

        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return $host;
        }

        $tail = array_slice($labels, -2);
        if (count($labels) >= 3 && strlen(end($labels)) <= 2 && strlen($labels[count($labels) - 2]) <= 3) {
            $tail = array_slice($labels, -3);
        }

        return implode('.', $tail);
    }
}

if (!function_exists('sp_normalize_checkout_url')) {
    /**
     * Make sure a checkout URL points at the purchase-session route.
     *
     * Purchase sessions are served at `/checkout/{code}`. The bare `/{code}` route
     * is the subscription-product route: it looks for a product, finds none, and
     * returns a 404 page that carries no Turbo frame - which the shopper sees as
     * "Content missing" rather than an error.
     *
     * Applied wherever a URL is about to be used, not just where it is created,
     * because URLs are also replayed from order meta and the WooCommerce session -
     * including ones stored by an older build, which would otherwise keep failing
     * forever on any order that already has one.
     *
     * Conservative: only a single-segment path that looks like a session code is
     * touched. Anything else is returned unchanged.
     *
     * @param string $url
     * @return string
     */
    function sp_normalize_checkout_url($url) {
        if (!is_string($url) || $url === '') {
            return $url;
        }

        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host']) || empty($parts['path'])) {
            return $url;
        }

        if (strpos($parts['path'], '/checkout/') !== false) {
            return $url;
        }

        $segments = array_values(array_filter(explode('/', $parts['path']), 'strlen'));
        if (count($segments) !== 1) {
            return $url;
        }

        $code = $segments[0];
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{5,63}$/', $code)) {
            return $url;
        }

        $rebuilt = $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . '/checkout/' . $code
            . (isset($parts['query']) ? '?' . $parts['query'] : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');

        error_log('PP: repaired checkout URL missing the /checkout/ prefix: ' . $url . ' -> ' . $rebuilt);

        return $rebuilt;
    }
}
