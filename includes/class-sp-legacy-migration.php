<?php
/**
 * Legacy key migration.
 *
 * Earlier builds of this plugin stored everything under a `coinsub` prefix:
 * the gateway id, its WooCommerce settings option, plugin options, transients
 * and every piece of order meta. Those identifiers are now `sp` / `_sp_`.
 *
 * Renaming them in code alone would orphan the data on any site that already
 * had the plugin installed - the gateway would come up unconfigured and every
 * historical order would lose its payment details. This class copies the old
 * keys to the new ones exactly once per site.
 *
 * It is deliberately conservative:
 *  - it never overwrites a new-style key that already holds a value,
 *  - options are copied, not deleted, so rolling back to an older build still
 *    finds its data,
 *  - it is idempotent and version-stamped, so it is a no-op on every load
 *    after the first.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SP_Legacy_Migration {

    /** Option holding the schema version this site has been migrated to. */
    const VERSION_OPTION = 'sp_legacy_migration_version';

    /** Bump when a new migration step is added below. */
    const CURRENT_VERSION = 2;

    const LEGACY_PREFIX = 'coinsub';
    const NEW_PREFIX    = 'sp';

    const LOCK_TRANSIENT = 'sp_legacy_migration_lock';

    /**
     * Entry point. Cheap enough to call on every load: a single option read
     * short-circuits it once the site is up to date.
     */
    public static function maybe_migrate() {
        $migrated = (int) get_option(self::VERSION_OPTION, 0);
        if ($migrated >= self::CURRENT_VERSION) {
            return;
        }

        // Guard against two concurrent requests both running the migration.
        if (!self::acquire_lock()) {
            return;
        }

        try {
            self::migrate_options();
            self::migrate_gateway_order();
            self::migrate_post_meta();
            self::migrate_hpos_meta();
            self::migrate_payment_method();
            self::purge_legacy_transients();
            self::migrate_scheduled_events();
            self::drop_obsolete_branding_cache();

            update_option(self::VERSION_OPTION, self::CURRENT_VERSION, false);
            error_log('Stablecoin Pay Migration: legacy "coinsub" keys migrated to "sp" (v' . self::CURRENT_VERSION . ')');
        } catch (Exception $e) {
            // Leave the version stamp unset so the next load retries.
            error_log('Stablecoin Pay Migration: failed - ' . $e->getMessage());
        }

        self::release_lock();
    }

    // ---------------------------------------------------------------- options

    /**
     * Gateway settings + standalone plugin options.
     *
     * Uses the options API rather than a SQL rename so the object cache and
     * autoload flags stay coherent.
     */
    private static function migrate_options() {
        $map = array(
            // WooCommerce derives this option name from the gateway id.
            'woocommerce_coinsub_settings' => 'woocommerce_sp_settings',
            'coinsub_checkout_page_id'     => 'sp_checkout_page_id',
            'coinsub_webhook_secret'       => 'sp_webhook_secret',
        );

        foreach ($map as $old => $new) {
            $legacy = get_option($old, null);
            if ($legacy === null || $legacy === false) {
                continue;
            }

            // Never clobber a value the new build has already written.
            $current = get_option($new, null);
            if ($current !== null && $current !== false && $current !== '') {
                continue;
            }

            update_option($new, $legacy);
            error_log('Stablecoin Pay Migration: option ' . $old . ' -> ' . $new);
        }

        // The gateway settings array is keyed by field name, not by gateway id,
        // so its contents carry over unchanged. Only the option name differs.
    }

    /**
     * WooCommerce remembers gateway display order in `woocommerce_gateway_order`,
     * keyed by gateway id. Re-key the legacy entry so the gateway keeps its slot.
     */
    private static function migrate_gateway_order() {
        $order = get_option('woocommerce_gateway_order', null);
        if (!is_array($order) || !array_key_exists(self::LEGACY_PREFIX, $order)) {
            return;
        }
        if (array_key_exists(self::NEW_PREFIX, $order)) {
            return;
        }

        $rebuilt = array();
        foreach ($order as $id => $position) {
            $rebuilt[$id === self::LEGACY_PREFIX ? self::NEW_PREFIX : $id] = $position;
        }
        update_option('woocommerce_gateway_order', $rebuilt);
        error_log('Stablecoin Pay Migration: gateway order re-keyed');
    }

    // ------------------------------------------------------------- order meta

    /**
     * Legacy (post-table) order storage: rename `_coinsub_*` meta keys to `_sp_*`.
     *
     * Prefix-based rather than an enumerated list, because some keys are dynamic
     * (e.g. `_coinsub_product_{product_id}`).
     */
    private static function migrate_post_meta() {
        global $wpdb;

        $renamed = self::rename_meta_keys($wpdb->postmeta, 'post_id');
        if ($renamed) {
            error_log('Stablecoin Pay Migration: ' . (int) $renamed . ' post meta key(s) renamed');
        }
    }

    /**
     * HPOS (High-Performance Order Storage) equivalent of the above.
     */
    private static function migrate_hpos_meta() {
        global $wpdb;

        $table = $wpdb->prefix . 'wc_orders_meta';
        if (!self::table_exists($table)) {
            return;
        }

        $renamed = self::rename_meta_keys($table, 'order_id');
        if ($renamed) {
            error_log('Stablecoin Pay Migration: ' . (int) $renamed . ' HPOS order meta key(s) renamed');
        }
    }

    /**
     * Rename every `_coinsub_*` meta key in $table to `_sp_*`.
     *
     * Legacy rows whose new-style counterpart already exists are dropped first,
     * so the rename cannot create duplicate (object, meta_key) pairs or
     * resurrect a stale value over a fresher one.
     *
     * @param string $table     Fully-qualified meta table name.
     * @param string $id_column Column identifying the owning object.
     * @return int              Number of rows renamed.
     */
    private static function rename_meta_keys($table, $id_column) {
        global $wpdb;

        $legacy_prefix = '_' . self::LEGACY_PREFIX . '_';
        $new_prefix    = '_' . self::NEW_PREFIX . '_';
        $like_legacy   = $wpdb->esc_like($legacy_prefix) . '%';
        $suffix_start  = strlen($legacy_prefix) + 1;

        // $table and $id_column are internal constants, never user input.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE legacy FROM `{$table}` AS legacy
                 INNER JOIN `{$table}` AS current
                    ON current.`{$id_column}` = legacy.`{$id_column}`
                   AND current.meta_key = CONCAT(%s, SUBSTRING(legacy.meta_key, %d))
                 WHERE legacy.meta_key LIKE %s",
                $new_prefix,
                $suffix_start,
                $like_legacy
            )
        );

        return (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}`
                    SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d))
                  WHERE meta_key LIKE %s",
                $new_prefix,
                $suffix_start,
                $like_legacy
            )
        );
    }

    /**
     * Re-point existing orders from the old gateway id to the new one, so they
     * still resolve to this gateway for refunds, emails and status handling.
     */
    private static function migrate_payment_method() {
        global $wpdb;

        // Legacy post-table orders store it as `_payment_method` meta.
        $updated = $wpdb->update(
            $wpdb->postmeta,
            array('meta_value' => self::NEW_PREFIX),
            array('meta_key' => '_payment_method', 'meta_value' => self::LEGACY_PREFIX),
            array('%s'),
            array('%s', '%s')
        );
        if ($updated) {
            error_log('Stablecoin Pay Migration: ' . (int) $updated . ' order(s) re-pointed to gateway "sp" (posts)');
        }

        // HPOS stores it as a column on the orders table.
        $orders_table = $wpdb->prefix . 'wc_orders';
        if (self::table_exists($orders_table)) {
            $updated = $wpdb->update(
                $orders_table,
                array('payment_method' => self::NEW_PREFIX),
                array('payment_method' => self::LEGACY_PREFIX),
                array('%s'),
                array('%s')
            );
            if ($updated) {
                error_log('Stablecoin Pay Migration: ' . (int) $updated . ' order(s) re-pointed to gateway "sp" (HPOS)');
            }
        }
    }

    // -------------------------------------------------------------- transients

    /**
     * Legacy transients are caches (branding payloads, checkout URLs, fetch
     * guards). Dropping them is cheaper and safer than renaming - they simply
     * repopulate under the new names on next use.
     */
    private static function purge_legacy_transients() {
        global $wpdb;

        $patterns = array(
            $wpdb->esc_like('_transient_' . self::LEGACY_PREFIX . '_') . '%',
            $wpdb->esc_like('_transient_timeout_' . self::LEGACY_PREFIX . '_') . '%',
        );

        foreach ($patterns as $pattern) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $pattern
                )
            );
        }
    }

    /**
     * Move the cleanup cron from the legacy hook name to the new one.
     */
    private static function migrate_scheduled_events() {
        $legacy_hook = self::LEGACY_PREFIX . '_cleanup_expired_sessions';
        $new_hook    = self::NEW_PREFIX . '_cleanup_expired_sessions';

        $next = wp_next_scheduled($legacy_hook);
        if (!$next) {
            return;
        }

        wp_clear_scheduled_hook($legacy_hook);
        if (!wp_next_scheduled($new_hook)) {
            wp_schedule_event($next, 'hourly', $new_hook);
        }
        error_log('Stablecoin Pay Migration: cleanup cron re-hooked to ' . $new_hook);
    }

    /**
     * Remove the branding cache left by builds that fetched branding from the API.
     *
     * Branding now comes only from sp-whitelabel-config.php, so these rows are dead
     * weight - and worse, they are a snapshot of whatever partner the site last
     * talked to, which is exactly the sort of stale value someone would later
     * mistake for configuration.
     */
    private static function drop_obsolete_branding_cache() {
        global $wpdb;

        delete_option('sp_whitelabel_branding');
        delete_option('coinsub_whitelabel_branding');

        delete_transient('sp_whitelabel_fetching');
        delete_transient('sp_whitelabel_fetching_time');
        delete_transient('sp_refresh_branding_on_load');

        // Per-merchant branding fetch guards, if any survived.
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
              WHERE option_name LIKE '_transient_sp_branding_fetch_attempted_%'
                 OR option_name LIKE '_transient_timeout_sp_branding_fetch_attempted_%'"
        );

        error_log('Stablecoin Pay Migration: removed the obsolete API branding cache');
    }

    // ----------------------------------------------------------------- helpers

    private static function table_exists($table) {
        global $wpdb;
        return (bool) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
        );
    }

    private static function acquire_lock() {
        if (get_transient(self::LOCK_TRANSIENT)) {
            return false;
        }
        set_transient(self::LOCK_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS);
        return true;
    }

    private static function release_lock() {
        delete_transient(self::LOCK_TRANSIENT);
    }
}
