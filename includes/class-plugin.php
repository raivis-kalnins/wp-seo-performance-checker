<?php

class SEOPC_Plugin {
    
    private static $instance = null;
    
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init();
    }
    
    private function init() {
        // Load classes
        new SEOPC_Admin_Menu();
        new SEOPC_Dashboard_Widget();
        new SEOPC_Ajax_Handler();
        
        // Enqueue assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        
        // Add settings link
        add_filter('plugin_action_links_' . SEOPC_PLUGIN_BASENAME, [$this, 'add_settings_link']);
    }
    
    public function enqueue_assets($hook) {
        $allowed_pages = [
            'toplevel_page_seo-performance',
            'seo-performance_page_seo-meta-analyzer',
            'seo-performance_page_seo-sitemap',
            'index.php' // Dashboard
        ];
        
        if (!in_array($hook, $allowed_pages)) {
            return;
        }
        
        wp_enqueue_style(
            'seopc-admin-css',
            SEOPC_PLUGIN_URL . 'assets/css/admin.css',
            [],
            SEOPC_VERSION
        );
        
        wp_enqueue_script(
            'seopc-admin-js',
            SEOPC_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            SEOPC_VERSION,
            true
        );
        
        wp_localize_script('seopc-admin-js', 'seopc_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('seopc_nonce'),
            'strings' => [
                'analyzing' => __('Analyzing...', 'seo-performance-checker'),
                'error' => __('Error occurred', 'seo-performance-checker'),
                'success' => __('Analysis complete', 'seo-performance-checker')
            ]
        ]);
    }
    
    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=seo-performance') . '">' . __('Settings', 'seo-performance-checker') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
}