<?php
/**
 * Payment Provider Admin Payments Page
 * Merchant-facing payments management page (whitelabel-friendly: uses display company name).
 */

if (!defined('ABSPATH')) {
    exit;
}

class SP_Admin_Payments {
    
    private $api_client;
    
    public function __construct() {
        // Add menu item to WooCommerce
        add_action('admin_menu', array($this, 'add_admin_menu'), 55);
        
        // Add admin styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
        
        // AJAX handler for payment details
        add_action('wp_ajax_sp_get_payment_details', array($this, 'ajax_get_payment_details'));
    }
    
    /**
     * Get API client instance
     */
    private function get_api_client() {
        if ($this->api_client === null) {
            if (!class_exists('SP_API_Client')) {
                require_once plugin_dir_path(__FILE__) . 'class-sp-api-client.php';
            }
            $this->api_client = new SP_API_Client();
        }
        return $this->api_client;
    }
    
    /**
     * Get whitelabel branding class
     */
    private function get_branding() {
        if (!class_exists('SP_Whitelabel_Branding')) {
            require_once plugin_dir_path(__FILE__) . 'class-sp-whitelabel-branding.php';
        }
        return new SP_Whitelabel_Branding();
    }
    
    /**
     * Check if settings are saved (merchant ID and API key exist)
     */
    private function are_settings_saved() {
        $gateway_settings = get_option('woocommerce_sp_settings', array());
        $merchant_id = isset($gateway_settings['merchant_id']) ? trim($gateway_settings['merchant_id']) : '';
        $api_key = isset($gateway_settings['api_key']) ? trim($gateway_settings['api_key']) : '';
        return !empty($merchant_id) && !empty($api_key);
    }
    
    /**
     * Get company name for display. When whitelabel config is filled, use config only (no API/DB). Empty config = Stablecoin Pay.
     */
    private function get_display_company_name() {
        if (class_exists('SP_Whitelabel_Branding')) {
            $env_id = SP_Whitelabel_Branding::get_whitelabel_env_id_from_config();
            if (!empty($env_id)) {
                $name = SP_Whitelabel_Branding::get_whitelabel_plugin_name_from_config();
                return $name ?: __('Stablecoin Pay', 'stablecoin-pay');
            }
        }
        // Stablecoin Pay build: use API branding when settings saved, else default
        if (!$this->are_settings_saved()) {
            return __('Payment Provider', 'stablecoin-pay');
        }
        
        // Settings are saved - try to get whitelabel branding
        $branding = $this->get_branding();
        $branding_data = $branding->get_branding(false);
        return !empty($branding_data['company']) ? $branding_data['company'] : __('Payment Provider', 'stablecoin-pay');
    }
    
    /**
     * Add admin menu item
     */
    public function add_admin_menu() {
        $menu_title = $this->get_display_company_name() . ' ' . __('Payments', 'stablecoin-pay');
        
        add_submenu_page(
            'woocommerce',
            $menu_title,
            $menu_title,
            'manage_woocommerce',
            'sp-payments',
            array($this, 'render_payments_page')
        );
    }
    
    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_styles($hook) {
        if ($hook !== 'woocommerce_page_sp-payments') {
            return;
        }
        
        wp_enqueue_style('sp-admin', plugins_url('../assets/admin.css', __FILE__), array(), '1.0.0');
    }
    
    /**
     * Render payments management page
     */
    public function render_payments_page() {
        $page_title = $this->get_display_company_name() . ' ' . __('Payments', 'stablecoin-pay');
        
        // Get payments from API
        $api_client = $this->get_api_client();
        $payments_response = $api_client ? $api_client->get_all_payments() : null;
        
        if ($payments_response && !is_wp_error($payments_response)) {
            error_log('PP - Payments API response structure: ' . json_encode($payments_response));
        }
        
        $payments = array();
        if (!is_wp_error($payments_response)) {
            if (isset($payments_response['data']) && is_array($payments_response['data'])) {
            $payments = $payments_response['data'];
            } elseif (is_array($payments_response)) {
            // Sometimes the API might return the array directly without 'data' wrapper
            $payments = $payments_response;
            } else {
                // API returned something unexpected - log it
                error_log('PP - Payments: Unexpected API response format: ' . json_encode($payments_response));
            }
        } else {
            error_log('PP - Payments API error: ' . $payments_response->get_error_message());
        }
        
        // Match payments with WooCommerce orders
        $payments_with_orders = $this->match_payments_with_orders($payments);
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <?php /* Stablecoin Pay logo removed for whitelabel - only used in checkout as default fallback */ ?>
                <?php echo esc_html($page_title); ?>
            </h1>
            
            <hr class="wp-header-end">
            
            <?php if (is_wp_error($payments_response)): ?>
                <div class="notice notice-error" style="margin: 20px 0;">
                    <p><strong><?php _e('Error loading payments:', 'stablecoin-pay'); ?></strong> <?php echo esc_html($payments_response->get_error_message()); ?></p>
                    <p><?php printf(__('Please check your API credentials in WooCommerce > Settings > Payments > %s', 'stablecoin-pay'), esc_html($this->get_display_company_name())); ?></p>
                </div>
            <?php elseif (empty($payments_with_orders)): ?>
                <div class="notice notice-info" style="margin: 20px 0;">
                    <p><?php _e('No payments found.', 'stablecoin-pay'); ?></p>
                </div>
            <?php else: ?>
                <div class="sp-table-scroll">
                    <table class="wp-list-table widefat fixed striped" style="margin-top: 20px; min-width: 900px;">
                        <thead>
                            <tr>
                                <th style="width: 50px;"><?php _e('Order', 'stablecoin-pay'); ?></th>
                                <th style="width: 300px;"><?php _e('Customer', 'stablecoin-pay'); ?></th>
                                <th style="width: 100px;"><?php _e('Amount', 'stablecoin-pay'); ?></th>
                                <th style="width: 100px;"><?php _e('Status', 'stablecoin-pay'); ?></th>
                                <th style="width: 150px;"><?php _e('Transaction Hash', 'stablecoin-pay'); ?></th>
                                <th><?php _e('Date', 'stablecoin-pay'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments_with_orders as $payment): ?>
                            <tr style="height: 70px;">
                                <td style="vertical-align: middle; width: 50px;">
                                    <?php if ($payment['order']): ?>
                                        <a style="font-weight: bold;" href="<?php echo esc_url(admin_url('post.php?post=' . $payment['order']->get_id() . '&action=edit')); ?>">
                                            #<?php echo esc_html($payment['order']->get_order_number()); ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #999;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="vertical-align: middle; width: 320px;">
                                    <?php if ($payment['order']): ?>
                                        <?php echo esc_html($payment['customer_name']); ?><br>
                                        <small style="color: #222; font-size: 13px;"><?php echo esc_html($payment['customer_email']); ?></small>
                                    <?php else: ?>
                                        <?php echo esc_html($payment['customer_email'] ?? $payment['customer_name'] ?? '—'); ?>
                                    <?php endif; ?>
                                </td>
                                <td style="vertical-align: middle; width: 100px;">
                                    <?php 
                                    $amount = isset($payment['amount']) ? (float)$payment['amount'] : 0;
                                    $currency = isset($payment['currency']) ? $payment['currency'] : 'USD';
    
                                    $formatted_amount = number_format($amount, 2, '.', ',');
    
                                    echo $formatted_amount . ' ' . esc_html($currency);
                                    ?>
                                </td>
                                <td style="vertical-align: middle; width: 100px;">
                                    <span style="font-weight: bold;" class="payment-status status-<?php echo esc_attr(strtolower($payment['status'] ?? 'unknown')); ?>">
                                        <?php echo esc_html(ucfirst($payment['status'] ?? 'Unknown')); ?>
                                    </span>
                                </td>
                                <td style="vertical-align: middle; width: 150px;">
                                    <?php if (!empty($payment['transaction_hash'])): ?>
                                        <a style="font-weight: bold; text-decoration: underline;" href="<?php echo esc_url($payment['block_explorer_url']); ?>" target="_blank" > 
                                            <?php echo esc_html(substr($payment['transaction_hash'], 0, 10)) . '...'; ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #999;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="vertical-align: middle;">
                                    <?php 
                                    if (!empty($payment['created_at'])) {
                                        $ts = is_numeric($payment['created_at']) ? (int)$payment['created_at'] : strtotime($payment['created_at']);
                                        
                                        if ($ts) {
                                            $date = date_i18n('M d, Y', $ts);       // e.g., Nov 18, 2026
                                            $time = date_i18n('h:i:s A', $ts);      // e.g., 03:37:27 PM
                                            echo esc_html($date) . "<br>" . esc_html($time);
                                        } else {
                                            echo esc_html($payment['created_at']);
                                        }
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        
        <style>
        .payment-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-completed, .status-success, .status-paid {
            background: #d4edda;
            color: #155724;
        }
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        .status-failed, .status-error {
            background: #f8d7da;
            color: #721c24;
        }
        .status-unknown {
            background: #e2e3e5;
            color: #383d41;
        }
        .sp-table-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        
        </style>
        
        <?php
    }
    
    /**
     * Match payments from API with WooCommerce orders
     */
    private function match_payments_with_orders($payments) {
        $matched_payments = array();
        
        // Ensure $payments is an array
        if (!is_array($payments)) {
            error_log('PP - Payments: $payments is not an array, returning empty');
            return array();
        }
        
        foreach ($payments as $payment) {
            // Skip if payment is not an array
            if (!is_array($payment)) {
                error_log('PP - Payments: Skipping invalid payment item (not an array)');
                continue;
            }
            
            $order = null;
            $customer_name = '';
            $customer_email = '';
            
            // Try to match by order ID in payment metadata
            if (isset($payment['metadata']) && isset($payment['metadata']['woocommerce_order_id'])) {
                $order_id = absint($payment['metadata']['woocommerce_order_id']);
                $order = wc_get_order($order_id);
            }
            
            // Try to match by purchase session ID
            if (!$order && isset($payment['purchase_session_id'])) {
                $orders = wc_get_orders(array(
                    'meta_key' => '_sp_purchase_session_id',
                    'meta_value' => $payment['purchase_session_id'],
                    'limit' => 1
                ));
                
                if (!empty($orders)) {
                    $order = $orders[0];
                }
            }
            
            // Try to match by payment ID stored in order meta
            if (!$order && isset($payment['payment_id'])) {
                $orders = wc_get_orders(array(
                    'meta_key' => '_sp_payment_id',
                    'meta_value' => $payment['payment_id'],
                    'limit' => 1
                ));
                
                if (!empty($orders)) {
                    $order = $orders[0];
                }
            }
            
            // Get customer info
            if ($order) {
                $customer_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
                $customer_email = $order->get_billing_email();
            } elseif (isset($payment['customer_email'])) {
                $customer_email = $payment['customer_email'];
                $customer_name = $payment['customer_name'] ?? '';
            }
            
            // Extract created_at from payment - check multiple possible field names and locations
            $created_at = '';
            
            // Check direct field
            if (isset($payment['created_at']) && !empty($payment['created_at'])) {
                $created_at = $payment['created_at'];
            }
            // Check alternative field names
            elseif (isset($payment['createdAt']) && !empty($payment['createdAt'])) {
                $created_at = $payment['createdAt'];
            }
            elseif (isset($payment['date']) && !empty($payment['date'])) {
                $created_at = $payment['date'];
            }
            elseif (isset($payment['timestamp']) && !empty($payment['timestamp'])) {
                $created_at = $payment['timestamp'];
            }
            // Check if nested in data object
            elseif (isset($payment['data']['created_at']) && !empty($payment['data']['created_at'])) {
                $created_at = $payment['data']['created_at'];
            } elseif (isset($payment['data']['createdAt']) && !empty($payment['data']['createdAt'])) {
                $created_at = $payment['data']['createdAt'];
            } elseif (isset($payment['created']) && !empty($payment['created'])) { // some APIs use 'created'
                $created_at = $payment['created'];
            } elseif (isset($payment['attributes']['created_at']) && !empty($payment['attributes']['created_at'])) {
                $created_at = $payment['attributes']['created_at'];
            } elseif (isset($payment['attributes']['createdAt']) && !empty($payment['attributes']['createdAt'])) {
                $created_at = $payment['attributes']['createdAt'];
            }

            // Fallback to transaction_date variants if created_at not present
            if (empty($created_at)) {
                if (isset($payment['transaction_date']) && !empty($payment['transaction_date'])) {
                    $created_at = $payment['transaction_date'];
                } elseif (isset($payment['transactionDate']) && !empty($payment['transactionDate'])) {
                    $created_at = $payment['transactionDate'];
                } elseif (isset($payment['data']['transaction_date']) && !empty($payment['data']['transaction_date'])) {
                    $created_at = $payment['data']['transaction_date'];
                } elseif (isset($payment['attributes']['transaction_date']) && !empty($payment['attributes']['transaction_date'])) {
                    $created_at = $payment['attributes']['transaction_date'];
                }
            }
            
            // Log for debugging if still empty
            if (empty($created_at)) {
                if (is_array($payment)) {
                error_log('PP - Payment data keys: ' . json_encode(array_keys($payment)));
                error_log('PP - Full payment data: ' . json_encode($payment));
                } else {
                    error_log('PP - Payment data is not an array: ' . json_encode($payment));
                }
            }
            
            // Normalize milliseconds timestamps to seconds if needed
            if (is_numeric($created_at)) {
                $created_num = (int)$created_at;
                if ($created_num > 20000000000) { // > year 2603 in seconds -> likely ms
                    $created_at = (int) floor($created_num / 1000);
                }
            }

            $matched_payments[] = array(
                'payment_id' => $payment['payment_id'] ?? $payment['id'] ?? '',
                'order' => $order,
                'customer_name' => $customer_name,
                'customer_email' => $customer_email,
                'amount' => $payment['amount'] ?? 0,
                'currency' => $payment['currency'] ?? 'USD',
                'status' => $payment['status'] ?? 'unknown',
                'transaction_hash' => $payment['transaction_hash'] ?? $payment['tx_hash'] ?? '',
                'block_explorer_url' => $payment['block_explorer_url'] ?? '',
                'created_at' => $created_at
            );
        }
        
        // Sort by date (newest first)
        usort($matched_payments, function($a, $b) {
            $date_a = !empty($a['created_at']) ? (is_numeric($a['created_at']) ? $a['created_at'] : strtotime($a['created_at'])) : 0;
            $date_b = !empty($b['created_at']) ? (is_numeric($b['created_at']) ? $b['created_at'] : strtotime($b['created_at'])) : 0;
            return $date_b - $date_a;
        });
        
        return $matched_payments;
    }
    
    /**
     * AJAX handler to get payment details
     */
    public function ajax_get_payment_details() {
        check_ajax_referer('sp_payment_details', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'stablecoin-pay')));
            return;
        }
        
        $payment_id = sanitize_text_field($_POST['payment_id']);
        
        $api_client = $this->get_api_client();
        if (!$api_client) {
            wp_send_json_error(array('message' => __('API client not available', 'stablecoin-pay')));
            return;
        }
        
        $result = $api_client->get_payment_details($payment_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
            return;
        }
        
        // Format payment details for display
        $payment_data = isset($result['data']) ? $result['data'] : $result;
        $html = $this->format_payment_details_html($payment_data);
        
        wp_send_json_success(array('html' => $html));
    }
    
    /**
     * Format payment details as HTML table
     */
    private function format_payment_details_html($payment_data) {
        $html = '<table>';
        
        $fields = array(
            'payment_id' => 'Payment ID',
            'id' => 'Payment ID',
            'amount' => 'Amount',
            'currency' => 'Currency',
            'status' => 'Status',
            'transaction_hash' => 'Transaction Hash',
            'tx_hash' => 'Transaction Hash',
            'chain_id' => 'Chain ID',
            'token' => 'Token',
            'customer_email' => 'Customer Email',
            'customer_wallet' => 'Customer Wallet',
            'created_at' => 'Created At',
            'date' => 'Date',
            'purchase_session_id' => 'Purchase Session ID'
        );
        
        foreach ($fields as $key => $label) {
            if (isset($payment_data[$key])) {
                $value = $payment_data[$key];
                
                // Format dates
                if (in_array($key, array('created_at', 'date')) && is_numeric($value)) {
                    $value = date('Y-m-d H:i:s', $value);
                }
                
                // Format amounts
                if ($key === 'amount' && is_numeric($value)) {
                    $currency = isset($payment_data['currency']) ? $payment_data['currency'] : 'USD';
                    $value = wc_price($value, array('currency' => $currency));
                }
                
                $html .= '<tr><td>' . esc_html($label) . '</td><td>' . esc_html($value) . '</td></tr>';
            }
        }
        
        // Add metadata if present
        if (isset($payment_data['metadata']) && is_array($payment_data['metadata'])) {
            $html .= '<tr><td colspan="2"><strong>Metadata:</strong></td></tr>';
            foreach ($payment_data['metadata'] as $key => $value) {
                $html .= '<tr><td style="padding-left: 20px;">' . esc_html($key) . '</td><td>' . esc_html(is_array($value) ? json_encode($value) : $value) . '</td></tr>';
            }
        }
        
        $html .= '</table>';
        
        return $html;
    }
}

// Initialize
new SP_Admin_Payments();

