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
    // Partner API environment (e.g. paymentservers.com, vantack.com). Null = Stablecoin Pay.
    'environment_id' => 'paymentservers.com',

    // Display name used everywhere (admin, gateway, Plugins list, checkout).
    'plugin_name' => 'Payment Servers',

    // Where merchants sign up and manage their account (setup instructions, field descriptions).
    'dashboard_url' => 'https://app.paymentservers.com',

    // Logo: prefer path under plugin (bundled in images/) so it loads same-origin. A direct https URL to app.*
    // often breaks on checkout because those responses send Cross-Origin-Resource-Policy: same-site (Chrome blocks).
    'logo_url' => 'images/paymentservers.square.dark.png',

    // Setup walkthrough shown in the gateway Setup Instructions box. Same sourcing rules as logo_url:
    // a path under the plugin (bundled in images/) loads same-origin and always works. A remote URL is
    // fine too, but the host must send byte-range (Accept-Ranges) responses or seeking breaks, and must
    // not send Cross-Origin-Resource-Policy: same-site. Leave empty to hide the video box entirely.
    'setup_video_url' => 'images/setup-video.mp4',

    // Hosted checkout host the customer is redirected to. Optional: when omitted it is derived
    // from environment_id (buy.{environment_id}), which resolves to the same thing. Set explicitly
    // so the redirect never depends on that naming convention holding, and so it stays correct even
    // if the API returns a different host. Point it at a test buy host to build against test.
    'buy_base_url' => 'https://buy.paymentservers.com',

    // Zip filename produced by create-plugin-package.sh when this config is present.
    'zip_name' => 'paymentservers-plugin.zip',
);
