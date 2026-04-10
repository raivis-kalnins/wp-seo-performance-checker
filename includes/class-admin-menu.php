<?php

class SEOPC_Admin_Menu {
    
    public function __construct() {
        add_action('admin_menu', [$this, 'register_menus']);
        add_action('admin_post_seopc_clear_history', [$this, 'handle_clear_history']);
        add_action('admin_post_seopc_export_redirects_csv', [$this, 'handle_export_redirects_csv']);
    }
    
    public function register_menus() {
        add_menu_page(
            __('SEO Performance', 'seo-performance-checker'),
            __('SEO Performance', 'seo-performance-checker'),
            'manage_options',
            'seo-performance',
            [$this, 'render_main_page'],
            'dashicons-chart-line',
            25
        );
        
        add_submenu_page(
            'seo-performance',
            __('Dashboard', 'seo-performance-checker'),
            __('Dashboard', 'seo-performance-checker'),
            'manage_options',
            'seo-performance',
            [$this, 'render_main_page']
        );
        
        add_submenu_page(
            'seo-performance',
            __('Meta Analyzer', 'seo-performance-checker'),
            __('Meta Analyzer', 'seo-performance-checker'),
            'manage_options',
            'seo-meta-analyzer',
            [$this, 'render_meta_page']
        );
        
        add_submenu_page(
            'seo-performance',
            __('Sitemap Manager', 'seo-performance-checker'),
            __('Sitemap Manager', 'seo-performance-checker'),
            'manage_options',
            'seo-sitemap',
            [$this, 'render_sitemap_page']
        );
    }
    
    public function render_main_page() {
        include SEOPC_PLUGIN_DIR . 'templates/main-page.php';
    }
    
    public function render_meta_page() {
        include SEOPC_PLUGIN_DIR . 'templates/meta-analyzer.php';
    }
    
    public function render_sitemap_page() {
        include SEOPC_PLUGIN_DIR . 'templates/sitemap-manager.php';
    }

    public function handle_clear_history() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'seo-performance-checker'));
        }

        check_admin_referer('seopc_clear_history');

        update_option('seopc_analysis_history', []);

        wp_safe_redirect(add_query_arg([
            'page' => 'seo-performance',
            'history_cleared' => '1'
        ], admin_url('admin.php')));
        exit;
    }

}
