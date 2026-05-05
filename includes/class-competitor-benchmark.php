<?php

class SEOPC_Competitor_Benchmark {

    const META_KEY = '_seopc_competitor_urls';

    public function __construct() {
        add_action('add_meta_boxes', [$this, 'register_meta_box']);
        add_action('save_post', [$this, 'save_urls']);
    }

    public function register_meta_box() {
        $post_types = get_post_types(['public' => true], 'names');
        unset($post_types['attachment']);
        foreach ($post_types as $post_type) {
            add_meta_box(
                'seopc-competitor-benchmark',
                __('SEO Performance: Competitor URLs', 'seo-performance-checker'),
                [$this, 'render_meta_box'],
                $post_type,
                'side',
                'default'
            );
        }
    }

    public function render_meta_box($post) {
        wp_nonce_field('seopc_save_competitor_urls', 'seopc_competitor_urls_nonce');
        $urls = self::get_urls($post->ID);
        echo '<p>' . esc_html__('Add one competing page URL per line. These are used for a lightweight on-page benchmark.', 'seo-performance-checker') . '</p>';
        echo '<textarea name="seopc_competitor_urls" rows="5" style="width:100%;">' . esc_textarea(implode("\n", $urls)) . '</textarea>';
    }

    public function save_urls($post_id) {
        if (!isset($_POST['seopc_competitor_urls_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['seopc_competitor_urls_nonce'])), 'seopc_save_competitor_urls')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $raw = sanitize_textarea_field(wp_unslash($_POST['seopc_competitor_urls'] ?? ''));
        $urls = self::normalize_urls($raw);
        if (empty($urls)) {
            delete_post_meta($post_id, self::META_KEY);
            return;
        }
        update_post_meta($post_id, self::META_KEY, $urls);
    }

    public static function get_urls($post_id) {
        $urls = get_post_meta($post_id, self::META_KEY, true);
        if (!is_array($urls)) {
            return [];
        }
        return array_values(array_filter(array_map('esc_url_raw', $urls)));
    }

    public static function normalize_urls($raw) {
        $parts = preg_split('/[\r\n,]+/', (string) $raw);
        $urls = [];
        foreach ($parts as $part) {
            $url = esc_url_raw(trim($part));
            if ($url && wp_http_validate_url($url)) {
                $urls[] = $url;
            }
        }
        return array_values(array_unique($urls));
    }

    public function analyze_post($post_id, $limit = 3) {
        $post = get_post($post_id);
        if (!$post) {
            return ['current' => [], 'competitors' => [], 'summary' => [], 'suggestions' => []];
        }

        $current_url = get_permalink($post_id);
        $current = $this->build_local_post_stats($post_id, $current_url);
        $competitor_urls = array_slice(self::get_urls($post_id), 0, max(1, (int) $limit));
        $competitors = [];
        foreach ($competitor_urls as $url) {
            $stats = $this->fetch_remote_stats($url);
            if (!empty($stats)) {
                $competitors[] = $stats;
            }
        }

        return [
            'current' => $current,
            'competitors' => $competitors,
            'summary' => $this->build_summary($current, $competitors),
            'suggestions' => $this->build_suggestions($current, $competitors),
        ];
    }

    private function build_local_post_stats($post_id, $url) {
        $post = get_post($post_id);
        $content = (string) ($post->post_content ?? '');
        $title = wp_strip_all_tags(get_the_title($post_id));
        $meta_description = $this->get_meta_description($post_id);
        return [
            'label' => __('This page', 'seo-performance-checker'),
            'url' => $url,
            'title' => $title,
            'title_length' => function_exists('mb_strlen') ? mb_strlen($title) : strlen($title),
            'meta_description' => $meta_description,
            'meta_description_length' => function_exists('mb_strlen') ? mb_strlen($meta_description) : strlen($meta_description),
            'word_count' => str_word_count(wp_strip_all_tags(html_entity_decode($content))),
            'heading_count' => preg_match_all('/<h[1-6][^>]*>/i', $content, $m),
            'image_count' => preg_match_all('/<img[^>]*>/i', $content, $m2),
            'schema_count' => substr_count($content, 'application/ld+json'),
            'fetch_error' => '',
        ];
    }

    private function fetch_remote_stats($url) {
        $response = wp_remote_get($url, [
            'timeout' => 12,
            'redirection' => 5,
            'user-agent' => 'Mozilla/5.0 (compatible; SEOPC/1.7.0; +' . home_url('/') . ')',
        ]);
        if (is_wp_error($response)) {
            return [
                'label' => wp_parse_url($url, PHP_URL_HOST) ?: $url,
                'url' => $url,
                'fetch_error' => $response->get_error_message(),
            ];
        }
        $html = (string) wp_remote_retrieve_body($response);
        if ($html === '') {
            return [
                'label' => wp_parse_url($url, PHP_URL_HOST) ?: $url,
                'url' => $url,
                'fetch_error' => __('Empty response body.', 'seo-performance-checker'),
            ];
        }

        $title = $this->match('/<title[^>]*>(.*?)<\/title>/is', $html);
        $description = $this->extract_meta($html, 'description');
        return [
            'label' => $this->truncate($title ?: (wp_parse_url($url, PHP_URL_HOST) ?: $url), 60),
            'url' => $url,
            'title' => $title,
            'title_length' => function_exists('mb_strlen') ? mb_strlen($title) : strlen($title),
            'meta_description' => $description,
            'meta_description_length' => function_exists('mb_strlen') ? mb_strlen($description) : strlen($description),
            'word_count' => str_word_count(wp_strip_all_tags(html_entity_decode($html))),
            'heading_count' => preg_match_all('/<h[1-6][^>]*>/i', $html, $m),
            'image_count' => preg_match_all('/<img[^>]*>/i', $html, $m2),
            'schema_count' => substr_count($html, 'application/ld+json'),
            'fetch_error' => '',
        ];
    }

    private function build_summary($current, $competitors) {
        if (empty($competitors)) {
            return [];
        }
        $fields = ['word_count', 'heading_count', 'image_count', 'schema_count', 'title_length', 'meta_description_length'];
        $summary = [];
        foreach ($fields as $field) {
            $values = [];
            foreach ($competitors as $competitor) {
                if (isset($competitor[$field]) && empty($competitor['fetch_error'])) {
                    $values[] = (float) $competitor[$field];
                }
            }
            if (!$values) {
                continue;
            }
            $summary[$field] = [
                'current' => (float) ($current[$field] ?? 0),
                'competitor_average' => round(array_sum($values) / count($values), 1),
                'gap' => round((float) ($current[$field] ?? 0) - (array_sum($values) / count($values)), 1),
            ];
        }
        return $summary;
    }

    private function build_suggestions($current, $competitors) {
        $summary = $this->build_summary($current, $competitors);
        $suggestions = [];
        if (!empty($summary['word_count']) && $summary['word_count']['current'] + 150 < $summary['word_count']['competitor_average']) {
            $suggestions[] = __('Competitors are materially longer on average. Consider expanding the page with more useful detail, FAQs, or examples.', 'seo-performance-checker');
        }
        if (!empty($summary['heading_count']) && $summary['heading_count']['current'] + 2 < $summary['heading_count']['competitor_average']) {
            $suggestions[] = __('Competitors use more heading structure. Add clearer H2/H3 sections to improve scannability.', 'seo-performance-checker');
        }
        if (!empty($summary['image_count']) && $summary['image_count']['current'] + 1 < $summary['image_count']['competitor_average']) {
            $suggestions[] = __('Competitors use more supporting visuals. Consider adding screenshots, diagrams, or examples.', 'seo-performance-checker');
        }
        if (!empty($summary['schema_count']) && $summary['schema_count']['current'] < $summary['schema_count']['competitor_average']) {
            $suggestions[] = __('Competitors appear to expose more structured data. Review whether FAQ, Article, Product, or Organization schema could strengthen this page.', 'seo-performance-checker');
        }
        if (!empty($summary['title_length']) && $summary['title_length']['current'] < 45) {
            $suggestions[] = __('Your title is shorter than most competitors. Consider a fuller title with a stronger benefit and keyword match.', 'seo-performance-checker');
        }
        if (!empty($summary['meta_description_length']) && $summary['meta_description_length']['current'] < 120) {
            $suggestions[] = __('Your meta description is short relative to competitor pages. Add a clearer value proposition and call to action.', 'seo-performance-checker');
        }
        return array_values(array_unique($suggestions));
    }

    private function get_meta_description($post_id) {
        $keys = ['_yoast_wpseo_metadesc', '_rank_math_description', '_aioseo_description'];
        foreach ($keys as $key) {
            $value = trim((string) get_post_meta($post_id, $key, true));
            if ($value !== '') {
                return $value;
            }
        }
        return trim((string) get_post_field('post_excerpt', $post_id));
    }

    private function extract_meta($html, $name) {
        if (preg_match('/<meta[^>]+name=["\']' . preg_quote($name, '/') . '["\'][^>]+content=["\']([^"\']*)["\']/i', $html, $m)) {
            return trim(wp_strip_all_tags(html_entity_decode($m[1])));
        }
        if (preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']' . preg_quote($name, '/') . '["\']/i', $html, $m)) {
            return trim(wp_strip_all_tags(html_entity_decode($m[1])));
        }
        return '';
    }

    private function match($pattern, $html) {
        return preg_match($pattern, $html, $m) ? trim(wp_strip_all_tags(html_entity_decode($m[1]))) : '';
    }

    private function truncate($text, $max) {
        if ((function_exists('mb_strlen') ? mb_strlen($text) : strlen($text)) <= $max) {
            return $text;
        }
        return (function_exists('mb_substr') ? mb_substr($text, 0, $max - 1) : substr($text, 0, $max - 1)) . '…';
    }
}
