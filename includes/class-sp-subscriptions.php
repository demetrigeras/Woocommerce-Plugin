<?php
/**
 * Stablecoin Pay Subscriptions Manager
 * 
 * Handles subscription products and customer subscription management
 */

if (!defined('ABSPATH')) {
    exit;
}

class SP_Subscriptions {

    /**
     * @var self|null
     */
    private static $instance = null;

    private $api_client;

    /**
     * Single instance — needed so WooCommerce admin (order edit) can render
     * the same subscription summary as My Account without re-instantiating
     * and double-registering hooks.
     *
     * @return self
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Cart validation
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_cart_items'), 10, 3);
        // Enforce subscription quantity limits during cart checks/updates
        add_action('woocommerce_check_cart_items', array($this, 'enforce_subscription_quantities'));
        
        // Orders list: no Subscription column; subscription details + cancel only on the single order (view-order) page
        add_action('woocommerce_order_details_after_order_table', array($this, 'view_order_subscription_section'), 10, 1);
        add_action('wp_footer', array($this, 'sp_cancel_script'));
        // Only for Stablecoin Pay: hide on-hold rows; show "Completed" instead of "Processing" for customers
        add_filter('woocommerce_my_account_my_orders_query', array($this, 'my_account_orders_query_passthrough'));
        add_action('woocommerce_my_account_my_orders_column_order-total', array($this, 'orders_list_mark_sp'), 20, 1);
        add_action('wp_footer', array($this, 'my_account_hide_sp_on_hold_rows'));
        add_action('woocommerce_order_details_after_order_table', array($this, 'view_order_show_completed_for_sp'), 5, 1);
        
        // Handle subscription cancellation
        add_action('wp_ajax_sp_cancel_subscription', array($this, 'ajax_cancel_subscription'));
        
        // Add subscription fields to product
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_subscription_fields'));
        add_action('woocommerce_process_product_meta', array($this, 'save_subscription_fields'));
    }
    
    /**
     * Get API client instance
     */
    private function get_api_client() {
        if ($this->api_client === null) {
            if (!class_exists('SP_API_Client')) {
                return null;
            }
            $this->api_client = new SP_API_Client();
        }
        return $this->api_client;
    }
    
    /**
     * Validate cart items - enforce subscription rules
     */
    public function validate_cart_items($passed, $product_id, $quantity) {
        $product = wc_get_product($product_id);
        $is_subscription = $product->get_meta('_sp_subscription') === 'yes';
        
        // Check what's already in cart
        $cart = WC()->cart->get_cart();
        $has_subscription = false;
        $has_regular = false;
        $has_same_subscription = false;
        
        foreach ($cart as $cart_item) {
            $cart_product = $cart_item['data'];
            if ($cart_product->get_meta('_sp_subscription') === 'yes') {
                $has_subscription = true;
                if ((int)$cart_product->get_id() === (int)$product_id) {
                    $has_same_subscription = true;
                }
            } else {
                $has_regular = true;
            }
        }
        
        // Subscriptions limited to quantity 1
        if ($is_subscription && (int)$quantity > 1) {
            wc_add_notice(__('You can only purchase one of a subscription at a time.', 'stablecoin-pay'), 'error');
            return false;
        }
        
        // Prevent adding the same subscription product twice
        if ($is_subscription && $has_same_subscription) {
            wc_add_notice(__('This subscription is already in your cart.', 'stablecoin-pay'), 'error');
            return false;
        }
        
        // Enforce rules - prevent mixing subscriptions and regular products
        if ($is_subscription && $has_regular) {
            wc_add_notice(__('Subscriptions must be purchased separately. Regular products have been removed from your cart.', 'stablecoin-pay'), 'notice');
            // Remove regular products from cart
            $this->remove_regular_products_from_cart();
            return true; // Allow the subscription to be added
        }
        
        if (!$is_subscription && $has_subscription) {
            wc_add_notice(__('You have a subscription in your cart. Subscriptions must be purchased separately. Please checkout the subscription first.', 'stablecoin-pay'), 'error');
            return false;
        }
        
        if ($is_subscription && $has_subscription) {
            wc_add_notice(__('You can only have one subscription in your cart at a time. Please checkout your current subscription first.', 'stablecoin-pay'), 'error');
            return false;
        }
        
        return $passed;
    }

    /**
     * Ensure any subscription line items are clamped to quantity 1
     */
    public function enforce_subscription_quantities() {
        $cart = WC()->cart;
        if (!$cart) {
            return;
        }
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            if ($product && $product->get_meta('_sp_subscription') === 'yes') {
                if ((int)$cart_item['quantity'] !== 1) {
                    $cart->set_quantity($cart_item_key, 1, true);
                    wc_add_notice(__('Subscription quantity has been set to 1.', 'stablecoin-pay'), 'notice');
                }
            }
        }
    }
    
    /**
     * Remove regular products from cart when subscription is present
     */
    private function remove_regular_products_from_cart() {
        $cart = WC()->cart;
        $cart_items = $cart->get_cart();
        
        foreach ($cart_items as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $is_subscription = $product->get_meta('_sp_subscription') === 'yes';
            
            if (!$is_subscription) {
                $cart->remove_cart_item($cart_item_key);
                error_log('🛒 Removed regular product from cart: ' . $product->get_name());
            }
        }
    }
    
    /**
     * Add subscription fields to product edit page
     */
    public function add_subscription_fields() {
        global $post;
        
        echo '<div class="options_group show_if_simple">';
        
        $name = class_exists('SP_Whitelabel_Branding') ? SP_Whitelabel_Branding::get_whitelabel_plugin_name_from_config() : null;
        $sub_label = $name ? sprintf(__('%s Subscription', 'stablecoin-pay'), $name) : __('Subscription', 'stablecoin-pay');
        woocommerce_wp_checkbox(array(
            'id' => '_sp_subscription',
            'label' => $sub_label,
            'description' => __('Enable this to make this a recurring subscription product', 'stablecoin-pay'),
            'value' => get_post_meta($post->ID, '_sp_subscription', true)
        ));
        
        woocommerce_wp_select(array(
            'id' => '_sp_frequency',
            'label' => __('Frequency', 'stablecoin-pay'),
            'options' => array(
                '1' => 'Every',
                '2' => 'Every Other',
                '3' => 'Every Third',
                '4' => 'Every Fourth',
                '5' => 'Every Fifth',
                '6' => 'Every Sixth',
                '7' => 'Every Seventh',
            ),
            'value' => get_post_meta($post->ID, '_sp_frequency', true),
            'desc_tip' => true,
            'description' => __('How often the subscription renews', 'stablecoin-pay')
        ));
        
        $stored_interval = get_post_meta($post->ID, '_sp_interval', true);
        woocommerce_wp_select(array(
            'id' => '_sp_interval',
            'label' => __('Interval', 'stablecoin-pay'),
            'options' => array(
                'day' => 'Day',
                'week' => 'Week',
                'month' => 'Month',
                'year' => 'Year',
            ),
            'value' => $stored_interval,
            'desc_tip' => true,
            'description' => __('Time period for the subscription', 'stablecoin-pay'),
            'custom_attributes' => array('required' => 'required')
        ));
        
        $duration_value = get_post_meta($post->ID, '_sp_duration', true);
        $duration_display = ($duration_value === '0' || empty($duration_value)) ? '' : $duration_value;
        
        echo '<p class="form-field _sp_duration_field">';
        echo '<label for="_sp_duration">' . __('Duration', 'stablecoin-pay') . '</label>';
        echo '<input type="text" id="_sp_duration" name="_sp_duration" value="' . esc_attr($duration_display) . '" placeholder="Until Cancelled" style="width: 50%;" />';
        echo '<span class="description" style="display: block; margin-top: 5px;">';
        echo __('Leave blank for <strong>"Until Cancelled"</strong> (subscription continues forever)<br>Or enter a number for limited payments (e.g., <strong>12</strong> = stops after 12 payments)', 'stablecoin-pay');
        echo '</span>';
        echo '</p>';
        
        echo '</div>';
    }
    //
    /**
     * Save subscription fields
     */
    public function save_subscription_fields($post_id) {
        $is_subscription = isset($_POST['_sp_subscription']) ? 'yes' : 'no';
        update_post_meta($post_id, '_sp_subscription', $is_subscription);
        
        if ($is_subscription === 'yes') {
            $frequency = isset($_POST['_sp_frequency']) ? sanitize_text_field($_POST['_sp_frequency']) : '1';
            $interval = isset($_POST['_sp_interval']) ? sanitize_text_field($_POST['_sp_interval']) : '';
            $duration = isset($_POST['_sp_duration']) ? sanitize_text_field($_POST['_sp_duration']) : '';

            // Normalize interval to allowed label values
            $allowed_intervals = array('day','week','month','year');
            $interval = strtolower(trim($interval));
            // Map accidental numeric submissions to labels
            $num_to_label = array('0' => 'day', '1' => 'week', '2' => 'month', '3' => 'year');
            if (isset($num_to_label[$interval])) {
                $interval = $num_to_label[$interval];
            }
            if (!in_array($interval, $allowed_intervals, true)) {
                // Require a valid selection; leave as empty and rely on required attribute in UI
                $interval = '';
            }
            
            // Convert empty duration to "0" (Until Cancelled)
            if (empty($duration) || $duration === 'Until Cancelled') {
                $duration = '0';
            }
            
            error_log('💾 Saving subscription product #' . $post_id);
            error_log('  Frequency: ' . $frequency);
            error_log('  Interval: ' . $interval);
            error_log('  Duration: ' . $duration);
            
            update_post_meta($post_id, '_sp_frequency', $frequency);
            update_post_meta($post_id, '_sp_interval', $interval);
            update_post_meta($post_id, '_sp_duration', $duration);
        }
    }
    
    /**
     * Do not change the orders query. On-hold hiding for Stablecoin Pay only is done in my_account_hide_sp_on_hold_rows (JS)
     * so other payment methods (Visa, etc.) are not affected.
     *
     * @param array $args Query args for wc_get_orders
     * @return array
     */
    public function my_account_orders_query_passthrough($args) {
        return $args;
    }

    /**
     * Output order total and append hidden marker for Stablecoin Pay orders (so JS can show "Completed" instead of "Processing").
     * Ensures the Total column always shows the amount (some themes/block templates don't output it by default).
     */
    public function orders_list_mark_sp($order) {
        if (!$order || !$order instanceof WC_Order) {
            return;
        }
        echo wp_kses_post($order->get_formatted_order_total());
        if ($order->get_payment_method() === 'sp') {
            echo ' <span class="sp-order" style="display:none;"></span>';
        }
    }

    /**
     * On view-order page: for Stablecoin Pay orders with status Processing, show "Completed" to the customer.
     */
    public function view_order_show_completed_for_sp($order) {
        if (is_admin() || !is_account_page()) {
            return;
        }
        if (is_numeric($order)) {
            $order = wc_get_order($order);
        }
        if (!$order || !$order instanceof WC_Order || $order->get_payment_method() !== 'sp' || $order->get_status() !== 'processing') {
            return;
        }
        ?>
        <script>
        (function() {
            var label = <?php echo json_encode(esc_html__('Completed', 'stablecoin-pay')); ?>;
            function run() {
                document.querySelectorAll('.order-status.status-processing, mark.status-processing').forEach(function(el) {
                    if (/Processing/.test(el.textContent)) el.textContent = label;
                });
            }
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
            else run();
        })();
        </script>
        <?php
    }

    /**
     * On My Account > Orders: hide only rows for Stablecoin Pay orders that are on-hold (awaiting payment).
     * Also show "Completed" instead of "Processing" for Stablecoin Pay paid orders (customer view only).
     */
    public function my_account_hide_sp_on_hold_rows() {
        if (!is_account_page() || !is_wc_endpoint_url('orders')) {
            return;
        }
        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }
        $order_ids = wc_get_orders(array(
            'customer_id' => $user_id,
            'status' => 'on-hold',
            'payment_method' => 'sp',
            'return' => 'ids',
            'limit' => -1,
        ));
        $order_ids = array_map('intval', (array) $order_ids);
        $completed_label = esc_js(__('Completed', 'stablecoin-pay'));
        ?>
        <script>
        (function() {
            var hideOrderIds = <?php echo json_encode(array_values($order_ids)); ?>;
            var completedLabel = <?php echo json_encode($completed_label); ?>;
            function run() {
                var table = document.querySelector('.woocommerce-orders-table, .shop_table.my_account_orders');
                if (!table) return;
                var rows = table.querySelectorAll('tbody tr');
                rows.forEach(function(tr) {
                    var link = tr.querySelector('a[href*="view-order"]');
                    if (!link || !link.href) return;
                    var m = link.href.match(/view-order\/(\d+)/);
                    if (!m) return;
                    var id = parseInt(m[1], 10);
                    if (hideOrderIds.indexOf(id) !== -1) {
                        tr.style.display = 'none';
                        return;
                    }
                    if (tr.querySelector('.sp-order')) {
                        var statusEl = tr.querySelector('.order-status.status-processing');
                        if (statusEl && /Processing/.test(statusEl.textContent)) statusEl.textContent = completedLabel;
                    }
                });
            }
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
            else run();
        })();
        </script>
        <?php
    }
    
    /**
     * On single order page (view-order): show subscription details in a row + Cancel button (Stablecoin Pay subscription orders only).
     *
     * @param WC_Order $order
     */
    public function view_order_subscription_section($order) {
        $this->render_subscription_order_panel($order, 'customer');
    }

    /**
     * Subscription summary shown on My Account view-order (`customer`)
     * and WooCommerce admin order edit (`admin`): start date, next payment,
     * regularity, status, and Cancel (when active).
     *
     * @param WC_Order|int|null $order
     * @param string              $context 'customer' or 'admin'.
     */
    public function render_subscription_order_panel($order, $context = 'customer') {
        if (is_numeric($order)) {
            $order = wc_get_order($order);
        }
        if (!$order || !$order instanceof WC_Order || $order->get_payment_method() !== 'sp') {
            return;
        }
        if ($order->get_meta('_sp_is_subscription') !== 'yes') {
            return;
        }

        $agreement_id      = $order->get_meta('_sp_agreement_id');
        $status            = $order->get_meta('_sp_subscription_status');
        $cancelled_at_sql  = $order->get_meta('_sp_cancelled_at');

        $frequency_text = $this->get_subscription_frequency_text($order);
        $duration_text  = $this->get_subscription_duration_text($order);
        $duration_raw   = $this->get_subscription_duration_raw($order);
        $start_date     = $order->get_date_created() ? $order->get_date_created()->date_i18n(wc_date_format()) : '—';

        // Next payment — exact same resolution logic as the merchant
        // Subscriptions admin screen (`class-sp-admin-subscriptions.php`):
        //   1. Try cached `_sp_next_payment` order meta
        //   2. If we still don't have a date AND we have an agreement_id,
        //      fetch fresh from the API, persist the raw value back to
        //      order meta, and use it.
        $next_payment = '';
        if ($status !== 'cancelled' && !empty($agreement_id)) {
            $cached_raw = $order->get_meta('_sp_next_payment');
            if (!empty($cached_raw)) {
                $next_payment = $this->format_date($cached_raw);
            }

            if (empty($next_payment)) {
                $api_client = $this->get_api_client();
                if ($api_client) {
                    $agreement_response = $api_client->retrieve_agreement($agreement_id);
                    if (!is_wp_error($agreement_response)) {
                        $agreement_data   = isset($agreement_response['data']) ? $agreement_response['data'] : $agreement_response;
                        $next_payment_raw = $this->get_next_payment_from_agreement_data($agreement_data);
                        if (!empty($next_payment_raw)) {
                            $order->update_meta_data('_sp_next_payment', $next_payment_raw);
                            $order->save();
                            $next_payment = $this->format_date($next_payment_raw);
                        }
                    }
                }
            }
        }
        if (empty($next_payment)) {
            $next_payment = '—';
        }

        if (empty($duration_raw) || $duration_raw === '0') {
            $regularity_text = $frequency_text;
        } else {
            $regularity_text = $frequency_text . ' ' . sprintf(__('for %s', 'stablecoin-pay'), $duration_text);
        }

        // Collected payments stats — walks the parent subscription chain so
        // the same numbers appear on the original order AND on each renewal.
        $payments_stats = $this->collect_subscription_payment_stats($order);
        $payments_count  = $payments_stats['count'];
        $payments_total  = $payments_stats['total'];
        $payments_currency = $payments_stats['currency'];
        $duration_int    = (int) $duration_raw;
        if ($payments_count > 0) {
            if ($duration_int > 0) {
                /* translators: 1: paid count, 2: scheduled total */
                $count_label = sprintf(__('%1$d of %2$d', 'stablecoin-pay'), $payments_count, $duration_int);
            } else {
                /* translators: %d: paid count (open-ended subscription) */
                $count_label = sprintf(_n('%d payment', '%d payments', $payments_count, 'stablecoin-pay'), $payments_count);
            }
            $total_label = function_exists('wc_price')
                ? wp_strip_all_tags(wc_price($payments_total, array('currency' => $payments_currency)))
                : ($payments_currency . ' ' . number_format($payments_total, 2));
        } else {
            $count_label = '—';
            $total_label = '';
        }

        // Status label (admin sees explicit badges; optional line for customer cancelled).
        if ($status === 'cancelled') {
            $status_label = __('Cancelled', 'stablecoin-pay');
            $status_color = '#856404';
            $status_bg = '#fff3cd';
        } elseif (empty($agreement_id)) {
            $status_label = __('Pending activation', 'stablecoin-pay');
            $status_color = '#0c5460';
            $status_bg = '#d1ecf1';
        } else {
            $status_label = __('Active', 'stablecoin-pay');
            $status_color = '#155724';
            $status_bg = '#d4edda';
        }

        $can_cancel_customer = ($status !== 'cancelled' && !empty($agreement_id));

        $section_mt = ('admin' === $context) ? '0' : '1.5em';
        $section_mb = ('admin' === $context) ? '14px' : '1.5em';
        ?>
        <section class="sp-subscription-details sp-subscription-details--<?php echo esc_attr($context); ?>" style="margin-top: <?php echo esc_attr($section_mt); ?>; margin-bottom: <?php echo esc_attr($section_mb); ?>; padding: 1em 1.25em; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 6px;">
            <h3 style="margin: 0 0 1em; font-size: 1em;"><?php esc_html_e('Subscription', 'stablecoin-pay'); ?></h3>

            <?php if ($context === 'admin') : ?>
            <div style="margin:-4px 0 12px; display:inline-block;">
                <span style="display:inline-block; padding:3px 10px; border-radius:4px; font-size:12px; font-weight:600; color: <?php echo esc_attr($status_color); ?>; background: <?php echo esc_attr($status_bg); ?>; border:1px solid rgba(0,0,0,.06);"><?php echo esc_html($status_label); ?></span>
                <?php if ($status === 'cancelled' && !empty($cancelled_at_sql)) : ?>
                    <span style="margin-left:8px; font-size:12px; color:#646970;">
                        <?php
                        printf(
                            /* translators: %s: localized cancellation datetime */
                            esc_html__('Cancelled on %s', 'stablecoin-pay'),
                            esc_html(
                                function_exists('wc_string_to_datetime')
                                    ? wc_format_datetime(wc_string_to_datetime($cancelled_at_sql))
                                    : date_i18n(
                                        get_option('date_format') . ' ' . get_option('time_format'),
                                        strtotime($cancelled_at_sql)
                                    )
                            )
                        );
                        ?>
                    </span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="sp-subscription-fields" style="display: flex; flex-wrap: wrap; gap: 1.5em 2em; align-items: flex-end;">
                <div>
                    <div style="font-size: 0.85em; color: #6c757d; margin-bottom: 0.25em;"><?php esc_html_e('Start date', 'stablecoin-pay'); ?></div>
                    <div><?php echo esc_html($start_date); ?></div>
                </div>
                <div>
                    <div style="font-size: 0.85em; color: #6c757d; margin-bottom: 0.25em;"><?php esc_html_e('Next payment', 'stablecoin-pay'); ?></div>
                    <div><?php echo esc_html($next_payment); ?></div>
                </div>
                <div>
                    <div style="font-size: 0.85em; color: #6c757d; margin-bottom: 0.25em;"><?php esc_html_e('Regularity', 'stablecoin-pay'); ?></div>
                    <div><?php echo esc_html($regularity_text); ?></div>
                </div>
                <div>
                    <div style="font-size: 0.85em; color: #6c757d; margin-bottom: 0.25em;"><?php esc_html_e('Payments collected', 'stablecoin-pay'); ?></div>
                    <div>
                        <?php echo esc_html($count_label); ?>
                        <?php if (!empty($total_label)) : ?>
                            <span style="color:#6c757d; font-size: 0.9em;">(<?php echo esc_html($total_label); ?>)</span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($can_cancel_customer && $context === 'customer') : ?>
                    <div style="align-self: flex-end; margin-left: auto;">
                        <button type="button" class="button sp-cancel-subscription" data-agreement-id="<?php echo esc_attr($agreement_id); ?>" data-order-id="<?php echo esc_attr($order->get_id()); ?>"><?php esc_html_e('Cancel subscription', 'stablecoin-pay'); ?></button>
                    </div>
                <?php elseif ($can_cancel_customer && $context === 'admin') : ?>
                    <div style="align-self: flex-end; margin-left: auto;">
                        <button type="button" class="button button-secondary sp-admin-cancel-subscription" data-agreement-id="<?php echo esc_attr($agreement_id); ?>" data-order-id="<?php echo esc_attr($order->get_id()); ?>"><?php esc_html_e('Cancel subscription', 'stablecoin-pay'); ?></button>
                    </div>
                <?php elseif ($context === 'customer' && $status === 'cancelled') : ?>
                    <div style="align-self: flex-end; margin-left: auto; color: #6c757d;"><em><?php esc_html_e('Cancelled', 'stablecoin-pay'); ?></em></div>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    /**
     * Count payments collected for a subscription, plus the running total.
     *
     * Walks from any order in the chain (parent or renewal) to the original
     * subscription order, then counts that order plus every renewal listed
     * in `_sp_renewal_orders` whose status is `processing` / `completed`
     * / `refunded` (refunded still counts as money that was once collected).
     *
     * @param WC_Order $order
     * @return array{count:int,total:float,currency:string,parent_id:int}
     */
    private function collect_subscription_payment_stats($order) {
        $result = array(
            'count'     => 0,
            'total'     => 0.0,
            'currency'  => $order->get_currency() ?: get_woocommerce_currency(),
            'parent_id' => $order->get_id(),
        );

        $parent = $order;
        if ($order->get_meta('_sp_is_renewal_order') === 'yes') {
            $parent_id = (int) $order->get_meta('_sp_parent_subscription_order');
            if ($parent_id > 0) {
                $maybe_parent = wc_get_order($parent_id);
                if ($maybe_parent) {
                    $parent = $maybe_parent;
                }
            }
        }
        $result['parent_id'] = $parent->get_id();
        $result['currency']  = $parent->get_currency() ?: $result['currency'];

        $paid_statuses = array('processing', 'completed', 'refunded');

        // Original payment.
        if (in_array($parent->get_status(), $paid_statuses, true)) {
            $result['count'] += 1;
            $result['total'] += (float) $parent->get_total();
        }

        // Renewal payments.
        $renewal_ids = $parent->get_meta('_sp_renewal_orders');
        if (is_array($renewal_ids)) {
            foreach ($renewal_ids as $rid) {
                $renewal_order = wc_get_order((int) $rid);
                if (!$renewal_order) {
                    continue;
                }
                if (!in_array($renewal_order->get_status(), $paid_statuses, true)) {
                    continue;
                }
                $result['count'] += 1;
                $result['total'] += (float) $renewal_order->get_total();
            }
        }

        return $result;
    }

    /**
     * Cancel-subscription script (runs on My Account so Cancel works on view-order page).
     */
    public function sp_cancel_script() {
        if (!is_account_page()) {
            return;
        }
        $nonce = wp_create_nonce('sp_cancel_subscription');
        ?>
        <script>
        jQuery(function($) {
            $(document.body).on('click', '.sp-cancel-subscription', function(e) {
                e.preventDefault();
                if (!confirm('<?php echo esc_js(__('Are you sure you want to cancel this subscription?', 'stablecoin-pay')); ?>')) {
                    return;
                }
                var btn = $(this);
                btn.prop('disabled', true).text('<?php echo esc_js(__('Cancelling...', 'stablecoin-pay')); ?>');
                $.post('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                    action: 'sp_cancel_subscription',
                    agreement_id: btn.data('agreement-id'),
                    order_id: btn.data('order-id'),
                    nonce: '<?php echo esc_js($nonce); ?>'
                }).done(function(res) {
                    if (res.success) {
                        alert('<?php echo esc_js(__('Subscription cancelled successfully', 'stablecoin-pay')); ?>');
                        location.reload();
                    } else {
                        alert(res.data && res.data.message ? res.data.message : '<?php echo esc_js(__('Error cancelling subscription', 'stablecoin-pay')); ?>');
                        btn.prop('disabled', false).text('<?php echo esc_js(__('Cancel subscription', 'stablecoin-pay')); ?>');
                    }
                }).fail(function() {
                    alert('<?php echo esc_js(__('Error cancelling subscription', 'stablecoin-pay')); ?>');
                    btn.prop('disabled', false).text('<?php echo esc_js(__('Cancel subscription', 'stablecoin-pay')); ?>');
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Get raw subscription duration from order ('0' = until cancelled, or number of payments).
     */
    private function get_subscription_duration_raw($order) {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->get_meta('_sp_subscription') === 'yes') {
                $duration = $product->get_meta('_sp_duration');
                return $duration === '' ? '0' : (string) $duration;
            }
        }
        return '0';
    }

    /**
     * Get subscription duration text from order (e.g. "12 payments" or "Until cancelled")
     */
    private function get_subscription_duration_text($order) {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->get_meta('_sp_subscription') === 'yes') {
                $duration = $product->get_meta('_sp_duration');
                if (empty($duration) || $duration === '0') {
                    return __('Until cancelled', 'stablecoin-pay');
                }
                return sprintf(_n('%s payment', '%s payments', (int) $duration, 'stablecoin-pay'), (int) $duration);
            }
        }
        return '';
    }
    
    /**
     * Extract next payment date from agreement API data.
     * API returns e.g. next_process_date (ISO string) or next_payment_date (timestamp).
     *
     * @param array $agreement_data Agreement data from retrieve_agreement (or nested under 'data').
     * @return string Raw value (timestamp or date string) or empty string.
     */
    private function get_next_payment_from_agreement_data($agreement_data) {
        if (!is_array($agreement_data) || empty($agreement_data)) {
            return '';
        }
        foreach (array('next_process_date', 'next_payment_date') as $key) {
            if (isset($agreement_data[$key]) && $agreement_data[$key] !== '' && $agreement_data[$key] !== null) {
                return $agreement_data[$key];
            }
        }
        return '';
    }
    
    /**
     * Format date from API response
     */
    private function format_date($date_value) {
        if (empty($date_value)) {
            return '';
        }
        
        // If it's a timestamp (numeric)
        if (is_numeric($date_value)) {
            return date_i18n('Y-m-d h:i:s A', (int)$date_value);
        }
        
        // If it's a date string, try to parse it
        $timestamp = strtotime($date_value);
        if ($timestamp !== false) {
            return date_i18n('Y-m-d h:i:s A', $timestamp);
        }
        
        // Return as-is if we can't parse it
        return $date_value;
    }
    
    /**
     * Get subscription product name from order
     */
    private function get_subscription_product_name($order) {
        $items = $order->get_items();
        if (empty($items)) {
            return 'Subscription';
        }
        
        $first_item = reset($items);
        return $first_item->get_name();
    }
    
    /**
     * Get subscription frequency text from order
     */
    private function get_subscription_frequency_text($order) {
        $frequency_map = array(
            '1' => 'Every',
            '2' => 'Every Other',
            '3' => 'Every Third',
            '4' => 'Every Fourth',
            '5' => 'Every Fifth',
            '6' => 'Every Sixth',
            '7' => 'Every Seventh',
        );
        
        $interval_map = array(
            '0' => 'Day', 'day' => 'Day',
            '1' => 'Week', 'week' => 'Week',
            '2' => 'Month', 'month' => 'Month',
            '3' => 'Year', 'year' => 'Year',
        );
        
        // Get subscription data from order items
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->get_meta('_sp_subscription') === 'yes') {
                $frequency = $product->get_meta('_sp_frequency');
                $interval = $product->get_meta('_sp_interval');
                
                $frequency_text = isset($frequency_map[$frequency]) ? $frequency_map[$frequency] : 'Every';
                $interval_text = isset($interval_map[$interval]) ? $interval_map[$interval] : 'Month';
                
                return $frequency_text . ' ' . $interval_text;
            }
        }
        
        return __('N/A', 'stablecoin-pay');
    }

    /**
     * Build frequency text from agreement data if it includes numeric frequency/interval
     */
    private function format_frequency_from_agreement($agreement_data) {
        $frequency = null;
        $interval = null;
        if (isset($agreement_data['frequency'])) {
            $frequency = is_numeric($agreement_data['frequency']) ? (int)$agreement_data['frequency'] : null;
        }
        if (isset($agreement_data['interval'])) {
            $interval = is_numeric($agreement_data['interval']) ? (int)$agreement_data['interval'] : null;
        }
        if ($frequency === null || $interval === null) {
            return '';
        }

        // Frequency words
        $frequencyWords = array(
            1 => 'Every',
            2 => 'Every Other',
            3 => 'Every Third',
            4 => 'Every Fourth',
            5 => 'Every Fifth',
            6 => 'Every Sixth',
            7 => 'Every Seventh'
        );
        $freqText = isset($frequencyWords[$frequency]) ? $frequencyWords[$frequency] : 'Every ' . $frequency . 'th';

        // Interval words (backend mapping 0=Day,1=Week,2=Month,3=Year)
        $intervalWords = array(
            0 => 'Day',
            1 => 'Week',
            2 => 'Month',
            3 => 'Year'
        );
        $intervalText = isset($intervalWords[$interval]) ? $intervalWords[$interval] : 'Month';

        return $freqText . ' ' . $intervalText;
    }
    
    /**
     * AJAX handler for subscription cancellation
     */
    public function ajax_cancel_subscription() {
        check_ajax_referer('sp_cancel_subscription', 'nonce');
        
        $agreement_id = sanitize_text_field($_POST['agreement_id']);
        $order_id = absint($_POST['order_id']);
        
        // Verify order belongs to current user
        $order = wc_get_order($order_id);
        if (!$order || $order->get_customer_id() != get_current_user_id()) {
            wp_send_json_error(array('message' => __('Invalid order', 'stablecoin-pay')));
        }
        
    
        $api_client = $this->get_api_client();
        if (!$api_client) {
            wp_send_json_error(array('message' => __('API client not available', 'stablecoin-pay')));
        }
        
        $result = $api_client->cancel_agreement($agreement_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        // Update order meta
        $order->update_meta_data('_sp_subscription_status', 'cancelled');
        // Stamp local cancelled_at timestamp
        $order->update_meta_data('_sp_cancelled_at', current_time('mysql'));
        $order->add_order_note(__('Subscription cancelled by customer', 'stablecoin-pay'));
        $order->save();
        
        wp_send_json_success(array('message' => __('Subscription cancelled successfully', 'stablecoin-pay')));
    }
}

// Bootstrap (singleton — use SP_Subscriptions::instance() from other classes).
SP_Subscriptions::instance();

