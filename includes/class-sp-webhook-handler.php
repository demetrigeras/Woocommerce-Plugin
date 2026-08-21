<?php
/**
 * Stablecoin Pay Webhook Handler
 * 
 * Handles webhook notifications from Stablecoin Pay
 */

if (!defined('ABSPATH')) {
    exit;
}

class SP_Webhook_Handler {

    /** Seconds a delivery's timestamp may differ from ours before we reject it. */
    const SIGNATURE_TOLERANCE = 300;

    /** How long a handled event id is remembered, to absorb delivery retries. */
    const EVENT_DEDUPE_TTL = DAY_IN_SECONDS;

    /**
     * Constructor
     */
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));
        add_action('wp_ajax_sp_test_connection', array($this, 'test_connection'));
        add_action('wp_ajax_nopriv_sp_test_connection', array($this, 'test_connection'));
        add_action('wp_ajax_sp_check_payment_status', array($this, 'check_payment_status'));
        add_action('wp_ajax_nopriv_sp_check_payment_status', array($this, 'check_payment_status'));
    }
    
    /**
     * Register webhook endpoint with WordPress REST API
     */
    public function register_webhook_endpoint() {
        // Every namespace is served identically. The first is canonical and is what
        // new registrations point at; the rest are kept alive so merchants whose
        // dashboard still holds an older callback URL keep receiving deliveries.
        // Do not drop a legacy namespace until no webhook points at it.
        foreach (SP_Webhook_Provisioner::all_namespaces() as $namespace) {
            register_rest_route($namespace, '/webhook', array(
                'methods' => 'POST',
                'callback' => array($this, 'handle_webhook'),
                'permission_callback' => '__return_true', // Allow public access
            ));

            // Test endpoint to verify webhook is accessible
            register_rest_route($namespace, '/webhook/test', array(
                'methods' => 'GET',
                'callback' => array($this, 'test_webhook_endpoint'),
                'permission_callback' => '__return_true',
            ));
        }
    }
    
    /**
     * Test webhook endpoint
     */
    public function test_webhook_endpoint($request) {
        error_log('🧪 PP Webhook - Test endpoint accessed');
        return new WP_REST_Response(array(
            'status' => 'success',
            'message' => 'Stablecoin Pay webhook endpoint is working!',
            'endpoint' => rest_url(SP_Webhook_Provisioner::CALLBACK_ROUTE),
            'timestamp' => current_time('mysql')
        ), 200);
    }
    
    /**
     * Handle webhook requests
     */
    public function handle_webhook($request) {
        error_log('🔔 PP Webhook - Received webhook request at ' . current_time('mysql'));
        error_log('🔔 PP Webhook - User Agent: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'Not set'));
        error_log('🔔 PP Webhook - Remote IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'Not set'));

        // The signature covers the bytes exactly as sent. Read the raw body here and
        // verify against it BEFORE any JSON decode - decoding and re-encoding changes
        // the bytes (key order, unicode escaping, whitespace) and breaks the HMAC.
        $raw_body = $request->get_body();

        $verification = $this->verify_delivery($request, $raw_body);
        if (is_wp_error($verification)) {
            error_log('❌ PP Webhook - Rejected: ' . $verification->get_error_message());
            return new WP_REST_Response(
                array('error' => $verification->get_error_message()),
                (int) ($verification->get_error_data() ?: 401)
            );
        }

        // Deliveries retry on failure, so the same event legitimately arrives more
        // than once. Claim the event id before doing any work.
        $event_id = $request->get_header('x-event-id');
        if ($event_id !== null && $event_id !== '' && !$this->claim_event($event_id)) {
            error_log('🔁 PP Webhook - Duplicate delivery for event ' . $event_id . ' - acknowledging without reprocessing');
            return new WP_REST_Response(array('status' => 'duplicate'), 200);
        }

        // Get the request body
        $data = $request->get_json_params();

        if (!$data) {
            error_log('❌ PP Webhook - Invalid JSON data');
            return new WP_REST_Response(array('error' => 'Invalid JSON data'), 400);
        }

        error_log('🔔 PP Webhook - Data: ' . json_encode($data));

        // Log specific data structures for debugging
        if (isset($data['agreement'])) {
            error_log('🔔 PP Webhook - Agreement data: ' . json_encode($data['agreement']));
        }
        if (isset($data['transaction_details'])) {
            error_log('🔔 PP Webhook - Transaction details: ' . json_encode($data['transaction_details']));
        }

        // Process the webhook
        $this->process_webhook($data);
        
        // Return success response
        error_log('✅ PP Webhook - Processed successfully');
        return new WP_REST_Response(array('status' => 'success'), 200);
    }
    
    /**
     * Process webhook data
     */
    private function process_webhook($data) {
        error_log('PP Webhook: Full payload: ' . json_encode($data));
        
        $event_type = $data['type'] ?? 'unknown';
        $origin_id = $data['origin_id'] ?? null;
        $merchant_id = $data['merchant_id'] ?? null;
        $order = null;
        
        error_log('PP Webhook: Event type: ' . $event_type);
        error_log('PP Webhook: Origin ID: ' . $origin_id);
        error_log('PP Webhook: Merchant ID: ' . $merchant_id);
        
        // For transfer/failed_transfer (refunds): find order by transfer_id or destination_email (no origin_id in payload)
        if (in_array($event_type, array('transfer', 'failed_transfer'), true)) {
            $transfer_id = $data['transfer_id'] ?? null;
            $destination_email = $data['destination_email'] ?? null;
            if ($transfer_id) {
                $orders_by_refund_id = wc_get_orders(array(
                    'meta_key' => '_sp_refund_id',
                    'meta_value' => $transfer_id,
                    'meta_compare' => '=',
                    'limit' => 1,
                    'orderby' => 'date',
                    'order' => 'DESC',
                ));
                if (!empty($orders_by_refund_id)) {
                    $order = $orders_by_refund_id[0];
                    error_log('PP Webhook: Found order #' . $order->get_id() . ' by transfer_id (refund_id) for ' . $event_type);
                }
            }
            if (!$order && $destination_email) {
                $orders_pending_refund = wc_get_orders(array(
                    'meta_key' => '_sp_refund_pending',
                    'meta_value' => 'yes',
                    'meta_compare' => '=',
                    'limit' => 5,
                    'orderby' => 'date',
                    'order' => 'DESC',
                ));
                foreach ($orders_pending_refund as $candidate) {
                    if (strtolower(trim($candidate->get_billing_email())) === strtolower(trim($destination_email))) {
                        $order = $candidate;
                        error_log('PP Webhook: Found order #' . $order->get_id() . ' by destination_email for ' . $event_type);
                        break;
                    }
                }
            }
        }
        
        if (!$order && !$origin_id) {
            error_log('PP Webhook: No order found and no origin ID provided');
            return;
        }
        
        // Find the order by origin ID (purchase session ID) if not already found
        if (!$order && $origin_id) {
            error_log('PP Webhook: Searching for order with origin ID: ' . $origin_id);
            $order = $this->find_order_by_purchase_session_id($origin_id);
        }
        
        if ($order) {
            error_log('✅ PP Webhook: Order found by purchase session ID: Order #' . $order->get_id());
            error_log('PP Webhook: Order status: ' . $order->get_status());
            error_log('PP Webhook: Order payment method: ' . $order->get_payment_method());
        } else {
            error_log('⚠️ PP Webhook: Order NOT found by purchase session ID: ' . $origin_id);
        }
        
        // For recurring payments, also try to find by agreement_id
        if (!$order && isset($data['agreement_id'])) {
            $agreement_id = $data['agreement_id'];
            error_log('PP Webhook: Order not found by origin ID, trying agreement ID: ' . $agreement_id);
            
            // Find subscription order by agreement_id
            $orders_by_agreement = wc_get_orders(array(
                'meta_key' => '_sp_agreement_id',
                'meta_value' => $agreement_id,
                'meta_compare' => '=',
                'limit' => 1,
                'orderby' => 'date',
                'order' => 'ASC' // Get the first (original) subscription order
            ));
            
            if (!empty($orders_by_agreement)) {
                $order = $orders_by_agreement[0];
                error_log('✅ PP Webhook: Found subscription order #' . $order->get_id() . ' by agreement ID');
            }
        }
        
        // For transfer events (refunds), also try to find by payment_id or refund_id
        if (!$order && $event_type === 'transfer' && isset($data['payment_id'])) {
            $payment_id = $data['payment_id'];
            error_log('PP Webhook: Order not found by origin ID, trying payment ID for transfer: ' . $payment_id);
            
            // Find order by payment_id (for refunds)
            $orders_by_payment = wc_get_orders(array(
                'meta_key' => '_sp_payment_id',
                'meta_value' => $payment_id,
                'meta_compare' => '=',
                'limit' => 1,
                'orderby' => 'date',
                'order' => 'DESC'
            ));
            
            if (!empty($orders_by_payment)) {
                $order = $orders_by_payment[0];
                error_log('✅ PP Webhook: Found order #' . $order->get_id() . ' by payment ID for transfer');
            }
        }
        
        // Also try to find by refund_id if this is a transfer event
        if (!$order && $event_type === 'transfer' && isset($data['transfer_id'])) {
            // Check all orders with pending refunds
            $orders_with_refunds = wc_get_orders(array(
                'meta_key' => '_sp_refund_pending',
                'meta_value' => 'yes',
                'meta_compare' => '=',
                'limit' => 10,
                'orderby' => 'date',
                'order' => 'DESC'
            ));
            
            // Try to match by payment_id in webhook data
            if (!empty($orders_with_refunds) && isset($data['payment_id'])) {
                foreach ($orders_with_refunds as $refund_order) {
                    $order_payment_id = $refund_order->get_meta('_sp_payment_id');
                    if ($order_payment_id === $data['payment_id']) {
                        $order = $refund_order;
                        error_log('✅ PP Webhook: Found order #' . $order->get_id() . ' by matching payment ID with pending refund');
                        break;
                    }
                }
            }
        }
        
        if (!$order) {
            error_log('❌ PP Webhook: Order not found for event: ' . $event_type . ( $origin_id ? ' (origin_id: ' . $origin_id . ')' : '' ));
            error_log('PP Webhook: Event type: ' . $event_type);
            error_log('PP Webhook: Merchant ID: ' . $merchant_id);
            
            // Debug: List all orders with Stablecoin Pay metadata
            $all_orders = wc_get_orders(array('limit' => 10, 'orderby' => 'date', 'order' => 'DESC'));
            error_log('PP Webhook: Recent orders:');
            foreach ($all_orders as $test_order) {
                $test_session_id = $test_order->get_meta('_sp_purchase_session_id');
                $test_sp_id = $test_order->get_meta('_sp_order_id');
                if ($test_session_id || $test_sp_id) {
                    error_log('  Order #' . $test_order->get_id() . ' - Session: ' . $test_session_id . ' - PP ID: ' . $test_sp_id);
                }
            }
            
            // Try to find by WooCommerce order ID in metadata
            if (isset($data['metadata']['woocommerce_order_id'])) {
                $wc_order_id = $data['metadata']['woocommerce_order_id'];
                error_log('PP Webhook: Trying to find order by WooCommerce ID: ' . $wc_order_id);
                $order = wc_get_order($wc_order_id);
                if ($order) {
                    error_log('✅ PP Webhook: Found order by WooCommerce ID: ' . $wc_order_id);
                } else {
                    error_log('❌ PP Webhook: Order not found by WooCommerce ID: ' . $wc_order_id);
                }
            }
            
            if (!$order) {
                return;
            }
        }
        
        error_log('✅ PP Webhook: Found order ID: ' . $order->get_id() . ( $origin_id ? ' for origin ID: ' . $origin_id : ' for event: ' . $event_type ));
        error_log('PP Webhook: Order status before update: ' . $order->get_status());
        
        // Ensure order is associated with a customer if possible
        if (!$order->get_customer_id() && $order->get_billing_email()) {
            $user = get_user_by('email', $order->get_billing_email());
            if ($user) {
                $order->set_customer_id($user->ID);
                $order->save();
                error_log('✅ PP Webhook: Associated order with user ID: ' . $user->ID . ' by email: ' . $order->get_billing_email());
            }
        }
        
        // Verify merchant ID matches
        $order_merchant_id = $order->get_meta('_sp_merchant_id');
        if ($order_merchant_id && $merchant_id) {
            // Remove mrch_ prefix if present for comparison
            $clean_webhook_merchant_id = str_replace('mrch_', '', $merchant_id);
            $clean_order_merchant_id = str_replace('mrch_', '', $order_merchant_id);
            
            if ($clean_order_merchant_id !== $clean_webhook_merchant_id) {
                error_log('PP Webhook: Merchant ID mismatch for order: ' . $order->get_id());
                error_log('PP Webhook: Order merchant ID: ' . $clean_order_merchant_id);
                error_log('PP Webhook: Webhook merchant ID: ' . $clean_webhook_merchant_id);
                return;
            }
        }
        
        switch ($event_type) {
            case 'payment':
                $this->handle_payment_completed($order, $data);
                break;
                
            case 'failed_payment':
                // Check if this failed_payment is for THIS order's payment
                $webhook_payment_id = $data['payment_id'] ?? null;
                $order_payment_id = $order->get_meta('_sp_payment_id');
                
                // Only process failed_payment if:
                // 1. Order is still pending/failed (not already successful)
                // 2. AND the payment ID matches (this is the current order's payment, not a future one)
                
                $current_status = $order->get_status();
                
                // If order is already successful, always ignore (payment succeeded)
                if (in_array($current_status, array('processing', 'completed', 'on-hold'))) {
                    error_log('⚠️ PP Webhook: Ignoring failed_payment webhook - order #' . $order->get_id() . ' is already ' . $current_status);
                    error_log('⚠️ PP Webhook: Payment was successful on-chain, ignoring failed_payment');
                    
                    $order->add_order_note(
                        __('Stablecoin Pay: Ignored failed_payment webhook - payment already successful (order status: ' . $current_status . ')', 'stablecoin-pay')
                    );
                    $order->save();
                } 
                // If order is pending but payment IDs don't match, ignore (this is a future payment failure)
                elseif ($order_payment_id && $webhook_payment_id && $order_payment_id !== $webhook_payment_id) {
                    error_log('⚠️ PP Webhook: Ignoring failed_payment webhook - payment ID mismatch');
                    error_log('⚠️ PP Webhook: Order payment ID: ' . $order_payment_id . ', Webhook payment ID: ' . $webhook_payment_id);
                    error_log('⚠️ PP Webhook: This failure is for a different payment (likely future subscription payment)');
                    
                    $order->add_order_note(
                        __('Stablecoin Pay: Ignored failed_payment webhook - failure is for different payment ID (likely future subscription payment)', 'stablecoin-pay')
                    );
                    $order->save();
                }
                // Otherwise, this is a real failure for this order's payment
                else {
                    error_log('❌ PP Webhook: Processing failed_payment for order #' . $order->get_id());
                    $this->handle_payment_failed($order, $data);
                }
                break;
                
            case 'cancellation':
                $this->handle_payment_cancelled($order, $data);
                break;
                
            case 'transfer':
                $this->handle_transfer_completed($order, $data);
                break;
                
            case 'failed_transfer':
                $this->handle_transfer_failed($order, $data);
                break;
                
            default:
                error_log('PP Webhook: Unknown event type: ' . $event_type);
        }
    }
    
    /**
     * Handle payment completed
     */
    private function handle_payment_completed($order, $data) {
        error_log('🎉 PP Webhook: Processing payment completion for order #' . $order->get_id());
        error_log('PP Webhook: Current order status: ' . $order->get_status());
        
        // Check if this is a recurring payment for a subscription
        $agreement_id = $data['agreement_id'] ?? null;
        $is_subscription = $order->get_meta('_sp_is_subscription') === 'yes';
        
        // If this is a subscription payment and the order is already processed, it's a recurring payment
        // We need to create a renewal order instead of updating the original
        if ($is_subscription && $agreement_id) {
            $existing_agreement_id = $order->get_meta('_sp_agreement_id');
            $order_status = $order->get_status();
            
            // Check if this order is already completed/processing (meaning it's the original subscription order)
            // and we have a matching agreement_id, then this is a recurring payment
            if ($existing_agreement_id === $agreement_id && 
                in_array($order_status, array('processing', 'completed', 'on-hold'))) {
                
                error_log('🔄 PP Webhook: This is a recurring payment for subscription order #' . $order->get_id());
                error_log('🔄 PP Webhook: Creating renewal order...');
                
                // Create renewal order
                $renewal_order = $this->create_renewal_order($order, $data);
                
                if ($renewal_order) {
                    error_log('✅ PP Webhook: Renewal order #' . $renewal_order->get_id() . ' created successfully');
                    // Process the renewal order instead of the original
                    $order = $renewal_order;
                } else {
                    error_log('❌ PP Webhook: Failed to create renewal order, processing original order instead');
                }
            }
        }
        
        // Store status as Processing only (merchant sees Processing). Customer sees "Completed" via display filter in class-sp-subscriptions.
        $current_status = $order->get_status();
        error_log('PP Webhook: Current order status BEFORE update: ' . $current_status);
        $target_status = 'processing';
        error_log('PP Webhook: Target status: ' . $target_status);
        
        // Update status with error handling
        try {
            $status_updated = $order->update_status($target_status, __('Payment received', 'stablecoin-pay'));
            error_log('PP Webhook: update_status() returned: ' . ($status_updated ? 'TRUE' : 'FALSE'));
            
            // Verify status was actually updated
            $order->save(); // Ensure changes are persisted
            $new_status = $order->get_status();
            error_log('PP Webhook: Order status AFTER update: ' . $new_status);
            
            if ($new_status !== $target_status) {
                error_log('⚠️ PP Webhook: WARNING - Status update may have failed! Expected: ' . $target_status . ', Got: ' . $new_status);
                
                // Try direct database update as fallback
                wp_update_post(array(
                    'ID' => $order->get_id(),
                    'post_status' => 'wc-' . $target_status
                ));
                
                // Reload order and verify
                $order = wc_get_order($order->get_id());
                $final_status = $order->get_status();
                error_log('PP Webhook: Final status after fallback update: ' . $final_status);
                
                if ($final_status === $target_status) {
                    error_log('✅ PP Webhook: Status updated successfully via fallback method');
                } else {
                    error_log('❌ PP Webhook: CRITICAL - Status update failed even with fallback!');
                    error_log('❌ PP Webhook: This may be caused by another plugin blocking status updates');
                }
            } else {
                error_log('✅  Webhook: Status updated successfully to: ' . $target_status);
            }
        } catch (Exception $e) {
            error_log('❌  Webhook: Exception during status update: ' . $e->getMessage());
            error_log('❌ Webhook: Stack trace: ' . $e->getTraceAsString());
        } catch (Error $e) {
            error_log('❌  Webhook: Fatal error during status update: ' . $e->getMessage());
            error_log('❌  Webhook: Stack trace: ' . $e->getTraceAsString());
        }
        
        // Ensure payment method and title are set (whitelabel title for customer-facing display)
        $payment_method = $order->get_payment_method();
        $order->set_payment_method('sp');
        $order->set_payment_method_title($this->get_sp_payment_method_title());
        if ($payment_method !== 'sp') {
            $order->save();
            error_log(' Webhook: Set payment method to sp');
        }
        
        // Add order note with transaction details
        $transaction_details = $data['transaction_details'] ?? array();
        $transaction_id = $transaction_details['transaction_id'] ?? 'N/A';
        $transaction_hash = $transaction_details['transaction_hash'] ?? 'N/A';
        
        // Extract user information from webhook payload
        $user_data = $data['user'] ?? array();
        if (!empty($user_data)) {
            // Update order billing information from webhook user data if available
            if (isset($user_data['first_name']) && !empty($user_data['first_name'])) {
                $order->set_billing_first_name($user_data['first_name']);
            }
            if (isset($user_data['last_name']) && !empty($user_data['last_name'])) {
                $order->set_billing_last_name($user_data['last_name']);
            }
            if (isset($user_data['email']) && !empty($user_data['email'])) {
                $order->set_billing_email($user_data['email']);
            }
            // Store subscriber_id if available
            if (isset($user_data['subscriber_id']) && !empty($user_data['subscriber_id'])) {
                $order->update_meta_data('_sp_subscriber_id', $user_data['subscriber_id']);
            }
        }
        
        $order->add_order_note(
            __('Payment complete', 'stablecoin-pay')
        );
        
        // Store transaction details in WooCommerce
        if (isset($data['payment_id'])) {
            $order->update_meta_data('_sp_payment_id', $data['payment_id']);
        }
        
        if (isset($data['agreement_id'])) {
            $order->update_meta_data('_sp_agreement_id', $data['agreement_id']);
        }
        
        // Store next payment date for subscription (customer view-order). Same field as merchant uses from agreement API.
        $next_payment = $this->get_next_payment_from_webhook_data($data);
        if ($next_payment !== '') {
            $order->update_meta_data('_sp_next_payment', $next_payment);
        }
        
        if (isset($transaction_details['transaction_id'])) {
            $order->update_meta_data('_sp_transaction_id', $transaction_details['transaction_id']);
        }
        
        if (isset($transaction_details['transaction_hash'])) {
            $order->update_meta_data('_sp_transaction_hash', $transaction_details['transaction_hash']);
        }
        
        if (isset($transaction_details['chain_id'])) {
            $order->update_meta_data('_sp_chain_id', $transaction_details['chain_id']);
        }
        
        // Store network name from webhook metadata if available (for explorer URLs)
        if (isset($transaction_details['network'])) {
            $order->update_meta_data('_sp_network_name', $transaction_details['network']);
        } elseif (isset($data['network'])) {
            $order->update_meta_data('_sp_network_name', $data['network']);
        }
        
        // Store explorer URL directly from webhook if provided
        if (isset($transaction_details['explorer_url'])) {
            $order->update_meta_data('_sp_explorer_url', $transaction_details['explorer_url']);
        } elseif (isset($data['explorer_url'])) {
            $order->update_meta_data('_sp_explorer_url', $data['explorer_url']);
        }
        
        // Store customer wallet address if available
        if (isset($transaction_details['customer_wallet_address'])) {
            $order->update_meta_data('_customer_wallet_address', $transaction_details['customer_wallet_address']);
        }
        
        // Store signing address from agreement message if available
        if (isset($data['agreement']['message']['signing_address'])) {
            $order->update_meta_data('_customer_wallet_address', $data['agreement']['message']['signing_address']);
            error_log('🔑 PP Webhook - Stored signing address as customer wallet: ' . $data['agreement']['message']['signing_address']);
        }
        
        // Store complete agreement message data for refunds
        if (isset($data['agreement']['message'])) {
            $agreement_message = $data['agreement']['message'];
            $order->update_meta_data('_sp_agreement_message', json_encode($agreement_message));
            error_log('🔑 PP Webhook - Stored agreement message: ' . json_encode($agreement_message));
            
            // Extract specific fields for easy access
            if (isset($agreement_message['signing_address'])) {
                $order->update_meta_data('_sp_signing_address', $agreement_message['signing_address']);
            }
            if (isset($agreement_message['permitId'])) {
                $order->update_meta_data('_sp_permit_id', $agreement_message['permitId']);
            }
        }
        
        // Store token symbol - use currency from transaction_details (currency field = token symbol)
        // Fallback to payments API lookup if not available
        $token_symbol = null;
        if (isset($transaction_details['currency']) && !empty($transaction_details['currency'])) {
            $token_symbol = $transaction_details['currency'];
            $order->update_meta_data('_sp_token_symbol', $token_symbol);
        } elseif (isset($data['currency']) && !empty($data['currency'])) {
            $token_symbol = $data['currency'];
            $order->update_meta_data('_sp_token_symbol', $token_symbol);
        } elseif (isset($data['payment_id']) && !empty($data['payment_id'])) {
            // Fallback: Look up payment details via API to get currency/token symbol
            $api_client = new SP_API_Client();
            $payment_details = $api_client->get_payment_details($data['payment_id']);
            if (!is_wp_error($payment_details) && isset($payment_details['currency'])) {
                $token_symbol = $payment_details['currency'];
                $order->update_meta_data('_sp_token_symbol', $token_symbol);
            }
        }
        
        $order->save();
        
        // Emails are now handled by WooCommerce order status hooks
        
        // Clear cart and session data since payment is now complete (only if available)
        if (function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
        }
        if (function_exists('WC') && WC()->session) {
            // Clear all Stablecoin Pay session variables after successful payment
            WC()->session->set('sp_order_id', null);
            WC()->session->set('sp_purchase_session_id', null);
            WC()->session->set('sp_pending_order_id', null);
            
            // Also clear checkout URL from session (if order ID is known)
            if ($order && method_exists($order, 'get_id')) {
                WC()->session->set('sp_checkout_url_' . $order->get_id(), null);
            }
        }
        error_log('✅ PP Webhook - Cleared cart/session if available after successful payment');
        
        // Set a flag to trigger redirect to order-received page
        $order->update_meta_data('_sp_redirect_to_received', 'yes');
        $order->save();
        
        // Emails are handled by WooCommerce order status hooks, not webhook
        
        // Log payment confirmation
        error_log('PP Webhook: PAYMENT COMPLETE for order #' . $order->get_id() . ' | Transaction Hash: ' . ($transaction_hash ?? 'N/A'));
    }
    
    /**
     * Handle payment failed
     * Only called if order is NOT already in a successful state
     */
    private function handle_payment_failed($order, $data) {
        error_log('❌ PP Webhook: Processing payment failure for order #' . $order->get_id());
        
        $failure_reason = $data['failure_reason'] ?? 'Unknown';
        error_log('❌ PP Webhook: Failure reason: ' . $failure_reason);
        
        // Only mark as failed if order is still pending
        // If it's already processing/completed, don't change it
        $current_status = $order->get_status();
        if (!in_array($current_status, array('processing', 'completed', 'on-hold'))) {
            $order->update_status('failed', __('Payment Failed', 'stablecoin-pay'));
        } else {
            error_log('⚠️ PP Webhook: Order #' . $order->get_id() . ' already ' . $current_status . ' - not changing to failed');
        }
        
        // Add order note
        $order->add_order_note(
            sprintf(
                __('Stablecoin Pay Payment Failed - Reason: %s', 'stablecoin-pay'),
                $failure_reason
            )
        );
        
        // Store failure reason
        if (isset($data['failure_reason'])) {
            $order->update_meta_data('_sp_failure_reason', $failure_reason);
        }
        
        $order->save();
    }
    
    /**
     * Handle payment cancelled
     */
    private function handle_payment_cancelled($order, $data) {
        $order->update_status('cancelled', __('Payment Cancelled', 'stablecoin-pay'));
        
        // Add order note
        $order->add_order_note(__('Stablecoin Pay Payment Cancelled - Customer cancelled the payment', 'stablecoin-pay'));
        
        $order->save();
    }
    
    /**
     * Handle transfer webhook (type = "transfer").
     * Only mark as completed when payload status indicates success; otherwise treat as failed.
     * TransferPayload: hash, network, to_address, amount_in_usd, status, transfer_id, wallet_id.
     */
    private function handle_transfer_completed($order, $data) {
        error_log('🔄 PP Webhook: Processing transfer event for order #' . $order->get_id());
        
        $status = isset($data['status']) ? strtolower(trim((string) $data['status'])) : '';
        $transfer_id = $data['transfer_id'] ?? null;
        $hash = $data['hash'] ?? null;
        $network = $data['network'] ?? null;
        $wallet_id = $data['wallet_id'] ?? null;
        $amount = $data['amount'] ?? $data['amount_in_usd'] ?? null;
        $to_address = $data['to_address'] ?? null;
        $destination_email = $data['destination_email'] ?? null;
        
        // Only mark refund complete when status is explicitly success-like (fix: do not mark failed transfers complete)
        $success_statuses = array('completed', 'confirmed', 'success', 'succeeded', 'complete');
        $is_success = in_array($status, $success_statuses, true);
        if (!$is_success) {
            error_log('❌ PP Webhook: Transfer event status not success: "' . $status . '" – treating as failed');
            $this->handle_transfer_failed($order, $data);
            return;
        }
        
        $refund_id = $order->get_meta('_sp_refund_id');
        $refund_pending = $order->get_meta('_sp_refund_pending');
        
        if ($refund_pending === 'yes' || !empty($refund_id)) {
            error_log('💰 PP Webhook: This is a refund transfer - refund ID: ' . ($refund_id ?: 'N/A'));
            
            $order->update_meta_data('_sp_refund_status', 'completed');
            $order->update_meta_data('_sp_refund_pending', 'no');
            if ($hash !== null && $hash !== '') {
                $order->update_meta_data('_sp_refund_transaction_hash', $hash);
            }
            if ($transfer_id) {
                $order->update_meta_data('_sp_refund_transfer_id', $transfer_id);
            }
            
            $refund_note = sprintf(
                __('✅ Stablecoin Pay Refund Completed: Transfer ID: %s. Refund has been successfully sent to customer.', 'stablecoin-pay'),
                $transfer_id ?: 'N/A'
            );
            if ($hash) {
                $refund_note .= ' Hash: ' . $hash;
            }
            if ($network) {
                $refund_note .= ' Network: ' . $network;
            }
            if ($amount) {
                $refund_note .= ' Amount: ' . $amount;
            }
            if ($to_address) {
                $refund_note .= ' To: ' . $to_address;
            } elseif ($destination_email) {
                $refund_note .= ' To: ' . $destination_email;
            }
            $order->add_order_note($refund_note);
            
            if ($order->get_status() !== 'refunded') {
                $order->update_status('refunded', __('Refund completed via Stablecoin Pay', 'stablecoin-pay'));
            }
            error_log('✅ PP Webhook: Refund marked as successful for order #' . $order->get_id());
        } else {
            $order->update_status('processing', __('Transfer completed via Stablecoin Pay', 'stablecoin-pay'));
            $note = sprintf(
                __('Stablecoin Pay transfer completed. Transfer ID: %s', 'stablecoin-pay'),
                $transfer_id ?: 'N/A'
            );
            if ($hash) {
                $note .= ', Hash: ' . $hash;
            }
            if ($network) {
                $note .= ', Network: ' . $network;
            }
            $order->add_order_note($note);
        }
        
        if ($transfer_id) {
            $order->update_meta_data('_sp_transfer_id', $transfer_id);
        }
        if ($hash !== null && $hash !== '') {
            $order->update_meta_data('_sp_transfer_hash', $hash);
        }
        if ($wallet_id !== null && $wallet_id !== '') {
            $order->update_meta_data('_sp_wallet_id', $wallet_id);
        }
        if ($network !== null && $network !== '') {
            $order->update_meta_data('_sp_network', $network);
        }
        
        $order->save();
    }
    
    /**
     * Handle transfer failed
     * Only add an order note that the transfer failed. Do not change order status or any meta.
     * Order stays e.g. completed/processing – we do not process or mark the refund.
     */
    private function handle_transfer_failed($order, $data) {
        error_log('❌ PP Webhook: Transfer failed for order #' . $order->get_id());
        
        $failure_reason = $data['failure_reason'] ?? $data['error'] ?? $data['status'] ?? 'Unknown error';
        $reason = is_string($failure_reason) ? $failure_reason : 'Unknown error';
        $transfer_id = $data['transfer_id'] ?? null;

        $note = sprintf(__('Transfer failed: %s', 'stablecoin-pay'), $reason);
        if ($transfer_id) {
            $note .= ' (Transfer ID: ' . $transfer_id . ')';
        }
        $order->add_order_note($note);

        // Do not change order status. Do not update any meta. Do not say the order is refunded.
        $order->save();
    }
    
    /**
     * Find order by purchase session ID
     */
    private function find_order_by_purchase_session_id($purchase_session_id) {
        // Search for order with matching purchase session ID
        $orders = wc_get_orders(array(
            'meta_key' => '_sp_purchase_session_id',
            'meta_value' => $purchase_session_id,
            'limit' => 1
        ));
        
        if (!empty($orders)) {
            return $orders[0];
        }
        
        // If not found, try with different prefix variations
        $variations = array(
            'sess_' . $purchase_session_id,
            'wc_' . $purchase_session_id,
            $purchase_session_id
        );
        
        // Also try removing sess_ prefix if it exists
        if (strpos($purchase_session_id, 'sess_') === 0) {
            $variations[] = substr($purchase_session_id, 5); // Remove 'sess_' prefix
        }
        
        foreach ($variations as $variation) {
            $orders = wc_get_orders(array(
                'meta_key' => '_sp_purchase_session_id',
                'meta_value' => $variation,
                'limit' => 1
            ));
            
            if (!empty($orders)) {
                return $orders[0];
            }
        }
        
        return null;
    }
    
    /**
     * Authenticate an inbound delivery.
     *
     * Two schemes are accepted, in priority order:
     *
     *  1. HMAC signature (auto-provisioned webhooks). The signing secret is issued
     *     by the API at registration and stored locally.
     *  2. Legacy shared secret, passed as a `secret` query arg or an
     *     `x-coinsub-secret` header. This is how manually-created webhooks
     *     authenticate; kept so merchants who set theirs up by hand keep working
     *     until their record is re-provisioned.
     *
     * @return true|WP_Error
     */
    private function verify_delivery($request, $raw_body) {
        $signature      = $request->get_header('x-webhook-signature');
        $signing_secret = class_exists('SP_Webhook_Provisioner')
            ? SP_Webhook_Provisioner::signing_secret()
            : '';

        if (!empty($signature)) {
            if ($signing_secret === '') {
                return new WP_Error(
                    'sp_no_signing_secret',
                    'Signed delivery received but this site has no signing secret stored. Re-save the payment settings to register the webhook.',
                    401
                );
            }
            return $this->verify_signature($request, $raw_body, $signature, $signing_secret);
        }

        $legacy_secret = get_option('sp_webhook_secret', '');
        if (!empty($legacy_secret)) {
            $provided = $request->get_param('secret');
            if (!$provided) {
                $headers  = $request->get_headers();
                $provided = $headers['x-coinsub-secret'][0] ?? null;
            }
            if (empty($provided)) {
                return new WP_Error('sp_secret_required', 'Unauthorized - secret required', 401);
            }
            if (!hash_equals($legacy_secret, (string) $provided)) {
                return new WP_Error('sp_secret_mismatch', 'Unauthorized - secret mismatch', 401);
            }
            error_log('✅ PP Webhook - Verified via legacy shared secret');
            return true;
        }

        // Provisioned sites must present a signature; an unsigned delivery here is
        // either a misconfiguration or someone probing the endpoint.
        if ($signing_secret !== '') {
            return new WP_Error('sp_signature_required', 'Unauthorized - signature required', 401);
        }

        error_log('⚠️ PP Webhook - No secret configured, allowing webhook (not recommended for production)');
        return true;
    }

    /**
     * HMAC-SHA256 over "{timestamp}.{raw_body}", base64, constant-time compared.
     *
     * @return true|WP_Error
     */
    private function verify_signature($request, $raw_body, $signature, $secret) {
        $version = $request->get_header('x-webhook-signature-version');
        if (!empty($version) && strtolower(trim($version)) !== 'v1') {
            return new WP_Error(
                'sp_unsupported_signature_version',
                'Unsupported signature version: ' . sanitize_text_field($version),
                400
            );
        }

        $timestamp = $request->get_header('x-webhook-timestamp');
        if ($timestamp === null || $timestamp === '' || !ctype_digit((string) $timestamp)) {
            return new WP_Error('sp_missing_timestamp', 'Unauthorized - missing or malformed timestamp', 401);
        }

        // Bound replay. A delivery far outside this window is either a replay or a
        // badly skewed clock; either way it is not safe to act on.
        $skew = abs(time() - (int) $timestamp);
        if ($skew > self::SIGNATURE_TOLERANCE) {
            return new WP_Error(
                'sp_stale_timestamp',
                'Unauthorized - timestamp outside the allowed window (' . $skew . 's). Check this server\'s clock.',
                401
            );
        }

        $expected = base64_encode(
            hash_hmac('sha256', $timestamp . '.' . $raw_body, $secret, true)
        );

        if (!hash_equals($expected, (string) $signature)) {
            return new WP_Error('sp_invalid_signature', 'Unauthorized - invalid signature', 401);
        }

        error_log('✅ PP Webhook - Signature verified');
        return true;
    }

    /**
     * Claim an event id, returning false if it has already been handled.
     *
     * Retries mean the same event can arrive several times; only the first claim
     * should do work.
     */
    private function claim_event($event_id) {
        $key = 'sp_evt_' . md5((string) $event_id);

        if (get_transient($key)) {
            return false;
        }

        set_transient($key, 1, self::EVENT_DEDUPE_TTL);
        return true;
    }
    
    /**
     * Test API connection
     */
    public function test_connection() {
        if (!wp_verify_nonce($_POST['nonce'], 'sp_test_connection')) {
            wp_die('Security check failed');
        }
        
        $api_client = new SP_API_Client();
        $result = $api_client->test_connection();
        
        if ($result) {
            wp_send_json_success('Connection successful');
        } else {
            wp_send_json_error('Connection failed');
        }
    }
    
    /**
     * Check payment status for frontend polling
     */
    public function check_payment_status() {
        error_log('🔍 PP - Checking payment status...');
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['security'], 'sp_check_payment')) {
            error_log('❌ PP - Invalid nonce');
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        error_log('✅ PP - Nonce verified');
        
        // Get the most recent order for this user
        $user_id = get_current_user_id();
        $orders = wc_get_orders(array(
            'customer_id' => $user_id,
            'status' => array('pending', 'processing', 'completed'),
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        
        if (empty($orders)) {
            wp_send_json_success(array(
                'payment_completed' => false,
                'message' => 'No recent orders found'
            ));
            return;
        }
        
        $order = $orders[0];
        
        // Check if order is completed
        if ($order->get_status() === 'completed') {
            wp_send_json_success(array(
                'payment_completed' => true,
                'redirect_url' => $order->get_checkout_order_received_url(),
                'order_id' => $order->get_id()
            ));
        } else {
            wp_send_json_success(array(
                'payment_completed' => false,
                'order_status' => $order->get_status(),
                'order_id' => $order->get_id()
            ));
        }
    }
    
    // Email functions removed - now handled by WooCommerce order status hooks
    
    // Merchant notification function removed - now handled by WooCommerce order status hooks
    
    
    // Customer email function removed - now handled by WooCommerce order status hooks
    
    // WooCommerce fallback email function removed - now handled by WooCommerce order status hooks
    
    /**
     * Get network name for chain ID
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
            '421613' => 'Arbitrum Nova',
            '295' => 'Hedera Mainnet',
            '296' => 'Hedera Testnet'
        );
        
        return isset($networks[$chain_id]) ? $networks[$chain_id] : 'Chain ID ' . $chain_id;
    }
    
    /**
     * Extract next payment date from webhook payload.
     * API uses next_process_date (ISO string) or next_payment_date (timestamp).
     *
     * @param array $data Webhook payload (may have agreement with these fields)
     * @return string Raw value or empty string
     */
    private function get_next_payment_from_webhook_data($data) {
        if (!is_array($data)) {
            return '';
        }
        foreach (array('next_process_date', 'next_payment_date') as $key) {
            if (isset($data[$key]) && $data[$key] !== '' && $data[$key] !== null) {
                return $data[$key];
            }
        }
        if (isset($data['agreement']) && is_array($data['agreement'])) {
            foreach (array('next_process_date', 'next_payment_date') as $key) {
                if (isset($data['agreement'][$key]) && $data['agreement'][$key] !== '' && $data['agreement'][$key] !== null) {
                    return $data['agreement'][$key];
                }
            }
        }
        return '';
    }
    
    /**
     * Payment method title for orders (whitelabel name for customer-facing display)
     *
     * @return string
     */
    private function get_sp_payment_method_title() {
        $name = class_exists('SP_Whitelabel_Branding') ? SP_Whitelabel_Branding::get_whitelabel_plugin_name_from_config() : null;
        if (!empty($name)) {
            return sprintf(__('Pay with %s', 'stablecoin-pay'), $name);
        }
        return __('Pay with Stablecoin Pay', 'stablecoin-pay');
    }
    
    /**
     * Create a renewal order for recurring subscription payments
     * 
     * @param WC_Order $parent_order The original subscription order
     * @param array $payment_data Webhook payment data
     * @return WC_Order|false The renewal order or false on failure
     */
    private function create_renewal_order($parent_order, $payment_data) {
        try {
            error_log('🔄 PP: Creating renewal order from parent order #' . $parent_order->get_id());
            
            // Create new order
            $renewal_order = wc_create_order();
            
            if (is_wp_error($renewal_order) || !$renewal_order) {
                error_log('❌ PP: Failed to create renewal order');
                return false;
            }
            
            error_log('✅ PP: Renewal order #' . $renewal_order->get_id() . ' created');
            
            // Copy customer information
            $renewal_order->set_customer_id($parent_order->get_customer_id());
            $renewal_order->set_billing_first_name($parent_order->get_billing_first_name());
            $renewal_order->set_billing_last_name($parent_order->get_billing_last_name());
            $renewal_order->set_billing_company($parent_order->get_billing_company());
            $renewal_order->set_billing_address_1($parent_order->get_billing_address_1());
            $renewal_order->set_billing_address_2($parent_order->get_billing_address_2());
            $renewal_order->set_billing_city($parent_order->get_billing_city());
            $renewal_order->set_billing_state($parent_order->get_billing_state());
            $renewal_order->set_billing_postcode($parent_order->get_billing_postcode());
            $renewal_order->set_billing_country($parent_order->get_billing_country());
            $renewal_order->set_billing_email($parent_order->get_billing_email());
            $renewal_order->set_billing_phone($parent_order->get_billing_phone());
            
            // Copy shipping information
            if ($parent_order->has_shipping_address()) {
                $renewal_order->set_shipping_first_name($parent_order->get_shipping_first_name());
                $renewal_order->set_shipping_last_name($parent_order->get_shipping_last_name());
                $renewal_order->set_shipping_company($parent_order->get_shipping_company());
                $renewal_order->set_shipping_address_1($parent_order->get_shipping_address_1());
                $renewal_order->set_shipping_address_2($parent_order->get_shipping_address_2());
                $renewal_order->set_shipping_city($parent_order->get_shipping_city());
                $renewal_order->set_shipping_state($parent_order->get_shipping_state());
                $renewal_order->set_shipping_postcode($parent_order->get_shipping_postcode());
                $renewal_order->set_shipping_country($parent_order->get_shipping_country());
            }
            
            // Copy order items
            foreach ($parent_order->get_items() as $item_id => $item) {
                $product = $item->get_product();
                
                if (!$product) {
                    error_log('⚠️ PP: Product not found for item #' . $item_id . ', skipping');
                    continue;
                }
                
                $item_data = array(
                    'name' => $item->get_name(),
                    'quantity' => $item->get_quantity(),
                    'total' => $item->get_total(),
                    'subtotal' => $item->get_subtotal(),
                    'tax_class' => $item->get_tax_class(),
                    'product_id' => $product->get_id(),
                    'variation_id' => $item->get_variation_id(),
                );
                
                // Add variation attributes if it's a variation
                if ($item->get_variation_id()) {
                    foreach ($item->get_meta_data() as $meta) {
                        if (strpos($meta->key, 'pa_') === 0 || strpos($meta->key, 'attribute_') === 0) {
                            $item_data['variation'][$meta->key] = $meta->value;
                        }
                    }
                }
                
                $renewal_order->add_product($product, $item->get_quantity(), $item_data);
            }
            
            // Copy shipping methods
            foreach ($parent_order->get_items('shipping') as $item_id => $shipping_item) {
                $item = new WC_Order_Item_Shipping();
                $item->set_method_id($shipping_item->get_method_id());
                $item->set_method_title($shipping_item->get_method_title());
                $item->set_instance_id($shipping_item->get_instance_id());
                $item->set_total($shipping_item->get_total());
                $item->set_total_tax($shipping_item->get_total_tax());
                
                // Copy shipping item meta
                foreach ($shipping_item->get_meta_data() as $meta) {
                    $item->add_meta_data($meta->key, $meta->value);
                }
                
                $renewal_order->add_item($item);
            }
            
            // Copy fees
            foreach ($parent_order->get_items('fee') as $item_id => $fee_item) {
                $fee = new WC_Order_Item_Fee();
                $fee->set_name($fee_item->get_name());
                $fee->set_total($fee_item->get_total());
                $fee->set_tax_class($fee_item->get_tax_class());
                $fee->set_tax_status($fee_item->get_tax_status());
                $fee->set_total_tax($fee_item->get_total_tax());
                $renewal_order->add_item($fee);
            }
            
            // Set payment method (whitelabel title for display)
            $renewal_order->set_payment_method('sp');
            $renewal_order->set_payment_method_title($this->get_sp_payment_method_title());
            
            // Set currency
            $renewal_order->set_currency($parent_order->get_currency());
            
            // Calculate totals
            $renewal_order->calculate_totals();
            
            // Set parent/child relationship
            $renewal_order->update_meta_data('_sp_parent_subscription_order', $parent_order->get_id());
            $renewal_order->update_meta_data('_sp_is_renewal_order', 'yes');
            $renewal_order->update_meta_data('_sp_agreement_id', $parent_order->get_meta('_sp_agreement_id'));
            
            // Track renewal orders in parent order
            $renewal_orders = $parent_order->get_meta('_sp_renewal_orders');
            if (!is_array($renewal_orders)) {
                $renewal_orders = array();
            }
            $renewal_orders[] = $renewal_order->get_id();
            $parent_order->update_meta_data('_sp_renewal_orders', $renewal_orders);
            $parent_order->save();
            
            // Add order note
            $renewal_order->add_order_note(
                sprintf(
                    __('Renewal order for subscription order #%s. Recurring payment received via Stablecoin Pay.', 'stablecoin-pay'),
                    $parent_order->get_order_number()
                )
            );
            
            // Add note to parent order
            $parent_order->add_order_note(
                sprintf(
                    __('Renewal order #%s created for recurring payment.', 'stablecoin-pay'),
                    $renewal_order->get_order_number()
                )
            );
            
            // Store transaction details from webhook
            $transaction_details = $payment_data['transaction_details'] ?? array();
            if (isset($payment_data['payment_id'])) {
                $renewal_order->update_meta_data('_sp_payment_id', $payment_data['payment_id']);
            }
            if (isset($transaction_details['transaction_id'])) {
                $renewal_order->update_meta_data('_sp_transaction_id', $transaction_details['transaction_id']);
            }
            if (isset($transaction_details['transaction_hash'])) {
                $renewal_order->update_meta_data('_sp_transaction_hash', $transaction_details['transaction_hash']);
            }
            if (isset($transaction_details['chain_id'])) {
                $renewal_order->update_meta_data('_sp_chain_id', $transaction_details['chain_id']);
            }
            if (isset($transaction_details['network'])) {
                $renewal_order->update_meta_data('_sp_network_name', $transaction_details['network']);
            }
            if (isset($transaction_details['explorer_url'])) {
                $renewal_order->update_meta_data('_sp_explorer_url', $transaction_details['explorer_url']);
            }
            
            $renewal_order->save();
            
            error_log('✅ PP: Renewal order #' . $renewal_order->get_id() . ' created and linked to parent #' . $parent_order->get_id());
            
            return $renewal_order;
            
        } catch (Exception $e) {
            error_log('❌ PP: Error creating renewal order: ' . $e->getMessage());
            return false;
        }
    }

    // Note: We intentionally leave any other orders in on-hold state; no auto-cancel
}
