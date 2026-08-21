<?php
/**
 * Stablecoin Pay - Required Plugin Auto-Installer
 * 
 * Automatically installs and activates required plugins that are bundled with Stablecoin Pay.
 * This ensures all security dependencies (like email verification) are present.
 */

if (!defined('ABSPATH')) {
    exit;
}
// Plugin Installer Class
class SP_Plugin_Installer {
    
    /**
     * Required plugins to auto-install
     */
    private static $required_plugins = array(
        'emails-verification-for-woocommerce' => array(
            'name' => 'Email Verification for WooCommerce',
            'slug' => 'emails-verification-for-woocommerce',
            'main_file' => 'emails-verification-for-woocommerce/emails-verification-for-woocommerce.php',
            'bundled_zip' => 'emails-verification-for-woocommerce.zip',
            'required_for' => 'Email verification security (ensures users own their email addresses)',
        ),
    );
    
    /**
     * Initialize auto-installer
     */
    public static function init() {
        // Run on plugin activation
        register_activation_hook(SP_PLUGIN_FILE, array(__CLASS__, 'install_required_plugins'));
        
        // Check and show admin notices if something went wrong
        add_action('admin_notices', array(__CLASS__, 'check_required_plugins_status'));
    }
    
    /**
     * Install all required plugins on activation
     */
    public static function install_required_plugins() {
        error_log('🔧 Stablecoin Pay: Checking required plugins...');
        
        foreach (self::$required_plugins as $plugin) {
            self::install_plugin($plugin);
        }
    }
    
    /**
     * Install and activate a single plugin
     * 
     * @param array $plugin Plugin configuration
     */
    private static function install_plugin($plugin) {
        // Check if already installed and activated
        if (is_plugin_active($plugin['main_file'])) {
            error_log('✅ ' . $plugin['name'] . ' is already active');
            return true;
        }
        
        // Check if installed but not activated
        $installed_plugins = get_plugins();
        if (isset($installed_plugins[$plugin['main_file']])) {
            error_log('🔄 ' . $plugin['name'] . ' found but not active - activating...');
            $result = activate_plugin($plugin['main_file']);
            
            if (is_wp_error($result)) {
                error_log('❌ Failed to activate ' . $plugin['name'] . ': ' . $result->get_error_message());
                return false;
            }
            
            error_log('✅ ' . $plugin['name'] . ' activated successfully');
            return true;
        }
        
        // Plugin not installed - install from bundled ZIP
        error_log('📦 ' . $plugin['name'] . ' not found - installing from bundle...');
        
        $bundled_zip = SP_PLUGIN_DIR . 'bundled-plugins/' . $plugin['bundled_zip'];
        
        if (!file_exists($bundled_zip)) {
            error_log('❌ Bundled plugin ZIP not found: ' . $bundled_zip);
            return false;
        }
        
        // Install the plugin
        $result = self::install_plugin_from_zip($bundled_zip, $plugin);
        
        if ($result) {
            error_log('✅ ' . $plugin['name'] . ' installed and activated successfully');
            return true;
        }
        
        error_log('❌ Failed to install ' . $plugin['name']);
        return false;
    }
    
    /**
     * Install plugin from ZIP file
     * 
     * @param string $zip_path Path to ZIP file
     * @param array $plugin Plugin configuration
     * @return bool Success
     */
    private static function install_plugin_from_zip($zip_path, $plugin) {
        // Load WordPress upgrader
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        
        // Silent upgrader (no output)
        WP_Filesystem();
        
        $upgrader = new Plugin_Upgrader(new WP_Ajax_Upgrader_Skin());
        
        // Install from local ZIP
        $result = $upgrader->install($zip_path);
        
        if (is_wp_error($result)) {
            error_log('❌ Installation error: ' . $result->get_error_message());
            return false;
        }
        
        if (!$result) {
            error_log('❌ Installation failed (no error message)');
            return false;
        }
        
        // Refresh plugin list
        wp_cache_delete('plugins', 'plugins');
        
        // Activate the plugin
        $activate_result = activate_plugin($plugin['main_file']);
        
        if (is_wp_error($activate_result)) {
            error_log('❌ Activation error: ' . $activate_result->get_error_message());
            return false;
        }
        
        return true;
    }
    
    /**
     * Check status of required plugins and show admin notice if missing
     */
    public static function check_required_plugins_status() {
        $missing_plugins = array();
        
        foreach (self::$required_plugins as $plugin) {
            if (!is_plugin_active($plugin['main_file'])) {
                $missing_plugins[] = $plugin;
            }
        }
        
        if (!empty($missing_plugins)) {
            ?>
            <div class="notice notice-error">
                <p><strong>⚠️ Stablecoin Pay - Required Plugin Missing</strong></p>
                <p>The following required plugin could not be automatically installed:</p>
                <ul>
                    <?php foreach ($missing_plugins as $plugin): ?>
                        <li>
                            <strong><?php echo esc_html($plugin['name']); ?></strong><br>
                            <em>Required for: <?php echo esc_html($plugin['required_for']); ?></em>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p>
                    <strong>Please install manually:</strong><br>
                    Go to <strong>Plugins → Add New</strong>, search for "<?php echo esc_html($missing_plugins[0]['name']); ?>", and click Install & Activate.
                </p>
                <p><em>Stablecoin Pay requires this plugin for security - it ensures users verify their email addresses.</em></p>
            </div>
            <?php
        }
    }
    
    /**
     * Check if all required plugins are active
     * 
     * @return bool True if all required plugins are active
     */
    public static function all_required_plugins_active() {
        foreach (self::$required_plugins as $plugin) {
            if (!is_plugin_active($plugin['main_file'])) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Get list of required plugins
     * 
     * @return array Required plugins
     */
    public static function get_required_plugins() {
        return self::$required_plugins;
    }
}

// Initialize auto-installer
SP_Plugin_Installer::init();

