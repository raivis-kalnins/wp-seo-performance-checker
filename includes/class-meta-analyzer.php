<?php

class SEOPC_Meta_Analyzer {
    
    public function analyze_all_meta($post_id = null) {
        if ($post_id) {
            $url = get_permalink($post_id);
        } else {
            $url = home_url();
        }
        
        $html = $this->fetch_html($url);
        
        return [
            'basic_meta' => $this->analyze_basic_meta($html, $post_id),
            'open_graph' => $this->analyze_open_graph($html),
            'twitter_cards' => $this->analyze_twitter_cards($html),
            'technical_meta' => $this->analyze_technical_meta($html),
            'canonical' => $this->analyze_canonical($html, $url),
            'robots' => $this->analyze_robots_meta($html),
            'score' => 0
        ];
    }
    
    private function fetch_html($url) {
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'sslverify' => false
        ]);
        
        return is_wp_error($response) ? '' : wp_remote_retrieve_body($response);
    }
    
    private function analyze_basic_meta($html, $post_id) {
        return [
            'title' => $this->analyze_title_tag($html, $post_id),
            'description' => $this->analyze_meta_description($html, $post_id)
        ];
    }
    
    private function analyze_title_tag($html, $post_id) {
        preg_match('/<title>(.*?)<\/title>/si', $html, $matches);
        $title = isset($matches[1]) ? trim($matches[1]) : '';
        $length = strlen($title);
        
        $issues = [];
        
        if ($length < 30) {
            $issues[] = ['type' => 'too_short', 'message' => 'Title too short'];
        } elseif ($length > 60) {
            $issues[] = ['type' => 'too_long', 'message' => 'Title will be truncated'];
        }
        
        return [
            'content' => $title,
            'length' => $length,
            'status' => empty($issues) ? 'good' : 'warning',
            'issues' => $issues
        ];
    }
    
    private function analyze_meta_description($html, $post_id) {
        preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches);
        $description = isset($matches[1]) ? trim($matches[1]) : '';
        $length = strlen($description);
        
        $issues = [];
        
        if (empty($description)) {
            $issues[] = ['type' => 'missing', 'severity' => 'critical'];
        } elseif ($length < 120) {
            $issues[] = ['type' => 'too_short'];
        } elseif ($length > 160) {
            $issues[] = ['type' => 'too_long'];
        }
        
        return [
            'content' => $description,
            'length' => $length,
            'status' => empty($issues) ? 'good' : (isset($issues[0]['severity']) ? 'critical' : 'warning'),
            'issues' => $issues
        ];
    }
    
    private function analyze_open_graph($html) {
        $og_tags = ['og:title', 'og:description', 'og:image', 'og:url', 'og:type'];
        $present = [];
        $missing = [];
        
        foreach ($og_tags as $tag) {
            $pattern = '/<meta[^>]+property=["\']' . $tag . '["\'][^>]+content=["\']([^"\']+)["\']/i';
            if (preg_match($pattern, $html, $m)) {
                $present[$tag] = $m[1];
            } else {
                $missing[] = $tag;
            }
        }
        
        return [
            'present' => $present,
            'missing' => $missing,
            'complete' => empty($missing)
        ];
    }
    
    private function analyze_twitter_cards($html) {
        $twitter_tags = ['twitter:card', 'twitter:title', 'twitter:description', 'twitter:image'];
        $present = [];
        $missing = [];
        
        foreach ($twitter_tags as $tag) {
            $pattern = '/<meta[^>]+name=["\']' . $tag . '["\'][^>]+content=["\']([^"\']+)["\']/i';
            if (preg_match($pattern, $html, $m)) {
                $present[$tag] = $m[1];
            } else {
                $missing[] = $tag;
            }
        }
        
        return [
            'present' => $present,
            'missing' => $missing,
            'card_type' => $present['twitter:card'] ?? 'none'
        ];
    }
    
    private function analyze_technical_meta($html) {
        $viewport = preg_match('/<meta[^>]+name=["\']viewport["\']/', $html);
        $charset = preg_match('/<meta[^>]+charset=/i', $html);
        
        return [
            'viewport' => $viewport,
            'charset' => $charset,
            'status' => ($viewport && $charset) ? 'good' : 'warning'
        ];
    }
    
    private function analyze_canonical($html, $url) {
        preg_match('/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/i', $html, $matches);
        
        if (empty($matches[1])) {
            return [
                'present' => false,
                'status' => 'warning',
                'recommended' => $url
            ];
        }
        
        return [
            'present' => true,
            'url' => $matches[1],
            'correct' => trailingslashit($matches[1]) === trailingslashit($url)
        ];
    }
    
    private function analyze_robots_meta($html) {
        preg_match('/<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches);
        
        if (empty($matches[1])) {
            return [
                'present' => false,
                'indexable' => true,
                'message' => 'Defaults to index, follow'
            ];
        }
        
        $content = strtolower($matches[1]);
        
        return [
            'present' => true,
            'content' => $content,
            'indexable' => strpos($content, 'noindex') === false,
            'followable' => strpos($content, 'nofollow') === false
        ];
    }
}