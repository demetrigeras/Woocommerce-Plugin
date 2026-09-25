<?php
/**
 * Cart handoff for headless storefronts.
 *
 * A decoupled storefront that drives checkout through the Store API has to get
 * three separate things right - send a Nonce or Cart-Token, render the gateway
 * title from something other than the bare slug, and follow the
 * `payment_result.redirect_url` it gets back. When any one of those is missed
 * the shopper sees a dead button and the order sits in "pending payment".
 *
 * This gives that storefront a way to opt out of the whole problem. It links
 * the shopper here with the line items in the URL:
 *
 *     https://cms.example.com/?sp-cart=123:2,456|789:1
 *
 * The cart is rebuilt server-side and the shopper is sent to the normal
 * WooCommerce checkout page, which is a plain WordPress page load. Nonces, the
 * gateway title, and the redirect to the hosted checkout are then all handled
 * by stock WooCommerce, exactly as they are on a non-headless store.
 *
 * Item syntax: `product[|variation]:quantity`, comma separated.
 *
 * Prices are always resolved from the catalog. Nothing about price, and no
 * arbitrary cart metadata, is ever taken from the URL - otherwise this would be
 * a public discount generator.
 *
 * This file is inert unless `sp-cart` is present in the query string, so it
 * changes nothing for stores that do not use it.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Most distinct line items accepted in one handoff. */
if (!defined('SP_CART_HANDOFF_MAX_ITEMS')) {
    define('SP_CART_HANDOFF_MAX_ITEMS', 50);
}

/** Largest quantity accepted for a single line. */
if (!defined('SP_CART_HANDOFF_MAX_QTY')) {
    define('SP_CART_HANDOFF_MAX_QTY', 1000);
}

if (!function_exists('sp_parse_cart_handoff_spec')) {
    /**
     * Parse `123:2,456|789:1` into structured line items.
     *
     * Malformed entries are skipped rather than failing the whole handoff, so a
     * single bad id cannot strand a shopper with an empty cart.
     *
     * @param string $spec
     * @return array List of array{product_id:int, variation_id:int, quantity:int}
     */
    function sp_parse_cart_handoff_spec($spec) {
        $items = array();

        foreach (explode(',', $spec) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            // Quantity is optional and defaults to 1.
            $parts    = explode(':', $chunk, 2);
            $ids      = trim($parts[0]);
            $quantity = isset($parts[1]) ? (int) trim($parts[1]) : 1;

            if ($quantity < 1 || $quantity > SP_CART_HANDOFF_MAX_QTY) {
                continue;
            }

            // `parent|variation` for variable products.
            $id_parts     = explode('|', $ids, 2);
            $product_id   = (int) trim($id_parts[0]);
            $variation_id = isset($id_parts[1]) ? (int) trim($id_parts[1]) : 0;

            if ($product_id < 1) {
                continue;
            }

            $items[] = array(
                'product_id'   => $product_id,
                'variation_id' => $variation_id > 0 ? $variation_id : 0,
                'quantity'     => $quantity,
            );

            if (count($items) >= SP_CART_HANDOFF_MAX_ITEMS) {
                break;
            }
        }

        return $items;
    }
}

if (!function_exists('sp_cart_handoff_item_is_sellable')) {
    /**
     * Whether a parsed line item refers to something actually on sale here.
     *
     * @param array $item
     * @return bool
     */
    function sp_cart_handoff_item_is_sellable($item) {
        $id      = $item['variation_id'] > 0 ? $item['variation_id'] : $item['product_id'];
        $product = wc_get_product($id);

        if (!$product) {
            return false;
        }

        if (!$product->is_purchasable() || !$product->is_in_stock()) {
            return false;
        }

        // A variation must genuinely belong to the parent named in the URL,
        // so a valid variation id cannot be attached to an unrelated product.
        if ($item['variation_id'] > 0 && (int) $product->get_parent_id() !== $item['product_id']) {
            return false;
        }

        return true;
    }
}

if (!function_exists('sp_handle_cart_handoff')) {
    /**
     * Rebuild the cart from the URL and send the shopper to checkout.
     */
    function sp_handle_cart_handoff() {
        if (!isset($_GET['sp-cart'])) {
            return;
        }

        if (!function_exists('WC') || !WC()->cart) {
            return;
        }

        $spec  = sanitize_text_field(wp_unslash($_GET['sp-cart']));
        $items = sp_parse_cart_handoff_spec($spec);

        if (empty($items)) {
            error_log('PP Cart Handoff: no usable line items in sp-cart');
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }

        WC()->cart->empty_cart();

        $added   = 0;
        $skipped = 0;

        foreach ($items as $item) {
            if (!sp_cart_handoff_item_is_sellable($item)) {
                $skipped++;
                continue;
            }

            $result = WC()->cart->add_to_cart(
                $item['product_id'],
                $item['quantity'],
                $item['variation_id']
            );

            if ($result) {
                $added++;
            } else {
                $skipped++;
            }
        }

        // Product ids are not sensitive, but keep the log to counts so a full
        // basket never lands in a shared debug log.
        error_log(sprintf('PP Cart Handoff: %d item(s) added, %d skipped', $added, $skipped));

        if ($added === 0) {
            wc_add_notice(
                __('Those items are no longer available.', 'stablecoin-pay'),
                'error'
            );
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }

        if ($skipped > 0) {
            wc_add_notice(
                __('Some items were unavailable and have been left out of your order.', 'stablecoin-pay'),
                'notice'
            );
        }

        $destination = apply_filters('sp_cart_handoff_destination', wc_get_checkout_url(), $items);

        wp_safe_redirect($destination);
        exit;
    }

    // Priority 5: ahead of anything that might render the page, but late enough
    // that WooCommerce has loaded the session cart.
    add_action('template_redirect', 'sp_handle_cart_handoff', 5);
}
