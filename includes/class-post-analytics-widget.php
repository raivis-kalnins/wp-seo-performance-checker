<?php

class SEOPC_Post_Analytics_Widget {

    public function __construct() {
        add_action('add_meta_boxes', [$this, 'register_meta_box']);
    }

    public function register_meta_box() {
        $post_types = get_post_types(['public' => true], 'names');
        unset($post_types['attachment']);
        foreach ($post_types as $post_type) {
            add_meta_box(
                'seopc-post-analytics',
                __('SEO Performance: Insights', 'seo-performance-checker'),
                [$this, 'render_meta_box'],
                $post_type,
                'side',
                'default'
            );
        }
    }

    public function render_meta_box($post) {
        $service = new SEOPC_Google_Integrations();
        $data = $service->get_post_dashboard_data($post->ID, ['preset' => 'last_28_days']);

        $content_score = $data['content_score'] ?? ['score' => 0, 'checks' => [], 'suggestions' => []];
        echo '<div class="seopc-post-widget-group">';
        echo '<h4 style="margin:0 0 8px;">' . esc_html__('Content SEO Score', 'seo-performance-checker') . '</h4>';
        echo '<div class="seopc-inline-score ' . esc_attr($this->score_class($content_score['score'] ?? 0)) . '">' . esc_html((int) ($content_score['score'] ?? 0)) . '/100</div>';
        if (!empty($data['keywords'])) {
            echo '<p><strong>' . esc_html__('Tracked keywords:', 'seo-performance-checker') . '</strong><br>' . esc_html(implode(', ', $data['keywords'])) . '</p>';
        } else {
            echo '<p>' . esc_html__('Add target keywords in the SEO Performance: Target Keywords box.', 'seo-performance-checker') . '</p>';
        }
        echo '</div>';

        if (!empty($content_score['checks'])) {
            echo '<div class="seopc-post-widget-group"><h4 style="margin:12px 0 8px;">' . esc_html__('Checklist', 'seo-performance-checker') . '</h4><ul class="seopc-checklist-mini">';
            foreach (array_slice($content_score['checks'], 0, 8) as $check) {
                $icon = !empty($check['passed']) ? '✅' : '⚠️';
                echo '<li><span>' . esc_html($icon . ' ' . $check['label']) . '</span>';
                if (!empty($check['detail'])) {
                    echo '<small>' . esc_html($check['detail']) . '</small>';
                }
                echo '</li>';
            }
            echo '</ul></div>';
        }

        if (!empty($content_score['suggestions'])) {
            echo '<div class="seopc-post-widget-group"><h4 style="margin:12px 0 8px;">' . esc_html__('Suggestions', 'seo-performance-checker') . '</h4><ul class="seopc-checklist-mini">';
            foreach (array_slice($content_score['suggestions'], 0, 4) as $suggestion) {
                echo '<li><span>' . esc_html($suggestion) . '</span></li>';
            }
            echo '</ul></div>';
        }

        if (!empty($data['rewrite_suggestions'])) {
            $rewrite = $data['rewrite_suggestions'];
            echo '<div class="seopc-post-widget-group"><h4 style="margin:12px 0 8px;">' . esc_html__('SERP rewrite suggestions', 'seo-performance-checker') . '</h4>';
            if (!empty($rewrite['recommended_title'])) {
                echo '<p><strong>' . esc_html__('Suggested title', 'seo-performance-checker') . '</strong><br><span>' . esc_html($rewrite['recommended_title']) . '</span></p>';
            }
            if (!empty($rewrite['recommended_description'])) {
                echo '<p><strong>' . esc_html__('Suggested meta description', 'seo-performance-checker') . '</strong><br><span>' . esc_html($rewrite['recommended_description']) . '</span></p>';
            }
            if (!empty($rewrite['why'])) {
                echo '<ul class="seopc-checklist-mini">';
                foreach (array_slice($rewrite['why'], 0, 3) as $reason) {
                    echo '<li><span>' . esc_html($reason) . '</span></li>';
                }
                echo '</ul>';
            }
            echo '</div>';
        }

        if (!empty($data['competitor_benchmark']['competitors'])) {
            $benchmark = $data['competitor_benchmark'];
            echo '<div class="seopc-post-widget-group"><h4 style="margin:12px 0 8px;">' . esc_html__('Competitor benchmark', 'seo-performance-checker') . '</h4>';
            if (!empty($benchmark['summary']['word_count'])) {
                echo '<p><strong>' . esc_html__('Word count gap', 'seo-performance-checker') . '</strong><br>' . esc_html(number_format_i18n((float) $benchmark['summary']['word_count']['current'])) . ' vs ' . esc_html(number_format_i18n((float) $benchmark['summary']['word_count']['competitor_average'])) . ' ' . esc_html__('avg competitor words', 'seo-performance-checker') . '</p>';
            }
            if (!empty($benchmark['summary']['heading_count'])) {
                echo '<p><strong>' . esc_html__('Heading count gap', 'seo-performance-checker') . '</strong><br>' . esc_html(number_format_i18n((float) $benchmark['summary']['heading_count']['current'])) . ' vs ' . esc_html(number_format_i18n((float) $benchmark['summary']['heading_count']['competitor_average'])) . ' ' . esc_html__('avg competitor headings', 'seo-performance-checker') . '</p>';
            }
            if (!empty($benchmark['suggestions'])) {
                echo '<ul class="seopc-checklist-mini">';
                foreach (array_slice($benchmark['suggestions'], 0, 3) as $item) {
                    echo '<li><span>' . esc_html($item) . '</span></li>';
                }
                echo '</ul>';
            }
            echo '</div>';
        }

        if (empty($data['connected'])) {
            echo '<p>' . esc_html($data['message'] ?? __('Connect Google Analytics on the plugin dashboard first.', 'seo-performance-checker')) . '</p>';
            echo '<p><a class="button" href="' . esc_url(admin_url('options-general.php?page=seo-performance')) . '">' . esc_html__('Open dashboard', 'seo-performance-checker') . '</a></p>';
            return;
        }

        if (!empty($data['errors'])) {
            foreach ($data['errors'] as $error) {
                echo '<div class="notice notice-warning inline"><p>' . esc_html($error) . '</p></div>';
            }
        }

        $range_label = $data['date_range']['label'] ?? __('Last 28 days', 'seo-performance-checker');
        echo '<p><strong>' . esc_html($range_label) . '</strong></p>';
        echo '<p><small>' . esc_html($data['post_url'] ?? '') . '</small></p>';

        $this->render_metric_group(__('GA4', 'seo-performance-checker'), [
            'Views' => $data['analytics']['screenPageViews'] ?? null,
            'Sessions' => $data['analytics']['sessions'] ?? null,
            'Users' => $data['analytics']['activeUsers'] ?? null,
            'Bounce rate' => $data['analytics']['bounceRate'] ?? null,
        ], ['bounceRate']);



        if (!empty($data['analytics_trend'])) {
            echo '<div class="seopc-post-widget-group seopc-chart-card" data-default-metric="screenPageViews">';
            echo '<div class="seopc-chart-card-header"><div><h4 style="margin:0;">' . esc_html__('Traffic trend', 'seo-performance-checker') . '</h4></div><div class="seopc-chart-toolbar"><button type="button" class="button button-small is-primary" data-metric="screenPageViews">' . esc_html__('Views', 'seo-performance-checker') . '</button><button type="button" class="button button-small" data-metric="sessions">' . esc_html__('Sessions', 'seo-performance-checker') . '</button></div></div>';
            echo '<script type="application/json" class="seopc-chart-series-json">' . wp_json_encode($data['analytics_trend']) . '</script><div class="seopc-chart-canvas"></div></div>';
        }


        if (!empty($data['internal_link_suggestions'])) {
            echo '<div class="seopc-post-widget-group"><h4 style="margin:12px 0 8px;">' . esc_html__('Internal link ideas', 'seo-performance-checker') . '</h4><ul class="seopc-checklist-mini">';
            foreach ($data['internal_link_suggestions'] as $suggestion) {
                echo '<li><span><a href="' . esc_url($suggestion['url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($suggestion['title']) . '</a></span><small>' . esc_html(sprintf(__('Anchor: %s', 'seo-performance-checker'), $suggestion['anchor'])) . '</small></li>';
            }
            echo '</ul></div>';
        }

        echo '<p><a class="button button-secondary" href="' . esc_url(admin_url('options-general.php?page=seo-performance')) . '">' . esc_html__('Open full analytics dashboard', 'seo-performance-checker') . '</a></p>';
    }

    private function render_metric_group($title, $metrics, $reverse_good = []) {
        echo '<div class="seopc-post-widget-group">';
        echo '<h4 style="margin:12px 0 8px;">' . esc_html($title) . '</h4>';
        echo '<ul class="seopc-post-metric-list">';
        foreach ($metrics as $label => $metric) {
            if (!$metric) {
                continue;
            }
            $value = $metric['value'] ?? 0;
            $delta = (float) ($metric['delta_percent'] ?? 0);
            $class = 'neutral';
            if (abs($delta) >= 0.01) {
                $is_reverse = in_array($label === 'Position' ? 'position' : ($label === 'Bounce rate' ? 'bounceRate' : ''), $reverse_good, true);
                $positive = $delta > 0;
                $good = $is_reverse ? !$positive : $positive;
                $class = $good ? 'good' : 'bad';
            }
            echo '<li><span>' . esc_html($label) . '</span><strong>' . esc_html($this->format_metric_value($label, $value)) . '</strong><small class="seopc-metric-delta ' . esc_attr($class) . '">' . esc_html(($delta > 0 ? '+' : '') . round($delta, 2) . '%') . '</small></li>';
        }
        echo '</ul></div>';
    }

    private function format_metric_value($label, $value) {
        if ($label === 'CTR') {
            return round(((float) $value) * 100, 2) . '%';
        }
        if ($label === 'Position' || $label === 'Bounce rate') {
            return round((float) $value, 2);
        }
        return number_format_i18n((float) $value);
    }

    private function score_class($score) {
        if ($score >= 80) {
            return 'good';
        }
        if ($score >= 60) {
            return 'warning';
        }
        return 'bad';
    }
}
