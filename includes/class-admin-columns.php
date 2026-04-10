<?php

class SEOPC_Admin_Columns {

    private $history_index = null;

    public function __construct() {
        add_action('admin_init', [$this, 'register_hooks']);
        add_action('admin_head-edit.php', [$this, 'print_admin_list_styles']);
        add_action('add_meta_boxes', [$this, 'register_sidebar_metabox']);
    }

    public function register_hooks() {
        $post_types = get_post_types(['public' => true], 'names');

        foreach ($post_types as $post_type) {
            if ($post_type === 'attachment') {
                continue;
            }

            add_filter("manage_{$post_type}_posts_columns", [$this, 'add_score_column']);
            add_action("manage_{$post_type}_posts_custom_column", [$this, 'render_score_column'], 10, 2);
            add_filter("manage_edit-{$post_type}_sortable_columns", [$this, 'make_score_column_sortable']);
        }

        add_action('pre_get_posts', [$this, 'handle_score_sorting']);
    }


    public function register_sidebar_metabox() {
        $post_types = get_post_types(['public' => true], 'names');

        foreach ($post_types as $post_type) {
            if ($post_type === 'attachment') {
                continue;
            }

            add_meta_box(
                'seopc-single-results',
                __('SEO Results', 'seo-performance-checker'),
                [$this, 'render_sidebar_metabox'],
                $post_type,
                'side',
                'high'
            );
        }
    }

    public function render_sidebar_metabox($post) {
        $entry = $this->get_score_entry($post->ID);
        $edit_link = admin_url('admin.php?page=seo-performance');

        echo '<div class="seopc-single-box" data-post-id="' . esc_attr($post->ID) . '">';

        if (empty($entry)) {
            echo '<p>' . esc_html__('No saved SEO results yet for this item.', 'seo-performance-checker') . '</p>';
        } else {
            $score = isset($entry['overall_score']) ? (int) $entry['overall_score'] : 0;
            $critical_issues = isset($entry['critical_issues']) ? (int) $entry['critical_issues'] : 0;
            $score_class = $score >= 80 ? 'good' : ($score >= 60 ? 'warning' : 'critical');
            $requests = isset($entry['requests_count']) ? (int) $entry['requests_count'] : null;
            $link_data = $entry['internal_links'] ?? [];
            $broken_links = isset($link_data['broken_count']) ? (int) $link_data['broken_count'] : null;
            $external_links = isset($link_data['external']) ? (int) $link_data['external'] : null;

            echo '<p><span class="seopc-score-badge ' . esc_attr($score_class) . '">' . esc_html($score) . '/100</span></p>';
            echo '<p><strong>' . esc_html__('Critical issues:', 'seo-performance-checker') . '</strong> ' . esc_html($critical_issues) . '</p>';
            if ($requests !== null) {
                echo '<p><strong>' . esc_html__('Requests:', 'seo-performance-checker') . '</strong> ' . esc_html($requests) . '</p>';
            }
            if ($broken_links !== null) {
                echo '<p><strong>' . esc_html__('Broken links:', 'seo-performance-checker') . '</strong> ' . esc_html($broken_links) . '</p>';
            }
            if ($external_links !== null) {
                echo '<p><strong>' . esc_html__('External links:', 'seo-performance-checker') . '</strong> ' . esc_html($external_links) . '</p>';
            }
            if (!empty($entry['timestamp'])) {
                echo '<p><strong>' . esc_html__('Last checked:', 'seo-performance-checker') . '</strong> ' . esc_html(human_time_diff(strtotime($entry['timestamp']), current_time('timestamp'))) . ' ' . esc_html__('ago', 'seo-performance-checker') . '</p>';
            }
            echo '<button type="button" class="button button-secondary seopc-view-results-btn" data-post-id="' . esc_attr($post->ID) . '" style="width:100%;margin-bottom:8px;" title="' . esc_attr__('View saved results again', 'seo-performance-checker') . '">' . esc_html__('View Results', 'seo-performance-checker') . '</button>';
        }

        echo '<button type="button" class="button button-primary seopc-test-again-btn" data-post-id="' . esc_attr($post->ID) . '" style="width:100%;">' . esc_html__('Test Again', 'seo-performance-checker') . '</button>';
        echo '<div class="seopc-single-results-output" style="margin-top:12px;"></div>';
        echo '<p style="margin-top:12px;"><a href="' . esc_url($edit_link) . '">' . esc_html__('Open SEO Dashboard', 'seo-performance-checker') . '</a></p>';
        echo '</div>';
    }

    public function add_score_column($columns) {
        $new_columns = [];

        foreach ($columns as $key => $label) {
            $new_columns[$key] = $label;

            if ($key === 'title') {
                $new_columns['seopc_score'] = __('SEO Score', 'seo-performance-checker');
            }
        }

        if (!isset($new_columns['seopc_score'])) {
            $new_columns['seopc_score'] = __('SEO Score', 'seo-performance-checker');
        }

        return $new_columns;
    }

    public function render_score_column($column, $post_id) {
        if ($column !== 'seopc_score') {
            return;
        }

        $entry = $this->get_score_entry($post_id);

        if (empty($entry)) {
            echo '<span class="seopc-score-empty">' . esc_html__('Not analyzed yet', 'seo-performance-checker') . '</span>';
            return;
        }

        $score = isset($entry['overall_score']) ? (int) $entry['overall_score'] : 0;
        $critical_issues = isset($entry['critical_issues']) ? (int) $entry['critical_issues'] : 0;
        $score_class = $score >= 80 ? 'good' : ($score >= 60 ? 'warning' : 'critical');

        echo '<span class="seopc-score-badge ' . esc_attr($score_class) . '">' . esc_html($score) . '/100</span>';

        if ($critical_issues > 0) {
            echo '<div class="seopc-score-meta">' . sprintf(
                esc_html(_n('%d critical issue', '%d critical issues', $critical_issues, 'seo-performance-checker')),
                $critical_issues
            ) . '</div>';
        } else {
            echo '<div class="seopc-score-meta seopc-score-meta-good">' . esc_html__('No critical issues', 'seo-performance-checker') . '</div>';
        }
    }

    public function make_score_column_sortable($columns) {
        $columns['seopc_score'] = 'seopc_score';
        return $columns;
    }

    public function handle_score_sorting($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        $orderby = $query->get('orderby');
        if ($orderby !== 'seopc_score') {
            return;
        }

        $post_type = $query->get('post_type');
        if (empty($post_type)) {
            $post_type = 'post';
        }

        $post_ids = $this->get_sorted_post_ids((array) $post_type, $query->get('order'));

        if (empty($post_ids)) {
            $query->set('post__in', [0]);
            return;
        }

        $query->set('post__in', $post_ids);
        $query->set('orderby', 'post__in');
    }

    public function print_admin_list_styles() {
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'edit') {
            return;
        }

        echo '<style>
            .column-seopc_score { width: 140px; }
            .seopc-score-empty { color: #646970; }
            .seopc-score-meta { margin-top: 6px; color: #646970; font-size: 12px; line-height: 1.35; }
            .seopc-score-meta-good { color: #0a5c0a; }
            .seopc-score-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 999px;
                font-size: 11px;
                font-weight: 600;
            }
            .seopc-score-badge.good { background: #d1f7d1; color: #0a5c0a; }
            .seopc-score-badge.warning { background: #fcf9e8; color: #946f00; }
            .seopc-score-badge.critical { background: #f7d1d1; color: #8a2424; }
        </style>';
    }

    private function get_score_entry($post_id) {
        $history = $this->get_history_index();
        return $history[$post_id] ?? null;
    }

    private function get_history_index() {
        if ($this->history_index !== null) {
            return $this->history_index;
        }

        $history = get_option('seopc_analysis_history', []);
        $index = [];

        foreach ($history as $entry) {
            $entry_post_id = isset($entry['post_id']) ? (int) $entry['post_id'] : 0;
            if (!$entry_post_id || isset($index[$entry_post_id])) {
                continue;
            }

            $index[$entry_post_id] = $entry;
        }

        $this->history_index = $index;
        return $this->history_index;
    }

    private function get_sorted_post_ids($post_types, $order = 'desc') {
        global $wpdb;

        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        $history = $this->get_history_index();
        $score_map = [];

        foreach ($history as $post_id => $entry) {
            $score_map[(int) $post_id] = isset($entry['overall_score']) ? (int) $entry['overall_score'] : -1;
        }

        $placeholders = implode(', ', array_fill(0, count($post_types), '%s'));
        $sql = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($placeholders) AND post_status NOT IN ('trash', 'auto-draft')",
            ...$post_types
        );
        $post_ids = $wpdb->get_col($sql);

        usort($post_ids, function ($a, $b) use ($score_map, $order) {
            $score_a = $score_map[(int) $a] ?? -1;
            $score_b = $score_map[(int) $b] ?? -1;

            if ($score_a === $score_b) {
                return (int) $a <=> (int) $b;
            }

            if ($order === 'ASC') {
                return $score_a <=> $score_b;
            }

            return $score_b <=> $score_a;
        });

        return array_map('intval', $post_ids);
    }
}
