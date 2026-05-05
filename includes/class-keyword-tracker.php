<?php

class SEOPC_Keyword_Tracker {

    const META_KEY = '_seopc_target_keywords';

    public function __construct() {
        add_action('add_meta_boxes', [$this, 'register_meta_box']);
        add_action('save_post', [$this, 'save_keywords']);
    }

    public function register_meta_box() {
        $post_types = get_post_types(['public' => true], 'names');
        unset($post_types['attachment']);
        foreach ($post_types as $post_type) {
            add_meta_box(
                'seopc-keyword-tracker',
                __('SEO Performance: Target Keywords', 'seo-performance-checker'),
                [$this, 'render_meta_box'],
                $post_type,
                'side',
                'default'
            );
        }
    }

    public function render_meta_box($post) {
        wp_nonce_field('seopc_save_keywords', 'seopc_keywords_nonce');
        $keywords = self::get_keywords($post->ID);
        echo '<p>' . esc_html__('Add one keyword per line or separate with commas.', 'seo-performance-checker') . '</p>';
        echo '<textarea name="seopc_target_keywords" rows="6" style="width:100%;">' . esc_textarea(implode("\n", $keywords)) . '</textarea>';
    }

    public function save_keywords($post_id) {
        if (!isset($_POST['seopc_keywords_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['seopc_keywords_nonce'])), 'seopc_save_keywords')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        $raw = sanitize_textarea_field(wp_unslash($_POST['seopc_target_keywords'] ?? ''));
        $keywords = self::normalize_keywords($raw);
        if (empty($keywords)) {
            delete_post_meta($post_id, self::META_KEY);
            return;
        }
        update_post_meta($post_id, self::META_KEY, $keywords);
    }

    public static function get_keywords($post_id) {
        $keywords = get_post_meta($post_id, self::META_KEY, true);
        if (!is_array($keywords)) {
            return [];
        }
        return array_values(array_filter(array_map('sanitize_text_field', $keywords)));
    }

    public static function normalize_keywords($raw) {
        $parts = preg_split('/[\r\n,]+/', (string) $raw);
        $keywords = [];
        foreach ($parts as $part) {
            $keyword = sanitize_text_field(trim($part));
            if ($keyword !== '') {
                $keywords[] = $keyword;
            }
        }
        return array_values(array_unique($keywords));
    }

    public static function get_tracked_posts($limit = 100) {
        $query = new WP_Query([
            'post_type' => get_post_types(['public' => true], 'names'),
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'meta_query' => [[
                'key' => self::META_KEY,
                'compare' => 'EXISTS',
            ]],
            'fields' => 'ids',
        ]);
        return array_map('intval', $query->posts ?: []);
    }
}
