<?php
/**
 * Integration diagnostics.
 *
 * When a store is headless, "it doesn't work" can mean the gateway never
 * registered, another plugin filtered it out, REST is blocked, or the
 * storefront simply never renders what we publish. Those look identical from
 * outside the site. This page reports what the plugin itself can see from
 * inside WordPress, so the cause is a fact rather than a guess.
 *
 * Admin-only (`manage_woocommerce`). No credential is ever printed - key and
 * secret fields are reported as "set" or "empty" only.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SP_Admin_Diagnostics {

    const GATEWAY_ID = 'sp';

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Payment Provider Diagnostics',
            'Payment Provider Diagnostics',
            'manage_woocommerce',
            'sp-diagnostics',
            array($this, 'display_page')
        );
    }

    /* ---------------------------------------------------------------------
     * Rendering
     * ------------------------------------------------------------------ */

    public function display_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to view this page.', 'stablecoin-pay'));
        }

        $checks = $this->run_checks();
        $report = $this->build_text_report($checks);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Integration Diagnostics', 'stablecoin-pay'); ?></h1>
            <p>
                <?php esc_html_e('Each row is checked live inside WordPress. Send the report at the bottom when asking for support - it contains no keys or secrets.', 'stablecoin-pay'); ?>
            </p>

            <table class="widefat striped" style="max-width:1100px;margin-top:16px;">
                <thead>
                    <tr>
                        <th style="width:60px;"><?php esc_html_e('Status', 'stablecoin-pay'); ?></th>
                        <th style="width:260px;"><?php esc_html_e('Check', 'stablecoin-pay'); ?></th>
                        <th><?php esc_html_e('Result', 'stablecoin-pay'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($checks as $check) : ?>
                    <tr>
                        <td style="font-size:18px;text-align:center;"><?php echo esc_html($this->status_icon($check['status'])); ?></td>
                        <td><strong><?php echo esc_html($check['label']); ?></strong></td>
                        <td>
                            <?php echo esc_html($check['result']); ?>
                            <?php if (!empty($check['detail'])) : ?>
                                <div style="color:#666;margin-top:4px;"><?php echo esc_html($check['detail']); ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2 style="margin-top:28px;"><?php esc_html_e('Copyable report', 'stablecoin-pay'); ?></h2>
            <textarea readonly rows="20" style="width:100%;max-width:1100px;font-family:monospace;font-size:12px;" onclick="this.select();"><?php echo esc_textarea($report); ?></textarea>
        </div>
        <?php
    }

    private function status_icon($status) {
        if ($status === 'ok') {
            return '[OK]';
        }
        if ($status === 'warn') {
            return '[!]';
        }
        if ($status === 'fail') {
            return '[X]';
        }
        return '[i]';
    }

    private function build_text_report($checks) {
        $lines = array();
        $lines[] = 'Integration diagnostics - ' . gmdate('Y-m-d H:i:s') . ' UTC';
        $lines[] = str_repeat('-', 60);
        foreach ($checks as $check) {
            $lines[] = sprintf('[%s] %s: %s', strtoupper($check['status']), $check['label'], $check['result']);
            if (!empty($check['detail'])) {
                $lines[] = '        ' . $check['detail'];
            }
        }
        return implode("\n", $lines);
    }

    /* ---------------------------------------------------------------------
     * Checks
     * ------------------------------------------------------------------ */

    private function run_checks() {
        return array(
            $this->check_environment(),
            $this->check_settings(),
            $this->check_gateway_registered(),
            $this->check_gateway_available(),
            $this->check_gateway_filters(),
            $this->check_rest_route(),
            $this->check_rest_loopback(),
            $this->check_store_api_extension(),
            $this->check_rest_auth_filters(),
            $this->check_other_plugins(),
        );
    }

    private function check_environment() {
        $home = wp_parse_url(home_url(), PHP_URL_HOST);
        $site = wp_parse_url(site_url(), PHP_URL_HOST);
        $split = ($home && $site && strcasecmp($home, $site) !== 0);

        return array(
            'label'  => 'Site addresses',
            'status' => 'info',
            'result' => sprintf('WordPress: %s | Site: %s', (string) $site, (string) $home),
            'detail' => $split
                ? 'WordPress Address and Site Address differ. Treated as a decoupled setup.'
                : 'Both point at the same host. A separate storefront domain is still headless as far as this plugin is concerned.',
        );
    }

    private function check_settings() {
        $settings = get_option('woocommerce_' . self::GATEWAY_ID . '_settings', array());
        $enabled  = (isset($settings['enabled']) && $settings['enabled'] === 'yes');
        $has_mid  = !empty($settings['merchant_id']);
        $has_key  = !empty($settings['api_key']);

        $missing = array();
        if (!$enabled) { $missing[] = 'not enabled'; }
        if (!$has_mid) { $missing[] = 'merchant ID empty'; }
        if (!$has_key) { $missing[] = 'API key empty'; }

        return array(
            'label'  => 'Gateway settings',
            'status' => empty($missing) ? 'ok' : 'fail',
            // Never print the values themselves.
            'result' => sprintf(
                'enabled: %s | merchant ID: %s | API key: %s',
                $enabled ? 'yes' : 'no',
                $has_mid ? 'set' : 'EMPTY',
                $has_key ? 'set' : 'EMPTY'
            ),
            'detail' => empty($missing) ? '' : 'Gateway hides itself while: ' . implode(', ', $missing) . '.',
        );
    }

    private function check_gateway_registered() {
        if (!function_exists('WC') || !WC()->payment_gateways()) {
            return array(
                'label'  => 'Gateway registered',
                'status' => 'fail',
                'result' => 'WooCommerce payment gateways unavailable',
                'detail' => 'WooCommerce may not be active.',
            );
        }

        $gateways = WC()->payment_gateways()->payment_gateways();
        $found    = isset($gateways[self::GATEWAY_ID]);

        return array(
            'label'  => 'Gateway registered',
            'status' => $found ? 'ok' : 'fail',
            'result' => $found
                ? 'Yes - "' . self::GATEWAY_ID . '" is in the gateway list'
                : 'No - "' . self::GATEWAY_ID . '" is missing from the gateway list',
            'detail' => $found
                ? 'Title as WooCommerce sees it: "' . $gateways[self::GATEWAY_ID]->get_title() . '"'
                : 'Something removed it through the woocommerce_payment_gateways filter. See the filter row below.',
        );
    }

    private function check_gateway_available() {
        if (!function_exists('WC') || !WC()->payment_gateways()) {
            return array(
                'label'  => 'Gateway offered at checkout',
                'status' => 'fail',
                'result' => 'WooCommerce unavailable',
                'detail' => '',
            );
        }

        $available = WC()->payment_gateways()->get_available_payment_gateways();
        $found     = isset($available[self::GATEWAY_ID]);

        return array(
            'label'  => 'Gateway offered at checkout',
            'status' => $found ? 'ok' : 'warn',
            'result' => $found
                ? 'Yes - the Store API will list "' . self::GATEWAY_ID . '"'
                : 'No - the Store API will not list "' . self::GATEWAY_ID . '"',
            'detail' => 'Currently offered: ' . (empty($available) ? '(none)' : implode(', ', array_keys($available)))
                . '. Checked from wp-admin, so cart-dependent rules (shipping, totals, currency) are not fully exercised here.',
        );
    }

    private function check_gateway_filters() {
        $hooks   = array('woocommerce_payment_gateways', 'woocommerce_available_payment_gateways');
        $foreign = array();

        foreach ($hooks as $hook) {
            foreach ($this->describe_hook_callbacks($hook) as $desc) {
                if ($desc['source'] !== 'this plugin') {
                    $foreign[] = $hook . ' <- ' . $desc['source'] . ' (' . $desc['callback'] . ')';
                }
            }
        }

        return array(
            'label'  => 'Who else filters gateways',
            'status' => empty($foreign) ? 'ok' : 'warn',
            'result' => empty($foreign)
                ? 'Nothing outside this plugin filters the gateway list'
                : count($foreign) . ' external filter(s) can add or remove gateways',
            'detail' => empty($foreign) ? '' : implode(' | ', $foreign),
        );
    }

    private function check_rest_route() {
        $namespaces = class_exists('SP_Webhook_Provisioner')
            ? SP_Webhook_Provisioner::all_namespaces()
            : array('woowh/v1');

        $routes = rest_get_server()->get_routes();
        $found  = array();
        foreach ($namespaces as $namespace) {
            $route = '/' . trim($namespace, '/') . '/payment-method';
            if (isset($routes[$route])) {
                $found[] = $route;
            }
        }

        return array(
            'label'  => 'Branding REST route',
            'status' => empty($found) ? 'fail' : 'ok',
            'result' => empty($found)
                ? 'Not registered'
                : 'Registered: ' . implode(', ', $found),
            'detail' => empty($found)
                ? 'includes/sp-storefront-rest.php did not load. The deployed build is older than this feature.'
                : 'Public URL: ' . rest_url(ltrim($found[0], '/')),
        );
    }

    private function check_rest_loopback() {
        $namespaces = class_exists('SP_Webhook_Provisioner')
            ? SP_Webhook_Provisioner::all_namespaces()
            : array('woowh/v1');
        $url = rest_url(trim($namespaces[0], '/') . '/payment-method');

        $response = wp_remote_get($url, array(
            'timeout'   => 10,
            'sslverify' => false,
        ));

        if (is_wp_error($response)) {
            return array(
                'label'  => 'REST reachable from the server',
                'status' => 'fail',
                'result' => 'Request failed: ' . $response->get_error_message(),
                'detail' => 'URL tried: ' . $url,
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $ok   = ($code === 200);

        $title   = '';
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['title'])) {
            $title = (string) $decoded['title'];
        }

        return array(
            'label'  => 'REST reachable from the server',
            'status' => $ok ? 'ok' : 'fail',
            'result' => 'HTTP ' . $code . ($title !== '' ? ' - title: "' . $title . '"' : ''),
            'detail' => $ok
                ? 'URL: ' . $url . ' - this is the URL a headless storefront should call.'
                : 'URL: ' . $url . ' - a non-200 here means REST is blocked on this site (security plugin, server rule, or the route is missing). First 200 chars of the body: ' . substr($body, 0, 200),
        );
    }

    private function check_store_api_extension() {
        $available = function_exists('woocommerce_store_api_register_endpoint_data');
        $hooked    = has_action('woocommerce_blocks_loaded', 'sp_register_storefront_store_api_data');

        if (!$available) {
            return array(
                'label'  => 'Store API branding field',
                'status' => 'warn',
                'result' => 'Store API extensions not supported by this WooCommerce version',
                'detail' => 'The storefront must use the branding REST route instead.',
            );
        }

        return array(
            'label'  => 'Store API branding field',
            'status' => $hooked ? 'ok' : 'warn',
            'result' => $hooked
                ? 'Registered - cart responses carry extensions.stablecoin_pay'
                : 'Not registered',
            'detail' => $hooked
                ? 'Storefront should read cart.extensions.stablecoin_pay.title instead of printing the raw slug.'
                : 'includes/sp-storefront-rest.php did not load, or loaded after woocommerce_blocks_loaded fired.',
        );
    }

    private function check_rest_auth_filters() {
        $hooks   = array('rest_authentication_errors', 'rest_pre_dispatch', 'rest_api_init');
        $foreign = array();

        foreach ($hooks as $hook) {
            foreach ($this->describe_hook_callbacks($hook) as $desc) {
                $source = $desc['source'];
                if ($source !== 'this plugin' && $source !== 'WordPress core' && $source !== 'WooCommerce') {
                    $foreign[] = $hook . ' <- ' . $source;
                }
            }
        }

        $foreign = array_values(array_unique($foreign));

        return array(
            'label'  => 'Who else filters REST',
            'status' => empty($foreign) ? 'ok' : 'warn',
            'result' => empty($foreign)
                ? 'Nothing outside core and WooCommerce intercepts REST'
                : count($foreign) . ' external REST filter(s) present',
            'detail' => empty($foreign)
                ? ''
                : 'These can reject requests before the plugin sees them: ' . implode(' | ', $foreign),
        );
    }

    private function check_other_plugins() {
        $active = (array) get_option('active_plugins', array());
        $names  = array();
        foreach ($active as $file) {
            $names[] = dirname($file);
        }
        sort($names);

        $notable = array_values(array_filter($names, function ($name) {
            return (bool) preg_match('/headless|graphql|cocart|jwt|rest|cors|wordfence|security|firewall|cache/i', $name);
        }));

        return array(
            'label'  => 'Active plugins',
            'status' => empty($notable) ? 'info' : 'warn',
            'result' => count($names) . ' active',
            'detail' => (empty($notable) ? '' : 'Can affect REST or checkout: ' . implode(', ', $notable) . '. ')
                . 'All: ' . implode(', ', $names),
        );
    }

    /* ---------------------------------------------------------------------
     * Hook introspection
     * ------------------------------------------------------------------ */

    /**
     * List every callback attached to a hook, with the plugin it came from.
     *
     * @param string $hook
     * @return array
     */
    private function describe_hook_callbacks($hook) {
        global $wp_filter;

        if (empty($wp_filter[$hook])) {
            return array();
        }

        $out = array();
        foreach ($wp_filter[$hook]->callbacks as $callbacks) {
            foreach ($callbacks as $entry) {
                if (!isset($entry['function'])) {
                    continue;
                }
                $file  = $this->callback_file($entry['function']);
                $out[] = array(
                    'callback' => $this->callback_name($entry['function']),
                    'source'   => $this->file_source($file),
                );
            }
        }

        return $out;
    }

    private function callback_name($callback) {
        if (is_string($callback)) {
            return $callback;
        }
        if (is_array($callback) && count($callback) === 2) {
            $class = is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0];
            return $class . '::' . (string) $callback[1];
        }
        if ($callback instanceof Closure) {
            return 'closure';
        }
        if (is_object($callback)) {
            return get_class($callback) . '::__invoke';
        }
        return 'unknown';
    }

    /**
     * Resolve the file a callback is defined in. Returns an empty string when
     * it cannot be determined - reflection fails on internal or dynamic
     * callables, and a diagnostics page must never be the thing that breaks.
     */
    private function callback_file($callback) {
        try {
            if (is_string($callback) && function_exists($callback)) {
                $ref = new ReflectionFunction($callback);
            } elseif ($callback instanceof Closure) {
                $ref = new ReflectionFunction($callback);
            } elseif (is_array($callback) && count($callback) === 2) {
                $ref = new ReflectionMethod($callback[0], $callback[1]);
            } elseif (is_object($callback) && method_exists($callback, '__invoke')) {
                $ref = new ReflectionMethod($callback, '__invoke');
            } else {
                return '';
            }
            return (string) $ref->getFileName();
        } catch (Exception $e) {
            return '';
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Map a file path to a human-readable owner.
     */
    private function file_source($file) {
        if ($file === '') {
            return 'unknown';
        }

        $file = wp_normalize_path($file);

        if (defined('SP_PLUGIN_DIR') && strpos($file, wp_normalize_path(SP_PLUGIN_DIR)) === 0) {
            return 'this plugin';
        }

        if (defined('WPMU_PLUGIN_DIR') && strpos($file, wp_normalize_path(WPMU_PLUGIN_DIR)) === 0) {
            return 'must-use plugin';
        }

        $plugin_dir = wp_normalize_path(WP_PLUGIN_DIR);
        if (strpos($file, $plugin_dir) === 0) {
            $relative = ltrim(substr($file, strlen($plugin_dir)), '/');
            $parts    = explode('/', $relative);
            $folder   = isset($parts[0]) ? $parts[0] : $relative;
            if ($folder === 'woocommerce') {
                return 'WooCommerce';
            }
            return 'plugin: ' . $folder;
        }

        if (strpos($file, wp_normalize_path(get_theme_root())) === 0) {
            return 'theme';
        }

        if (strpos($file, wp_normalize_path(ABSPATH . WPINC)) === 0
            || strpos($file, wp_normalize_path(ABSPATH . 'wp-admin')) === 0) {
            return 'WordPress core';
        }

        return 'other';
    }
}
