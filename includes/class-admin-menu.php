<?php

class SEOPC_Admin_Menu {
    
    public function __construct() {
        add_action('admin_menu', [$this, 'register_menus']);
        add_action('admin_post_seopc_clear_history', [$this, 'handle_clear_history']);
        add_action('admin_post_seopc_export_redirects_csv', [$this, 'handle_export_redirects_csv']);
    }
    
    public function register_menus() {
        add_submenu_page(
            'options-general.php',
            __('SEO Checker', 'seo-performance-checker'),
            __('SEO Checker', 'seo-performance-checker'),
            'manage_options',
            'seo-performance',
            [$this, 'render_main_page']
        );

        // Keep old direct URLs working, but hide them from the Settings submenu.
        add_submenu_page(null, __('Meta Analyzer', 'seo-performance-checker'), __('Meta Analyzer', 'seo-performance-checker'), 'manage_options', 'seo-meta-analyzer', [$this, 'redirect_legacy_meta_page']);
        add_submenu_page(null, __('Sitemap Manager', 'seo-performance-checker'), __('Sitemap Manager', 'seo-performance-checker'), 'manage_options', 'seo-sitemap', [$this, 'redirect_legacy_sitemap_page']);
    }

    public static function get_tabs() {
        return [
            'dashboard' => __('Dashboard', 'seo-performance-checker'),
            'meta-analyzer' => __('Meta Analyzer', 'seo-performance-checker'),
            'sitemap' => __('Sitemap Manager', 'seo-performance-checker'),
            'media-tools' => __('Media Tools', 'seo-performance-checker'),
            'dynamic-overrides' => __('Dynamic Overrides', 'seo-performance-checker'),
            'meta-import-export' => __('Meta Import / Export', 'seo-performance-checker'),
        ];
    }

    public static function get_current_tab() {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'dashboard';
        return array_key_exists($tab, self::get_tabs()) ? $tab : 'dashboard';
    }

    public static function render_tabs($active_tab = null) {
        $active_tab = $active_tab ?: self::get_current_tab();
        echo '<h2 class="nav-tab-wrapper seopc-admin-tabs">';
        foreach (self::get_tabs() as $tab_key => $tab_label) {
            $url = add_query_arg(['page' => 'seo-performance', 'tab' => $tab_key], admin_url('options-general.php'));
            printf(
                '<a href="%s" class="nav-tab %s">%s</a>',
                esc_url($url),
                $active_tab === $tab_key ? 'nav-tab-active' : '',
                esc_html($tab_label)
            );
        }
        echo '</h2>';
    }

    public function render_main_page() {
        $tab = self::get_current_tab();

        if ($tab === 'meta-analyzer') {
            include SEOPC_PLUGIN_DIR . 'templates/meta-analyzer.php';
            return;
        }

        if ($tab === 'sitemap') {
            include SEOPC_PLUGIN_DIR . 'templates/sitemap-manager.php';
            return;
        }

        if ($tab === 'media-tools' && class_exists('SEOPC_Media_Tools')) {
            $media_tools = new SEOPC_Media_Tools();
            $media_tools->render_admin_page();
            return;
        }

        if ($tab === 'dynamic-overrides' && class_exists('SEOPC_Dynamic_Overrides')) {
            $dynamic_overrides = new SEOPC_Dynamic_Overrides();
            $dynamic_overrides->render_admin_page();
            return;
        }

        if ($tab === 'meta-import-export' && class_exists('SEOPC_Meta_Import_Export')) {
            $meta_import_export = new SEOPC_Meta_Import_Export();
            $meta_import_export->render_admin_page();
            return;
        }

        include SEOPC_PLUGIN_DIR . 'templates/main-page.php';
    }

    public function redirect_legacy_meta_page() {
        wp_safe_redirect(add_query_arg(['page' => 'seo-performance', 'tab' => 'meta-analyzer'], admin_url('options-general.php')));
        exit;
    }

    public function redirect_legacy_sitemap_page() {
        wp_safe_redirect(add_query_arg(['page' => 'seo-performance', 'tab' => 'sitemap'], admin_url('options-general.php')));
        exit;
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
        ], admin_url('options-general.php')));
        exit;
    }

}
