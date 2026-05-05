<?php

class SEOPC_Google_Integrations {

    const OPTION_KEY = 'seopc_google_settings';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public function __construct() {
        add_action('admin_init', [$this, 'handle_oauth_callback']);
        add_action('admin_post_seopc_google_connect', [$this, 'handle_connect']);
        add_action('admin_post_seopc_google_disconnect', [$this, 'handle_disconnect']);
        add_action('admin_post_seopc_google_save_settings', [$this, 'handle_save_settings']);
        add_action('admin_post_seopc_google_select_property', [$this, 'handle_select_property']);
    }

    public static function get_settings() {
        $settings = get_option(self::OPTION_KEY, []);
        return wp_parse_args($settings, [
            'client_id' => '',
            'client_secret' => '',
            'access_token' => '',
            'refresh_token' => '',
            'token_expires' => 0,
            'selected_property' => '',
            'connected_email' => ''
        ]);
    }

    public static function get_redirect_uri() {
        return admin_url('options-general.php?page=seo-performance&seopc_google_callback=1');
    }

    public function handle_save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'seo-performance-checker'));
        }
        check_admin_referer('seopc_google_save_settings');

        $settings = self::get_settings();
        $settings['client_id'] = sanitize_text_field(wp_unslash($_POST['client_id'] ?? ''));
        $settings['client_secret'] = sanitize_text_field(wp_unslash($_POST['client_secret'] ?? ''));
        update_option(self::OPTION_KEY, $settings);

        wp_safe_redirect(add_query_arg([
            'page' => 'seo-performance',
            'google_settings_saved' => '1'
        ], admin_url('options-general.php')));
        exit;
    }

    public function handle_connect() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'seo-performance-checker'));
        }
        check_admin_referer('seopc_google_connect');

        $settings = self::get_settings();
        if (empty($settings['client_id']) || empty($settings['client_secret'])) {
            wp_safe_redirect(add_query_arg([
                'page' => 'seo-performance',
                'google_error' => rawurlencode(__('Add your Google OAuth Client ID and Client Secret first.', 'seo-performance-checker'))
            ], admin_url('options-general.php')));
            exit;
        }

        $state = wp_create_nonce('seopc_google_oauth');
        update_option('seopc_google_oauth_state', $state);

        $params = [
            'client_id' => $settings['client_id'],
            'redirect_uri' => self::get_redirect_uri(),
            'response_type' => 'code',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'scope' => implode(' ', [
                'https://www.googleapis.com/auth/analytics.readonly',
                'openid',
                'email'
            ]),
            'state' => $state,
        ];

        wp_redirect(self::AUTH_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        exit;
    }

    public function handle_oauth_callback() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }
        if (empty($_GET['page']) || $_GET['page'] !== 'seo-performance' || empty($_GET['seopc_google_callback'])) {
            return;
        }

        if (!empty($_GET['error'])) {
            wp_safe_redirect(add_query_arg([
                'page' => 'seo-performance',
                'google_error' => rawurlencode(sanitize_text_field(wp_unslash($_GET['error'])))
            ], admin_url('options-general.php')));
            exit;
        }

        $code = sanitize_text_field(wp_unslash($_GET['code'] ?? ''));
        $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        $saved_state = sanitize_text_field((string) get_option('seopc_google_oauth_state', ''));

        if (!$code || !$state || !$saved_state || !hash_equals($saved_state, $state)) {
            wp_safe_redirect(add_query_arg([
                'page' => 'seo-performance',
                'google_error' => rawurlencode(__('Google authorization failed: invalid state or code.', 'seo-performance-checker'))
            ], admin_url('options-general.php')));
            exit;
        }

        delete_option('seopc_google_oauth_state');
        $settings = self::get_settings();
        $response = wp_remote_post(self::TOKEN_URL, [
            'timeout' => 20,
            'body' => [
                'code' => $code,
                'client_id' => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
                'redirect_uri' => self::get_redirect_uri(),
                'grant_type' => 'authorization_code',
            ],
        ]);

        if (is_wp_error($response)) {
            wp_safe_redirect(add_query_arg([
                'page' => 'seo-performance',
                'google_error' => rawurlencode($response->get_error_message())
            ], admin_url('options-general.php')));
            exit;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['access_token'])) {
            wp_safe_redirect(add_query_arg([
                'page' => 'seo-performance',
                'google_error' => rawurlencode($data['error_description'] ?? __('Token exchange failed.', 'seo-performance-checker'))
            ], admin_url('options-general.php')));
            exit;
        }

        $settings['access_token'] = sanitize_text_field($data['access_token']);
        if (!empty($data['refresh_token'])) {
            $settings['refresh_token'] = sanitize_text_field($data['refresh_token']);
        }
        $settings['token_expires'] = time() + max(60, intval($data['expires_in'] ?? 3600)) - 60;

        $user = $this->api_get('https://www.googleapis.com/oauth2/v2/userinfo', [], $settings['access_token']);
        if (!is_wp_error($user) && !empty($user['email'])) {
            $settings['connected_email'] = sanitize_email($user['email']);
        }

        update_option(self::OPTION_KEY, $settings);

        wp_safe_redirect(add_query_arg([
            'page' => 'seo-performance',
            'google_connected' => '1'
        ], admin_url('options-general.php')));
        exit;
    }

    public function handle_disconnect() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'seo-performance-checker'));
        }
        check_admin_referer('seopc_google_disconnect');

        $settings = self::get_settings();
        $settings['access_token'] = '';
        $settings['refresh_token'] = '';
        $settings['token_expires'] = 0;
        $settings['selected_property'] = '';
        $settings['connected_email'] = '';
        update_option(self::OPTION_KEY, $settings);

        wp_safe_redirect(add_query_arg([
            'page' => 'seo-performance',
            'google_disconnected' => '1'
        ], admin_url('options-general.php')));
        exit;
    }

    public function handle_select_property() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'seo-performance-checker'));
        }
        check_admin_referer('seopc_google_select_property');

        $settings = self::get_settings();
        $settings['selected_property'] = sanitize_text_field(wp_unslash($_POST['selected_property'] ?? ''));
        update_option(self::OPTION_KEY, $settings);

        wp_safe_redirect(add_query_arg([
            'page' => 'seo-performance',
            'google_selection_saved' => '1'
        ], admin_url('options-general.php')));
        exit;
    }

    public function is_connected() {
        $settings = self::get_settings();
        return !empty($settings['refresh_token']) || (!empty($settings['access_token']) && intval($settings['token_expires']) > time());
    }

    public function get_access_token() {
        $settings = self::get_settings();
        if (!empty($settings['access_token']) && intval($settings['token_expires']) > time()) {
            return $settings['access_token'];
        }

        if (empty($settings['refresh_token']) || empty($settings['client_id']) || empty($settings['client_secret'])) {
            return new WP_Error('seopc_google_not_connected', __('Google account is not connected.', 'seo-performance-checker'));
        }

        $response = wp_remote_post(self::TOKEN_URL, [
            'timeout' => 20,
            'body' => [
                'client_id' => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
                'refresh_token' => $settings['refresh_token'],
                'grant_type' => 'refresh_token',
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['access_token'])) {
            return new WP_Error('seopc_google_refresh_failed', $data['error_description'] ?? __('Failed to refresh Google token.', 'seo-performance-checker'));
        }

        $settings['access_token'] = sanitize_text_field($data['access_token']);
        $settings['token_expires'] = time() + max(60, intval($data['expires_in'] ?? 3600)) - 60;
        update_option(self::OPTION_KEY, $settings);

        return $settings['access_token'];
    }

    private function api_get($url, $args = [], $token = '') {
        $token = $token ?: $this->get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $request = wp_remote_get(add_query_arg($args, $url), [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
        ]);

        return $this->decode_response($request);
    }

    private function api_post_json($url, $payload = []) {
        $token = $this->get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $request = wp_remote_post($url, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        return $this->decode_response($request);
    }

    private function decode_response($response) {
        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code >= 400) {
            $message = $body['error']['message'] ?? $body['error_description'] ?? __('Google API request failed.', 'seo-performance-checker');
            return new WP_Error('seopc_google_api_error', $message, ['status' => $code, 'body' => $body]);
        }

        return is_array($body) ? $body : [];
    }

    public function get_account_summaries() {
        return $this->api_get('https://analyticsadmin.googleapis.com/v1beta/accountSummaries');
    }

    public function get_search_console_sites() {
        $response = $this->api_get('https://www.googleapis.com/webmasters/v3/sites');
        if (is_wp_error($response)) {
            return $response;
        }

        return $response['siteEntry'] ?? [];
    }

    public function get_dashboard_data($args = []) {
        $settings = self::get_settings();
        $range = $this->resolve_date_range($args);

        $data = [
            'connected' => $this->is_connected(),
            'settings' => $settings,
            'date_range' => $range,
            'accounts' => [],
            'properties' => [],
            'analytics_overview' => [],
            'analytics_pages' => [],
            'analytics_trend' => [],
            'analytics_devices' => [],
            'analytics_countries' => [],
            'analytics_sources' => [],
            'analytics_post' => [],
            'analytics_insights' => [],
            'search_overview' => [],
            'search_queries' => [],
            'search_pages' => [],
            'search_trend' => [],
            'search_devices' => [],
            'search_countries' => [],
            'search_post' => [],
            'search_insights' => [],
            'tracked_keywords' => [],
            'page_opportunities' => [],
            'content_scores' => [],
            'errors' => [],
            'competitor_benchmarks' => [],
            'rewrite_suggestions' => [],
        ];

        if (!$data['connected']) {
            return $data;
        }

        $account_summaries = $this->get_account_summaries();
        if (is_wp_error($account_summaries)) {
            $data['errors'][] = $account_summaries->get_error_message();
        } else {
            $data['accounts'] = $account_summaries['accountSummaries'] ?? [];
            foreach ($data['accounts'] as $account) {
                foreach (($account['propertySummaries'] ?? []) as $property) {
                    $data['properties'][] = [
                        'account' => $account['displayName'] ?? ($account['name'] ?? ''),
                        'property' => $property['displayName'] ?? '',
                        'property_id' => str_replace('properties/', '', $property['property'] ?? ''),
                    ];
                }
            }
        }

        if (empty($settings['selected_property']) && !empty($data['properties'][0]['property_id'])) {
            $settings['selected_property'] = $data['properties'][0]['property_id'];
            update_option(self::OPTION_KEY, $settings);
        }
        $selected_property = $settings['selected_property'] ?: ($data['properties'][0]['property_id'] ?? '');
        $data['settings'] = self::get_settings();

        if ($selected_property) {
            $analytics = $this->get_analytics_overview($selected_property, $range);
            if (is_wp_error($analytics)) {
                $data['errors'][] = $analytics->get_error_message();
            } else {
                $data['analytics_overview'] = $analytics['overview'];
                $data['analytics_pages'] = $analytics['pages'];
                $data['analytics_trend'] = $analytics['trend'];
                $data['analytics_devices'] = $analytics['devices'];
                $data['analytics_countries'] = $analytics['countries'];
                $data['analytics_sources'] = $analytics['sources'];
                $data['analytics_insights'] = $analytics['insights'];
            }
        }

        $opportunity_engine = new SEOPC_Opportunity_Engine();
        $content_analyzer = new SEOPC_Content_Analyzer();
        $tracked_posts = SEOPC_Keyword_Tracker::get_tracked_posts(50);
        $competitor_benchmark = new SEOPC_Competitor_Benchmark();
        $rewrite_suggestions = new SEOPC_Rewrite_Suggestions();
        foreach ($tracked_posts as $tracked_post_id) {
            $keywords = SEOPC_Keyword_Tracker::get_keywords($tracked_post_id);
            $content_result = $content_analyzer->analyze_post($tracked_post_id, $keywords);
            $benchmark_result = $competitor_benchmark->analyze_post($tracked_post_id, 3);
            $rewrite_result = $rewrite_suggestions->build_for_post($tracked_post_id, [
                'keywords' => $keywords,
                'primary_keyword' => $content_result['primary_keyword'] ?? '',
                'content_score' => $content_result,
                'competitor' => $benchmark_result,
            ]);
            $data['content_scores'][] = [
                'post_id' => $tracked_post_id,
                'title' => get_the_title($tracked_post_id),
                'edit_url' => get_edit_post_link($tracked_post_id, ''),
                'score' => $content_result['score'] ?? 0,
                'primary_keyword' => $content_result['primary_keyword'] ?? '',
            ];
            if (!empty($benchmark_result['competitors'])) {
                $data['competitor_benchmarks'][] = [
                    'post_id' => $tracked_post_id,
                    'title' => get_the_title($tracked_post_id),
                    'edit_url' => get_edit_post_link($tracked_post_id, ''),
                    'summary' => $benchmark_result['summary'],
                    'suggestions' => $benchmark_result['suggestions'],
                    'competitor_count' => count($benchmark_result['competitors']),
                ];
            }
            $data['rewrite_suggestions'][] = [
                'post_id' => $tracked_post_id,
                'title' => get_the_title($tracked_post_id),
                'edit_url' => get_edit_post_link($tracked_post_id, ''),
                'recommended_title' => $rewrite_result['recommended_title'] ?? '',
                'recommended_description' => $rewrite_result['recommended_description'] ?? '',
                'why' => $rewrite_result['why'] ?? [],
            ];
        }

        if (!empty($data['search_pages'])) {
            $data['page_opportunities'] = $opportunity_engine->build_page_opportunities($data['search_pages'], 10);
        }

        if (!empty($data['content_scores'])) {
            usort($data['content_scores'], function ($a, $b) {
                return ($a['score'] ?? 0) <=> ($b['score'] ?? 0);
            });
            $data['content_scores'] = array_slice($data['content_scores'], 0, 10);
        }

        if (!empty($data['tracked_keywords'])) {
            usort($data['tracked_keywords'], function ($a, $b) {
                return ($b['impressions'] ?? 0) <=> ($a['impressions'] ?? 0);
            });
            $data['tracked_keywords'] = array_slice($data['tracked_keywords'], 0, 15);
        }
        if (!empty($data['rewrite_suggestions'])) {
            usort($data['rewrite_suggestions'], function ($a, $b) {
                return strcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
            });
            $data['rewrite_suggestions'] = array_slice($data['rewrite_suggestions'], 0, 10);
        }
        if (!empty($data['competitor_benchmarks'])) {
            $data['competitor_benchmarks'] = array_slice($data['competitor_benchmarks'], 0, 10);
        }

        return $data;
    }

    private function resolve_date_range($args = []) {
        $preset = sanitize_key($args['preset'] ?? ($_GET['seopc_range'] ?? 'last_28_days'));
        $start_raw = sanitize_text_field(wp_unslash($args['start_date'] ?? ($_GET['seopc_start_date'] ?? '')));
        $end_raw = sanitize_text_field(wp_unslash($args['end_date'] ?? ($_GET['seopc_end_date'] ?? '')));

        $today = current_time('timestamp');
        $yesterday = strtotime('-1 day', $today);
        $end = $yesterday;
        $start = strtotime('-27 days', $yesterday);
        $label = __('Last 28 days', 'seo-performance-checker');

        switch ($preset) {
            case 'last_7_days':
                $start = strtotime('-6 days', $yesterday);
                $label = __('Last 7 days', 'seo-performance-checker');
                break;
            case 'last_30_days':
                $start = strtotime('-29 days', $yesterday);
                $label = __('Last 30 days', 'seo-performance-checker');
                break;
            case 'last_90_days':
                $start = strtotime('-89 days', $yesterday);
                $label = __('Last 90 days', 'seo-performance-checker');
                break;
            case 'month_to_date':
                $end = $yesterday;
                $start = strtotime(date_i18n('Y-m-01', $end));
                $label = __('Month to date', 'seo-performance-checker');
                break;
            case 'last_month':
                $start = strtotime('first day of last month 00:00:00', $today);
                $end = strtotime('last day of last month 00:00:00', $today);
                $label = __('Last month', 'seo-performance-checker');
                break;
            case 'custom':
                $start_candidate = $start_raw ? strtotime($start_raw . ' 00:00:00') : false;
                $end_candidate = $end_raw ? strtotime($end_raw . ' 00:00:00') : false;
                if ($start_candidate && $end_candidate) {
                    $start = $start_candidate;
                    $end = min($end_candidate, $yesterday);
                    $label = __('Custom range', 'seo-performance-checker');
                } else {
                    $preset = 'last_28_days';
                }
                break;
            case 'last_28_days':
            default:
                $preset = 'last_28_days';
                $start = strtotime('-27 days', $yesterday);
                $label = __('Last 28 days', 'seo-performance-checker');
                break;
        }

        if ($start > $end) {
            $start = strtotime('-27 days', $end);
        }

        $days = max(1, (int) floor(($end - $start) / DAY_IN_SECONDS) + 1);
        $previous_end = strtotime('-1 day', $start);
        $previous_start = strtotime('-' . ($days - 1) . ' days', $previous_end);

        return [
            'preset' => $preset,
            'label' => $label,
            'start_date' => gmdate('Y-m-d', $start),
            'end_date' => gmdate('Y-m-d', $end),
            'days' => $days,
            'previous_start_date' => gmdate('Y-m-d', $previous_start),
            'previous_end_date' => gmdate('Y-m-d', $previous_end),
            'comparison_label' => sprintf(
                __('Compared with %1$s to %2$s', 'seo-performance-checker'),
                gmdate('M j, Y', $previous_start),
                gmdate('M j, Y', $previous_end)
            ),
        ];
    }

    public function get_analytics_overview($property_id, $range) {
        $base = 'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode($property_id) . ':runReport';
        $metrics = [
            ['name' => 'activeUsers'],
            ['name' => 'sessions'],
            ['name' => 'screenPageViews'],
            ['name' => 'bounceRate'],
            ['name' => 'averageSessionDuration'],
        ];

        $overview = $this->api_post_json($base, [
            'dateRanges' => [[
                'startDate' => $range['start_date'],
                'endDate' => $range['end_date'],
            ]],
            'metrics' => $metrics,
        ]);
        if (is_wp_error($overview)) {
            return $overview;
        }

        $overview_previous = $this->api_post_json($base, [
            'dateRanges' => [[
                'startDate' => $range['previous_start_date'],
                'endDate' => $range['previous_end_date'],
            ]],
            'metrics' => $metrics,
        ]);
        if (is_wp_error($overview_previous)) {
            return $overview_previous;
        }

        $pages = $this->api_post_json($base, [
            'dateRanges' => [[
                'startDate' => $range['start_date'],
                'endDate' => $range['end_date'],
            ]],
            'dimensions' => [['name' => 'pagePath']],
            'metrics' => [
                ['name' => 'screenPageViews'],
                ['name' => 'sessions'],
                ['name' => 'activeUsers'],
            ],
            'orderBys' => [[
                'metric' => ['metricName' => 'screenPageViews'],
                'desc' => true,
            ]],
            'limit' => 10,
        ]);
        if (is_wp_error($pages)) {
            return $pages;
        }

        $devices = $this->api_post_json($base, [
            'dateRanges' => [[
                'startDate' => $range['start_date'],
                'endDate' => $range['end_date'],
            ]],
            'dimensions' => [['name' => 'deviceCategory']],
            'metrics' => [
                ['name' => 'activeUsers'],
                ['name' => 'sessions'],
                ['name' => 'screenPageViews'],
            ],
            'orderBys' => [[
                'metric' => ['metricName' => 'screenPageViews'],
                'desc' => true,
            ]],
            'limit' => 10,
        ]);
        if (is_wp_error($devices)) {
            return $devices;
        }

        $countries = $this->api_post_json($base, [
            'dateRanges' => [[
                'startDate' => $range['start_date'],
                'endDate' => $range['end_date'],
            ]],
            'dimensions' => [['name' => 'country']],
            'metrics' => [
                ['name' => 'activeUsers'],
                ['name' => 'sessions'],
                ['name' => 'screenPageViews'],
            ],
            'orderBys' => [[
                'metric' => ['metricName' => 'screenPageViews'],
                'desc' => true,
            ]],
            'limit' => 10,
        ]);
        if (is_wp_error($countries)) {
            return $countries;
        }

        $sources = $this->api_post_json($base, [
            'dateRanges' => [[
                'startDate' => $range['start_date'],
                'endDate' => $range['end_date'],
            ]],
            'dimensions' => [
                ['name' => 'sessionSource'],
                ['name' => 'sessionMedium'],
            ],
            'metrics' => [
                ['name' => 'sessions'],
                ['name' => 'activeUsers'],
                ['name' => 'screenPageViews'],
            ],
            'orderBys' => [[
                'metric' => ['metricName' => 'sessions'],
                'desc' => true,
            ]],
            'limit' => 10,
        ]);
        if (is_wp_error($sources)) {
            return $sources;
        }

        $trend = $this->api_post_json($base, [
            'dateRanges' => [[
                'startDate' => $range['start_date'],
                'endDate' => $range['end_date'],
            ]],
            'dimensions' => [['name' => 'date']],
            'metrics' => [
                ['name' => 'activeUsers'],
                ['name' => 'sessions'],
                ['name' => 'screenPageViews'],
            ],
            'orderBys' => [[
                'dimension' => ['dimensionName' => 'date'],
            ]],
            'limit' => max(31, (int) $range['days'] + 5),
        ]);
        if (is_wp_error($trend)) {
            return $trend;
        }

        $current_values = $this->map_metric_response($overview);
        $previous_values = $this->map_metric_response($overview_previous);

        $overview_values = [];
        foreach ($current_values as $metric_name => $value) {
            $overview_values[$metric_name] = [
                'value' => $value,
                'previous_value' => $previous_values[$metric_name] ?? 0,
                'delta_percent' => $this->calculate_delta_percent($value, $previous_values[$metric_name] ?? 0),
            ];
        }

        $page_rows = [];
        foreach (($pages['rows'] ?? []) as $row) {
            $page_rows[] = [
                'page' => $row['dimensionValues'][0]['value'] ?? '/',
                'pageViews' => $row['metricValues'][0]['value'] ?? '0',
                'sessions' => $row['metricValues'][1]['value'] ?? '0',
                'users' => $row['metricValues'][2]['value'] ?? '0',
            ];
        }

        $trend_rows = [];
        foreach (($trend['rows'] ?? []) as $row) {
            $date_key = $row['dimensionValues'][0]['value'] ?? '';
            $trend_rows[] = [
                'date' => $this->format_ga_date($date_key),
                'activeUsers' => (float) ($row['metricValues'][0]['value'] ?? 0),
                'sessions' => (float) ($row['metricValues'][1]['value'] ?? 0),
                'screenPageViews' => (float) ($row['metricValues'][2]['value'] ?? 0),
            ];
        }

        $device_rows = $this->map_ga_dimension_rows($devices['rows'] ?? [], ['device', 'users', 'sessions', 'pageViews']);
        $country_rows = $this->map_ga_dimension_rows($countries['rows'] ?? [], ['country', 'users', 'sessions', 'pageViews']);
        $source_rows = [];
        foreach (($sources['rows'] ?? []) as $row) {
            $source_rows[] = [
                'source' => $row['dimensionValues'][0]['value'] ?? '',
                'medium' => $row['dimensionValues'][1]['value'] ?? '',
                'sessions' => (float) ($row['metricValues'][0]['value'] ?? 0),
                'users' => (float) ($row['metricValues'][1]['value'] ?? 0),
                'pageViews' => (float) ($row['metricValues'][2]['value'] ?? 0),
            ];
        }

        return [
            'overview' => $overview_values,
            'pages' => $page_rows,
            'trend' => $trend_rows,
            'devices' => $device_rows,
            'countries' => $country_rows,
            'sources' => $source_rows,
            'insights' => $this->build_analytics_insights($overview_values, $page_rows, $range),
        ];
    }

    public function get_search_console_overview($site_url, $range) {
        $encoded_site = rawurlencode($site_url);
        $endpoint = 'https://www.googleapis.com/webmasters/v3/sites/' . $encoded_site . '/searchAnalytics/query';

        $overview = $this->api_post_json($endpoint, [
            'startDate' => $range['start_date'],
            'endDate' => $range['end_date'],
            'dataState' => 'final',
        ]);
        if (is_wp_error($overview)) {
            return $overview;
        }

        $overview_previous = $this->api_post_json($endpoint, [
            'startDate' => $range['previous_start_date'],
            'endDate' => $range['previous_end_date'],
            'dataState' => 'final',
        ]);
        if (is_wp_error($overview_previous)) {
            return $overview_previous;
        }

        $queries = $this->api_post_json($endpoint, [
            'startDate' => $range['start_date'],
            'endDate' => $range['end_date'],
            'dimensions' => ['query'],
            'rowLimit' => 10,
            'dataState' => 'final',
        ]);
        if (is_wp_error($queries)) {
            return $queries;
        }

        $pages = $this->api_post_json($endpoint, [
            'startDate' => $range['start_date'],
            'endDate' => $range['end_date'],
            'dimensions' => ['page'],
            'rowLimit' => 10,
            'dataState' => 'final',
        ]);
        if (is_wp_error($pages)) {
            return $pages;
        }

        $trend = $this->api_post_json($endpoint, [
            'startDate' => $range['start_date'],
            'endDate' => $range['end_date'],
            'dimensions' => ['date'],
            'rowLimit' => max(31, (int) $range['days'] + 5),
            'dataState' => 'final',
        ]);
        if (is_wp_error($trend)) {
            return $trend;
        }

        $devices = $this->api_post_json($endpoint, [
            'startDate' => $range['start_date'],
            'endDate' => $range['end_date'],
            'dimensions' => ['device'],
            'rowLimit' => 10,
            'dataState' => 'final',
        ]);
        if (is_wp_error($devices)) {
            return $devices;
        }

        $countries = $this->api_post_json($endpoint, [
            'startDate' => $range['start_date'],
            'endDate' => $range['end_date'],
            'dimensions' => ['country'],
            'rowLimit' => 10,
            'dataState' => 'final',
        ]);
        if (is_wp_error($countries)) {
            return $countries;
        }

        $current_values = $this->extract_search_overview_values($overview);
        $previous_values = $this->extract_search_overview_values($overview_previous);

        $overview_values = [];
        foreach ($current_values as $metric_name => $value) {
            $overview_values[$metric_name] = [
                'value' => $value,
                'previous_value' => $previous_values[$metric_name] ?? 0,
                'delta_percent' => $this->calculate_delta_percent($value, $previous_values[$metric_name] ?? 0),
            ];
        }

        $query_rows = [];
        foreach (($queries['rows'] ?? []) as $row) {
            $query_rows[] = [
                'query' => $row['keys'][0] ?? '',
                'clicks' => (float) ($row['clicks'] ?? 0),
                'impressions' => (float) ($row['impressions'] ?? 0),
                'ctr' => (float) ($row['ctr'] ?? 0),
                'position' => (float) ($row['position'] ?? 0),
            ];
        }

        $page_rows = [];
        foreach (($pages['rows'] ?? []) as $row) {
            $page_rows[] = [
                'page' => $row['keys'][0] ?? '',
                'clicks' => (float) ($row['clicks'] ?? 0),
                'impressions' => (float) ($row['impressions'] ?? 0),
                'ctr' => (float) ($row['ctr'] ?? 0),
                'position' => (float) ($row['position'] ?? 0),
            ];
        }

        $trend_rows = [];
        foreach (($trend['rows'] ?? []) as $row) {
            $trend_rows[] = [
                'date' => $row['keys'][0] ?? '',
                'clicks' => (float) ($row['clicks'] ?? 0),
                'impressions' => (float) ($row['impressions'] ?? 0),
                'ctr' => round(((float) ($row['ctr'] ?? 0)) * 100, 2),
                'position' => (float) ($row['position'] ?? 0),
            ];
        }

        $device_rows = [];
        foreach (($devices['rows'] ?? []) as $row) {
            $device_rows[] = [
                'device' => $row['keys'][0] ?? '',
                'clicks' => (float) ($row['clicks'] ?? 0),
                'impressions' => (float) ($row['impressions'] ?? 0),
                'ctr' => (float) ($row['ctr'] ?? 0),
                'position' => (float) ($row['position'] ?? 0),
            ];
        }

        $country_rows = [];
        foreach (($countries['rows'] ?? []) as $row) {
            $country_rows[] = [
                'country' => $row['keys'][0] ?? '',
                'clicks' => (float) ($row['clicks'] ?? 0),
                'impressions' => (float) ($row['impressions'] ?? 0),
                'ctr' => (float) ($row['ctr'] ?? 0),
                'position' => (float) ($row['position'] ?? 0),
            ];
        }

        return [
            'overview' => $overview_values,
            'queries' => $query_rows,
            'pages' => $page_rows,
            'trend' => $trend_rows,
            'devices' => $device_rows,
            'countries' => $country_rows,
            'insights' => $this->build_search_insights($overview_values, $query_rows, $page_rows, $range),
        ];
    }


    private function map_ga_dimension_rows($rows, $keys = []) {
        $mapped = [];
        foreach ($rows as $row) {
            $item = [];
            if (!empty($keys[0])) {
                $item[$keys[0]] = $row['dimensionValues'][0]['value'] ?? '';
            }
            if (!empty($keys[1])) {
                $item[$keys[1]] = (float) ($row['metricValues'][0]['value'] ?? 0);
            }
            if (!empty($keys[2])) {
                $item[$keys[2]] = (float) ($row['metricValues'][1]['value'] ?? 0);
            }
            if (!empty($keys[3])) {
                $item[$keys[3]] = (float) ($row['metricValues'][2]['value'] ?? 0);
            }
            $mapped[] = $item;
        }
        return $mapped;
    }

    public function get_post_dashboard_data($post_id, $args = []) {
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }

        $settings = self::get_settings();
        if (empty($settings['selected_property']) || !$this->is_connected()) {
            return [
                'connected' => false,
                'message' => __('Connect Google Analytics first.', 'seo-performance-checker'),
            ];
        }

        $range = $this->resolve_date_range($args);
        $post_url = get_permalink($post_id);
        $page_path = wp_parse_url($post_url, PHP_URL_PATH);
        if (!$page_path) {
            $page_path = '/';
        }

        $analytics = $this->get_post_analytics_summary($settings['selected_property'], $range, $page_path);
        $search = [];

        $keyword_tracker = new SEOPC_Keyword_Tracker();
        $content_analyzer = new SEOPC_Content_Analyzer();
        $internal_links = new SEOPC_Internal_Links();
        $competitor_benchmark = new SEOPC_Competitor_Benchmark();
        $rewrite_suggestions = new SEOPC_Rewrite_Suggestions();
        $keywords = SEOPC_Keyword_Tracker::get_keywords($post_id);
        $keyword_metrics = [];
        $analytics_trend = $this->get_post_analytics_trend($settings['selected_property'], $range, $page_path);
        $search_trend = [];

        $content_score = $content_analyzer->analyze_post($post_id, $keywords);
        $competitor = $competitor_benchmark->analyze_post($post_id, 3);
        $rewrite = $rewrite_suggestions->build_for_post($post_id, [
            'keywords' => $keywords,
            'primary_keyword' => $content_score['primary_keyword'] ?? '',
            'content_score' => $content_score,
            'search' => is_wp_error($search) ? [] : $search,
            'competitor' => $competitor,
        ]);

        return [
            'connected' => true,
            'date_range' => $range,
            'post_url' => $post_url,
            'page_path' => $page_path,
            'analytics' => is_wp_error($analytics) ? [] : $analytics,
            'search' => is_wp_error($search) ? [] : $search,
            'keywords' => $keywords,
            'keyword_metrics' => is_wp_error($keyword_metrics) ? [] : $keyword_metrics,
            'content_score' => $content_score,
            'internal_link_suggestions' => $internal_links->suggest_for_post($post_id, 4),
            'competitor_benchmark' => $competitor,
            'rewrite_suggestions' => $rewrite,
            'analytics_trend' => is_wp_error($analytics_trend) ? [] : $analytics_trend,
            'search_trend' => is_wp_error($search_trend) ? [] : $search_trend,
            'errors' => array_values(array_filter([
                is_wp_error($analytics) ? $analytics->get_error_message() : '',
                is_wp_error($search) ? $search->get_error_message() : '',
                is_wp_error($keyword_metrics) ? $keyword_metrics->get_error_message() : '',
                is_wp_error($analytics_trend) ? $analytics_trend->get_error_message() : '',
                is_wp_error($search_trend) ? $search_trend->get_error_message() : '',
            ])),
        ];
    }

    private function get_post_analytics_summary($property_id, $range, $page_path) {
        $base = 'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode($property_id) . ':runReport';
        $metrics = [
            ['name' => 'screenPageViews'],
            ['name' => 'sessions'],
            ['name' => 'activeUsers'],
            ['name' => 'bounceRate'],
        ];
        $filter = [
            'filter' => [
                'fieldName' => 'pagePath',
                'stringFilter' => [
                    'matchType' => 'EXACT',
                    'value' => $page_path,
                ],
            ],
        ];

        $current = $this->api_post_json($base, [
            'dateRanges' => [[ 'startDate' => $range['start_date'], 'endDate' => $range['end_date'] ]],
            'metrics' => $metrics,
            'dimensionFilter' => $filter,
        ]);
        if (is_wp_error($current)) {
            return $current;
        }

        $previous = $this->api_post_json($base, [
            'dateRanges' => [[ 'startDate' => $range['previous_start_date'], 'endDate' => $range['previous_end_date'] ]],
            'metrics' => $metrics,
            'dimensionFilter' => $filter,
        ]);
        if (is_wp_error($previous)) {
            return $previous;
        }

        $current_values = $this->map_metric_response($current);
        $previous_values = $this->map_metric_response($previous);
        $output = [];
        foreach ($current_values as $metric_name => $value) {
            $output[$metric_name] = [
                'value' => $value,
                'previous_value' => $previous_values[$metric_name] ?? 0,
                'delta_percent' => $this->calculate_delta_percent($value, $previous_values[$metric_name] ?? 0),
            ];
        }
        return $output;
    }

    private function get_post_search_console_summary($site_url, $range, $post_url) {
        $encoded_site = rawurlencode($site_url);
        $endpoint = 'https://www.googleapis.com/webmasters/v3/sites/' . $encoded_site . '/searchAnalytics/query';
        $payload = [
            'dimensions' => ['page'],
            'dimensionFilterGroups' => [[
                'filters' => [[
                    'dimension' => 'page',
                    'operator' => 'equals',
                    'expression' => $post_url,
                ]],
            ]],
            'dataState' => 'final',
        ];
        $current = $this->api_post_json($endpoint, array_merge($payload, [
            'startDate' => $range['start_date'],
            'endDate' => $range['end_date'],
        ]));
        if (is_wp_error($current)) {
            return $current;
        }
        $previous = $this->api_post_json($endpoint, array_merge($payload, [
            'startDate' => $range['previous_start_date'],
            'endDate' => $range['previous_end_date'],
        ]));
        if (is_wp_error($previous)) {
            return $previous;
        }
        $current_values = $this->extract_search_overview_values($current);
        $previous_values = $this->extract_search_overview_values($previous);
        $output = [];
        foreach ($current_values as $metric_name => $value) {
            $output[$metric_name] = [
                'value' => $value,
                'previous_value' => $previous_values[$metric_name] ?? 0,
                'delta_percent' => $this->calculate_delta_percent($value, $previous_values[$metric_name] ?? 0),
            ];
        }
        return $output;
    }

    private function map_metric_response($response) {
        $values = [];
        $headers = $response['metricHeaders'] ?? [];
        $row = $response['rows'][0]['metricValues'] ?? [];
        foreach ($headers as $index => $header) {
            $name = $header['name'] ?? ('metric_' . $index);
            $values[$name] = (float) ($row[$index]['value'] ?? 0);
        }
        return $values;
    }

    private function extract_search_overview_values($response) {
        $defaults = [
            'clicks' => 0,
            'impressions' => 0,
            'ctr' => 0,
            'position' => 0,
        ];
        if (empty($response['rows'][0])) {
            return $defaults;
        }

        $row = $response['rows'][0];
        return [
            'clicks' => (float) ($row['clicks'] ?? 0),
            'impressions' => (float) ($row['impressions'] ?? 0),
            'ctr' => (float) ($row['ctr'] ?? 0),
            'position' => (float) ($row['position'] ?? 0),
        ];
    }

    private function calculate_delta_percent($current, $previous) {
        $current = (float) $current;
        $previous = (float) $previous;

        if ($previous == 0.0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }

    private function format_ga_date($date_key) {
        if (!$date_key || strlen($date_key) !== 8) {
            return $date_key;
        }

        $year = substr($date_key, 0, 4);
        $month = substr($date_key, 4, 2);
        $day = substr($date_key, 6, 2);
        return $year . '-' . $month . '-' . $day;
    }

    private function build_analytics_insights($overview, $pages, $range) {
        $insights = [];
        $sessions_delta = $overview['sessions']['delta_percent'] ?? 0;
        $views_delta = $overview['screenPageViews']['delta_percent'] ?? 0;
        $bounce_delta = $overview['bounceRate']['delta_percent'] ?? 0;

        $insights[] = [
            'title' => __('Traffic trend', 'seo-performance-checker'),
            'text' => $sessions_delta >= 0
                ? sprintf(__('Sessions are up %s%% versus the previous comparison window.', 'seo-performance-checker'), round($sessions_delta, 2))
                : sprintf(__('Sessions are down %s%% versus the previous comparison window.', 'seo-performance-checker'), abs(round($sessions_delta, 2))),
        ];

        $insights[] = [
            'title' => __('Engagement signal', 'seo-performance-checker'),
            'text' => $bounce_delta <= 0
                ? __('Bounce rate improved or stayed steady, which is a positive engagement signal.', 'seo-performance-checker')
                : __('Bounce rate increased, so review landing pages and internal link depth.', 'seo-performance-checker'),
        ];

        if (!empty($pages[0])) {
            $insights[] = [
                'title' => __('Top page in range', 'seo-performance-checker'),
                'text' => sprintf(
                    __('%1$s generated %2$s page views in %3$s.', 'seo-performance-checker'),
                    $pages[0]['page'],
                    number_format_i18n((float) $pages[0]['pageViews']),
                    $range['label']
                ),
            ];
        }

        if ($views_delta > 0 && !empty($pages[0]['page'])) {
            $insights[] = [
                'title' => __('Opportunity', 'seo-performance-checker'),
                'text' => __('Your traffic is rising. Re-run SEO checks on the top GA pages first to convert that demand more efficiently.', 'seo-performance-checker'),
            ];
        }

        return array_slice($insights, 0, 4);
    }

    private function build_search_insights($overview, $queries, $pages, $range) {
        $insights = [];
        $clicks_delta = $overview['clicks']['delta_percent'] ?? 0;
        $impressions_delta = $overview['impressions']['delta_percent'] ?? 0;
        $ctr_delta = $overview['ctr']['delta_percent'] ?? 0;

        $insights[] = [
            'title' => __('Search visibility trend', 'seo-performance-checker'),
            'text' => $impressions_delta >= 0
                ? sprintf(__('Impressions are up %s%% versus the previous comparison window.', 'seo-performance-checker'), round($impressions_delta, 2))
                : sprintf(__('Impressions are down %s%% versus the previous comparison window.', 'seo-performance-checker'), abs(round($impressions_delta, 2))),
        ];

        if (!empty($queries[0])) {
            $top_query = $queries[0];
            $insights[] = [
                'title' => __('Top query', 'seo-performance-checker'),
                'text' => sprintf(
                    __('"%1$s" brought %2$s clicks and %3$s impressions in %4$s.', 'seo-performance-checker'),
                    $top_query['query'],
                    number_format_i18n((float) $top_query['clicks']),
                    number_format_i18n((float) $top_query['impressions']),
                    $range['label']
                ),
            ];
        }

        if (!empty($pages[0]) && (float) $pages[0]['impressions'] > 0 && (float) $pages[0]['ctr'] < 0.03) {
            $insights[] = [
                'title' => __('CTR opportunity', 'seo-performance-checker'),
                'text' => __('Your top search page has strong visibility but a low CTR. Improve title tags, meta descriptions, and rich-result markup first.', 'seo-performance-checker'),
            ];
        } else {
            $insights[] = [
                'title' => __('Click-through trend', 'seo-performance-checker'),
                'text' => $ctr_delta >= 0
                    ? __('CTR improved or held steady, which suggests your snippets are staying competitive.', 'seo-performance-checker')
                    : __('CTR dropped. Review SERP titles, descriptions, and schema-rich snippets on key landing pages.', 'seo-performance-checker'),
            ];
        }

        if ($clicks_delta > 0) {
            $insights[] = [
                'title' => __('Action suggestion', 'seo-performance-checker'),
                'text' => __('Use rising Search Console pages as your priority queue for on-page optimization and image improvements.', 'seo-performance-checker'),
            ];
        }

        return array_slice($insights, 0, 4);
    }

    private function get_post_keyword_metrics($site_url, $range, $post_url, $keywords) {
        if (empty($keywords)) {
            return [];
        }
        $encoded_site = rawurlencode($site_url);
        $endpoint = 'https://www.googleapis.com/webmasters/v3/sites/' . $encoded_site . '/searchAnalytics/query';
        $rows = [];
        foreach (array_slice($keywords, 0, 10) as $keyword) {
            $response = $this->api_post_json($endpoint, [
                'startDate' => $range['start_date'],
                'endDate' => $range['end_date'],
                'dimensions' => ['query'],
                'dimensionFilterGroups' => [[
                    'filters' => [
                        ['dimension' => 'page', 'operator' => 'equals', 'expression' => $post_url],
                        ['dimension' => 'query', 'operator' => 'contains', 'expression' => $keyword],
                    ],
                ]],
                'rowLimit' => 1,
                'dataState' => 'final',
            ]);
            if (is_wp_error($response)) {
                return $response;
            }
            $row = $response['rows'][0] ?? [];
            $rows[] = [
                'keyword' => $keyword,
                'clicks' => (float) ($row['clicks'] ?? 0),
                'impressions' => (float) ($row['impressions'] ?? 0),
                'ctr' => (float) ($row['ctr'] ?? 0),
                'position' => (float) ($row['position'] ?? 0),
            ];
        }
        return $rows;
    }

    private function get_post_analytics_trend($property_id, $range, $page_path) {
        $base = 'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode($property_id) . ':runReport';
        $response = $this->api_post_json($base, [
            'dateRanges' => [[ 'startDate' => $range['start_date'], 'endDate' => $range['end_date'] ]],
            'dimensions' => [['name' => 'date']],
            'metrics' => [
                ['name' => 'screenPageViews'],
                ['name' => 'sessions'],
                ['name' => 'activeUsers'],
            ],
            'dimensionFilter' => [
                'filter' => [
                    'fieldName' => 'pagePath',
                    'stringFilter' => ['matchType' => 'EXACT', 'value' => $page_path],
                ],
            ],
            'orderBys' => [[ 'dimension' => ['dimensionName' => 'date'] ]],
            'limit' => max(31, (int) $range['days'] + 5),
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $rows = [];
        foreach (($response['rows'] ?? []) as $row) {
            $date_key = $row['dimensionValues'][0]['value'] ?? '';
            $rows[] = [
                'date' => $this->format_ga_date($date_key),
                'screenPageViews' => (float) ($row['metricValues'][0]['value'] ?? 0),
                'sessions' => (float) ($row['metricValues'][1]['value'] ?? 0),
                'activeUsers' => (float) ($row['metricValues'][2]['value'] ?? 0),
            ];
        }
        return $rows;
    }

    private function get_post_search_console_trend($site_url, $range, $post_url) {
        $encoded_site = rawurlencode($site_url);
        $endpoint = 'https://www.googleapis.com/webmasters/v3/sites/' . $encoded_site . '/searchAnalytics/query';
        $response = $this->api_post_json($endpoint, [
            'startDate' => $range['start_date'],
            'endDate' => $range['end_date'],
            'dimensions' => ['date'],
            'dimensionFilterGroups' => [[
                'filters' => [[
                    'dimension' => 'page',
                    'operator' => 'equals',
                    'expression' => $post_url,
                ]],
            ]],
            'rowLimit' => max(31, (int) $range['days'] + 5),
            'dataState' => 'final',
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $rows = [];
        foreach (($response['rows'] ?? []) as $row) {
            $rows[] = [
                'date' => $row['keys'][0] ?? '',
                'clicks' => (float) ($row['clicks'] ?? 0),
                'impressions' => (float) ($row['impressions'] ?? 0),
                'ctr' => round(((float) ($row['ctr'] ?? 0)) * 100, 2),
                'position' => (float) ($row['position'] ?? 0),
            ];
        }
        return $rows;
    }

}
