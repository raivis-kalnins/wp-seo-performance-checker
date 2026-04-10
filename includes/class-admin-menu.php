<?php

class SEOPC_Admin_Menu {
    
    public function __construct() {
        add_action('admin_menu', [$this, 'register_menus']);
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
}