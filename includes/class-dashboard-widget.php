<?php

class SEOPC_Dashboard_Widget {
    
    public function __construct() {
        add_action('wp_dashboard_setup', [$this, 'register_widget']);
    }
    
    public function register_widget() {
        wp_add_dashboard_widget(
            'seopc_dashboard_widget',
            __('SEO & Performance Overview', 'seo-performance-checker'),
            [$this, 'render_widget'],
            null,
            null,
            'normal',
            'high'
        );
    }
    
    public function render_widget() {
        include SEOPC_PLUGIN_DIR . 'templates/dashboard-widget.php';
    }
    
    public static function get_recent_checks() {
        return get_option('seopc_analysis_history', []);
    }
    
    public static function get_site_health_score() {
        $checks = get_option('seopc_analysis_history', []);
        if (empty($checks)) {
            return null;
        }
        
        $scores = array_column($checks, 'overall_score');
        return round(array_sum($scores) / count($scores));
    }
    
    public static function get_critical_issues_count() {
        $checks = get_option('seopc_analysis_history', []);
        $count = 0;
        
        foreach ($checks as $check) {
            if (isset($check['critical_issues'])) {
                $count += $check['critical_issues'];
            }
        }
        
        return $count;
    }
}