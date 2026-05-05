<?php

class SEOPC_Meta_Analyzer {

    public function analyze_all_meta($post_id = null) {
        $url = $post_id ? get_permalink($post_id) : home_url();
        $html = $this->fetch_html($url);

        $results = [
            'url' => $url,
            'basic_meta' => $this->analyze_basic_meta($html, $post_id),
            'open_graph' => $this->analyze_open_graph($html),
            'twitter_cards' => $this->analyze_twitter_cards($html),
            'technical_meta' => $this->analyze_technical_meta($html),
            'canonical' => $this->analyze_canonical($html, $url),
            'robots' => $this->analyze_robots_meta($html),
            'schema_org' => $this->analyze_schema_org($html, $url),
            'seo_checks' => [],
            'score' => 0
        ];

        $results['seo_checks'] = $this->build_seo_checks($results);
        $results['score'] = $this->calculate_meta_score($results['seo_checks']);

        return $results;
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
        $title = isset($matches[1]) ? trim(wp_strip_all_tags($matches[1])) : '';
        $length = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);

        $issues = [];
        if ($length === 0) {
            $issues[] = ['type' => 'missing', 'severity' => 'critical', 'message' => 'Title tag is missing'];
        } elseif ($length < 30) {
            $issues[] = ['type' => 'too_short', 'message' => 'Title is shorter than the usual SEO range'];
        } elseif ($length > 60) {
            $issues[] = ['type' => 'too_long', 'message' => 'Title may be truncated in search results'];
        }

        return [
            'content' => $title,
            'length' => $length,
            'status' => empty($issues) ? 'good' : (($issues[0]['severity'] ?? '') === 'critical' ? 'critical' : 'warning'),
            'issues' => $issues
        ];
    }

    private function analyze_meta_description($html, $post_id) {
        preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches);
        $description = isset($matches[1]) ? trim($matches[1]) : '';
        $length = function_exists('mb_strlen') ? mb_strlen($description) : strlen($description);

        $issues = [];
        if (empty($description)) {
            $issues[] = ['type' => 'missing', 'severity' => 'critical', 'message' => 'Meta description is missing'];
        } elseif ($length < 120) {
            $issues[] = ['type' => 'too_short', 'message' => 'Description is shorter than the usual SEO range'];
        } elseif ($length > 160) {
            $issues[] = ['type' => 'too_long', 'message' => 'Description may be truncated in search results'];
        }

        return [
            'content' => $description,
            'length' => $length,
            'status' => empty($issues) ? 'good' : (($issues[0]['severity'] ?? '') === 'critical' ? 'critical' : 'warning'),
            'issues' => $issues
        ];
    }

    private function analyze_open_graph($html) {
        $og_tags = ['og:title', 'og:description', 'og:image', 'og:url', 'og:type', 'og:site_name'];
        $present = [];
        $missing = [];

        foreach ($og_tags as $tag) {
            $pattern = '/<meta[^>]+property=["\']' . preg_quote($tag, '/') . '["\'][^>]+content=["\']([^"\']*)["\']/i';
            if (preg_match($pattern, $html, $m)) {
                $present[$tag] = trim($m[1]);
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
            $pattern = '/<meta[^>]+name=["\']' . preg_quote($tag, '/') . '["\'][^>]+content=["\']([^"\']*)["\']/i';
            if (preg_match($pattern, $html, $m)) {
                $present[$tag] = trim($m[1]);
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
        $viewport = (bool) preg_match('/<meta[^>]+name=["\']viewport["\']/', $html);
        $charset = (bool) preg_match('/<meta[^>]+charset=/i', $html);

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
                'followable' => true,
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

    private function analyze_schema_org($html, $url) {
        $items = [];
        $issues = [];

        if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            foreach ($matches[1] as $index => $raw_json) {
                $raw_json = trim(html_entity_decode($raw_json));
                if ($raw_json === '') {
                    continue;
                }

                $decoded = json_decode($raw_json, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $issues[] = [
                        'type' => 'invalid_json_ld',
                        'message' => json_last_error_msg(),
                        'block' => $index + 1
                    ];
                    continue;
                }

                foreach ($this->flatten_schema_nodes($decoded) as $node) {
                    $type = $node['@type'] ?? 'Unknown';
                    if (is_array($type)) {
                        $type = implode(', ', array_filter(array_map('strval', $type)));
                    }

                    $properties = array_keys(array_filter($node, function($value, $key) {
                        return strpos((string) $key, '@') !== 0;
                    }, ARRAY_FILTER_USE_BOTH));

                    $items[] = [
                        'format' => 'json-ld',
                        'type' => $type,
                        'properties' => array_values($properties),
                        'property_count' => count($properties),
                        'has_required_name' => array_key_exists('name', $node),
                        'has_required_url' => array_key_exists('url', $node),
                        'raw_preview' => substr(wp_json_encode($node), 0, 240)
                    ];
                }
            }
        }

        if (preg_match_all('/<[^>]+itemtype=["\']https?:\/\/schema\.org\/([^"\']+)["\'][^>]*>/i', $html, $micro_matches)) {
            foreach ($micro_matches[1] as $micro_type) {
                $items[] = [
                    'format' => 'microdata',
                    'type' => trim($micro_type),
                    'properties' => [],
                    'property_count' => 0,
                    'has_required_name' => null,
                    'has_required_url' => null,
                    'raw_preview' => ''
                ];
            }
        }

        return [
            'present' => !empty($items),
            'items' => $items,
            'count' => count($items),
            'issues' => $issues,
            'validator_url' => 'https://validator.schema.org/#url=' . rawurlencode($url)
        ];
    }

    private function flatten_schema_nodes($decoded) {
        $nodes = [];

        if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
            foreach ($decoded['@graph'] as $node) {
                if (is_array($node)) {
                    $nodes[] = $node;
                }
            }
            return $nodes;
        }

        if (is_array($decoded) && array_keys($decoded) === range(0, count($decoded) - 1)) {
            foreach ($decoded as $node) {
                if (is_array($node)) {
                    $nodes[] = $node;
                }
            }
            return $nodes;
        }

        return [is_array($decoded) ? $decoded : []];
    }

    private function build_seo_checks($results) {
        return [
            ['label' => 'Title tag', 'status' => $results['basic_meta']['title']['status'] === 'good' ? 'pass' : 'fail'],
            ['label' => 'Meta description', 'status' => $results['basic_meta']['description']['status'] === 'good' ? 'pass' : 'fail'],
            ['label' => 'Canonical URL', 'status' => !empty($results['canonical']['present']) && !empty($results['canonical']['correct']) ? 'pass' : 'fail'],
            ['label' => 'Robots index/follow', 'status' => ($results['robots']['indexable'] ?? false) && ($results['robots']['followable'] ?? false) ? 'pass' : 'fail'],
            ['label' => 'Viewport + charset', 'status' => $results['technical_meta']['status'] === 'good' ? 'pass' : 'fail'],
            ['label' => 'Open Graph tags', 'status' => !empty($results['open_graph']['complete']) ? 'pass' : 'fail'],
            ['label' => 'Twitter card tags', 'status' => empty($results['twitter_cards']['missing']) ? 'pass' : 'fail'],
            ['label' => 'Schema.org markup', 'status' => !empty($results['schema_org']['present']) && empty($results['schema_org']['issues']) ? 'pass' : 'fail'],
        ];
    }

    private function calculate_meta_score($checks) {
        if (empty($checks)) {
            return 0;
        }

        $passed = count(array_filter($checks, function($check) {
            return ($check['status'] ?? '') === 'pass';
        }));

        return (int) round(($passed / count($checks)) * 100);
    }
}
