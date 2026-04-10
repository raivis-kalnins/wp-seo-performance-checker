<?php

class SEOPC_Speed_Analyzer {
    
    public function analyze_speed($url) {
        $results = [
            'load_time' => $this->measure_load_time($url),
            'server_response' => $this->check_server_response($url),
            'page_size' => $this->analyze_page_size($url),
            'resource_count' => $this->count_resources($url),
            'recommendations' => [],
            'score' => 0
        ];
        
        // Calculate score
        $results['score'] = $this->calculate_speed_score($results);
        
        // Generate recommendations
        $results['recommendations'] = $this->generate_recommendations($results);
        
        return $results;
    }
    
    private function measure_load_time($url) {
        $start = microtime(true);
        
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'sslverify' => false
        ]);
        
        $load_time = microtime(true) - $start;
        
        return [
            'seconds' => round($load_time, 3),
            'milliseconds' => round($load_time * 1000),
            'status' => $load_time < 2 ? 'good' : ($load_time < 4 ? 'warning' : 'critical')
        ];
    }
    
    private function check_server_response($url) {
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'sslverify' => false
        ]);
        
        if (is_wp_error($response)) {
            return [
                'status_code' => 0,
                'error' => $response->get_error_message()
            ];
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $headers = wp_remote_retrieve_headers($response);
        
        return [
            'status_code' => $code,
            'content_type' => $headers['content-type'] ?? 'unknown',
            'cache_control' => $headers['cache-control'] ?? 'not set',
            'gzip_enabled' => isset($headers['content-encoding']) && strpos($headers['content-encoding'], 'gzip') !== false
        ];
    }
    
    private function analyze_page_size($url) {
        $response = wp_remote_get($url);
        
        if (is_wp_error($response)) {
            return ['error' => 'Failed to fetch'];
        }
        
        $body = wp_remote_retrieve_body($response);
        $size = strlen($body);
        
        // Analyze components
        $html_size = $size;
        $images_size = $this->estimate_images_size($body);
        $scripts = substr_count($body, '<script');
        $styles = substr_count($body, '<link rel="stylesheet"');
        
        return [
            'total_bytes' => $size,
            'total_kb' => round($size / 1024, 2),
            'total_mb' => round($size / 1024 / 1024, 2),
            'html_size_kb' => round($html_size / 1024, 2),
            'images_estimate_kb' => round($images_size / 1024, 2),
            'scripts_count' => $scripts,
            'stylesheets_count' => $styles,
            'status' => $size < 500000 ? 'good' : ($size < 2000000 ? 'warning' : 'critical')
        ];
    }
    
    private function estimate_images_size($html) {
        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/', $html, $matches);
        
        $total_size = 0;
        foreach ($matches[1] as $img_url) {
            $head = wp_remote_head($img_url, ['timeout' => 5]);
            if (!is_wp_error($head)) {
                $headers = wp_remote_retrieve_headers($head);
                if (isset($headers['content-length'])) {
                    $total_size += intval($headers['content-length']);
                }
            }
        }
        
        return $total_size;
    }
    
    private function count_resources($url) {
        $response = wp_remote_get($url);
        $html = wp_remote_retrieve_body($response);
        
        return [
            'images' => substr_count($html, '<img'),
            'scripts' => substr_count($html, '<script'),
            'stylesheets' => substr_count($html, '<link rel="stylesheet"'),
            'iframes' => substr_count($html, '<iframe')
        ];
    }
    
    private function calculate_speed_score($results) {
        $score = 100;
        
        // Load time penalty
        $load_time = $results['load_time']['seconds'];
        if ($load_time > 3) $score -= 30;
        elseif ($load_time > 2) $score -= 15;
        elseif ($load_time > 1) $score -= 5;
        
        // Size penalty
        $size_kb = $results['page_size']['total_kb'] ?? 0;
        if ($size_kb > 2000) $score -= 20;
        elseif ($size_kb > 1000) $score -= 10;
        
        // Resource penalty
        $scripts = $results['resource_count']['scripts'] ?? 0;
        if ($scripts > 20) $score -= 10;
        
        return max(0, $score);
    }
    
    private function generate_recommendations($results) {
        $recommendations = [];
        
        if ($results['load_time']['seconds'] > 3) {
            $recommendations[] = [
                'priority' => 'high',
                'issue' => 'Slow load time',
                'suggestion' => 'Enable caching and optimize images'
            ];
        }
        
        if (!($results['server_response']['gzip_enabled'] ?? false)) {
            $recommendations[] = [
                'priority' => 'high',
                'issue' => 'GZIP not enabled',
                'suggestion' => 'Enable GZIP compression on server'
            ];
        }
        
        if (($results['page_size']['total_kb'] ?? 0) > 1000) {
            $recommendations[] = [
                'priority' => 'medium',
                'issue' => 'Large page size',
                'suggestion' => 'Compress images and minify CSS/JS'
            ];
        }
        
        return $recommendations;
    }
}