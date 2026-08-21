<?php
/**
 * Payment Provider Admin Log Viewer
 * View debug logs directly in WordPress admin (whitelabel-friendly: no platform name in UI).
 */

if (!defined('ABSPATH')) {
    exit;
}

class SP_Admin_Logs {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }
    
    /**
     * Add menu under WooCommerce
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Payment Provider Logs',
            'Payment Provider Logs',
            'manage_woocommerce',
            'sp-logs',
            array($this, 'display_logs_page')
        );
    }
    
    /**
     * Display logs page
     */
    public function display_logs_page() {
        // Handle clear action
        if (isset($_GET['action']) && $_GET['action'] === 'clear' && check_admin_referer('sp-clear-logs')) {
            $this->clear_logs();
            echo '<div class="notice notice-success"><p>✅ Logs cleared!</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1>🔍 Payment Provider Debug Logs</h1>
            
            <div style="background: #fff; padding: 20px; border: 1px solid #ccc; margin: 20px 0;">
                <div style="margin: 20px 0;">
                    <a href="?page=sp-logs" class="button button-primary">🔄 Refresh</a>
                    <a href="?page=sp-logs&action=clear&_wpnonce=<?php echo wp_create_nonce('sp-clear-logs'); ?>" class="button" onclick="return confirm('Clear all logs?')">🗑️ Clear Logs</a>
                </div>
                
                <p><strong>Log File:</strong> <code><?php echo WP_CONTENT_DIR; ?>/debug.log</code></p>
                
                <?php
                $logs = $this->get_sp_logs(200);
                
                if (empty($logs)) {
                    echo '<div class="notice notice-warning"><p>⚠️ No payment provider logs found yet.</p></div>';
                    echo '<h3>Enable Debug Logging:</h3>';
                    echo '<p>Add this to your <code>wp-config.php</code> file:</p>';
                    echo '<pre style="background:#f5f5f5;padding:15px;border-radius:5px;overflow-x:auto;">define(\'WP_DEBUG\', true);
define(\'WP_DEBUG_LOG\', true);
define(\'WP_DEBUG_DISPLAY\', false);</pre>';
                    echo '<p><strong>Then:</strong> Go to checkout and try to place an order. Logs will appear here.</p>';
                } else {
                    echo '<h3>📋 Payment Provider Logs (Most Recent First):</h3>';
                    echo '<div style="background: #1e1e1e; color: #00ff00; padding: 20px; border-radius: 5px; font-family: monospace; font-size: 13px; overflow-x: auto; max-height: 600px; overflow-y: scroll; line-height: 1.6;">';
                    
                    foreach (array_reverse($logs) as $log_line) {
                        // Color code different types of logs
                        if (stripos($log_line, '✅') !== false || stripos($log_line, 'AVAILABLE') !== false) {
                            echo '<div style="color: #00ff00; padding: 3px;">' . esc_html($log_line) . '</div>';
                        } elseif (stripos($log_line, '❌') !== false || stripos($log_line, 'error') !== false) {
                            echo '<div style="color: #ff5555; padding: 3px; background: #3d1f1f;">' . esc_html($log_line) . '</div>';
                        } elseif (stripos($log_line, '🚀') !== false || stripos($log_line, 'Step') !== false) {
                            echo '<div style="color: #ffff00; padding: 3px;">' . esc_html($log_line) . '</div>';
                        } else {
                            echo '<div style="color: #aaa; padding: 3px;">' . esc_html($log_line) . '</div>';
                        }
                    }
                    
                    echo '</div>';
                    echo '<p style="margin-top: 15px;"><em>Showing last ' . count($logs) . ' payment provider log entries</em></p>';
                }
                ?>
            </div>
            
            <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0073aa; margin: 20px 0;">
                <h3>🎯 How to Use This:</h3>
                <ol>
                    <li>Make sure debug logging is enabled (see above)</li>
                    <li>Go to your store and try to checkout with your payment provider</li>
                    <li>Come back here and click "🔄 Refresh"</li>
                    <li>You'll see exactly what happened!</li>
                </ol>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get payment provider-related logs only (filters debug.log by plugin prefix).
     */
    private function get_sp_logs($max_lines = 200) {
        $log_file = WP_CONTENT_DIR . '/debug.log';
        
        if (!file_exists($log_file)) {
            return array();
        }
        
        $file_lines = file($log_file);
        if ($file_lines === false) {
            return array();
        }
        
        // Filter for payment provider plugin logs only (sp = option/class names; PP = log prefix)
        $sp_logs = array();
        foreach ($file_lines as $line) {
            if (stripos($line, 'stablecoin pay') !== false || stripos($line, 'PP ') !== false || stripos($line, 'PP API') !== false) {
                $sp_logs[] = $line;
            }
        }
        
        // Return last N lines
        return array_slice($sp_logs, -$max_lines);
    }
    
    /**
     * Clear logs
     */
    private function clear_logs() {
        $log_file = WP_CONTENT_DIR . '/debug.log';
        if (file_exists($log_file)) {
            file_put_contents($log_file, '');
        }
    }
}

