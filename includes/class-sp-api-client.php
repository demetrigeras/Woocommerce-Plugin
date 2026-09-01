<?php
/**
 * Payment Provider API Client
 * Handles communication with the payment provider API (whitelabel-friendly: logs use PP prefix).
 */

if (!defined('ABSPATH')) {
    exit;
}

class SP_API_Client {
    
    /**
     * API base URL
     */
    private $api_base_url;
    
    /**
     * Merchant ID
     */
    private $merchant_id;
    
    /**
     * API key (if required)
     */
    private $api_key;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->load_settings();
    }
    
    /**
     * Load settings from payment gateway or global options
     */
    private function load_settings() {
        // Try to get settings from payment gateway first, then fallback to global options
        $gateway_settings = get_option('woocommerce_sp_settings', array());

        // Test/staging builds pin the API host in sp-whitelabel-config.php (api_base_url).
        // Production leaves it unset and falls back to the default host below. The gateway
        // may still override this later via update_settings() with the same resolved URL.
        $api_base_override = class_exists('SP_Whitelabel_Branding')
            ? SP_Whitelabel_Branding::get_api_base_url_override()
            : null;
        $this->api_base_url = $api_base_override ? $api_base_override : 'https://api.coinsub.io/v1';
        
        // Get merchant credentials from settings
        $this->merchant_id = isset($gateway_settings['merchant_id']) ? $gateway_settings['merchant_id'] : '';
        $this->api_key = isset($gateway_settings['refunds_api_key']) && !empty($gateway_settings['refunds_api_key']) ? $gateway_settings['refunds_api_key'] : (isset($gateway_settings['api_key']) ? $gateway_settings['api_key'] : '');
    }
    
    /**
     * Update settings (called when gateway settings change)
     */
    public function update_settings($api_base_url, $merchant_id, $api_key) {
        $this->api_base_url = $api_base_url;
        $this->merchant_id = $merchant_id;
        $this->api_key = $api_key;
    }
    
    /**
     * Create a purchase session
     */
    public function create_purchase_session($order_data) {
        // Purchase session uses base v1 URL
        $endpoint = rtrim($this->api_base_url, '/') . '/purchase/session/start';
        
        error_log('PP API - Base URL: ' . $this->api_base_url);
        error_log('PP API - Endpoint: ' . $endpoint);
        error_log('PP API - Order Amount: ' . $order_data['amount'] . ' ' . $order_data['currency']);
        
        $payload = array(
            'name' => $order_data['name'],
            // Required by the API: an empty or missing value comes back as
            // "Invalid request payload" and checkout never starts. Callers always
            // supply this, so the fallback is a guard rather than a feature.
            'details' => !empty($order_data['details'])
                ? $order_data['details']
                : ('Order ' . ($order_data['metadata']['woocommerce_order_id'] ?? '')),
            'currency' => $order_data['currency'],
            'amount' => $order_data['amount'],
            'recurring' => $order_data['recurring'] ?? false,
            'metadata' => $order_data['metadata'],
            'success_url' => $order_data['success_url'],
            'cancel_url' => $order_data['cancel_url'],
            'failure_url' => $order_data['failure_url'] ?? $order_data['cancel_url'] // Use cancel_url as fallback if failure_url not provided
        );
        
        // Add subscription fields if recurring
        if (!empty($order_data['recurring']) && $order_data['recurring'] === true) {
            if (isset($order_data['frequency'])) {
                $payload['frequency'] = $order_data['frequency'];
            }
            if (isset($order_data['interval'])) {
                $payload['interval'] = $order_data['interval'];
            }
            if (isset($order_data['duration'])) {
                $payload['duration'] = $order_data['duration'];
            }
        }
        
        error_log('PP API - Payload: ' . json_encode(self::redact_for_log($payload)));
        error_log('PP API - Success URL: ' . ($payload['success_url'] ?? 'NOT SET'));
      
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key
        );
        
        if (!empty($this->api_key)) {
            $headers['Authorization'] = 'Bearer ' . $this->api_key;
        }
        
        // Timeout is required so the request cannot hang forever if the server never responds.
        // This call only creates a session (returns checkout URL). Blocktime/confirmation is out of
        // our control and happens later on the payment server; this request does not wait for it.
        $timeout = apply_filters('sp_purchase_session_timeout', 60);
        $start_time = microtime(true);
        error_log('PP API - Purchase session call at ' . date('H:i:s') . ' (timeout ' . $timeout . 's)');
        
        $response = wp_remote_post($endpoint, array(
            'headers' => $headers,
            'body' => json_encode($payload),
            'timeout' => $timeout
        ));
        
        $end_time = microtime(true);
        $duration = round($end_time - $start_time, 2);
        error_log('PP API - Purchase session completed in ' . $duration . 's');
        
        if (is_wp_error($response)) {
            error_log('PP API - Error after ' . $duration . 's: ' . $response->get_error_message());
            return new WP_Error('api_error', $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('api_error', isset($data['error']) ? $data['error'] : 'API request failed');
        }
        
        // Extract purchase session ID and URL from response
        $purchase_session_id = $data['data']['purchase_session_id'] ?? null;
        $checkout_url = $data['data']['url'] ?? null;
        
        error_log('PP API - Response received. Session ID: ' . $purchase_session_id . ', Checkout URL: ' . $checkout_url);
     
        // Remove 'sess_' prefix if present (API may return sess_UUID; checkout needs UUID)
        if ($purchase_session_id && strpos($purchase_session_id, 'sess_') === 0) {
            $purchase_session_id = substr($purchase_session_id, 5); // Remove 'sess_' prefix
        }
        
        return array(
            'purchase_session_id' => $purchase_session_id,
            'checkout_url' => $checkout_url,
            'raw_data' => $data
        );
    }
    
    /**
     * Get purchase session status
     */
    public function get_purchase_session_status($purchase_session_id) {
        $endpoint = $this->api_base_url . '/purchase/status/' . $purchase_session_id;
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key,
            
        );
        
        $response = wp_remote_get($endpoint, array(
            'headers' => $headers,
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('api_error', $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('api_error', isset($data['message']) ? $data['message'] : 'API request failed');
        }
        
        return $data;
    }
    
    // REMOVED: create_order - using WooCommerce-only approach
    
    // REMOVED: update_order - using WooCommerce-only approach
    
    // REMOVED: checkout_order - using WooCommerce-only approach
    
    // REMOVED: create_product - using WooCommerce-only approach
    
    // REMOVED: get_product_by_woocommerce_id - using WooCommerce-only approach
    
    /**
   
     * Cancel a subscription agreement
     */
    public function cancel_agreement($agreement_id) {
        // Agreements endpoint is at /v1/agreements, not /v1/commerce
        $endpoint = rtrim($this->api_base_url, '/') . '/agreements/cancel/' . $agreement_id;
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key
        );
        
        $response = wp_remote_post($endpoint, array(
            'headers' => $headers,
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('api_error', $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('api_error', isset($data['error']) ? $data['error'] : 'Failed to cancel subscription');
        }
        
        return $data;
    }

    /**
     * Retrieve agreement data
     */
    public function retrieve_agreement($agreement_id) {
        $endpoint = rtrim($this->api_base_url, '/') . '/agreements/' . $agreement_id . '/retrieve_agreement';
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key
        );
        $response = wp_remote_get($endpoint, array('headers' => $headers, 'timeout' => 30));
        if (is_wp_error($response)) {
            return new WP_Error('api_error', $response->get_error_message());
        }
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('api_error', isset($data['error']) ? $data['error'] : 'API request failed');
        }
        return $data;
    }

    /**
     * Initiate a refund transfer request
     */
    public function refund_transfer_request($to_address, $amount, $chain_id, $token_symbol) {
        $endpoint = rtrim($this->api_base_url, '/') . '/merchants/transfer/request';
        
        // Debug API key and endpoint
        error_log('🔑 PP Refund API - Full URL: ' . $endpoint);
        error_log('🔑 PP Refund API - API Key: ' . ($this->api_key ? 'SET' : 'NOT SET'));
        error_log('🔑 PP Refund API - Merchant ID: ' . ($this->merchant_id ?: 'NOT SET'));
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key
        );
        $payload = array(
            'to_address' => $to_address,
            'amount' => (float)$amount,
            'chainId' => (int)$chain_id,
            'token' => $token_symbol
        );
        $response = wp_remote_post($endpoint, array('headers' => $headers, 'body' => json_encode($payload), 'timeout' => 30));
        if (is_wp_error($response)) {
            return new WP_Error('api_error', $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        error_log('PP Refund API - Response code: ' . $code);

        // Accept any 2xx. A transfer request is asynchronous, so 201 Created and
        // 202 Accepted are ordinary success responses. Requiring exactly 200 marked
        // refunds that had genuinely been submitted as failures, and left the order
        // sitting in refund-pending while the transfer went through anyway.
        if ($code < 200 || $code >= 300) {
            $message = self::extract_api_message($data);
            error_log('❌ PP Refund API - Failed (HTTP ' . $code . '): ' . substr($body, 0, 300));

            return new WP_Error(
                'api_error',
                $message !== '' ? $message : ('Refund request failed (HTTP ' . $code . ')'),
                array('status' => $code, 'body' => $body)
            );
        }

        return is_array($data) ? $data : array();
    }

    /**
     * Pull a human-readable failure reason out of an error body.
     *
     * The API is not consistent about which key carries this, and reading only
     * `error` meant real reasons - insufficient balance in particular - were
     * replaced with a generic "API request failed". That mattered: the refund flow
     * keys its insufficient-funds guidance off this text, so losing it also lost
     * the merchant's instructions for topping up.
     *
     * @param mixed $data Decoded response body.
     * @return string Empty when nothing usable was found.
     */
    private static function extract_api_message($data) {
        if (!is_array($data)) {
            return '';
        }

        $nodes = array($data);
        foreach (array('data', 'error', 'result') as $envelope) {
            if (isset($data[$envelope]) && is_array($data[$envelope])) {
                $nodes[] = $data[$envelope];
            }
        }

        foreach ($nodes as $node) {
            foreach (array('message', 'error', 'detail', 'description', 'reason') as $key) {
                if (!empty($node[$key]) && is_string($node[$key])) {
                    return $node[$key];
                }
            }
        }

        return '';
    }

    /**
     * Read the transfer/refund identifier out of a transfer-request response.
     *
     * Tolerant of envelopes and key naming for the same reason the webhook
     * provisioner is: the identifier is what later matches the incoming `transfer`
     * webhook back to this order, so failing to read it means the refund completes
     * on-chain but the order is never updated.
     *
     * @param mixed $data
     * @return string Empty when no identifier is present.
     */
    public static function extract_transfer_id($data) {
        return self::first_scalar($data, array('transfer_id', 'transferId', 'refund_id', 'refundId', 'id'));
    }

    /**
     * Transaction hash from a transfer-request response, if it is settled already.
     *
     * @param mixed $data
     * @return string
     */
    public static function extract_transaction_hash($data) {
        return self::first_scalar($data, array('transaction_hash', 'transactionHash', 'hash', 'tx_hash', 'txHash'));
    }

    /**
     * Status reported by a transfer-request response.
     *
     * @param mixed $data
     * @return string Lower-cased status, or '' when none was reported.
     */
    public static function extract_transfer_status($data) {
        return strtolower(self::first_scalar($data, array('status', 'state', 'transfer_status', 'transferStatus')));
    }

    /**
     * Whether a transfer was accepted but is parked awaiting a merchant signature.
     *
     * Transfers above the merchant's signing limit (default $100) are created but
     * not broadcast until the merchant signs or raises the limit in their
     * dashboard. The API still answers 2xx, so without this check the plugin
     * reports a successful refund while nothing happens on-chain.
     *
     * @param mixed $data Decoded transfer-request response.
     * @return bool
     */
    public static function transfer_awaits_signature($data) {
        $status = self::extract_transfer_status($data);

        $pending_states = array(
            'pending', 'pending_signature', 'pending-signature',
            'awaiting_signature', 'awaiting-signature', 'requires_signature',
            'signature_required', 'unsigned', 'needs_signature', 'queued',
        );
        if ($status !== '' && in_array($status, $pending_states, true)) {
            return true;
        }

        // Some responses describe it in prose rather than a status field.
        $haystack = strtolower(self::extract_api_message($data));
        if ($haystack === '') {
            return false;
        }

        return (strpos($haystack, 'signature') !== false && strpos($haystack, 'requir') !== false)
            || strpos($haystack, 'signing limit') !== false
            || strpos($haystack, 'awaiting signature') !== false
            || strpos($haystack, 'needs to be signed') !== false;
    }

    /**
     * Copy of a payload with personal data masked, for logging.
     *
     * The plugin logs payloads to diagnose checkout problems, and those payloads
     * carry customer names, emails, phone numbers, postal addresses and wallet
     * addresses. debug.log is world-readable on many hosts, so writing that out
     * verbatim turns a debugging aid into a data leak. Amounts, ids, counts and
     * currencies survive - they are what the logs are actually read for.
     *
     * @param mixed $data
     * @param int   $depth
     * @return mixed Safe to pass to json_encode() and log.
     */
    public static function redact_for_log($data, $depth = 0) {
        if ($depth > 6 || !is_array($data)) {
            return $data;
        }

        // Substring match, so billing_email / shipping_first_name / customer_wallet
        // are all covered without enumerating every prefix Woo uses.
        $sensitive = array(
            'email', 'phone', 'name', 'address', 'street', 'city', 'postcode',
            'postal', 'zip', 'state', 'province', 'country', 'company',
            'wallet', 'signing_address', 'to_address', 'from_address',
            'ip', 'user_agent', 'customer',
        );

        // Exact keys that merely contain a sensitive word but are not personal data:
        // a bare `name` here is an order title, a product name or a fee label, and
        // masking it makes checkout problems impossible to diagnose from the log.
        // The customer's own name always arrives as first_name / last_name /
        // customer_name, which the substring scan below still catches.
        $not_sensitive = array('name', 'display_name', 'product_name', 'item_name', 'fee_name', 'network_name', 'method_name');

        $out = array();
        foreach ($data as $key => $value) {
            $key_l = strtolower((string) $key);

            $is_sensitive = false;
            if (!in_array($key_l, $not_sensitive, true)) {
                foreach ($sensitive as $needle) {
                    if (strpos($key_l, $needle) !== false) {
                        $is_sensitive = true;
                        break;
                    }
                }
            }

            if ($is_sensitive) {
                // Keep the shape (present / absent) without the value.
                $out[$key] = is_array($value)
                    ? '[redacted array(' . count($value) . ')]'
                    : (($value === null || $value === '') ? '[empty]' : '[redacted]');
                continue;
            }

            $out[$key] = is_array($value) ? self::redact_for_log($value, $depth + 1) : $value;
        }

        return $out;
    }

    /**
     * Structure of a response, values withheld, for diagnosing shape mismatches.
     *
     * @param mixed $value
     * @return string
     */
    public static function describe_shape($value, $depth = 0) {
        if ($depth > 4) {
            return '...';
        }

        if (is_array($value)) {
            if ($value === array()) {
                return '[]';
            }
            if (array_keys($value) === range(0, count($value) - 1)) {
                return '[' . count($value) . ' x ' . self::describe_shape($value[0], $depth + 1) . ']';
            }
            $parts = array();
            foreach ($value as $key => $item) {
                $safe = in_array(strtolower((string) $key), array('status', 'state', 'message', 'error', 'detail', 'type', 'reason'), true);
                $parts[] = $key . ':' . ($safe && is_string($item)
                    ? '"' . substr($item, 0, 80) . '"'
                    : self::describe_shape($item, $depth + 1));
            }
            return '{' . implode(', ', $parts) . '}';
        }

        if (is_bool($value))  { return $value ? 'true' : 'false'; }
        if (is_int($value))   { return 'int(' . $value . ')'; }
        if (is_float($value)) { return 'float(' . $value . ')'; }
        if ($value === null)  { return 'null'; }
        if (is_string($value)) { return 'string(' . strlen($value) . ')'; }

        return gettype($value);
    }

    private static function first_scalar($data, array $keys) {
        if (!is_array($data)) {
            return '';
        }

        $nodes = array($data);
        foreach (array('data', 'transfer', 'result', 'payload') as $envelope) {
            if (isset($data[$envelope]) && is_array($data[$envelope])) {
                $nodes[] = $data[$envelope];
            }
        }

        foreach ($nodes as $node) {
            foreach ($keys as $key) {
                if (isset($node[$key]) && (is_string($node[$key]) || is_int($node[$key]))) {
                    $value = trim((string) $node[$key]);
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        return '';
    }
    
    /**
     * Get all payments for a merchant
     */
    public function get_all_payments() {
        $endpoint = rtrim($this->api_base_url, '/') . '/payments/all';
        
        // Log API request details
        error_log('PP API - Get All Payments');
        error_log('PP API - Endpoint: ' . $endpoint);
        error_log('PP API - Merchant ID: ' . (empty($this->merchant_id) ? 'EMPTY!' : substr($this->merchant_id, 0, 8) . '...'));
        error_log('PP API - API Key: ' . (empty($this->api_key) ? 'EMPTY!' : 'SET'));
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key
        );
        
        $response = wp_remote_get($endpoint, array('headers' => $headers, 'timeout' => 30));
        
        if (is_wp_error($response)) {
            error_log('❌ PP API - WP Error: ' . $response->get_error_message());
            return new WP_Error('api_error', $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        error_log('PP API - Response Code: ' . $response_code);
        error_log('PP API - Response Body: ' . substr($body, 0, 500));
        
        if ($response_code !== 200) {
            $error_message = isset($data['error']) ? $data['error'] : 'API request failed';
            error_log('❌ PP API - Error: ' . $error_message);
            return new WP_Error('api_error', $error_message);
        }
        
        return $data;
    }
    
    /**
     * Get payment details for a specific payment
     */
    public function get_payment_details($payment_id) {
        $endpoint = rtrim($this->api_base_url, '/') . '/payments/' . $payment_id;
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Merchant-ID' => $this->merchant_id,
            'API-Key' => $this->api_key
        );
        
        $response = wp_remote_get($endpoint, array('headers' => $headers, 'timeout' => 30));
        
        if (is_wp_error($response)) {
            return new WP_Error('api_error', $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('api_error', isset($data['error']) ? $data['error'] : 'API request failed');
        }
        
        return $data;
    }

// REMOVED: update_commerce_order_from_webhook - using WooCommerce-only approach
}
