<?php
/**
 * Stablecoin Pay Payment Gateway
 * 
 * Simple cryptocurrency payment gateway for WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_SP extends WC_Payment_Gateway {
    
    private $api_client;
    private $brand_company = ''; // No default - will be set from branding API
    private $button_logo_url = ''; // Logo URL for button (injected via JS)
    private $button_company_name = ''; // Company name for button
    private $checkout_title = ''; // Whitelabel title for checkout only (not admin)
    private $checkout_icon = ''; // Whitelabel icon for checkout only (not admin)
    
    /**
     * Constructor
     */
    public function __construct() {
        // Only log on checkout, not in admin (reduces log noise)
        if (is_checkout()) {
        error_log('PP Gateway: Constructor called');
        }
        
        $this->id = 'sp';
        // Icon for Payments list (WooCommerce → Settings → Payments). Checkout uses get_icon().
        $this->icon = $this->get_list_logo_url();
        $this->has_fields = true; // Enable custom payment box
        // Display name from whitelabel config only (no hardcoding elsewhere)
        $config_name = class_exists('SP_Whitelabel_Branding') ? SP_Whitelabel_Branding::get_whitelabel_plugin_name_from_config() : null;
        $gateway_label = $config_name ? $config_name : __('Stablecoin Pay', 'stablecoin-pay');
        $this->method_title = $gateway_label;
        $this->method_description = $config_name ? sprintf(__('Accept Crypto payments with %s', 'stablecoin-pay'), $config_name) : __('Accept Crypto payments with Stablecoin Pay', 'stablecoin-pay');
        
        // Declare supported features
        $this->supports = array(
            'products',
            'refunds'
        );
        
        // Only log on checkout, not in admin (reduces log noise)
        if (is_checkout()) {
        error_log('PP Gateway: Supports: ' . json_encode($this->supports));
        }
        
        // Load settings
        $this->init_form_fields();
        $this->init_settings();
        
        // Admin title: from whitelabel config when set, else default
        $this->title = $config_name ? ('Pay with ' . $config_name) : 'Pay with Stablecoin Pay';
        $this->description = '';
        $this->enabled = $this->get_option('enabled', 'yes');
        
        // Initialize API client
        $this->api_client = new SP_API_Client();
        
        // CRITICAL: Only load whitelabel branding on frontend (checkout), NOT in admin
        // Admin/settings page should always show "Stablecoin Pay"
        if (!is_admin()) {
            $this->load_whitelabel_branding();
        } else {
            // In admin: use config display name when set (no hardcoding elsewhere). Logo from config only (no bundled default image).
            $this->checkout_title = $config_name ? ('Pay with ' . $config_name) : 'Pay with Stablecoin Pay';
            $admin_logo = class_exists('SP_Whitelabel_Branding') ? SP_Whitelabel_Branding::get_whitelabel_logo_url_from_config() : null;
            $this->checkout_icon = $admin_logo ? $admin_logo : '';
            $this->button_logo_url = $admin_logo ? $admin_logo : '';
            $this->button_company_name = $config_name ? $config_name : 'Stablecoin Pay';
        }
        
        // Only log constructor details on checkout, not in admin (reduces log noise)
        if (is_checkout()) {
        error_log('PP Gateway: Constructor - ID: ' . $this->id);
        error_log('PP Gateway: Constructor - Title: ' . $this->title);
        error_log('PP Gateway: Constructor - Description: ' . $this->description);
        error_log('PP Gateway: Constructor - Enabled: ' . $this->enabled);
        error_log('PP Gateway: Constructor - Merchant ID: ' . $this->get_option('merchant_id'));
        error_log('PP Gateway: Constructor - Method Title: ' . $this->method_title);
        error_log('PP Gateway: Constructor - Has fields: ' . ($this->has_fields ? 'YES' : 'NO'));
        }
        
        // Add hooks
        // CRITICAL: This hook fires when settings are saved - it's the primary way WooCommerce saves gateway settings
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'update_api_client_settings'), 10);
        
        // ALSO hook into admin_init to catch form submission early (backup method for debugging)
        add_action('admin_init', array($this, 'maybe_process_admin_options'), 5);
        
        // Automatically ensure checkout page has shortcode when gateway is enabled
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'ensure_checkout_shortcode_on_save'), 20);
        
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
        add_action('wp_footer', array($this, 'add_checkout_script'));
        add_action('wp_head', array($this, 'add_payment_button_styles'));
        
        // Check and restore cart if user returns with pending order
        add_action('woocommerce_checkout_init', array($this, 'maybe_restore_cart_from_pending_order'), 5);
        // Removed woocommerce_order_button_text filter - using default "Place order" for all payment methods
        
       
        add_action('admin_head', array($this, 'hide_manual_refund_ui_for_sp'));
        add_action('admin_footer', array($this, 'hide_manual_refund_js_for_sp'));
        add_filter('woocommerce_order_item_display_meta_key', array($this, 'customize_refund_meta_key'), 10, 3);
        
        // Simple approach: Just completely hide and disable manual refund button via CSS/JS only
        // No complex interception - just hide it so it can't be clicked
        // IMPORTANT: This ONLY affects Stablecoin Pay orders - other payment gateways (Stripe, PayPal, etc.) are unaffected
        
        // Add AJAX actions
        add_action('wp_ajax_sp_redirect_after_payment', array($this, 'redirect_after_payment_ajax'));
        add_action('wp_ajax_nopriv_sp_redirect_after_payment', array($this, 'redirect_after_payment_ajax'));
        
    }
    
    /**
     * Admin panel options
     */
    public function admin_options() {
        /**
         * CRITICAL FIX: We MUST call parent::admin_options() FIRST!
         * 
         * THE PROBLEM:
         * - When we output HTML before calling parent::admin_options(), it breaks WooCommerce's form structure
         * - WooCommerce's parent method expects to output the <form> tag from scratch
         * - If we output HTML first, the form action attribute ends up empty
         * 
         * THE SOLUTION:
         * - Call parent::admin_options() FIRST to generate the complete form structure
         * - Then inject instructions via JavaScript AFTER the form is rendered
         */
        
        parent::admin_options();
        
        // Inject instructions via JavaScript (after the form)
        $instructions_html = $this->get_setup_instructions_html();
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Inject instructions box at the top (after the h2 title, before the form table)
            var instructionsHtml = <?php echo json_encode($instructions_html); ?>;
            var instructions = $('<div style="background:#fff;border-left:4px solid #3b82f6;padding:20px;margin:20px 0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;color:#1d2327">').html(instructionsHtml);
            
            // Insert after the h2 title (which is the first h2 in the form)
            $('h2').first().after(instructions);
            
            // CRITICAL FIX: Ensure form action is set (run multiple times to catch dynamic form generation)
            function ensureFormAction() {
                var $form = $('form');
                if ($form.length > 0) {
                    var currentAction = $form.attr('action');
                    console.log('PP: Form action check:', currentAction || 'EMPTY - THIS IS THE PROBLEM!');
                    console.log('PP: Form method:', $form.attr('method'));
                    
                    // If form action is empty, set it to the FULL current URL with query params
                    // WooCommerce needs the full URL with page, tab, and section parameters
                    if (!currentAction || currentAction === '' || currentAction === '#') {
                        // Get the FULL current URL including query parameters
                        var currentUrl = window.location.href;
                        $form.attr('action', currentUrl);
                        console.log('PP: ⚠️ Form action was empty! Fixed to:', currentUrl);
                        return true; // Fixed
                    } else {
                        console.log('PP: ✅ Form action is set correctly:', currentAction);
                        return false; // Already set
                    }
                }
                return false; // Form not found
            }
            
            // Run immediately
            ensureFormAction();
            
            // Run again after a short delay (in case form is generated dynamically)
            setTimeout(function() {
                if (ensureFormAction()) {
                    console.log('PP: ✅ Form action fixed on delayed check');
                }
            }, 100);
            
            // Run one more time after a longer delay (for very slow form generation)
            setTimeout(function() {
                if (ensureFormAction()) {
                    console.log('PP: ✅ Form action fixed on final delayed check');
                }
            }, 500);
            
            // CRITICAL: WooCommerce may use AJAX or regular form submission
            // We need to catch BOTH scenarios
            
            // Method 1: Listen for form submit (regular POST)
            $('form').on('submit', function(e) {
                var $submitForm = $(this);
                console.log('PP: ✅✅✅ FORM SUBMIT EVENT FIRED! ✅✅✅');
                console.log('PP: Form action:', $submitForm.attr('action'));
                console.log('PP: Form method:', $submitForm.attr('method'));
                console.log('PP: Merchant ID value:', $('#woocommerce_sp_merchant_id').val());
                console.log('PP: API Key value:', $('#woocommerce_sp_api_key').val() ? '***SET***' : 'EMPTY');
                
                // Ensure form action is set before submission
                if (!$submitForm.attr('action') || $submitForm.attr('action') === '') {
                    var currentUrl = window.location.href;
                    $submitForm.attr('action', currentUrl);
                    console.log('PP: ⚠️ Form action was empty on submit! Fixed to:', currentUrl);
                }
                
                // CRITICAL: Verify all required fields are present
                var merchantId = $('#woocommerce_sp_merchant_id').val();
                var apiKey = $('#woocommerce_sp_api_key').val();
                console.log('PP: Pre-submit check - Merchant ID:', merchantId ? 'SET (' + merchantId.length + ' chars)' : 'EMPTY');
                console.log('PP: Pre-submit check - API Key:', apiKey ? 'SET (' + apiKey.length + ' chars)' : 'EMPTY');
                
                // Ensure enabled checkbox is included
                var enabledCheckbox = $('#woocommerce_sp_enabled');
                if (enabledCheckbox.length > 0) {
                    console.log('PP: Enabled checkbox found, checked:', enabledCheckbox.is(':checked'));
                }
                
                console.log('PP: Form will submit now...');
                // Don't prevent default - let form submit normally
            });
            
            // Also listen for form submission via AJAX (WooCommerce might use AJAX)
            $(document).on('submit', 'form', function(e) {
                console.log('PP: 🔄 Form submit event (document level) - Form action:', $(this).attr('action'));
            });
            
            // Method 2: Listen for save button clicks (BEFORE form submit)
            $(document).on('click', 'button[name="save"], input[name="save"], .button-primary[name="save"]', function(e) {
                var $form = $('form');
                var $button = $(this);
                console.log('PP: ✅✅✅ SAVE BUTTON CLICKED! ✅✅✅');
                console.log('PP: Button type:', $button.attr('type'));
                console.log('PP: Button name:', $button.attr('name'));
                console.log('PP: Merchant ID value:', $('#woocommerce_sp_merchant_id').val());
                console.log('PP: API Key value:', $('#woocommerce_sp_api_key').val() ? '***SET***' : 'EMPTY');
                console.log('PP: Form exists:', $form.length > 0);
                console.log('PP: Form action:', $form.attr('action'));
                
                // CRITICAL: Ensure form action is set before button click submits
                if ($form.length > 0) {
                    var currentAction = $form.attr('action');
                    if (!currentAction || currentAction === '' || currentAction === '#') {
                        var currentUrl = window.location.href;
                        $form.attr('action', currentUrl);
                        console.log('PP: ⚠️ Form action was empty on button click! Fixed to:', currentUrl);
                    }
                    
                    // CRITICAL: Also ensure the form has the correct method
                    if ($form.attr('method') !== 'post') {
                        $form.attr('method', 'post');
                        console.log('PP: ⚠️ Form method was not POST! Fixed to POST');
                    }
                    
                    // CRITICAL: Ensure nonce field exists (WooCommerce requires this)
                    if ($form.find('input[name="_wpnonce"]').length === 0) {
                        console.error('PP: ⚠️ WARNING: No nonce field found! This might prevent form submission.');
                    } else {
                        console.log('PP: ✅ Nonce field found');
                    }
                    
                    // Verify form will submit
                    console.log('PP: Final form action:', $form.attr('action'));
                    console.log('PP: Final form method:', $form.attr('method'));
                    console.log('PP: Form will submit in 100ms...');
                    
                    // Force form submission if it doesn't happen automatically
                    setTimeout(function() {
                        if ($form.length > 0 && $form.attr('action')) {
                            console.log('PP: 🔄 Ensuring form submission...');
                            // Don't actually force submit - let WooCommerce handle it
                            // But log that we're ready
                        }
                    }, 100);
                } else {
                    console.error('PP: ❌❌❌ NO FORM FOUND! This is a critical error!');
                }
                
                // Don't prevent default - let button submit form normally
            });
            
            // Method 3: Listen for WooCommerce AJAX submission (if it uses AJAX)
            $(document).ajaxComplete(function(event, xhr, settings) {
                if (settings.url && settings.url.indexOf('wc-settings') !== -1) {
                    console.log('PP: ✅✅✅ WOOCOMMERCE AJAX REQUEST DETECTED! ✅✅✅');
                    console.log('PP: AJAX URL:', settings.url);
                    console.log('PP: AJAX Method:', settings.type);
                }
            });
            
            // Also check for any JavaScript errors that might prevent submission
            window.addEventListener('error', function(e) {
                console.error('PP: ❌ JavaScript Error detected:', e.message, e.filename, e.lineno);
            });
            
            // DIAGNOSTIC: Check form structure after page load
            setTimeout(function() {
                var $form = $('form');
                console.log('PP: 🔍 FORM DIAGNOSTICS:');
                console.log('PP: - Form exists:', $form.length > 0);
                if ($form.length > 0) {
                    console.log('PP: - Form action:', $form.attr('action'));
                    console.log('PP: - Form method:', $form.attr('method'));
                    console.log('PP: - Form ID:', $form.attr('id'));
                    console.log('PP: - Form class:', $form.attr('class'));
                    console.log('PP: - Has nonce:', $form.find('input[name="_wpnonce"]').length > 0);
                    console.log('PP: - Has merchant_id field:', $('#woocommerce_sp_merchant_id').length > 0);
                    console.log('PP: - Has api_key field:', $('#woocommerce_sp_api_key').length > 0);
                    console.log('PP: - Has enabled checkbox:', $('#woocommerce_sp_enabled').length > 0);
                    console.log('PP: - Has save button:', $('button[name="save"], input[name="save"]').length > 0);
                }
            }, 1000);
        });
        </script>
        <?php
    }
    

    /**
     * Initialize form fields
     */
    public function init_form_fields() {
        // Only log on checkout, not in admin (reduces log noise)
        if (is_checkout()) {
        error_log('PP Gateway: init_form_fields() called');
        }
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'stablecoin-pay'),
                'type' => 'checkbox',
                'label' => $this->get_enable_label(),
                'default' => 'no'
            ),
            'merchant_id' => array(
                'title' => __('Merchant ID', 'stablecoin-pay'),
                'type' => 'text',
                'description' => $this->get_credentials_source_description(),
                'default' => '',
                'placeholder' => 'e.g., 12345678-abcd-1234-abcd-123456789abc',
            ),
            'api_key' => array(
                'title' => __('API Key', 'stablecoin-pay'),
                'type' => 'password',
                'description' => $this->get_credentials_source_description(),
                'default' => '',
            ),
            'webhook_url' => array(
                // Rendered as plain text, not an input: nothing here is editable and
                // there is nothing to copy any more, so a form field would just
                // invite people to change a value that is derived from the site.
                'title' => __('Webhook URL', 'stablecoin-pay'),
                'type' => 'sp_readonly_url',
                'description' => $this->get_webhook_destination_description(),
            ),
            // No Store Domain row here on purpose: the domain and the instruction
            // for submitting it already appear in Step 2 of the setup box above,
            // and repeating them as a settings field just says the same thing twice.
            
        );
    }
    
    /**
     * Get API base URL. Partner builds resolve it from the config file's environment_id.
     *
     * This host decides more than where requests go: the API resolves app.buyurl from the Host
     * it was called on, so calling api.syncharge.com hands back a buy.paymentservers.com
     * checkout URL no matter what this build is branded as. It must come from the config file,
     * not from the cached branding option, which describes whatever build ran here previously.
     */
    public function get_api_base_url() {
        if (class_exists('SP_Whitelabel_Branding')) {
            // 1. Explicit pin (test/staging builds)
            $override = SP_Whitelabel_Branding::get_api_base_url_override();
            if ($override) {
                return $override;
            }
            // 2. Partner build: environment_id from sp-whitelabel-config.php
            $config_env_id = SP_Whitelabel_Branding::get_whitelabel_env_id_from_config();
            if (!empty($config_env_id)) {
                return 'https://api.' . $config_env_id . '/v1';
            }
        }
        // 3. Non-partner build: the default host.
        return 'https://api.coinsub.io/v1';
    }

    /**
     * Get asset base URL (for logos, favicons, etc.). Same resolution order as get_api_base_url().
     */
    public function get_asset_base_url() {
        if (class_exists('SP_Whitelabel_Branding')) {
            $override = SP_Whitelabel_Branding::get_app_base_url_override();
            if ($override) {
                return $override;
            }
            $config_env_id = SP_Whitelabel_Branding::get_whitelabel_env_id_from_config();
            if (!empty($config_env_id)) {
                return 'https://app.' . $config_env_id;
            }
        }
        return 'https://app.coinsub.io';
    }
    
    /**
     * Load whitelabel branding
     * 
     * CRITICAL: This method ONLY affects checkout (frontend), NOT admin!
     * Admin/settings page always shows "Stablecoin Pay" regardless of whitelabel.
     * 
     * @param bool $force_refresh If true, force API call to refresh branding. If false, use cache only.
     */
    private function load_whitelabel_branding($force_refresh = false) {
        // Config-only. Branding used to be fetched from the API and cached in an
        // option; that lookup is gone, so there is nothing to refresh and nothing
        // that can disagree with the build. $force_refresh is kept only so existing
        // call sites stay valid.
        unset($force_refresh);

        $company_name = class_exists('SP_Whitelabel_Branding')
            ? SP_Whitelabel_Branding::get_whitelabel_plugin_name_from_config()
            : null;
        $logo_url = class_exists('SP_Whitelabel_Branding')
            ? SP_Whitelabel_Branding::get_whitelabel_logo_url_from_config()
            : null;

        if (empty($company_name)) {
            $company_name = 'Stablecoin Pay';
        }

        $this->brand_company        = $company_name;
        $this->checkout_title       = 'Pay with ' . $company_name;
        $this->checkout_icon        = $logo_url ? $logo_url : '';
        $this->button_logo_url      = $logo_url ? $logo_url : '';
        $this->button_company_name  = $company_name;

        if (is_checkout()) {
            error_log('PP Whitelabel: checkout branding from config - "' . $this->checkout_title . '"');
        }
    }
    
    /**
     * Backup method: Try to catch form submission via admin_init hook
     * This is a fallback in case process_admin_options() isn't being called
     */
    public function maybe_process_admin_options() {
        // Only run on WooCommerce settings page for this gateway
        if (!isset($_GET['page']) || $_GET['page'] !== 'wc-settings') {
            return;
        }
        if (!isset($_GET['tab']) || $_GET['tab'] !== 'checkout') {
            return;
        }
        if (!isset($_GET['section']) || $_GET['section'] !== $this->id) {
            return;
        }
        
        // Check if form was submitted (save button clicked)
        if (isset($_POST['save']) && isset($_POST['woocommerce_' . $this->id . '_enabled'])) {
            error_log('PP Whitelabel: 🔔🔔🔔 maybe_process_admin_options() DETECTED FORM SUBMISSION! 🔔🔔🔔');
            error_log('PP Whitelabel: POST data keys: ' . implode(', ', array_keys($_POST)));
            error_log('PP Whitelabel: Merchant ID in POST: ' . (isset($_POST['woocommerce_sp_merchant_id']) ? 'YES - Value: ' . substr($_POST['woocommerce_sp_merchant_id'], 0, 20) . '...' : 'NO'));
            error_log('PP Whitelabel: API Key in POST: ' . (isset($_POST['woocommerce_sp_api_key']) ? 'YES - Length: ' . strlen($_POST['woocommerce_sp_api_key']) : 'NO'));
            
            // CRITICAL FIX: WooCommerce's process_admin_options() isn't being called automatically
            // So we need to call it manually as a backup to ensure settings are saved
            error_log('PP Whitelabel: ⚠️ WooCommerce process_admin_options() not called automatically - calling manually as backup...');
            $this->process_admin_options();
            error_log('PP Whitelabel: ✅ process_admin_options() called manually - settings should now be saved');
        }
    }
    
    /**
     * Update API client settings when gateway settings are saved
     * This is called by the hook: woocommerce_update_options_payment_gateways_sp
     * This hook fires AFTER WooCommerce has saved the settings to the database
     * 
     * NOTE: This is also called directly from process_admin_options() to ensure it runs
     * We use a static flag to prevent duplicate execution
     */
    public function update_api_client_settings() {
        // Prevent duplicate execution (could be called from hook AND process_admin_options)
        static $executed = false;
        if ($executed) {
            error_log('PP Whitelabel: ⚠️ update_api_client_settings() already executed, skipping duplicate call');
            return;
        }
        $executed = true;
        
        error_log('═══════════════════════════════════════════════════════════');
        error_log('PP Whitelabel: 🔔🔔🔔 SETTINGS SAVE DETECTED! 🔔🔔🔔');
        error_log('PP Whitelabel: update_api_client_settings() CALLED');
        error_log('═══════════════════════════════════════════════════════════');
        
        // Reload settings from database (they were just saved by WooCommerce)
        $this->init_settings();
        
        $merchant_id = $this->get_option('merchant_id', '');
        $api_key = $this->get_option('api_key', '');
        $api_base_url = $this->get_api_base_url();
        
        error_log('PP Whitelabel: 📝 Settings - Merchant ID: ' . (empty($merchant_id) ? 'EMPTY' : substr($merchant_id, 0, 20) . '...'));
        error_log('PP Whitelabel: 📝 Settings - API Key: ' . (strlen($api_key) > 0 ? substr($api_key, 0, 10) . '...' : 'EMPTY'));
        error_log('PP Whitelabel: 📝 Settings - API Base URL: ' . $api_base_url);
        error_log('═══════════════════════════════════════════════════════════');
        
        // Update API client if we have credentials
        if (!empty($merchant_id) && !empty($api_key)) {
            $this->api_client->update_settings($api_base_url, $merchant_id, $api_key);
            error_log('PP Whitelabel: ✅ API client updated with credentials');
        } else {
            // No credentials - skip everything
            error_log('PP Whitelabel: ⚠️ Skipping - no credentials');
            return;
        }
    }


    /**
     * Override process_admin_options to ensure our method is called
     * This is called automatically by WooCommerce when settings are saved
     */
    public function process_admin_options() {
        // Prevent duplicate execution (could be called from WooCommerce AND maybe_process_admin_options)
        static $executed = false;
        if ($executed) {
            error_log('PP Whitelabel: ⚠️ process_admin_options() already executed, skipping duplicate call');
            return parent::process_admin_options(); // Still call parent to save, but skip our custom logic
        }
        $executed = true;
        
        error_log('═══════════════════════════════════════════════════════════');
        error_log('PP Whitelabel: 🔔🔔🔔 process_admin_options() CALLED - Settings are being saved! 🔔🔔🔔');
        error_log('═══════════════════════════════════════════════════════════');
        error_log('PP Whitelabel: POST data keys: ' . implode(', ', array_keys($_POST)));
        
        // Log POST data for merchant_id, api_key
        if (isset($_POST['woocommerce_sp_merchant_id'])) {
            $merchant_id_preview = substr($_POST['woocommerce_sp_merchant_id'], 0, 20);
            error_log('PP Whitelabel: 📝 POST merchant_id: ' . $merchant_id_preview . '... (length: ' . strlen($_POST['woocommerce_sp_merchant_id']) . ')');
        } else {
            error_log('PP Whitelabel: ⚠️ POST merchant_id NOT SET');
        }
        
        if (isset($_POST['woocommerce_sp_api_key'])) {
            $api_key_length = strlen($_POST['woocommerce_sp_api_key']);
            error_log('PP Whitelabel: 📝 POST api_key: ' . ($api_key_length > 0 ? substr($_POST['woocommerce_sp_api_key'], 0, 10) . '... (length: ' . $api_key_length . ')' : 'EMPTY'));
        } else {
            error_log('PP Whitelabel: ⚠️ POST api_key NOT SET - This is normal for password fields if unchanged');
        }
        
        
        // IMPORTANT: For password fields, WooCommerce only sends them in POST if they're changed
        // If api_key is not in POST, we need to preserve the existing value
        $existing_api_key = $this->get_option('api_key', '');
        if (!isset($_POST['woocommerce_sp_api_key']) && !empty($existing_api_key)) {
            // Password field not in POST means user didn't change it - preserve existing value
            $_POST['woocommerce_sp_api_key'] = $existing_api_key;
            error_log('PP Whitelabel: 🔒 Preserving existing API key (password field unchanged)');
        }
        
        // Call parent to save settings first
        $result = parent::process_admin_options();
        
        error_log('PP Whitelabel: 🔔 Parent process_admin_options() returned. Result: ' . ($result ? 'SUCCESS (true)' : 'FAILED (false)'));
        
        // Verify settings were saved
        $saved_merchant_id = $this->get_option('merchant_id', '');
        $saved_api_key = $this->get_option('api_key', '');
        error_log('PP Whitelabel: ✅ Saved merchant_id: ' . (empty($saved_merchant_id) ? 'EMPTY' : substr($saved_merchant_id, 0, 20) . '... (length: ' . strlen($saved_merchant_id) . ')'));
        error_log('PP Whitelabel: ✅ Saved api_key: ' . (empty($saved_api_key) ? 'EMPTY' : substr($saved_api_key, 0, 10) . '... (length: ' . strlen($saved_api_key) . ')'));
        error_log('═══════════════════════════════════════════════════════════');
        
        // Now fetch branding (if we have credentials)
        // Wrap in try-catch to prevent fatal errors from breaking the save process
        if (!empty($saved_merchant_id) && !empty($saved_api_key)) {
            try {
                error_log('PP Whitelabel: 🔔 Calling update_api_client_settings() to fetch branding...');
                $this->update_api_client_settings();
            } catch (Exception $e) {
                error_log('PP Whitelabel: ❌ ERROR fetching branding: ' . $e->getMessage());
                error_log('PP Whitelabel: ❌ Stack trace: ' . $e->getTraceAsString());
                // Don't break the save process - settings were saved successfully
            } catch (Error $e) {
                error_log('PP Whitelabel: ❌ FATAL ERROR fetching branding: ' . $e->getMessage());
                error_log('PP Whitelabel: ❌ Stack trace: ' . $e->getTraceAsString());
                // Don't break the save process - settings were saved successfully
            }
        } else {
            error_log('PP Whitelabel: ⚠️ Skipping branding fetch - no credentials AND no payment provider name');
        }

        // Register this site's webhook with the API so the merchant never has to
        // create one by hand. Idempotent: saving twice reuses the existing record
        // rather than creating a second. Failures are recorded for an admin notice
        // and deliberately do not affect $result - a webhook problem must never
        // stop settings saving or block checkout.
        if (class_exists('SP_Webhook_Provisioner')) {
            SP_Webhook_Provisioner::sync();
        }

        error_log('═══════════════════════════════════════════════════════════');

        return $result;
    }
    
    /**
     * Declare HPOS compatibility
     */
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', SP_PLUGIN_FILE, true);
        }
    }

    /**
     * Process the payment and return the result
     */
    public function process_payment($order_id) {
        error_log('PP Gateway: process_payment() called for order #' . $order_id);
        error_log('PP Gateway: Payment method: ' . ($_POST['payment_method'] ?? 'none'));
        error_log('PP Gateway: Order total: $' . wc_get_order($order_id)->get_total());
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            error_log('PP Gateway: Order not found: ' . $order_id);
            return array(
                'result' => 'failure',
                'messages' => __('Order not found', 'stablecoin-pay')
            );
        }
        
        error_log('PP Gateway: Order found. Starting payment process...');
        
        try {
            // Always recompute cart totals at process-time. The session
            // cache (`sp_cart_data`) is populated by
            // `WC_SP_Cart_Sync` which hooks `woocommerce_add_to_cart`
            // and `woocommerce_checkout_update_order_review`. The first
            // captures totals *before* shipping is chosen; the second only
            // fires on classic checkout. Block checkout updates the cart
            // via the Store API which doesn't trigger our sync hooks, so
            // the cached value can be wildly stale (we've seen $0.12 sent
            // to the provider for a $64+ order because of this).
            //
            // The live WC()->cart at this point has been fully calculated
            // by the time the AJAX handler reaches us (products + shipping
            // + tax + fees + coupons), so it is the source of truth.
            $cart_data = $this->calculate_cart_totals();

            // Persist the fresh values so anything reading the cache later
            // (refund flows, debug tools, etc.) sees consistent numbers.
            if (function_exists('WC') && WC()->session) {
                WC()->session->set('sp_cart_data', $cart_data);
            }

            // Resolve the authoritative total in this priority order:
            //
            //   1. `_sp_displayed_total` — the exact figure the
            //      block-checkout React component rendered to the customer
            //      and posted along with the order. Whatever was on screen
            //      MUST be what we charge.
            //   2. `$order->get_total()` — the WC order's saved total.
            //      We've already attached shipping/fees/coupons to the
            //      order in `sp_ajax_process_payment`, so this is
            //      usually correct for the classic flow too.
            //   3. The freshly-recomputed cart total.
            //
            // The order of preference matters: if the React side reports
            // a different number than the server-side cart, the React
            // number wins because that's what the customer saw and agreed
            // to. Anything else is a recipe for under/over-charging.
            $displayed_total = (float) $order->get_meta('_sp_displayed_total');
            $order_total     = (float) $order->get_total();
            $cart_total      = (float) $cart_data['total'];
            $authoritative   = $cart_total;
            $source          = 'cart';

            if ($displayed_total > 0) {
                $authoritative = $displayed_total;
                $source        = 'frontend';
            } elseif ($order_total > $cart_total && $order_total > 0) {
                $authoritative = $order_total;
                $source        = 'order';
            }

            error_log('PP Gateway: Resolved GRAND TOTAL = $' . number_format($authoritative, 2)
                . ' (source: ' . $source . ')'
                . ' [frontend=$' . number_format($displayed_total, 2)
                . ', order=$' . number_format($order_total, 2)
                . ', cart=$' . number_format($cart_total, 2) . ']');

            if (abs($authoritative - $cart_total) > 0.005) {
                $cart_data['total']    = $authoritative;
                $cart_data['subtotal'] = (float) $order->get_subtotal();
                $cart_data['shipping'] = (float) $order->get_shipping_total();
                $cart_data['tax']      = (float) $order->get_total_tax();
                $cart_data['discount'] = (float) $order->get_discount_total();
                $order_fee_total   = 0.0;
                $order_fee_details = array();
                foreach ($order->get_fees() as $fee_item) {
                    $fee_amount = (float) $fee_item->get_total();
                    $order_fee_total += $fee_amount;
                    $order_fee_details[] = array(
                        'name'      => $fee_item->get_name(),
                        'amount'    => $fee_amount,
                        'taxable'   => $fee_item->get_tax_class() !== '0' && $fee_item->get_tax_class() !== 0,
                        'tax_class' => $fee_item->get_tax_class(),
                    );
                }
                $cart_data['fees']        = $order_fee_total;
                $cart_data['fee_details'] = $order_fee_details;
            }

            error_log('PP Gateway: Live cart totals → subtotal $' . $cart_data['subtotal'] . ' + shipping $' . $cart_data['shipping'] . ' + tax $' . $cart_data['tax'] . ' = total $' . $cart_data['total'] . ' ' . $cart_data['currency'] . ', subscription=' . ($cart_data['has_subscription'] ? 'YES' : 'NO'));
            
            // Ensure API client is using production settings
            $api_base_url = $this->get_api_base_url();
            $merchant_id = $this->get_option('merchant_id', '');
            $api_key = $this->get_option('api_key', '');
            $this->api_client->update_settings($api_base_url, $merchant_id, $api_key);
            error_log('PP Gateway: API client updated (production), Base URL: ' . $api_base_url);
            
            // Create purchase session directly with cart totals
            $session_start_time = microtime(true);
            error_log('PP Gateway: Creating purchase session at ' . date('H:i:s'));
            
            $purchase_session_data = $this->prepare_purchase_session_from_cart($order, $cart_data);
            
            $purchase_session = $this->api_client->create_purchase_session($purchase_session_data);
            
            $session_end_time = microtime(true);
            $session_duration = round($session_end_time - $session_start_time, 2);
            error_log('PP Gateway: Purchase session creation took ' . $session_duration . ' seconds');
            
            // Check for errors BEFORE trying to access as array
            if (is_wp_error($purchase_session)) {
                error_log('PP Gateway: Purchase session failed: ' . $purchase_session->get_error_message());
                throw new Exception($purchase_session->get_error_message());
            }
            
            error_log('PP Gateway: Purchase session created: ' . ($purchase_session['purchase_session_id'] ?? 'unknown'));
            
            // Get checkout URL from purchase session
            $checkout_url = isset($purchase_session['checkout_url']) ? $purchase_session['checkout_url'] : '';
            
            if (empty($checkout_url)) {
                error_log('PP Gateway: CRITICAL: Checkout URL is empty in purchase session response. Data: ' . json_encode($purchase_session));
                throw new Exception('Checkout URL not received from API');
            }
            
            error_log('PP Gateway: Checkout URL from API: ' . $checkout_url);
            
            // Decide which host the customer's checkout page should live on. The hosted buy app
            // brands itself purely from the Host it is served on, so this line decides which
            // company the customer sees — get it wrong and they land on another partner's page.
            //
            // Order matters:
            //   1. buy_base_url from config  - test/staging builds, pinned explicitly
            //   2. environment_id from config - partner builds. Derived from the config rather
            //      than the cached branding option, which is a snapshot of whatever the API last
            //      returned and may still describe a previous build's partner. Using environment_id
            //      also keeps the real TLD (buy.syncharge.io), unlike the .com assumption below.
            $branding_available = class_exists('SP_Whitelabel_Branding');
            $buyurl = $branding_available ? SP_Whitelabel_Branding::get_buy_base_url_override() : null;
            if (empty($buyurl) && $branding_available) {
                $config_env_id = SP_Whitelabel_Branding::get_whitelabel_env_id_from_config();
                if (!empty($config_env_id)) {
                    $buyurl = 'https://buy.' . $config_env_id;
                }
            }
            if (!empty($buyurl)) {
                $buyurl_parts = parse_url($buyurl);
                if ($buyurl_parts && isset($buyurl_parts['scheme'], $buyurl_parts['host'])) {
                    $whitelabel_domain = $buyurl_parts['scheme'] . '://' . $buyurl_parts['host'];
                    $checkout_url_parts = parse_url($checkout_url);
                    if ($checkout_url_parts && isset($checkout_url_parts['scheme'], $checkout_url_parts['host'])) {
                        $original_domain = $checkout_url_parts['scheme'] . '://' . $checkout_url_parts['host'];
                        $checkout_url = str_replace($original_domain, $whitelabel_domain, $checkout_url);
                        error_log('PP Gateway: Whitelabel checkout URL: ' . $checkout_url);
                    }
                }
            }
            
            error_log('PP Gateway: FINAL CHECKOUT URL: ' . $checkout_url);
            
            // Store Stablecoin Pay data in order meta
            $order->update_meta_data('_sp_purchase_session_id', $purchase_session['purchase_session_id']);
            $order->update_meta_data('_sp_checkout_url', $checkout_url);
            $order->update_meta_data('_sp_merchant_id', $this->get_option('merchant_id'));
            
            error_log('PP Gateway: Stored purchase session ID and checkout URL in order meta');
            
            // Store subscription data if applicable
            if ($cart_data['has_subscription']) {
                $order->update_meta_data('_sp_is_subscription', 'yes');
                $order->update_meta_data('_sp_subscription_data', $cart_data['subscription_data']);
            } else {
                $order->update_meta_data('_sp_is_subscription', 'no');
            }
            
            // Store cart items in order meta
            $order->update_meta_data('_sp_cart_items', $cart_data['items']);
            $order->save();
            
            // Verify it was stored
            $stored_url = $order->get_meta('_sp_checkout_url');
            if ($stored_url !== $checkout_url) {
                error_log('PP Gateway: WARNING: Checkout URL mismatch in order meta');
            } else {
                error_log('PP Gateway: Checkout URL stored in order meta');
            }
            
          
            $order->add_order_note(__('Awaiting crypto payment. Customer is completing payment in the hosted checkout.', 'stablecoin-pay'));
            
            // Store order ID in session (used for tracking, not cart restoration)
            // Note: We intentionally DON'T restore cart on return - fresh checkout each time
            WC()->session->set('sp_pending_order_id', $order->get_id());
            
            // Store checkout URL in session to avoid long URLs (use order ID as key)
            WC()->session->set('sp_checkout_url_' . $order->get_id(), $checkout_url);
            error_log('PP Gateway: Stored checkout URL in session');
            
            // Verify session storage
            $session_url = WC()->session->get('sp_checkout_url_' . $order->get_id());
            if ($session_url !== $checkout_url) {
                error_log('PP Gateway: WARNING: Checkout URL mismatch in session');
            } else {
                error_log('PP Gateway: Checkout URL stored in session');
            }
            
            // Keep cart so if user returns without paying they still have their items and can restart payment (new order).
            // We do NOT empty the cart; the pending order is kept for tracking but payment always restarts with a fresh order.
            
            error_log('PP Gateway: Payment process complete');

            // Redirect browser directly to the payment provider's hosted checkout.
            // The payment provider handles the success_url redirect natively at the top-level
            // window, so the customer is returned to /checkout/order-received/ after paying.
            // (Avoids cross-origin iframe limitations that prevented the parent from navigating.)
            error_log('PP Gateway: Redirecting directly to hosted checkout URL');
            return array(
                'result' => 'success',
                'redirect' => $checkout_url,
                'sp_checkout_url' => $checkout_url
            );
            
        } catch (Exception $e) {
            error_log('PP Gateway: Payment error: ' . $e->getMessage());
            wc_add_notice(__('Payment error: ', 'stablecoin-pay') . $e->getMessage(), 'error');
            return array(
                'result' => 'failure',
                'messages' => $e->getMessage()
            );
        }
    }
    
    /**
     * Ensure products exist in Stablecoin Pay
     */
    private function ensure_products_exist($order) {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            
            // Check if we already have a Stablecoin Pay product ID for this WooCommerce product
            $existing_sp_id = $order->get_meta('_sp_product_' . $product->get_id());
            
            if ($existing_sp_id) {
                continue; // Already exists
            }
            
            // Create product in Stablecoin Pay
            $product_data = array(
                'name' => $product->get_name(),
                'description' => $product->get_description() ?: $product->get_short_description(),
                'price' => (float) $product->get_price(),
                'currency' => get_woocommerce_currency(),
                'sku' => $product->get_sku(),
                'metadata' => array(
                    'woocommerce_product_id' => $product->get_id(),
                    'product_type' => $product->get_type(),
                    'source' => 'woocommerce_plugin'
                )
            );
            
            $sp_product = $this->api_client->create_product($product_data);
            
            if (!is_wp_error($sp_product)) {
                // Store the Stablecoin Pay product ID in order meta for future reference
                $order->update_meta_data('_sp_product_' . $product->get_id(), $sp_product['id']);
                $order->save();
            }
        }
    }
    
    // REMOVED: prepare_order_data - using WooCommerce-only approach
    
    /**
     * Prepare purchase session data
     */
    private function prepare_purchase_session_data($order, $sp_order) {
        // Check if this is a subscription order
        $is_subscription = false;
        $subscription_data = null;
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->get_meta('_sp_subscription') === 'yes') {
                $is_subscription = true;
                $subscription_data = array(
                    'frequency' => $product->get_meta('_sp_frequency'),
                    'interval' => $product->get_meta('_sp_interval'),
                    'duration' => $product->get_meta('_sp_duration')
                );
                error_log('🔄 SUBSCRIPTION ORDER DETECTED!');
                error_log('  Frequency: ' . $subscription_data['frequency']);
                error_log('  Interval: ' . $subscription_data['interval']);
                error_log('  Duration: ' . $subscription_data['duration']);
                break;
            }
        }
        
        if (!$is_subscription) {
            error_log('📦 Regular order (not subscription)');
        }
        
        // Prepare product information
        $product_names = array();
        $product_details = array();
        $total_items = 0;
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            
            $item_name = $item->get_name();
            $quantity = $item->get_quantity();
            $total_items += $quantity;
            
            $product_names[] = $item_name;
            
            // Get Stablecoin Pay product ID from order meta if available
            $sp_product_id = $order->get_meta('_sp_product_' . $product->get_id());
            
            $product_details[] = array(
                'woocommerce_product_id' => $product->get_id(),
                'sp_product_id' => $sp_product_id ?: null,
                'name' => $item_name,
                'price' => (float) $item->get_total() / $quantity, // Price per unit
                'quantity' => $quantity,
                'total' => (float) $item->get_total(),
                'sku' => $product->get_sku(),
                'type' => $product->get_type()
            );
        }
        
        // Create order name with product details
        $order_name = count($product_names) > 1 
            ? 'WooCommerce Order: ' . implode(' + ', array_slice($product_names, 0, 3)) . (count($product_names) > 3 ? ' + ' . (count($product_names) - 3) . ' more' : '')
            : 'WooCommerce Order: ' . ($product_names[0] ?? 'Payment');
        
        // Get order totals breakdown
        $subtotal = (float) $order->get_subtotal();
        $shipping_total = (float) $order->get_shipping_total();
        $tax_total = (float) $order->get_total_tax();
        $total_amount = (float) $order->get_total();
        
        // Build details string with breakdown
        $details_parts = ['Payment for WooCommerce order #' . $order->get_order_number() . ' with ' . count($product_details) . ' product(s)'];
        if ($shipping_total > 0) {
            $details_parts[] = 'Shipping: $' . number_format($shipping_total, 2);
        }
        if ($tax_total > 0) {
            $details_parts[] = 'Tax: $' . number_format($tax_total, 2);
        }
        $details_string = implode(' | ', $details_parts);
        
        $success_url = $this->get_return_url($order);
        error_log('PP Gateway: Success URL: ' . $success_url);
        
        $session_data = array(
            'name' => $order_name,
            'details' => $details_string,
            'currency' => $order->get_currency(),
            'amount' => $total_amount,
            'recurring' => $is_subscription,
            'metadata' => array(
                'payment_gateway' => 'stablecoin_pay', // Identifier for data/analytics purposes
                'payment_type' => 'stablecoin_pay', // Payment type identifier
                'woocommerce_order_id' => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'customer_email' => $order->get_billing_email(),
                'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'source' => 'woocommerce_plugin',
                'is_subscription' => $is_subscription,
                'individual_products' => $product_names,
                'product_count' => count($product_details),
                'total_items' => $total_items,
                'products' => $product_details,
                'currency' => $order->get_currency(),
                'order_breakdown' => array(
                    'subtotal' => $subtotal,
                    'shipping' => array(
                        'method' => $order->get_shipping_method(),
                        'cost' => $shipping_total
                    ),
                    'tax' => array(
                        'amount' => $tax_total
                    ),
                    'total' => $total_amount
                ),
                'subtotal_amount' => $subtotal,
                'shipping_cost' => $shipping_total,
                'tax_amount' => $tax_total,
                'total_amount' => $total_amount,
                'billing_address' => array(
                    'first_name' => $order->get_billing_first_name(),
                    'last_name' => $order->get_billing_last_name(),
                    'company' => $order->get_billing_company(),
                    'address_1' => $order->get_billing_address_1(),
                    'address_2' => $order->get_billing_address_2(),
                    'city' => $order->get_billing_city(),
                    'state' => $order->get_billing_state(),
                    'postcode' => $order->get_billing_postcode(),
                    'country' => $order->get_billing_country(),
                    'email' => $order->get_billing_email(),
                    'phone' => $order->get_billing_phone()
                ),
                'shipping_address' => array(
                    'first_name' => $order->get_shipping_first_name(),
                    'last_name' => $order->get_shipping_last_name(),
                    'company' => $order->get_shipping_company(),
                    'address_1' => $order->get_shipping_address_1(),
                    'address_2' => $order->get_shipping_address_2(),
                    'city' => $order->get_shipping_city(),
                    'state' => $order->get_shipping_state(),
                    'postcode' => $order->get_shipping_postcode(),
                    'country' => $order->get_shipping_country()
                )
            ),
            'success_url' => $this->get_return_url($order), // Return to order received page after payment
            'cancel_url' => $this->get_return_url($order), // Return to order received page if cancelled
            'failure_url' => $this->get_return_url($order) // Return to order received page if failed
        );
        
        // Add subscription data if this is a subscription
        if ($is_subscription && $subscription_data) {
            error_log('🔍 Raw subscription data from product:');
            error_log('  Frequency: ' . var_export($subscription_data['frequency'], true));
            error_log('  Interval: ' . var_export($subscription_data['interval'], true));
            error_log('  Duration: ' . var_export($subscription_data['duration'], true));
            
            // Map interval number to capitalized string (matching Go API)
            $interval_map = array(
                '0' => 'Day', 0 => 'Day',
                '1' => 'Week', 1 => 'Week',
                '2' => 'Month', 2 => 'Month',
                '3' => 'Year', 3 => 'Year'
            );
            
            $interval_value = $subscription_data['interval'];
            
            // Don't default - let it error if interval is invalid
            if (!isset($interval_map[$interval_value])) {
                error_log('❌ Invalid interval value: ' . var_export($interval_value, true));
                throw new Exception('Invalid subscription interval. Please check product settings.');
            }
            
            $session_data['interval'] = $interval_map[$interval_value];
            $session_data['frequency'] = (string) $subscription_data['frequency'];
            $session_data['duration'] = (string) ($subscription_data['duration'] ?: '0');
            
            error_log('✅ Mapped subscription fields:');
            error_log('  interval: ' . $session_data['interval']);
            error_log('  frequency: ' . $session_data['frequency']);
            error_log('  duration: ' . $session_data['duration']);
            
            // Mark in metadata for tracking
            $session_data['metadata']['is_subscription'] = true;
            $session_data['metadata']['subscription_settings'] = $subscription_data;
        }
        
        return $session_data;
    }
    
    /**
     * Add checkout script to automatically open Stablecoin Pay checkout in new tab
     */
    public function add_checkout_script() {
        // Check if we're on the order received page
        if (!is_wc_endpoint_url('order-received')) {
            return;
        }
        
        // Get order ID from URL
        global $wp;
        $order_id = absint($wp->query_vars['order-received']);
        
        if (!$order_id) {
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Check if this is a Stablecoin Pay order with pending redirect
        $checkout_url = $order->get_meta('_sp_pending_redirect');
        
        if (!empty($checkout_url)) {
            // Delete the meta to prevent duplicate redirects
            $order->delete_meta_data('_sp_pending_redirect');
            $order->save();
            
            ?>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Open Stablecoin Pay checkout in new tab
                var spWindow = window.open('<?php echo esc_js($checkout_url); ?>', '_blank');
                
                // Show notice to user
                $('body').prepend('<div id="sp-checkout-notice" style="position: fixed; top: 20px; right: 20px; background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); color: white; padding: 20px; border-radius: 8px; z-index: 9999; box-shadow: 0 4px 20px rgba(0,0,0,0.3); max-width: 350px;"><strong style="font-size: 16px;">🚀 Complete Your Payment</strong><br><br>A new tab has opened with your payment checkout.<br><br><small>Your order will be confirmed once payment is received.</small><br><br><button onclick="window.open(\'<?php echo esc_js($checkout_url); ?>\', \'_blank\')" style="background: white; color: #1e3a8a; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; margin-top: 10px; font-weight: bold;">Reopen Payment Page</button></div>');
                
                // Remove notice after 30 seconds
                setTimeout(function() {
                    $('#sp-checkout-notice').fadeOut();
                }, 30000);
            });
            </script>
            <?php
        }
    }
    
    /**
     * Display payment fields with modal checkout
     */
    public function payment_fields() {
        echo '<div id="sp-payment-description">';
        echo '<p>' . __('Pay securely with cryptocurrency.', 'stablecoin-pay') . '</p>';
        echo '</div>';
        
        // Initialize empty checkout URL for the template
        $checkout_url = '';
        
        // Get Stablecoin Pay button text for JavaScript
        $sp_button_text = $this->get_order_button_text();
        
        // Include the modal template
        include plugin_dir_path(__FILE__) . 'sp-checkout-modal.php';
    }
    
    /**
     * Process refunds (Automatic API refund for single payments)
     */
    /**
     * Merchant signing limit, in the payment currency.
     *
     * Transfers above this are created but not broadcast until the merchant signs
     * them (or raises the limit) in their dashboard. The API answers 2xx either
     * way, so this is the difference between a refund that moves funds and one
     * that silently sits pending.
     *
     * @return float
     */
    public function get_refund_signing_limit() {
        return (float) apply_filters('sp_refund_signing_limit', 100.0);
    }

    /**
     * Extra balance needed on top of the refund amount to cover gas.
     *
     * @return float
     */
    public function get_refund_gas_headroom() {
        return (float) apply_filters('sp_refund_gas_headroom', 0.1);
    }

    /**
     * Merchant fee the transfer API charges to send the refund back out.
     *
     * Deducted from the same wallet balance as the refund and the gas, so it is
     * part of what the merchant must hold. The amount is not yet finalised, so the
     * default is 0 and the guidance says "plus the merchant fee" rather than
     * quoting a total that would be wrong. Set it via the filter once known:
     *
     *     add_filter('sp_refund_merchant_fee', function ($fee, $amount) {
     *         return round($amount * 0.01, 2);   // or a flat value
     *     }, 10, 2);
     *
     * @param float $amount
     * @return float
     */
    public function get_refund_merchant_fee($amount) {
        return (float) apply_filters('sp_refund_merchant_fee', 0.0, (float) $amount);
    }

    /**
     * Total the merchant wallet must hold for a refund of $amount to go through:
     * the refund itself, the gas headroom, and the merchant fee.
     *
     * @param float $amount
     * @return float
     */
    public function get_refund_required_balance($amount) {
        return round(
            (float) $amount + $this->get_refund_gas_headroom() + $this->get_refund_merchant_fee($amount),
            6
        );
    }

    /**
     * How much the wallet needs, phrased honestly about the unknown fee.
     *
     * @param float  $amount
     * @param string $token
     * @return string
     */
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

    /**
     * The one remedy available from here, for every failure path.
     *
     * Raising the signing limit in the merchant dashboard is currently the only
     * way to unblock a refund from WooCommerce - there is no API to lift it, and
     * no way to sign a parked transfer from this screen.
     *
     * NOTE: this whole refund flow is interim. Refunds are expected to move into
     * the merchant dashboard, at which point this can be removed rather than
     * extended.
     *
     * @return string
     */
    private function get_dashboard_limit_instruction() {
        $dashboard_url = $this->get_dashboard_url_from_config();

        $text = __('Go to your merchant dashboard and raise the signing limit on your wallet, then try the refund again. That is the only way to release a refund from here.', 'stablecoin-pay');

        if ($dashboard_url) {
            $host = wp_parse_url($dashboard_url, PHP_URL_HOST);
            $text .= ' ' . sprintf(
                /* translators: %s: linked dashboard hostname */
                __('Your dashboard is at %s.', 'stablecoin-pay'),
                '<a href="' . esc_url($dashboard_url) . '" target="_blank" rel="noopener">' . esc_html($host ?: $dashboard_url) . '</a>'
            );
        }

        return $text;
    }

    /**
     * Guidance shown when a refund cannot complete without merchant action.
     *
     * @param float  $amount
     * @param string $headline
     * @param string $action
     * @return string
     */
    private function build_refund_action_note($amount, $headline, $action) {
        $settings_url   = admin_url('admin.php?page=wc-settings&tab=checkout&section=sp');
        $settings_label = $this->get_title();
        $dashboard_url  = $this->get_dashboard_url_from_config();

        $note  = '<strong>' . $headline . '</strong><br><br>';
        $note .= $action . '<br><br>';

        if ($dashboard_url) {
            $note .= '<a href="' . esc_url($dashboard_url) . '" target="_blank" rel="noopener" class="button button-primary">'
                   . esc_html__('Open merchant dashboard', 'stablecoin-pay') . '</a> ';
        }
        $note .= '<a href="' . esc_url($settings_url) . '" class="button">'
               . sprintf(esc_html__('%s settings', 'stablecoin-pay'), esc_html($settings_label)) . '</a>';

        return $note;
    }

    public function process_refund($order_id, $amount = null, $reason = '') {
        error_log('PP Refund: process_refund called');
        error_log('PP Refund: Order ID: ' . $order_id);
        error_log('PP Refund: Amount parameter: ' . ($amount ?? 'NULL'));
        error_log('PP Refund: Reason: ' . $reason);
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            error_log('PP Refund: Order not found: ' . $order_id);
            return new WP_Error('invalid_order', __('Invalid order.', 'stablecoin-pay'));
        }
        
        error_log('PP Refund: Order total: ' . $order->get_total());
        error_log('PP Refund: Order status: ' . $order->get_status());
        error_log('PP Refund: Payment method: ' . $order->get_payment_method());
        
        // If amount is null or 0, use the order total
        if ($amount === null || $amount == 0) {
            $amount = $order->get_total();
            error_log('PP Refund: Using order total as refund amount: ' . $amount);
        }
        
        // Check if this is a subscription order (for logging only)
        $is_subscription = $order->get_meta('_sp_is_subscription') === 'yes';
        error_log('PP Refund: Is subscription: ' . ($is_subscription ? 'YES' : 'NO'));
        
        // Process automatic refund for ALL orders (including subscriptions) via API
        // IMPORTANT: All refunds are processed as USDC on Polygon for simplicity and wide acceptance
        // Get required payment details from order meta
        $customer_wallet = $order->get_meta('_customer_wallet_address');
        
        // Get customer email address for refund
        $customer_email = $order->get_billing_email();
        
        // Get agreement message data (stored from webhook) - for logging only
        $agreement_message_json = $order->get_meta('_sp_agreement_message');
        $agreement_message = $agreement_message_json ? json_decode($agreement_message_json, true) : null;
        
        error_log('PP Refund: Customer wallet: ' . ($customer_wallet ?: 'NOT FOUND'));
        error_log('PP Refund: Customer email: ' . ($customer_email ?: 'NOT FOUND'));
        error_log('PP Refund: Agreement message: ' . ($agreement_message_json ?: 'NOT FOUND'));
        
        // Debug: Show all order meta
        $all_meta = $order->get_meta_data();
        error_log('PP Refund: All order meta keys: ' . implode(', ', array_map(function($meta) { return $meta->key; }, $all_meta)));
        
        // Use customer email as to_address (preferred) or fallback to wallet address
        $to_address = $customer_email ?: $customer_wallet;
        
        // Validate required data for automatic refund
        if (empty($to_address)) {
            error_log('PP Refund: No customer email or wallet found, cannot process refund');
            
            // Fallback to manual refund for orders without customer data
            $refund_note = sprintf(
                __('AUTOMATIC REFUND FAILED - MANUAL REFUND REQUIRED: %s. Reason: %s. Customer email or wallet address not found. Please contact customer and process refund manually.', 'stablecoin-pay'),
                wc_price($amount),
                $reason
            );
            $order->add_order_note($refund_note);
            $order->update_status('refund-pending', __('Refund pending - manual processing required.', 'stablecoin-pay'));
            
            // Return error so WooCommerce doesn't mark as refunded
            return new WP_Error('missing_customer_data', __('Customer email or wallet address not found. Manual refund required.', 'stablecoin-pay'));
        }
        
        // Use the same chain and token from the original payment (stored from webhook)
        // Fallback to USDC if token not available (keep same chain)
        // Fallback to Polygon Mainnet with USDC if chain not available
        $chain_id = $order->get_meta('_sp_chain_id');
        $token_symbol = $order->get_meta('_sp_token_symbol');
        
        // Refund on the network the customer actually paid on. Only when the order
        // has no record of it do we fall back to the platform's current settlement
        // network, and that default is filterable because settlement moves (it was
        // USDC on Polygon, it is USDG on Ink) and hardcoding it here would silently
        // send refunds to the wrong chain after the next change.
        //
        //     add_filter('sp_refund_fallback_chain_id', fn() => '57073');
        //     add_filter('sp_refund_fallback_token', fn() => 'USDG');
        if (empty($chain_id)) {
            $chain_id = (string) apply_filters('sp_refund_fallback_chain_id', '137');
            error_log('PP Refund: Chain ID not recorded on the order, using fallback chain_id ' . $chain_id
                . ' - set the sp_refund_fallback_chain_id filter if settlement has moved');
        }

        if (empty($token_symbol)) {
            $token_symbol = (string) apply_filters('sp_refund_fallback_token', 'USDC');
            error_log('PP Refund: Token not recorded on the order, using fallback token ' . $token_symbol
                . ' on chain_id ' . $chain_id . ' - set the sp_refund_fallback_token filter if settlement has moved');
        }
        
        error_log('PP Refund: Using refund chain/token: ' . $token_symbol . ' on chain_id ' . $chain_id);
        
        error_log('PP Refund: Processing automatic refund for order #' . $order_id);
        error_log('PP Refund: Amount: ' . $amount);
        error_log('PP Refund: To Address (email/wallet): ' . $to_address);
        error_log('PP Refund: Chain ID: ' . $chain_id);
        error_log('PP Refund: Token: ' . $token_symbol);
        
        // Initialize API client with production settings
        $api_client = new SP_API_Client();
        $api_base_url = $this->get_api_base_url();
        $merchant_id = $this->get_option('merchant_id', '');
        $api_key = $this->get_option('api_key', '');
        
        // Ensure API client uses production
        $api_client->update_settings($api_base_url, $merchant_id, $api_key);
        error_log('PP Refund: API client initialized (production)');
        error_log('PP Refund: API Base URL: ' . $api_base_url);
        
        // Flag the two things that stop a transfer reaching the chain, before we
        // ask for it, so the log explains the outcome either way.
        $signing_limit = $this->get_refund_signing_limit();
        if ((float) $amount > $signing_limit) {
            error_log(sprintf(
                'PP Refund: ⚠️ amount %s exceeds the %s signing limit - the transfer will be created but will need signing before it broadcasts',
                $amount,
                $signing_limit
            ));
        }
        error_log(sprintf(
            'PP Refund: merchant wallet needs %s %s (refund %s + %s gas + %s merchant fee)',
            $this->get_refund_required_balance($amount),
            $token_symbol,
            $amount,
            $this->get_refund_gas_headroom(),
            $this->get_refund_merchant_fee($amount)
        ));

        error_log('PP Refund: About to call refund API...');
        
        // Call refund API using customer email or wallet address
        $refund_result = $api_client->refund_transfer_request(
            $to_address,
            $amount,
            $chain_id,
            $token_symbol
        );
        
        error_log('PP Refund: API call completed. Result: ' . (is_wp_error($refund_result) ? 'ERROR' : 'SUCCESS'));
        
        if (is_wp_error($refund_result)) {
            $error_message = $refund_result->get_error_message();
            error_log('PP Refund: API returned WP_Error: ' . $error_message);
            error_log('PP Refund: Error code: ' . $refund_result->get_error_code());
            error_log('PP Refund: Error data: ' . json_encode($refund_result->get_error_data()));
            
            // Check for insufficient funds error
            if (strpos(strtolower($error_message), 'insufficient') !== false || 
                strpos(strtolower($error_message), 'balance') !== false) {
                
                $insufficient_funds_note = sprintf(
                    __('REFUND FAILED - INSUFFICIENT FUNDS: %s. Reason: %s. Error: %s', 'stablecoin-pay'),
                    wc_price($amount),
                    $reason,
                    $error_message
                );
                
                $sp_settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=sp');
                
                // Gas AND the merchant fee come out of the same balance, so a wallet
                // holding exactly the refund amount can never send it.
                $insufficient_funds_note .= '<br><br><strong>🔧 Action Required - Top up your merchant wallet:</strong><br>';
                $insufficient_funds_note .= sprintf(
                    /* translators: %s: description of the balance required */
                    __('You need %s available. A wallet holding only the refund amount cannot send it.', 'stablecoin-pay'),
                    $this->describe_required_balance($amount, $token_symbol)
                ) . '<br><br>';
                $insufficient_funds_note .= $this->get_dashboard_limit_instruction() . '<br><br>';
                
                // Funding happens in the merchant dashboard, not here - this plugin
                // does not onramp.
                $settings_label = $this->get_title();
                $insufficient_funds_note .= '<strong>' . esc_html__('To add funds:', 'stablecoin-pay') . '</strong><br>';
                $insufficient_funds_note .= '1. ' . esc_html__('Top up your merchant wallet from your dashboard.', 'stablecoin-pay') . '<br>';
                $insufficient_funds_note .= '2. ' . esc_html__('Retry the refund once the balance has cleared.', 'stablecoin-pay') . '<br><br>';

                $insufficient_funds_note .= '<a href="' . esc_url($sp_settings_url) . '" class="button button-primary" style="background: #0284c7; border-color: #0284c7;">'
                    . sprintf(esc_html__('Go to %s settings', 'stablecoin-pay'), esc_html($settings_label)) . '</a>';
                
                $order->add_order_note($insufficient_funds_note);
                $order->update_status('refund-pending', __('Refund pending - insufficient funds. Top up your merchant wallet and retry.', 'stablecoin-pay'));
                
                error_log('PP Refund: Insufficient funds: ' . $error_message);
                return new WP_Error('insufficient_funds', $error_message);
            }
            
            // Other API errors. The signing limit is the usual culprit and the only
            // thing the merchant can act on from here, so always point at it.
            $refund_note = sprintf(
                __('REFUND FAILED: %s. Reason: %s. API Error: %s', 'stablecoin-pay'),
                wc_price($amount),
                $reason,
                $error_message
            );
            $refund_note .= '<br><br>' . $this->get_dashboard_limit_instruction();
            $refund_note .= '<br><br>' . sprintf(
                /* translators: %s: description of the balance required */
                __('Also check the wallet holds %s.', 'stablecoin-pay'),
                $this->describe_required_balance($amount, $token_symbol)
            );
            $order->add_order_note($refund_note);
            error_log('PP Refund: API Error: ' . $error_message);
            return $refund_result;
        }
        
        // Validate API response
        if (!is_array($refund_result) || empty($refund_result)) {
            error_log('PP Refund: API returned invalid response: ' . json_encode($refund_result));
            $refund_note = sprintf(
                __('REFUND FAILED: %s. Reason: %s. The API accepted the request but returned nothing we could read, so the transfer may or may not have been submitted. Check the transfer in your merchant dashboard BEFORE retrying - retrying a transfer that already went through would refund the customer twice.', 'stablecoin-pay'),
                wc_price($amount),
                $reason
            );
            $order->add_order_note($refund_note);
            $order->update_status('refund-pending', __('Refund pending - API error. Please retry.', 'stablecoin-pay'));
            return new WP_Error('invalid_api_response', __('API returned invalid response. Please try again.', 'stablecoin-pay'));
        }
        
        error_log('PP Refund: API response received: ' . json_encode($refund_result));
        error_log('PP Refund: Response shape: ' . SP_API_Client::describe_shape($refund_result));
        error_log('PP Refund: Reported transfer status: ' . (SP_API_Client::extract_transfer_status($refund_result) ?: '(none)'));

        // The request was accepted, but a transfer above the merchant's signing
        // limit is created without being broadcast. Nothing reaches the chain until
        // it is signed, and no `transfer` webhook will ever arrive, so treating this
        // as a completed refund is how a refund "succeeds" while doing nothing.
        if (SP_API_Client::transfer_awaits_signature($refund_result)) {
            $limit = $this->get_refund_signing_limit();

            $action = sprintf(
                /* translators: 1: refund amount, 2: signing limit */
                __('This transfer is %1$s, which is above the signing limit on your merchant wallet (default %2$s). It has been created but will NOT reach the blockchain until the limit is raised.', 'stablecoin-pay'),
                wc_price($amount),
                wc_price($limit)
            ) . '<br><br>' . $this->get_dashboard_limit_instruction()
              . '<br><br><strong>' . __('Do not click refund again first.', 'stablecoin-pay') . '</strong> '
              . __('A transfer for this order already exists; requesting another before checking the dashboard would refund the customer twice.', 'stablecoin-pay');

            $order->add_order_note($this->build_refund_action_note(
                $amount,
                __('⚠️ REFUND AWAITING YOUR SIGNATURE - no funds have moved yet', 'stablecoin-pay'),
                $action
            ));

            $pending_id = SP_API_Client::extract_transfer_id($refund_result);
            if ($pending_id !== '') {
                $order->update_meta_data('_sp_refund_id', $pending_id);
            }
            $order->update_meta_data('_sp_refund_pending', 'yes');
            $order->update_meta_data('_sp_refund_status', 'awaiting_signature');
            $order->save();

            error_log('PP Refund: ⚠️ transfer is awaiting merchant signature - nothing sent on-chain yet');

            // Deliberately an error: WooCommerce must not record this as a completed
            // refund while the funds are still sitting in the merchant wallet.
            return new WP_Error(
                'sp_awaiting_signature',
                sprintf(
                    __('Refund created but awaiting your signature. It is over the %s signing limit - approve it (or raise the limit) in your merchant dashboard. Do not retry, the transfer already exists.', 'stablecoin-pay'),
                    wc_price($limit)
                )
            );
        }
        
        // Success - add order note and update status.
        //
        // Read these tolerantly (envelopes, alternate key names). The identifier is
        // what matches the later `transfer` webhook back to this order, and the
        // previous code fell back to the literal string 'N/A' - which is non-empty,
        // so it was stored as though it were a real id and no incoming transfer
        // could ever match it. An empty string is the honest answer when the
        // response does not carry one.
        $refund_id = SP_API_Client::extract_transfer_id($refund_result);
        $transaction_hash = SP_API_Client::extract_transaction_hash($refund_result);

        if ($refund_id === '') {
            // Not fatal: the webhook handler can still tie the transfer back to this
            // order by destination_email against orders flagged _sp_refund_pending.
            error_log('PP Refund: response carried no transfer id - relying on the email fallback to confirm. Response keys: '
                . implode(', ', array_keys((array) $refund_result)));
        }
        
        // Prefer network name from webhook; fall back to chain_id map for older orders
        $network_name = $order->get_meta('_sp_network_name');
        if (empty($network_name)) {
            $network_name = $this->get_network_name($chain_id);
        }
        
        // Note: Refund uses the same chain/token as original payment (or USDC fallback)
        $refund_note = sprintf(
            __('REFUND INITIATED: %s. Reason: %s. Customer wallet: %s. Refund ID: %s. Refund will be sent as %s on %s (same as original payment). Refund initiated via payment provider API. Waiting for transfer confirmation...', 'stablecoin-pay'),
            wc_price($amount),
            $reason,
            $customer_wallet ?: $to_address,
            $refund_id !== '' ? $refund_id : __('pending (not returned by the API)', 'stablecoin-pay'),
            $token_symbol,
            $network_name
        );
        
        // Add note if using fallback
        $stored_chain_id = $order->get_meta('_sp_chain_id');
        $stored_token = $order->get_meta('_sp_token_symbol');
        if (empty($stored_chain_id) || empty($stored_token)) {
            $refund_note .= '<br><br><strong>ℹ️ Note:</strong> Original payment chain/token not found, using fallback: ' . $token_symbol . ' on ' . $network_name . '.';
        }
        
        $order->add_order_note($refund_note);
        
        // Store refund details and mark as pending
        $order->update_meta_data('_sp_refund_pending', 'yes');
        $order->update_meta_data('_sp_refund_status', 'pending');
        
        if (!empty($refund_id) && $refund_id !== 'N/A') {
            $order->update_meta_data('_sp_refund_id', $refund_id);
            error_log('PP Refund: Stored refund ID: ' . $refund_id);
        }
        if (!empty($transaction_hash) && $transaction_hash !== 'N/A') {
            $order->update_meta_data('_sp_refund_transaction_hash', $transaction_hash);
            error_log('PP Refund: Stored transaction hash: ' . $transaction_hash);
        }
        
        // Don't mark as refunded yet - wait for transfer webhook confirmation
        // WooCommerce will mark it as refunded when we return true, but we'll track status separately
        $order->save();
        
        error_log('PP Refund: Refund initiated for order #' . $order_id . ' - waiting for transfer confirmation via webhook');
        error_log('PP Refund: Refund ID: ' . $refund_id . ', Transaction Hash: ' . $transaction_hash);
        
        // Return true to WooCommerce so it shows the refund UI, but we'll update status when transfer webhook arrives
        return true;
    }
    
/**
     * Get token symbol for currency
     */
    private function get_token_symbol_for_currency($currency) {
        $currency_token_map = array(
            'USD' => 'USDC',
            'EUR' => 'USDC', // Default to USDC for EUR
            'GBP' => 'USDC', // Default to USDC for GBP
            'CAD' => 'USDC', // Default to USDC for CAD
            'AUD' => 'USDC', // Default to USDC for AUD
            'JPY' => 'USDC', // Default to USDC for JPY
            'CHF' => 'USDC', // Default to USDC for CHF
            'CNY' => 'USDC', // Default to USDC for CNY
        );
        
        return isset($currency_token_map[$currency]) ? $currency_token_map[$currency] : 'USDC';
    }
    
    /**
     * Get network name for chain ID (fallback when webhook did not set _sp_network_name on the order).
     */
    private function get_network_name($chain_id) {
        $networks = array(
            '1' => 'Ethereum Mainnet',
            '137' => 'Polygon',
            '80002' => 'Polygon Amoy Testnet',
            '11155111' => 'Sepolia Testnet',
            '56' => 'BSC',
            '97' => 'BSC Testnet',
            '42161' => 'Arbitrum One',
            '421614' => 'Arbitrum Sepolia',
            '10' => 'Optimism',
            '420' => 'Optimism Sepolia',
            '8453' => 'Base',
            '84532' => 'Base Sepolia',
            '295' => 'Hedera Mainnet',
            '296' => 'Hedera Testnet'
        );

        // Display names only - nothing branches on this. Filterable so a chain can
        // be named without a plugin release; settlement networks change (Ink is not
        // listed above yet, so refunds there currently read "Chain ID <n>").
        //
        //     add_filter('sp_network_names', function ($names) {
        //         $names['<ink chain id>'] = 'Ink';
        //         return $names;
        //     });
        $networks = apply_filters('sp_network_names', $networks);

        // Prefer the network name the API itself reported for this order, so the
        // note matches the provider's own wording ("PolygonAmoy", "Ink", ...).
        return isset($networks[$chain_id]) ? $networks[$chain_id] : 'Chain ID ' . $chain_id;
    }

    /**
     * Override can_refund to always allow refunds for Stablecoin Pay orders
     */
    public function can_refund($order) {
        error_log('PP Refund: can_refund() called for order #' . $order->get_id());
        error_log('PP Refund: Order payment method: ' . $order->get_payment_method());
        error_log('PP Refund: Order status: ' . $order->get_status());
        error_log('PP Refund: Gateway supports: ' . json_encode($this->supports));
        
        // Always allow refunds for Stablecoin Pay orders that have been paid
        if ($order->get_payment_method() === 'sp') {
            $paid_statuses = array('processing', 'completed', 'on-hold');
            $can_refund = in_array($order->get_status(), $paid_statuses);
            error_log('PP Refund: can_refund result: ' . ($can_refund ? 'YES' : 'NO'));
            return $can_refund;
        }
        
        // For other payment methods, use default behavior
        $result = parent::can_refund($order);
        error_log('PP Refund: can_refund (parent) result: ' . ($result ? 'YES' : 'NO'));
        return $result;
    }


    /**
     * Validate the payment form
     */
    public function validate_fields() {
        return true;
    }
    
    /**
     * Get payment method title (from config when whitelabel; no hardcoding elsewhere)
     */
    public function get_title() {
        $config_name = class_exists('SP_Whitelabel_Branding') ? SP_Whitelabel_Branding::get_whitelabel_plugin_name_from_config() : null;
        if (is_admin()) {
            return $config_name ? $config_name : __('Stablecoin Pay', 'stablecoin-pay');
        }
        
        // On checkout (frontend), use whitelabel title if available
        if (!empty($this->checkout_title)) {
            return $this->checkout_title;
        }
        
        // Fallback to default
        return $this->title ?: __('Pay with Stablecoin Pay', 'stablecoin-pay');
    }
    
    /**
     * Label for the Enable checkbox in gateway settings (from config when whitelabel).
     */
    public function get_enable_label() {
        $config_name = class_exists('SP_Whitelabel_Branding') ? SP_Whitelabel_Branding::get_whitelabel_plugin_name_from_config() : null;
        if ($config_name) {
            return sprintf(__('Enable %s crypto payments', 'stablecoin-pay'), $config_name);
        }
        return __('Enable Stablecoin Pay crypto payments', 'stablecoin-pay');
    }

    /**
     * Dashboard URL from whitelabel config (where merchants log in). Null when not whitelabel.
     *
     * @return string|null
     */
    public function get_dashboard_url_from_config() {
        return class_exists('SP_Whitelabel_Branding') ? SP_Whitelabel_Branding::get_whitelabel_dashboard_url_from_config() : null;
    }

    /**
     * Setup walkthrough video URL from whitelabel config, or null to hide the video box.
     *
     * @return string|null
     */
    public function get_setup_video_url_from_config() {
        return class_exists('SP_Whitelabel_Branding') ? SP_Whitelabel_Branding::get_whitelabel_setup_video_url_from_config() : null;
    }

    /**
     * HTML for "where to get credentials" - dashboard link when whitelabel, else generic.
     * Used in form field descriptions.
     *
     * @return string
     */
    public function get_credentials_source_description() {
        $dashboard_url = $this->get_dashboard_url_from_config();
        $plugin_name = class_exists('SP_Whitelabel_Branding') ? SP_Whitelabel_Branding::get_whitelabel_plugin_name_from_config() : null;
        if ($dashboard_url && $plugin_name) {
            $host = parse_url($dashboard_url, PHP_URL_HOST);
            $link = '<a href="' . esc_url($dashboard_url) . '" target="_blank" rel="noopener">' . esc_html($host ?: $dashboard_url) . '</a>';
            return sprintf(__('Get this from your %1$s dashboard at %2$s', 'stablecoin-pay'), esc_html($plugin_name), $link);
        }
        return __('Get this from your merchant dashboard', 'stablecoin-pay');
    }

    /**
     * HTML for "add webhook to dashboard" - dashboard link when whitelabel.
     *
     * @return string
     */
    /**
     * Render a derived, non-editable URL as plain text rather than a form input.
     *
     * WC_Settings_API dispatches on field type, so declaring the field as
     * `sp_readonly_url` routes it here.
     *
     * @param string $key
     * @param array  $data
     * @return string
     */
    public function generate_sp_readonly_url_html($key, $data) {
        $data = wp_parse_args($data, array('title' => '', 'description' => '', 'value' => null));

        // Fields may supply the value directly; otherwise it is resolved by key.
        $value = $data['value'] !== null ? $data['value'] : $this->get_option($key);

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc"><?php echo wp_kses_post($data['title']); ?></th>
            <td class="forminp">
                <code style="display:inline-block;padding:6px 10px;background:#f0f0f1;border-radius:3px;word-break:break-all;"><?php
                    echo esc_html($value);
                ?></code>
                <?php if (!empty($data['description'])) : ?>
                    <p class="description"><?php echo wp_kses_post($data['description']); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Nothing is posted for the read-only URL row, and the value is derived on
     * read anyway, so keep it out of the saved settings entirely.
     *
     * @param string $key
     * @param mixed  $value
     * @return string
     */
    public function validate_sp_readonly_url_field($key, $value) {
        return '';
    }

    /**
     * The domain the merchant must submit for whitelist approval.
     *
     * This is the origin that embeds the checkout widget, so it must be the store's
     * own host - taken from the site rather than typed, because a mistyped domain
     * fails approval silently and the checkout modal simply never loads.
     *
     * @return string e.g. shop.example.com
     */
    public function get_store_domain() {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        return $host ? $host : '';
    }

    /**
     * Whether the store domain could ever be approved.
     *
     * A local or private host is not reachable by the reviewer and cannot embed the
     * widget in production, so flagging it here saves a rejected submission.
     *
     * @return bool
     */
    private function store_domain_is_public() {
        $host = $this->get_store_domain();
        if ($host === '') {
            return false;
        }

        if (in_array($host, array('localhost', '127.0.0.1', '::1'), true)) {
            return false;
        }
        foreach (array('.local', '.test', '.localhost', '.invalid', '.example', '.internal') as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                return false;
            }
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return true;
    }

    /**
     * The webhook URL is derived from the site, never stored.
     *
     * Older builds saved it with the shared secret appended as `?secret=...`, so a
     * merchant who saved settings before upgrading still has that credential
     * sitting in the options table. Resolving the field here means the stale value
     * can never be rendered back into the settings form, whatever wrote it.
     *
     * @param string $key
     * @param mixed  $empty_value
     * @return mixed
     */
    public function get_option($key, $empty_value = null) {
        if ($key === 'webhook_url') {
            return class_exists('SP_Webhook_Provisioner')
                ? SP_Webhook_Provisioner::callback_url()
                : get_rest_url(null, SP_Webhook_Provisioner::CALLBACK_ROUTE);
        }

        return parent::get_option($key, $empty_value);
    }

    public function get_webhook_destination_description() {
        // The plugin registers this URL itself, so there is nothing to explain and
        // nothing to copy. Show nothing at all unless something is actually wrong.
        $status = class_exists('SP_Webhook_Provisioner') ? SP_Webhook_Provisioner::status() : array();

        if (!empty($status['message'])) {
            $colour = (isset($status['state']) && $status['state'] === 'unreachable') ? '#b26200' : '#b32d2e';
            return '<strong style="color:' . esc_attr($colour) . ';">'
                 . esc_html($status['message']) . '</strong>';
        }

        return '';
    }

    /**
     * Inner HTML for the setup instructions box (whitelabel-aware: dashboard URL and plugin name).
     *
     * @return string
     */
    public function get_setup_instructions_html() {
        $dashboard_url = $this->get_dashboard_url_from_config();
        $plugin_name = class_exists('SP_Whitelabel_Branding') ? SP_Whitelabel_Branding::get_whitelabel_plugin_name_from_config() : null;
        $setup_video_url = $this->get_setup_video_url_from_config();

        $step1_title = $plugin_name
            ? sprintf(__('Step 1. Get Your %s Credentials', 'stablecoin-pay'), esc_html($plugin_name))
            : __('Step 1. Get Your Payment Provider Credentials', 'stablecoin-pay');
        $dashboard_link = '';
        if ($dashboard_url) {
            $host = parse_url($dashboard_url, PHP_URL_HOST);
            $dashboard_link = '<a href="' . esc_url($dashboard_url) . '" target="_blank" rel="noopener">' . esc_html($host ?: $dashboard_url) . '</a>';
        }
        $login_phrase = $dashboard_link ? sprintf(__('Log in to your account at %s', 'stablecoin-pay'), $dashboard_link) : __('Log in to your account', 'stablecoin-pay');
        $whitelist_nav_phrase = $dashboard_link
            ? sprintf(
                /* translators: %s: linked dashboard hostname */
                __('In your dashboard at %s, open <strong>Settings &rarr; Domain Whitelist</strong>', 'stablecoin-pay'),
                $dashboard_link
            )
            : __('In your merchant dashboard, open <strong>Settings &rarr; Domain Whitelist</strong>', 'stablecoin-pay');
        // Repeat the dashboard URL in every step that sends the merchant there, so
        // they don't have to scroll back up after switching tabs.
        // Step 1 (credentials) lives under Settings → API Keys.
        // Step 2 (domain whitelist) lives under Settings → Domain Whitelist.
        // The webhook is not a step: it registers itself on save.
        if ($dashboard_link) {
            $nav_dashboard_phrase = sprintf(
                /* translators: %s: linked dashboard hostname (e.g. app.paymentservers.com) */
                __('Navigate to <strong>Settings &rarr; API Keys</strong> in your dashboard at %s', 'stablecoin-pay'),
                $dashboard_link
            );
        } else {
            $nav_dashboard_phrase = __('Navigate to <strong>Settings &rarr; API Keys</strong> in your dashboard', 'stablecoin-pay');
        }

        $step3_title = $plugin_name ? sprintf(__('Step 3: Enable %s', 'stablecoin-pay'), esc_html($plugin_name)) : __('Step 3: Enable payment provider', 'stablecoin-pay');
        $important_phrase = $plugin_name
            ? sprintf(__('<strong>⚠️ Important:</strong> %s works alongside other payment methods. Make sure to complete ALL steps above. The webhook is registered for you, but the domain whitelist in Step 2 is a manual approval and checkout will not load until it is granted.', 'stablecoin-pay'), esc_html($plugin_name))
            : __('<strong>⚠️ Important:</strong> The payment provider works alongside other payment methods. Make sure to complete ALL steps above. The webhook is registered for you, but the domain whitelist in Step 2 is a manual approval and checkout will not load until it is granted.', 'stablecoin-pay');

        $subscription_checkbox = $plugin_name
            ? sprintf(__('"%s Subscription"', 'stablecoin-pay'), esc_html($plugin_name))
            : __('Subscription', 'stablecoin-pay');

        ob_start();
        ?>
        <h3 style="margin-top:0;font-size:1.3em"><?php echo esc_html(__('Setup Instructions', 'stablecoin-pay')); ?></h3>
        <?php /* SETUP VIDEO - TEMPORARILY DISABLED, DO NOT DELETE.
                 Kept intact so it can be switched back on by removing this comment
                 wrapper (the opening line above and the closing one below). It is
                 still wired to `setup_video_url` in sp-whitelabel-config.php, so a
                 partner build that sets that value will show the video again the
                 moment this is uncommented. ?>
        <?php if ($setup_video_url) : ?>
        <div style="margin:0 0 20px;padding:15px;background:#f5f9ff;border:1px solid #3b82f6;border-radius:6px">
            <h4 style="margin:0 0 8px;color:#1d4ed8">🎥 <?php esc_html_e('Setup Video', 'stablecoin-pay'); ?></h4>
            <p style="margin:0 0 10px"><?php esc_html_e('Watch this walkthrough first to complete the setup quickly.', 'stablecoin-pay'); ?></p>
            <div style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;border-radius:6px;background:#000">
                <?php // Self-hosted mp4 (captions are burned into the picture, so no <track> is needed).
                      // preload="metadata" keeps the settings page light: the browser fetches only the
                      // header to show duration, not the whole file, until the merchant hits play. ?>
                <video
                    controls
                    preload="metadata"
                    playsinline
                    title="<?php esc_attr_e('Setup video walkthrough', 'stablecoin-pay'); ?>"
                    style="position:absolute;top:0;left:0;width:100%;height:100%;border:0">
                    <source src="<?php echo esc_url($setup_video_url); ?>" type="video/mp4">
                    <?php esc_html_e('Your browser cannot play this video.', 'stablecoin-pay'); ?>
                    <a href="<?php echo esc_url($setup_video_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open it in a new tab instead.', 'stablecoin-pay'); ?></a>
                </video>
            </div>
        </div>
        <?php endif; ?>
        <?php */ ?>
        <h4 style="margin:1.5em 0 .5em"><?php echo $step1_title; ?></h4>
        <ol style="line-height:1.6;margin-top:0">
            <li><?php echo $login_phrase; ?></li>
            <li><?php echo $nav_dashboard_phrase; ?></li>
            <li><?php echo __('Copy your <strong>Merchant ID</strong>', 'stablecoin-pay'); ?></li>
            <li><?php echo __('After you open <strong>Settings &rarr; API Keys</strong>, click <strong>Add API</strong>, name your API key, select <strong>Full Access</strong> for permissions, click <strong>Review Key</strong>, and then copy and paste the generated API key into the <strong>API Key</strong> field in WooCommerce settings.', 'stablecoin-pay'); ?></li>
            <li><?php esc_html_e('Paste both into the fields below', 'stablecoin-pay'); ?></li>
        </ol>
        <?php // The webhook is registered automatically on save, so it is not a step
              // the merchant performs and is deliberately absent from this list. A
              // failure surfaces as an admin notice with a retry button instead. ?>
        <h4 style="margin:1.5em 0 .5em"><?php esc_html_e('Step 2: Whitelist your store domain', 'stablecoin-pay'); ?></h4>
        <div style="margin:0 0 10px;padding:12px;background:#fef3c7;border:1px solid #998843;border-radius:4px">
            <p style="margin:0 0 8px"><strong><?php esc_html_e('Required before customers can pay.', 'stablecoin-pay'); ?></strong>
            <?php esc_html_e('Checkout opens in an embedded window, and each store domain is reviewed before it is allowed to embed it.', 'stablecoin-pay'); ?></p>
            <ol style="line-height:1.6;margin:0">
                <li><?php echo sprintf(
                    /* translators: %s: this store's domain, e.g. shop.example.com */
                    __('Copy your store domain: %s', 'stablecoin-pay'),
                    '<code style="padding:2px 6px;background:#fff;border-radius:3px">' . esc_html($this->get_store_domain()) . '</code>'
                ); ?></li>
                <li><?php echo $whitelist_nav_phrase; ?></li>
                <li><?php esc_html_e('Paste the domain, submit it, and wait for approval.', 'stablecoin-pay'); ?></li>
                <li><em><?php esc_html_e('Until it is approved the checkout window will not load, even though everything else is configured correctly.', 'stablecoin-pay'); ?></em></li>
            </ol>
            <?php if (!$this->store_domain_is_public()) : ?>
                <p style="margin:10px 0 0"><strong style="color:#b26200;"><?php
                    esc_html_e('This looks like a local or private address, which cannot be approved. Submit your live store domain once the site is public.', 'stablecoin-pay');
                ?></strong></p>
            <?php endif; ?>
        </div>
        <h4 style="margin:1.5em 0 .5em"><?php echo $step3_title; ?></h4>
        <ol style="line-height:1.6;margin-top:0">
            <li><?php echo sprintf(__('Check the <strong>%s</strong> box below', 'stablecoin-pay'), esc_html($this->get_enable_label())); ?></li>
            <li><?php echo __('Click <strong>Save changes</strong>', 'stablecoin-pay'); ?></li>
            <li><?php esc_html_e('Done! Customers will now see the payment option at checkout!', 'stablecoin-pay'); ?></li>
        </ol>
        <p style="margin-bottom:0;padding:10px;background:#fef3c7;border-radius:4px;border:1px solid #998843"><?php echo $important_phrase; ?></p>
        <?php // Refunds move real funds on-chain, so they carry costs a card refund
              // does not. Merchants should know this before they issue one, not
              // after they see the customer received less than they paid. ?>
        <div style="margin-top:20px;padding:15px;background:#f0f6fc;border-radius:4px;border:1px solid #6b8cae">
            <h3 style="margin-top:0">↩️ <?php esc_html_e('A quick note on refunds', 'stablecoin-pay'); ?></h3>
            <p style="margin-top:0"><?php esc_html_e('You can refund an order from here, and it will be sent back on-chain. Because it is a real blockchain transfer, two small costs come with it:', 'stablecoin-pay'); ?></p>
            <ul style="line-height:1.6;margin:10px 0">
                <li><?php echo sprintf(
                    /* translators: %s: gas headroom amount, e.g. 0.1 */
                    __('A <strong>network fee of roughly %s</strong> to move the funds. This is paid to the network, not to us, and it is not returned to the customer.', 'stablecoin-pay'),
                    esc_html((string) $this->get_refund_gas_headroom())
                ); ?></li>
                <li><?php esc_html_e('Your usual merchant fee, which applies to the transfer that sends the money back.', 'stablecoin-pay'); ?></li>
            </ul>
            <p style="margin-bottom:0"><strong><?php esc_html_e('So where you have the choice, refunding by card or cash is usually the kinder option', 'stablecoin-pay'); ?></strong> &mdash;
            <?php esc_html_e('the customer gets the full amount back and it costs you nothing to send. On-chain refunds are here for when that is not practical.', 'stablecoin-pay'); ?></p>
        </div>
        <div style="margin-top:20px;padding:15px;background:#e8f5e9;border-radius:4px;border:1px solid #4caf50">
            <h3 style="margin-top:0">💳 <?php esc_html_e('Setting Up Subscription Products', 'stablecoin-pay'); ?></h3>
            <p><strong><?php esc_html_e('To enable recurring payments for a product:', 'stablecoin-pay'); ?></strong></p>
            <ol style="line-height:1.6;margin-top:10px">
                <li><?php echo __('Go to <strong>Products</strong> → Select the product you want to make a subscription', 'stablecoin-pay'); ?></li>
                <li><?php echo __('Click <strong>Edit</strong> and scroll to the <strong>Product Data</strong> section', 'stablecoin-pay'); ?></li>
                <li><?php echo sprintf(__('Check the <strong>%s</strong> checkbox', 'stablecoin-pay'), $subscription_checkbox); ?></li>
                <li><?php esc_html_e('Configure the subscription settings:', 'stablecoin-pay'); ?>
                    <ul style="margin-top:8px">
                        <li><?php echo __('<strong>Frequency:</strong> How often it repeats (Every, Every Other, Every Third, etc.)', 'stablecoin-pay'); ?></li>
                        <li><?php echo __('<strong>Interval:</strong> Time period (Day, Week, Month, Year)', 'stablecoin-pay'); ?></li>
                        <li><?php echo __('<strong>Duration:</strong> Number of payments (0 = Until Cancelled)', 'stablecoin-pay'); ?></li>
                    </ul>
                </li>
                <li><?php echo __('Click <strong>Update</strong> to save the product', 'stablecoin-pay'); ?></li>
            </ol>
            <p style="margin-bottom:0;font-size:13px;color:#2e7d32"><strong><?php esc_html_e('Note:', 'stablecoin-pay'); ?></strong> <?php esc_html_e('Each product must be configured individually. Customers can manage their subscriptions from their account page.', 'stablecoin-pay'); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Logo URL for the Payments list (and config-based checkout). From config logo_url or fallback.
     *
     * @return string
     */
    public function get_list_logo_url() {
        if (class_exists('SP_Whitelabel_Branding')) {
            $config_logo = SP_Whitelabel_Branding::get_whitelabel_logo_url_from_config();
            if (!empty($config_logo)) {
                return $config_logo;
            }
        }
        return '';
    }

    /**
     * Get payment method icon.
     * - On the Payments list (all gateways): show logo next to name (from config logo_url or fallback).
     * - On the Manage page (our gateway settings): no icon.
     * - On checkout: whitelabel logo.
     */
    public function get_icon() {
        $icon_url = '';
        
        // Normalize company name once for all checks
        $normalized_company = !empty($this->brand_company) ? strtolower(str_replace(' ', '', $this->brand_company)) : '';
        
        // In admin: show logo only on the Payments list (not on our Manage settings page)
        if (is_admin()) {
            $section = isset($_GET['section']) ? sanitize_text_field(wp_unslash($_GET['section'])) : '';
            if ($section === 'sp') {
                $icon_url = ''; // Manage page: no icon
            } else {
                $icon_url = $this->get_list_logo_url(); // Payments list: logo next to name
            }
        } else {
            // On checkout (frontend), use whitelabel icon from config only (no bundled image)
            $icon_url = !empty($this->checkout_icon) ? $this->checkout_icon : '';
        }
        
        // Only log on checkout, not in admin (reduces log noise)
        if (is_checkout()) {
            error_log('PP Whitelabel: 🖼️ get_icon() called - Context: CHECKOUT - Using icon URL: ' . $icon_url);
        }
        
        // No fallback to bundled image; empty is valid (text-only display)
        if (empty($icon_url) && !is_admin()) {
            error_log('PP Whitelabel: 🖼️ No icon URL (config logo_url only)');
        }
        if (empty($icon_url)) {
            return '';
        }
        
        $icon_size = is_admin() ? '24px' : '30px';
        if (is_checkout()) {
            error_log('PP Whitelabel: 🖼️ Icon size: ' . $icon_size . ' for company: "' . $this->brand_company . '"');
        }
        
        $icon_html = '<img src="' . esc_url($icon_url) . '" alt="' . esc_attr($this->get_title()) . '" style="max-width: ' . $icon_size . '; max-height: ' . $icon_size . '; height: auto; vertical-align: middle; margin-left: 8px;" />';
        
        return apply_filters('woocommerce_gateway_icon', $icon_html, $this->id);
    }
    
    /**
     * Customize the payment button text
     * CRITICAL: Only used on checkout (frontend), uses whitelabel data
     */
    public function get_order_button_text() {
        // Get logo URL and company name from checkout-specific data
        $logo_url = !empty($this->button_logo_url) ? $this->button_logo_url : '';
        $company_name = !empty($this->button_company_name) ? $this->button_company_name : 'Stablecoin Pay';
        
        // If we have checkout title, extract company name from it
        if (!empty($this->checkout_title) && empty($this->button_company_name)) {
            // Extract company name from "Pay with CompanyName"
            if (preg_match('/Pay with (.+)/', $this->checkout_title, $matches)) {
                $company_name = $matches[1];
                $this->button_company_name = $company_name;
            }
        }
        
        // Use checkout icon if available
        if (!empty($this->checkout_icon)) {
            $logo_url = $this->checkout_icon;
            $this->button_logo_url = $logo_url;
        }
        
        error_log('PP Whitelabel: 🔘 Button text (CHECKOUT) - Company: "' . $company_name . '" | Logo URL: ' . $logo_url);
        
        // Return text only - logo will be added via JavaScript
        return sprintf(__('Pay with %s', 'stablecoin-pay'), $company_name);
    }
    
  
    public function hide_manual_refund_ui_for_sp() {
        // Only run on order edit pages
        if (!function_exists('get_current_screen')) {
            return;
        }
        
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }
        
        // Check if we're on an order edit page (HPOS uses 'woocommerce_page_wc-orders', traditional uses 'shop_order')
        $is_order_page = ($screen->id === 'woocommerce_page_wc-orders' || $screen->id === 'shop_order' || $screen->post_type === 'shop_order');
        
        if (!$is_order_page) {
            return;
        }
        
        // Get order ID - try HPOS first, then fallback to traditional
        $order_id = 0;
        if (isset($_GET['id'])) {
            $order_id = absint($_GET['id']); // HPOS uses ?id= in URL
        } elseif (isset($_GET['post'])) {
            $order_id = absint($_GET['post']); // Traditional uses ?post= in URL
        } elseif (isset($GLOBALS['post']) && isset($GLOBALS['post']->ID)) {
            $order_id = absint($GLOBALS['post']->ID);
        }
        
        if (!$order_id) {
            // On order list page, just hide for all - JavaScript will check individual orders
            ?>
            <style type="text/css">
            /* Hide manual refund button globally - JavaScript will handle per-order */
            .woocommerce-order-refund .refund-actions .do-manual-refund,
            .woocommerce-order-refund .refund-actions button[class*="manual"],
            .woocommerce-order-refund .refund-actions a[class*="manual"],
            .woocommerce-order-refund .refund-actions input[value*="manual"],
            .woocommerce-order-refund .refund-actions input[type="radio"][value="manual"],
            .woocommerce-order-refund .refund-actions label[for*="manual"] {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                height: 0 !important;
                width: 0 !important;
                overflow: hidden !important;
            }
            </style>
            <?php
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== 'sp') {
            return;
        }
        
        // Add class to body so CSS only applies to Stablecoin Pay orders
        ?>
        <script type="text/javascript">
        jQuery(function($) {
            $('body').addClass('sp-order-page');
        });
        </script>
        <style type="text/css">
        /* Completely hide manual refund button ONLY for Stablecoin Pay orders */
        body.sp-order-page .woocommerce-order-refund .refund-actions .do-manual-refund,
        body.sp-order-page .woocommerce-order-refund .refund-actions button[class*="manual"],
        body.sp-order-page .woocommerce-order-refund .refund-actions a[class*="manual"],
        body.sp-order-page .woocommerce-order-refund .refund-actions input[value*="manual"],
        body.sp-order-page .woocommerce-order-refund .refund-actions input[type="radio"][value="manual"],
        body.sp-order-page .woocommerce-order-refund .refund-actions label[for*="manual"],
        body.sp-order-page .woocommerce-order-refund .manual-refund-actions,
        body.sp-order-page .woocommerce-order-refund .refund-form .manual-refund {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
            height: 0 !important;
            width: 0 !important;
            overflow: hidden !important;
        }
        
        /* Ensure automatic refund is selected by default for Stablecoin Pay orders */
        body.sp-order-page .woocommerce-order-refund input[type="radio"][value="api"]:checked,
        body.sp-order-page .woocommerce-order-refund .do-api-refund {
            display: inline-block !important;
        }
        </style>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Only run for Stablecoin Pay orders
            var paymentMethod = '<?php echo esc_js($order->get_payment_method()); ?>';
            if (paymentMethod !== 'sp') {
                return;
            }
            
            // Function to disable manual refund options
            function disableManualRefund() {
                var $section = $('.woocommerce-order-refund');
                if ($section.length === 0) return;
                
                // Completely hide manual refund button
                $section.find('.do-manual-refund, button.do-manual-refund, a.do-manual-refund').hide().remove();
                
                // Hide manual refund radio option and all related elements
                var $manualRadio = $section.find('input[type="radio"][value="manual"]');
                $manualRadio.closest('li, div, p, label, tr').hide().remove();
                
                // Hide any buttons with "manual" in text or class
                $section.find('button, a').each(function() {
                    var $btn = $(this);
                    var text = $btn.text().toLowerCase();
                    var classes = $btn.attr('class') || '';
                    if (text.indexOf('manual') !== -1 || classes.indexOf('manual') !== -1) {
                        $btn.hide().remove();
                    }
                });
                
                // Select automatic refund if available
                var apiRefund = $('.woocommerce-order-refund input[type="radio"][value="api"]');
                if (apiRefund.length && !apiRefund.is(':checked')) {
                    apiRefund.prop('checked', true).trigger('change');
                }
                
                // Inject notice if not present
                if ($section.find('.sp-manual-refund-disabled').length === 0) {
                    $section.find('.refund-actions').prepend('<div class="notice notice-warning sp-manual-refund-disabled" style="margin-bottom:8px;">⚠️ Manual refund is disabled for this payment method. Use the API refund button.</div>');
                }
            }
            
            // Run immediately
            disableManualRefund();
            
            // Also run when refund modal/interface is opened
            $(document).on('click', '.refund-items', function() {
                setTimeout(disableManualRefund, 100);
            });
            
            // Watch for dynamically loaded content
            var observer = new MutationObserver(function(mutations) {
                disableManualRefund();
            });
            
            // Observe changes to the refund section
            var refundContainer = document.querySelector('.woocommerce-order-refund');
            if (refundContainer) {
                observer.observe(refundContainer, {
                    childList: true,
                    subtree: true
                });
            }
        });
        </script>
        <?php
    }
    
    /**
     * Additional JavaScript to hide manual refund button (runs in footer for better timing)
     * Works with both HPOS and traditional order storage
     */
    public function hide_manual_refund_js_for_sp() {
        // Only run on order edit pages
        if (!function_exists('get_current_screen')) {
            return;
        }
        
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }
        
        // Check if we're on an order edit page
        $is_order_page = ($screen->id === 'woocommerce_page_wc-orders' || $screen->id === 'shop_order' || $screen->post_type === 'shop_order');
        
        if (!$is_order_page) {
            return;
        }
        
        // Get order ID - try HPOS first, then fallback to traditional
        $order_id = 0;
        if (isset($_GET['id'])) {
            $order_id = absint($_GET['id']);
        } elseif (isset($_GET['post'])) {
            $order_id = absint($_GET['post']);
        } elseif (isset($GLOBALS['post']) && isset($GLOBALS['post']->ID)) {
            $order_id = absint($GLOBALS['post']->ID);
        }
        
        // If we have an order ID, check if it's Stablecoin Pay. Otherwise, JS will check dynamically
        $is_sp = false;
        if ($order_id) {
            $order = wc_get_order($order_id);
            if ($order && $order->get_payment_method() === 'sp') {
                $is_sp = true;
            }
        }
        
        ?>
        <script type="text/javascript">
        jQuery(function($) {
            // Check if this is a Stablecoin Pay order - only hide manual refund for Stablecoin Pay orders
            var isSPOrder = <?php echo $is_sp ? 'true' : 'false'; ?>;
            var orderId = <?php echo $order_id ? absint($order_id) : 'null'; ?>;
            // Gateway display title, used for the text-scan fallback below. The gateway id ('sp')
            // is far too short to substring-match page text safely, so match the title instead.
            var spMethodTitle = <?php echo wp_json_encode(strtolower(trim((string) $this->title))); ?>;
            
            // Function to check if order is Stablecoin Pay (for dynamic content)
            function checkIfSPOrder() {
                // If we already know it's Stablecoin Pay from PHP, use that
                if (isSPOrder) {
                    return true;
                }
                
                // Try to find payment method from WooCommerce order data
                // WooCommerce stores this in various places - check them all
                var paymentMethod = '';
                
                // Method 1: Check order details meta box
                var $orderDetails = $('.woocommerce-order-data, .order_data_column, .woocommerce-order-items');
                if ($orderDetails.length > 0) {
                    var orderText = $orderDetails.text().toLowerCase();
                    if (spMethodTitle && orderText.indexOf(spMethodTitle) !== -1) {
                        return true;
                    }
                }
                
                // Method 2: Check if there's a "Refund via Stablecoin Pay" button - if so, it's Stablecoin Pay
                if ($('.button.refund-items[data-refund-id], button.do-api-refund').length > 0) {
                    // Check if gateway is Stablecoin Pay by looking for gateway-specific elements
                    var $gatewayElements = $('[data-gateway="sp"], [data-payment-method="sp"]');
                    if ($gatewayElements.length > 0) {
                        return true;
                    }
                }
                
                // Method 3: Check order edit form fields
                var $paymentField = $('select[name*="payment_method"], input[name*="payment_method"], .payment_method');
                if ($paymentField.length > 0) {
                    $paymentField.each(function() {
                        var val = $(this).val() || $(this).text() || '';
                        if (val.toLowerCase() === 'sp') {
                            return true;
                        }
                    });
                }
                
                return false;
            }
            
            // Simple aggressive approach: Remove manual refund buttons ONLY for Stablecoin Pay orders
            function hideManualRefundButtons() {
                // Only hide if this is a Stablecoin Pay order
                if (!checkIfSPOrder()) {
                    return; // Not a Stablecoin Pay order - leave manual refund buttons alone
                }
                
                // Remove all manual refund buttons and radios
                $('.do-manual-refund, button.do-manual-refund, a.do-manual-refund').hide().remove();
                
                // Remove manual refund radio buttons and their containers
                $('input[type="radio"][value="manual"], input[type="radio"][id*="manual"], input[type="radio"][name*="manual"]').each(function() {
                    $(this).closest('li, div, p, label, tr, td').hide().remove();
                });
                
                // Remove any buttons with "manual refund" in text or class
                $('.woocommerce-order-refund, #woocommerce-order-refund, .refund-actions').find('button, a, input[type="button"]').each(function() {
                    var $btn = $(this);
                    var text = ($btn.text() || '').toLowerCase();
                    var classes = ($btn.attr('class') || '').toLowerCase();
                    if ((text.indexOf('manual') !== -1 && text.indexOf('refund') !== -1) || classes.indexOf('manual') !== -1) {
                        $btn.hide().remove();
                    }
                });
            }
            
            // Run immediately and repeatedly
            hideManualRefundButtons();
            setInterval(hideManualRefundButtons, 500); // Run every 500ms to catch dynamic content
            
            // Watch for refund section opening
            $(document).on('click', '.refund-items, #refund-items, button[data-action="refund"]', function() {
                setTimeout(hideManualRefundButtons, 50);
                setTimeout(hideManualRefundButtons, 200);
                setTimeout(hideManualRefundButtons, 500);
            });
            
            // Watch for AJAX completion
            $(document).ajaxComplete(function() {
                setTimeout(hideManualRefundButtons, 50);
            });
            
            // Use MutationObserver for dynamically added content
            if (typeof MutationObserver !== 'undefined') {
                var observer = new MutationObserver(function() {
                    hideManualRefundButtons();
                });
                observer.observe(document.body, {
                    childList: true,
                    subtree: true
                });
            }
        });
        </script>
        <?php
    }
    
    // All interception methods removed - using simple CSS/JS approach only
    
    /**
     * Customize refund meta key display (if needed)
     */
    public function customize_refund_meta_key($display_key, $meta, $order) {
        // Be defensive: $order can be a WC_Order, item, or other context in email templates
        if (is_object($order) && method_exists($order, 'get_payment_method')) {
            if ($order->get_payment_method() === 'sp') {
                // Customize any refund-related meta keys if needed
            }
        }
        return $display_key;
    }
    
    /**
     * Add custom CSS for the payment button
     */
    public function add_payment_button_styles() {
        if (is_checkout()) {
            ?>
            <style>
            /* Force display Stablecoin Pay payment method */
            .payment_method_sp {
                display: list-item !important;
                visibility: visible !important;
                opacity: 1 !important;
                position: relative !important;
            }
            
            /* Hide the payment box - we don't need it */
            .woocommerce-checkout .payment_method_sp .payment_box {
                display: none !important;
            }
            
            /* Style the "Place Order" button when Stablecoin Pay is selected */
            .payment_method_sp input[type="radio"]:checked ~ #place_order,
            body.woocommerce-checkout.payment_method_sp #place_order {
                background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%) !important;
                border: none !important;
                color: white !important;
                font-weight: bold !important;
                font-size: 18px !important;
                padding: 15px 30px !important;
                border-radius: 8px !important;
                text-transform: uppercase !important;
                letter-spacing: 1px !important;
                box-shadow: 0 4px 15px rgba(59, 130, 246, 0.4) !important;
                transition: all 0.3s ease !important;
            }
            
            body.woocommerce-checkout.payment_method_sp #place_order:hover {
                transform: translateY(-2px) !important;
                box-shadow: 0 6px 20px rgba(59, 130, 246, 0.6) !important;
            }
            </style>
            <script>
            // Simple debugging - no complex workarounds needed since you're using traditional checkout
            jQuery(document).ready(function($) {
                console.log('PP payment gateway loaded');
                
                // Get logo URL and company name from PHP
                var spLogoUrl = '<?php echo esc_js($this->button_logo_url); ?>';
                var spCompanyName = '<?php echo esc_js($this->button_company_name); ?>';
                
                // Function to inject logo into button
                function injectButtonLogo() {
                    var $button = $('#place_order');
                    if ($button.length === 0) return;
                    
                    // Only inject if Stablecoin Pay is selected
                    var selectedMethod = $('input[name="payment_method"]:checked').val();
                    if (selectedMethod !== 'sp') {
                        // Remove logo if another method is selected
                        $button.find('img.sp-button-logo').remove();
                        return;
                    }
                    
                    // Check if logo already injected
                    if ($button.find('img.sp-button-logo').length > 0) {
                        return;
                    }
                    
                    // Inject logo if we have a URL
                    if (spLogoUrl && spLogoUrl.trim() !== '') {
                        var $logo = $('<img>', {
                            src: spLogoUrl,
                            alt: spCompanyName || 'Stablecoin Pay',
                            class: 'sp-button-logo',
                            css: {
                                'max-width': '20px',
                                'height': 'auto',
                                'vertical-align': 'middle',
                                'margin-right': '8px',
                                'display': 'inline-block'
                            }
                        });
                        
                        // Prepend logo to button text
                        var buttonText = $button.html();
                        // Remove existing logo if any
                        buttonText = buttonText.replace(/<img[^>]*class="sp-button-logo"[^>]*>/gi, '');
                        $button.html($logo[0].outerHTML + buttonText);
                        
                        console.log('PP logo injected into button:', spLogoUrl);
                    }
                }
                
                // Inject logo on page load
                injectButtonLogo();
                
                // Style the Place Order button when Stablecoin Pay is selected
                $('input[name="payment_method"]').on('change', function() {
                    var selectedMethod = $(this).val();
                    if (selectedMethod === 'sp') {
                        console.log('PP selected');
                        $('body').addClass('payment_method_sp');
                        // Inject logo when Stablecoin Pay is selected
                        setTimeout(injectButtonLogo, 100);
                    } else {
                        $('body').removeClass('payment_method_sp');
                        // Remove logo when another method is selected
                        $('#place_order').find('img.sp-button-logo').remove();
                    }
                });
                
                // Check initial state
                var initialMethod = $('input[name="payment_method"]:checked').val();
                if (initialMethod === 'sp') {
                    $('body').addClass('payment_method_sp');
                }
            });
            </script>
            <?php
        }
    }
    
    /**
     * Add refund transaction hash (for manual refunds)
     */
    public function add_refund_transaction_hash($order_id, $transaction_hash) {
        $order = wc_get_order($order_id);
        
        if ($order) {
            $order->add_order_note(__('Refund processed', 'stablecoin-pay'));
            $order->update_meta_data('_refund_transaction_hash', $transaction_hash);
            $order->save();
        }
    }
    
    /**
     * Get refund instructions for merchants
     */
    public function get_refund_instructions() {
        return array(
            'title' => __('Manual Refund Process', 'stablecoin-pay'),
            'steps' => array(
                __('1. Customer requests refund', 'stablecoin-pay'),
                __('2. Approve refund in WooCommerce', 'stablecoin-pay'),
                __('3. Open your crypto wallet (MetaMask, etc.)', 'stablecoin-pay'),
                __('4. Send crypto back to customer wallet address', 'stablecoin-pay'),
                __('5. Update order status to "Refunded"', 'stablecoin-pay'),
            ),
            'note' => __('Remember: You pay gas fees for the refund transaction', 'stablecoin-pay')
        );
    }
    
    
    /**
     * Check if gateway needs setup
     */
    public function needs_setup() {
        $needs_setup = empty($this->get_option('merchant_id'));
        error_log('PP Gateway: needs_setup() = ' . ($needs_setup ? 'YES' : 'NO'));
        return $needs_setup;
    }
    
    /**
     * Check if the gateway is available
     */
    public function is_available() {
        // Only log availability checks on checkout page (not admin) to reduce log noise
        $context = is_checkout() ? 'CHECKOUT PAGE' : (is_admin() ? 'ADMIN' : 'OTHER');
        
        // Only log detailed debug info on checkout page, not admin
        if (is_checkout()) {
        error_log('PP Gateway: Availability check [' . $context . '] - Enabled: ' . $this->get_option('enabled') . ', Merchant ID: ' . ($this->get_option('merchant_id') ? 'set' : 'empty') . ', API Key: ' . (!empty($this->get_option('api_key')) ? 'set' : 'empty'));
        }
        
        // Check cart (only on frontend)
        if (!is_admin() && WC()->cart) {
            error_log('PP Gateway: Cart total $' . WC()->cart->get_total('edit') . ', items: ' . WC()->cart->get_cart_contents_count() . ', currency: ' . get_woocommerce_currency());
            if (WC()->cart->needs_shipping()) {
                $chosen_shipping = WC()->session ? WC()->session->get('chosen_shipping_methods') : array();
                error_log('PP Gateway: Cart needs shipping, chosen: ' . json_encode($chosen_shipping));
            }
        }
        
        if ($this->get_option('enabled') !== 'yes') {
            if (is_checkout()) {
            error_log('PP Gateway: UNAVAILABLE - disabled in settings');
            }
            return false;
        }
        
        if (empty($this->get_option('merchant_id'))) {
            if (is_checkout()) {
            error_log('PP Gateway: UNAVAILABLE - no merchant ID');
            }
            return false;
        }
        
        if (empty($this->get_option('api_key'))) {
            if (is_checkout()) {
            error_log('PP Gateway: UNAVAILABLE - no API key');
            }
            return false;
        }
        
        $parent_available = parent::is_available();
        if (is_checkout()) {
        error_log('PP Gateway: Parent is_available: ' . ($parent_available ? 'true' : 'false'));
        }
        
        if (!$parent_available) {
            if (is_checkout()) {
            error_log('PP Gateway: UNAVAILABLE - parent returned false (cart/terms/shipping etc.)');
            $terms_page_id = wc_get_page_id('terms');
            if (empty($terms_page_id)) {
                error_log('PP Gateway: Terms & Conditions page not set - may block gateways.');
                }
            }
            return false;
        }
        
        if (is_checkout()) {
        error_log('PP Gateway: AVAILABLE');
        }
        return true;
    }
    
    /**
     * Simple function: Got payment? Redirect to orders page outside modal
     */
    public function redirect_after_payment() {
        // Get the most recent order
        $user_id = get_current_user_id();
        $orders = wc_get_orders(array(
            'customer_id' => $user_id,
            'status' => array('completed'),
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        
        if (!empty($orders)) {
            $order = $orders[0];
            $redirect_url = $order->get_checkout_order_received_url();
            
            error_log('PP Gateway: Payment completed, redirecting');
            
            // Return redirect URL for JavaScript to use
            return array(
                'success' => true,
                'redirect_url' => $redirect_url,
                'order_id' => $order->get_id()
            );
        }
        
        return array(
            'success' => false,
            'message' => 'No completed orders found'
        );
    }
    
    /**
     * AJAX handler for redirect after payment
     */
    public function redirect_after_payment_ajax() {
        $result = $this->redirect_after_payment();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * Calculate cart totals from WooCommerce cart
     * Includes discounts and coupons
     */
    private function calculate_cart_totals() {
        $cart = WC()->cart;
        
        $subtotal = (float) $cart->get_subtotal();
        $shipping = (float) $cart->get_shipping_total();
        $tax = (float) $cart->get_total_tax();
        
        // Get discount information (coupons/discounts)
        $discount_total = (float) $cart->get_discount_total();
        $discount_tax = (float) $cart->get_discount_tax();
        $applied_coupons = $cart->get_applied_coupons();
        
        // Get coupon details
        $coupon_details = array();
        if (!empty($applied_coupons)) {
            foreach ($applied_coupons as $coupon_code) {
                $coupon = new WC_Coupon($coupon_code);
                if ($coupon->get_id()) {
                    $coupon_discount = $cart->get_coupon_discount_amount($coupon_code, $cart->display_prices_including_tax());
                    $coupon_details[] = array(
                        'code' => $coupon_code,
                        'discount' => (float) $coupon_discount,
                        'type' => $coupon->get_discount_type(),
                        'description' => $coupon->get_description()
                    );
                }
            }
        }
        
        // Get cart fees (additional charges like handling fees, processing fees, etc.)
        $fees = $cart->get_fees();
        $fee_total = 0;
        $fee_details = array();
        if (!empty($fees)) {
            foreach ($fees as $fee) {
                $fee_amount = (float) $fee->amount;
                $fee_total += $fee_amount;
                $fee_details[] = array(
                    'name' => $fee->name,
                    'amount' => $fee_amount,
                    'taxable' => $fee->taxable,
                    'tax_class' => $fee->tax_class
                );
            }
        }
        
        // Calculate final total (WooCommerce already applies discounts and fees to get_total())
        $total = (float) $cart->get_total('edit');
        
        // Ensure total is never 0
        if ($total <= 0) {
            $total = $subtotal > 0 ? $subtotal : 0.01; // Minimum $0.01
        }
        
        // Check if cart contains subscription
        $has_subscription = false;
        $subscription_data = null;
        
        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $is_sub = $product->get_meta('_sp_subscription') === 'yes';
            
            if ($is_sub) {
                $has_subscription = true;
                $subscription_data = array(
                    'frequency' => $product->get_meta('_sp_frequency'),
                    'interval' => $product->get_meta('_sp_interval'),
                    'duration' => $product->get_meta('_sp_duration')
                );
                break;
            }
        }
        
        return array(
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'tax' => $tax,
            'discount' => $discount_total,
            'discount_tax' => $discount_tax,
            'fees' => $fee_total,
            'fee_details' => $fee_details,
            'total' => $total,
            'currency' => get_woocommerce_currency(),
            'has_subscription' => $has_subscription,
            'subscription_data' => $subscription_data,
            'applied_coupons' => $applied_coupons,
            'coupon_details' => $coupon_details,
            'items' => $this->get_cart_items_data()
        );
    }
    
    /**
     * Get cart items data for purchase session
     * Includes discount information for each item
     */
    private function get_cart_items_data() {
        $items = array();
        
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            
            // Get original price and discounted price
            $original_price = (float) $product->get_price();
            $line_subtotal = (float) $cart_item['line_subtotal']; // Price before discount
            $line_total = (float) $cart_item['line_total']; // Price after discount
            $line_discount = $line_subtotal - $line_total; // Discount amount for this line
            
            $items[] = array(
                'name' => $product->get_name(),
                'quantity' => $cart_item['quantity'],
                'price' => $original_price,
                'line_subtotal' => $line_subtotal, // Before discount
                'line_total' => $line_total, // After discount (what customer pays)
                'line_discount' => $line_discount, // Discount amount
                'total' => $line_total // Alias for backward compatibility
            );
        }
        
        return $items;
    }
    
    /**
     * Prepare purchase session data from cart (WooCommerce-only approach)
     */
    private function prepare_purchase_session_from_cart($order, $cart_data) {
       
        if ($cart_data['has_subscription']) {
            $order->update_meta_data('_sp_is_subscription', 'yes');
            $order->update_meta_data('_sp_subscription_data', $cart_data['subscription_data']);
        } else {
            $order->update_meta_data('_sp_is_subscription', 'no');
        }
        
        // Store cart items in order meta
        $order->update_meta_data('_sp_cart_items', $cart_data['items']);
        $order->save();
        
        // Prepare purchase session data. The session name is shown as the
        // title in the hosted checkout, so we keep it short — just the order
        // number. Itemized product names live in the `details` field below
        // (rendered as a line-item list on the hosted checkout).
        // `get_order_number()` respects plugins like Sequential Order Numbers
        // that rewrite the displayed order number.
        $session_name = 'Order #' . $order->get_order_number();

        // Build ABSOLUTE redirect URLs for the payment provider.
        // We intentionally bypass $this->get_return_url() here because the
        // `woocommerce_get_return_url` filter (themes/plugins) can mutate the
        // URL into something the payment provider can't redirect to
        // (relative path, wrong host, missing order key, etc.).
        $success_url = $order->get_checkout_order_received_url();
        $cancel_url  = wc_get_checkout_url();
        $failure_url = wc_get_checkout_url();

        // Guarantee each URL is absolute (has scheme + host) so the provider
        // can perform a top-level redirect cross-origin.
        $ensure_absolute = function($url) {
            if (empty($url)) {
                return home_url('/');
            }
            if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
                $url = home_url($url);
            }
            return $url;
        };
        $success_url = $ensure_absolute($success_url);
        $cancel_url  = $ensure_absolute($cancel_url);
        $failure_url = $ensure_absolute($failure_url);

        error_log('PP Gateway: success_url sent to provider: ' . $success_url);
        error_log('PP Gateway: cancel_url sent to provider: ' . $cancel_url);
        error_log('PP Gateway: failure_url sent to provider: ' . $failure_url);

        $session_data = array(
            'name' => $session_name,
            'details' => $this->get_order_details_text($order, $cart_data),
            'currency' => $cart_data['currency'],
            'amount' => $cart_data['total'],
            'recurring' => $cart_data['has_subscription'],
            'metadata' => array(
                'payment_gateway' => 'stablecoin_pay', // Identifier for data/analytics purposes
                'payment_type' => 'stablecoin_pay', // Payment type identifier
                'woocommerce_order_id' => $order->get_id(),
                'cart_items' => $cart_data['items'],
                'subtotal' => $cart_data['subtotal'],
                'shipping' => $cart_data['shipping'],
                'tax' => $cart_data['tax'],
                'discount' => isset($cart_data['discount']) ? $cart_data['discount'] : 0,
                'discount_tax' => isset($cart_data['discount_tax']) ? $cart_data['discount_tax'] : 0,
                'fees' => isset($cart_data['fees']) ? $cart_data['fees'] : 0,
                'fee_details' => isset($cart_data['fee_details']) ? $cart_data['fee_details'] : array(),
                'applied_coupons' => isset($cart_data['applied_coupons']) ? $cart_data['applied_coupons'] : array(),
                'coupon_details' => isset($cart_data['coupon_details']) ? $cart_data['coupon_details'] : array(),
                'total' => $cart_data['total'],
                'billing_address' => array(
                    'first_name' => $order->get_billing_first_name(),
                    'last_name' => $order->get_billing_last_name(),
                    'email' => $order->get_billing_email(),
                    'phone' => $order->get_billing_phone(),
                    'address_1' => $order->get_billing_address_1(),
                    'city' => $order->get_billing_city(),
                    'state' => $order->get_billing_state(),
                    'postcode' => $order->get_billing_postcode(),
                    'country' => $order->get_billing_country()
                ),
                'shipping_address' => array(
                    'first_name' => $order->get_shipping_first_name(),
                    'last_name' => $order->get_shipping_last_name(),
                    'address_1' => $order->get_shipping_address_1(),
                    'city' => $order->get_shipping_city(),
                    'state' => $order->get_shipping_state(),
                    'postcode' => $order->get_shipping_postcode(),
                    'country' => $order->get_shipping_country()
                )
            ),
            'success_url' => $success_url,
            'cancel_url' => $cancel_url,
            'failure_url' => $failure_url
        );
        
        // Add subscription fields if recurring
        if ($cart_data['has_subscription'] && $cart_data['subscription_data']) {
            $freq = $cart_data['subscription_data']['frequency'];
            $intr = $cart_data['subscription_data']['interval'];
            $dur = $cart_data['subscription_data']['duration'];

            // Map frequency number -> label (example expects labels like "Every", "Every Other")
            $frequency_map = array(
                '1' => 'Every',
                '2' => 'Every Other',
                '3' => 'Every Third',
                '4' => 'Every Fourth',
                '5' => 'Every Fifth',
                '6' => 'Every Sixth',
                '7' => 'Every Seventh',
            );
            $freq_label = isset($frequency_map[(string)$freq]) ? $frequency_map[(string)$freq] : 'Every';

            // Normalize interval to Capitalized label for API (Day/Week/Month/Year) per working example
            $interval_cap_map = array(
                '0' => 'Day', 'day' => 'Day', 'Day' => 'Day',
                '1' => 'Week', 'week' => 'Week', 'Week' => 'Week',
                '2' => 'Month', 'month' => 'Month', 'Month' => 'Month',
                '3' => 'Year', 'year' => 'Year', 'Year' => 'Year',
            );
            $intr_key = (string) $intr;
            $intr_key = isset($interval_cap_map[$intr_key]) ? $intr_key : strtolower(trim($intr_key));
            $intr_out = isset($interval_cap_map[$intr_key]) ? $interval_cap_map[$intr_key] : 'Month';

            // Build payload matching the working example
            $session_data['frequency'] = $freq_label;          // e.g., "Every"
            $session_data['interval'] = $intr_out;             // e.g., "Week"
            $session_data['Duration'] = (string) $dur;         // capital D per example
            $session_data['duration'] = (string) $dur;         // keep lowercase for backward compat
            $session_data['metadata']['subscription_data'] = $cart_data['subscription_data'];
        }
        
        return $session_data;
    }
    
    /**
     * Get order details text for purchase session
     * Includes discount information if applicable
     */
    private function get_order_details_text($order, $cart_data) {
        $details = array();
        
        foreach ($cart_data['items'] as $item) {
            // Use discounted price if available, otherwise use original price
            $item_price = isset($item['line_total']) ? $item['line_total'] : (isset($item['total']) ? $item['total'] : $item['price']);
            $details[] = $item['quantity'] . 'x ' . $item['name'] . ' ($' . number_format($item_price, 2) . ')';
        }
        
        // Add discount information if coupons were applied
        if (isset($cart_data['discount']) && $cart_data['discount'] > 0) {
            $discount_text = 'Discount: -$' . number_format($cart_data['discount'], 2);
            if (isset($cart_data['applied_coupons']) && !empty($cart_data['applied_coupons'])) {
                $discount_text .= ' (' . implode(', ', $cart_data['applied_coupons']) . ')';
            }
            $details[] = $discount_text;
        }
        
        // Add fees if any
        if (isset($cart_data['fees']) && $cart_data['fees'] > 0) {
            if (isset($cart_data['fee_details']) && !empty($cart_data['fee_details'])) {
                foreach ($cart_data['fee_details'] as $fee) {
                    $details[] = $fee['name'] . ': $' . number_format($fee['amount'], 2);
                }
            } else {
                $details[] = 'Fees: $' . number_format($cart_data['fees'], 2);
            }
        }
        
        if ($cart_data['shipping'] > 0) {
            $details[] = 'Shipping: $' . number_format($cart_data['shipping'], 2);
        }
        
        if ($cart_data['tax'] > 0) {
            $details[] = 'Tax: $' . number_format($cart_data['tax'], 2);
        }
        
        return implode(', ', $details);
    }
    
    /**
     * Ensure WooCommerce checkout page has [woocommerce_checkout] shortcode
     * Called automatically when gateway settings are saved
     * Only runs if gateway is being enabled
     */
    public function ensure_checkout_shortcode_on_save() {
        // Check if gateway is being enabled (from POST or current setting)
        $enabled = isset($_POST['woocommerce_sp_enabled']) 
            ? sanitize_text_field($_POST['woocommerce_sp_enabled']) 
            : $this->enabled;
        
        if ($enabled === 'yes') {
            // Call the function from main plugin file
            if (function_exists('sp_ensure_checkout_shortcode')) {
                sp_ensure_checkout_shortcode();
            }
        }
    }
    
    /**
     * When user returns to checkout with an empty cart but we have a pending Stablecoin Pay order in session,
     * restore the cart from that order so they can place a new order and restart payment.
     * We do NOT reuse the old checkout URL or order for payment – next "Pay" creates a fresh order and purchase session.
     * The previous pending order stays in the system for tracking.
     */
    public function maybe_restore_cart_from_pending_order() {
        if (!function_exists('WC') || !WC()->session || !WC()->cart) {
            return;
        }
        $pending_order_id = WC()->session->get('sp_pending_order_id');
        if (empty($pending_order_id) || !WC()->cart->is_empty()) {
            return;
        }
        $order = wc_get_order($pending_order_id);
        if (!$order || $order->get_payment_method() !== 'sp' || !in_array($order->get_status(), array('pending', 'on-hold'), true)) {
            return;
        }
        $restored = 0;
        foreach ($order->get_items() as $item) {
            if (!$item->is_type('line_item')) {
                continue;
            }
            $product = $item->get_product();
            if (!$product || !$product->is_purchasable()) {
                continue;
            }
            $qty = (int) $item->get_quantity();
            if ($qty < 1) {
                continue;
            }
            $variation_id = $item->get_variation_id();
            $variation = array();
            if ($variation_id) {
                foreach ($item->get_meta_data() as $meta) {
                    if (strpos($meta->key, 'attribute_') === 0) {
                        $variation[$meta->key] = $meta->value;
                    }
                }
            }
            $added = WC()->cart->add_to_cart($product->get_id(), $qty, $variation_id, $variation);
            if ($added) {
                $restored++;
            }
        }
        if ($restored > 0) {
            WC()->session->set('sp_pending_order_id', null);
            wc_add_notice(__('Your cart has been restored. Please place your order again to continue to payment.', 'stablecoin-pay'), 'notice');
        }
    }
}
