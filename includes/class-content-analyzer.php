<?php

class SEOPC_Content_Analyzer {

    public function analyze_post($post_id, $keywords = []) {
        $post = get_post($post_id);
        if (!$post) {
            return ['score' => 0, 'checks' => [], 'suggestions' => []];
        }

        $title = wp_strip_all_tags(get_the_title($post_id));
        $content = (string) $post->post_content;
        $plain = trim(wp_strip_all_tags($content));
        $word_count = max(0, str_word_count(wp_strip_all_tags(html_entity_decode($plain, ENT_QUOTES))));
        $primary_keyword = $keywords[0] ?? '';
        $checklist = [];

        $title_has_keyword = $primary_keyword ? (stripos($title, $primary_keyword) !== false) : false;
        $checklist[] = $this->make_check('keyword_title', __('Keyword in title', 'seo-performance-checker'), $title_has_keyword, 15, $primary_keyword ? '' : __('Add a target keyword first.', 'seo-performance-checker'));

        $heading_has_keyword = $primary_keyword ? $this->content_has_keyword_in_heading($content, $primary_keyword) : false;
        $checklist[] = $this->make_check('keyword_heading', __('Keyword in heading', 'seo-performance-checker'), $heading_has_keyword, 15);

        $density = $primary_keyword ? $this->calculate_keyword_density($plain, $primary_keyword) : 0;
        $density_ok = $primary_keyword ? ($density >= 0.3 && $density <= 2.5) : false;
        $checklist[] = $this->make_check('keyword_density', __('Keyword density', 'seo-performance-checker'), $density_ok, 10, sprintf(__('Current density: %s%%', 'seo-performance-checker'), round($density, 2)));

        $meta_description = $this->get_meta_description($post_id);
        $meta_has_keyword = $primary_keyword && $meta_description ? (stripos($meta_description, $primary_keyword) !== false) : false;
        $checklist[] = $this->make_check('meta_description', __('Keyword in meta description', 'seo-performance-checker'), $meta_has_keyword, 15, $meta_description ? '' : __('No meta description found in common SEO fields.', 'seo-performance-checker'));

        $internal_links = $this->count_internal_links($content);
        $checklist[] = $this->make_check('internal_links', __('Internal links', 'seo-performance-checker'), $internal_links >= 2, 10, sprintf(__('Found %d internal links.', 'seo-performance-checker'), $internal_links));

        $image_stats = $this->extract_image_stats($content);
        $checklist[] = $this->make_check('image_alts', __('Image alt coverage', 'seo-performance-checker'), ($image_stats['images'] === 0 || $image_stats['missing_alt'] === 0), 10, sprintf(__('Images: %1$d, missing alt: %2$d', 'seo-performance-checker'), $image_stats['images'], $image_stats['missing_alt']));

        $checklist[] = $this->make_check('content_length', __('Content length', 'seo-performance-checker'), $word_count >= 300, 10, sprintf(__('Word count: %d', 'seo-performance-checker'), $word_count));

        $slug = (string) get_post_field('post_name', $post_id);
        $slug_has_keyword = $primary_keyword ? (stripos(str_replace('-', ' ', $slug), $primary_keyword) !== false) : false;
        $checklist[] = $this->make_check('keyword_slug', __('Keyword in URL slug', 'seo-performance-checker'), $slug_has_keyword, 5);

        $score = 0;
        foreach ($checklist as $check) {
            if (!empty($check['passed'])) {
                $score += (int) $check['weight'];
            }
        }
        $score = min(100, $score);

        return [
            'score' => $score,
            'word_count' => $word_count,
            'primary_keyword' => $primary_keyword,
            'keyword_density' => $density,
            'internal_links' => $internal_links,
            'image_stats' => $image_stats,
            'checks' => $checklist,
            'suggestions' => $this->build_suggestions($checklist, $primary_keyword),
        ];
    }

    private function make_check($key, $label, $passed, $weight, $detail = '') {
        return [
            'key' => $key,
            'label' => $label,
            'passed' => (bool) $passed,
            'weight' => (int) $weight,
            'detail' => (string) $detail,
        ];
    }

    private function content_has_keyword_in_heading($html, $keyword) {
        if (!$keyword || !$html) {
            return false;
        }
        if (preg_match_all('/<h[1-3][^>]*>(.*?)<\/h[1-3]>/is', $html, $matches)) {
            foreach ($matches[1] as $heading) {
                if (stripos(wp_strip_all_tags($heading), $keyword) !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    private function calculate_keyword_density($text, $keyword) {
        $text = strtolower(wp_strip_all_tags($text));
        $keyword = strtolower(trim($keyword));
        if (!$text || !$keyword) {
            return 0;
        }
        $words = preg_split('/\s+/', $text);
        $total_words = count(array_filter($words));
        if ($total_words === 0) {
            return 0;
        }
        preg_match_all('/\b' . preg_quote($keyword, '/') . '\b/i', $text, $matches);
        $count = count($matches[0]);
        return ($count / $total_words) * 100;
    }

    private function get_meta_description($post_id) {
        $keys = ['_yoast_wpseo_metadesc', '_rank_math_description', '_aioseo_description'];
        foreach ($keys as $key) {
            $value = trim((string) get_post_meta($post_id, $key, true));
            if ($value !== '') {
                return $value;
            }
        }
        $excerpt = trim((string) get_post_field('post_excerpt', $post_id));
        return $excerpt;
    }

    private function count_internal_links($html) {
        if (!$html || !preg_match_all('/<a[^>]+href=["\']([^"\']+)["\']/i', $html, $matches)) {
            return 0;
        }
        $count = 0;
        $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
        foreach ($matches[1] as $href) {
            $host = wp_parse_url($href, PHP_URL_HOST);
            if (!$host || $host === $site_host) {
                $count++;
            }
        }
        return $count;
    }

    private function extract_image_stats($html) {
        $stats = ['images' => 0, 'missing_alt' => 0];
        if (!$html || !preg_match_all('/<img[^>]*>/i', $html, $matches)) {
            return $stats;
        }
        $stats['images'] = count($matches[0]);
        foreach ($matches[0] as $img) {
            if (!preg_match('/alt=["\']([^"\']*)["\']/i', $img, $alt_match) || trim($alt_match[1]) === '') {
                $stats['missing_alt']++;
            }
        }
        return $stats;
    }

    private function build_suggestions($checks, $primary_keyword) {
        $suggestions = [];
        foreach ($checks as $check) {
            if (!empty($check['passed'])) {
                continue;
            }
            switch ($check['key']) {
                case 'keyword_title':
                    $suggestions[] = $primary_keyword ? sprintf(__('Add "%s" closer to the start of the SEO title.', 'seo-performance-checker'), $primary_keyword) : __('Add a target keyword to unlock keyword-based recommendations.', 'seo-performance-checker');
                    break;
                case 'keyword_heading':
                    $suggestions[] = __('Add the primary keyword to an H1 or H2 heading.', 'seo-performance-checker');
                    break;
                case 'keyword_density':
                    $suggestions[] = __('Use the primary keyword naturally a little more often in the body copy.', 'seo-performance-checker');
                    break;
                case 'meta_description':
                    $suggestions[] = __('Write a meta description that includes the target keyword and a clearer click incentive.', 'seo-performance-checker');
                    break;
                case 'internal_links':
                    $suggestions[] = __('Add at least two internal links to relevant posts or service pages.', 'seo-performance-checker');
                    break;
                case 'image_alts':
                    $suggestions[] = __('Add descriptive alt text to all content images.', 'seo-performance-checker');
                    break;
                case 'content_length':
                    $suggestions[] = __('Expand the page with more useful detail, examples, or FAQs.', 'seo-performance-checker');
                    break;
                case 'keyword_slug':
                    $suggestions[] = __('Consider a cleaner slug that includes the primary keyword.', 'seo-performance-checker');
                    break;
            }
        }
        return array_values(array_unique($suggestions));
    }
}
