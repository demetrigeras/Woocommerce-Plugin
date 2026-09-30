<?php
/**
 * WHITELABEL / DOWNLOADABLE BUILD CONFIG
 *
 * Single source for partner identity. No switches: when this array is filled,
 * the plugin uses only these values for name and logo (no API/database lookup).
 *
 * - Array filled (environment_id set + fields set): Partner build. Name = plugin_name, logo = logo_url (or default if logo_url empty).
 * - Array empty / file missing / environment_id null: Fall back to Stablecoin Pay (default name and logo).
 *
 * Run ./create-plugin-package.sh to build the zip. Set logo_url to a plugin-relative path (e.g. images/logo.png) or full URL; leave empty for default logo.
 */
if (!defined('ABSPATH')) {
    exit;
}

return array(
    // Partner API environment (e.g. paymentservers.com, vantack.com, syncharge.io). Null = Stablecoin Pay.
    'environment_id' => 'syncharge.io',

    // Display name used everywhere (admin, gateway, Plugins list, checkout).
    'plugin_name' => 'Syncharge',

    // Where merchants sign up and manage their account (setup instructions, field descriptions).
    'dashboard_url' => 'https://app.syncharge.io',

    // Logo: prefer path under plugin (bundled in images/) so it loads same-origin. A direct https URL to app.*
    // often breaks on checkout because those responses send Cross-Origin-Resource-Policy: same-site (Chrome blocks).
    'logo_url' => 'images/syncharge.square.dark.svg',

    // Setup walkthrough shown in the gateway Setup Instructions box. Same sourcing rules as logo_url:
    // a path under the plugin (bundled in images/) loads same-origin and always works. A remote URL is
    // fine too, but the host must send byte-range (Accept-Ranges) responses or seeking breaks, and must
    // not send Cross-Origin-Resource-Policy: same-site. Leave empty to hide the video box entirely.
    'setup_video_url' => 'images/setup-video.mp4',

    // Hosted checkout host the customer is redirected to. Optional: when omitted it is derived
    // from environment_id (buy.{environment_id}), which resolves to the same thing. Set explicitly
    // so the redirect never depends on that naming convention holding, and so it stays correct even
    // if the API returns a different host. Point it at a test buy host to build against test.
    'buy_base_url' => 'https://buy.syncharge.io',

    // Flat network fee the payment method charges per payment, in the store's currency.
    //
    // TEMPORARY: the DFNS method this build uses settles at roughly the same cost every
    // time (observed at 10-11 cents), so the figure is pinned here rather than read from
    // the payment. The customer is shown and charged it inside the hosted checkout, which
    // is after WooCommerce has finished totalling the order - so the plugin adds it to the
    // order as a fee line once payment completes, which is what makes the order summary
    // add up to what was actually paid.
    //
    // Omit the key (or set 0) on builds whose method does not charge it.
    'network_fee' => 0.10,

    // Zip filename produced by create-plugin-package.sh when this config is present.
    'zip_name' => 'syncharge-plugin.zip',
);
