<?php

class SEOPC_Ajax_Handler {
    
    public function __construct() {
        add_action('wp_ajax_seopc_analyze_post', [$this, 'analyze_post']);
        add_action('wp_ajax_seopc_analyze_meta', [$this, 'analyze_meta']);
        add_action('wp_ajax_seopc_check_sitemap', [$this, 'check_sitemap']);
        add_action('wp_ajax_seopc_find_orphaned', [$this, 'find_orphaned']);
        add_action('wp_ajax_seopc_bulk_analysis', [$this, 'bulk_analysis']);
    }
    
    public function analyze_post() {
        check_ajax_referer('seopc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $post_id = intval($_POST['post_id']);
        
        $analyzer = new SEOPC_SEO_Analyzer();
        $speed_analyzer = new SEOPC_Speed_Analyzer();
        
        $seo_results = $analyzer->analyze_post($post_id);
        $speed_results = $speed_analyzer->analyze_speed(get_permalink($post_id));
        
        wp_send_json_success([
            'seo' => $seo_results,
            'speed' => $speed_results
        ]);
    }
    
    public function analyze_meta() {
        check_ajax_referer('seopc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $post_id = !empty($_POST['post_id']) ? intval($_POST['post_id']) : null;
        
        $analyzer = new SEOPC_Meta_Analyzer();
        $results = $analyzer->analyze_all_meta($post_id);
        
        wp_send_json_success($results);
    }
    
    public function check_sitemap() {
        check_ajax_referer('seopc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $manager = new SEOPC_Sitemap_Manager();
        $health = $manager->analyze_sitemap_health();
        
        wp_send_json_success($health);
    }
    
    public function find_orphaned() {
        check_ajax_referer('seopc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $manager = new SEOPC_Sitemap_Manager();
        $orphaned = $manager->find_orphaned_pages();
        
        wp_send_json_success($orphaned);
    }
    
    public function bulk_analysis() {
        check_ajax_referer('seopc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $post_type = sanitize_text_field($_POST['post_type'] ?? 'post');
        $issue_type = sanitize_text_field($_POST['issue_type'] ?? 'all');
        
        $posts = get_posts([
            'post_type' => $post_type === 'all' ? ['post', 'page'] : $post_type,
            'posts_per_page' => 50,
            'post_status' => 'publish'
        ]);
        
        $results = [];
        $analyzer = new SEOPC_Meta_Analyzer();
        
        foreach ($posts as $post) {
            $meta = $analyzer->analyze_all_meta($post->ID);
            
            $issues = [];
            if ($issue_type === 'missing_title' && empty($meta['basic_meta']['title']['content'])) {
                $issues[] = 'Missing title';
            }
            if ($issue_type === 'long_title' && $meta['basic_meta']['title']['length'] > 60) {
                $issues[] = 'Title too long';
            }
            if ($issue_type === 'missing_description' && empty($meta['basic_meta']['description']['content'])) {
                $issues[] = 'Missing description';
            }
            
            if ($issue_type === 'all' || !empty($issues)) {
                $results[] = [
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'title_length' => $meta['basic_meta']['title']['length'],
                    'has_description' => !empty($meta['basic_meta']['description']['content']),
                    'has_og_image' => !empty($meta['open_graph']['present']['og:image']),
                    'issues' => $issues
                ];
            }
        }
        
        wp_send_json_success($results);
    }
}