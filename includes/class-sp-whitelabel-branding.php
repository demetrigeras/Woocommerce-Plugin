<?php
/**
 * Whitelabel branding.
 *
 * Every value comes from sp-whitelabel-config.php, which is written at build time
 * for a partner. There is deliberately no API lookup and no cached branding option:
 * the config file is the single source of truth, so branding cannot drift between
 * what a build ships with and what some earlier API response happened to cache.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SP_Whitelabel_Branding {

    /**
     * Relative path to whitelabel config file (plugin root). When set, we fetch branding by environment_id instead of merchant_id.
     */
    const WHITELABEL_CONFIG_FILE = 'sp-whitelabel-config.php';

    /**
     * Get environment_id from whitelabel config. Array empty / file missing / environment_id null = fall back to Stablecoin Pay.
     *
     * @return string|null environment_id (e.g. vantack.com) or null for Stablecoin Pay
     */
    public static function get_whitelabel_env_id_from_config() {
        if (!defined('SP_PLUGIN_DIR')) {
            return null;
        }
        $path = SP_PLUGIN_DIR . self::WHITELABEL_CONFIG_FILE;
        if (!is_readable($path)) {
            return null;
        }
        $config = include $path;
        if (!is_array($config) || empty($config['environment_id'])) {
            return null;
        }
        return $config['environment_id'];
    }

    /**
     * Read a plain string value out of the whitelabel config file.
     *
     * Unlike the branding getters this is NOT gated on environment_id: the environment
     * overrides apply to the default Stablecoin Pay build too.
     *
     * @param string $key Config key
     * @return string|null Trimmed non-empty string, else null
     */
    private static function get_config_string($key) {
        if (!defined('SP_PLUGIN_DIR')) {
            return null;
        }
        $path = SP_PLUGIN_DIR . self::WHITELABEL_CONFIG_FILE;
        if (!is_readable($path)) {
            return null;
        }
        $config = include $path;
        if (!is_array($config) || empty($config[$key]) || !is_string($config[$key])) {
            return null;
        }
        $value = trim($config[$key]);
        return $value !== '' ? $value : null;
    }

    /**
     * API base URL override for test/staging builds (config key api_base_url).
     * Null on production builds, where callers derive the URL from environment_id.
     *
     * @return string|null e.g. https://test-api.coinsub.io/v1
     */
    public static function get_api_base_url_override() {
        $url = self::get_config_string('api_base_url');
        return $url !== null ? rtrim($url, '/') : null;
    }

    /**
     * App/dashboard base URL override for test/staging builds (config key app_base_url).
     * Used for logo and favicon hosts served by app.*.
     *
     * @return string|null e.g. https://test.coinsub.io
     */
    public static function get_app_base_url_override() {
        $url = self::get_config_string('app_base_url');
        return $url !== null ? rtrim($url, '/') : null;
    }

    /**
     * Hosted-checkout base URL override for test/staging builds (config key buy_base_url).
     * When set, the checkout URL returned by the API keeps this host instead of being
     * rewritten to the partner's production buy.{company}.com domain.
     *
     * @return string|null e.g. https://test-buy.coinsub.io
     */
    public static function get_buy_base_url_override() {
        $url = self::get_config_string('buy_base_url');
        return $url !== null ? rtrim($url, '/') : null;
    }

    /**
     * API base URL for this build's branding lookups: explicit pin, then the partner build's
     * environment_id, then the default host. Deliberately does not consult the cached branding
     * option — this class is what populates that option, so trusting it here would let a previous
     * build's partner decide which environment we ask.
     *
     * @return string e.g. https://api.syncharge.io/v1
     */
    public static function resolve_api_base_url() {
        $override = self::get_api_base_url_override();
        if ($override) {
            return $override;
        }
        $env_id = self::get_whitelabel_env_id_from_config();
        if (!empty($env_id)) {
            return 'https://api.' . $env_id . '/v1';
        }
        return 'https://api.coinsub.io/v1';
    }

    /**
     * Get plugin display name from whitelabel config (e.g. "Company X").
     * Used so the only hardcoded partner name is in sp-whitelabel-config.php.
     *
     * @return string|null plugin_name when config has environment_id, else null
     */
    public static function get_whitelabel_plugin_name_from_config() {
        if (!defined('SP_PLUGIN_DIR')) {
            return null;
        }
        $path = SP_PLUGIN_DIR . self::WHITELABEL_CONFIG_FILE;
        if (!is_readable($path)) {
            return null;
        }
        $config = include $path;
        if (!is_array($config) || empty($config['environment_id'])) {
            return null;
        }
        return isset($config['plugin_name']) && $config['plugin_name'] !== '' ? $config['plugin_name'] : null;
    }

    /**
     * Get dashboard URL from whitelabel config (where merchants log in and get credentials).
     * Used in setup instructions and field descriptions so merchants know where to go.
     *
     * @return string|null Full URL (e.g. https://app.paymentservers.com) when config has environment_id, else null
     */
    public static function get_whitelabel_dashboard_url_from_config() {
        if (!defined('SP_PLUGIN_DIR')) {
            return null;
        }
        $path = SP_PLUGIN_DIR . self::WHITELABEL_CONFIG_FILE;
        if (!is_readable($path)) {
            return null;
        }
        $config = include $path;
        if (!is_array($config) || empty($config['environment_id'])) {
            return null;
        }
        if (!empty($config['dashboard_url']) && is_string($config['dashboard_url'])) {
            return rtrim($config['dashboard_url'], '/');
        }
        return 'https://app.' . $config['environment_id'];
    }

    /**
     * Get logo URL from whitelabel config (Payments list + checkout icon/button).
     * Manual: set logo_url in config to the full URL (e.g. copy from your app/API response
     * like app.logo.square.dark → https://app.vantack.com/img/domain/vantack/vantack.square.dark.png).
     * No lookup; use a path under the plugin (e.g. images/foo.png) to avoid CORP blocking
     * when app.* serves logos with Cross-Origin-Resource-Policy: same-site.
     *
     * @return string|null Full URL when config has logo_url set, else null
     */
    public static function get_whitelabel_logo_url_from_config() {
        if (!defined('SP_PLUGIN_DIR')) {
            return null;
        }
        $path = SP_PLUGIN_DIR . self::WHITELABEL_CONFIG_FILE;
        if (!is_readable($path)) {
            return null;
        }
        $config = include $path;
        if (!is_array($config) || empty($config['environment_id'])) {
            return null;
        }
        if (!empty($config['logo_url']) && is_string($config['logo_url'])) {
            return self::resolve_whitelabel_asset_url(trim($config['logo_url']));
        }
        return null;
    }

    /**
     * Get setup walkthrough video URL from whitelabel config (gateway Setup Instructions box).
     * Same sourcing rules as logo_url: prefer a path under the plugin (e.g. images/setup-video.mp4)
     * so it loads same-origin, since app.* serves assets with Cross-Origin-Resource-Policy: same-site.
     *
     * Unlike the logo this is NOT gated on environment_id — the walkthrough applies to the
     * default Stablecoin Pay build too, which has environment_id null.
     *
     * @return string|null Full URL when config has setup_video_url set, else null
     */
    public static function get_whitelabel_setup_video_url_from_config() {
        if (!defined('SP_PLUGIN_DIR')) {
            return null;
        }
        $path = SP_PLUGIN_DIR . self::WHITELABEL_CONFIG_FILE;
        if (!is_readable($path)) {
            return null;
        }
        $config = include $path;
        if (!is_array($config)) {
            return null;
        }
        if (!empty($config['setup_video_url']) && is_string($config['setup_video_url'])) {
            return self::resolve_whitelabel_asset_url(trim($config['setup_video_url']));
        }
        return null;
    }

    /**
     * Turn a config asset path into a full URL safe for the front end (same-origin avoids CORP on
     * merchant sites). Shared by logo_url and setup_video_url.
     *
     * @param string $raw From config: https URL, // URL, site path starting with /, or path relative to plugin root.
     * @return string|null
     */
    private static function resolve_whitelabel_asset_url($raw) {
        if ($raw === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $raw)) {
            return $raw;
        }
        if (preg_match('#^//#', $raw)) {
            return (is_ssl() ? 'https:' : 'http:') . $raw;
        }
        if ($raw[0] === '/') {
            return home_url($raw);
        }
        if (defined('SP_PLUGIN_URL')) {
            return rtrim(SP_PLUGIN_URL, '/') . '/' . ltrim($raw, '/');
        }
        return $raw;
    }

    /**
     * Partner display name, or null on a non-partner build.
     *
     * @return string|null
     */
    public function get_company_name() {
        return self::get_whitelabel_plugin_name_from_config();
    }

    /**
     * Partner logo URL.
     *
     * The $type/$theme arguments are accepted for call-site compatibility but are
     * not used: a build ships exactly one logo, named in the config.
     *
     * @param string $type
     * @param string $theme
     * @return string Empty string when the build has no logo configured.
     */
    public function get_logo_url($type = 'default', $theme = 'light') {
        $logo = self::get_whitelabel_logo_url_from_config();
        return $logo ? $logo : '';
    }

    /**
     * Favicon URL. Falls back to the configured logo, since a partner build has no
     * separate favicon asset.
     *
     * @return string
     */
    public function get_favicon_url() {
        return $this->get_logo_url();
    }

    /**
     * "Powered by" line for partner surfaces.
     *
     * @return string
     */
    public function get_powered_by_text() {
        $name = self::get_whitelabel_plugin_name_from_config();
        if (empty($name)) {
            $name = __('Stablecoin Pay', 'stablecoin-pay');
        }

        /* translators: %s: payment provider name */
        return sprintf(__('Powered by %s', 'stablecoin-pay'), $name);
    }

    /**
     * Rewrite this plugin's metadata (Name, Author, Description) shown on
     * the WordPress Plugins admin screen so it carries the whitelabel
     * partner name instead of "Stablecoin Pay".
     *
     * Build-time rewriting in `create-plugin-package.sh` handles fresh
     * installs of the whitelabel zip. This runtime filter handles the
     * case where a partner build is dropped on top of an existing
     * install whose cached header WordPress already parsed.
     *
     * Hooked into the `all_plugins` filter — see registration at the
     * bottom of this file.
     */
    public static function rebrand_plugin_metadata($plugins) {
        if (!is_array($plugins)) {
            return $plugins;
        }

        $brand_name = self::get_whitelabel_plugin_name_from_config();
        if (!$brand_name) {
            return $plugins;
        }

        if (!defined('SP_PLUGIN_FILE')) {
            return $plugins;
        }

        $our_basename = plugin_basename(SP_PLUGIN_FILE);
        if (!isset($plugins[$our_basename])) {
            return $plugins;
        }

        $description = sprintf(
            /* translators: %s: whitelabel payment provider name */
            __('Accept cryptocurrency payments with %s. Simple crypto payments for WooCommerce.', 'stablecoin-pay'),
            $brand_name
        );

        $plugins[$our_basename]['Name']        = $brand_name;
        $plugins[$our_basename]['Title']       = $brand_name;
        $plugins[$our_basename]['Author']      = $brand_name;
        $plugins[$our_basename]['AuthorName']  = $brand_name;
        $plugins[$our_basename]['Description'] = $description;

        return $plugins;
    }
}

// Rebrand the plugin's Name / Author / Description on the WP Plugins page
// when a whitelabel config is active.
add_filter('all_plugins', array('SP_Whitelabel_Branding', 'rebrand_plugin_metadata'));
