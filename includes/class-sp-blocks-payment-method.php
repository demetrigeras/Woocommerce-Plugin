<?php
/**
 * Stablecoin Pay — WooCommerce Blocks (Block Checkout) Integration
 *
 * Registers the gateway as a block-aware payment method so it appears on the
 * new React-based WooCommerce block checkout. The classic shortcode checkout
 * is handled separately by `includes/sp-checkout-modal.php` — both can be
 * active at the same time.
 *
 * To finish enabling this integration:
 *   1. Build the JS bundle:   `npm install && npm run build`
 *   2. Flip the constant:      define('SP_BLOCKS_CHECKOUT_ENABLED', true);
 *      (set in stablecoin-pay.php once `build/index.js` exists and the React
 *      `onPaymentSetup` flow is filled in)
 *
 * Until both are done, this class is a harmless no-op: it registers a payment
 * method type that has no JS handle to load, so Woo silently skips it on the
 * block checkout — meaning merchants don't see a broken option there.
 *
 * Reference docs:
 *   - https://github.com/woocommerce/woocommerce-blocks/blob/trunk/docs/third-party-developers/extensibility/checkout-payment-methods/payment-method-integration.md
 *   - AbstractPaymentMethodType: woocommerce/src/Blocks/Payments/Integrations/AbstractPaymentMethodType.php
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')) {
    // Woo Blocks not loaded yet (e.g. WC < 5.5 or WC Blocks disabled).
    // Bail silently — classic checkout still works via sp-checkout-modal.php.
    return;
}

class SP_Blocks_Payment_Method extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

    /**
     * Payment method slug. MUST match the classic gateway ID
     * (`WC_Gateway_SP::$id` = 'sp') so the same `process_payment()`
     * handles both flows server-side.
     *
     * @var string
     */
    protected $name = 'sp';

    /**
     * Pull settings from the existing gateway options so we don't duplicate
     * a separate settings store.
     */
    public function initialize() {
        $this->settings = get_option('woocommerce_sp_settings', array());
    }

    /**
     * Whether the payment method is available. Mirrors classic gateway
     * availability so a merchant who disables the gateway in the admin sees
     * it disappear from BOTH checkouts in lockstep.
     */
    public function is_active() {
        if (empty($this->settings['enabled']) || $this->settings['enabled'] !== 'yes') {
            return false;
        }

        // Only advertise on block checkout when the JS bundle is built AND
        // the integration has been opted-in. This avoids showing a broken
        // option to merchants before the React side is finished.
        if (!defined('SP_BLOCKS_CHECKOUT_ENABLED') || !SP_BLOCKS_CHECKOUT_ENABLED) {
            return false;
        }

        return file_exists(SP_PLUGIN_DIR . 'build/index.js');
    }

    /**
     * Register the JS bundle that drives the block checkout payment method UI.
     *
     * `@wordpress/scripts` emits two files per entry: `build/index.js` and
     * `build/index.asset.php`. The .asset.php file lists exact dependencies
     * and a content hash for cache-busting — we read it here so we don't
     * have to hand-maintain a dependency list.
     */
    public function get_payment_method_script_handles() {
        $handle = 'sp-blocks';
        $script_path = 'build/index.js';
        $script_url = SP_PLUGIN_URL . $script_path;
        $script_asset_path = SP_PLUGIN_DIR . 'build/index.asset.php';

        if (!file_exists(SP_PLUGIN_DIR . $script_path)) {
            // Bundle not built yet. is_active() should already have returned
            // false so we never reach here in practice, but be defensive.
            return array();
        }

        $script_asset = file_exists($script_asset_path)
            ? require $script_asset_path
            : array('dependencies' => array(), 'version' => SP_VERSION);

        wp_register_script(
            $handle,
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        // Allow translation strings inside the React component.
        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations($handle, 'stablecoin-pay');
        }

        return array($handle);
    }

    /**
     * Data passed from PHP to the React component via
     * `getSetting('sp_data')` on the JS side. Add anything the block
     * checkout UI needs (branding, supported features, dashboard URL, etc.).
     */
    public function get_payment_method_data() {
    
        $config_name = class_exists('SP_Whitelabel_Branding')
            ? SP_Whitelabel_Branding::get_whitelabel_plugin_name_from_config()
            : null;

        if (!empty($this->settings['title'])) {
            $brand_name = $this->settings['title'];
        } elseif ($config_name) {
            /* translators: %s: whitelabel payment provider name */
            $brand_name = sprintf(__('Pay with %s', 'stablecoin-pay'), $config_name);
        } else {
            $brand_name = __('Pay with Stablecoin', 'stablecoin-pay');
        }

        $description = !empty($this->settings['description'])
            ? $this->settings['description']
            : __('Pay securely with stablecoin.', 'stablecoin-pay');

        // Whitelabel branding (logo, etc.) — same source as classic checkout.
        $branding = get_option('sp_whitelabel_branding', array());

        // Logo/company resolution mirrors the classic gateway: on a partner build the config file
        // wins outright. The sp_whitelabel_branding option is a cache of whatever the API last
        // returned, so if it took precedence a site that previously ran another partner's build
        // would keep showing that partner's logo and name. No partner asset is named here — the
        // only per-company values live in sp-whitelabel-config.php.
        $config_logo = class_exists('SP_Whitelabel_Branding')
            ? SP_Whitelabel_Branding::get_whitelabel_logo_url_from_config()
            : null;
        $logo_url = $config_logo ?: (!empty($branding['logo_url']) ? $branding['logo_url'] : '');
        $company_name = $config_name ?: (!empty($branding['company_name']) ? $branding['company_name'] : 'Stablecoin Pay');

        return array(
            'title'           => $brand_name,
            'description'     => $description,
            'logoUrl'         => $logo_url,
            'companyName'     => $company_name,
            'ajaxUrl'         => admin_url('admin-ajax.php'),
            'processAction'   => 'sp_process_payment',
            'nonce'           => wp_create_nonce('woocommerce-process_checkout'),
            'supports'        => $this->get_supported_features(),
        );
    }

    /**
     * Which block-checkout features the gateway claims to support.
     *
     * Common values include 'products' (one-off), 'subscriptions' (recurring),
     * 'refunds'. Block checkout uses this to decide whether to even render
     * the method (e.g. it's hidden for subscription carts if 'subscriptions'
     * isn't here).
     */
    public function get_supported_features() {
        // Mirror the classic gateway's declared features.
        $gateway = class_exists('WC_Gateway_SP') ? new WC_Gateway_SP() : null;
        return $gateway && !empty($gateway->supports)
            ? array_values($gateway->supports)
            : array('products');
    }
}
