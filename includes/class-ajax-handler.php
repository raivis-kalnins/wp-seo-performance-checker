<?php

class SEOPC_Ajax_Handler {
    
    public function __construct() {
        add_action('wp_ajax_seopc_analyze_post', [$this, 'analyze_post']);
        add_action('wp_ajax_seopc_analyze_meta', [$this, 'analyze_meta']);
        add_action('wp_ajax_seopc_check_sitemap', [$this, 'check_sitemap']);
        add_action('wp_ajax_seopc_find_orphaned', [$this, 'find_orphaned']);
        add_action('wp_ajax_seopc_bulk_analysis', [$this, 'bulk_analysis']);
        add_action('wp_ajax_seopc_get_saved_result', [$this, 'get_saved_result']);
        add_action('wp_ajax_seopc_bulk_retest', [$this, 'bulk_retest']);
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
        
        $requests_count = (int) (($speed_results['resource_count']['images'] ?? 0) + ($speed_results['resource_count']['scripts'] ?? 0) + ($speed_results['resource_count']['stylesheets'] ?? 0) + ($speed_results['resource_count']['iframes'] ?? 0));

        $this->update_history_entry($post_id, [
            'requests_count' => $requests_count,
            'speed_score' => $speed_results['score'] ?? 0,
            'speed_results' => $speed_results,
            'internal_links' => $seo_results['internal_links'] ?? []
        ]);

        wp_send_json_success([
            'seo' => $seo_results,
            'speed' => $speed_results,
            'history' => [
                'requests_count' => $requests_count
            ]
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
    

    public function get_saved_result() {
        check_ajax_referer('seopc_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }

        $post_id = intval($_POST['post_id'] ?? 0);
        if (!$post_id) {
            wp_send_json_error('Invalid post');
        }

        $history = get_option('seopc_analysis_history', []);
        foreach ($history as $entry) {
            if ((int) ($entry['post_id'] ?? 0) === $post_id) {
                wp_send_json_success($entry);
            }
        }

        wp_send_json_error('No saved results found');
    }

    private function update_history_entry($post_id, $data) {
        $history = get_option('seopc_analysis_history', []);

        foreach ($history as $index => $entry) {
            if ((int) ($entry['post_id'] ?? 0) === (int) $post_id) {
                $history[$index] = array_merge($entry, $data);
                update_option('seopc_analysis_history', $history);
                return;
            }
        }
    }

    public function bulk_retest() {
        check_ajax_referer('seopc_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $post_ids = array_map('intval', (array) ($_POST['post_ids'] ?? []));
        $post_ids = array_values(array_filter(array_unique($post_ids)));

        if (empty($post_ids)) {
            wp_send_json_error('No items selected');
        }

        $analyzer = new SEOPC_SEO_Analyzer();
        $speed_analyzer = new SEOPC_Speed_Analyzer();
        $results = [];

        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish') {
                continue;
            }

            $seo_results = $analyzer->analyze_post($post_id);
            if (is_wp_error($seo_results)) {
                continue;
            }

            $speed_results = $speed_analyzer->analyze_speed(get_permalink($post_id));
            $requests_count = (int) (($speed_results['resource_count']['images'] ?? 0) + ($speed_results['resource_count']['scripts'] ?? 0) + ($speed_results['resource_count']['stylesheets'] ?? 0) + ($speed_results['resource_count']['iframes'] ?? 0));

            $this->update_history_entry($post_id, [
                'requests_count' => $requests_count,
                'speed_score' => $speed_results['score'] ?? 0,
                'speed_results' => $speed_results,
                'internal_links' => $seo_results['internal_links'] ?? [],
                'post_title' => get_the_title($post_id),
                'post_type' => get_post_type($post_id),
                'slug' => get_post_field('post_name', $post_id),
                'url' => get_permalink($post_id),
            ]);

            $results[] = [
                'post_id' => $post_id,
                'post_title' => get_the_title($post_id),
                'post_type' => get_post_type($post_id),
                'overall_score' => $seo_results['overall_score'] ?? 0,
                'critical_issues' => $seo_results['critical_issues'] ?? 0,
                'broken_links' => (int) (($seo_results['internal_links']['broken_count'] ?? 0)),
                'requests_count' => $requests_count,
            ];
        }

        wp_send_json_success([
            'processed' => count($results),
            'results' => $results,
        ]);
    }

    public function bulk_analysis() {
        check_ajax_referer('seopc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $post_type = sanitize_text_field($_POST['post_type'] ?? 'post');
        $issue_type = sanitize_text_field($_POST['issue_type'] ?? 'all');
        
        $all_post_types = get_post_types(['public' => true], 'names');
        unset($all_post_types['attachment']);

        $posts = get_posts([
            'post_type' => $post_type === 'all' ? array_values($all_post_types) : $post_type,
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