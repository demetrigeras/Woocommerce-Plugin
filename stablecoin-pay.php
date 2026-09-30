<?php
/**
 * Plugin Name: Stablecoin Pay
 * Description: Accept cryptocurrency payments with Stablecoin Pay. Simple crypto payments for WooCommerce.
 * Version: 1.0.0
 * Author: Stablecoin Pay
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: stablecoin-pay
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SP_PLUGIN_FILE', __FILE__);
define('SP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SP_VERSION', '1.0.0');


function sp_get_whitelabel_plugin_name() {
    $path = SP_PLUGIN_DIR . 'sp-whitelabel-config.php';
    if (!is_readable($path)) {
        return null;
    }
    $config = include $path;
    if (!is_array($config) || empty($config['environment_id'])) {
        return null;
    }
    return isset($config['plugin_name']) && $config['plugin_name'] !== '' ? $config['plugin_name'] : null;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', 'sp_woocommerce_missing_notice');
    return;
}

/**
 * WooCommerce missing notice
 */
function sp_woocommerce_missing_notice() {
    $label = sp_get_whitelabel_plugin_name() ?: 'Stablecoin Pay';
    echo '<div class="error"><p><strong>' . esc_html($label) . '</strong> requires WooCommerce to be installed and active.</p></div>';
}

/**
 * Initialize the plugin
 */
function sp_commerce_init() {
    // Carry data over from builds that used the legacy "coinsub" key prefix.
    // This MUST run before the webhook-secret check below: otherwise a site
    // upgrading from an old build would mint a brand-new secret, the migration
    // would then decline to overwrite it, and the merchant's existing webhook
    // registration would start failing signature verification.
    require_once SP_PLUGIN_DIR . 'includes/class-sp-legacy-migration.php';
    SP_Legacy_Migration::maybe_migrate();

    // Ensure a per-site webhook secret exists
    if (!get_option('sp_webhook_secret')) {
        $secret = wp_generate_password(32, false, false);
        add_option('sp_webhook_secret', $secret, '', false);
    }

    // Include required files
    require_once SP_PLUGIN_DIR . 'includes/sp-checkout-url.php';
    require_once SP_PLUGIN_DIR . 'includes/class-sp-api-client.php';
    require_once SP_PLUGIN_DIR . 'includes/class-sp-whitelabel-branding.php';
    require_once SP_PLUGIN_DIR . 'includes/class-sp-payment-gateway.php';
    require_once SP_PLUGIN_DIR . 'includes/class-sp-webhook-handler.php';
    require_once SP_PLUGIN_DIR . 'includes/class-sp-webhook-provisioner.php';
    require_once SP_PLUGIN_DIR . 'includes/class-sp-webhook-admin.php';
    require_once SP_PLUGIN_DIR . 'includes/class-sp-cart-sync.php';
    require_once SP_PLUGIN_DIR . 'includes/class-sp-subscriptions.php';
    require_once SP_PLUGIN_DIR . 'includes/class-sp-order-manager.php';
    require_once SP_PLUGIN_DIR . 'includes/class-sp-admin-logs.php';
    require_once SP_PLUGIN_DIR . 'includes/class-sp-admin-subscriptions.php';
    require_once SP_PLUGIN_DIR . 'includes/class-sp-admin-payments.php';
    require_once SP_PLUGIN_DIR . 'includes/class-sp-review-page.php';
    require_once SP_PLUGIN_DIR . 'includes/class-sp-checkout-page-checker.php';
    require_once SP_PLUGIN_DIR . 'includes/class-sp-blocks-payment-method.php';
    
    // Register custom order status
    
    // Initialize components
    new SP_Webhook_Handler();
    new SP_Order_Manager();

    // Webhook provisioning notices / retry action (admin only).
    if (is_admin()) {
        new SP_Webhook_Admin();
    }
    
    // Email hooks are handled by SP_Order_Manager class
    
    // Initialize cart sync (tracks cart changes in real-time)
    if (!is_admin()) {
        new WC_SP_Cart_Sync();
    }

    // Force the Phone field to be required at checkout. The plugin sends
    // billing_phone to the payment provider and uses it for support /
    // refunds, so the field should never advertise itself as "(optional)"
    // when the gateway is active.
    //
    // Block checkout reads requirement from the
    // `woocommerce_checkout_phone_field` option ('required' | 'optional' |
    // 'hidden'). Override it at read time so we don't permanently rewrite
    // the merchant's saved setting.
    add_filter('pre_option_woocommerce_checkout_phone_field', function ($value) {
        return 'required';
    });

    // Classic checkout reads requirement from the per-field array.
    add_filter('woocommerce_billing_fields', function ($fields) {
        if (isset($fields['billing_phone'])) {
            $fields['billing_phone']['required'] = true;
        }
        return $fields;
    });
    
    // Plugin list: show whitelabel name, description and author from config
    add_filter('all_plugins', function ($plugins) {
        $name = sp_get_whitelabel_plugin_name();
        if ($name && isset($plugins[plugin_basename(SP_PLUGIN_FILE)])) {
            $plugins[plugin_basename(SP_PLUGIN_FILE)]['Name']        = $name;
            $plugins[plugin_basename(SP_PLUGIN_FILE)]['Description'] = sprintf(__('Accept cryptocurrency payments with %s. Simple crypto payments for WooCommerce.', 'stablecoin-pay'), $name);
            $plugins[plugin_basename(SP_PLUGIN_FILE)]['Author']      = $name;
            $plugins[plugin_basename(SP_PLUGIN_FILE)]['AuthorName']  = $name;
        }
        return $plugins;
    });

    // Initialize admin tools (only in admin)
    if (is_admin()) {
        new SP_Admin_Logs();
    }

    // Initialize review/brand explainer page
    new SP_Review_Page();
    
    // Register checkout page shortcode
    add_shortcode('stablecoin_pay_checkout', 'sp_checkout_page_shortcode');
    
    // Add preconnect/prefetch to head for faster iframe loading (priority 1 = very early)
    add_action('wp_head', 'sp_checkout_page_preconnect', 1);
    
    // Disable unnecessary WordPress/WooCommerce assets on checkout iframe page for faster loading
    add_action('wp_enqueue_scripts', 'sp_disable_unnecessary_assets_on_checkout_page', 999);
}

/**
 * Declare HPOS compatibility
 */
function sp_commerce_declare_hpos_compatibility() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
}

/**
 * Add Stablecoin Pay payment gateway to WooCommerce
 */
function sp_add_payment_gateway($gateways) {
    $gateways[] = 'WC_Gateway_SP';
    return $gateways;
}

/**
 * Initialize the payment gateway
 */
function sp_init_payment_gateway() {
    if (class_exists('WC_Gateway_SP')) {
        new WC_Gateway_SP();
    }
}

/**
 * Add Stablecoin Pay gateway to WooCommerce gateways
 */
function sp_add_gateway_class($methods) {
    error_log('🔧 PP - Registering payment gateway class');
    error_log('🔧 PP - WC_Gateway_SP class exists: ' . (class_exists('WC_Gateway_SP') ? 'YES' : 'NO'));
    error_log('🔧 PP - Existing gateways: ' . implode(', ', $methods));
    $methods[] = 'WC_Gateway_SP';
    error_log('🔧 PP - Gateway added to methods array. Total gateways: ' . count($methods));
    error_log('🔧 PP - Updated gateways: ' . implode(', ', $methods));
    return $methods;
}

/**
 * Plugin activation
 */
function sp_register_review_rewrite_rule() {
    add_rewrite_rule(
        '^stablecoin-pay-review/?$',
        'index.php?sp_review=1',
        'top'
    );
}

function sp_commerce_activate() {
    // Add rewrite rules for the webhook endpoint, canonical and legacy namespaces
    // alike, so a merchant whose dashboard still holds an older callback URL keeps
    // resolving. (WordPress serves /wp-json/ directly; these rules only matter on
    // installs where that path is not already routed.)
    require_once SP_PLUGIN_DIR . 'includes/class-sp-webhook-provisioner.php';
    foreach (SP_Webhook_Provisioner::all_namespaces() as $sp_namespace) {
        add_rewrite_rule(
            '^wp-json/' . preg_quote($sp_namespace, '/') . '/webhook/?$',
            'index.php?sp_webhook=1',
            'top'
        );
    }

    // Add rewrite for the review/branding explainer page
    sp_register_review_rewrite_rule();
    
    // Create dedicated checkout page
    sp_create_checkout_page();
    
    // Make sure the WooCommerce Checkout page has SOME checkout form in it.
    // Both the modern block and the legacy shortcode are supported by the
    // plugin, so we only auto-insert content when neither is present.
    sp_ensure_checkout_form_present();

    // Flush rewrite rules
    flush_rewrite_rules();
}

/**
 * Ensure the WooCommerce Checkout page has a checkout form on it.
 *
 * Both supported entry points work out of the box:
 *   - The `[woocommerce_checkout]` shortcode (classic / legacy themes)
 *   - The `wp:woocommerce/checkout` block (modern WC default)
 *
 * If the page already has either one, we leave it alone — merchants who
 * deliberately chose classic should stay on classic. We only auto-insert
 * content if the Checkout page is empty (or has no checkout form at all),
 * in which case we drop in the modern Checkout block, matching what a
 * fresh WooCommerce install ships with.
 *
 * Safe: never overwrites existing checkout content.
 */
function sp_ensure_checkout_form_present() {
    $checkout_page_id = wc_get_page_id('checkout');

    if (!$checkout_page_id || $checkout_page_id === 0) {
        error_log('⚠️ Stablecoin Pay: WooCommerce checkout page not configured - skipping checkout-form check');
        return false;
    }

    $checkout_page = get_post($checkout_page_id);
    if (!$checkout_page) {
        error_log('⚠️ Stablecoin Pay: Could not retrieve checkout page');
        return false;
    }

    $page_content = (string) $checkout_page->post_content;

    // A checkout form is already present if either the shortcode or the
    // block (in any of its serialization forms) is on the page.
    $has_checkout = (
        strpos($page_content, '[woocommerce_checkout]') !== false ||
        strpos($page_content, '<!-- wp:woocommerce/checkout') !== false ||
        strpos($page_content, 'wp-block-woocommerce-checkout') !== false
    );

    if ($has_checkout) {
        error_log('✅ Stablecoin Pay: Checkout page already has a checkout form - no changes needed');
        return true;
    }

    // No checkout form found. Insert the modern Checkout block — it's what
    // a fresh WC install ships with and works seamlessly with our block
    // checkout integration.
    $block_markup = '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout is-loading"></div><!-- /wp:woocommerce/checkout -->';

    $trimmed_content = trim($page_content);
    if (empty($trimmed_content)) {
        $new_content = $block_markup;
        error_log('🔄 Stablecoin Pay: Checkout page is empty - inserting WooCommerce Checkout block');
    } else {
        $new_content = $block_markup . "\n\n" . $page_content;
        error_log('🔄 Stablecoin Pay: Checkout page has content but no checkout form - prepending WooCommerce Checkout block');
    }

    $updated = wp_update_post(array(
        'ID' => $checkout_page_id,
        'post_content' => $new_content,
    ));

    if ($updated && !is_wp_error($updated)) {
        error_log('✅ Stablecoin Pay: Successfully added the WooCommerce Checkout block to the checkout page');
        return true;
    }

    $error_msg = is_wp_error($updated) ? $updated->get_error_message() : 'Unknown error';
    error_log('❌ Stablecoin Pay: Failed to update checkout page - ' . $error_msg);
    return false;
}

/**
 * Create dedicated checkout page for Stablecoin Pay
 * This page will display the payment iframe full-page
 */
function sp_create_checkout_page() {
    // Check if page already exists
    $page_slug = 'stablecoin-pay-checkout';
    $existing_page = get_page_by_path($page_slug);
    
    if ($existing_page) {
        // Page exists, make sure it's published
        if ($existing_page->post_status !== 'publish') {
            wp_update_post(array(
                'ID' => $existing_page->ID,
                'post_status' => 'publish'
            ));
        }
        update_option('sp_checkout_page_id', $existing_page->ID);
        return $existing_page->ID;
    }
    
    // Create the page
    $page_data = array(
        'post_title'    => 'Complete Your Payment',
        'post_name'     => $page_slug,
        'post_content'  => '[stablecoin_pay_checkout]',
        'post_status'   => 'publish',
        'post_type'     => 'page',
        'post_author'   => 1,
        'comment_status' => 'closed',
        'ping_status'    => 'closed'
    );
    
    $page_id = wp_insert_post($page_data);
    
    if ($page_id && !is_wp_error($page_id)) {
        // Store page ID in options for easy reference
        update_option('sp_checkout_page_id', $page_id);
        error_log('✅ Stablecoin Pay: Created dedicated checkout page (ID: ' . $page_id . ')');
        return $page_id;
    }
    
    return false;
}

/**
 * Plugin deactivation
 */
function sp_commerce_deactivate() {
    // Disable the registered webhook so we stop receiving deliveries this site can
    // no longer act on. The API has no DELETE by design - the record survives and
    // is re-enabled by sync() if the plugin is reactivated, so the merchant never
    // ends up with duplicate webhooks.
    $provisioner = SP_PLUGIN_DIR . 'includes/class-sp-webhook-provisioner.php';
    if (is_readable($provisioner)) {
        require_once SP_PLUGIN_DIR . 'includes/class-sp-whitelabel-branding.php';
        require_once $provisioner;
        SP_Webhook_Provisioner::disable();
    }

    // Optionally delete checkout page on deactivation
    // Uncomment if you want to clean up on deactivation
    // $page_id = get_option('sp_checkout_page_id');
    // if ($page_id) {
    //     wp_delete_post($page_id, true);
    //     delete_option('sp_checkout_page_id');
    // }
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

/**
 * Disable unnecessary WordPress/WooCommerce assets on checkout iframe page
 * This speeds up page load so iframe can start loading faster
 */
function sp_disable_unnecessary_assets_on_checkout_page() {
    $page_slug = 'stablecoin-pay-checkout';
    $checkout_page = get_page_by_path($page_slug);
    
    if (!$checkout_page || !is_page($checkout_page->ID)) {
        return;
    }
    
    // Defer non-critical scripts - let them load after iframe starts
    add_filter('script_loader_tag', function($tag, $handle) {
        // Don't defer jQuery, WooCommerce, or our own scripts
        $critical_scripts = array('jquery', 'jquery-core', 'jquery-migrate', 'wc-checkout', 'stablecoin-pay');
        
        // Defer all other scripts
        if (!in_array($handle, $critical_scripts) && strpos($tag, 'src=') !== false) {
            // Skip if already has defer or async
            if (strpos($tag, 'defer') === false && strpos($tag, 'async') === false) {
                $tag = str_replace(' src=', ' defer src=', $tag);
            }
        }
        
        return $tag;
    }, 10, 2);
    
    // Remove WooCommerce scripts we don't need on this page
    wp_dequeue_style('woocommerce-general');
    wp_dequeue_style('woocommerce-layout');
    wp_dequeue_style('woocommerce-smallscreen');
    
    // Keep only essential scripts
}

/**
 * Add preconnect/prefetch to head for checkout page (loads early in <head>)
 * This significantly speeds up iframe loading by starting DNS resolution early
 */
function sp_checkout_page_preconnect() {
    // Only on checkout page
    $page_slug = 'stablecoin-pay-checkout';
    $checkout_page = get_page_by_path($page_slug);
    
    if (!$checkout_page || !is_page($checkout_page->ID)) {
        return;
    }
    
    // Try to get checkout URL early for preconnect
    $checkout_url = '';
    
    // Method 1: Try to get from order_id
    if (isset($_GET['order_id']) && !empty($_GET['order_id'])) {
        $order_id = intval($_GET['order_id']);
        
        // Try session first (if WooCommerce is initialized)
        if (function_exists('WC') && WC()->session) {
            $checkout_url = sp_normalize_checkout_url(WC()->session->get('sp_checkout_url_' . $order_id));
            if (!empty($checkout_url)) {
                error_log('🔗 PP Checkout Page: Checkout URL from session for order #' . $order_id . ': ' . $checkout_url);
            }
        }
        
        // Fallback to order meta
        if (empty($checkout_url) && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order) {
                $checkout_url = $order->get_meta('_sp_checkout_url');
            }
        }
    }
    
    // Method 2: Fallback to query parameter
    if (empty($checkout_url) && isset($_GET['checkout_url'])) {
        $checkout_url = esc_url_raw(urldecode($_GET['checkout_url']));
    }
    
    // If we have a checkout URL, add aggressive resource hints early in head
    if (!empty($checkout_url)) {
        $parsed_url = parse_url($checkout_url);
        if (isset($parsed_url['scheme']) && isset($parsed_url['host'])) {
            $checkout_domain = $parsed_url['scheme'] . '://' . $parsed_url['host'];
            
            // DNS prefetch (starts DNS lookup immediately - fastest hint)
            echo '<link rel="dns-prefetch" href="' . esc_url($checkout_domain) . '">' . "\n";
            
            // Preconnect (DNS + TCP + TLS handshake - most aggressive resource hint)
            // This establishes connection before iframe src is even parsed
            echo '<link rel="preconnect" href="' . esc_url($checkout_domain) . '" crossorigin>' . "\n";
            
            // Note: Can't prefetch cross-origin documents (CORS restriction)
            // But preconnect should help significantly with DNS/TCP/TLS
        }
    }
    
    // Hide admin bar with CSS (faster than JS)
    echo '<style>#wpadminbar { display: none !important; }</style>' . "\n";
}

/**
 * Shortcode handler for checkout page
 * Displays the payment iframe full-page
 */
function sp_checkout_page_shortcode($atts) {
    error_log('🎬 PP Checkout Page: Shortcode called');
    
    // Get checkout URL from query parameter OR from session using order_id
    $checkout_url = '';
    
    // Method 1: Try to get from order_id (shorter URL)
    if (isset($_GET['order_id']) && !empty($_GET['order_id'])) {
        $order_id = intval($_GET['order_id']);
        error_log('🔍 PP Checkout Page: Looking up checkout URL for order_id: ' . $order_id);
        
        // CRITICAL: Check if checkout URL exists in session first (indicates active session)
        // If not in session, the user likely left the page and the checkout URL was already used
        $checkout_url = WC()->session->get('sp_checkout_url_' . $order_id);
        
        if (!empty($checkout_url)) {
            error_log('🔗 PP Checkout Page: Checkout URL found in session for order #' . $order_id . ': ' . $checkout_url);
        }
        
        if (empty($checkout_url)) {
            // No checkout URL in session - user likely left the page
            // Checkout URLs are one-time use, so we can't reuse them
            error_log('⚠️ PP Checkout Page: No checkout URL in session for order_id: ' . $order_id . ' - user likely left page, checkout URL is one-time use');
            error_log('⚠️ PP Checkout Page: Redirecting to checkout page to create fresh order');
            
            // Redirect to checkout page - will create a fresh order
            return '<div style="padding: 40px; text-align: center; max-width: 600px; margin: 50px auto;">
                <h2 style="margin-bottom: 20px;">Starting Fresh Checkout</h2>
                <p style="margin-bottom: 30px;">This checkout session has expired. Please start a new checkout.</p>
                <a href="' . esc_url(wc_get_checkout_url()) . '" class="button" style="padding: 12px 24px; text-decoration: none; display: inline-block; background: #2271b1; color: white; border-radius: 4px;">Start New Checkout</a>
                <script>
                    setTimeout(function() {
                        window.location.href = "' . esc_js(wc_get_checkout_url()) . '";
                    }, 2000);
                </script>
            </div>';
        }
        
        error_log('✅ PP Checkout Page: Found checkout URL in session: ' . $checkout_url);
    }
    
    // Method 2: Fallback to query parameter (for backward compatibility)
    if (empty($checkout_url) && isset($_GET['checkout_url'])) {
        // URL decode the checkout URL if it's encoded
        $raw_url = $_GET['checkout_url'];
        $checkout_url = sp_normalize_checkout_url(esc_url_raw(urldecode($raw_url)));
        error_log('✅ PP Checkout Page: Using checkout URL from query parameter: ' . $checkout_url);
    }
    
    if (empty($checkout_url)) {
        error_log('❌ PP Checkout Page: No checkout URL found - order_id: ' . (isset($_GET['order_id']) ? $_GET['order_id'] : 'not set') . ', checkout_url param: ' . (isset($_GET['checkout_url']) ? 'set' : 'not set'));
        return '<div style="padding: 40px; text-align: center; max-width: 600px; margin: 50px auto;">
            <h2 style="margin-bottom: 20px;">Payment Checkout</h2>
            <p style="margin-bottom: 30px;">No checkout URL provided. Please return to the checkout page and try again.</p>
            <a href="' . esc_url(wc_get_checkout_url()) . '" class="button" style="padding: 12px 24px; text-decoration: none; display: inline-block;">Return to Checkout</a>
        </div>';
    }
    
    error_log('🎯 PP Checkout Page: Final checkout URL to load: ' . $checkout_url);
    
    // REMOVED: Domain blocking check - these domains work fine in iframes
    // The JavaScript fallback will handle redirect if iframe is actually blocked by X-Frame-Options
    
    // Page title comes from the whitelabel config.
    $company_name = class_exists('SP_Whitelabel_Branding')
        ? (SP_Whitelabel_Branding::get_whitelabel_plugin_name_from_config() ?: 'Stablecoin Pay')
        : 'Stablecoin Pay';
    
    error_log('📝 PP Checkout Page: Starting output buffer, company: ' . $company_name);
    
    // Output full-page iframe with back button and loading indicator
    ob_start();
    ?>
    <!-- Preconnect already added to <head> for faster loading -->
    
    <!-- CRITICAL PERFORMANCE: Output iframe FIRST in body to start loading ASAP -->
    <!-- Browser starts loading iframe as soon as it encounters <iframe src> -->
    <iframe 
        id="stablecoin-pay-checkout-iframe" 
        src="<?php echo esc_url($checkout_url); ?>" 
        style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; border: none; opacity: 0; transition: opacity 0.3s ease; z-index: 9998;"
        allow="clipboard-read *; publickey-credentials-create *; publickey-credentials-get *; autoplay *; camera *; microphone *; payment *; fullscreen *; clipboard-write *"
        title="Complete Your Payment - <?php echo esc_attr($company_name); ?>"
        loading="eager"
        referrerpolicy="no-referrer-when-downgrade"
        importance="high"
        allowfullscreen
    ></iframe>
    
    <div id="stablecoin-pay-checkout-container" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 9999; background: transparent; pointer-events: none;">
        <!-- Back button in top left corner (pointer-events: auto to allow clicking) -->
        <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" 
           id="stablecoin-pay-back-button" 
           style="position: absolute; top: 20px; left: 20px; z-index: 10000; display: flex; align-items: center; justify-content: center; width: 48px; height: 48px; background: rgba(255, 255, 255, 0.95); border-radius: 50%; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15); text-decoration: none; transition: all 0.2s ease; cursor: pointer; pointer-events: auto;"
           onmouseover="this.style.background='rgba(255, 255, 255, 1)'; this.style.transform='scale(1.05)';"
           onmouseout="this.style.background='rgba(255, 255, 255, 0.95)'; this.style.transform='scale(1)';"
           title="Back to Checkout">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="color: #000;">
                <path d="M15 18L9 12L15 6" stroke="#000" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </a>
        
        <!-- Loading indicator (shown while iframe loads) - pointer-events: auto to be visible -->
        <div id="stablecoin-pay-loading" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 10001; text-align: center; color: #666; pointer-events: auto;">
            <div style="width: 50px; height: 50px; border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 20px;"></div>
            <p style="margin: 0; font-size: 16px;">Loading payment checkout...</p>
            <p style="margin: 10px 0 0; font-size: 12px; color: #999;">This may take a few moments</p>
        </div>
    </div>
    
    <style>
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    /* Hide admin bar immediately with CSS (faster than JS) */
    #wpadminbar { display: none !important; }
    </style>
    
    <!-- Inline script to start iframe loading immediately (before jQuery/DOM ready) -->
    <!-- CRITICAL: This script runs IMMEDIATELY, before WordPress/WooCommerce scripts load -->
    <script>
    (function() {
        // Get iframe element immediately - it should already be in DOM
        var iframe = document.getElementById('stablecoin-pay-checkout-iframe');
        var loadingDiv = document.getElementById('stablecoin-pay-loading');
        
        if (!iframe) {
            // If iframe not found, retry after a micro-delay (DOM might still be parsing)
            setTimeout(function() {
                iframe = document.getElementById('stablecoin-pay-checkout-iframe');
                if (iframe) {
                    console.log('🔗 Iframe found on retry, URL:', iframe.src);
                }
            }, 10);
            return;
        }
        
        // Security: Don't log iframe URL in console (sensitive one-time use URL)
        // Note: We cannot check iframe.contentDocument for cross-origin iframes due to Same-Origin Policy
        // This is normal - cross-origin iframes can still load and work fine, we just can't access their content
        // We'll rely on the iframe's onload/onerror events to detect actual blocking
        
        // Note: Browser should start loading iframe src automatically when HTML is parsed
        // Preconnect in <head> should have already established connection
        
        var loadStartTime = Date.now();
        var loadTimeout = null;
        var TIMEOUT_DURATION = 300000; // 5 minutes timeout
        var fallbackShown = false;
        
        // FALLBACK: Show iframe after 3 seconds even if onload hasn't fired
        // This prevents blank screen if onload event fails or is delayed
        var fallbackTimeout = setTimeout(function() {
            if (!fallbackShown && iframe.style.opacity === '0') {
                console.warn('⚠️ Fallback: Showing iframe after 3 seconds (onload may not have fired)');
                iframe.style.opacity = '1';
                iframe.style.zIndex = '9999';
                if (loadingDiv) {
                    loadingDiv.style.display = 'none';
                }
                fallbackShown = true;
            }
        }, 3000);
        
        // Set timeout to detect if iframe takes too long to load
        loadTimeout = setTimeout(function() {
            var elapsed = ((Date.now() - loadStartTime) / 1000).toFixed(2);
            console.warn('⚠️ Iframe loading timeout after ' + elapsed + ' seconds');
            if (loadingDiv) {
                loadingDiv.innerHTML = '<div style="color: #d32f2f;"><p style="margin: 0 0 10px; font-size: 16px;">⚠️ Payment checkout is taking longer than expected</p><p style="margin: 0; font-size: 14px;">This may indicate a backend issue. Please try again or contact support.</p><a href="<?php echo esc_js(wc_get_checkout_url()); ?>" style="display: inline-block; margin-top: 15px; padding: 10px 20px; background: #2271b1; color: white; text-decoration: none; border-radius: 4px;">Return to Checkout</a></div>';
            }
        }, TIMEOUT_DURATION);
        
        // Start loading immediately - don't wait for jQuery
        iframe.onload = function() {
            if (loadTimeout) {
                clearTimeout(loadTimeout);
            }
            if (fallbackTimeout) {
                clearTimeout(fallbackTimeout);
            }
            
            var loadTime = ((Date.now() - loadStartTime) / 1000).toFixed(2);
            // Security: Don't log iframe load details (URL is sensitive)
            
            // Make iframe visible and bring to front
            iframe.style.opacity = '1';
            iframe.style.zIndex = '9999'; // Bring iframe to front once loaded
            if (loadingDiv) {
                loadingDiv.style.display = 'none';
            }
            
            // Hide container overlay once iframe is loaded (allows iframe interaction)
            var container = document.getElementById('stablecoin-pay-checkout-container');
            if (container) {
                container.style.pointerEvents = 'none';
                // Don't hide completely - keep back button accessible via z-index
            }
            
            fallbackShown = true;
            
            // Log warning if load time is excessive
            if (loadTime > 60) {
                console.warn('⚠️ Iframe took ' + loadTime + ' seconds to load - this is unusually slow and indicates backend/server performance issues at: ' + new URL(iframe.src).host);
            }
        };
        
        // Handle iframe load errors
        // Note: onerror may not fire for cross-origin iframes due to security restrictions
        // We rely on the fallback timeout and onload event instead
        iframe.onerror = function() {
            if (loadTimeout) {
                clearTimeout(loadTimeout);
            }
            if (fallbackTimeout) {
                clearTimeout(fallbackTimeout);
            }
            
            var loadTime = ((Date.now() - loadStartTime) / 1000).toFixed(2);
            var checkoutDomain = new URL(iframe.src).host;
            console.error('❌ Iframe onerror fired after ' + loadTime + ' seconds');
            console.error('❌ Iframe src:', iframe.src);
            console.error('❌ Domain:', checkoutDomain);
            console.warn('⚠️ Note: onerror may not fire reliably for cross-origin iframes. Relying on fallback timeout.');
            
            // Don't immediately redirect - let the fallback timeout handle it
            // The iframe might still be loading
        };
        
        // Start postMessage listener immediately (before jQuery ready)
        window.addEventListener('message', function(event) {
            // Security: Verify origin if possible (but don't block messages from checkout domain)
            var checkoutDomain = new URL(iframe.src).origin;
            
            // Check if this is a redirect message
            if (event.data && typeof event.data === 'object') {
                if (event.data.type === 'redirect' && event.data.url) {
                    // Security: Don't log redirect URL (sensitive)
                    window.location.href = event.data.url;
                    return;
                }
                
                // Check for error messages from iframe
                if (event.data.type === 'error' || event.data.error) {
                    // Log error type but not full data (may contain sensitive URLs)
                    console.error('❌ Error received from checkout iframe');
                    if (loadingDiv) {
                        loadingDiv.style.display = 'block';
                        loadingDiv.innerHTML = '<div style="color: #d32f2f;"><p style="margin: 0 0 10px; font-size: 16px;">⚠️ Error in payment checkout</p><p style="margin: 0; font-size: 14px;">' + (event.data.message || 'Please try again or contact support') + '</p><a href="<?php echo esc_js(wc_get_checkout_url()); ?>" style="display: inline-block; margin-top: 15px; padding: 10px 20px; background: #2271b1; color: white; text-decoration: none; border-radius: 4px;">Return to Checkout</a></div>';
                    }
                    return;
                }
            }
            
            // Check for order-received URL in message
            if (event.data && typeof event.data === 'string' && event.data.includes('order-received')) {
                // Security: Don't log order-received URL (sensitive)
                window.location.href = event.data;
                return;
            }
        });
        
        // Listen for console errors from iframe (if accessible)
        // Note: This won't catch errors in cross-origin iframes, but we can try
        var originalConsoleError = console.error;
        console.error = function() {
            var args = Array.from(arguments);
            var errorMessage = args.join(' ');
            
            // Check if error is related to the checkout (500 errors, etc.)
            if (errorMessage.includes('500') || errorMessage.includes('purchaser') || errorMessage.includes('checkout') || errorMessage.includes('Failed to load resource')) {
                console.warn('⚠️ Potential checkout error detected:', errorMessage);
            }
            
            // Call original console.error
            originalConsoleError.apply(console, args);
        };
    })();
    </script>
    
    <!-- Additional jQuery-dependent functionality (loads after jQuery) -->
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        
        // Handle back button click - clear order/checkout URL from session before going back
        $('#stablecoin-pay-back-button').on('click', function(e) {
            e.preventDefault();
            
            // Get order ID from URL
            var urlParams = new URLSearchParams(window.location.search);
            var orderId = urlParams.get('order_id');
            
            console.log('🔄 Going back to checkout - clearing order/checkout URL from session (order_id: ' + orderId + ')');
            
            // Clear session data before navigating away
            if (orderId) {
                jQuery.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'sp_clear_checkout_session',
                        order_id: orderId,
                        security: '<?php echo wp_create_nonce('sp_clear_checkout_session'); ?>'
                    },
                    success: function(response) {
                        console.log('✅ Session cleared, redirecting to checkout');
                        window.location.href = '<?php echo esc_js(wc_get_checkout_url()); ?>';
                    },
                    error: function() {
                        console.warn('⚠️ Failed to clear session, redirecting anyway');
                        window.location.href = '<?php echo esc_js(wc_get_checkout_url()); ?>';
                    }
                });
            } else {
                // No order ID, just redirect
                window.location.href = '<?php echo esc_js(wc_get_checkout_url()); ?>';
            }
        });
        
        // Clear session data when user leaves the page (back button, close tab, etc.)
        var clearingSession = false;
        function clearCheckoutSession() {
            if (clearingSession) return; // Prevent multiple calls
            clearingSession = true;
            
            var urlParams = new URLSearchParams(window.location.search);
            var orderId = urlParams.get('order_id');
            
            if (orderId) {
                console.log('🧹 Clearing checkout session on page unload (order_id: ' + orderId + ')');
                
                // Use sendBeacon for reliable delivery on page unload
                if (navigator.sendBeacon) {
                    var formData = new FormData();
                    formData.append('action', 'sp_clear_checkout_session');
                    formData.append('order_id', orderId);
                    formData.append('security', '<?php echo wp_create_nonce('sp_clear_checkout_session'); ?>');
                    
                    navigator.sendBeacon('<?php echo admin_url('admin-ajax.php'); ?>', formData);
                } else {
                    // Fallback: synchronous AJAX (not ideal but works)
                    jQuery.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        async: false,
                        data: {
                            action: 'sp_clear_checkout_session',
                            order_id: orderId,
                            security: '<?php echo wp_create_nonce('sp_clear_checkout_session'); ?>'
                        }
                    });
                }
            }
        }
        
        // Clear session when page is unloaded (user closes tab, navigates away, etc.)
        window.addEventListener('beforeunload', clearCheckoutSession);
        
        // Also clear on pagehide for better mobile support
        window.addEventListener('pagehide', clearCheckoutSession);
        
        // Note: PostMessage listener already set up in inline script above (loads earlier)
        
        // Check iframe URL periodically for redirects
        var checkInterval = setInterval(function() {
            try {
                var iframe = document.getElementById('stablecoin-pay-checkout-iframe');
                if (iframe && iframe.contentWindow) {
                    var iframeUrl = iframe.contentWindow.location.href;
                    
                    // Check if iframe has redirected to order-received page
                    if (iframeUrl.includes('order-received')) {
                        console.log('🔄 Iframe redirected to order-received, redirecting parent');
                        clearInterval(checkInterval);
                        window.location.href = iframeUrl;
                        return;
                    }
                }
            } catch(e) {
                // Cross-origin restrictions - this is expected
                // The iframe may have redirected to a different domain
            }
        }, 1000);
        
        // Stop checking after 5 minutes
        setTimeout(function() {
            clearInterval(checkInterval);
        }, 300000);
    });
    </script>
    <?php
    $output = ob_get_clean();
    error_log('✅ PP Checkout Page: Output buffer closed, length: ' . strlen($output) . ' bytes');
    return $output;
}

// Hook into WordPress
add_action('plugins_loaded', 'sp_commerce_init');

/**
 * Register the plugin's custom order status.
 *
 * The refund flow moves orders to `refund-pending` while a payout is in flight.
 * WC_Abstract_Order::set_status() silently falls back to `pending` for any status
 * it does not recognise, so without this registration those orders were stamped
 * "Pending payment" - which reads as unpaid, on an order that was in fact paid and
 * being refunded.
 */
function sp_register_order_statuses() {
    register_post_status('wc-refund-pending', array(
        'label'                     => _x('Refund pending', 'Order status', 'stablecoin-pay'),
        'public'                    => false,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        /* translators: %s: order count */
        'label_count'               => _n_noop(
            'Refund pending <span class="count">(%s)</span>',
            'Refund pending <span class="count">(%s)</span>',
            'stablecoin-pay'
        ),
    ));

    // WooCommerce has no core "partially refunded" status - a partial refund
    // leaves the order in whatever status it was already in, which reads as
    // "nothing was refunded". Register one so a partial payout is visible in the
    // orders list instead of being indistinguishable from an untouched order.
    register_post_status('wc-partially-refunded', array(
        'label'                     => _x('Partially refunded', 'Order status', 'stablecoin-pay'),
        'public'                    => false,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        /* translators: %s: order count */
        'label_count'               => _n_noop(
            'Partially refunded <span class="count">(%s)</span>',
            'Partially refunded <span class="count">(%s)</span>',
            'stablecoin-pay'
        ),
    ));
}
add_action('init', 'sp_register_order_statuses');

/**
 * Make the custom status selectable and visible in the WooCommerce order lists.
 *
 * @param array $statuses
 * @return array
 */
function sp_add_order_statuses($statuses) {
    $reordered = array();

    foreach ($statuses as $key => $label) {
        $reordered[$key] = $label;
        // Sit next to the built-in refunded status, where a merchant looks for them.
        if ($key === 'wc-refunded') {
            $reordered['wc-partially-refunded'] = _x('Partially refunded', 'Order status', 'stablecoin-pay');
            $reordered['wc-refund-pending']     = _x('Refund pending', 'Order status', 'stablecoin-pay');
        }
    }

    // If the built-in refunded status was filtered out by something else, still
    // make ours available rather than dropping them silently.
    if (!isset($reordered['wc-partially-refunded'])) {
        $reordered['wc-partially-refunded'] = _x('Partially refunded', 'Order status', 'stablecoin-pay');
    }
    if (!isset($reordered['wc-refund-pending'])) {
        $reordered['wc-refund-pending'] = _x('Refund pending', 'Order status', 'stablecoin-pay');
    }

    return $reordered;
}
add_filter('wc_order_statuses', 'sp_add_order_statuses');
add_filter('woocommerce_payment_gateways', 'sp_add_gateway_class');
add_action('before_woocommerce_init', 'sp_commerce_declare_hpos_compatibility');

// Load plugin text domain on init hook (prevents translation loading warnings)
add_action('init', function() {
    load_plugin_textdomain('stablecoin-pay', false, dirname(plugin_basename(__FILE__)) . '/languages');
}, 1);

// Generate webhook secret on activation as well
function sp_plugin_activate_secret() {
    if (!get_option('sp_webhook_secret')) {
        $secret = wp_generate_password(32, false, false);
        add_option('sp_webhook_secret', $secret, '', false);
    }
}
register_activation_hook(__FILE__, 'sp_plugin_activate_secret');


/**
 * Block checkout integration toggle.
 *
 * When `true` the gateway is registered with WooCommerce Blocks and the
 * "Pay with Crypto" option appears on the modern block-based checkout
 * (see includes/class-sp-blocks-payment-method.php + src/blocks/*.js).
 * When `false` only the legacy classic-checkout shortcode flow is wired up.
 *
 * Both flows share `WC_Gateway_SP::process_payment()` and the same
 * hosted-checkout iframe UX, so flipping this on does not regress
 * classic-checkout merchants — it just adds block-checkout support
 * alongside it.
 */
if (!defined('SP_BLOCKS_CHECKOUT_ENABLED')) {
    define('SP_BLOCKS_CHECKOUT_ENABLED', true);
}

/**
 * Register the gateway with the WooCommerce Blocks payment-method registry.
 *
 * Fires only when WooCommerce Blocks has loaded. Safe on stores that don't
 * have block checkout — the action simply never fires there.
 */
add_action('woocommerce_blocks_loaded', function () {
    if (!class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\PaymentMethodRegistry')) {
        return;
    }
    if (!class_exists('SP_Blocks_Payment_Method')) {
        return;
    }
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function ($payment_method_registry) {
            $payment_method_registry->register(new SP_Blocks_Payment_Method());
        }
    );
});

// NOTE: We do NOT force classic checkout store-wide. The gateway works on
// both classic (shortcode) and block-based WooCommerce checkout pages, so
// merchants can pick whichever they prefer without affecting any other
// payment methods they have installed (Stripe, PayPal, Apple Pay, etc.).
// The block-checkout integration lives in:
//   - includes/class-sp-blocks-payment-method.php  (server-side)
//   - src/blocks/*.js → build/index.js              (React content component)

// Force gateway availability for debugging (lower priority to avoid conflicts)
// Only log on checkout page to reduce log noise
add_filter('woocommerce_available_payment_gateways', 'sp_force_availability', 20);

function sp_force_availability($gateways) {
    // Only log detailed debug info on checkout page, not admin (reduces log noise)
    if (is_checkout()) {
        $page_context = 'CHECKOUT';
        error_log('🔧 PP - woocommerce_available_payment_gateways filter called on [' . $page_context . ']');
        error_log('🔧 PP - All available gateways: ' . implode(', ', array_keys($gateways)));
        error_log('🔧 PP - Total gateways count: ' . count($gateways));
        
        if (isset($gateways['sp'])) {
            error_log('🔧 PP - ✅ Gateway IS in available list! PP should be visible!');
            error_log('🔧 PP - Gateway object type: ' . get_class($gateways['sp']));
            error_log('🔧 PP - Gateway title: ' . $gateways['sp']->title);
            error_log('🔧 PP - Gateway enabled: ' . $gateways['sp']->enabled);
        } else {
            error_log('🔧 PP - ❌ Gateway NOT in available list! Being filtered out by WooCommerce!');
            error_log('🔧 PP - This means is_available() returned false OR gateway not registered');
        }
    }
    
    return $gateways;
}


// Always show refund buttons for Stablecoin Pay orders
add_filter('woocommerce_can_refund_order', 'sp_always_show_refund_button', 10, 2);
function sp_always_show_refund_button($can_refund, $order) {
    if ($order->get_payment_method() === 'sp') {
        $paid_statuses = array('processing', 'completed', 'on-hold');
        if (in_array($order->get_status(), $paid_statuses)) {
            return true;
        }
    }
    return $can_refund;
}

// Debug payment processing
add_action('woocommerce_checkout_process', 'sp_debug_checkout_process');
function sp_debug_checkout_process() {
    error_log('🛒 PP - woocommerce_checkout_process action fired');
    error_log('🛒 PP - POST data: ' . json_encode($_POST));
    
    if (isset($_POST['payment_method'])) {
        error_log('🛒 PP - Payment method in POST: ' . $_POST['payment_method']);
        if ($_POST['payment_method'] === 'sp') {
            error_log('🛒 PP - ✅ PP payment method selected!');
        }
    } else {
        error_log('🛒 PP - ❌ No payment_method in POST data');
    }
}

// Debug before payment processing
add_action('woocommerce_before_checkout_process', 'sp_debug_before_checkout');
function sp_debug_before_checkout() {
    error_log('🚀 PP - woocommerce_before_checkout_process action fired');
    error_log('🚀 PP - Cart total: $' . WC()->cart->get_total('edit'));
    error_log('🚀 PP - Cart items: ' . WC()->cart->get_cart_contents_count());
}

// Debug after payment processing
add_action('woocommerce_after_checkout_process', 'sp_debug_after_checkout');
function sp_debug_after_checkout() {
    error_log('✅ PP - woocommerce_after_checkout_process action fired');
}


// Activation and deactivation hooks
register_activation_hook(__FILE__, 'sp_commerce_activate');
register_deactivation_hook(__FILE__, 'sp_commerce_deactivate');

// Add settings link to plugins page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'sp_add_settings_link');

function sp_add_settings_link($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=sp') . '">' . __('Settings', 'stablecoin-pay') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Remove default plugin page links (Visit plugin site, Review)
add_filter('plugin_row_meta', 'sp_remove_plugin_meta_links', 10, 2);

function sp_remove_plugin_meta_links($links, $file) {
    if (strpos($file, 'sp-commerce.php') !== false) {
        // Remove all default meta links
        return array();
    }
    return $links;
}

// AJAX handler for modal payment processing
add_action('wp_ajax_sp_process_payment', 'sp_ajax_process_payment');
add_action('wp_ajax_nopriv_sp_process_payment', 'sp_ajax_process_payment');

// AJAX handler for clearing cart after successful payment
add_action('wp_ajax_sp_clear_cart_after_payment', 'sp_ajax_clear_cart_after_payment');
add_action('wp_ajax_nopriv_sp_clear_cart_after_payment', 'sp_ajax_clear_cart_after_payment');
add_action('wp_ajax_sp_check_webhook_status', 'sp_ajax_check_webhook_status');
add_action('wp_ajax_nopriv_sp_check_webhook_status', 'sp_ajax_check_webhook_status');

// Register AJAX handler for getting latest order URL
add_action('wp_ajax_sp_get_latest_order_url', 'sp_ajax_get_latest_order_url');
add_action('wp_ajax_nopriv_sp_get_latest_order_url', 'sp_ajax_get_latest_order_url');

// Register AJAX handler for clearing checkout session when user leaves checkout page
add_action('wp_ajax_sp_clear_checkout_session', 'sp_ajax_clear_checkout_session');
add_action('wp_ajax_nopriv_sp_clear_checkout_session', 'sp_ajax_clear_checkout_session');

// WordPress Heartbeat for real-time webhook communication
add_filter('heartbeat_received', 'sp_heartbeat_received', 10, 3);
add_filter('heartbeat_nopriv_received', 'sp_heartbeat_received', 10, 3);

function sp_ajax_process_payment() {
    // Note: Nonce check removed - checkout process creates order first, then redirects to payment
    // The actual payment happens on Stablecoin Pay's secure checkout page, not during this AJAX call
    
    // Check if cart is empty
    if (WC()->cart->is_empty()) {
        wp_send_json_error('Cart is empty');
    }

    // Server-side guard: enforce required checkout fields for Stablecoin Pay
    // custom AJAX flow.
    $checkout = WC_Checkout::instance();
    $posted_data = wp_unslash($_POST);

    // Before validating, mirror common contact fields across billing &
    // shipping so a customer who only typed into one fieldset (e.g.
    // entered the phone into the shipping form with "use same address
    // for billing" on) still passes validation. The client-side payload
    // already does this in the happy path; this is a defensive backstop
    // in case any WC Blocks variant skipped one of the fields.
    $mirror_pairs = array(
        array('billing_phone',      'shipping_phone'),
        array('billing_email',      'shipping_email'),
        array('billing_first_name', 'shipping_first_name'),
        array('billing_last_name',  'shipping_last_name'),
        array('billing_address_1',  'shipping_address_1'),
        array('billing_city',       'shipping_city'),
        array('billing_state',      'shipping_state'),
        array('billing_postcode',   'shipping_postcode'),
        array('billing_country',    'shipping_country'),
    );
    foreach ($mirror_pairs as $pair) {
        list($a, $b) = $pair;
        $aval = isset($posted_data[$a]) ? trim((string) $posted_data[$a]) : '';
        $bval = isset($posted_data[$b]) ? trim((string) $posted_data[$b]) : '';
        if ($aval === '' && $bval !== '') {
            $posted_data[$a] = $bval;
            $_POST[$a]       = $bval;
        }
        if ($bval === '' && $aval !== '') {
            $posted_data[$b] = $aval;
            $_POST[$b]       = $aval;
        }
    }

    $required_field_labels = array();
    $all_fields = $checkout->get_checkout_fields();
    foreach ($all_fields as $fieldset_key => $fieldset_fields) {
        if (!is_array($fieldset_fields)) {
            continue;
        }
        foreach ($fieldset_fields as $field_key => $field) {
            if (empty($field['required'])) {
                continue;
            }
            // Only validate fields that are expected in this custom flow.
            if (strpos($field_key, 'billing_') !== 0 && strpos($field_key, 'shipping_') !== 0) {
                continue;
            }
            $value = isset($posted_data[$field_key]) ? trim((string) $posted_data[$field_key]) : '';
            if ($value === '') {
                $required_field_labels[] = isset($field['label']) && $field['label'] !== '' ? $field['label'] : $field_key;
            }
        }
    }
    if (!empty($required_field_labels)) {
        error_log('PP AJAX: Validation failed. Missing: ' . implode(', ', $required_field_labels)
            . '. Received billing_phone="' . (isset($_POST['billing_phone']) ? $_POST['billing_phone'] : '(unset)') . '"'
            . ', shipping_phone="' . (isset($_POST['shipping_phone']) ? $_POST['shipping_phone'] : '(unset)') . '"'
            . ', billing_email="' . (isset($_POST['billing_email']) ? $_POST['billing_email'] : '(unset)') . '"');
        wp_send_json_error('Please fill in required fields: ' . implode(', ', $required_field_labels));
    }
    
    // IMPORTANT: Don't reuse orders with checkout URLs - they're one-time use only!
    // Always create a fresh order and purchase session for each checkout attempt
    // Clear any existing order from session to ensure fresh start
    $existing_order_id = WC()->session->get('sp_order_id');
    if ($existing_order_id) {
        // Clear the existing order from session - user will get a fresh order
        WC()->session->set('sp_order_id', null);
        WC()->session->set('sp_checkout_url_' . $existing_order_id, null);
        WC()->session->set('sp_pending_order_id', null);
    }

    // Add a short-lived lock to prevent concurrent requests from creating duplicates
    // BUT: Don't reuse orders with checkout URLs - they're one-time use only!
    $lock_key = 'sp_order_lock';
    $lock_time = time();
    $existing_lock = WC()->session->get($lock_key);
    if ($existing_lock && ($lock_time - intval($existing_lock)) < 5) { // 5-second window
        // Only check if there's a paid order - don't reuse pending orders with checkout URLs (one-time use)
        $orders = wc_get_orders(array(
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'payment_method' => 'sp'
        ));
        
        if (!empty($orders)) {
            $o = $orders[0];
            
            // Only reuse if order is already paid (processing/completed) - send to order received
            if (in_array($o->get_status(), array('processing','completed'))) {
                wp_send_json_success(array('result' => 'success', 'redirect' => $o->get_checkout_order_received_url(), 'order_id' => $o->get_id(), 'already_paid' => true));
            }
            
            // Don't reuse pending/on-hold orders - checkout URLs are one-time use
            // Just tell user to wait and we'll create a fresh order
        }
        
        // If no paid order found, tell client to wait and retry (will create fresh order)
        wp_send_json_error('Another payment attempt is already in progress. Please wait a moment...');
    }
    WC()->session->set($lock_key, $lock_time);

    // Get the payment gateway instance
    try {
        $gateway = new WC_Gateway_SP();
    } catch (Exception $e) {
        error_log('PP AJAX: Failed to create gateway instance: ' . $e->getMessage());
        wp_send_json_error('Failed to initialize payment gateway');
    }
    
    // Create order using WooCommerce's standard method
    // Create order using wc_create_order() which is the correct method
    $order = wc_create_order();
    
    if (!$order || is_wp_error($order)) {
        error_log('PP AJAX: Failed to create order');
        wp_send_json_error('Failed to create order');
    }
    
    $order_id = $order->get_id();

    // Store order id in session to prevent duplicates on repeated clicks
    WC()->session->set('sp_order_id', $order_id);
    
    // Add cart items to order. We pass the cart line's actual prices so
    // discounts/coupons applied at the cart level (which only affect the
    // line total — `$product->get_price()` doesn't know about them) carry
    // over to the order line items.
    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        $product = $cart_item['data'];
        $order->add_product(
            $product,
            $cart_item['quantity'],
            array(
                'subtotal'     => isset($cart_item['line_subtotal']) ? $cart_item['line_subtotal'] : null,
                'total'        => isset($cart_item['line_total']) ? $cart_item['line_total'] : null,
                'subtotal_tax' => isset($cart_item['line_subtotal_tax']) ? $cart_item['line_subtotal_tax'] : 0,
                'total_tax'    => isset($cart_item['line_tax']) ? $cart_item['line_tax'] : 0,
                'taxes'        => isset($cart_item['line_tax_data']) ? $cart_item['line_tax_data'] : array(),
            )
        );
    }
    
    // Helper: read a POST field with sanitization, falling back to a
    // sibling field when the primary is empty. Used so an empty
    // shipping_* field automatically mirrors the matching billing_*
    // field (the common "ship to same address" case), keeping the
    // server in sync with the client-side mirror logic in
    // sp-checkout-modal.php / src/blocks/content.js.
    $post_field = function ($key, $fallback_key = null) {
        $val = isset($_POST[$key]) ? trim((string) $_POST[$key]) : '';
        if ($val === '' && $fallback_key !== null) {
            $val = isset($_POST[$fallback_key]) ? trim((string) $_POST[$fallback_key]) : '';
        }
        return $val;
    };

    // Set billing address from form data
    $order->set_billing_first_name(sanitize_text_field($post_field('billing_first_name')));
    $order->set_billing_last_name(sanitize_text_field($post_field('billing_last_name')));
    $order->set_billing_company(sanitize_text_field($post_field('billing_company')));
    $order->set_billing_email(sanitize_email($post_field('billing_email')));
    $order->set_billing_phone(sanitize_text_field($post_field('billing_phone')));
    $order->set_billing_address_1(sanitize_text_field($post_field('billing_address_1')));
    $order->set_billing_address_2(sanitize_text_field($post_field('billing_address_2')));
    $order->set_billing_city(sanitize_text_field($post_field('billing_city')));
    $order->set_billing_state(sanitize_text_field($post_field('billing_state')));
    $order->set_billing_postcode(sanitize_text_field($post_field('billing_postcode')));
    $order->set_billing_country(sanitize_text_field($post_field('billing_country')));

    // Set shipping address. Each shipping field falls back to the matching
    // billing field when empty (ship-to-same-address case). This makes the
    // saved order's shipping panel populated correctly in the admin even
    // when the customer didn't tick "ship to different address".
    $order->set_shipping_first_name(sanitize_text_field($post_field('shipping_first_name', 'billing_first_name')));
    $order->set_shipping_last_name(sanitize_text_field($post_field('shipping_last_name', 'billing_last_name')));
    $order->set_shipping_company(sanitize_text_field($post_field('shipping_company', 'billing_company')));
    $order->set_shipping_address_1(sanitize_text_field($post_field('shipping_address_1', 'billing_address_1')));
    $order->set_shipping_address_2(sanitize_text_field($post_field('shipping_address_2', 'billing_address_2')));
    $order->set_shipping_city(sanitize_text_field($post_field('shipping_city', 'billing_city')));
    $order->set_shipping_state(sanitize_text_field($post_field('shipping_state', 'billing_state')));
    $order->set_shipping_postcode(sanitize_text_field($post_field('shipping_postcode', 'billing_postcode')));
    $order->set_shipping_country(sanitize_text_field($post_field('shipping_country', 'billing_country')));
    
    // Set payment method (whitelabel name for display)
    $order->set_payment_method('sp');
    $pm_title = __('Stablecoin Pay', 'stablecoin-pay');
    if (class_exists('SP_Whitelabel_Branding')) {
        $name = SP_Whitelabel_Branding::get_whitelabel_plugin_name_from_config();
        if (!empty($name)) {
            $pm_title = sprintf(__('Pay with %s', 'stablecoin-pay'), $name);
        }
    }
    $order->set_payment_method_title($pm_title);
    
    // Set customer ID if user is logged in
    if (is_user_logged_in()) {
        $order->set_customer_id(get_current_user_id());
    }
    
    // Set billing email for guest orders (needed for order association)
    $billing_email = sanitize_email($_POST['billing_email']);
    if ($billing_email) {
        $order->set_billing_email($billing_email);
    }
    
    // Mirror the customer's shipping/billing address onto WC()->customer
    // so cart shipping rates are recalculated against the address the
    // customer just entered. Without this, WC()->cart->get_shipping_total()
    // can be 0 in block-checkout flows where the Store API didn't have a
    // chance to re-quote shipping against this exact order's address.
    if (WC()->customer) {
        WC()->customer->set_billing_first_name($order->get_billing_first_name());
        WC()->customer->set_billing_last_name($order->get_billing_last_name());
        WC()->customer->set_billing_address_1($order->get_billing_address_1());
        WC()->customer->set_billing_address_2($order->get_billing_address_2());
        WC()->customer->set_billing_city($order->get_billing_city());
        WC()->customer->set_billing_state($order->get_billing_state());
        WC()->customer->set_billing_postcode($order->get_billing_postcode());
        WC()->customer->set_billing_country($order->get_billing_country());
        WC()->customer->set_shipping_first_name($order->get_shipping_first_name());
        WC()->customer->set_shipping_last_name($order->get_shipping_last_name());
        WC()->customer->set_shipping_address_1($order->get_shipping_address_1());
        WC()->customer->set_shipping_address_2($order->get_shipping_address_2());
        WC()->customer->set_shipping_city($order->get_shipping_city());
        WC()->customer->set_shipping_state($order->get_shipping_state());
        WC()->customer->set_shipping_postcode($order->get_shipping_postcode());
        WC()->customer->set_shipping_country($order->get_shipping_country());
        WC()->customer->save();
    }

    // Tell WooCommerce which payment method is being used *before* we
    // recompute totals. Lots of "extra fee" / "payment surcharge" plugins
    // (WooCommerce Extra Fees, Payment Gateway Based Fees and Discounts,
    // etc.) hook `woocommerce_cart_calculate_fees` and only add their
    // surcharge when `WC()->session->get('chosen_payment_method')`
    // matches their configured gateway. In our custom AJAX flow nothing
    // ever sets that, so the fee silently disappears from the cart total
    // we forward to the provider. Setting it here makes the hook fire
    // exactly the same way it would on a normal Woo checkout submit.
    $posted_pm = isset($_POST['payment_method']) ? sanitize_text_field(wp_unslash($_POST['payment_method'])) : 'sp';
    if (WC()->session) {
        WC()->session->set('chosen_payment_method', $posted_pm ?: 'sp');
    }

    // Recalculate shipping packages against the customer's address, then
    // recompute cart totals so shipping/tax/fees are up to date before we
    // copy them onto the order. `calculate_totals()` is what fires
    // `woocommerce_cart_calculate_fees`, so any conditional surcharges
    // (now that `chosen_payment_method` is set) attach to the cart in
    // this call.
    WC()->cart->calculate_shipping();
    WC()->cart->calculate_fees();
    WC()->cart->calculate_totals();

    // Transfer the chosen shipping method(s) from the cart to the order
    // as shipping line items. Without this, `wc_create_order()` would
    // produce an order whose total is just the product subtotal — which
    // is the root cause of the "purchase session amount = $0.12 for a
    // $64.83 product" bug we hit during block-checkout testing.
    $packages = WC()->shipping() ? WC()->shipping()->get_packages() : array();
    if (!empty($packages)) {
        $chosen_methods = WC()->session ? (array) WC()->session->get('chosen_shipping_methods', array()) : array();
        foreach ($packages as $package_key => $package) {
            $chosen_id = isset($chosen_methods[$package_key]) ? $chosen_methods[$package_key] : '';
            if (!$chosen_id || !isset($package['rates'][$chosen_id])) {
                continue;
            }
            $rate = $package['rates'][$chosen_id];

            $item = new WC_Order_Item_Shipping();
            $item->set_props(array(
                'method_title' => $rate->label,
                'method_id'    => $rate->method_id,
                'instance_id'  => $rate->instance_id,
                'total'        => wc_format_decimal($rate->cost),
                'taxes'        => array('total' => $rate->taxes),
            ));
            foreach ($rate->get_meta_data() as $meta_key => $meta_value) {
                $item->add_meta_data($meta_key, $meta_value, true);
            }
            $order->add_item($item);
        }
    }

    // Transfer cart fees (handling fees, payment surcharges, etc.).
    // Mirror WC_Checkout::create_order_fee_lines() exactly — including
    // the legacy_fee* props that some extensions read — so percent/
    // taxable fees behave identically to a native Woo checkout.
    foreach (WC()->cart->get_fees() as $fee_key => $fee) {
        $item = new WC_Order_Item_Fee();
        $item->legacy_fee     = $fee;
        $item->legacy_fee_key = $fee_key;
        $item->set_props(array(
            'name'      => $fee->name,
            'tax_class' => (!empty($fee->taxable) && isset($fee->tax_class)) ? $fee->tax_class : 0,
            'amount'    => isset($fee->amount) ? $fee->amount : 0,
            'total'     => isset($fee->total) ? $fee->total : (isset($fee->amount) ? $fee->amount : 0),
            'total_tax' => isset($fee->tax) ? $fee->tax : 0,
            'taxes'     => array(
                'total' => isset($fee->tax_data) ? $fee->tax_data : array(),
            ),
        ));
        $order->add_item($item);
        error_log('PP AJAX: Added fee line "' . $fee->name . '" $' . (isset($fee->total) ? $fee->total : $fee->amount));
    }

    // Transfer cart tax lines so the order tax breakdown matches the cart.
    foreach (array_keys(WC()->cart->get_cart_contents_taxes() + WC()->cart->get_shipping_taxes() + WC()->cart->get_fee_taxes()) as $tax_rate_id) {
        if ($tax_rate_id && apply_filters('woocommerce_cart_remove_taxes_zero_rate_id', 'zero-rated') !== $tax_rate_id) {
            $item = new WC_Order_Item_Tax();
            $item->set_rate($tax_rate_id);
            $item->set_tax_total(WC()->cart->get_tax_amount($tax_rate_id));
            $item->set_shipping_tax_total(WC()->cart->get_shipping_tax_amount($tax_rate_id));
            $order->add_item($item);
        }
    }

    // Apply the same coupons that were on the cart so the order line
    // items + totals reflect them after `calculate_totals()`.
    foreach (WC()->cart->get_applied_coupons() as $coupon_code) {
        $order->apply_coupon($coupon_code);
    }

    // Calculate totals and save (now includes shipping + fees + tax).
    $order->calculate_totals();
    $order->save();

    // If the front end (block checkout) shipped the displayed totals
    // along with the request, treat the GRAND TOTAL (`cart_total_minor`,
    // which mirrors WC Store API `totals.total_price`) as the source of
    // truth — that's the "Total" line the customer just agreed to on
    // screen (e.g. $450.80). We deliberately ignore `cart_total_items_minor`
    // which is the SUBTOTAL ($402.50) — only useful as metadata. The
    // displayed total is persisted on the order so `process_payment` and
    // `prepare_purchase_session_from_cart` find it without re-reading POST.
    if (isset($_POST['cart_total_minor']) && $_POST['cart_total_minor'] !== '') {
        $minor_unit = isset($_POST['cart_currency_minor_unit']) ? (int) $_POST['cart_currency_minor_unit'] : 2;
        $minor_unit = ($minor_unit >= 0 && $minor_unit <= 6) ? $minor_unit : 2;
        $divisor = pow(10, $minor_unit);

        $displayed_total    = ((float) $_POST['cart_total_minor']) / $divisor;
        $displayed_subtotal = isset($_POST['cart_total_items_minor']) && $_POST['cart_total_items_minor'] !== ''
            ? ((float) $_POST['cart_total_items_minor']) / $divisor
            : null;

        if ($displayed_total > 0) {
            $order->update_meta_data('_sp_displayed_total', $displayed_total);
            $order->update_meta_data('_sp_displayed_currency', isset($_POST['cart_currency_code']) ? sanitize_text_field(wp_unslash($_POST['cart_currency_code'])) : '');
            $order->save();
            error_log('PP AJAX: Front-end reported GRAND TOTAL: $' . number_format($displayed_total, 2)
                . ' (' . ($order->get_meta('_sp_displayed_currency') ?: 'currency unset') . ')'
                . ($displayed_subtotal !== null ? ' [subtotal for reference: $' . number_format($displayed_subtotal, 2) . ']' : ''));
        }
    }

    error_log('PP AJAX: Order #' . $order_id . ' built. Subtotal $' . $order->get_subtotal() . ' + shipping $' . $order->get_shipping_total() . ' + tax $' . $order->get_total_tax() . ' = total $' . $order->get_total());
    
    // If this order already has a checkout URL (rare race), reuse it
    $existing_checkout = sp_normalize_checkout_url($order->get_meta('_sp_checkout_url'));
    if (!empty($existing_checkout)) {
        error_log('🔗 PP AJAX: Found existing checkout URL: ' . $existing_checkout);
        // Store checkout URL in session to avoid long URLs
        WC()->session->set('sp_checkout_url_' . $order_id, $existing_checkout);
        
        // Get dedicated checkout page URL - use order_id instead of full URL
        $checkout_page_id = get_option('sp_checkout_page_id');
        if ($checkout_page_id) {
            $checkout_page_url = get_permalink($checkout_page_id);
            $redirect_url = add_query_arg('order_id', $order_id, $checkout_page_url);
            $result = array('result' => 'success', 'redirect' => $redirect_url, 'sp_checkout_url' => $existing_checkout);
        } else {
            // Fallback: redirect directly to checkout URL
            $result = array('result' => 'success', 'redirect' => $existing_checkout);
        }
    } else {
        // Process payment - this will create the purchase session
        $result = $gateway->process_payment($order->get_id());
    }
    
    if ($result['result'] === 'success') {
        // Log checkout URL if present in result
        if (isset($result['sp_checkout_url'])) {
            error_log('🔗 PP AJAX: Checkout URL in result: ' . $result['sp_checkout_url']);
        }
        // Also check order meta for checkout URL
        $checkout_url_from_order = $order->get_meta('_sp_checkout_url');
        if (!empty($checkout_url_from_order)) {
            error_log('🔗 PP AJAX: Checkout URL from order meta: ' . $checkout_url_from_order);
        }
        wp_send_json_success($result);
    } else {
        error_log('PP AJAX: Payment failed: ' . ($result['messages'] ?? 'Unknown error'));
        wp_send_json_error($result['messages'] ?? 'Payment failed');
    }
}

function sp_ajax_clear_cart_after_payment() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['security'], 'sp_clear_cart')) {
        error_log('Clear Cart: Security check failed');
        wp_die('Security check failed');
    }
    
    error_log('🆕 Clear Cart: Clearing cart and session after successful payment - ready for new order!');
    
    // Clear the WooCommerce cart completely

    
    // Clear all Stablecoin Pay session data - FRESH START!
    WC()->session->set('sp_order_id', null);
    WC()->session->set('sp_purchase_session_id', null); 
    
    // Force cart recalculation
    WC()->cart->calculate_totals();
    
    // Clear any cart fragments
    wc_clear_notices();
    
    error_log('✅ PP Clear Cart: Cart and session cleared successfully - ready for new orders!');
    
    wp_send_json_success(array('message' => 'Cart cleared successfully - ready for new orders!'));
}

function sp_ajax_check_webhook_status() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['security'], 'sp_check_webhook')) {
        error_log('PP Check Webhook: Security check failed');
        wp_die('Security check failed');
    }
    
    error_log('🔍 PP Check Webhook: Checking for webhook completion...');
    
    // Get the most recent order with Stablecoin Pay payment method
    $orders = wc_get_orders(array(
        'limit' => 1,
        'orderby' => 'date',
        'order' => 'DESC',
        'payment_method' => 'sp'
    ));
    
    if (empty($orders)) {
        error_log('PP Check Webhook: No PP orders found');
        wp_send_json_error('No orders found');
    }
    
    $order = $orders[0];
    $redirect_flag = $order->get_meta('_sp_redirect_to_received');
    
    if ($redirect_flag === 'yes') {
        error_log('✅ PP Check Webhook: Webhook completed for order #' . $order->get_id());
        
        // Clear the redirect flag
        $order->delete_meta_data('_sp_redirect_to_received');
        $order->save();
        
        // Get the order-received page URL (where customers see their completed order)
        $redirect_url = $order->get_checkout_order_received_url();
        
        wp_send_json_success(array('redirect_url' => $redirect_url));
    } else {
        error_log('PP Check Webhook: Webhook not yet completed for order #' . $order->get_id());
        wp_send_json_error('Webhook not completed yet');
    }
}

/**
 * AJAX handler to clear checkout session when user leaves checkout page
 * This prevents reuse of one-time-use purchase session URLs
 */
function sp_ajax_clear_checkout_session() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['security'], 'sp_clear_checkout_session')) {
        error_log('PP Clear Checkout Session: Security check failed');
        wp_send_json_error('Security check failed');
    }
    
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    
    if (!$order_id) {
        error_log('PP Clear Checkout Session: No order ID provided');
        wp_send_json_error('No order ID provided');
    }
    
    error_log('🧹 PP Clear Checkout Session: Clearing session data for order_id: ' . $order_id);
    
    // Clear order ID from session
    WC()->session->set('sp_order_id', null);
    
    // Clear checkout URL from session for this specific order
    WC()->session->set('sp_checkout_url_' . $order_id, null);
    
    // Clear pending order ID
    WC()->session->set('sp_pending_order_id', null);
    
    // Clear purchase session ID
    WC()->session->set('sp_purchase_session_id', null);
    
    error_log('✅ PP Clear Checkout Session: Session cleared for order_id: ' . $order_id . ' - user will get fresh order on next checkout');
    
    wp_send_json_success(array('message' => 'Session cleared successfully'));
}

/**
 * AJAX handler to get the latest order URL for backup redirect
 */
function sp_ajax_get_latest_order_url() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['security'], 'sp_get_order_url')) {
        error_log('PP Get Order URL: Security check failed');
        wp_die('Security check failed');
    }
    
    error_log('🔄 PP Get Order URL: Checking for latest order...');
    
    // Get the most recent order with Stablecoin Pay payment method
    $orders = wc_get_orders(array(
        'limit' => 1,
        'orderby' => 'date',
        'order' => 'DESC',
        'payment_method' => 'sp'
    ));
    
    if (empty($orders)) {
        error_log('PP Get Order URL: No PP orders found');
        wp_send_json_error('No orders found');
    }
    
    $order = $orders[0];
    $order_status = $order->get_status();
    
    error_log('PP Get Order URL: Found order #' . $order->get_id() . ' with status: ' . $order_status);
    
    // Check if order is completed/processing (payment successful)
    if (in_array($order_status, ['processing', 'completed', 'on-hold'])) {
        $redirect_url = $order->get_checkout_order_received_url();
        error_log('PP Get Order URL: Order completed, returning URL: ' . $redirect_url);
        wp_send_json_success(array('order_url' => $redirect_url));
    } else {
        error_log('PP Get Order URL: Order not yet completed, status: ' . $order_status);
        wp_send_json_error('Order not completed yet');
    }
}

/**
 * WordPress Heartbeat handler for real-time webhook communication
 */
function sp_heartbeat_received($response, $data, $screen_id) {
    // Check if frontend is requesting webhook status
    if (isset($data['sp_check_webhook']) && $data['sp_check_webhook']) {
        error_log('💓 PP Heartbeat: Checking for webhook completion...');
        
        // Get the most recent order with Stablecoin Pay payment method
        $orders = wc_get_orders(array(
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'payment_method' => 'sp'
        ));
        
        if (!empty($orders)) {
            $order = $orders[0];
            $redirect_flag = $order->get_meta('_sp_redirect_to_received');
            
            if ($redirect_flag === 'yes') {
                error_log('💓 PP Heartbeat: Webhook completed for order #' . $order->get_id());
                
                // Clear the redirect flag
                $order->delete_meta_data('_sp_redirect_to_received');
                $order->save();
                
                // Get the order-received page URL
                $redirect_url = $order->get_checkout_order_received_url();
                
                // Send response back to frontend
                $response['sp_webhook_complete'] = true;
                $response['sp_redirect_url'] = $redirect_url;
                
                error_log('💓 PP Heartbeat: Sending redirect URL to frontend: ' . $redirect_url);
            }
        }
    }
    
    return $response;
}

/**
 * Send Stablecoin Pay payment emails when order status changes to processing
 * DISABLED: WooCommerce handles all emails automatically based on order status
 */
function sp_send_payment_emails($order_id) {
    // Email sending disabled - WooCommerce will handle all emails automatically
    // when order status changes. Merchant can configure emails in WooCommerce > Settings > Emails
    return;
}

/**
 * Send Stablecoin Pay payment emails when order status changes (any status change)
 * DISABLED: WooCommerce handles all emails automatically based on order status
 */
function sp_send_payment_emails_on_status_change($order_id, $old_status, $new_status) {
    // Email sending disabled - WooCommerce will handle all emails automatically
    // when order status changes. Merchant can configure emails in WooCommerce > Settings > Emails
    return;
}

// Duplicate function removed

/**
 * Send custom Stablecoin Pay merchant notification
 * DISABLED: WooCommerce handles all emails automatically based on order status
 */


?>
