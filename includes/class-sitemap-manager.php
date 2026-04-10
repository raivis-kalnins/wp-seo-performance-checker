<?php

class SEOPC_Sitemap_Manager {
    
    private $post_types;
    private $taxonomies;
    
    public function __construct() {
        $settings = get_option('seopc_settings', []);
        $this->post_types = $settings['post_types'] ?? ['post', 'page'];
        $this->taxonomies = $settings['taxonomies'] ?? ['category', 'post_tag'];
    }
    
    public function generate_sitemap_index() {
        $sitemaps = [];
        
        foreach ($this->post_types as $post_type) {
            $count = wp_count_posts($post_type)->publish;
            $pages = ceil($count / 1000);
            
            for ($i = 1; $i <= $pages; $i++) {
                $sitemaps[] = [
                    'loc' => home_url("/sitemap-{$post_type}-{$i}.xml"),
                    'lastmod' => current_time('c')
                ];
            }
        }
        
        foreach ($this->taxonomies as $tax) {
            if (wp_count_terms($tax) > 0) {
                $sitemaps[] = [
                    'loc' => home_url("/sitemap-{$tax}.xml"),
                    'lastmod' => current_time('c')
                ];
            }
        }
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        foreach ($sitemaps as $sitemap) {
            $xml .= "\t<sitemap>\n";
            $xml .= "\t\t<loc>" . esc_url($sitemap['loc']) . "</loc>\n";
            $xml .= "\t\t<lastmod>" . $sitemap['lastmod'] . "</lastmod>\n";
            $xml .= "\t</sitemap>\n";
        }
        
        $xml .= '</sitemapindex>';
        
        return ['xml' => $xml, 'count' => count($sitemaps)];
    }
    
    public function generate_post_sitemap($post_type, $page = 1) {
        $posts = get_posts([
            'post_type' => $post_type,
            'post_status' => 'publish',
            'posts_per_page' => 1000,
            'paged' => $page,
            'orderby' => 'modified',
            'order' => 'DESC'
        ]);
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        foreach ($posts as $post) {
            $xml .= "\t<url>\n";
            $xml .= "\t\t<loc>" . esc_url(get_permalink($post)) . "</loc>\n";
            $xml .= "\t\t<lastmod>" . get_post_modified_time('c', true, $post) . "</lastmod>\n";
            $xml .= "\t\t<changefreq>" . $this->get_changefreq($post) . "</changefreq>\n";
            $xml .= "\t\t<priority>" . $this->get_priority($post) . "</priority>\n";
            $xml .= "\t</url>\n";
        }
        
        $xml .= '</urlset>';
        
        return ['xml' => $xml, 'count' => count($posts)];
    }
    
    public function analyze_sitemap_health() {
        $analysis = [
            'exists' => false,
            'valid' => false,
            'issues' => [],
            'stats' => []
        ];
        
        $sitemap_url = home_url('/sitemap.xml');
        $response = wp_remote_get($sitemap_url);
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = wp_remote_retrieve_body($response);
            $analysis['exists'] = true;
            $analysis['valid'] = $this->is_valid_xml($body);
            
            if (strpos($body, '<sitemapindex') !== false) {
                $analysis['type'] = 'index';
            } else {
                $analysis['type'] = 'single';
                $analysis['stats']['urls'] = substr_count($body, '<url>');
            }
        }
        
        // Check robots.txt
        $robots = wp_remote_get(home_url('/robots.txt'));
        if (!is_wp_error($robots)) {
            $robots_body = wp_remote_retrieve_body($robots);
            $analysis['robots_has_sitemap'] = strpos($robots_body, 'Sitemap:') !== false;
        }
        
        return $analysis;
    }
    
    public function find_orphaned_pages() {
        global $wpdb;
        
        $all_pages = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post', 'page')");
        
        // Simple orphaned check: posts with no internal links pointing to them
        // This is a simplified version - full implementation would parse all content
        $orphaned = [];
        
        foreach ($all_pages as $page_id) {
            $content = get_post_field('post_content', $page_id);
            $incoming_links = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status = 'publish'",
                '%' . get_permalink($page_id) . '%'
            ));
            
            if ($incoming_links == 0 && $page_id != get_option('page_on_front')) {
                $orphaned[] = $page_id;
            }
        }
        
        return [
            'total' => count($all_pages),
            'orphaned_count' => count($orphaned),
            'orphaned_ids' => array_slice($orphaned, 0, 20)
        ];
    }
    
    private function get_changefreq($post) {
        $age_days = (time() - strtotime($post->post_modified)) / 86400;
        
        if ($age_days < 1) return 'hourly';
        if ($age_days < 7) return 'daily';
        if ($age_days < 30) return 'weekly';
        if ($age_days < 365) return 'monthly';
        return 'yearly';
    }
    
    private function get_priority($post) {
        if ($post->post_type === 'page' && !$post->post_parent) return '1.0';
        if ($post->post_type === 'page') return '0.8';
        return '0.6';
    }
    
    private function is_valid_xml($xml) {
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        return empty($errors);
    }
}