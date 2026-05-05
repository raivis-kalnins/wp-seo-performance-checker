<?php

class SEOPC_SEO_Analyzer {

    private $link_status_cache = [];

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
            'post_type' => $post->post_type,
            'url' => $url,
            'basic_seo' => $this->analyze_basic_seo($post, $content),
            'images' => $this->analyze_images_detailed($content, $html),
            'headings' => $this->analyze_heading_structure_detailed($content, $html),
            'accessibility' => $this->analyze_accessibility($html, $content),
            'html_semantics' => $this->analyze_html_semantics($html, $content),
            'clickability' => $this->analyze_clickability($html),
            'internal_links' => $this->analyze_links($content, $url),
            'timestamp' => current_time('mysql')
        ];

        $analysis['score_breakdown'] = $this->build_score_breakdown($analysis);
        $analysis['overall_score'] = $analysis['score_breakdown']['final_score'];
        $analysis['critical_issues'] = $this->count_critical_issues($analysis);

        $this->save_analysis_history($analysis);

        return $analysis;
    }

    private function fetch_page_html($url) {
        $response = wp_remote_get($url, [
            'timeout' => 20,
            'redirection' => 5,
            'sslverify' => false,
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Cache-Control' => 'no-cache',
            ],
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36 SEO-Performance-Checker/' . SEOPC_VERSION
        ]);

        if (is_wp_error($response)) {
            return '';
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 400) {
            return '';
        }

        return (string) wp_remote_retrieve_body($response);
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
        $images = $this->get_rendered_image_tags($html);
        if (empty($images)) {
            preg_match_all('/<img[^>]+>/i', $content, $matches);
            $images = $matches[0];
        }

        $analysis = [
            'total' => count($images),
            'optimized_count' => 0,
            'issues' => [],
            'recommendations' => [],
            'items' => [],
            'total_size_bytes' => 0,
            'total_size_label' => '0 B'
        ];

        foreach ($images as $index => $img_tag) {
            $img_data = $this->parse_image_tag($img_tag);
            $details = $this->get_image_details($img_data, $img_tag);
            $analysis['items'][] = $details;
            $analysis['total_size_bytes'] += (int) ($details['size_bytes'] ?? 0);

            if (empty($img_data['alt'])) {
                $analysis['issues'][] = [
                    'type' => 'missing_alt',
                    'severity' => 'critical',
                    'image' => basename($img_data['src'] ?: 'unnamed'),
                    'image_url' => $details['src'],
                    'media_library_url' => $details['media_library_url'],
                    'location' => 'Content',
                    'fix' => 'Add descriptive alt text',
                    'impact' => 'Accessibility and SEO penalty'
                ];
            } elseif (strlen($img_data['alt']) < 5) {
                $analysis['issues'][] = [
                    'type' => 'short_alt',
                    'severity' => 'warning',
                    'image' => basename($img_data['src']),
                    'image_url' => $details['src'],
                    'media_library_url' => $details['media_library_url'],
                    'current' => $img_data['alt'],
                    'fix' => 'Expand alt text (5-125 characters)'
                ];
            }

            if (empty($img_data['width']) || empty($img_data['height'])) {
                $analysis['issues'][] = [
                    'type' => 'missing_dimensions',
                    'severity' => 'warning',
                    'image' => basename($img_data['src']),
                    'image_url' => $details['src'],
                    'media_library_url' => $details['media_library_url'],
                    'fix' => 'Add width and height attributes',
                    'impact' => 'Causes layout shift (CLS)'
                ];
            }

            if ($img_data['src']) {
                $ext = strtolower(pathinfo((string) wp_parse_url($img_data['src'], PHP_URL_PATH), PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png'], true) && !in_array($ext, ['webp', 'avif'], true)) {
                    $analysis['issues'][] = [
                        'type' => 'format_optimization',
                        'severity' => 'recommendation',
                        'image' => basename($img_data['src']),
                        'image_url' => $details['src'],
                        'media_library_url' => $details['media_library_url'],
                        'fix' => 'Convert to WebP or AVIF format',
                        'savings' => '25-35%'
                    ];
                }
            }

            if (strpos($img_tag, 'loading="lazy"') === false && $index > 2) {
                $analysis['issues'][] = [
                    'type' => 'lazy_loading',
                    'severity' => 'recommendation',
                    'image' => basename($img_data['src']),
                    'image_url' => $details['src'],
                    'media_library_url' => $details['media_library_url'],
                    'fix' => 'Add loading="lazy"'
                ];
            }

            if (!empty($details['modern_format'])) {
                $analysis['optimized_count']++;
            }
        }

        $analysis['total_size_label'] = $this->format_bytes($analysis['total_size_bytes']);

        return $analysis;
    }

    private function parse_image_tag($tag) {
        $data = ['src' => '', 'alt' => '', 'width' => '', 'height' => '', 'class' => '', 'loading' => ''];

        if (preg_match('/src=["\']([^"\']+)["\']/', $tag, $m)) {
            $data['src'] = $m[1];
        }
        if (preg_match('/alt=["\']([^"\']*)["\']/', $tag, $m)) {
            $data['alt'] = $m[1];
        }
        if (preg_match('/width=["\'](\d+)["\']/', $tag, $m)) {
            $data['width'] = $m[1];
        }
        if (preg_match('/height=["\'](\d+)["\']/', $tag, $m)) {
            $data['height'] = $m[1];
        }
        if (preg_match('/class=["\']([^"\']+)["\']/', $tag, $m)) {
            $data['class'] = $m[1];
        }
        if (preg_match('/loading=["\']([^"\']+)["\']/', $tag, $m)) {
            $data['loading'] = $m[1];
        }

        return $data;
    }

    private function get_image_details($img_data, $img_tag) {
        $src = $img_data['src'] ?? '';
        $attachment_id = 0;

        if (preg_match('/wp-image-(\d+)/', $img_tag, $class_match)) {
            $attachment_id = (int) $class_match[1];
        }

        if (!$attachment_id && $src) {
            $attachment_id = attachment_url_to_postid($src);
        }

        $home_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $image_host = wp_parse_url($src, PHP_URL_HOST);
        $is_external = $image_host && $home_host && strtolower($image_host) !== strtolower($home_host);
        $path = (string) wp_parse_url($src, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $size_bytes = 0;
        $media_library_url = '';
        $attachment_url = '';

        if ($attachment_id) {
            $meta = wp_get_attachment_metadata($attachment_id);
            if (!empty($meta['filesize'])) {
                $size_bytes = (int) $meta['filesize'];
            } else {
                $file = get_attached_file($attachment_id);
                if ($file && file_exists($file)) {
                    $size_bytes = (int) filesize($file);
                }
            }
            $media_library_url = admin_url('post.php?post=' . $attachment_id . '&action=edit');
            $attachment_url = wp_get_attachment_url($attachment_id) ?: '';
        }

        if (!$size_bytes && $src) {
            $size_bytes = $this->fetch_remote_file_size($src);
        }

        return [
            'src' => $src,
            'basename' => basename($path ?: $src),
            'alt' => $img_data['alt'] ?? '',
            'width' => $img_data['width'] ?? '',
            'height' => $img_data['height'] ?? '',
            'dimensions' => (!empty($img_data['width']) && !empty($img_data['height'])) ? ($img_data['width'] . '×' . $img_data['height']) : '',
            'loading' => $img_data['loading'] ?? '',
            'extension' => $extension,
            'modern_format' => in_array($extension, ['webp', 'avif', 'svg'], true),
            'size_bytes' => $size_bytes,
            'size_label' => $this->format_bytes($size_bytes),
            'attachment_id' => $attachment_id,
            'attachment_url' => $attachment_url,
            'media_library_url' => $media_library_url,
            'is_external' => (bool) $is_external,
            'source_label' => $attachment_id ? 'media-library' : ($is_external ? 'external' : 'content-url')
        ];
    }

    private function fetch_remote_file_size($url) {
        $head = wp_remote_head($url, ['timeout' => 5, 'sslverify' => false, 'redirection' => 3]);
        if (is_wp_error($head)) {
            return 0;
        }

        $headers = wp_remote_retrieve_headers($head);
        if (isset($headers['content-length'])) {
            return (int) $headers['content-length'];
        }

        return 0;
    }

    private function format_bytes($bytes) {
        $bytes = (int) $bytes;
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $power = (int) floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);
        $value = $bytes / pow(1024, $power);

        return round($value, $power === 0 ? 0 : 2) . ' ' . $units[$power];
    }
    private function analyze_heading_structure_detailed($content, $html = '') {
        $matches = $this->get_rendered_headings($html);
        $source = 'front-end';

        if (empty($matches)) {
            $filtered_content = (string) apply_filters('the_content', $content);
            $matches = $this->get_rendered_headings($filtered_content);
            $source = 'filtered-content';
        }

        if (empty($matches)) {
            $matches = $this->get_rendered_headings($content);
            $source = 'editor-content';
        }

        $analysis = [
            'headings' => [],
            'structure_errors' => [],
            'hierarchy_violations' => [],
            'score' => 100,
            'source' => $source
        ];

        $prev_level = 0;
        $h1_count = 0;

        foreach ($matches as $match) {
            $level = intval($match[1]);
            $text = trim((string) html_entity_decode(wp_strip_all_tags($match[2]), ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8'));
            $text = preg_replace('/\s+/', ' ', $text);

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

            if ($prev_level > 0 && $level > $prev_level + 1) {
                $analysis['hierarchy_violations'][] = [
                    'from' => 'H' . $prev_level,
                    'to' => 'H' . $level,
                    'text' => $text,
                    'fix' => 'Add H' . ($prev_level + 1) . ' before H' . $level
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

        $analysis['score'] = max(0, (int) $analysis['score']);

        return $analysis;
    }


    private function get_rendered_image_tags($html) {
        $html = $this->prepare_visible_frontend_html($html);
        if ($html === '') {
            return [];
        }

        if (preg_match_all('/<img\b[^>]*>/i', $html, $matches)) {
            return $matches[0];
        }

        return [];
    }

    private function get_rendered_headings($html) {
        $html = $this->prepare_visible_frontend_html($html);
        if ($html === '') {
            return [];
        }

        if (preg_match_all('/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is', $html, $matches, PREG_SET_ORDER)) {
            return $matches;
        }

        return [];
    }

    private function prepare_visible_frontend_html($html) {
        if (!is_string($html) || trim($html) === '') {
            return '';
        }

        // Prefer the real document body. This avoids counting headings embedded in
        // the <head>, preload JSON, serialized block data, or other support markup.
        if (preg_match('/<body\b[^>]*>(.*?)<\/body>/is', $html, $body_match)) {
            $html = $body_match[1];
        }

        // Remove containers that are not part of the normal visible DOM scan.
        // React/headless setups often place a full HTML copy in JSON/script data
        // and again inside <noscript>; counting those duplicates produces false
        // multiple-H1 errors even when the rendered page has one visible H1.
        $html = preg_replace('/<(script|style|template|noscript|svg)\b[^>]*>.*?<\/\1>/is', '', $html);

        if (!is_string($html)) {
            return '';
        }

        return trim($html);
    }

    private function analyze_accessibility($html, $content) {
        $analysis = [
            'violations' => [],
            'score' => 100
        ];

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

        foreach (['header', 'nav', 'main', 'article', 'aside', 'footer'] as $tag) {
            if (strpos($html, '<' . $tag) === false && strpos($html, '<' . $tag . ' ') === false) {
                $analysis['semantic_issues'][] = [
                    'type' => 'missing_' . $tag,
                    'recommendation' => 'Use <' . $tag . '> element'
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

        if (preg_match_all('/<a[^>]*>([^<]{1,2})<\/a>/', $html, $small_links)) {
            $analysis['issues'][] = [
                'type' => 'small_touch_target',
                'count' => count($small_links[0]),
                'fix' => 'Increase link text or padding (min 48x48px)'
            ];
        }

        return $analysis;
    }

    private function analyze_links($content, $page_url) {
        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER);

        $home_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $page_scheme = wp_parse_url($page_url, PHP_URL_SCHEME) ?: 'https';
        $page_host = wp_parse_url($page_url, PHP_URL_HOST) ?: $home_host;
        $max_checks = 15;
        $internal = 0;
        $external = 0;
        $checked = 0;
        $skipped = 0;
        $broken_urls = [];
        $external_urls = [];
        $details = [];
        $seen = [];

        foreach ($matches as $match) {
            $raw_url = html_entity_decode(trim($match[1]));
            $anchor_text = trim(wp_strip_all_tags($match[2]));

            if ($this->should_skip_link($raw_url)) {
                $skipped++;
                continue;
            }

            $normalized_url = $this->normalize_link_url($raw_url, $page_scheme, $page_host);
            if (!$normalized_url) {
                $skipped++;
                continue;
            }

            $target_host = wp_parse_url($normalized_url, PHP_URL_HOST);
            $is_external = $target_host && $home_host && strtolower($target_host) !== strtolower($home_host);

            if ($is_external) {
                $external++;
                if (!isset($external_urls[$normalized_url])) {
                    $external_urls[$normalized_url] = [
                        'url' => $normalized_url,
                        'anchor' => $anchor_text ?: $normalized_url
                    ];
                }
            } else {
                $internal++;
            }

            if (isset($seen[$normalized_url])) {
                continue;
            }

            $seen[$normalized_url] = true;
            $status = null;
            $is_broken = false;

            if ($checked < $max_checks) {
                $checked++;
                $status = $this->check_link_status($normalized_url);
                $is_broken = (bool) ($status['broken'] ?? false);

                if ($is_broken && !isset($broken_urls[$normalized_url])) {
                    $broken_urls[$normalized_url] = [
                        'url' => $normalized_url,
                        'status_code' => $status['status_code'] ?? 0,
                        'message' => $status['message'] ?? '',
                        'anchor' => $anchor_text ?: $normalized_url
                    ];
                }
            }

            $details[] = [
                'url' => $normalized_url,
                'anchor' => $anchor_text,
                'external' => $is_external,
                'status_code' => $status['status_code'] ?? null,
                'broken' => $is_broken
            ];
        }

        $broken_url_rows = array_values($broken_urls);
        $redirect_suggestions = $this->build_redirect_suggestions($broken_url_rows);

        return [
            'internal' => $internal,
            'external' => $external,
            'total' => $internal + $external,
            'checked_count' => $checked,
            'check_limit' => $max_checks,
            'scan_limited' => count($seen) > $max_checks,
            'skipped' => $skipped,
            'broken_count' => count($broken_urls),
            'broken_urls' => $broken_url_rows,
            'external_urls' => array_slice(array_values($external_urls), 0, 10),
            'redirect_suggestions' => $redirect_suggestions,
            'redirect_count' => count($redirect_suggestions),
            'details' => array_slice($details, 0, 20)
        ];
    }

    private function should_skip_link($url) {
        if ($url === '' || strpos($url, '#') === 0) {
            return true;
        }

        foreach (['mailto:', 'tel:', 'sms:', 'javascript:', 'data:'] as $prefix) {
            if (stripos($url, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    private function normalize_link_url($url, $page_scheme, $page_host) {
        if (strpos($url, '//') === 0) {
            return $page_scheme . ':' . $url;
        }

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        if (strpos($url, '/') === 0) {
            return $page_scheme . '://' . $page_host . $url;
        }

        return '';
    }

    private function build_redirect_suggestions($broken_urls) {
        if (empty($broken_urls)) {
            return [];
        }

        $suggestions = [];
        foreach ($broken_urls as $broken) {
            $from_url = $broken['url'] ?? '';
            $candidate = $this->find_redirect_target_for_url($from_url);
            if (!$candidate) {
                continue;
            }

            $confidence = 'low';
            if ($candidate['match_score'] >= 90) {
                $confidence = 'high';
            } elseif ($candidate['match_score'] >= 70) {
                $confidence = 'medium';
            }

            $path = wp_parse_url($from_url, PHP_URL_PATH);
            $suggestions[] = [
                'from_url' => $from_url,
                'from_path' => $path ?: '',
                'anchor' => $broken['anchor'] ?? '',
                'status_code' => $broken['status_code'] ?? 0,
                'to_post_id' => $candidate['post_id'],
                'to_title' => $candidate['title'],
                'to_slug' => $candidate['slug'],
                'to_url' => $candidate['url'],
                'match_score' => $candidate['match_score'],
                'confidence_label' => $confidence,
                'reason' => $candidate['reason'],
            ];
        }

        return $suggestions;
    }

    private function find_redirect_target_for_url($url) {
        $path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');
        if ($path === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', strtolower($path))));
        if (empty($segments)) {
            return null;
        }

        $target_slug = sanitize_title(end($segments));
        if ($target_slug === '') {
            return null;
        }

        $post_types = get_post_types(['public' => true], 'names');
        unset($post_types['attachment']);

        $posts = get_posts([
            'post_type' => array_values($post_types),
            'post_status' => 'publish',
            'posts_per_page' => 200,
            'orderby' => 'date',
            'order' => 'DESC',
            's' => str_replace('-', ' ', $target_slug),
        ]);

        $best = null;
        foreach ($posts as $post) {
            $post_slug = get_post_field('post_name', $post->ID);
            $score = 0;
            $reason = __('similar content match', 'seo-performance-checker');

            if ($post_slug === $target_slug) {
                $score = 100;
                $reason = __('exact slug match', 'seo-performance-checker');
            } elseif (strpos($post_slug, $target_slug) !== false || strpos($target_slug, $post_slug) !== false) {
                $score = 85;
                $reason = __('close slug match', 'seo-performance-checker');
            } else {
                similar_text($target_slug, $post_slug, $percent);
                $score = (int) round($percent);
                if ($score < 55) {
                    similar_text(str_replace('-', ' ', $target_slug), strtolower($post->post_title), $title_percent);
                    $score = max($score, (int) round($title_percent));
                    $reason = __('title similarity match', 'seo-performance-checker');
                } else {
                    $reason = __('slug similarity match', 'seo-performance-checker');
                }
            }

            if ($score < 55) {
                continue;
            }

            $candidate = [
                'post_id' => $post->ID,
                'title' => get_the_title($post->ID),
                'slug' => $post_slug,
                'url' => get_permalink($post->ID),
                'match_score' => $score,
                'reason' => $reason,
            ];

            if ($best === null || $candidate['match_score'] > $best['match_score']) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function check_link_status($url) {
        if (isset($this->link_status_cache[$url])) {
            return $this->link_status_cache[$url];
        }

        $args = [
            'timeout' => 6,
            'sslverify' => false,
            'redirection' => 3,
            'headers' => [
                'user-agent' => 'SEO Performance Checker/1.1.0'
            ]
        ];

        $response = wp_remote_head($url, $args);
        if (is_wp_error($response)) {
            $response = wp_remote_get($url, array_merge($args, ['limit_response_size' => 1024]));
        }

        if (is_wp_error($response)) {
            $result = [
                'broken' => true,
                'status_code' => 0,
                'message' => $response->get_error_message()
            ];
            $this->link_status_cache[$url] = $result;
            return $result;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $result = [
            'broken' => $status_code >= 400 || $status_code === 0,
            'status_code' => $status_code,
            'message' => ($status_code >= 400 || $status_code === 0) ? 'HTTP ' . $status_code : 'OK'
        ];

        $this->link_status_cache[$url] = $result;
        return $result;
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

    private function build_score_breakdown($analysis) {
        $scores = [
            'headings' => (int) ($analysis['headings']['score'] ?? 100),
            'accessibility' => (int) ($analysis['accessibility']['score'] ?? 100),
            'html_semantics' => (int) ($analysis['html_semantics']['score'] ?? 100),
            'clickability' => (int) ($analysis['clickability']['score'] ?? 100),
        ];

        $image_critical_count = count(array_filter($analysis['images']['issues'] ?? [], function($i) {
            return ($i['severity'] ?? '') === 'critical';
        }));

        $image_penalty = $image_critical_count * 5;
        $broken_link_count = (int) ($analysis['internal_links']['broken_count'] ?? 0);
        $broken_link_penalty = min(20, $broken_link_count * 5);
        $base_score = (int) round(array_sum($scores) / count($scores));
        $final_score = max(0, $base_score - $image_penalty - $broken_link_penalty);

        return [
            'components' => $scores,
            'base_score' => $base_score,
            'image_critical_count' => $image_critical_count,
            'image_penalty' => $image_penalty,
            'broken_link_count' => $broken_link_count,
            'broken_link_penalty' => $broken_link_penalty,
            'final_score' => $final_score,
            'summary' => sprintf(
                'Base %d from headings/accessibility/semantics/clickability, minus %d for critical image issues and %d for broken links.',
                $base_score,
                $image_penalty,
                $broken_link_penalty
            ),
        ];
    }

    private function count_critical_issues($analysis) {
        $count = 0;

        foreach ($analysis['images']['issues'] ?? [] as $issue) {
            if (($issue['severity'] ?? '') === 'critical') {
                $count++;
            }
        }

        foreach ($analysis['headings']['structure_errors'] ?? [] as $error) {
            $count++;
        }

        foreach ($analysis['accessibility']['violations'] ?? [] as $violation) {
            if (($violation['severity'] ?? '') === 'critical') {
                $count++;
            }
        }

        $count += (int) ($analysis['internal_links']['broken_count'] ?? 0);

        return $count;
    }

    private function save_analysis_history($analysis) {
        $history = get_option('seopc_analysis_history', []);

        array_unshift($history, [
            'post_id' => $analysis['post_id'],
            'post_title' => $analysis['post_title'],
            'post_type' => $analysis['post_type'] ?? '',
            'url' => $analysis['url'] ?? '',
            'slug' => get_post_field('post_name', $analysis['post_id']),
            'overall_score' => $analysis['overall_score'],
            'critical_issues' => $analysis['critical_issues'],
            'timestamp' => $analysis['timestamp'],
            'images' => $analysis['images'] ?? [],
            'headings' => $analysis['headings'] ?? [],
            'accessibility' => $analysis['accessibility'] ?? [],
            'html_semantics' => $analysis['html_semantics'] ?? [],
            'clickability' => $analysis['clickability'] ?? [],
            'internal_links' => $analysis['internal_links'] ?? []
        ]);

        $history = array_slice($history, 0, 50);

        update_option('seopc_analysis_history', $history);
    }
}
