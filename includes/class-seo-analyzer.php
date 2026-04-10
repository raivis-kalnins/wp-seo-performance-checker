<?php

class SEOPC_SEO_Analyzer {
    
    public function analyze_post($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('invalid_post', 'Post not found');
        }
        
        $content = $post->post_content;
        $url = get_permalink($post_id);
        $html = $this->fetch_page_html($url);
        
        $analysis = [
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'url' => $url,
            'basic_seo' => $this->analyze_basic_seo($post, $content),
            'images' => $this->analyze_images_detailed($content, $html),
            'headings' => $this->analyze_heading_structure_detailed($content),
            'accessibility' => $this->analyze_accessibility($html, $content),
            'html_semantics' => $this->analyze_html_semantics($html, $content),
            'clickability' => $this->analyze_clickability($html),
            'internal_links' => $this->analyze_internal_links($content),
            'timestamp' => current_time('mysql')
        ];
        
        $analysis['overall_score'] = $this->calculate_overall_score($analysis);
        $analysis['critical_issues'] = $this->count_critical_issues($analysis);
        
        // Save to history
        $this->save_analysis_history($analysis);
        
        return $analysis;
    }
    
    private function fetch_page_html($url) {
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'sslverify' => false,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]);
        
        if (is_wp_error($response)) {
            return '';
        }
        
        return wp_remote_retrieve_body($response);
    }
    
    private function analyze_basic_seo($post, $content) {
        return [
            'word_count' => str_word_count(strip_tags($content)),
            'has_excerpt' => !empty($post->post_excerpt),
            'permalink_structure' => $this->analyze_permalink($post),
            'categories' => count(wp_get_post_categories($post->ID)),
            'tags' => count(wp_get_post_tags($post->ID)),
            'has_featured_image' => has_post_thumbnail($post->ID)
        ];
    }
    
    private function analyze_images_detailed($content, $html) {
        preg_match_all('/<img[^>]+>/i', $content, $matches);
        $images = $matches[0];
        
        $analysis = [
            'total' => count($images),
            'optimized_count' => 0,
            'issues' => [],
            'recommendations' => []
        ];
        
        foreach ($images as $index => $img_tag) {
            $img_data = $this->parse_image_tag($img_tag);
            
            // Missing alt text
            if (empty($img_data['alt'])) {
                $analysis['issues'][] = [
                    'type' => 'missing_alt',
                    'severity' => 'critical',
                    'image' => basename($img_data['src'] ?: 'unnamed'),
                    'location' => 'Content',
                    'fix' => 'Add descriptive alt text',
                    'impact' => 'Accessibility and SEO penalty'
                ];
            } elseif (strlen($img_data['alt']) < 5) {
                $analysis['issues'][] = [
                    'type' => 'short_alt',
                    'severity' => 'warning',
                    'image' => basename($img_data['src']),
                    'current' => $img_data['alt'],
                    'fix' => 'Expand alt text (5-125 characters)'
                ];
            }
            
            // Missing dimensions
            if (empty($img_data['width']) || empty($img_data['height'])) {
                $analysis['issues'][] = [
                    'type' => 'missing_dimensions',
                    'severity' => 'warning',
                    'image' => basename($img_data['src']),
                    'fix' => 'Add width and height attributes',
                    'impact' => 'Causes layout shift (CLS)'
                ];
            }
            
            // Format optimization
            if ($img_data['src']) {
                $ext = strtolower(pathinfo($img_data['src'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png']) && !in_array($ext, ['webp', 'avif'])) {
                    $analysis['issues'][] = [
                        'type' => 'format_optimization',
                        'severity' => 'recommendation',
                        'image' => basename($img_data['src']),
                        'fix' => 'Convert to WebP format',
                        'savings' => '25-35%'
                    ];
                }
            }
            
            // Lazy loading
            if (strpos($img_tag, 'loading="lazy"') === false && $index > 2) {
                $analysis['issues'][] = [
                    'type' => 'lazy_loading',
                    'severity' => 'recommendation',
                    'image' => basename($img_data['src']),
                    'fix' => 'Add loading="lazy"'
                ];
            }
        }
        
        return $analysis;
    }
    
    private function parse_image_tag($tag) {
        $data = ['src' => '', 'alt' => '', 'width' => '', 'height' => ''];
        
        if (preg_match('/src=["\']([^"\']+)["\']/', $tag, $m)) $data['src'] = $m[1];
        if (preg_match('/alt=["\']([^"\']*)["\']/', $tag, $m)) $data['alt'] = $m[1];
        if (preg_match('/width=["\'](\d+)["\']/', $tag, $m)) $data['width'] = $m[1];
        if (preg_match('/height=["\'](\d+)["\']/', $tag, $m)) $data['height'] = $m[1];
        
        return $data;
    }
    
    private function analyze_heading_structure_detailed($content) {
        preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $content, $matches, PREG_SET_ORDER);
        
        $analysis = [
            'headings' => [],
            'structure_errors' => [],
            'hierarchy_violations' => [],
            'score' => 100
        ];
        
        $prev_level = 0;
        $h1_count = 0;
        
        foreach ($matches as $index => $match) {
            $level = intval($match[1]);
            $text = strip_tags($match[2]);
            
            // H1 check
            if ($level === 1) {
                $h1_count++;
                if ($h1_count > 1) {
                    $analysis['structure_errors'][] = [
                        'type' => 'multiple_h1',
                        'text' => $text,
                        'fix' => 'Change to H2'
                    ];
                    $analysis['score'] -= 20;
                }
            }
            
            // Hierarchy check
            if ($prev_level > 0 && $level > $prev_level + 1) {
                $analysis['hierarchy_violations'][] = [
                    'from' => "H$prev_level",
                    'to' => "H$level",
                    'text' => $text,
                    'fix' => "Add H" . ($prev_level + 1) . " before H$level"
                ];
                $analysis['score'] -= 10;
            }
            
            $analysis['headings'][] = [
                'level' => $level,
                'text' => substr($text, 0, 100)
            ];
            
            $prev_level = $level;
        }
        
        if ($h1_count === 0) {
            $analysis['structure_errors'][] = [
                'type' => 'missing_h1',
                'fix' => 'Add H1 heading'
            ];
            $analysis['score'] -= 25;
        }
        
        return $analysis;
    }
    
    private function analyze_accessibility($html, $content) {
        $analysis = [
            'violations' => [],
            'score' => 100
        ];
        
        // Check for skip link
        if (strpos($html, 'skip') === false && strpos($html, 'skip-link') === false) {
            $analysis['violations'][] = [
                'type' => 'missing_skip_link',
                'severity' => 'critical',
                'fix' => 'Add skip to content link'
            ];
            $analysis['score'] -= 15;
        }
        
        return $analysis;
    }
    
    private function analyze_html_semantics($html, $content) {
        $analysis = [
            'semantic_issues' => [],
            'attribute_issues' => [],
            'score' => 100
        ];
        
        // Check for semantic HTML5 tags
        $semantic_tags = ['header', 'nav', 'main', 'article', 'aside', 'footer'];
        foreach ($semantic_tags as $tag) {
            if (strpos($html, "<$tag") === false && strpos($html, "<$tag ") === false) {
                $analysis['semantic_issues'][] = [
                    'type' => "missing_$tag",
                    'recommendation' => "Use <$tag> element"
                ];
            }
        }
        
        return $analysis;
    }
    
    private function analyze_clickability($html) {
        $analysis = [
            'issues' => [],
            'score' => 100
        ];
        
        // Check touch targets
        if (preg_match_all('/<a[^>]*>([^<]{1,2})<\/a>/', $html, $small_links)) {
            $analysis['issues'][] = [
                'type' => 'small_touch_target',
                'count' => count($small_links[0]),
                'fix' => 'Increase link text or padding (min 48x48px)'
            ];
        }
        
        return $analysis;
    }
    
    private function analyze_internal_links($content) {
        preg_match_all('/href=["\']([^"\']+)["\']/', $content, $matches);
        
        $internal = 0;
        $external = 0;
        $broken = [];
        
        foreach ($matches[1] as $link) {
            if (strpos($link, home_url()) !== false || strpos($link, '/') === 0) {
                $internal++;
            } else {
                $external++;
            }
        }
        
        return [
            'internal' => $internal,
            'external' => $external,
            'total' => count($matches[1])
        ];
    }
    
    private function analyze_permalink($post) {
        $permalink = get_permalink($post);
        $slug = $post->post_name;
        
        $issues = [];
        
        if (strlen($slug) > 60) {
            $issues[] = 'URL too long';
        }
        
        if (preg_match('/[0-9]{4}\/[0-9]{2}\//', $permalink) && $post->post_type === 'page') {
            $issues[] = 'Date in page URL';
        }
        
        return [
            'url' => $permalink,
            'slug' => $slug,
            'issues' => $issues
        ];
    }
    
    private function calculate_overall_score($analysis) {
        $scores = [
            $analysis['headings']['score'] ?? 100,
            $analysis['accessibility']['score'] ?? 100,
            $analysis['html_semantics']['score'] ?? 100,
            $analysis['clickability']['score'] ?? 100
        ];
        
        // Deduct for images
        $image_penalty = count(array_filter($analysis['images']['issues'] ?? [], function($i) {
            return $i['severity'] === 'critical';
        })) * 5;
        
        $base_score = round(array_sum($scores) / count($scores));
        return max(0, $base_score - $image_penalty);
    }
    
    private function count_critical_issues($analysis) {
        $count = 0;
        
        foreach ($analysis['images']['issues'] ?? [] as $issue) {
            if ($issue['severity'] === 'critical') $count++;
        }
        
        foreach ($analysis['headings']['structure_errors'] ?? [] as $error) {
            $count++;
        }
        
        foreach ($analysis['accessibility']['violations'] ?? [] as $violation) {
            if ($violation['severity'] === 'critical') $count++;
        }
        
        return $count;
    }
    
    private function save_analysis_history($analysis) {
        $history = get_option('seopc_analysis_history', []);
        
        // Add new entry
        array_unshift($history, [
            'post_id' => $analysis['post_id'],
            'post_title' => $analysis['post_title'],
            'overall_score' => $analysis['overall_score'],
            'critical_issues' => $analysis['critical_issues'],
            'timestamp' => $analysis['timestamp']
        ]);
        
        // Keep only last 50 entries
        $history = array_slice($history, 0, 50);
        
        update_option('seopc_analysis_history', $history);
    }
}