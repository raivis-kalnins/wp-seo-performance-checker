<div class="wrap seopc-wrap">
    <?php if (isset($_GET['history_cleared']) && $_GET['history_cleared'] === '1'): ?>
    <div class="notice notice-success is-dismissible">
        <p><?php _e('Analysis history cleared successfully.', 'seo-performance-checker'); ?></p>
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['google_settings_saved'])): ?>
    <div class="notice notice-success is-dismissible"><p><?php _e('Google API settings saved.', 'seo-performance-checker'); ?></p></div>
    <?php endif; ?>
    <?php if (isset($_GET['google_connected'])): ?>
    <div class="notice notice-success is-dismissible"><p><?php _e('Google account connected successfully.', 'seo-performance-checker'); ?></p></div>
    <?php endif; ?>
    <?php if (isset($_GET['google_disconnected'])): ?>
    <div class="notice notice-success is-dismissible"><p><?php _e('Google account disconnected.', 'seo-performance-checker'); ?></p></div>
    <?php endif; ?>
    <?php if (isset($_GET['google_selection_saved'])): ?>
    <div class="notice notice-success is-dismissible"><p><?php _e('Google property selection updated.', 'seo-performance-checker'); ?></p></div>
    <?php endif; ?>
    <?php if (!empty($_GET['google_error'])): ?>
    <div class="notice notice-error is-dismissible"><p><?php echo esc_html(wp_unslash($_GET['google_error'])); ?></p></div>
    <?php endif; ?>

    <?php if (class_exists('SEOPC_Admin_Menu')) { SEOPC_Admin_Menu::render_tabs('dashboard'); } ?>

    <?php
    $history = get_option('seopc_analysis_history', []);
    $history_index = [];
    foreach ($history as $entry) {
        $post_id = (int) ($entry['post_id'] ?? 0);
        if (!$post_id || isset($history_index[$post_id])) {
            continue;
        }
        $entry['post_title'] = get_the_title($post_id) ?: ($entry['post_title'] ?? __('Untitled', 'seo-performance-checker'));
        $entry['post_type'] = get_post_type($post_id) ?: ($entry['post_type'] ?? '');
        $entry['slug'] = get_post_field('post_name', $post_id) ?: ($entry['slug'] ?? '');
        $entry['url'] = get_permalink($post_id) ?: ($entry['url'] ?? '');
        $history_index[$post_id] = $entry;
    }
    $history_items = array_values($history_index);
    $redirect_rows = [];
    foreach ($history_items as $history_item) {
        $suggestions = $history_item['internal_links']['redirect_suggestions'] ?? [];
        foreach ($suggestions as $suggestion) {
            $redirect_rows[] = [
                'post_id' => $history_item['post_id'],
                'post_title' => $history_item['post_title'],
                'post_type' => $history_item['post_type'],
                'slug' => $history_item['slug'],
                'from_url' => $suggestion['from_url'] ?? '',
                'from_path' => $suggestion['from_path'] ?? '',
                'anchor' => $suggestion['anchor'] ?? '',
                'to_title' => $suggestion['to_title'] ?? '',
                'to_slug' => $suggestion['to_slug'] ?? '',
                'to_url' => $suggestion['to_url'] ?? '',
                'confidence_label' => $suggestion['confidence_label'] ?? '',
                'match_score' => $suggestion['match_score'] ?? '',
                'reason' => $suggestion['reason'] ?? '',
            ];
        }
    }
    $public_post_types = get_post_types(['public' => true], 'objects');
    unset($public_post_types['attachment']);

    $google_service = new SEOPC_Google_Integrations();
    $google_data = $google_service->get_dashboard_data([
        'preset' => $_GET['seopc_range'] ?? '',
        'start_date' => $_GET['seopc_start_date'] ?? '',
        'end_date' => $_GET['seopc_end_date'] ?? '',
    ]);
    $google_settings = $google_data['settings'];
    $google_range = $google_data['date_range'] ?? [];
    $google_range_presets = [
        'last_7_days' => __('Last 7 days', 'seo-performance-checker'),
        'last_28_days' => __('Last 28 days', 'seo-performance-checker'),
        'last_30_days' => __('Last 30 days', 'seo-performance-checker'),
        'last_90_days' => __('Last 90 days', 'seo-performance-checker'),
        'month_to_date' => __('Month to date', 'seo-performance-checker'),
        'last_month' => __('Last month', 'seo-performance-checker'),
        'custom' => __('Custom range', 'seo-performance-checker'),
    ];
    $seopc_reverse_good_metrics = ['bounceRate', 'position'];
    $seopc_format_metric_value = static function($metric, $metric_data) {
        $value = is_array($metric_data) ? ($metric_data['value'] ?? 0) : $metric_data;
        switch ($metric) {
            case 'bounceRate':
            case 'position':
                return round((float) $value, 2);
            case 'ctr':
                return round(((float) $value) * 100, 2) . '%';
            case 'averageSessionDuration':
                $seconds = (int) round((float) $value);
                return gmdate($seconds >= 3600 ? 'H:i:s' : 'i:s', max(0, $seconds));
            default:
                return number_format_i18n((float) $value);
        }
    };
    $seopc_format_delta = static function($metric, $metric_data) use ($seopc_reverse_good_metrics) {
        $delta = (float) ($metric_data['delta_percent'] ?? 0);
        $is_reverse = in_array($metric, $seopc_reverse_good_metrics, true);
        $class = 'neutral';
        if (abs($delta) >= 0.01) {
            $positive = $delta > 0;
            $is_good = $is_reverse ? !$positive : $positive;
            $class = $is_good ? 'good' : 'bad';
        }
        $prefix = $delta > 0 ? '+' : '';
        return [
            'class' => $class,
            'label' => $prefix . round($delta, 2) . '%',
        ];
    };
    ?>

    <div class="seopc-page-header">
        <h1><?php _e('SEO Performance Dashboard', 'seo-performance-checker'); ?></h1>

        <div class="seopc-page-actions">
            <a href="<?php echo admin_url(); ?>" class="button seopc-dashboard-button">
                <span class="dashicons dashicons-dashboard"></span>
                <?php _e('WordPress Dashboard', 'seo-performance-checker'); ?>
            </a>
        </div>
    </div>

    <div class="seopc-section seopc-google-dashboard-section">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap;">
            <div>
                <h2 style="margin:0;"><?php _e('Google Analytics', 'seo-performance-checker'); ?></h2>
                <p class="description" style="margin-top:6px;"><?php _e('Connect Google Analytics 4 to surface traffic, trends, top pages, and engagement insights directly in this dashboard.', 'seo-performance-checker'); ?></p>
            </div>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <?php if (!empty($google_range['label'])): ?>
                    <span class="seopc-score-badge neutral"><?php echo esc_html($google_range['label']); ?></span>
                <?php endif; ?>
                <?php if (!empty($google_settings['connected_email'])): ?>
                    <span class="seopc-score-badge good"><?php echo esc_html($google_settings['connected_email']); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <form method="get" action="<?php echo esc_url(admin_url('options-general.php')); ?>" class="seopc-google-filter-form" style="margin-top:18px;display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <input type="hidden" name="page" value="seo-performance">
            <div class="seopc-filter-field">
                <label for="seopc-range"><?php _e('Date range', 'seo-performance-checker'); ?></label>
                <select id="seopc-range" name="seopc_range">
                    <?php foreach ($google_range_presets as $preset_key => $preset_label): ?>
                        <option value="<?php echo esc_attr($preset_key); ?>" <?php selected($google_range['preset'] ?? 'last_28_days', $preset_key); ?>><?php echo esc_html($preset_label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="seopc-filter-field seopc-custom-date-field">
                <label for="seopc-start-date"><?php _e('Start date', 'seo-performance-checker'); ?></label>
                <input type="date" id="seopc-start-date" name="seopc_start_date" value="<?php echo esc_attr($google_range['start_date'] ?? ''); ?>">
            </div>
            <div class="seopc-filter-field seopc-custom-date-field">
                <label for="seopc-end-date"><?php _e('End date', 'seo-performance-checker'); ?></label>
                <input type="date" id="seopc-end-date" name="seopc_end_date" value="<?php echo esc_attr($google_range['end_date'] ?? ''); ?>">
            </div>
            <div class="seopc-toolbar-actions">
                <button type="submit" class="button button-primary"><?php _e('Apply Range', 'seo-performance-checker'); ?></button>
                <a href="<?php echo esc_url(add_query_arg(['page' => 'seo-performance', 'seopc_range' => 'last_28_days'], admin_url('options-general.php'))); ?>" class="button"><?php _e('Reset', 'seo-performance-checker'); ?></a>
            </div>
            <?php if (!empty($google_range['comparison_label'])): ?>
                <div class="seopc-filter-summary"><?php echo esc_html($google_range['comparison_label']); ?></div>
            <?php endif; ?>
        </form>

        <div class="seopc-preview-grid" style="margin-top:20px;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));">
            <div class="seopc-preview-card">
                <h3><?php _e('Google Analytics Connection', 'seo-performance-checker'); ?></h3>
                <p><?php _e('Create a Google OAuth Web application in Google Cloud, enable the Google Analytics Data API and Google Analytics Admin API, then add the redirect URI shown below.', 'seo-performance-checker'); ?></p>
                <p><strong><?php _e('Redirect URI:', 'seo-performance-checker'); ?></strong><br><code style="word-break:break-all;"><?php echo esc_html(SEOPC_Google_Integrations::get_redirect_uri()); ?></code></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:15px;">
                    <input type="hidden" name="action" value="seopc_google_save_settings">
                    <?php wp_nonce_field('seopc_google_save_settings'); ?>
                    <p><label><strong><?php _e('Client ID', 'seo-performance-checker'); ?></strong><br>
                        <input type="text" name="client_id" class="regular-text" value="<?php echo esc_attr($google_settings['client_id']); ?>"></label></p>
                    <p><label><strong><?php _e('Client Secret', 'seo-performance-checker'); ?></strong><br>
                        <input type="password" name="client_secret" class="regular-text" value="<?php echo esc_attr($google_settings['client_secret']); ?>"></label></p>
                    <p>
                        <button type="submit" class="button button-secondary"><?php _e('Save Google API Settings', 'seo-performance-checker'); ?></button>
                    </p>
                </form>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="seopc_google_connect">
                        <?php wp_nonce_field('seopc_google_connect'); ?>
                        <button type="submit" class="button button-primary"><?php _e('Connect Google Account', 'seo-performance-checker'); ?></button>
                    </form>
                    <?php if ($google_data['connected']): ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Disconnect Google from this plugin?', 'seo-performance-checker')); ?>');">
                        <input type="hidden" name="action" value="seopc_google_disconnect">
                        <?php wp_nonce_field('seopc_google_disconnect'); ?>
                        <button type="submit" class="button"><?php _e('Disconnect', 'seo-performance-checker'); ?></button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="seopc-preview-card">
                <h3><?php _e('Property Selection', 'seo-performance-checker'); ?></h3>
                <?php if ($google_data['connected']): ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="seopc_google_select_property">
                    <?php wp_nonce_field('seopc_google_select_property'); ?>
                    <p>
                        <label><strong><?php _e('GA4 Property', 'seo-performance-checker'); ?></strong><br>
                        <select name="selected_property" style="min-width:280px;">
                            <option value=""><?php _e('Select property...', 'seo-performance-checker'); ?></option>
                            <?php foreach ($google_data['properties'] as $property): ?>
                                <option value="<?php echo esc_attr($property['property_id']); ?>" <?php selected($google_settings['selected_property'], $property['property_id']); ?>><?php echo esc_html($property['account'] . ' → ' . $property['property'] . ' (' . $property['property_id'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select></label>
                    </p>
                    <p><button type="submit" class="button button-secondary"><?php _e('Save Selected Property', 'seo-performance-checker'); ?></button></p>
                </form>
                <?php else: ?>
                    <p><?php _e('Save credentials and connect your Google account to load available GA4 properties.', 'seo-performance-checker'); ?></p>
                <?php endif; ?>

                <?php if (!empty($google_data['errors'])): ?>
                    <div class="notice notice-warning inline"><p><?php echo esc_html(implode(' | ', array_unique($google_data['errors']))); ?></p></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($google_data['connected'] && !empty($google_data['analytics_overview'])): ?>
        <div class="seopc-preview-grid" style="margin-top:20px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
            <?php
            $metric_cards = [
                ['source' => 'analytics_overview', 'key' => 'activeUsers', 'label' => __('Active Users', 'seo-performance-checker')],
                ['source' => 'analytics_overview', 'key' => 'sessions', 'label' => __('Sessions', 'seo-performance-checker')],
                ['source' => 'analytics_overview', 'key' => 'screenPageViews', 'label' => __('Page Views', 'seo-performance-checker')],
                ['source' => 'analytics_overview', 'key' => 'bounceRate', 'label' => __('Bounce Rate', 'seo-performance-checker')],
            ];
            foreach ($metric_cards as $metric_card):
                $metric_data = $google_data[$metric_card['source']][$metric_card['key']] ?? null;
                if (!$metric_data) {
                    continue;
                }
                $delta_data = $seopc_format_delta($metric_card['key'], $metric_data);
            ?>
            <div class="seopc-preview-card seopc-metric-card">
                <h4><?php echo esc_html($metric_card['label']); ?> <small><?php echo esc_html($google_range['label'] ?? ''); ?></small></h4>
                <div class="seopc-metric-value"><?php echo esc_html($seopc_format_metric_value($metric_card['key'], $metric_data)); ?></div>
                <div class="seopc-metric-delta <?php echo esc_attr($delta_data['class']); ?>"><?php echo esc_html($delta_data['label']); ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="seopc-preview-grid" style="margin-top:20px;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));">
            <div class="seopc-preview-card seopc-chart-card" data-default-metric="sessions">
                <div class="seopc-chart-card-header">
                    <div>
                        <h3><?php _e('Google Analytics Trend', 'seo-performance-checker'); ?></h3>
                        <p class="description"><?php echo esc_html($google_range['label'] ?? ''); ?></p>
                    </div>
                    <div class="seopc-chart-toolbar">
                        <button type="button" class="button button-small is-primary" data-metric="sessions"><?php _e('Sessions', 'seo-performance-checker'); ?></button>
                        <button type="button" class="button button-small" data-metric="activeUsers"><?php _e('Users', 'seo-performance-checker'); ?></button>
                        <button type="button" class="button button-small" data-metric="screenPageViews"><?php _e('Views', 'seo-performance-checker'); ?></button>
                    </div>
                </div>
                <script type="application/json" class="seopc-chart-series-json"><?php echo wp_json_encode($google_data['analytics_trend']); ?></script>
                <div class="seopc-chart-canvas"></div>
            </div>
            <div class="seopc-preview-card seopc-chart-card" data-default-metric="clicks">
                <div class="seopc-chart-card-header">
                    <div>
                        <h3><?php _e('Search Console Trend', 'seo-performance-checker'); ?></h3>
                        <p class="description"><?php echo esc_html($google_range['label'] ?? ''); ?></p>
                    </div>
                    <div class="seopc-chart-toolbar">
                        <button type="button" class="button button-small is-primary" data-metric="clicks"><?php _e('Clicks', 'seo-performance-checker'); ?></button>
                        <button type="button" class="button button-small" data-metric="impressions"><?php _e('Impr.', 'seo-performance-checker'); ?></button>
                        <button type="button" class="button button-small" data-metric="ctr"><?php _e('CTR', 'seo-performance-checker'); ?></button>
                        <button type="button" class="button button-small" data-metric="position"><?php _e('Pos.', 'seo-performance-checker'); ?></button>
                    </div>
                </div>
                <script type="application/json" class="seopc-chart-series-json"><?php echo wp_json_encode($google_data['search_trend']); ?></script>
                <div class="seopc-chart-canvas"></div>
            </div>
        </div>

        <div class="seopc-preview-grid" style="margin-top:20px;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));">
            <div class="seopc-preview-card">
                <h3><?php _e('Top Pages from Google Analytics', 'seo-performance-checker'); ?></h3>
                <?php if (!empty($google_data['analytics_pages'])): ?>
                <table class="widefat striped"><thead><tr><th><?php _e('Page', 'seo-performance-checker'); ?></th><th><?php _e('Views', 'seo-performance-checker'); ?></th><th><?php _e('Sessions', 'seo-performance-checker'); ?></th><th><?php _e('Users', 'seo-performance-checker'); ?></th></tr></thead><tbody>
                <?php foreach ($google_data['analytics_pages'] as $row): ?>
                    <tr><td><code><?php echo esc_html($row['page']); ?></code></td><td><?php echo esc_html(number_format_i18n((float) $row['pageViews'])); ?></td><td><?php echo esc_html(number_format_i18n((float) $row['sessions'])); ?></td><td><?php echo esc_html(number_format_i18n((float) $row['users'])); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php else: ?><p><?php _e('No GA4 page data returned yet for the selected property.', 'seo-performance-checker'); ?></p><?php endif; ?>
            </div>
            <div class="seopc-preview-card">
                <h3><?php _e('Top Search Queries', 'seo-performance-checker'); ?></h3>
                <?php if (false && !empty($google_data['search_queries'])): ?>
                <table class="widefat striped"><thead><tr><th><?php _e('Query', 'seo-performance-checker'); ?></th><th><?php _e('Clicks', 'seo-performance-checker'); ?></th><th><?php _e('Impr.', 'seo-performance-checker'); ?></th><th><?php _e('CTR', 'seo-performance-checker'); ?></th><th><?php _e('Pos.', 'seo-performance-checker'); ?></th></tr></thead><tbody>
                <?php foreach ($google_data['search_queries'] as $row): ?>
                    <tr><td><?php echo esc_html($row['query']); ?></td><td><?php echo esc_html(number_format_i18n((float) $row['clicks'])); ?></td><td><?php echo esc_html(number_format_i18n((float) $row['impressions'])); ?></td><td><?php echo esc_html(round(((float) $row['ctr']) * 100, 2)); ?>%</td><td><?php echo esc_html(round((float) $row['position'], 2)); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php else: ?><p><?php _e('No Search Console query data returned yet for the selected property.', 'seo-performance-checker'); ?></p><?php endif; ?>
            </div>
        </div>


        <div class="seopc-preview-grid" style="margin-top:20px;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));">
            <div class="seopc-preview-card">
                <h3><?php _e('GA4 Device Breakdown', 'seo-performance-checker'); ?></h3>
                <?php if (!empty($google_data['analytics_devices'])): ?>
                <table class="widefat striped"><thead><tr><th><?php _e('Device', 'seo-performance-checker'); ?></th><th><?php _e('Views', 'seo-performance-checker'); ?></th><th><?php _e('Sessions', 'seo-performance-checker'); ?></th><th><?php _e('Users', 'seo-performance-checker'); ?></th></tr></thead><tbody>
                <?php foreach ($google_data['analytics_devices'] as $row): ?>
                    <tr><td><?php echo esc_html(ucfirst($row['device'])); ?></td><td><?php echo esc_html(number_format_i18n((float) $row['pageViews'])); ?></td><td><?php echo esc_html(number_format_i18n((float) $row['sessions'])); ?></td><td><?php echo esc_html(number_format_i18n((float) $row['users'])); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php else: ?><p><?php _e('No GA4 device data returned yet for the selected property.', 'seo-performance-checker'); ?></p><?php endif; ?>
            </div>
            <div class="seopc-preview-card">
                <h3><?php _e('GA4 Country Breakdown', 'seo-performance-checker'); ?></h3>
                <?php if (!empty($google_data['analytics_countries'])): ?>
                <table class="widefat striped"><thead><tr><th><?php _e('Country', 'seo-performance-checker'); ?></th><th><?php _e('Views', 'seo-performance-checker'); ?></th><th><?php _e('Sessions', 'seo-performance-checker'); ?></th><th><?php _e('Users', 'seo-performance-checker'); ?></th></tr></thead><tbody>
                <?php foreach ($google_data['analytics_countries'] as $row): ?>
                    <tr><td><?php echo esc_html($row['country']); ?></td><td><?php echo esc_html(number_format_i18n((float) $row['pageViews'])); ?></td><td><?php echo esc_html(number_format_i18n((float) $row['sessions'])); ?></td><td><?php echo esc_html(number_format_i18n((float) $row['users'])); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php else: ?><p><?php _e('No GA4 country data returned yet for the selected property.', 'seo-performance-checker'); ?></p><?php endif; ?>
            </div>
        </div>

        <div class="seopc-preview-grid" style="margin-top:20px;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));">
            <div class="seopc-preview-card" style="grid-column:1 / -1;">
                <h3><?php _e('GA4 Source / Medium', 'seo-performance-checker'); ?></h3>
                <?php if (!empty($google_data['analytics_sources'])): ?>
                <table class="widefat striped"><thead><tr><th><?php _e('Source', 'seo-performance-checker'); ?></th><th><?php _e('Medium', 'seo-performance-checker'); ?></th><th><?php _e('Sessions', 'seo-performance-checker'); ?></th><th><?php _e('Users', 'seo-performance-checker'); ?></th><th><?php _e('Views', 'seo-performance-checker'); ?></th></tr></thead><tbody>
                <?php foreach ($google_data['analytics_sources'] as $row): ?>
                    <tr><td><?php echo esc_html($row['source']); ?></td><td><?php echo esc_html($row['medium']); ?></td><td><?php echo esc_html(number_format_i18n((float) $row['sessions'])); ?></td><td><?php echo esc_html(number_format_i18n((float) $row['users'])); ?></td><td><?php echo esc_html(number_format_i18n((float) $row['pageViews'])); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php else: ?><p><?php _e('No GA4 source / medium data returned yet for the selected property.', 'seo-performance-checker'); ?></p><?php endif; ?>
            </div>
        </div>

        <div class="seopc-preview-grid" style="margin-top:20px;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));">
            <div class="seopc-preview-card">
                <h3><?php _e('Search Device Breakdown', 'seo-performance-checker'); ?></h3>
                <?php if (false && !empty($google_data['search_devices'])): ?>
                <table class="widefat striped"><thead><tr><th><?php _e('Device', 'seo-performance-checker'); ?></th><th><?php _e('Clicks', 'seo-performance-checker'); ?></th><th><?php _e('Impr.', 'seo-performance-checker'); ?></th><th><?php _e('CTR', 'seo-performance-checker'); ?></th><th><?php _e('Pos.', 'seo-performance-checker'); ?></th></tr></thead><tbody>
                <?php foreach ($google_data['search_devices'] as $row): ?>
                    <tr><td><?php echo esc_html(ucfirst($row['device'])); ?></td><td><?php echo esc_html(number_format_i18n((float) $row['clicks'])); ?></td><td><?php echo esc_html(number_format_i18n((float) $row['impressions'])); ?></td><td><?php echo esc_html(round(((float) $row['ctr']) * 100, 2)); ?>%</td><td><?php echo esc_html(round((float) $row['position'], 2)); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php else: ?><p><?php _e('No Search Console device data returned yet for the selected property.', 'seo-performance-checker'); ?></p><?php endif; ?>
            </div>
            <div class="seopc-preview-card">
                <h3><?php _e('Search Country Breakdown', 'seo-performance-checker'); ?></h3>
                <?php if (false && !empty($google_data['search_countries'])): ?>
                <table class="widefat striped"><thead><tr><th><?php _e('Country', 'seo-performance-checker'); ?></th><th><?php _e('Clicks', 'seo-performance-checker'); ?></th><th><?php _e('Impr.', 'seo-performance-checker'); ?></th><th><?php _e('CTR', 'seo-performance-checker'); ?></th><th><?php _e('Pos.', 'seo-performance-checker'); ?></th></tr></thead><tbody>
                <?php foreach ($google_data['search_countries'] as $row): ?>
                    <tr><td><?php echo esc_html($row['country']); ?></td><td><?php echo esc_html(number_format_i18n((float) $row['clicks'])); ?></td><td><?php echo esc_html(number_format_i18n((float) $row['impressions'])); ?></td><td><?php echo esc_html(round(((float) $row['ctr']) * 100, 2)); ?>%</td><td><?php echo esc_html(round((float) $row['position'], 2)); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php else: ?><p><?php _e('No Search Console country data returned yet for the selected property.', 'seo-performance-checker'); ?></p><?php endif; ?>
            </div>
        </div>


        <div class="seopc-preview-grid" style="margin-top:20px;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));">
            <div class="seopc-preview-card">
                <h3><?php _e('Tracked Keywords', 'seo-performance-checker'); ?></h3>
                <?php if (false && !empty($google_data['tracked_keywords'])): ?>
                <table class="widefat striped"><thead><tr><th><?php _e('Keyword', 'seo-performance-checker'); ?></th><th><?php _e('Page', 'seo-performance-checker'); ?></th><th><?php _e('Clicks', 'seo-performance-checker'); ?></th><th><?php _e('Impr.', 'seo-performance-checker'); ?></th><th><?php _e('Pos.', 'seo-performance-checker'); ?></th></tr></thead><tbody>
                <?php foreach ($google_data['tracked_keywords'] as $row): ?>
                    <tr><td><?php echo esc_html($row['keyword']); ?></td><td><?php if (!empty($row['edit_url'])): ?><a href="<?php echo esc_url($row['edit_url']); ?>"><?php echo esc_html($row['post_title']); ?></a><?php else: echo esc_html($row['post_title']); endif; ?></td><td><?php echo esc_html(number_format_i18n((float) $row['clicks'])); ?></td><td><?php echo esc_html(number_format_i18n((float) $row['impressions'])); ?></td><td><?php echo esc_html(round((float) $row['position'], 2)); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php else: ?><p><?php _e('Add target keywords to posts to unlock Search Console keyword tracking here.', 'seo-performance-checker'); ?></p><?php endif; ?>
            </div>
            <div class="seopc-preview-card">
                <h3><?php _e('Lowest Content SEO Scores', 'seo-performance-checker'); ?></h3>
                <?php if (!empty($google_data['content_scores'])): ?>
                <table class="widefat striped"><thead><tr><th><?php _e('Page', 'seo-performance-checker'); ?></th><th><?php _e('Primary keyword', 'seo-performance-checker'); ?></th><th><?php _e('Score', 'seo-performance-checker'); ?></th></tr></thead><tbody>
                <?php foreach ($google_data['content_scores'] as $row): ?>
                    <tr><td><?php if (!empty($row['edit_url'])): ?><a href="<?php echo esc_url($row['edit_url']); ?>"><?php echo esc_html($row['title']); ?></a><?php else: echo esc_html($row['title']); endif; ?></td><td><?php echo esc_html($row['primary_keyword'] ?: '—'); ?></td><td><strong><?php echo esc_html((int) $row['score']); ?>/100</strong></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php else: ?><p><?php _e('Content scores appear here once you add target keywords to posts and pages.', 'seo-performance-checker'); ?></p><?php endif; ?>
            </div>
        </div>

        <div class="seopc-preview-grid" style="margin-top:20px;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));">
            <div class="seopc-preview-card" style="grid-column:1 / -1;">
                <h3><?php _e('Landing Page Opportunities', 'seo-performance-checker'); ?></h3>
                <?php if (false && !empty($google_data['page_opportunities'])): ?>
                <table class="widefat striped"><thead><tr><th><?php _e('Page', 'seo-performance-checker'); ?></th><th><?php _e('Impr.', 'seo-performance-checker'); ?></th><th><?php _e('Clicks', 'seo-performance-checker'); ?></th><th><?php _e('CTR', 'seo-performance-checker'); ?></th><th><?php _e('Pos.', 'seo-performance-checker'); ?></th><th><?php _e('Opportunity', 'seo-performance-checker'); ?></th></tr></thead><tbody>
                <?php foreach ($google_data['page_opportunities'] as $row): ?>
                    <tr><td style="word-break:break-all;"><a href="<?php echo esc_url($row['page']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($row['page']); ?></a></td><td><?php echo esc_html(number_format_i18n((float) $row['impressions'])); ?></td><td><?php echo esc_html(number_format_i18n((float) $row['clicks'])); ?></td><td><?php echo esc_html(round(((float) $row['ctr']) * 100, 2)); ?>%</td><td><?php echo esc_html(round((float) $row['position'], 2)); ?></td><td><strong><?php echo esc_html(round((float) $row['opportunity_score'], 1)); ?></strong></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php else: ?><p><?php _e('Opportunity scores appear when Search Console page data is available.', 'seo-performance-checker'); ?></p><?php endif; ?>
            </div>
        </div>

        <div class="seopc-preview-grid" style="margin-top:20px;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));">
            <div class="seopc-preview-card">
                <h3><?php _e('Top Search Pages', 'seo-performance-checker'); ?></h3>
                <?php if (false && !empty($google_data['search_pages'])): ?>
                <table class="widefat striped"><thead><tr><th><?php _e('Page', 'seo-performance-checker'); ?></th><th><?php _e('Clicks', 'seo-performance-checker'); ?></th><th><?php _e('Impr.', 'seo-performance-checker'); ?></th><th><?php _e('CTR', 'seo-performance-checker'); ?></th><th><?php _e('Pos.', 'seo-performance-checker'); ?></th></tr></thead><tbody>
                <?php foreach ($google_data['search_pages'] as $row): ?>
                    <tr><td style="word-break:break-all;"><a href="<?php echo esc_url($row['page']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($row['page']); ?></a></td><td><?php echo esc_html(number_format_i18n((float) $row['clicks'])); ?></td><td><?php echo esc_html(number_format_i18n((float) $row['impressions'])); ?></td><td><?php echo esc_html(round(((float) $row['ctr']) * 100, 2)); ?>%</td><td><?php echo esc_html(round((float) $row['position'], 2)); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php else: ?><p><?php _e('No Search Console page data returned yet for the selected property.', 'seo-performance-checker'); ?></p><?php endif; ?>
            </div>
            <div class="seopc-preview-card">
                <h3><?php _e('Insights', 'seo-performance-checker'); ?></h3>
                <div class="seopc-insight-stack">
                    <?php foreach (($google_data['analytics_insights'] ?? []) as $insight): ?>
                    <div class="seopc-insight-item">
                        <strong><?php echo esc_html($insight['title'] ?? ''); ?></strong>
                        <p><?php echo esc_html($insight['text'] ?? ''); ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
                <ul style="list-style:disc;padding-left:20px;">
                    <li><?php _e('Compare top GA pages with your SEO test results to prioritize pages that have both traffic and optimization gaps.', 'seo-performance-checker'); ?></li>
                    <li><?php _e('Watch low average position pages and improve internal linking, schema markup, metadata, and image optimization.', 'seo-performance-checker'); ?></li>
                </ul>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="seopc-section">
        <h2><?php _e('Quick Analysis', 'seo-performance-checker'); ?></h2>

        <div style="display: flex; gap: 15px; align-items: center; margin: 20px 0; flex-wrap: wrap;">
            <select id="seopc-post-select" style="min-width: 300px;">
                <option value=""><?php _e('Select page/post/CPT...', 'seo-performance-checker'); ?></option>
                <?php
                $posts = get_posts([
                    'posts_per_page' => 100,
                    'post_type' => array_keys($public_post_types),
                    'post_status' => 'publish'
                ]);
                foreach ($posts as $post):
                    $slug = get_post_field('post_name', $post->ID);
                ?>
                <option value="<?php echo $post->ID; ?>">
                    <?php echo esc_html($post->post_title ?: __('Untitled', 'seo-performance-checker')); ?> — /<?php echo esc_html($slug); ?>/ (<?php echo esc_html($post->post_type); ?>)
                </option>
                <?php endforeach; ?>
            </select>

            <button id="seopc-analyze-btn" class="button button-primary button-hero">
                <?php _e('Analyze', 'seo-performance-checker'); ?>
            </button>
        </div>
    </div>

    <div id="seopc-results" style="display: none;">
        <!-- Results populated by JavaScript -->
    </div>

    <div class="seopc-section">
        <h2><?php _e('SERP Rewrite Suggestions', 'seo-performance-checker'); ?></h2>
        <?php if (!empty($google_data['rewrite_suggestions'])): ?>
        <table class="widefat striped">
            <thead><tr><th><?php _e('Page', 'seo-performance-checker'); ?></th><th><?php _e('Suggested Title', 'seo-performance-checker'); ?></th><th><?php _e('Suggested Meta Description', 'seo-performance-checker'); ?></th><th><?php _e('Why', 'seo-performance-checker'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($google_data['rewrite_suggestions'] as $row): ?>
                <tr>
                    <td><a href="<?php echo esc_url($row['edit_url']); ?>"><?php echo esc_html($row['title']); ?></a></td>
                    <td><?php echo esc_html($row['recommended_title']); ?></td>
                    <td><?php echo esc_html($row['recommended_description']); ?></td>
                    <td><?php echo esc_html(implode(' ', array_slice((array) ($row['why'] ?? []), 0, 2))); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p><?php _e('No rewrite suggestions yet. Add tracked keywords to posts to generate stronger title and meta recommendations.', 'seo-performance-checker'); ?></p>
        <?php endif; ?>
    </div>

    <div class="seopc-section">
        <h2><?php _e('Competitor Benchmarks', 'seo-performance-checker'); ?></h2>
        <?php if (!empty($google_data['competitor_benchmarks'])): ?>
        <table class="widefat striped">
            <thead><tr><th><?php _e('Page', 'seo-performance-checker'); ?></th><th><?php _e('Competitors', 'seo-performance-checker'); ?></th><th><?php _e('Word Count Gap', 'seo-performance-checker'); ?></th><th><?php _e('Heading Gap', 'seo-performance-checker'); ?></th><th><?php _e('Top Opportunity', 'seo-performance-checker'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($google_data['competitor_benchmarks'] as $row): 
                $summary = $row['summary'] ?? []; ?>
                <tr>
                    <td><a href="<?php echo esc_url($row['edit_url']); ?>"><?php echo esc_html($row['title']); ?></a></td>
                    <td><?php echo esc_html((int) ($row['competitor_count'] ?? 0)); ?></td>
                    <td><?php echo isset($summary['word_count']) ? esc_html(round((float) ($summary['word_count']['gap'] ?? 0), 1)) : '—'; ?></td>
                    <td><?php echo isset($summary['heading_count']) ? esc_html(round((float) ($summary['heading_count']['gap'] ?? 0), 1)) : '—'; ?></td>
                    <td><?php echo esc_html($row['suggestions'][0] ?? __('Review benchmark details in the editor widget.', 'seo-performance-checker')); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p><?php _e('No competitor benchmarks yet. Add competitor URLs in the post editor to compare page depth, headings, images, and schema usage.', 'seo-performance-checker'); ?></p>
        <?php endif; ?>
    </div>

    <div class="seopc-section">
        <div style="display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap; margin-bottom: 16px;">
            <h2 style="margin: 0;"><?php _e('Site Overview', 'seo-performance-checker'); ?></h2>

            <div class="seopc-toolbar-actions">
                <button type="button" class="button button-primary" id="seopc-bulk-retest-btn" disabled>
                    <?php _e('Bulk Re-Test Selected', 'seo-performance-checker'); ?>
                </button>

                <?php if (!empty($history_items)): ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Clear all saved item history?', 'seo-performance-checker')); ?>');">
                    <input type="hidden" name="action" value="seopc_clear_history">
                    <?php wp_nonce_field('seopc_clear_history'); ?>
                    <button type="submit" class="button button-secondary">
                        <span class="dashicons dashicons-trash" style="margin-top: 3px;"></span>
                        <?php _e('Clear History', 'seo-performance-checker'); ?>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($history_items)): ?>
        <div class="seopc-filter-bar">
            <div class="seopc-filter-field">
                <label for="seopc-filter-post-type"><?php _e('Post type', 'seo-performance-checker'); ?></label>
                <select id="seopc-filter-post-type">
                    <option value="all"><?php _e('All types', 'seo-performance-checker'); ?></option>
                    <?php foreach ($public_post_types as $post_type): ?>
                    <option value="<?php echo esc_attr($post_type->name); ?>"><?php echo esc_html($post_type->labels->singular_name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="seopc-filter-field">
                <label for="seopc-filter-score"><?php _e('Score', 'seo-performance-checker'); ?></label>
                <select id="seopc-filter-score">
                    <option value="all"><?php _e('All scores', 'seo-performance-checker'); ?></option>
                    <option value="good"><?php _e('Good (80+)', 'seo-performance-checker'); ?></option>
                    <option value="warning"><?php _e('Needs work (60-79)', 'seo-performance-checker'); ?></option>
                    <option value="critical"><?php _e('Critical (<60)', 'seo-performance-checker'); ?></option>
                </select>
            </div>
            <div class="seopc-filter-field">
                <label for="seopc-filter-broken"><?php _e('Broken links', 'seo-performance-checker'); ?></label>
                <select id="seopc-filter-broken">
                    <option value="all"><?php _e('All items', 'seo-performance-checker'); ?></option>
                    <option value="has-broken"><?php _e('Has broken links', 'seo-performance-checker'); ?></option>
                    <option value="no-broken"><?php _e('No broken links', 'seo-performance-checker'); ?></option>
                </select>
            </div>
            <div class="seopc-filter-field seopc-filter-field-search">
                <label for="seopc-filter-search"><?php _e('Search', 'seo-performance-checker'); ?></label>
                <input type="search" id="seopc-filter-search" placeholder="<?php esc_attr_e('Title or slug...', 'seo-performance-checker'); ?>">
            </div>
            <div class="seopc-filter-summary" id="seopc-filter-summary">
                <?php echo esc_html(sprintf(_n('%d item', '%d items', count($history_items), 'seo-performance-checker'), count($history_items))); ?>
            </div>
        </div>

        <table class="seopc-table seopc-history-table">
            <thead>
                <tr>
                    <th class="seopc-check-col"><input type="checkbox" id="seopc-select-all"></th>
                    <th><?php _e('Item', 'seo-performance-checker'); ?></th>
                    <th><?php _e('Type', 'seo-performance-checker'); ?></th>
                    <th><?php _e('Score', 'seo-performance-checker'); ?></th>
                    <th><?php _e('Issues', 'seo-performance-checker'); ?></th>
                    <th><?php _e('Requests', 'seo-performance-checker'); ?></th>
                    <th><?php _e('Broken Links', 'seo-performance-checker'); ?></th>
                    <th><?php _e('External Links', 'seo-performance-checker'); ?></th>
                    <th><?php _e('Redirects', 'seo-performance-checker'); ?></th>
                    <th><?php _e('Date', 'seo-performance-checker'); ?></th>
                    <th><?php _e('Actions', 'seo-performance-checker'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history_items as $check):
                    $score = (int) ($check['overall_score'] ?? 0);
                    $link_data = $check['internal_links'] ?? [];
                    $broken_count = (int) ($link_data['broken_count'] ?? 0);
                    $slug = $check['slug'] ? '/' . ltrim($check['slug'], '/') . '/' : '/';
                    $score_band = $score >= 80 ? 'good' : ($score >= 60 ? 'warning' : 'critical');
                ?>
                <tr
                    class="seopc-history-row"
                    data-post-id="<?php echo esc_attr($check['post_id']); ?>"
                    data-post-type="<?php echo esc_attr($check['post_type']); ?>"
                    data-score="<?php echo esc_attr($score); ?>"
                    data-score-band="<?php echo esc_attr($score_band); ?>"
                    data-broken="<?php echo esc_attr($broken_count); ?>"
                    data-search="<?php echo esc_attr(strtolower(($check['post_title'] ?? '') . ' ' . ($check['slug'] ?? ''))); ?>"
                >
                    <td class="seopc-check-col"><input type="checkbox" class="seopc-row-checkbox" value="<?php echo esc_attr($check['post_id']); ?>"></td>
                    <td>
                        <div class="seopc-item-title-cell">
                            <strong><?php echo esc_html($check['post_title']); ?></strong>
                            <code><?php echo esc_html($slug); ?></code>
                        </div>
                    </td>
                    <td><?php echo esc_html($check['post_type']); ?></td>
                    <td>
                        <span class="seopc-score-badge <?php echo esc_attr($score_band); ?>">
                            <?php echo esc_html($score); ?>/100
                        </span>
                    </td>
                    <td><?php echo intval($check['critical_issues']); ?> critical</td>
                    <td><?php echo isset($check['requests_count']) ? intval($check['requests_count']) : '&mdash;'; ?></td>
                    <td><?php echo $broken_count; ?></td>
                    <td><?php echo isset($link_data['external']) ? intval($link_data['external']) : '&mdash;'; ?></td>
                    <td><?php echo isset($link_data['redirect_count']) ? intval($link_data['redirect_count']) : '&mdash;'; ?></td>
                    <td><?php echo human_time_diff(strtotime($check['timestamp']), current_time('timestamp')); ?> ago</td>
                    <td>
                        <button type="button" class="button button-small seopc-view-results-btn" data-post-id="<?php echo esc_attr($check['post_id']); ?>" title="<?php echo esc_attr__('View saved results for this item', 'seo-performance-checker'); ?>"><?php _e('Results', 'seo-performance-checker'); ?></button>
                        <button type="button" class="button button-small seopc-run-row-retest" data-post-id="<?php echo esc_attr($check['post_id']); ?>"><?php _e('Test Again', 'seo-performance-checker'); ?></button>
                        <a href="<?php echo get_edit_post_link($check['post_id']); ?>" class="button button-small"><?php _e('Edit', 'seo-performance-checker'); ?></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div id="seopc-bulk-retest-status" class="seopc-bulk-status" style="display:none;"></div>
        <?php else: ?>
        <p><?php _e('No analyses yet. Run your first check above!', 'seo-performance-checker'); ?></p>
        <?php endif; ?>
    </div>

    <?php if (!empty($history_items)): ?>
    <div class="seopc-section">
        <h2><?php _e('Broken & External Links by Item', 'seo-performance-checker'); ?></h2>
        <p><?php _e('Review the latest saved link checks for recent pages, posts, and public custom post types.', 'seo-performance-checker'); ?></p>

        <div class="seopc-link-card-grid">
            <?php foreach (array_slice($history_items, 0, 6) as $check):
                $link_data = $check['internal_links'] ?? [];
                $broken_urls = $link_data['broken_urls'] ?? [];
                $external_urls = $link_data['external_urls'] ?? [];
                $slug = $check['slug'] ? '/' . ltrim($check['slug'], '/') . '/' : '/';
            ?>
            <div class="seopc-link-card">
                <div class="seopc-link-card-header">
                    <div>
                        <h3><?php echo esc_html($check['post_title']); ?></h3>
                        <p><?php echo esc_html(strtoupper($check['post_type'])); ?> · <?php echo esc_html($slug); ?></p>
                    </div>
                    <span class="seopc-score-badge <?php echo $check['overall_score'] >= 80 ? 'good' : ($check['overall_score'] >= 60 ? 'warning' : 'critical'); ?>">
                        <?php echo intval($check['overall_score']); ?>/100
                    </span>
                </div>

                <div class="seopc-link-card-metrics">
                    <span><strong><?php echo intval($link_data['broken_count'] ?? 0); ?></strong> <?php _e('Broken', 'seo-performance-checker'); ?></span>
                    <span><strong><?php echo intval($link_data['external'] ?? 0); ?></strong> <?php _e('External', 'seo-performance-checker'); ?></span>
                    <span><strong><?php echo intval($link_data['internal'] ?? 0); ?></strong> <?php _e('Internal', 'seo-performance-checker'); ?></span>
                </div>

                <div class="seopc-link-card-columns">
                    <div>
                        <h4><?php _e('Broken links', 'seo-performance-checker'); ?></h4>
                        <?php if (!empty($broken_urls)): ?>
                        <ul class="seopc-link-list">
                            <?php foreach (array_slice($broken_urls, 0, 4) as $item): ?>
                            <li>
                                <a href="<?php echo esc_url($item['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($item['anchor'] ?? $item['url']); ?></a>
                                <small><?php echo esc_html($item['status_code'] ? 'HTTP ' . $item['status_code'] : ($item['message'] ?? 'Error')); ?></small>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php else: ?>
                        <p class="seopc-muted"><?php _e('No broken links detected in the saved scan.', 'seo-performance-checker'); ?></p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <h4><?php _e('External links', 'seo-performance-checker'); ?></h4>
                        <?php if (!empty($external_urls)): ?>
                        <ul class="seopc-link-list">
                            <?php foreach (array_slice($external_urls, 0, 4) as $item): ?>
                            <li>
                                <a href="<?php echo esc_url($item['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($item['anchor'] ?? $item['url']); ?></a>
                                <small><?php echo esc_html($item['url']); ?></small>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php else: ?>
                        <p class="seopc-muted"><?php _e('No external links found in the saved scan.', 'seo-performance-checker'); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="seopc-link-card-actions">
                    <button type="button" class="button button-secondary button-small seopc-view-results-btn" data-post-id="<?php echo esc_attr($check['post_id']); ?>" title="<?php echo esc_attr__('Open the full saved results again', 'seo-performance-checker'); ?>"><?php _e('View Results', 'seo-performance-checker'); ?></button>
                    <button type="button" class="button button-small seopc-run-row-retest" data-post-id="<?php echo esc_attr($check['post_id']); ?>"><?php _e('Test Again', 'seo-performance-checker'); ?></button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if (!empty($redirect_rows)): ?>
    <div class="seopc-section">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap;">
            <div>
                <h2><?php _e('Needed Redirects', 'seo-performance-checker'); ?></h2>
                <p><?php _e('Suggested redirect targets based on broken internal links found in the latest saved scans.', 'seo-performance-checker'); ?></p>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="seopc_export_redirects_csv">
                <?php wp_nonce_field('seopc_export_redirects_csv'); ?>
                <button type="submit" class="button button-secondary">
                    <span class="dashicons dashicons-media-spreadsheet" style="margin-top:3px;"></span>
                    <?php _e('Export Redirects CSV', 'seo-performance-checker'); ?>
                </button>
            </form>
        </div>

        <table class="seopc-table seopc-history-table">
            <thead>
                <tr>
                    <th><?php _e('Source Item', 'seo-performance-checker'); ?></th>
                    <th><?php _e('Broken URL', 'seo-performance-checker'); ?></th>
                    <th><?php _e('Suggested Redirect Target', 'seo-performance-checker'); ?></th>
                    <th><?php _e('Confidence', 'seo-performance-checker'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($redirect_rows, 0, 25) as $redirect_row):
                    $source_slug = $redirect_row['slug'] ? '/' . ltrim($redirect_row['slug'], '/') . '/' : '/';
                    $target_slug = $redirect_row['to_slug'] ? '/' . ltrim($redirect_row['to_slug'], '/') . '/' : '/';
                ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html($redirect_row['post_title']); ?></strong><br>
                        <code><?php echo esc_html($source_slug); ?></code>
                    </td>
                    <td>
                        <a href="<?php echo esc_url($redirect_row['from_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($redirect_row['from_path'] ?: $redirect_row['from_url']); ?></a>
                        <?php if (!empty($redirect_row['anchor'])): ?><br><small><?php echo esc_html($redirect_row['anchor']); ?></small><?php endif; ?>
                    </td>
                    <td>
                        <strong><?php echo esc_html($redirect_row['to_title'] ?: __('No suggestion', 'seo-performance-checker')); ?></strong>
                        <?php if (!empty($redirect_row['to_url'])): ?><br><a href="<?php echo esc_url($redirect_row['to_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($target_slug); ?></a><?php endif; ?>
                        <?php if (!empty($redirect_row['reason'])): ?><br><small><?php echo esc_html($redirect_row['reason']); ?></small><?php endif; ?>
                    </td>
                    <td>
                        <span class="seopc-score-badge <?php echo esc_attr(($redirect_row['confidence_label'] === 'high') ? 'good' : (($redirect_row['confidence_label'] === 'medium') ? 'warning' : 'critical')); ?>">
                            <?php echo esc_html(ucfirst($redirect_row['confidence_label'] ?: 'low')); ?>
                        </span>
                        <?php if ($redirect_row['match_score'] !== ''): ?><br><small><?php echo intval($redirect_row['match_score']); ?>% match</small><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div>
<div id="seopc-results-modal" class="seopc-modal" style="display:none;">
    <div class="seopc-modal-backdrop"></div>
    <div class="seopc-modal-dialog">
        <div class="seopc-modal-header">
            <h2><?php _e('Saved Results', 'seo-performance-checker'); ?></h2>
            <button type="button" class="button-link seopc-modal-close" aria-label="<?php esc_attr_e('Close', 'seo-performance-checker'); ?>">&times;</button>
        </div>
        <div class="seopc-modal-body"></div>
    </div>
</div>
