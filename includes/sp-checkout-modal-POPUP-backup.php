<?php
/**
 * Stablecoin Pay Checkout Integration
 * 
 * This file contains the HTML, CSS, and JavaScript for the checkout iframe
 * The iframe URL is whitelabeled based on merchant credentials
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<!-- Stablecoin Pay Checkout Styles -->
<style>
/* POPUP STYLES */
/* --- MODAL OVERLAY --- */
#sp-checkout-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6); /* Slightly darker dim */
    z-index: 999999;
    display: none;
    justify-content: center;
    align-items: center;
    backdrop-filter: blur(3px); /* Modern blur effect */
    opacity: 0;
    transition: opacity 0.3s ease;
}

/* --- MODAL BOX --- */
#sp-checkout-container {
    background: #ffffff;
    width: 500px;
    max-width: 92%;
    height: 750px;
    max-height: 90vh;
    border-radius: 12px; /* Smooth corners */
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    transform: translateY(20px);
    transition: transform 0.3s ease;
    border: 1px solid #e0e0e0;
}

/* --- HEADER SECTION --- */
.sp-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 24px;
    background: #fcfcfc;
    border-bottom: 1px solid #f0f0f0;
    flex-shrink: 0; /* Prevent header from shrinking */
}

.sp-header-title {
    font-size: 18px;
    font-weight: 600;
    color: #1a1a1a;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
    display: flex;
    align-items: center;
}

/* --- CLOSE BUTTON --- */
#sp-close-btn {
    background: transparent;
    border: none;
    font-size: 24px;
    line-height: 1;
    color: #999;
    cursor: pointer;
    padding: 4px;
    transition: color 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}

#sp-close-btn:hover {
    color: #333;
}

/* --- IFRAME WRAPPER (PADDING AREA) --- */
.sp-iframe-wrapper {
    flex-grow: 1;
    position: relative;
    padding: 10px; /* Add padding inside the modal */
    background: #fff;
}

#sp-checkout-iframe {
    width: 100%;
    height: 100%;
    border: none;
    display: block;
}

/* --- ANIMATION STATES --- */
body.sp-modal-open #sp-checkout-overlay {
    opacity: 1;
}
body.sp-modal-open #sp-checkout-container {
    transform: translateY(0);
}

/* --- MOBILE RESPONSIVE --- */
@media (max-width: 768px) {
    #sp-checkout-container {
        width: 100%;
        height: 100%;
        max-height: 100%;
        border-radius: 0;
        border: none;
    }
    
    .sp-iframe-wrapper {
        padding: 0; /* Remove padding on mobile for max space */
    }
}
</style>

<!-- Stablecoin Pay Checkout JavaScript -->
<script type="text/javascript">
jQuery(document).ready(function($) {
    // Only load functionality on checkout page
    if (!$('body').hasClass('woocommerce-checkout') && !$('body').hasClass('woocommerce-page-checkout')) {
        return;
    }
    
    // Prevent double submission
    if (typeof window.spSubmitting === 'undefined') {
        window.spSubmitting = false;
    }
    
    function removeSPLogo() {
        var $placeOrderButton = $('#place_order');
        if ($placeOrderButton.length === 0) return;
        
        var currentHtml = $placeOrderButton.html();
        if (currentHtml.includes('sp-button-logo')) {
            // Remove Stablecoin Pay logo only, preserve everything else
            var textWithoutLogo = currentHtml.replace(/<img[^>]*class="sp-button-logo"[^>]*>/gi, '');
            $placeOrderButton.html(textWithoutLogo);
            console.log('✅ Stablecoin Pay: Removed Stablecoin Pay logo - letting WooCommerce handle button text');
        }
    }
    
    // Override the place order button ONLY for Stablecoin Pay
    $('body').on('click', '#place_order', function(e) {
        var paymentMethod = $('input[name="payment_method"]:checked').val();
        
        if (paymentMethod === 'sp') {
            e.preventDefault();
            e.stopPropagation();
            
            if (window.spSubmitting) return false;
            window.spSubmitting = true;
            
            // Show loading state
            var $btn = $(this);
            var originalText = $btn.text();
            $btn.prop('disabled', true).text('Processing...');
            
            // Process the payment via AJAX
            $.ajax({
                url: wc_checkout_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'sp_process_payment',
                    security: wc_checkout_params.checkout_nonce,
                    payment_method: 'sp',
                    // Billing fields
                    billing_first_name: $('input[name="billing_first_name"]').val(),
                    billing_last_name: $('input[name="billing_last_name"]').val(),
                    billing_email: $('input[name="billing_email"]').val(),
                    billing_phone: $('input[name="billing_phone"]').val(),
                    billing_address_1: $('input[name="billing_address_1"]').val(),
                    billing_city: $('input[name="billing_city"]').val(),
                    billing_state: $('select[name="billing_state"]').val(),
                    billing_postcode: $('input[name="billing_postcode"]').val(),
                    billing_country: $('select[name="billing_country"]').val(),
                    // Shipping address fields
                    shipping_first_name: $('input[name="shipping_first_name"]').val(),
                    shipping_last_name: $('input[name="shipping_last_name"]').val(),
                    shipping_address_1: $('input[name="shipping_address_1"]').val(),
                    shipping_city: $('input[name="shipping_city"]').val(),
                    shipping_state: $('select[name="shipping_state"]').val(),
                    shipping_postcode: $('input[name="shipping_postcode"]').val(),
                    shipping_country: $('select[name="shipping_country"]').val()
                },
                success: function(response) {
                    var checkoutUrl = null;
                    
                    if (response.success && response.data) {
                        if (response.data.result === 'success' && response.data.redirect) {
                            checkoutUrl = response.data.redirect;
                        } else if (response.data.sp_checkout_url) {
                            checkoutUrl = response.data.sp_checkout_url;
                        }
                    }
                    
                    if (checkoutUrl) {
                        // Remove any existing overlay
                        $('#sp-checkout-overlay').remove();
                        
                        // Create POPUP Modal
                        var popupHtml = `
                            <div id="sp-checkout-overlay">
                                <div id="sp-checkout-container">
                                    <div class="sp-modal-header">
                                        <div class="sp-header-title">
                                            Pay with Stablecoin Pay
                                        </div>
                                        <button id="sp-close-btn" title="Close Checkout">
                                            <span style="font-size: 28px;">&times;</span>
                                        </button>
                                    </div>
                                    
                                    <div class="sp-iframe-wrapper">
                                        <iframe id="sp-checkout-iframe" 
                                                src="${checkoutUrl}" 
                                                allow="clipboard-read *; publickey-credentials-create *; publickey-credentials-get *; autoplay *; camera *; microphone *; payment *; fullscreen *">
                                        </iframe>
                                    </div>
                                </div>
                            </div>`;
                        
                        // Append to BODY instead of form
                        $('body').append(popupHtml);
                        
                        // Show overlay with flex to center
                        $('#sp-checkout-overlay').css('display', 'flex');
                        $('body').addClass('sp-modal-open').css('overflow', 'hidden'); // Prevent background scrolling
                        
                        // Handle Close Button
                        $('#sp-close-btn').on('click', function() {
                            $('#sp-checkout-overlay').fadeOut(200, function() {
                                $(this).remove();
                            });
                            // Reset state so user can click Place Order again if they cancelled
                            $('body').removeClass('sp-modal-open').css('overflow', '');
                            $btn.prop('disabled', false).text(originalText); // Reset button text
                            window.spSubmitting = false;
                        });

                        setupIframeRedirectDetection();
                        
                    } else {
                        var errorMsg = 'Payment error: ';
                        if (response.data && typeof response.data === 'string') {
                            errorMsg += response.data;
                        } else if (response.data && response.data.message) {
                            errorMsg += response.data.message;
                        } else {
                            errorMsg += 'Unknown error';
                        }
                        alert(errorMsg);
                        $btn.prop('disabled', false).text(originalText);
                        window.spSubmitting = false;
                    }
                },
                error: function(xhr, status, error) {
                   alert('Connection error. Please try again.');
                   $btn.prop('disabled', false).text(originalText);
                   window.spSubmitting = false;
                }
            });
            
            return false;
        }
    });
    
    function setupIframeRedirectDetection() {
        console.log('🔄 Setting up iframe redirect detection...');
        
        var messageHandler = function(event) {
            if (event.data && typeof event.data === 'object' && event.data.type === 'redirect' && event.data.url) {
                window.location.href = event.data.url;
            }
            if (event.data && typeof event.data === 'string' && event.data.includes('order-received')) {
                window.location.href = event.data;
            }
        };
        
        window.addEventListener('message', messageHandler);
        
        var checkInterval = setInterval(function() {
            var iframe = document.getElementById('sp-checkout-iframe');
            if (!iframe) {
                clearInterval(checkInterval);
                window.removeEventListener('message', messageHandler);
                return;
            }
            
            try {
                if (iframe.contentWindow.location.href.includes('order-received')) {
                    clearInterval(checkInterval);
                    window.location.href = iframe.contentWindow.location.href;
                }
            } catch(e) {
                // Cross-origin blocks access
            }
        }, 1000);
    }
});
</script>