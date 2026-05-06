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
        new SEOPC_Admin_Columns();
        new SEOPC_Google_Integrations();
        new SEOPC_Post_Analytics_Widget();
        new SEOPC_Keyword_Tracker();
        new SEOPC_Competitor_Benchmark();
        new SEOPC_Media_Tools();
        new SEOPC_Dynamic_Overrides();
        new SEOPC_Meta_Import_Export();
        
        // Enqueue assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        
        // Add settings link
        add_filter('plugin_action_links_' . SEOPC_PLUGIN_BASENAME, [$this, 'add_settings_link']);
        add_action('admin_footer', [$this, 'render_results_modal']);
    }
    
    public function enqueue_assets($hook) {
        $allowed_pages = [
            'settings_page_seo-performance',
            'index.php' // Dashboard
        ];
        
        if (!in_array($hook, $allowed_pages) && !in_array($hook, ['post.php', 'post-new.php'], true)) {
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
            'admin_url' => admin_url(),
            'strings' => [
                'analyzing' => __('Analyzing...', 'seo-performance-checker'),
                'error' => __('Error occurred', 'seo-performance-checker'),
                'success' => __('Analysis complete', 'seo-performance-checker')
            ]
        ]);
    }
    

    public function render_results_modal() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            return;
        }

        $allowed_bases = ['settings_page_seo-performance', 'dashboard', 'post'];
        if (!in_array($screen->base, $allowed_bases, true)) {
            return;
        }

        if ($screen->base === 'settings_page_seo-performance') {
            return;
        }
        ?>
        <div id="seopc-results-modal" class="seopc-modal" style="display:none;">
            <div class="seopc-modal-backdrop"></div>
            <div class="seopc-modal-dialog">
                <div class="seopc-modal-header">
                    <h2><?php _e('Saved Results', 'seo-performance-checker'); ?></h2>
                    <button type="button" class="button-link seopc-modal-close" aria-label="<?php esc_attr_e('Close', 'seo-performance-checker'); ?>">&times;</button>
                </div>
                <div class="seopc-modal-body"></div>
            </div>
        </div>
        <?php
    }

    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=seo-performance') . '">' . __('Settings', 'seo-performance-checker') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
}