<?php
/**
 * Stablecoin Pay Order Manager
 * 
 * Handles order status updates and management
 */

if (!defined('ABSPATH')) {
    exit;
}

class SP_Order_Manager {

    /**
     * Dedupe rendering when both Woo billing + shipping admin hooks fire.
     *
     * @var array<int, bool>
     */
    private static $admin_subscription_summary_done = array();

    /**
     * Dedupe renewal / payments blocks for the same order.
     *
     * @var array<int, bool>
     */
    private static $admin_subscription_extras_done = array();

    /** Cron hook for the missed-webhook sweep. */
    const POLL_HOOK = 'sp_poll_pending_payments';

    /** Seconds between sweeps. */
    const POLL_INTERVAL = 300;

    /** Minimum seconds between provider status checks for the same order. */
    const POLL_THROTTLE = 120;

    /** How far back a sweep looks. Past this an unpaid order is left alone. */
    const POLL_MAX_AGE = DAY_IN_SECONDS;

    /** Orders examined per sweep. */
    const POLL_BATCH = 20;

    /**
     * Constructor
     */
    public function __construct() {
        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 10, 3);
        add_action('wp_ajax_sp_admin_cancel_subscription', array($this, 'ajax_admin_cancel_subscription'));

        // The webhook is the fast path; polling is the guarantee. A delivery that is
        // never sent, rejected on signature, or can't be matched to an order otherwise
        // strands a payment that has already settled on-chain. So ask the provider
        // directly: when the customer is looking at the order, and on a sweep for the
        // ones who never return to the site.
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
        add_action('woocommerce_thankyou', array($this, 'poll_on_order_page'), 5, 1);
        add_action('woocommerce_view_order', array($this, 'poll_on_order_page'), 5, 1);
        add_action(self::POLL_HOOK, array($this, 'poll_pending_orders'));
        self::schedule_polling();

        // WooCommerce emails print the payment method title that was stored on the
        // order at checkout. That value is a snapshot, so any order placed before a
        // rebrand keeps naming the old provider - in customer-facing email, long
        // after the plugin itself was white-labelled. Resolve it at render time so
        // every order, past and present, shows the current partner name.
        add_filter('woocommerce_order_get_payment_method_title', array($this, 'whitelabel_payment_method_title'), 10, 2);

        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_subscription_status'));
        add_action('woocommerce_admin_order_data_after_shipping_address', array($this, 'display_subscription_status'));

        add_action('admin_footer', array($this, 'print_admin_subscription_cancel_script'), 99);
        // NOTE: The "Payment / Transaction: 0x…🔗" panel that previously
        // appeared after the billing address has been removed. The
        // transaction hash is still stored in order meta
        // (`_sp_transaction_hash`) so it's available to other
        // tooling / reporting / refund flows, but it no longer clutters
        // the admin order details view.
    }
    
    /**
     * Handle order status changes
     */
    public function handle_order_status_change($order_id, $old_status, $new_status) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        // Check if this is a Stablecoin Pay order
        $purchase_session_id = $order->get_meta('_sp_purchase_session_id');
        if (empty($purchase_session_id)) {
            return;
        }
        
        // Handle specific status changes
        switch ($new_status) {
            case 'cancelled':
                $this->handle_order_cancellation($order);
                break;
                
            case 'refunded':
                $this->handle_order_refund($order);
                break;
        }
    }
    
    /**
     * Handle order cancellation
     */
    private function handle_order_cancellation($order) {
        $purchase_session_id = $order->get_meta('_sp_purchase_session_id');
        
        if (empty($purchase_session_id)) {
            return;
        }
        
        // Add order note
        $order->add_order_note(__('Order cancelled - payment provider session may still be active', 'stablecoin-pay'));
        
       
    }
    
    /**
     * Handle order refund
     */
    private function handle_order_refund($order) {
        $purchase_session_id = $order->get_meta('_sp_purchase_session_id');
        
        if (empty($purchase_session_id)) {
            return;
        }
        
        // Add order note
        $order->add_order_note(__('Order refunded - payment may need manual refund processing', 'stablecoin-pay'));
    }
    
    /**
     * Display Stablecoin Pay information in admin order page
     */
    public function display_sp_info($order) {
        $transaction_hash = $order->get_meta('_sp_transaction_hash');
        $payment_id = $order->get_meta('_sp_payment_id');
        $chain_id = $order->get_meta('_sp_chain_id');
        
        // Only show if payment is complete
        if (empty($transaction_hash) && empty($payment_id)) {
            return;
        }
        
        // Get blockchain explorer URL (pass order to access webhook metadata)
        $explorer_url = $this->get_explorer_url($chain_id, $transaction_hash, $order);
        
        ?>
        <div class="address">
            <p><strong><?php _e('Payment', 'stablecoin-pay'); ?></strong></p>
            
            <?php if ($payment_id): ?>
            
            <?php endif; ?>
            
            <?php if ($transaction_hash): ?>
            <p>
                <strong><?php _e('Transaction:', 'stablecoin-pay'); ?></strong><br>
                <a href="<?php echo esc_url($explorer_url); ?>" target="_blank" style="color: #3b82f6;">
                    <?php echo esc_html(substr($transaction_hash, 0, 10) . '...' . substr($transaction_hash, -8)); ?> 🔗
                </a>
            </p>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Renders Subscription summary once per order edit screen (billing or shipping hook).
     *
     * @param WC_Order $order
     */
    private function maybe_render_admin_subscription_summary($order) {
        $id = $order->get_id();
        if (!empty(self::$admin_subscription_summary_done[$id])) {
            return;
        }
        if ($order->get_meta('_sp_is_subscription') !== 'yes') {
            return;
        }
        if (!class_exists('SP_Subscriptions')) {
            return;
        }
        self::$admin_subscription_summary_done[$id] = true;
        SP_Subscriptions::instance()->render_subscription_order_panel($order, 'admin');
    }

    /**
     * One admin footer handler for merchants cancelling subscriptions from the edit-order screen.
     */
    public function print_admin_subscription_cancel_script() {
        if (!current_user_can('edit_shop_orders')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            return;
        }
        $allowed = (
            ($screen->id === 'shop_order')
            || ($screen->id === 'woocommerce_page_wc-orders')
        );
        if (!$allowed) {
            return;
        }
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        ?>
        <script>
        jQuery(function($) {
            $(document.body).on('click', '.sp-admin-cancel-subscription', function(e) {
                e.preventDefault();
                if (!confirm('<?php echo esc_js(__('Are you sure you want to cancel this subscription?', 'stablecoin-pay')); ?>')) {
                    return;
                }
                var button = $(this);
                button.prop('disabled', true).text('<?php echo esc_js(__('Cancelling…', 'stablecoin-pay')); ?>');
                $.ajax({
                    url: typeof ajaxurl !== 'undefined' ? ajaxurl : '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'sp_admin_cancel_subscription',
                        order_id: button.data('order-id'),
                        agreement_id: button.data('agreement-id'),
                        nonce: '<?php echo esc_js(wp_create_nonce('sp_admin_cancel')); ?>'
                    }
                }).done(function(response) {
                    if (response && response.success) {
                        alert('<?php echo esc_js(__('Subscription cancelled successfully', 'stablecoin-pay')); ?>');
                        location.reload();
                    } else {
                        var msg = (response && response.data && response.data.message) ? response.data.message : '';
                        alert(msg || '<?php echo esc_js(__('Could not cancel subscription', 'stablecoin-pay')); ?>');
                        button.prop('disabled', false).text('<?php echo esc_js(__('Cancel subscription', 'stablecoin-pay')); ?>');
                    }
                }).fail(function() {
                    alert('<?php echo esc_js(__('Error cancelling subscription', 'stablecoin-pay')); ?>');
                    button.prop('disabled', false).text('<?php echo esc_js(__('Cancel subscription', 'stablecoin-pay')); ?>');
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX handler for admin subscription cancellation
     */
    public function ajax_admin_cancel_subscription() {
        check_ajax_referer('sp_admin_cancel', 'nonce');
        
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'stablecoin-pay')));
        }
        
        $order_id = absint($_POST['order_id']);
        $agreement_id = sanitize_text_field($_POST['agreement_id']);
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('Invalid order', 'stablecoin-pay')));
        }
        
     
        if (!class_exists('SP_API_Client')) {
            wp_send_json_error(array('message' => __('API client not available', 'stablecoin-pay')));
        }
        
        $api_client = new SP_API_Client();
        $result = $api_client->cancel_agreement($agreement_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        // Update order meta
        $order->update_meta_data('_sp_subscription_status', 'cancelled');
        $order->update_meta_data('_sp_cancelled_at', current_time('mysql'));
        $order->add_order_note(__('Subscription cancelled by merchant', 'stablecoin-pay'));
        $order->save();
        
        // Add HTML message to order details
        $this->add_subscription_cancelled_message($order);
        
        // Fetch and display payments for this subscription
        $this->add_subscription_payments_section($order, $agreement_id);
        
        wp_send_json_success(array('message' => __('Subscription cancelled successfully', 'stablecoin-pay')));
    }
    
    /**
     * Add subscription cancelled message to order details
     */
    private function add_subscription_cancelled_message($order) {
        $cancelled_message = $order->get_meta('_sp_cancelled_message');
        if (empty($cancelled_message)) {
            $html_message = '<div class="sp-subscription-cancelled" style="background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; margin: 10px 0; border-radius: 5px;">
                <h4 style="margin: 0 0 10px 0; color: #721c24;">📋 Subscription Status</h4>
                <p style="margin: 0; font-weight: bold;">❌ SUBSCRIPTION CANCELLED</p>
                <p style="margin: 5px 0 0 0; font-size: 14px;">This subscription has been cancelled and will not process future payments.</p>
            </div>';
            
            $order->update_meta_data('_sp_cancelled_message', $html_message);
            $order->save();
        }
    }
    
    /**
     * Add subscription payments section to order details
     */
    private function add_subscription_payments_section($order, $agreement_id) {
        $api_client = new SP_API_Client();
        $payments = $api_client->get_all_payments();
        
        if (is_wp_error($payments)) {
            error_log('PP Order Manager: Failed to fetch payments: ' . $payments->get_error_message());
            return;
        }
        
        // Filter payments for this agreement (if agreement_id is available in payment data)
        $agreement_payments = array();
        if (is_array($payments)) {
            foreach ($payments as $payment) {
                // Check if this payment belongs to the cancelled agreement
                if (isset($payment['agreement_id']) && $payment['agreement_id'] === $agreement_id) {
                    $agreement_payments[] = $payment;
                }
            }
        }
        
        if (!empty($agreement_payments)) {
            $payments_html = $this->generate_payments_html($agreement_payments);
            $order->update_meta_data('_sp_payments_display', $payments_html);
            $order->save();
        }
    }
    
    /**
     * Generate HTML for payments display
     */
    private function generate_payments_html($payments) {
        $html = '<div class="sp-payments-section" style="background: #f8f9fa; border: 1px solid #dee2e6; padding: 15px; margin: 10px 0; border-radius: 5px;">
            <h4 style="margin: 0 0 15px 0; color: #495057;">💳 Subscription Payments</h4>
            <div class="payments-table" style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                    <thead>
                        <tr style="background: #e9ecef;">
                            <th style="padding: 8px; text-align: left; border: 1px solid #dee2e6;">Payment ID</th>
                            <th style="padding: 8px; text-align: left; border: 1px solid #dee2e6;">Amount</th>
                            <th style="padding: 8px; text-align: left; border: 1px solid #dee2e6;">Status</th>
                            <th style="padding: 8px; text-align: left; border: 1px solid #dee2e6;">Date</th>
                        </tr>
                    </thead>
                    <tbody>';
        
        foreach ($payments as $payment) {
            $status_color = $payment['status'] === 'completed' ? '#28a745' : ($payment['status'] === 'failed' ? '#dc3545' : '#ffc107');
            $amount = isset($payment['amount']) ? '$' . number_format($payment['amount'], 2) : 'N/A';
            $date = isset($payment['created_at']) ? date('M j, Y g:i A', strtotime($payment['created_at'])) : 'N/A';
            
            $html .= '<tr>
                <td style="padding: 8px; border: 1px solid #dee2e6;">' . esc_html($payment['id'] ?? 'N/A') . '</td>
                <td style="padding: 8px; border: 1px solid #dee2e6;">' . esc_html($amount) . '</td>
                <td style="padding: 8px; border: 1px solid #dee2e6; color: ' . $status_color . '; font-weight: bold;">' . esc_html(ucfirst($payment['status'] ?? 'Unknown')) . '</td>
                <td style="padding: 8px; border: 1px solid #dee2e6;">' . esc_html($date) . '</td>
            </tr>';
        }
        
        $html .= '</tbody>
                </table>
            </div>
        </div>';
        
        return $html;
    }
    
    /**
     * Display subscription status and payments in order details
     */
    public function display_subscription_status($order) {
        if (!$order instanceof WC_Order || $order->get_payment_method() !== 'sp') {
            return;
        }

        // Strictly subscription-only: regular one-off Stablecoin Pay orders should
        // NOT see any subscription UI (summary, cancel button, renewal links,
        // payments table, or cancelled-state banner). Renewal/child orders
        // also carry `_sp_is_subscription === 'yes'` so they keep the
        // panel and the link back to the parent.
        if ($order->get_meta('_sp_is_subscription') !== 'yes') {
            return;
        }

        // Subscription snapshot + Cancel button (shown once whether billing/shipping fires).
        $this->maybe_render_admin_subscription_summary($order);

        $id = $order->get_id();
        if (!empty(self::$admin_subscription_extras_done[$id])) {
            return;
        }
        self::$admin_subscription_extras_done[$id] = true;

        // Display parent/child relationship if applicable
        $this->display_renewal_order_relationship($order);

        // Display cancelled message if exists
        $cancelled_message = $order->get_meta('_sp_cancelled_message');
        if (!empty($cancelled_message)) {
            echo $cancelled_message;
        }

        // Display payments if exists
        $payments_display = $order->get_meta('_sp_payments_display');
        if (!empty($payments_display)) {
            echo $payments_display;
        } else {
            $this->maybe_display_subscription_payments($order);
        }
    }
    
    /**
     * Display renewal order relationship (parent/child)
     */
    private function display_renewal_order_relationship($order) {
        $is_renewal = $order->get_meta('_sp_is_renewal_order') === 'yes';
        $is_subscription = $order->get_meta('_sp_is_subscription') === 'yes';
        
        $html = '';
        
        // If this is a renewal order, show parent link
        if ($is_renewal) {
            $parent_order_id = $order->get_meta('_sp_parent_subscription_order');
            if ($parent_order_id) {
                $parent_order = wc_get_order($parent_order_id);
                if ($parent_order) {
                    // get_edit_order_url() resolves to the right screen under both the
                    // legacy posts table and High-Performance Order Storage.
                    $parent_order_url = $parent_order->get_edit_order_url();
                    $html .= '<div class="sp-renewal-info" style="background: #e7f3ff; border: 1px solid #b3d9ff; padding: 15px; margin: 10px 0; border-radius: 5px;">';
                    $html .= '<h4 style="margin: 0 0 10px 0; color: #0056b3;">🔄 Renewal Order</h4>';
                    $html .= '<p style="margin: 0; font-size: 14px;">';
                    $html .= 'This is a renewal order for subscription order <strong><a href="' . esc_url($parent_order_url) . '">#' . $parent_order->get_order_number() . '</a></strong>.';
                    $html .= '</p>';
                    $html .= '</div>';
                }
            }
        }
        
        // If this is a subscription order, show all renewal orders
        if ($is_subscription) {
            $renewal_orders = $order->get_meta('_sp_renewal_orders');
            if (is_array($renewal_orders) && !empty($renewal_orders)) {
                $html .= '<div class="sp-renewal-orders" style="background: #f0f9ff; border: 1px solid #b3d9ff; padding: 15px; margin: 10px 0; border-radius: 5px;">';
                $html .= '<h4 style="margin: 0 0 10px 0; color: #0056b3;">📋 Renewal Orders (' . count($renewal_orders) . ')</h4>';
                $html .= '<ul style="margin: 10px 0 0 0; padding-left: 20px;">';
                
                foreach ($renewal_orders as $renewal_order_id) {
                    $renewal_order = wc_get_order($renewal_order_id);
                    if ($renewal_order) {
                        $renewal_order_url = $renewal_order->get_edit_order_url();
                        $renewal_status = $renewal_order->get_status();
                        $status_colors = array(
                            'processing' => '#0073aa',
                            'completed' => '#46b450',
                            'pending' => '#ffb900',
                            'on-hold' => '#ff922b',
                            'cancelled' => '#dc3232',
                            'failed' => '#dc3232',
                            'refunded' => '#999'
                        );
                        $status_color = isset($status_colors[$renewal_status]) ? $status_colors[$renewal_status] : '#666';
                        
                        $html .= '<li style="margin: 5px 0;">';
                        $html .= '<a href="' . esc_url($renewal_order_url) . '" style="color: ' . $status_color . '; font-weight: bold;">';
                        $html .= 'Order #' . $renewal_order->get_order_number();
                        $html .= '</a>';
                        $html .= ' - <span style="color: ' . $status_color . ';">' . ucfirst($renewal_status) . '</span>';
                        $html .= ' - <span style="color: #666; font-size: 12px;">' . $renewal_order->get_date_created()->date_i18n(get_option('date_format')) . '</span>';
                        $html .= '</li>';
                    }
                }
                
                $html .= '</ul>';
                $html .= '</div>';
            }
        }
        
        if (!empty($html)) {
            echo $html;
        }
    }

    /**
     * Maybe display subscription payments for active subscriptions
     */
    private function maybe_display_subscription_payments($order) {
        $is_subscription = $order->get_meta('_sp_is_subscription') === 'yes';
        $agreement_id = $order->get_meta('_sp_agreement_id');
        
        if (!$is_subscription || empty($agreement_id)) {
            return;
        }
        
        // Only fetch once per page load to avoid multiple API calls
        static $fetched_orders = array();
        if (in_array($order->get_id(), $fetched_orders)) {
            return;
        }
        $fetched_orders[] = $order->get_id();
        
        $api_client = new SP_API_Client();
        $payments = $api_client->get_all_payments();
        
        if (is_wp_error($payments)) {
            return;
        }
        
        // Filter payments for this agreement
        $agreement_payments = array();
        if (is_array($payments)) {
            foreach ($payments as $payment) {
                if (isset($payment['agreement_id']) && $payment['agreement_id'] === $agreement_id) {
                    $agreement_payments[] = $payment;
                }
            }
        }
        
        if (!empty($agreement_payments)) {
            $payments_html = $this->generate_payments_html($agreement_payments);
            echo $payments_html;
        }
    }

    /**
     * Get blockchain explorer URL dynamically from webhook data or chain_id
     * Uses stored explorer_url from webhook, network name, or generates from chain_id
     * Priority: explorer_url from webhook > network_name from webhook > chain_id mapping
     */
    private function get_explorer_url($chain_id, $transaction_hash, $order = null) {
        if (empty($transaction_hash)) {
            return '';
        }
        
        // Try to get order if not provided
        if (!$order) {
            global $post;
            if ($post && $post->post_type === 'shop_order') {
                $order = wc_get_order($post->ID);
            }
        }
        
        if ($order) {
            // Only use explorer_url directly from webhook - no hardcoded mappings
            $explorer_url = $order->get_meta('_sp_explorer_url');
            if (!empty($explorer_url)) {
                // Replace transaction hash placeholder if needed, or append hash
                if (strpos($explorer_url, '{hash}') !== false || strpos($explorer_url, '{tx}') !== false) {
                    $explorer_url = str_replace(array('{hash}', '{tx}'), $transaction_hash, $explorer_url);
                } elseif (strpos($explorer_url, $transaction_hash) === false) {
                    // If URL doesn't contain hash, append it
                    $explorer_url = rtrim($explorer_url, '/') . '/tx/' . $transaction_hash;
                }
                return $explorer_url;
            }
            
            // If webhook provided network name, construct URL using it
            $network_name = $order->get_meta('_sp_network_name');
            if (!empty($network_name)) {
                return $this->build_explorer_url_from_network($network_name, $transaction_hash);
            }

            // Try chain_id from order meta if not passed in
            if (empty($chain_id)) {
                $chain_id = $order->get_meta('_sp_chain_id');
            }
        }

        // Use chain_id mapping as final fallback
        if (!empty($chain_id)) {
            $network_from_chain = $this->get_network_from_chain_id($chain_id);
            if (!empty($network_from_chain)) {
                return $this->build_explorer_url_from_network($network_from_chain, $transaction_hash);
            }
        }
        
        // No data available to construct a link
        error_log('PP: Cannot build explorer URL - missing explorer_url/network_name/chain_id');
        return '';
    }
    
    /**
     * Build explorer URL from network name (from webhook)
     * Uses network name directly from webhook - no hardcoded mappings
     */
    private function build_explorer_url_from_network($network_name, $transaction_hash) {
        $network = strtolower(trim($network_name));
        // OKLink format with English locale
        return 'https://www.oklink.com/en/' . rawurlencode($network) . '/tx/' . rawurlencode($transaction_hash);
    }

    private function get_network_from_chain_id($chain_id) {
        // Normalize numeric value
        $id = (int) $chain_id;
        $map = array(
            1 => 'eth',           // Ethereum Mainnet
            10 => 'optimism',     // Optimism
            56 => 'bsc',          // BNB Smart Chain
            137 => 'polygon',     // Polygon Mainnet
            8453 => 'base',       // Base
            42161 => 'arbitrum-one', // Arbitrum One
            43114 => 'avalanche', // Avalanche C-Chain
            80002 => 'amoy',      // Polygon Amoy (testnet)
            11155111 => 'sepolia', // Ethereum Sepolia
            295 => 'hedera',      // Hedera Mainnet
            296 => 'hedera-testnet', // Hedera Testnet
        );
        return isset($map[$id]) ? $map[$id] : '';
    }
    
    /**
     * Render this gateway's payment method title using the current partner name.
     *
     * Applies to orders paid through this plugin only - including ones recorded
     * under the pre-rename gateway id, since those are exactly the orders most
     * likely to be carrying a stale provider name.
     *
     * @param string   $title Stored title.
     * @param WC_Order $order
     * @return string
     */
    public function whitelabel_payment_method_title($title, $order) {
        if (!$order instanceof WC_Order) {
            return $title;
        }

        $method = $order->get_payment_method();
        if ($method !== 'sp' && $method !== 'coinsub') {
            return $title;
        }

        return self::get_whitelabel_method_title();
    }

    /**
     * "Pay with {partner}", from the whitelabel config.
     *
     * Falls back to the plugin's own name rather than to whatever was stored,
     * because a stored value is the thing we are trying to stop displaying.
     *
     * @return string
     */
    public static function get_whitelabel_method_title() {
        $name = class_exists('SP_Whitelabel_Branding')
            ? SP_Whitelabel_Branding::get_whitelabel_plugin_name_from_config()
            : null;

        if (!empty($name)) {
            /* translators: %s: payment provider name */
            return sprintf(__('Pay with %s', 'stablecoin-pay'), $name);
        }

        return __('Pay with Stablecoin Pay', 'stablecoin-pay');
    }

    /**
     * Add Stablecoin Pay information to order emails
     */
    public function add_sp_info_to_email($order, $sent_to_admin, $plain_text, $email) {
        // Only show payment info if order is Stablecoin Pay
        if ($order->get_payment_method() !== 'sp') {
            return;
        }
        
        $order_status = $order->get_status();
        
        // For pending/on-hold orders: show "Complete Payment" button
        if (in_array($order_status, array('pending', 'on-hold'), true)) {
            $checkout_url = $order->get_meta('_sp_checkout_url');
            if (empty($checkout_url)) {
                return;
            }
            
            if ($plain_text) {
                echo "\n" . __('Complete Your Payment:', 'stablecoin-pay') . "\n";
                echo __('Payment URL: ', 'stablecoin-pay') . $checkout_url . "\n";
            } else {
                ?>
                <div style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-left: 4px solid #007cba;">
                    <h3 style="margin-top: 0;"><?php _e('Complete Your Payment', 'stablecoin-pay'); ?></h3>
                    <p><?php _e('Click the button below to complete your payment:', 'stablecoin-pay'); ?></p>
                    <p>
                        <a href="<?php echo esc_url($checkout_url); ?>" style="background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 3px; display: inline-block;">
                            <?php _e('Complete Payment', 'stablecoin-pay'); ?>
                        </a>
                    </p>
                </div>
                <?php
            }
            return;
        }
        
        // For completed/processing orders: show transaction hash link (if available)
        $transaction_hash = $order->get_meta('_sp_transaction_hash');
        $chain_id = $order->get_meta('_sp_chain_id');
        
        if (empty($transaction_hash)) {
            return; // No transaction info to show
        }
        
        $explorer_url = $this->get_explorer_url($chain_id, $transaction_hash, $order);
        
        if ($plain_text) {
            echo "\n" . __('Payment Information:', 'stablecoin-pay') . "\n";
            echo __('Transaction Hash: ', 'stablecoin-pay') . $transaction_hash . "\n";
            if ($explorer_url) {
                echo __('View on Blockchain: ', 'stablecoin-pay') . $explorer_url . "\n";
            }
        } else {
            ?>
            <div style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-left: 4px solid #007cba;">
                <h3 style="margin-top: 0;"><?php _e('Payment Confirmation', 'stablecoin-pay'); ?></h3>
                <p>
                    <strong><?php _e('Transaction Hash:', 'stablecoin-pay'); ?></strong><br>
                    <code><?php echo esc_html($transaction_hash); ?></code>
                </p>
                <?php if ($explorer_url): ?>
                <p>
                    <a href="<?php echo esc_url($explorer_url); ?>" style="background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 3px; display: inline-block;" target="_blank">
                        <?php _e('View on Blockchain', 'stablecoin-pay'); ?>
                    </a>
                </p>
                <?php endif; ?>
            </div>
            <?php
        }
    }
    
    /**
     * Get order status from Stablecoin Pay
     */
    public function get_sp_order_status($order) {
        $purchase_session_id = $order->get_meta('_sp_purchase_session_id');
        
        if (empty($purchase_session_id)) {
            return null;
        }
        
        $api_client = new SP_API_Client();
        $status = $api_client->get_purchase_session_status($purchase_session_id);
        
        if (is_wp_error($status)) {
            return null;
        }
        
        return $status;
    }
    
    /**
     * Ask the provider what actually happened to an order's purchase session and apply
     * it locally.
     *
     * This is the backup for a payment webhook that never arrived or was rejected.
     * Nothing else moves a Stablecoin Pay order out of "Pending payment", so without
     * this an order whose delivery was lost stays unpaid forever while the money has
     * already settled on-chain.
     *
     * Deliberately one-directional: it only ever runs on an order still waiting for its
     * first payment, so a stale or partial read can never contradict a webhook that did
     * get through.
     *
     * @param WC_Order|int $order
     * @return bool Whether the order was moved out of its unpaid state.
     */
    public function sync_order_status($order) {
        if (is_numeric($order)) {
            $order = wc_get_order($order);
        }
        if (!$order instanceof WC_Order || $order->get_payment_method() !== 'sp') {
            return false;
        }
        if (!in_array($order->get_status(), array('pending', 'on-hold'), true)) {
            return false;
        }
        // A payment id is only ever written by the webhook completion path, so its
        // presence means a delivery was already processed and polling has no business
        // second-guessing it.
        if (!empty($order->get_meta('_sp_payment_id'))) {
            return false;
        }
        $purchase_session_id = $order->get_meta('_sp_purchase_session_id');
        if (empty($purchase_session_id)) {
            return false;
        }
        if (!$this->claim_status_check($order)) {
            return false;
        }

        // Short timeout: this runs inline on the order pages, so an unresponsive
        // provider must not hold up the page. The sweep will try again.
        $api_client = new SP_API_Client();
        $response   = $api_client->get_purchase_session_status($purchase_session_id, 10);

        if (is_wp_error($response)) {
            // Never act on a failed lookup. An unreachable provider is not evidence
            // that a payment did not happen.
            error_log('PP Poll: status lookup failed for order #' . $order->get_id() . ': ' . $response->get_error_message());
            return false;
        }

        $payload   = self::unwrap_session_payload($response);
        $sp_status = self::extract_session_status($payload);

        // The exact shape of this response has never been confirmed against the live
        // API, so record what came back. Keys only - the payload carries buyer details.
        error_log('PP Poll: order #' . $order->get_id() . ' session ' . $purchase_session_id
            . ' reports "' . $sp_status . '" (keys: ' . implode(',', array_keys($payload)) . ')');

        if (in_array($sp_status, self::$paid_statuses, true)) {
            // Replay the webhook's completion path so an order confirmed by polling is
            // indistinguishable from one confirmed by a delivery.
            SP_Webhook_Handler::instance()->handle_payment_completed($order, $payload);
            $order->add_order_note(__('Payment confirmed by status check - no payment webhook was received for this order.', 'stablecoin-pay'));
            return true;
        }

        // Everything else is only written to the log, never to the order.
        //
        // Polling exists to rescue a payment whose webhook went missing, so the only
        // transition worth the risk is the one that moves an order forward. Failing or
        // cancelling on a polled status would mean destroying a live order on the
        // strength of a string from an endpoint whose vocabulary we have not verified,
        // and an order the customer is still paying for looks exactly like one they
        // abandoned. The webhook already reports failures and cancellations, and it is
        // signed, so it is the one source allowed to end an order.
        if ($sp_status !== '') {
            error_log('PP Poll: leaving order #' . $order->get_id() . ' as "' . $order->get_status()
                . '" - status check reported "' . $sp_status . '", which only the webhook may act on');
        }

        return false;
    }

    /**
     * Poll the order the customer is looking at right now. Covers the common case: the
     * delivery was missed while they were paying and they have just landed back on
     * order-received or view-order.
     *
     * @param int $order_id
     */
    public function poll_on_order_page($order_id) {
        $this->sync_order_status(absint($order_id));
    }

    /**
     * Sweep orders still waiting on a payment webhook, for the customers who never come
     * back to the site after paying.
     */
    public function poll_pending_orders() {
        $orders = wc_get_orders(array(
            'payment_method' => 'sp',
            'status'         => array('pending', 'on-hold'),
            'limit'          => self::POLL_BATCH,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'date_created'   => '>' . (time() - self::POLL_MAX_AGE),
        ));

        foreach ($orders as $order) {
            $this->sync_order_status($order);
        }
    }

    /**
     * One provider call per order per POLL_THROTTLE seconds. The order pages can be
     * reloaded freely and the sweep overlaps with them.
     *
     * @param WC_Order $order
     * @return bool Whether this caller may perform the check.
     */
    private function claim_status_check($order) {
        $key = 'sp_status_check_' . $order->get_id();
        if (get_transient($key)) {
            return false;
        }
        set_transient($key, time(), self::POLL_THROTTLE);
        return true;
    }

    /**
     * Statuses that mean the money arrived.
     *
     * "success" is absent on purpose. A great many APIs wrap their responses as
     * {"status":"success","data":{...}}, where that word describes the HTTP call and
     * says nothing about the payment - treating it as payment confirmation would mark
     * every unpaid order as paid.
     *
     * @var string[]
     */
    private static $paid_statuses = array('completed', 'complete', 'paid', 'succeeded', 'settled');

    /**
     * Status out of a purchase-session status response.
     *
     * @param array $payload Payload, already unwrapped from any envelope.
     * @return string Lower-cased status, or '' when none was reported.
     */
    private static function extract_session_status($payload) {
        foreach (array('purchase_session_status', 'session_status', 'payment_status', 'status', 'state') as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key]) && (string) $payload[$key] !== '') {
                return strtolower(trim((string) $payload[$key]));
            }
        }
        return '';
    }

    /**
     * The payment object out of the response.
     *
     * Returns the inner node when the response is enveloped rather than merging the two
     * levels together, so an outer transport "status" can never be mistaken for the
     * payment's own.
     *
     * @param mixed $response
     * @return array
     */
    private static function unwrap_session_payload($response) {
        if (!is_array($response)) {
            return array();
        }
        if (isset($response['data']) && is_array($response['data']) && !empty($response['data'])) {
            return $response['data'];
        }
        return $response;
    }

    /**
     * Register the sweep. Cheap to call on every load - it only writes when nothing is
     * scheduled yet.
     */
    public static function schedule_polling() {
        if (!wp_next_scheduled(self::POLL_HOOK)) {
            wp_schedule_event(time() + self::POLL_INTERVAL, 'sp_five_minutes', self::POLL_HOOK);
        }
    }

    /**
     * Drop the sweep, so a deactivated plugin leaves no cron entry behind.
     */
    public static function unschedule_polling() {
        wp_clear_scheduled_hook(self::POLL_HOOK);
    }

    /**
     * @param array $schedules
     * @return array
     */
    public function add_cron_interval($schedules) {
        if (!is_array($schedules)) {
            $schedules = array();
        }
        if (!isset($schedules['sp_five_minutes'])) {
            $schedules['sp_five_minutes'] = array(
                'interval' => self::POLL_INTERVAL,
                'display'  => __('Every 5 minutes (Stablecoin Pay)', 'stablecoin-pay'),
            );
        }
        return $schedules;
    }

}
