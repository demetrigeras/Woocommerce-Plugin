<?php
/**
 * Public-facing review/explainer page for Stablecoin Pay
 *
 * @package Stablecoin Pay
 */

if (!defined('ABSPATH')) {
    exit;
}

class SP_Review_Page {
    const QUERY_VAR = 'sp_review';

    public function __construct() {
        add_action('init', array($this, 'register_rewrite'));
        add_filter('query_vars', array($this, 'register_query_var'));
        add_filter('template_include', array($this, 'maybe_load_template'));
    }

    /**
     * Register the rewrite rule on every load (WordPress stores it until flushed).
     */
    public function register_rewrite() {
        if (function_exists('sp_register_review_rewrite_rule')) {
            sp_register_review_rewrite_rule();
        }
    }

    /**
     * Add the custom query var so WordPress recognizes it.
     *
     * @param array $vars
     * @return array
     */
    public function register_query_var($vars) {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    /**
     * Swap the template when visiting the review page.
     *
     * @param string $template
     * @return string
     */
    public function maybe_load_template($template) {
        $is_review_page = get_query_var(self::QUERY_VAR);

        if (!empty($is_review_page)) {
            return SP_PLUGIN_DIR . 'includes/templates/sp-review-page.php';
        }

        return $template;
    }
}

