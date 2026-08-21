<?php
/**
 * Admin surface for webhook auto-provisioning.
 *
 * Shows the outcome of the last registration attempt and offers a retry, so a
 * failure is visible and recoverable without the merchant having to re-save
 * settings or open the dashboard.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SP_Webhook_Admin {

    const RETRY_ACTION = 'sp_retry_webhook_setup';

    public function __construct() {
        add_action('admin_notices', array($this, 'render_notice'));
        add_action('admin_post_' . self::RETRY_ACTION, array($this, 'handle_retry'));
    }

    /**
     * Surface provisioning problems. Success is only announced once, right after
     * a save, so a healthy site does not carry a permanent banner.
     */
    public function render_notice() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $status = SP_Webhook_Provisioner::status();
        if (empty($status['state'])) {
            return;
        }

        $state   = $status['state'];
        $message = isset($status['message']) ? $status['message'] : '';

        if ($state === 'ok') {
            // Only on the gateway settings screen, and only for a few minutes
            // after the save that produced it.
            if (!$this->is_gateway_screen()) {
                return;
            }
            if (empty($status['time']) || (time() - (int) $status['time']) > 5 * MINUTE_IN_SECONDS) {
                return;
            }
            printf(
                '<div class="notice notice-success is-dismissible"><p><strong>%s</strong> %s</p></div>',
                esc_html__('Webhook ready.', 'stablecoin-pay'),
                esc_html($message)
            );
            return;
        }

        // "incomplete" just means credentials have not been entered yet - that is
        // not a failure worth shouting about outside the settings screen.
        if ($state === 'incomplete' && !$this->is_gateway_screen()) {
            return;
        }

        $class = ($state === 'unreachable' || $state === 'incomplete') ? 'notice-warning' : 'notice-error';

        printf(
            '<div class="notice %1$s"><p><strong>%2$s</strong> %3$s</p>%4$s</div>',
            esc_attr($class),
            esc_html__('Payment webhook not registered.', 'stablecoin-pay'),
            esc_html($message),
            $state === 'incomplete' ? '' : $this->retry_button()
        );
    }

    private function retry_button() {
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=' . self::RETRY_ACTION),
            self::RETRY_ACTION
        );

        return sprintf(
            '<p><a href="%1$s" class="button button-secondary">%2$s</a></p>',
            esc_url($url),
            esc_html__('Retry webhook setup', 'stablecoin-pay')
        );
    }

    public function handle_retry() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do that.', 'stablecoin-pay'));
        }
        check_admin_referer(self::RETRY_ACTION);

        SP_Webhook_Provisioner::sync();

        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=wc-settings&tab=checkout&section=sp'));
        exit;
    }

    private function is_gateway_screen() {
        if (!isset($_GET['page'], $_GET['section'])) {
            return false;
        }
        return $_GET['page'] === 'wc-settings' && $_GET['section'] === 'sp';
    }
}
