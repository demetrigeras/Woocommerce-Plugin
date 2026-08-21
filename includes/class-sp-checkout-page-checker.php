<?php
/**
 * Stablecoin Pay - Checkout Page Checker
 *
 * Detects whether the merchant's WooCommerce Checkout page is configured at
 * all. Both supported entry points (the `[woocommerce_checkout]` shortcode
 * and the `wp:woocommerce/checkout` block) are accepted — the only failure
 * modes we warn about are:
 *   - No Checkout page is configured in WooCommerce → Settings → Advanced
 *   - The configured Checkout page exists but has no checkout form on it
 *
 * A one-click fix inserts the modern Checkout block (what a fresh WC install
 * ships with) so the merchant never has to hand-edit page content.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SP_Checkout_Page_Checker {

    const FIX_ACTION  = 'sp_fix_checkout_page';
    const NONCE_NAME  = 'sp_fix_checkout_nonce';
    const DISMISS_KEY = 'sp_dismiss_checkout_notice';

    /**
     * Hook everything up.
     */
    public static function init() {
        add_action('admin_notices', array(__CLASS__, 'maybe_render_fix_result'));
        add_action('admin_notices', array(__CLASS__, 'maybe_render_notice'));
        add_action('admin_post_' . self::FIX_ACTION, array(__CLASS__, 'handle_fix_action'));
    }

    /**
     * Render a transient banner after the "fix" action runs so the merchant
     * gets clear feedback that something happened.
     */
    public static function maybe_render_fix_result() {
        if (!isset($_GET['sp_checkout_fix'])) {
            return;
        }
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $flag = sanitize_key(wp_unslash($_GET['sp_checkout_fix']));
        switch ($flag) {
            case 'fixed':
                echo '<div class="notice notice-success is-dismissible"><p>';
                echo esc_html__('Checkout page updated: the WooCommerce checkout form is now in place. Reload the storefront checkout to verify.', 'stablecoin-pay');
                echo '</p></div>';
                break;
            case 'already_ok':
                echo '<div class="notice notice-info is-dismissible"><p>';
                echo esc_html__('Checkout page already contains the WooCommerce checkout form — no changes were made.', 'stablecoin-pay');
                echo '</p></div>';
                break;
            case 'error':
                echo '<div class="notice notice-error is-dismissible"><p>';
                echo esc_html__('Could not update the Checkout page automatically. Please edit it manually and make sure the WooCommerce Checkout block is on the page.', 'stablecoin-pay');
                echo '</p></div>';
                break;
            case 'no_page':
                echo '<div class="notice notice-error is-dismissible"><p>';
                echo esc_html__('No Checkout page is configured in WooCommerce → Settings → Advanced. Set one and try again.', 'stablecoin-pay');
                echo '</p></div>';
                break;
        }
    }

    /**
     * Inspect the configured WooCommerce Checkout page.
     *
     * Both the legacy shortcode (`[woocommerce_checkout]`) and the modern
     * `wp:woocommerce/checkout` block are accepted as a valid checkout
     * form — the gateway works with either.
     *
     * @return array{state:string,page_id:int,page:?WP_Post,reason:string}
     *   state is one of: 'ok', 'missing', 'no_page'
     */
    public static function inspect_checkout_page() {
        if (!function_exists('wc_get_page_id')) {
            return array(
                'state'   => 'no_page',
                'page_id' => 0,
                'page'    => null,
                'reason'  => 'WooCommerce not active',
            );
        }

        $page_id = (int) wc_get_page_id('checkout');
        if ($page_id <= 0) {
            return array(
                'state'   => 'no_page',
                'page_id' => 0,
                'page'    => null,
                'reason'  => 'No Checkout page is configured in WooCommerce → Settings → Advanced.',
            );
        }

        $page = get_post($page_id);
        if (!$page || $page->post_status === 'trash') {
            return array(
                'state'   => 'no_page',
                'page_id' => $page_id,
                'page'    => null,
                'reason'  => 'The configured Checkout page does not exist or is in the trash.',
            );
        }

        $content = (string) $page->post_content;

        $has_shortcode = has_shortcode($content, 'woocommerce_checkout');
        $has_block = function_exists('has_block')
            ? has_block('woocommerce/checkout', $content)
            : (strpos($content, '<!-- wp:woocommerce/checkout') !== false);

        if ($has_shortcode || $has_block) {
            return array('state' => 'ok', 'page_id' => $page_id, 'page' => $page, 'reason' => '');
        }

        return array(
            'state'   => 'missing',
            'page_id' => $page_id,
            'page'    => $page,
            'reason'  => 'The Checkout page does not contain the WooCommerce Checkout block or shortcode.',
        );
    }

    /**
     * Render an admin notice on relevant admin pages if the Checkout page is
     * misconfigured.
     */
    public static function maybe_render_notice() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // Only show on screens the merchant is likely to be looking at when
        // they care: WooCommerce settings, the plugins list, and the dashboard.
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $screen_id = $screen ? $screen->id : '';
        $allowed_screens = array(
            'dashboard',
            'plugins',
            'woocommerce_page_wc-settings',
            'woocommerce_page_wc-status',
        );
        if (!in_array($screen_id, $allowed_screens, true)) {
            return;
        }

        // Respect dismissal until the user takes another upgrade.
        if (get_user_meta(get_current_user_id(), self::DISMISS_KEY, true) === '1') {
            return;
        }

        $result = self::inspect_checkout_page();
        if ($result['state'] === 'ok') {
            return;
        }

        $brand = self::get_brand_name();
        $fix_url = wp_nonce_url(
            admin_url('admin-post.php?action=' . self::FIX_ACTION),
            self::FIX_ACTION,
            self::NONCE_NAME
        );

        if ($result['state'] === 'missing') {
            $notice_class = 'notice-error';
            $headline = sprintf(
                /* translators: %s: brand name */
                __('%s: your Checkout page is missing the WooCommerce checkout form', 'stablecoin-pay'),
                $brand
            );
            $body = __('Your WooCommerce Checkout page does not contain a checkout form, so customers have nothing to fill out when they go to pay. Click below to add the standard WooCommerce Checkout block to the page automatically.', 'stablecoin-pay');
            $button_label = __('Add the WooCommerce Checkout block for me', 'stablecoin-pay');
        } else {
            $notice_class = 'notice-error';
            $headline = sprintf(
                /* translators: %s: brand name */
                __('%s: no Checkout page is configured', 'stablecoin-pay'),
                $brand
            );
            $body = esc_html($result['reason']);
            $button_label = '';
        }

        echo '<div class="notice ' . esc_attr($notice_class) . '">';
        echo '<p><strong>' . esc_html($headline) . '</strong></p>';
        echo '<p>' . esc_html($body) . '</p>';
        if (!empty($button_label)) {
            echo '<p>';
            echo '<a href="' . esc_url($fix_url) . '" class="button button-primary">'
                . esc_html($button_label) . '</a> ';
            if (!empty($result['page_id'])) {
                echo '<a href="' . esc_url(get_edit_post_link($result['page_id'])) . '" class="button">'
                    . esc_html__('Edit the Checkout page myself', 'stablecoin-pay') . '</a>';
            }
            echo '</p>';
        } else {
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=advanced')) . '" class="button button-primary">'
                . esc_html__('Open WooCommerce → Settings → Advanced', 'stablecoin-pay') . '</a></p>';
        }
        echo '</div>';
    }

    /**
     * Handle the one-click "fix the checkout page" link from the admin notice.
     */
    public static function handle_fix_action() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to modify the Checkout page.', 'stablecoin-pay'), 403);
        }
        check_admin_referer(self::FIX_ACTION, self::NONCE_NAME);

        $result = self::inspect_checkout_page();
        if ($result['state'] === 'ok') {
            self::redirect_back_with_flag('already_ok');
            return;
        }

        if (empty($result['page_id']) || !$result['page']) {
            self::redirect_back_with_flag('no_page');
            return;
        }

        $page = $result['page'];

        // Insert the modern WooCommerce Checkout block — same markup a
        // fresh WC install ships with. Works with both classic and block
        // checkout customer experiences.
        $new_content = '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout is-loading"></div><!-- /wp:woocommerce/checkout -->';

        $update = wp_update_post(array(
            'ID'           => $page->ID,
            'post_content' => $new_content,
        ), true);

        if (is_wp_error($update)) {
            error_log('PP: Failed to update Checkout page: ' . $update->get_error_message());
            self::redirect_back_with_flag('error');
            return;
        }

        self::redirect_back_with_flag('fixed');
    }

    /**
     * Redirect back to the page the admin came from, with a status flag.
     */
    private static function redirect_back_with_flag($flag) {
        $back = wp_get_referer();
        if (!$back) {
            $back = admin_url('admin.php?page=wc-settings&tab=checkout&section=sp');
        }
        $back = add_query_arg('sp_checkout_fix', rawurlencode($flag), $back);
        wp_safe_redirect($back);
        exit;
    }

   
    private static function get_brand_name() {
        $branding = get_option('sp_whitelabel_branding', array());
        if (!empty($branding['company_name'])) {
            return $branding['company_name'];
        }

        $config_file = SP_PLUGIN_DIR . 'sp-whitelabel-config.php';
        if (file_exists($config_file)) {
            $config = include $config_file;
            if (is_array($config) && !empty($config['plugin_name'])) {
                return $config['plugin_name'];
            }
        }

        return 'Stablecoin Pay';
    }
}

SP_Checkout_Page_Checker::init();
