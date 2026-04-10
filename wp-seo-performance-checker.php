<?php
/**
 * Plugin Name: WP SEO Performance Checker
 * Plugin URI: /wp-seo-performance-checker
 * Description: Comprehensive SEO analysis, page speed testing, meta tag validation, and sitemap management for WordPress admin.
 * Version: 1.2.0
 * Author: Raivis Kalnins
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: seo-performance-checker
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SEOPC_VERSION', '1.2.0');
define('SEOPC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SEOPC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SEOPC_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'SEOPC_';
    $base_dir = SEOPC_PLUGIN_DIR . 'includes/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $len);
    $file = $base_dir . 'class-' . str_replace('_', '-', strtolower($relative_class)) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

// Activation hook
register_activation_hook(__FILE__, 'seopc_activate_plugin');

function seopc_activate_plugin() {
    // Create default options
    add_option('seopc_settings', [
        'post_types' => ['post', 'page'],
        'taxonomies' => ['category', 'post_tag'],
        'include_images' => true,
        'include_lastmod' => true,
        'ping_search_engines' => true,
        'auto_generate_sitemap' => true
    ]);
    
    add_option('seopc_analysis_history', []);
    
    // Flush rewrite rules for sitemap
    flush_rewrite_rules();
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'seopc_deactivate_plugin');

function seopc_deactivate_plugin() {
    flush_rewrite_rules();
}

// Initialize plugin
add_action('plugins_loaded', ['SEOPC_Plugin', 'instance']);

// Add sitemap rewrite rules
add_action('init', 'seopc_add_rewrite_rules');

function seopc_add_rewrite_rules() {
    add_rewrite_rule('^sitemap\.xml$', 'index.php?seopc_sitemap=index', 'top');
    add_rewrite_rule('^sitemap-([a-z]+)-(\d+)\.xml$', 'index.php?seopc_sitemap=$matches[1]&seopc_page=$matches[2]', 'top');
    add_rewrite_rule('^sitemap-([a-z]+)\.xml$', 'index.php?seopc_sitemap=$matches[1]', 'top');
    
    add_rewrite_tag('%seopc_sitemap%', '([^&]+)');
    add_rewrite_tag('%seopc_page%', '([^&]+)');
}

// Serve sitemap
add_action('template_redirect', 'seopc_serve_sitemap');

function seopc_serve_sitemap() {
    $sitemap_type = get_query_var('seopc_sitemap');
    
    if (empty($sitemap_type)) {
        return;
    }
    
    $sitemap_manager = new SEOPC_Sitemap_Manager();
    
    header('Content-Type: application/xml; charset=UTF-8');
    
    if ($sitemap_type === 'index') {
        $sitemap = $sitemap_manager->generate_sitemap_index();
        echo $sitemap['xml'];
    } else {
        $page = intval(get_query_var('seopc_page')) ?: 1;
        $sitemap = $sitemap_manager->generate_post_sitemap($sitemap_type, $page);
        echo $sitemap['xml'];
    }
    
    exit;
}