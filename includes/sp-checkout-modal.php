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
#sp-checkout-container {
	margin: 20px 0;
	background: white;
	border-radius: 16px;
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
	overflow: hidden;
	display: none; /* Hidden by default */
}

#sp-checkout-iframe {
	width: 100%;
	height: 550px;
	border: none;
}

/* Hide button when checkout iframe is visible - don't interfere with other payment methods */
body.sp-iframe-visible .woocommerce-checkout .form-row.place-order,
body.sp-iframe-visible .woocommerce-checkout #place_order {
	display: none !important;
}

/* Mobile responsive */
@media (max-width: 768px) {
	#sp-checkout-container {
		margin: 10px 0;
	}
	
	#sp-checkout-iframe {
		height: 550px;
	}
}
</style>

<!-- Stablecoin Pay Checkout JavaScript -->
<script type="text/javascript">
jQuery(document).ready(function($) {
    // Only load Stablecoin Pay checkout functionality if we're on checkout page
    if (!$('body').hasClass('woocommerce-checkout') && !$('body').hasClass('woocommerce-page-checkout')) {
        return; // Exit early if not on checkout page
    }
    
    // Prevent double submission
    if (typeof window.spSubmitting === 'undefined') {
        window.spSubmitting = false;
    }
    
    // SIMPLIFIED: Just remove Stablecoin Pay logo when switching away, don't touch button text at all
    // Let WooCommerce handle button text completely - it will use "Place order" for all methods
    function removeSPLogo() {
        var $placeOrderButton = $('#place_order');
        if ($placeOrderButton.length === 0) {
            return;
        }
        
        var currentHtml = $placeOrderButton.html();
        if (currentHtml.includes('sp-button-logo')) {
            // Remove Stablecoin Pay logo only, preserve everything else
            var textWithoutLogo = currentHtml.replace(/<img[^>]*class="sp-button-logo"[^>]*>/gi, '');
            $placeOrderButton.html(textWithoutLogo);
            console.log('✅ Stablecoin Pay: Removed Stablecoin Pay logo - letting WooCommerce handle button text');
        }
    }
    
    // Handle Stablecoin Pay button visibility based on iframe state
    function ensurePlaceOrderButtonVisibility() {
        var paymentMethod = $('input[name="payment_method"]:checked').val();
        
        // Only handle Stablecoin Pay - completely ignore other payment methods
        if (paymentMethod !== 'sp') {
            // For non-Stablecoin Pay methods, just hide Stablecoin Pay iframe
            $('#sp-checkout-container').hide();
            $('body').removeClass('sp-iframe-visible');
            removeSPLogo();
            return;
        }
        
        // For Stablecoin Pay, handle button visibility based on iframe state
        var $placeOrderRow = $('.woocommerce-checkout .form-row.place-order');
        var $placeOrderButton = $('#place_order');
        
        if ($('#sp-checkout-container').is(':visible')) {
            // Hide button when Stablecoin Pay iframe is visible
            $placeOrderRow.hide();
            $placeOrderButton.hide();
            $('body').addClass('sp-iframe-visible');
            console.log('🔒 Stablecoin Pay: Hiding Place Order button (Stablecoin Pay iframe visible)');
        } else {
            // Show button when Stablecoin Pay is selected but iframe not visible yet
            $placeOrderRow.show();
            $placeOrderButton.show();
            $('body').removeClass('sp-iframe-visible');
            console.log('✅ Stablecoin Pay: Showing Place Order button (Stablecoin Pay selected, no iframe)');
        }
    }
    
    // Watch for payment method changes
    $('body').on('change', 'input[name="payment_method"]', function() {
        var newMethod = $(this).val();
        console.log('🔄 Stablecoin Pay: Payment method changed to: ' + newMethod);
        
        if (newMethod === 'sp') {
            ensurePlaceOrderButtonVisibility();
        } else {
            // For other payment methods, remove Stablecoin Pay logo and hide iframe
            removeSPLogo();
            $('#sp-checkout-container').hide();
            $('body').removeClass('sp-iframe-visible');
            ensurePlaceOrderButtonVisibility();
        }
    });
    
    // Initialize button visibility
    setTimeout(function() {
        ensurePlaceOrderButtonVisibility();
    }, 100);

    // Mirror billing <-> shipping inputs before any validation/submission.
    //
    // Why: some merchant checkout layouts label the visible section "Shipping"
    // but the underlying input names are `billing_*` (or vice versa), and some
    // sites force `ship_to_different_address=1` while only exposing one
    // section. In those cases the hidden side's inputs stay empty, which trips
    // HTML5 `required` validation, WooCommerce's `.woocommerce-invalid` check,
    // and the server-side WC checkout validator.
    //
    // We copy whichever side the user actually filled into the empty mirror so
    // the form passes validation regardless of which section was exposed.
    function mirrorBillingShippingInputs() {
        var $form = $('form.checkout');
        if ($form.length === 0) { return; }
        var keys = [
            'first_name', 'last_name', 'company', 'country',
            'address_1', 'address_2', 'city', 'state', 'postcode'
        ];
        keys.forEach(function(key) {
            var $b = $form.find('[name="billing_' + key + '"]').first();
            var $s = $form.find('[name="shipping_' + key + '"]').first();
            if ($b.length === 0 && $s.length === 0) { return; }

            var bVal = $b.length ? String($b.val() || '').trim() : '';
            var sVal = $s.length ? String($s.val() || '').trim() : '';

            if (bVal && !sVal && $s.length) {
                $s.val(bVal).trigger('change');
            } else if (sVal && !bVal && $b.length) {
                $b.val(sVal).trigger('change');
            }
        });
    }

    function validateSPCheckoutForm() {
        var $form = $('form.checkout');
        if ($form.length === 0) {
            return true;
        }

        // Reset previous custom Stablecoin Pay validation UI.
        $form.find('.sp-inline-error').remove();
        $('#sp-checkout-error-box').remove();

        // First, copy billing<->shipping values across so a partially-filled
        // form (only billing OR only shipping) doesn't fail validation.
        mirrorBillingShippingInputs();

        // Note: we used to also run HTML5 `checkValidity()` and look for
        // `.woocommerce-invalid` here, but both are mirror-unaware and caused
        // false positives on shipping-only / billing-only checkouts. The
        // `checkout_place_order_<gateway>` event already only fires AFTER
        // WooCommerce's own pre-submit validation passes, so the row-level
        // check below is sufficient as a soft sanity check.

        // Hard guard: validate Woo required field wrappers directly.
        //
        // Important: WooCommerce stores allow merchants to expose only billing
        // OR only shipping (e.g., shipping-only stores that hide billing).
        // In those cases the hidden section's `billing_*` / `shipping_*` inputs
        // remain empty, but the populated section's mirror field is sufficient
        // because WooCommerce copies it server-side. We therefore treat
        // billing_X and shipping_X as interchangeable for validation purposes.
        //
        // We also look up the input by `name` (more reliable than walking the
        // row's children, which can pick up select2 / hidden helper inputs).
        var addressMirrorMap = {
            billing_first_name: 'shipping_first_name',
            billing_last_name: 'shipping_last_name',
            billing_address_1: 'shipping_address_1',
            billing_address_2: 'shipping_address_2',
            billing_city: 'shipping_city',
            billing_state: 'shipping_state',
            billing_postcode: 'shipping_postcode',
            billing_country: 'shipping_country'
        };
        // Build reverse map as well (shipping -> billing).
        var addressMirrorReverse = {};
        Object.keys(addressMirrorMap).forEach(function(k) {
            addressMirrorReverse[addressMirrorMap[k]] = k;
        });

        function readFieldValue(name) {
            if (!name) { return ''; }
            var $field = $form.find('[name="' + name + '"]').filter(':enabled').first();
            if ($field.length === 0) { return ''; }
            if ($field.is(':checkbox')) {
                return $field.is(':checked') ? '1' : '';
            }
            return String($field.val() || '').trim();
        }

        function hasMirrorValue(name) {
            var mirror = addressMirrorMap[name] || addressMirrorReverse[name];
            if (!mirror) { return false; }
            return readFieldValue(mirror) !== '';
        }

        var missingMessages = [];
        $form.find('.form-row.validate-required:visible').each(function() {
            var $row = $(this);
            // Prefer the canonical named input (skips select2 helper inputs).
            var $named = $row.find('input[name], select[name], textarea[name]').filter(':enabled').first();
            var $input = $named.length ? $named : $row.find('input, select, textarea').filter(':enabled').first();
            if ($input.length === 0) {
                return;
            }

            var fieldName = $input.attr('name') || '';
            var isCheckbox = $input.is(':checkbox');
            var value = isCheckbox ? ($input.is(':checked') ? '1' : '') : String($input.val() || '').trim();

            // Treat billing_X / shipping_X as interchangeable: if this row's
            // field is empty but its mirror counterpart is filled, accept it.
            if (value === '' && !isCheckbox && hasMirrorValue(fieldName)) {
                value = '__mirrored__';
            }

            if (value === '') {
                $row.removeClass('woocommerce-validated').addClass('woocommerce-invalid woocommerce-invalid-required-field');
                var label = $row.find('label').first().text().replace('*', '').trim() || 'Required field';
                missingMessages.push(label + ' is a required field.');

                // Always show an inline message under each missing field.
                if ($row.find('.sp-inline-error').length === 0) {
                    $row.append('<span class="sp-inline-error" style="display:block;color:#b81c23;font-size:0.875em;margin-top:6px;">' + $('<div>').text(label + ' is a required field.').html() + '</span>');
                }
            } else {
                $row.removeClass('woocommerce-invalid woocommerce-invalid-required-field').addClass('woocommerce-validated');
            }
        });

        if (missingMessages.length > 0) {
            showSPCheckoutErrors(missingMessages);
            return false;
        }

        return true;
    }

    function showSPCheckoutErrors(messages) {
        var $form = $('form.checkout');
        if ($form.length === 0 || !messages || messages.length === 0) {
            return;
        }

        var list = '';
        $.each(messages, function(_, msg) {
            list += '<li>' + $('<div>').text(msg).html() + '</li>';
        });
        var errorHtml = '<ul class="woocommerce-error" role="alert">' + list + '</ul>';

        // Dedicated visible box inside checkout form (theme-independent).
        var $errorBox = $('<div id="sp-checkout-error-box" class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout" style="margin-bottom:16px;"></div>').html(errorHtml);
        $form.prepend($errorBox);

        // Prefer WooCommerce's native renderer when available.
        if (typeof wc_checkout_form !== 'undefined' && wc_checkout_form && typeof wc_checkout_form.submit_error === 'function') {
            wc_checkout_form.submit_error(errorHtml);
            return;
        }

        // Inject WooCommerce notice HTML directly for maximum compatibility across themes/templates.
        $('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();

        var $noticeGroup = $('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"></div>').html(errorHtml);

        // Preferred location used by many themes.
        var $noticesWrapper = $('.woocommerce-notices-wrapper').first();
        if ($noticesWrapper.length) {
            $noticesWrapper.prepend($noticeGroup);
        } else {
            // Fallback: insert before checkout form.
            $form.before($noticeGroup);
        }

        $form.removeClass('processing');
        if (typeof $form.unblock === 'function') {
            $form.unblock();
        }

        // Also trigger WooCommerce checkout_error event for any native listeners.
        $(document.body).trigger('checkout_error', [errorHtml]);

        // Match WooCommerce UX: focus on notices.
        if ($noticeGroup.length && $noticeGroup.offset()) {
            $('html, body').animate({ scrollTop: $noticeGroup.offset().top - 120 }, 200);
        }
    }
    
    // Mirror billing<->shipping BEFORE WooCommerce runs its own pre-submit
    // validation (which happens earlier in the same submit cycle). Using the
    // raw `submit` event with a very high priority ensures we run before
    // WooCommerce's checkout.js submit handler reads the inputs.
    $(document).on('submit', 'form.checkout', function() {
        try { mirrorBillingShippingInputs(); } catch (_) {}
    });

    // Use WooCommerce's native validated checkout flow.
    // This fires only AFTER WooCommerce validates required fields and terms.
    $('form.checkout').on('checkout_place_order_sp', function() {
        if (window.spSubmitting) {
            return false;
        }

        // Belt-and-suspenders: also mirror here, in case the submit handler
        // above didn't get a chance to run (some themes intercept submit).
        try { mirrorBillingShippingInputs(); } catch (_) {}

        // Extra guard for edge cases.
        if (!validateSPCheckoutForm()) {
            console.warn('⚠️ Stablecoin Pay: Checkout validation failed, payment not started.');
            // Let WooCommerce continue its native submit path so standard frontend notices render.
            return true;
        }

        window.spSubmitting = true;

        var $placeOrder = $('#place_order');
        $placeOrder.prop('disabled', true).text('Processing...');

        // Read a value by name from the checkout form, regardless of input type.
        // Falls back to the billing/shipping mirror if the requested field is empty —
        // many stores expose only one address section and rely on Woo to copy the
        // other server-side, but we want both populated in the API payload.
        function readCheckoutValue(name) {
            var $form = $('form.checkout');
            var $field = $form.find('[name="' + name + '"]').first();
            var value = $field.length ? String($field.val() || '').trim() : '';
            if (value !== '') {
                return value;
            }
            var mirror = null;
            if (name.indexOf('billing_') === 0) {
                mirror = 'shipping_' + name.substring('billing_'.length);
            } else if (name.indexOf('shipping_') === 0) {
                mirror = 'billing_' + name.substring('shipping_'.length);
            }
            if (mirror) {
                var $mirrorField = $form.find('[name="' + mirror + '"]').first();
                if ($mirrorField.length) {
                    return String($mirrorField.val() || '').trim();
                }
            }
            return '';
        }

        // Process the payment via AJAX
        $.ajax({
                url: wc_checkout_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'sp_process_payment',
                    security: wc_checkout_params.checkout_nonce,
                    payment_method: 'sp',
                    billing_first_name: readCheckoutValue('billing_first_name'),
                    billing_last_name: readCheckoutValue('billing_last_name'),
                    billing_email: readCheckoutValue('billing_email'),
                    billing_phone: readCheckoutValue('billing_phone'),
                    billing_address_1: readCheckoutValue('billing_address_1'),
                    billing_city: readCheckoutValue('billing_city'),
                    billing_state: readCheckoutValue('billing_state'),
                    billing_postcode: readCheckoutValue('billing_postcode'),
                    billing_country: readCheckoutValue('billing_country'),
                    // Shipping address fields
                    shipping_first_name: readCheckoutValue('shipping_first_name'),
                    shipping_last_name: readCheckoutValue('shipping_last_name'),
                    shipping_address_1: readCheckoutValue('shipping_address_1'),
                    shipping_city: readCheckoutValue('shipping_city'),
                    shipping_state: readCheckoutValue('shipping_state'),
                    shipping_postcode: readCheckoutValue('shipping_postcode'),
                    shipping_country: readCheckoutValue('shipping_country')
                },
            success: function(response) {
                    // Get the checkout URL from the response
                    // The response should include sp_checkout_url (the API checkout URL)
                    // NOTE: Do not log checkout URL in console for security (one-time use URL)
                    var checkoutUrl = null;
                    
                    if (response.success && response.data) {
                        // PRIORITY: Get sp_checkout_url (the actual API checkout URL for iframe)
                        if (response.data.sp_checkout_url) {
                            checkoutUrl = response.data.sp_checkout_url;
                            // Security: Don't log checkout URL in console (sensitive one-time use URL)
                        } else {
                            console.error('❌ Stablecoin Pay - No sp_checkout_url in response!');
                            // Don't log full response data - may contain sensitive info
                        }
                    }
                    
                    if (checkoutUrl) {
                        // Security: Checkout URL is sensitive (one-time use) - don't log it
                        
                        // Remove any existing iframe to prevent duplicates
                        $('#sp-checkout-iframe').remove();
                        $('#sp-checkout-container').remove();
                        
                        // Create iframe container above the payment button
                        var iframeContainer = $('<div id="sp-checkout-container" style="margin: 20px 0; background: white; border-radius: 16px; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1); overflow: hidden;"><iframe id="sp-checkout-iframe" src="' + checkoutUrl + '" style="width: 100%; height: 600px; border: none;" allow="clipboard-read *; publickey-credentials-create *; publickey-credentials-get *; autoplay *; camera *; microphone *; payment *; fullscreen *; clipboard-write *" onload="handleSPIframeLoad()"></iframe></div>');
                        
                        // Insert above the payment button
                        $('.woocommerce-checkout .form-row.place-order').before(iframeContainer);
                        
                        // Show the iframe container
                        $('#sp-checkout-container').show();
                        $('body').addClass('sp-iframe-visible');
                        
                        // Hide the payment button
                        $('.woocommerce-checkout .form-row.place-order').hide();
                        $('#place_order').hide();
                        
                        // Set up iframe redirect detection
                        setupSPIframeRedirectDetection();
                        
                        // Security: Don't log that iframe was embedded (URL is sensitive)
                    } else {
                        console.log('Payment failed - response details:', response);
                        // Show detailed error
                        var errorMsg = 'Payment error: ';
                        if (response.data) {
                            if (typeof response.data === 'string') {
                                errorMsg += response.data;
                            } else if (response.data.message) {
                                errorMsg += response.data.message;
                            } else {
                                errorMsg += JSON.stringify(response.data);
                            }
                        } else {
                            errorMsg += 'Unknown error - no data received';
                        }
                        alert(errorMsg);
                        $placeOrder.prop('disabled', false);
                        // Let WooCommerce handle button text (will be "Place order")
                        window.spSubmitting = false;
                    }
                },
                error: function(xhr, status, error) {
                    console.log('AJAX Error occurred:');
                    console.log('Status:', status);
                    console.log('Error:', error);
                    console.log('Response:', xhr.responseText);
                    
                    var errorMsg = 'Payment error: Unable to process payment';
                    if (xhr.responseText) {
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.data) {
                                errorMsg = 'Payment error: ' + response.data;
                            }
                        } catch(e) {
                            errorMsg = 'Payment error: ' + xhr.responseText;
                        }
                    }
                    
                    alert(errorMsg);
                    $placeOrder.prop('disabled', false);
                    // Let WooCommerce handle button text (will be "Place order")
                    window.spSubmitting = false;
                }
        });

        // Stop WooCommerce's default submit (we handle Stablecoin Pay checkout ourselves).
        return false;
    });
    
    // Set up iframe redirect detection
    function setupSPIframeRedirectDetection() {
        // Listen for postMessage events from the iframe
        window.addEventListener('message', function(event) {
            // Security: Don't log message data - may contain sensitive URLs
            
            // Check if this is a redirect message
            if (event.data && typeof event.data === 'object') {
                if (event.data.type === 'redirect' && event.data.url) {
                    // Security: Don't log redirect URL (sensitive)
                    window.location.href = event.data.url;
                }
            }
            
            // Also check for URL changes in the iframe
            if (event.data && typeof event.data === 'string' && event.data.includes('order-received')) {
                // Security: Don't log order-received URL (sensitive)
                window.location.href = event.data;
            }
        });
        
        // Check iframe URL periodically for redirects
        var checkInterval = setInterval(function() {
            try {
                var iframe = document.getElementById('sp-checkout-iframe');
                if (iframe && iframe.contentWindow) {
                    var iframeUrl = iframe.contentWindow.location.href;
                    
                    // Check if iframe has redirected to order-received page
                    if (iframeUrl.includes('order-received')) {
                        // Security: Don't log iframe URL (sensitive)
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
    }
    
    // Handle iframe load
    function handleSPIframeLoad() {
        // Security: Don't log iframe load (URL is sensitive)
        setupSPIframeRedirectDetection();
    }
    
    // Make functions available globally
    window.handleSPIframeLoad = handleSPIframeLoad;
    window.ensurePlaceOrderButtonVisibility = ensurePlaceOrderButtonVisibility;
    
    // Also check when WooCommerce updates checkout (AJAX)
    $(document.body).on('updated_checkout', function() {
        console.log('🔄 Stablecoin Pay: WooCommerce checkout updated via AJAX');
        ensurePlaceOrderButtonVisibility();
    });
    
    // Also watch for when payment methods are loaded/updated
    $(document.body).on('payment_method_selected', function() {
        console.log('🔄 Stablecoin Pay: Payment method selected event fired');
        ensurePlaceOrderButtonVisibility();
    });
});
</script>