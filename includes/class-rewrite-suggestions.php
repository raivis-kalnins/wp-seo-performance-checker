<?php

class SEOPC_Rewrite_Suggestions {

    public function build_for_post($post_id, $context = []) {
        $title = wp_strip_all_tags(get_the_title($post_id));
        $keywords = array_values(array_filter((array) ($context['keywords'] ?? [])));
        $primary_keyword = (string) ($context['primary_keyword'] ?? ($keywords[0] ?? ''));
        $search = (array) ($context['search'] ?? []);
        $content_score = (array) ($context['content_score'] ?? []);
        $competitor = (array) ($context['competitor'] ?? []);
        $meta_description = $this->get_meta_description($post_id);
        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

        $title_basis = $primary_keyword ?: $title;
        $title_templates = [
            $this->trim_text($title_basis . ' | ' . $site_name, 60),
            $this->trim_text($title_basis . ': Practical Guide & Best Practices', 60),
            $this->trim_text('Best ' . $title_basis . ' Tips for Better Results', 60),
        ];

        $low_ctr = (float) ($search['ctr']['value'] ?? 0) < 0.03 && (float) ($search['impressions']['value'] ?? 0) > 100;
        if ($low_ctr) {
            array_unshift($title_templates, $this->trim_text($title_basis . ' - Improve Results Faster', 60));
        }

        $description_templates = [
            $this->trim_text($this->first_sentence($meta_description) ?: ('Learn how to improve ' . strtolower($title_basis) . ' with practical steps, examples, and a clearer action plan.'), 155),
            $this->trim_text(($primary_keyword ? $primary_keyword . ': ' : '') . 'See the key steps, common mistakes, and opportunities to improve performance and conversions.', 155),
            $this->trim_text('Discover actionable ways to strengthen this page with better structure, richer content, and clearer next steps for visitors.', 155),
        ];

        if (!empty($competitor['suggestions'])) {
            $description_templates[] = $this->trim_text('Compare this page with competing results and use the insights to improve headings, content depth, schema, and click-through appeal.', 155);
        }

        return [
            'recommended_title' => $title_templates[0] ?? '',
            'alternative_titles' => array_values(array_unique(array_filter($title_templates))),
            'recommended_description' => $description_templates[0] ?? '',
            'alternative_descriptions' => array_values(array_unique(array_filter($description_templates))),
            'why' => $this->build_reasoning($content_score, $search, $competitor, $primary_keyword),
        ];
    }

    private function build_reasoning($content_score, $search, $competitor, $primary_keyword) {
        $reasons = [];
        if ($primary_keyword) {
            $reasons[] = sprintf(__('Suggestions are centered on the primary keyword: %s.', 'seo-performance-checker'), $primary_keyword);
        }
        if ((float) ($search['impressions']['value'] ?? 0) > 100 && (float) ($search['ctr']['value'] ?? 0) < 0.03) {
            $reasons[] = __('Search Console shows impressions with relatively weak CTR, so the rewrite options push for clearer click value.', 'seo-performance-checker');
        }
        if ((int) ($content_score['score'] ?? 0) < 80) {
            $reasons[] = __('The current content score suggests there is room to align the title and description more closely with the page topic.', 'seo-performance-checker');
        }
        if (!empty($competitor['suggestions'])) {
            $reasons[] = __('Competitor benchmarking indicates opportunities to sharpen message clarity and differentiation in SERP snippets.', 'seo-performance-checker');
        }
        return $reasons;
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

    private function trim_text($text, $max) {
        $text = trim(preg_replace('/\s+/', ' ', (string) $text));
        $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($len <= $max) {
            return $text;
        }
        return rtrim(function_exists('mb_substr') ? mb_substr($text, 0, $max - 1) : substr($text, 0, $max - 1), " ,.-") . '…';
    }

    private function first_sentence($text) {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }
        if (preg_match('/^(.+?[.!?])\s/u', $text, $m)) {
            return $m[1];
        }
        return $text;
    }
}
