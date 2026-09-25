<?php
/**
 * Storefront branding for headless frontends.
 *
 * A headless storefront talks to WooCommerce through the Store API, which returns
 * payment methods as bare slugs:
 *
 *     "payment_methods": ["sp"]
 *
 * The display name, description and logo are delivered to WordPress-rendered
 * pages through `wcSettings`, which a headless frontend never loads - so it has
 * nothing to render but the slug, and shows "sp" to the shopper.
 *
 * The same branding is published two ways here so a decoupled frontend can label
 * the method properly:
 *
 *   1. GET /wp-json/woowh/v1/payment-method  - standalone, no nonce required.
 *   2. Store API `extensions.stablecoin_pay` on the cart and checkout responses,
 *      so a frontend that already fetches the cart needs no extra request.
 *
 * Everything returned here is already public-facing (it is shown on the checkout
 * page); no credential or setting is exposed.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('sp_storefront_payment_method_data')) {
    /**
     * Public branding for this gateway, for a headless storefront to render.
     *
     * @return array
     */
    function sp_storefront_payment_method_data() {
        $settings = get_option('woocommerce_sp_settings', array());

        $company = class_exists('SP_Whitelabel_Branding')
            ? SP_Whitelabel_Branding::get_whitelabel_plugin_name_from_config()
            : null;
        $logo = class_exists('SP_Whitelabel_Branding')
            ? SP_Whitelabel_Branding::get_whitelabel_logo_url_from_config()
            : null;

        // Same precedence the block checkout uses, so both surfaces agree.
        if (!empty($settings['title'])) {
            $title = $settings['title'];
        } elseif ($company) {
            /* translators: %s: payment provider name */
            $title = sprintf(__('Pay with %s', 'stablecoin-pay'), $company);
        } else {
            $title = __('Pay with Stablecoin', 'stablecoin-pay');
        }

        $description = !empty($settings['description'])
            ? $settings['description']
            : __('Pay securely with stablecoin.', 'stablecoin-pay');

        return array(
            // Matches the WooCommerce gateway id, so the frontend can map this
            // onto the slug the Store API gives it.
            'id'          => 'sp',
            'title'       => $title,
            'description' => $description,
            'logo_url'    => $logo ? $logo : '',
            'company'     => $company ? $company : 'Stablecoin Pay',
            'enabled'     => (isset($settings['enabled']) && $settings['enabled'] === 'yes'),
        );
    }
}

if (!function_exists('sp_storefront_payment_method_schema')) {
    /**
     * Schema for the Store API extension payload. Store API rejects extension
     * data that has no matching schema, so every key above is declared here.
     *
     * @return array
     */
    function sp_storefront_payment_method_schema() {
        $string_field = function ($description) {
            return array(
                'description' => $description,
                'type'        => 'string',
                'context'     => array('view', 'edit'),
                'readonly'    => true,
            );
        };

        return array(
            'id'          => $string_field(__('Payment gateway id.', 'stablecoin-pay')),
            'title'       => $string_field(__('Payment method title to display.', 'stablecoin-pay')),
            'description' => $string_field(__('Payment method description.', 'stablecoin-pay')),
            'logo_url'    => $string_field(__('Payment method logo URL.', 'stablecoin-pay')),
            'company'     => $string_field(__('Payment provider name.', 'stablecoin-pay')),
            'enabled'     => array(
                'description' => __('Whether the gateway is enabled.', 'stablecoin-pay'),
                'type'        => 'boolean',
                'context'     => array('view', 'edit'),
                'readonly'    => true,
            ),
        );
    }
}

if (!function_exists('sp_register_storefront_routes')) {
    /**
     * GET /wp-json/woowh/v1/payment-method
     */
    function sp_register_storefront_routes() {
        $namespaces = class_exists('SP_Webhook_Provisioner')
            ? SP_Webhook_Provisioner::all_namespaces()
            : array('woowh/v1');

        foreach ($namespaces as $namespace) {
            register_rest_route($namespace, '/payment-method', array(
                'methods'             => 'GET',
                'callback'            => 'sp_storefront_payment_method',
                'permission_callback' => '__return_true',
            ));
        }
    }
    add_action('rest_api_init', 'sp_register_storefront_routes');
}

if (!function_exists('sp_storefront_payment_method')) {
    /**
     * @return WP_REST_Response
     */
    function sp_storefront_payment_method() {
        return new WP_REST_Response(sp_storefront_payment_method_data(), 200);
    }
}

if (!function_exists('sp_register_storefront_store_api_data')) {
    /**
     * Attach the branding to the Store API cart and checkout responses under
     * `extensions.stablecoin_pay`, so a headless frontend that already reads the
     * cart can label the method without a second request.
     */
    function sp_register_storefront_store_api_data() {
        if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
            // Store API too old (or WooCommerce Blocks absent). The standalone
            // REST route above still covers this case.
            return;
        }

        $endpoints = array();

        if (class_exists('\Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema')) {
            $endpoints[] = \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER;
        }
        if (class_exists('\Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema')) {
            $endpoints[] = \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER;
        }

        foreach ($endpoints as $endpoint) {
            woocommerce_store_api_register_endpoint_data(array(
                'endpoint'        => $endpoint,
                'namespace'       => 'stablecoin_pay',
                'data_callback'   => 'sp_storefront_payment_method_data',
                'schema_callback' => 'sp_storefront_payment_method_schema',
                'schema_type'     => ARRAY_A,
            ));
        }
    }
    add_action('woocommerce_blocks_loaded', 'sp_register_storefront_store_api_data');
}
