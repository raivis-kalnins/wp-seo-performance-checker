<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Password-protected front-end SEO, network, media search and browser optimizer toolkit.
 *
 * The toolkit intentionally stores no downloaded media in WordPress. Remote audit/search
 * responses use short transients, while image/video conversion happens in the visitor's browser.
 */
class SEOPC_Frontend_Toolkit {
    const OPTION = 'seopc_toolkit_settings';
    const TOKEN_TTL_MAX = 86400;
    private static $booted = false;

    public function __construct() {
        $this->ensure_settings();

        if (self::$booted) {
            return;
        }
        self::$booted = true;

        add_shortcode('seopc_toolkit', [$this, 'render_shortcode']);
        add_shortcode('seo_toolkit', [$this, 'render_shortcode']);

        add_action('admin_post_seopc_save_toolkit_settings', [$this, 'save_settings']);
        add_filter('body_class', [$this, 'body_classes']);

        $actions = [
            'seopc_toolkit_login' => 'ajax_login',
            'seopc_toolkit_analyze' => 'ajax_analyze',
            'seopc_toolkit_pagespeed' => 'ajax_pagespeed',
            'seopc_toolkit_network' => 'ajax_network',
            'seopc_toolkit_search_media' => 'ajax_search_media',
            'seopc_toolkit_fetch_media' => 'ajax_fetch_media',
            'seopc_toolkit_sitemap' => 'ajax_sitemap',
            'seopc_toolkit_social_profiles' => 'ajax_social_profiles',
            'seopc_toolkit_design_preview' => 'ajax_design_preview',
        ];

        foreach ($actions as $action => $method) {
            add_action('wp_ajax_' . $action, [$this, $method]);
            add_action('wp_ajax_nopriv_' . $action, [$this, $method]);
        }
    }

    public static function default_settings() {
        return [
            'enabled' => 1,
            'password_protection' => 1,
            'password_hash' => '',
            'password_secret' => '',
            'session_minutes' => 60,
            'cache_minutes' => 10,
            'rate_limit_hour' => 40,
            'pagespeed_api_key' => '',
            'openverse_enabled' => 1,
            'wikimedia_enabled' => 1,
            'pexels_api_key' => '',
            'pixabay_api_key' => '',
            'unsplash_access_key' => '',
            'security_defaults_version' => 253,
            'admin_bypass' => 1,
            'full_screen' => 1,
            'hide_theme_chrome' => 0,
            'max_html_mb' => 6,
            'max_media_mb' => 200,
        ];
    }

    private function ensure_settings() {
        $stored = get_option(self::OPTION, []);
        $settings = wp_parse_args(is_array($stored) ? $stored : [], self::default_settings());
        $changed = !is_array($stored);

        $security_version = is_array($stored) ? (int) ($stored['security_defaults_version'] ?? 0) : 0;
        if ($security_version < 253) {
            // v2.5.3 repairs the v2.5.2 activation default, which could store the legacy
            // SEO@password hash while the UI documented Seo@test. Preserve a genuinely
            // customized password, but repair empty/legacy installs automatically.
            $stored_hash = (string) ($settings['password_hash'] ?? '');
            $stored_secret = $this->decrypt_secret((string) ($settings['password_secret'] ?? ''));
            $legacy_password = $stored_hash !== '' && wp_check_password('SEO@password', $stored_hash);
            if ($stored_hash === '' || $legacy_password || $stored_secret === 'SEO@password') {
                $settings['password_hash'] = wp_hash_password('Seo@test');
                $settings['password_secret'] = $this->encrypt_secret('Seo@test');
            }
            $settings['password_protection'] = 1;
            $settings['security_defaults_version'] = 253;
            $changed = true;
        } elseif (empty($settings['password_hash'])) {
            $settings['password_hash'] = wp_hash_password('Seo@test');
            $settings['password_secret'] = $this->encrypt_secret('Seo@test');
            $changed = true;
        }

        if (empty($settings['password_secret']) && wp_check_password('Seo@test', $settings['password_hash'])) {
            $settings['password_secret'] = $this->encrypt_secret('Seo@test');
            $changed = true;
        }

        foreach (self::default_settings() as $key => $value) {
            if (!is_array($stored) || !array_key_exists($key, $stored)) {
                $changed = true;
                break;
            }
        }

        if ($changed) {
            update_option(self::OPTION, $settings, false);
        }
    }

    public function get_settings() {
        return wp_parse_args(get_option(self::OPTION, []), self::default_settings());
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        $display_password = $this->decrypt_secret((string) ($settings['password_secret'] ?? ''));
        ?>
        <div class="wrap seopc-wrap">
            <?php if (class_exists('SEOPC_Admin_Menu')) { SEOPC_Admin_Menu::render_tabs('frontend-toolkit'); } ?>
            <?php if (!empty($_GET['toolkit_saved'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Front-end toolkit settings saved.', 'seo-performance-checker'); ?></p></div>
            <?php endif; ?>
            <h1><?php esc_html_e('Front-end Smart SEO & Media Toolkit', 'seo-performance-checker'); ?></h1>
            <p><?php esc_html_e('Place the shortcode on any page. Audits and searches use Ajax; downloaded media is not saved to WordPress. Browser conversions stay on the visitor device.', 'seo-performance-checker'); ?></p>
            <p><code>[seopc_toolkit]</code> &nbsp; <code>[seo_toolkit]</code></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="seopc-settings-form">
                <input type="hidden" name="action" value="seopc_save_toolkit_settings">
                <?php wp_nonce_field('seopc_save_toolkit_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable toolkit', 'seo-performance-checker'); ?></th>
                        <td><label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($settings['enabled'])); ?>> <?php esc_html_e('Allow the shortcode to render.', 'seo-performance-checker'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Password protection', 'seo-performance-checker'); ?></th>
                        <td>
                            <label><input type="checkbox" name="password_protection" value="1" <?php checked(!empty($settings['password_protection'])); ?>> <?php esc_html_e('Require visitors to unlock the front-end toolkit.', 'seo-performance-checker'); ?></label>
                            <p class="description"><?php esc_html_e('Enabled for the agency front-end. Visitors must unlock the toolkit unless administrator bypass is active.', 'seo-performance-checker'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="seopc-toolkit-password"><?php esc_html_e('Toolkit password', 'seo-performance-checker'); ?></label></th>
                        <td>
                            <div style="display:flex;gap:8px;align-items:center;max-width:560px;">
                                <input id="seopc-toolkit-password" type="text" name="new_password" value="<?php echo esc_attr($display_password); ?>" class="regular-text" maxlength="128" autocomplete="off" spellcheck="false">
                                <button type="button" class="button" id="seopc-copy-toolkit-password"><?php esc_html_e('Copy', 'seo-performance-checker'); ?></button>
                            </div>
                            <?php if ($display_password === ''): ?>
                                <p class="description" style="color:#b45309;"><?php esc_html_e('This password came from an older plugin version and cannot be recovered from its hash. Enter a new password and save once to make it visible here.', 'seo-performance-checker'); ?></p>
                            <?php else: ?>
                                <p class="description"><?php esc_html_e('Visible only to administrators on this settings screen. The front-end password is Seo@test unless you change it here.', 'seo-performance-checker'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Administrator bypass', 'seo-performance-checker'); ?></th>
                        <td><label><input type="checkbox" name="admin_bypass" value="1" <?php checked(!empty($settings['admin_bypass'])); ?>> <?php esc_html_e('When protection is enabled, automatically unlock for logged-in administrators.', 'seo-performance-checker'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Front-end layout', 'seo-performance-checker'); ?></th>
                        <td>
                            <label><input type="checkbox" name="full_screen" value="1" <?php checked(!empty($settings['full_screen'])); ?>> <?php esc_html_e('Use full browser width and at least full viewport height.', 'seo-performance-checker'); ?></label><br>
                            <label><input type="checkbox" name="hide_theme_chrome" value="1" <?php checked(!empty($settings['hide_theme_chrome'])); ?>> <?php esc_html_e('Hide the theme header and footer on pages containing the toolkit shortcode.', 'seo-performance-checker'); ?></label>
                            <p class="description"><?php esc_html_e('Optimized for block themes including Twenty Twenty-Five 1.5. The WordPress admin bar remains available to logged-in administrators.', 'seo-performance-checker'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="seopc-session-minutes"><?php esc_html_e('Session length', 'seo-performance-checker'); ?></label></th>
                        <td><input id="seopc-session-minutes" type="number" min="5" max="1440" name="session_minutes" value="<?php echo esc_attr((int) $settings['session_minutes']); ?>"> <?php esc_html_e('minutes', 'seo-performance-checker'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="seopc-cache-minutes"><?php esc_html_e('Result cache', 'seo-performance-checker'); ?></label></th>
                        <td><input id="seopc-cache-minutes" type="number" min="1" max="120" name="cache_minutes" value="<?php echo esc_attr((int) $settings['cache_minutes']); ?>"> <?php esc_html_e('minutes (transients only)', 'seo-performance-checker'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="seopc-rate-limit"><?php esc_html_e('Rate limit', 'seo-performance-checker'); ?></label></th>
                        <td><input id="seopc-rate-limit" type="number" min="5" max="500" name="rate_limit_hour" value="<?php echo esc_attr((int) $settings['rate_limit_hour']); ?>"> <?php esc_html_e('requests per IP per hour', 'seo-performance-checker'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="seopc-pagespeed-key"><?php esc_html_e('Google PageSpeed API key', 'seo-performance-checker'); ?></label></th>
                        <td><input id="seopc-pagespeed-key" type="password" name="pagespeed_api_key" value="<?php echo esc_attr($settings['pagespeed_api_key']); ?>" class="regular-text" autocomplete="off"><p class="description"><?php esc_html_e('Optional, but recommended for reliable quota.', 'seo-performance-checker'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Free media providers', 'seo-performance-checker'); ?></th>
                        <td>
                            <label><input type="checkbox" name="openverse_enabled" value="1" <?php checked(!empty($settings['openverse_enabled'])); ?>> Openverse</label><br>
                            <label><input type="checkbox" name="wikimedia_enabled" value="1" <?php checked(!empty($settings['wikimedia_enabled'])); ?>> Wikimedia Commons</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="seopc-pexels-key">Pexels API key</label></th>
                        <td><input id="seopc-pexels-key" type="password" name="pexels_api_key" value="<?php echo esc_attr($settings['pexels_api_key']); ?>" class="regular-text" autocomplete="off"><p class="description">Enables in-tool Pexels photo/video search and download actions. Without a key, Pexels remains available as a direct native-search source.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="seopc-pixabay-key">Pixabay API key</label></th>
                        <td><input id="seopc-pixabay-key" type="password" name="pixabay_api_key" value="<?php echo esc_attr($settings['pixabay_api_key']); ?>" class="regular-text" autocomplete="off"><p class="description">Enables in-tool Pixabay image/video search. Without a key, Pixabay remains available as a direct native-search source.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="seopc-unsplash-key">Unsplash access key</label></th>
                        <td><input id="seopc-unsplash-key" type="password" name="unsplash_access_key" value="<?php echo esc_attr($settings['unsplash_access_key']); ?>" class="regular-text" autocomplete="off"><p class="description">Enables in-tool Unsplash image search with required provider attribution/download tracking. Without a key, Unsplash remains available as a direct native-search source.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Remote limits', 'seo-performance-checker'); ?></th>
                        <td>
                            <label><?php esc_html_e('HTML', 'seo-performance-checker'); ?> <input type="number" min="1" max="10" name="max_html_mb" value="<?php echo esc_attr((int) $settings['max_html_mb']); ?>"> MB</label><br>
                            <label><?php esc_html_e('Media proxy', 'seo-performance-checker'); ?> <input type="number" min="5" max="500" name="max_media_mb" value="<?php echo esc_attr((int) $settings['max_media_mb']); ?>"> MB</label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save Toolkit Settings', 'seo-performance-checker')); ?>
            </form>
            <script>
            (function () {
                var button = document.getElementById('seopc-copy-toolkit-password');
                var input = document.getElementById('seopc-toolkit-password');
                if (!button || !input) return;
                button.addEventListener('click', function () {
                    if (!input.value) return;
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(input.value);
                    } else {
                        input.select();
                        document.execCommand('copy');
                    }
                    button.textContent = '<?php echo esc_js(__('Copied', 'seo-performance-checker')); ?>';
                    window.setTimeout(function () { button.textContent = '<?php echo esc_js(__('Copy', 'seo-performance-checker')); ?>'; }, 1400);
                });
            }());
            </script>
            <div class="notice notice-info inline"><p><?php esc_html_e('Network “ping” uses DNS lookup, HTTP timing and TCP connection timing because most shared hosts do not permit raw ICMP ping. AVIF export depends on browser support; the tool falls back to WebP when needed. Video compression is browser-only and exports WebM in real time.', 'seo-performance-checker'); ?></p></div>
        </div>
        <?php
    }

    public function save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'seo-performance-checker'));
        }
        check_admin_referer('seopc_save_toolkit_settings');

        $old = $this->get_settings();
        $settings = $old;
        $settings['enabled'] = !empty($_POST['enabled']) ? 1 : 0;
        $settings['password_protection'] = !empty($_POST['password_protection']) ? 1 : 0;
        $settings['admin_bypass'] = !empty($_POST['admin_bypass']) ? 1 : 0;
        $settings['full_screen'] = !empty($_POST['full_screen']) ? 1 : 0;
        $settings['hide_theme_chrome'] = !empty($_POST['hide_theme_chrome']) ? 1 : 0;
        $settings['openverse_enabled'] = !empty($_POST['openverse_enabled']) ? 1 : 0;
        $settings['wikimedia_enabled'] = !empty($_POST['wikimedia_enabled']) ? 1 : 0;
        $settings['session_minutes'] = min(1440, max(5, absint($_POST['session_minutes'] ?? 60)));
        $settings['cache_minutes'] = min(120, max(1, absint($_POST['cache_minutes'] ?? 10)));
        $settings['rate_limit_hour'] = min(500, max(5, absint($_POST['rate_limit_hour'] ?? 40)));
        $settings['max_html_mb'] = min(10, max(1, absint($_POST['max_html_mb'] ?? 6)));
        $settings['max_media_mb'] = min(500, max(5, absint($_POST['max_media_mb'] ?? 200)));
        $settings['pagespeed_api_key'] = sanitize_text_field(wp_unslash($_POST['pagespeed_api_key'] ?? ''));
        $settings['pexels_api_key'] = sanitize_text_field(wp_unslash($_POST['pexels_api_key'] ?? ''));
        $settings['pixabay_api_key'] = sanitize_text_field(wp_unslash($_POST['pixabay_api_key'] ?? ''));
        $settings['unsplash_access_key'] = sanitize_text_field(wp_unslash($_POST['unsplash_access_key'] ?? ''));

        $new_password = isset($_POST['new_password']) ? trim((string) wp_unslash($_POST['new_password'])) : '';
        if ($new_password !== '') {
            $new_password = function_exists('mb_substr') ? mb_substr($new_password, 0, 128) : substr($new_password, 0, 128);
            $settings['password_hash'] = wp_hash_password($new_password);
            $settings['password_secret'] = $this->encrypt_secret($new_password);
        }

        update_option(self::OPTION, $settings, false);
        wp_safe_redirect(add_query_arg([
            'page' => 'seo-performance',
            'tab' => 'frontend-toolkit',
            'toolkit_saved' => 1,
        ], admin_url('options-general.php')));
        exit;
    }

    public function render_shortcode($atts = []) {
        $settings = $this->get_settings();
        if (empty($settings['enabled'])) {
            return '<div class="seopc-toolkit-disabled">' . esc_html__('The SEO toolkit is currently disabled.', 'seo-performance-checker') . '</div>';
        }

        wp_enqueue_style('seopc-toolkit', SEOPC_PLUGIN_URL . 'assets/css/frontend-toolkit.css', [], SEOPC_VERSION);
        wp_enqueue_script('seopc-toolkit', SEOPC_PLUGIN_URL . 'assets/js/frontend-toolkit.js', ['wp-element'], SEOPC_VERSION, true);

        wp_localize_script('seopc-toolkit', 'SEOPC_TOOLKIT', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('seopc_toolkit_public'),
            'passwordProtected' => !empty($settings['password_protection']),
            'adminBypass' => !empty($settings['password_protection']) && !empty($settings['admin_bypass']) && current_user_can('manage_options'),
            'fullScreen' => !empty($settings['full_screen']),
            'hideThemeChrome' => !empty($settings['hide_theme_chrome']),
            'sessionMinutes' => (int) $settings['session_minutes'],
            'maxMediaMb' => (int) $settings['max_media_mb'],
            'version' => SEOPC_VERSION,
            'pageSpeedConfigured' => !empty($settings['pagespeed_api_key']),
            'settingsUrl' => current_user_can('manage_options') ? add_query_arg(['page' => 'seo-performance', 'tab' => 'frontend-toolkit'], admin_url('options-general.php')) : '',
            'mediaProviders' => [
                'wpphotos' => true,
                'openverse' => !empty($settings['openverse_enabled']),
                'wikimedia' => !empty($settings['wikimedia_enabled']),
                'pexels' => !empty($settings['pexels_api_key']),
                'pixabay' => !empty($settings['pixabay_api_key']),
                'unsplash' => !empty($settings['unsplash_access_key']),
            ],
            'externalTools' => [
                'pagespeed' => 'https://pagespeed.web.dev/analysis?url=',
                'pingdom' => 'https://tools.pingdom.com/#',
                'speedvitals' => 'https://speedvitals.com/report/',
                'seoextension' => 'https://seo-extension.com/',
            ],
            'strings' => [
                'genericError' => __('Something went wrong. Please try again.', 'seo-performance-checker'),
                'locked' => __('Enter the toolkit password.', 'seo-performance-checker'),
            ],
        ]);

        $id = 'seopc-toolkit-' . wp_rand(1000, 999999);
        $classes = ['seopc-toolkit-root'];
        if (!empty($settings['full_screen'])) {
            $classes[] = 'seopc-layout-fullscreen';
        }
        return '<div id="' . esc_attr($id) . '" class="' . esc_attr(implode(' ', $classes)) . '"></div><noscript>' . esc_html__('JavaScript is required for this toolkit.', 'seo-performance-checker') . '</noscript>';
    }

    public function ajax_login() {
        $this->check_nonce();
        $this->enforce_rate_limit('login', 12);
        $settings = $this->get_settings();
        if (empty($settings['password_protection'])) {
            wp_send_json_success(['token' => 'public', 'protected' => false]);
        }
        $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';

        if (!empty($settings['admin_bypass']) && current_user_can('manage_options')) {
            wp_send_json_success(['token' => $this->issue_token()]);
        }

        $password_ok = $password !== '' && wp_check_password($password, (string) $settings['password_hash']);

        // Self-heal the v2.5.2 activation bug even if an object/page cache served settings
        // created before the v2.5.3 migration ran. The documented agency password is Seo@test.
        if (!$password_ok && $password === 'Seo@test' && !empty($settings['password_hash']) && wp_check_password('SEO@password', (string) $settings['password_hash'])) {
            $settings['password_hash'] = wp_hash_password('Seo@test');
            $settings['password_secret'] = $this->encrypt_secret('Seo@test');
            $settings['password_protection'] = 1;
            $settings['security_defaults_version'] = 253;
            update_option(self::OPTION, $settings, false);
            $password_ok = true;
        }

        if (!$password_ok) {
            wp_send_json_error(['message' => __('Incorrect password.', 'seo-performance-checker')], 403);
        }

        wp_send_json_success(['token' => $this->issue_token()]);
    }

    public function ajax_analyze() {
        $this->authorize('analyze');
        $url = $this->validated_url($_POST['url'] ?? '');
        $settings = $this->get_settings();
        $fresh = !empty($_POST['fresh']) && sanitize_text_field(wp_unslash($_POST['fresh'])) === '1';
        $cache_key = 'seopc_audit_v254_' . md5($url);
        $cached = $fresh ? false : get_transient($cache_key);
        if (is_array($cached)) {
            $cached['cached'] = true;
            wp_send_json_success($cached);
        }

        $result = $this->quick_audit($url, $settings);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }
        set_transient($cache_key, $result, (int) $settings['cache_minutes'] * MINUTE_IN_SECONDS);
        wp_send_json_success($result);
    }

    public function ajax_pagespeed() {
        $this->authorize('pagespeed', 12);
        $url = $this->validated_url($_POST['url'] ?? '');
        $strategy = sanitize_key($_POST['strategy'] ?? 'mobile');
        if (!in_array($strategy, ['mobile', 'desktop'], true)) {
            $strategy = 'mobile';
        }
        $settings = $this->get_settings();
        $fresh = !empty($_POST['fresh']) && sanitize_text_field(wp_unslash($_POST['fresh'])) === '1';
        $cache_key = 'seopc_psi_v240_' . md5($url . '|' . $strategy);
        $cached = $fresh ? false : get_transient($cache_key);
        if (is_array($cached)) {
            $cached['cached'] = true;
            wp_send_json_success($cached);
        }

        $params = [
            'url' => $url,
            'strategy' => $strategy,
            'locale' => str_replace('_', '-', determine_locale()),
        ];
        if (!empty($settings['pagespeed_api_key'])) {
            $params['key'] = $settings['pagespeed_api_key'];
        }

        // Keep this list aligned with the live PageSpeed/Lighthouse API. Agentic Browsing
        // is new and can be absent on older Google rollout nodes, so retry gracefully.
        $requested_categories = ['PERFORMANCE', 'ACCESSIBILITY', 'BEST_PRACTICES', 'SEO', 'AGENTIC_BROWSING'];
        $build_api_url = static function ($categories) use ($params) {
            $api_url = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . http_build_query($params);
            foreach ($categories as $category) {
                $api_url .= '&category=' . rawurlencode($category);
            }
            return $api_url;
        };
        $request_args = [
            'timeout' => 60,
            'redirection' => 2,
            'user-agent' => 'WP SEO Performance Checker/' . SEOPC_VERSION . '; ' . home_url('/'),
        ];

        $agentic_requested = true;
        $response = wp_remote_get($build_api_url($requested_categories), $request_args);
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()], 502);
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $json = json_decode(wp_remote_retrieve_body($response), true);

        // During staged rollouts PageSpeed can reject a newly introduced category even
        // though the rest of the Lighthouse categories are available. Never lose the
        // complete audit because of that: retry once without Agentic Browsing.
        if ($code === 400 && $agentic_requested) {
            $message = strtolower((string) ($json['error']['message'] ?? ''));
            if (strpos($message, 'agentic') !== false || strpos($message, 'category') !== false || strpos($message, 'invalid') !== false) {
                $fallback_categories = ['PERFORMANCE', 'ACCESSIBILITY', 'BEST_PRACTICES', 'SEO'];
                $response = wp_remote_get($build_api_url($fallback_categories), $request_args);
                if (is_wp_error($response)) {
                    wp_send_json_error(['message' => $response->get_error_message()], 502);
                }
                $code = (int) wp_remote_retrieve_response_code($response);
                $json = json_decode(wp_remote_retrieve_body($response), true);
                $agentic_requested = false;
            }
        }

        if ($code >= 400 || !is_array($json) || empty($json['lighthouseResult'])) {
            $message = $json['error']['message'] ?? __('PageSpeed could not complete this test. Add an API key or try again later.', 'seo-performance-checker');
            wp_send_json_error(['message' => $message], $code ?: 502);
        }

        $result = $this->format_pagespeed($json, $strategy);
        $result['agentic_browsing_requested'] = $agentic_requested;
        set_transient($cache_key, $result, max(15, (int) $settings['cache_minutes']) * MINUTE_IN_SECONDS);
        wp_send_json_success($result);
    }

    public function ajax_network() {
        $this->authorize('network');
        $raw = isset($_POST['target']) ? trim((string) wp_unslash($_POST['target'])) : '';
        if ($raw === '') {
            wp_send_json_error(['message' => __('Enter a domain or URL.', 'seo-performance-checker')], 400);
        }
        $url = preg_match('#^https?://#i', $raw) ? $raw : 'https://' . $raw;
        $url = $this->validated_url($url);
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $settings = $this->get_settings();
        $fresh = !empty($_POST['fresh']) && sanitize_text_field(wp_unslash($_POST['fresh'])) === '1';
        $cache_key = 'seopc_network_v221_' . md5($url);
        $cached = $fresh ? false : get_transient($cache_key);
        if (is_array($cached)) {
            $cached['cached'] = true;
            wp_send_json_success($cached);
        }

        $result = $this->network_test($host, $url);
        set_transient($cache_key, $result, max(5, (int) $settings['cache_minutes']) * MINUTE_IN_SECONDS);
        wp_send_json_success($result);
    }

    public function ajax_search_media() {
        $this->authorize('search_media', 160);
        $query = sanitize_text_field(wp_unslash($_POST['query'] ?? ''));
        $type = sanitize_key($_POST['media_type'] ?? 'image');
        $provider = sanitize_key($_POST['provider'] ?? 'all');
        $allowed_providers = ['all', 'wpphotos', 'openverse', 'wikimedia', 'pexels', 'pixabay', 'unsplash', 'iconify'];
        if (!in_array($provider, $allowed_providers, true)) {
            $provider = 'all';
        }
        $page = max(1, min(20, absint($_POST['page'] ?? 1)));
        if (strlen($query) < 2) {
            wp_send_json_error(['message' => __('Enter at least two characters.', 'seo-performance-checker')], 400);
        }
        if (!in_array($type, ['image', 'video', 'icon'], true)) {
            $type = 'image';
        }

        $settings = $this->get_settings();
        $fresh = !empty($_POST['fresh']) && sanitize_text_field(wp_unslash($_POST['fresh'])) === '1';
        $cache_key = 'seopc_media_v251_' . md5($provider . '|' . $type . '|' . $page . '|' . strtolower($query));
        $cached = $fresh ? false : get_transient($cache_key);
        if (is_array($cached)) {
            if (!empty($cached['items']) && is_array($cached['items'])) {
                foreach ($cached['items'] as &$cached_item) {
                    if (!empty($cached_item['download_url'])) {
                        $signed = $this->sign_media_url($cached_item['download_url'], $cached_item['download_trigger_url'] ?? '');
                        $cached_item['media_sig'] = $signed['sig'];
                        $cached_item['media_exp'] = $signed['exp'];
                    }
                }
                unset($cached_item);
            }
            wp_send_json_success($cached);
        }

        $items = [];
        $errors = [];
        if ($type === 'icon') {
            if ($provider === 'all' || $provider === 'iconify') {
                $this->search_iconify($query, $page, $items, $errors);
            }
        } else {
            if (($provider === 'all' || $provider === 'wpphotos') && $type === 'image') {
                $this->search_wp_photos($query, $page, $items, $errors);
            }
            if (($provider === 'all' || $provider === 'openverse') && $type === 'image' && !empty($settings['openverse_enabled'])) {
                $this->search_openverse($query, $page, $items, $errors);
            }
            if (($provider === 'all' || $provider === 'wikimedia') && !empty($settings['wikimedia_enabled'])) {
                $this->search_wikimedia($query, $type, $page, $items, $errors);
            }
            if (($provider === 'all' || $provider === 'pexels') && !empty($settings['pexels_api_key'])) {
                $this->search_pexels($query, $type, $page, $settings['pexels_api_key'], $items, $errors);
            }
            if (($provider === 'all' || $provider === 'pixabay') && !empty($settings['pixabay_api_key'])) {
                $this->search_pixabay($query, $type, $page, $settings['pixabay_api_key'], $items, $errors);
            }
            if (($provider === 'all' || $provider === 'unsplash') && $type === 'image' && !empty($settings['unsplash_access_key'])) {
                $this->search_unsplash($query, $page, $settings['unsplash_access_key'], $items, $errors);
            }
        }

        $seen = [];
        $unique = [];
        foreach ($items as $item) {
            $item['preview_url'] = esc_url_raw($item['preview_url'] ?? '', ['http', 'https']);
            $item['preview_fast_url'] = esc_url_raw($item['preview_fast_url'] ?? ($item['preview_url'] ?? ''), ['http', 'https']);
            $item['download_url'] = esc_url_raw($item['download_url'] ?? '', ['http', 'https']);
            $item['source_url'] = esc_url_raw($item['source_url'] ?? '', ['http', 'https']);
            $item['license_url'] = esc_url_raw($item['license_url'] ?? '', ['http', 'https']);
            $item['download_trigger_url'] = esc_url_raw($item['download_trigger_url'] ?? '', ['http', 'https']);
            $key = md5($item['download_url'] ?? ($item['preview_url'] ?? ''));
            if (empty($item['download_url']) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $item['quality_score'] = $this->media_quality_score($item, $type);
            $signed = $this->sign_media_url($item['download_url'], $item['download_trigger_url'] ?? '');
            $item['media_sig'] = $signed['sig'];
            $item['media_exp'] = $signed['exp'];
            $unique[] = $item;
        }

        // The old fan-out order meant Openverse/Wikimedia could fill the cap before
        // Pexels/Pixabay/Unsplash were shown. Rank the mixed result set so agency users
        // see the strongest usable assets first while still retaining source variety.
        usort($unique, function ($a, $b) {
            $score_cmp = (int) ($b['quality_score'] ?? 0) <=> (int) ($a['quality_score'] ?? 0);
            if ($score_cmp !== 0) return $score_cmp;
            return strcmp((string) ($a['provider'] ?? ''), (string) ($b['provider'] ?? ''));
        });
        $unique = array_slice($unique, 0, 48);

        $result = [
            'items' => $unique,
            'errors' => $errors,
            'page' => $page,
            'media_type' => $type,
            'provider' => $provider,
            'providers' => [
                'wpphotos' => ['enabled' => true, 'requires_key' => false],
                'openverse' => ['enabled' => !empty($settings['openverse_enabled']), 'requires_key' => false],
                'wikimedia' => ['enabled' => !empty($settings['wikimedia_enabled']), 'requires_key' => false],
                'pexels' => ['enabled' => !empty($settings['pexels_api_key']), 'requires_key' => true],
                'pixabay' => ['enabled' => !empty($settings['pixabay_api_key']), 'requires_key' => true],
                'unsplash' => ['enabled' => !empty($settings['unsplash_access_key']), 'requires_key' => true],
                'iconify' => ['enabled' => true, 'requires_key' => false],
            ],
            'notice' => __('Results are quality-ranked across enabled providers. Licences and attribution rules still differ by source; review the source page before publishing.', 'seo-performance-checker'),
        ];
        set_transient($cache_key, $result, max(10, (int) $settings['cache_minutes']) * MINUTE_IN_SECONDS);
        wp_send_json_success($result);
    }

    public function ajax_sitemap() {
        $this->authorize('sitemap', 20);
        $target = $this->validated_url($_POST['url'] ?? '');
        $settings = $this->get_settings();
        $fresh = !empty($_POST['fresh']) && sanitize_text_field(wp_unslash($_POST['fresh'])) === '1';
        $limit = min(1000, max(25, absint($_POST['limit'] ?? 250)));
        $origin = trailingslashit($this->origin_url($target));
        $cache_key = 'seopc_sitemap_v251_' . md5($origin . '|' . $limit);
        $cached = $fresh ? false : get_transient($cache_key);
        if (is_array($cached)) {
            $cached['cached'] = true;
            wp_send_json_success($cached);
        }

        $robots_candidates = [];
        $robots_url = $origin . 'robots.txt';
        $robots_response = wp_safe_remote_get($robots_url, [
            'timeout' => 8,
            'redirection' => 3,
            'user-agent' => 'Mozilla/5.0 (compatible; Website Growth Toolkit/' . SEOPC_VERSION . '; +' . home_url('/') . ')',
        ]);
        if (!is_wp_error($robots_response) && (int) wp_remote_retrieve_response_code($robots_response) < 400) {
            $robots_body = (string) wp_remote_retrieve_body($robots_response);
            if (preg_match_all('/^\s*Sitemap:\s*(\S+)/im', $robots_body, $matches)) {
                foreach ($matches[1] as $candidate) {
                    if ($this->same_public_origin($candidate, $origin)) {
                        $robots_candidates[] = esc_url_raw($candidate);
                    }
                }
            }
        }
        $robots_candidates = array_values(array_unique(array_filter($robots_candidates)));
        $fallback_candidates = [$origin . 'sitemap_index.xml', $origin . 'sitemap.xml', $origin . 'wp-sitemap.xml'];

        $groups = [];
        $urls = [];
        $errors = [];
        $visited = [];
        $used_fallback = false;

        // Prefer sitemap URLs explicitly declared in robots.txt. This avoids serially waiting
        // on several conventional sitemap paths after a valid index has already been found.
        $primary_candidates = $robots_candidates ?: $fallback_candidates;
        foreach ($primary_candidates as $candidate) {
            if (count($urls) >= $limit || count($visited) >= 60) break;
            $before = count($urls);
            $this->crawl_sitemap($candidate, $origin, $limit, $groups, $urls, $errors, $visited, 0);
            if (!$robots_candidates && count($urls) > $before) {
                // A conventional root worked; its index/urlset is authoritative enough for
                // this pass, so do not burn more request time probing alternate roots.
                $used_fallback = true;
                break;
            }
        }

        // Only fall back to conventional locations when robots.txt supplied sitemap entries
        // but none of them produced any URLs. One slow child sitemap no longer forces the
        // whole request to wait for every other common path as well.
        if ($robots_candidates && !$urls) {
            foreach ($fallback_candidates as $candidate) {
                if (isset($visited[$candidate]) || count($visited) >= 60) continue;
                $before = count($urls);
                $this->crawl_sitemap($candidate, $origin, $limit, $groups, $urls, $errors, $visited, 0);
                if (count($urls) > $before) { $used_fallback = true; break; }
            }
        }

        $url_values = array_slice(array_values($urls), 0, $limit);
        $path_types = [];
        $freshness = ['30d' => 0, '90d' => 0, '365d' => 0, 'older' => 0, 'unknown' => 0];
        $now = time();
        foreach ($url_values as $entry) {
            $path = trim((string) ($entry['path'] ?? ''), '/');
            $first = $path === '' ? 'home' : strtolower((string) strtok($path, '/'));
            if (strpos($first, 'product') === 0) $first = 'product';
            elseif (strpos($first, 'category') === 0) $first = 'category';
            elseif (strpos($first, 'tag') === 0) $first = 'tag';
            elseif (strpos($first, 'author') === 0) $first = 'author';
            $path_types[$first] = ($path_types[$first] ?? 0) + 1;
            $lastmod = !empty($entry['lastmod']) ? strtotime($entry['lastmod']) : false;
            if (!$lastmod) $freshness['unknown']++;
            else {
                $age = max(0, $now - $lastmod);
                if ($age <= 30 * DAY_IN_SECONDS) $freshness['30d']++;
                elseif ($age <= 90 * DAY_IN_SECONDS) $freshness['90d']++;
                elseif ($age <= 365 * DAY_IN_SECONDS) $freshness['365d']++;
                else $freshness['older']++;
            }
        }
        arsort($path_types);

        $result = [
            'origin' => $origin,
            'robots_url' => $robots_url,
            'sitemaps' => array_values($groups),
            'urls' => $url_values,
            'url_count' => count($url_values),
            'truncated' => count($urls) >= $limit,
            'errors' => array_values(array_unique($errors)),
            'analytics' => [
                'path_types' => $path_types,
                'freshness' => $freshness,
                'sitemap_count' => count($groups),
                'failed_sitemaps' => count(array_filter($groups, function ($group) { return !empty($group['error']); })),
            ],
            'cached' => false,
            'tested_at' => current_time('mysql'),
        ];
        set_transient($cache_key, $result, max(10, (int) $settings['cache_minutes']) * MINUTE_IN_SECONDS);
        wp_send_json_success($result);
    }

    private function origin_url($url) {
        $parts = wp_parse_url($url);
        if (empty($parts['host'])) return $url;
        $origin = ($parts['scheme'] ?? 'https') . '://' . $parts['host'];
        if (!empty($parts['port'])) $origin .= ':' . $parts['port'];
        return $origin . '/';
    }

    private function same_public_origin($candidate, $origin) {
        $candidate_host = strtolower((string) wp_parse_url($candidate, PHP_URL_HOST));
        $origin_host = strtolower((string) wp_parse_url($origin, PHP_URL_HOST));
        return $candidate_host && $origin_host && $candidate_host === $origin_host;
    }

    private function fetch_sitemap_document($sitemap_url) {
        $args = [
            'timeout' => 12,
            'redirection' => 5,
            'limit_response_size' => 12 * MB_IN_BYTES,
            'headers' => ['Accept' => 'application/xml,text/xml,application/gzip,text/plain;q=0.8,*/*;q=0.2'],
            'user-agent' => 'Mozilla/5.0 (compatible; Website Growth Toolkit/' . SEOPC_VERSION . '; +' . home_url('/') . ')',
        ];
        $started = microtime(true);
        $response = wp_safe_remote_get($sitemap_url, $args);
        if (is_wp_error($response)) {
            // Retry once with a smaller body cap; some hosts close long sitemap responses on
            // the first connection while succeeding immediately on a second request.
            $args['timeout'] = 16;
            $args['redirection'] = 2;
            $args['limit_response_size'] = 6 * MB_IN_BYTES;
            $response = wp_safe_remote_get($sitemap_url, $args);
        }
        $elapsed_ms = (int) round((microtime(true) - $started) * 1000);
        if (is_wp_error($response)) return [$response, '', 0, $elapsed_ms];
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $encoding = strtolower((string) wp_remote_retrieve_header($response, 'content-encoding'));
        $type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        if (($encoding === 'gzip' || strpos($type, 'gzip') !== false || preg_match('/\.gz(?:$|\?)/i', $sitemap_url)) && function_exists('gzdecode')) {
            $decoded = @gzdecode($body);
            if (is_string($decoded) && $decoded !== '') $body = $decoded;
        }
        return [$response, trim($body), $code, $elapsed_ms];
    }

    private function crawl_sitemap($sitemap_url, $origin, $limit, &$groups, &$urls, &$errors, &$visited, $depth) {
        if ($depth > 3 || isset($visited[$sitemap_url]) || count($visited) >= 60 || count($urls) >= $limit) return;
        if (!$this->same_public_origin($sitemap_url, $origin)) return;
        $visited[$sitemap_url] = true;

        [$response, $body, $code, $elapsed_ms] = $this->fetch_sitemap_document($sitemap_url);
        if (is_wp_error($response)) {
            $raw_message = $response->get_error_message();
            $is_timeout = stripos($raw_message, 'timed out') !== false || stripos($raw_message, 'cURL error 28') !== false;
            $message = $is_timeout
                ? __('Timed out waiting for this sitemap; skipped it and continued with the other sitemap sources.', 'seo-performance-checker')
                : $raw_message;
            $errors[] = $sitemap_url . ': ' . $message;
            $groups[$sitemap_url] = ['url' => $sitemap_url, 'type' => 'error', 'count' => 0, 'children' => [], 'status' => 0, 'elapsed_ms' => $elapsed_ms, 'error' => $message, 'timeout' => $is_timeout];
            return;
        }
        if ($code >= 400 || $body === '') {
            $message = $code >= 400 ? ('HTTP ' . $code) : 'Empty response';
            if ($code >= 400) $errors[] = $sitemap_url . ': ' . $message;
            $groups[$sitemap_url] = ['url' => $sitemap_url, 'type' => 'error', 'count' => 0, 'children' => [], 'status' => $code, 'elapsed_ms' => $elapsed_ms, 'error' => $message];
            return;
        }
        if (!class_exists('DOMDocument')) {
            $errors[] = $sitemap_url . ': DOM extension is not available';
            return;
        }
        $xml = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $xml->loadXML($body, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        if (!$loaded || !$xml->documentElement) {
            $errors[] = $sitemap_url . ': could not parse XML';
            $groups[$sitemap_url] = ['url' => $sitemap_url, 'type' => 'error', 'count' => 0, 'children' => [], 'status' => $code, 'elapsed_ms' => $elapsed_ms, 'error' => 'Could not parse XML'];
            return;
        }
        $xpath = new DOMXPath($xml);
        $name = strtolower($xml->documentElement->localName ?: $xml->documentElement->nodeName);
        if ($name === 'sitemapindex') {
            $children = [];
            $nodes = $xpath->query('/*[local-name()="sitemapindex"]/*[local-name()="sitemap"]/*[local-name()="loc"]');
            if ($nodes) foreach ($nodes as $node) {
                $loc = trim($node->textContent);
                if ($loc && $this->same_public_origin($loc, $origin)) $children[] = $loc;
            }
            $groups[$sitemap_url] = ['url' => $sitemap_url, 'type' => 'index', 'count' => count($children), 'children' => array_slice($children, 0, 60), 'status' => $code, 'elapsed_ms' => $elapsed_ms, 'error' => ''];
            foreach ($children as $child) {
                if (count($urls) >= $limit || count($visited) >= 60) break;
                $this->crawl_sitemap($child, $origin, $limit, $groups, $urls, $errors, $visited, $depth + 1);
            }
            return;
        }
        if ($name === 'urlset') {
            $items = [];
            $url_nodes = $xpath->query('/*[local-name()="urlset"]/*[local-name()="url"]');
            if ($url_nodes) foreach ($url_nodes as $item) {
                if (count($urls) >= $limit) break;
                $loc_node = $xpath->query('./*[local-name()="loc"]', $item)->item(0);
                $loc = $loc_node ? trim($loc_node->textContent) : '';
                if (!$loc || !$this->same_public_origin($loc, $origin)) continue;
                $lastmod_node = $xpath->query('./*[local-name()="lastmod"]', $item)->item(0);
                $changefreq_node = $xpath->query('./*[local-name()="changefreq"]', $item)->item(0);
                $priority_node = $xpath->query('./*[local-name()="priority"]', $item)->item(0);
                $entry = [
                    'url' => $loc,
                    'path' => (string) wp_parse_url($loc, PHP_URL_PATH),
                    'lastmod' => $lastmod_node ? trim($lastmod_node->textContent) : '',
                    'changefreq' => $changefreq_node ? trim($changefreq_node->textContent) : '',
                    'priority' => $priority_node ? trim($priority_node->textContent) : '',
                    'group' => $sitemap_url,
                ];
                $urls[$loc] = $entry;
                $items[] = $loc;
            }
            $groups[$sitemap_url] = ['url' => $sitemap_url, 'type' => 'urlset', 'count' => count($items), 'children' => [], 'status' => $code, 'elapsed_ms' => $elapsed_ms, 'error' => ''];
            return;
        }
        $errors[] = $sitemap_url . ': unsupported sitemap root <' . $name . '>';
        $groups[$sitemap_url] = ['url' => $sitemap_url, 'type' => 'error', 'count' => 0, 'children' => [], 'status' => $code, 'elapsed_ms' => $elapsed_ms, 'error' => 'Unsupported sitemap root'];
    }

    public function ajax_social_profiles() {
        $this->authorize('social_profiles', 20);
        $raw = isset($_POST['profiles']) ? wp_unslash($_POST['profiles']) : '';
        $profiles = json_decode((string) $raw, true);
        if (!is_array($profiles)) {
            wp_send_json_error(['message' => __('No social profiles were supplied.', 'seo-performance-checker')], 400);
        }

        $domains = $this->social_platform_domains();
        $items = [];
        $recommendations = [];
        foreach (array_slice($profiles, 0, 12, true) as $platform => $profile_url) {
            $platform = sanitize_key($platform);
            $profile_url = esc_url_raw((string) $profile_url, ['http', 'https']);
            if (!$profile_url || empty($domains[$platform])) continue;
            $host = strtolower((string) wp_parse_url($profile_url, PHP_URL_HOST));
            $host_ok = false;
            foreach ($domains[$platform] as $domain) {
                if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) { $host_ok = true; break; }
            }
            if (!$host_ok || $this->is_private_host($host)) continue;

            $cache_key = 'seopc_social_profile_v251_' . md5($profile_url);
            $cached = get_transient($cache_key);
            if (is_array($cached)) { $cached['cached'] = true; $items[] = $cached; continue; }

            $path = trim((string) wp_parse_url($profile_url, PHP_URL_PATH), '/');
            $handle = $path ? rawurldecode((string) basename($path)) : '';
            $response = wp_safe_remote_get($profile_url, [
                'timeout' => 12,
                'redirection' => 4,
                'limit_response_size' => 1024 * 1024,
                'headers' => ['Accept' => 'text/html,application/xhtml+xml'],
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124 Safari/537.36 WebsiteGrowthToolkit/' . SEOPC_VERSION,
            ]);
            if (is_wp_error($response)) {
                $items[] = [
                    'platform' => $platform, 'url' => $profile_url, 'handle' => $handle,
                    'status' => 0, 'state' => 'neutral', 'message' => $response->get_error_message(),
                    'verified' => false, 'blocked' => true, 'quality_score' => null,
                    'checks' => [], 'recommendations' => [], 'cached' => false,
                ];
                continue;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);
            $title = $description = $og_image = $og_title = $og_description = $canonical = $robots = '';
            if ($body !== '' && class_exists('DOMDocument')) {
                $dom = new DOMDocument();
                libxml_use_internal_errors(true);
                if ($dom->loadHTML('<?xml encoding="utf-8" ?>' . $body, LIBXML_NOWARNING | LIBXML_NOERROR)) {
                    $xpath = new DOMXPath($dom);
                    $title = $this->node_text($xpath, '//title');
                    $description = $this->meta_content($xpath, 'name', 'description');
                    $og_image = $this->meta_content($xpath, 'property', 'og:image');
                    $og_title = $this->meta_content($xpath, 'property', 'og:title');
                    $og_description = $this->meta_content($xpath, 'property', 'og:description');
                    $canonical = $this->attribute_value($xpath, '//link[translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="canonical"]', 'href');
                    $robots = $this->meta_content($xpath, 'name', 'robots');
                }
                libxml_clear_errors();
            }

            $successful = $status >= 200 && $status < 400;
            $blocked_statuses = [400, 401, 403, 405, 406, 408, 409, 418, 425, 426, 429, 451, 500, 502, 503, 504, 520, 521, 522, 523, 524, 525, 526];
            $blocked = in_array($status, $blocked_statuses, true);
            if ($successful && in_array($platform, ['facebook', 'instagram', 'linkedin', 'tiktok', 'x', 'threads'], true)) {
                $body_probe = strtolower(substr(wp_strip_all_tags($body), 0, 40000));
                $challenge_markers = [
                    'log in to facebook', 'login • instagram', 'log in • instagram', 'sign up for tiktok',
                    'join linkedin', 'sign in to x', 'javascript is disabled', 'verify you are human',
                    'temporarily blocked', 'security check required', 'enable javascript to continue'
                ];
                $looks_like_gate = false;
                foreach ($challenge_markers as $marker) {
                    if ($marker !== '' && strpos($body_probe, $marker) !== false) { $looks_like_gate = true; break; }
                }
                if ($looks_like_gate && $description === '' && $og_description === '') $blocked = true;
            }
            $state = $blocked ? 'warn' : ($successful ? 'good' : 'bad');
            $checks = [];
            $profile_recs = [];
            $score = 0;
            if (!$blocked && $successful) {
                $checks[] = ['label' => __('Reachable profile URL', 'seo-performance-checker'), 'pass' => true]; $score += 30;
                $has_title = $title !== '' || $og_title !== '';
                $checks[] = ['label' => __('Profile title metadata', 'seo-performance-checker'), 'pass' => $has_title]; if ($has_title) $score += 20; else $profile_recs[] = __('Profile page did not expose a useful title to the automated check.', 'seo-performance-checker');
                $has_description = $description !== '' || $og_description !== '';
                $checks[] = ['label' => __('Profile description metadata', 'seo-performance-checker'), 'pass' => $has_description]; if ($has_description) $score += 20; else $profile_recs[] = __('Profile page did not expose a useful public description to the automated check.', 'seo-performance-checker');
                $checks[] = ['label' => __('Share/profile image metadata', 'seo-performance-checker'), 'pass' => $og_image !== '']; if ($og_image !== '') $score += 20; else $profile_recs[] = __('Add/confirm a recognisable avatar or share image on the profile where the platform supports it.', 'seo-performance-checker');
                $handle_ok = $handle !== '' && strlen($handle) <= 50;
                $checks[] = ['label' => __('Readable profile handle', 'seo-performance-checker'), 'pass' => $handle_ok]; if ($handle_ok) $score += 10;
            }
            $message = $blocked
                ? __('The platform limits automated profile checks. The link exists, but profile completeness cannot be verified reliably without the platform API/login.', 'seo-performance-checker')
                : ($successful ? __('Public profile response received and metadata reviewed.', 'seo-performance-checker') : sprintf(__('Profile URL returned HTTP %d.', 'seo-performance-checker'), $status));
            if ($state === 'bad') {
                if (in_array($status, [404, 410], true)) {
                    $recommendations[] = sprintf(__('Fix or replace the %s profile link because the platform returned HTTP %d.', 'seo-performance-checker'), ucfirst($platform), $status);
                } else {
                    $blocked = true;
                    $state = 'warn';
                    $message = sprintf(__('The %s profile could not be verified reliably from this server (HTTP %d). Treat this as unverified rather than a failed profile.', 'seo-performance-checker'), ucfirst($platform), $status);
                }
            }
            foreach ($profile_recs as $rec) $recommendations[] = ucfirst($platform) . ': ' . $rec;

            $item = [
                'platform' => $platform, 'url' => $profile_url, 'handle' => $handle,
                'status' => $status, 'state' => $state, 'message' => $message,
                'verified' => !$blocked && $successful, 'blocked' => $blocked,
                'quality_score' => $blocked ? null : ($successful ? min(100, $score) : 0),
                'title' => $title, 'description' => $description, 'og_title' => $og_title,
                'og_description' => $og_description, 'og_image' => $og_image, 'canonical' => $canonical,
                'robots' => $robots, 'checks' => $checks, 'recommendations' => $profile_recs,
                'cached' => false,
            ];
            set_transient($cache_key, $item, 30 * MINUTE_IN_SECONDS);
            $items[] = $item;
        }

        $scored = array_values(array_filter(array_map(function ($item) { return $item['quality_score'] ?? null; }, $items), function ($score) { return $score !== null; }));
        wp_send_json_success([
            'items' => $items,
            'average_profile_score' => $scored ? (int) round(array_sum($scored) / count($scored)) : null,
            'recommendations' => array_values(array_unique($recommendations)),
            'note' => __('Social networks often block bots or require login. Blocked profiles are marked unverified instead of being scored as poor.', 'seo-performance-checker'),
        ]);
    }

    public function ajax_design_preview() {
        $this->authorize('design_preview', 20);
        $url = $this->validated_url($_POST['url'] ?? '');
        $settings = $this->get_settings();
        $fresh = !empty($_POST['fresh']) && sanitize_text_field(wp_unslash($_POST['fresh'])) === '1';
        $cache_key = 'seopc_design_preview_v251_' . md5($url);
        $cached = $fresh ? false : get_transient($cache_key);
        if (is_array($cached)) { $cached['cached'] = true; wp_send_json_success($cached); }

        $max = min(3, max(1, (int) $settings['max_html_mb'])) * MB_IN_BYTES;
        $response = wp_safe_remote_get($url, [
            'timeout' => 22,
            'redirection' => 5,
            'limit_response_size' => $max,
            'headers' => ['Accept' => 'text/html,application/xhtml+xml'],
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124 Safari/537.36 WebsiteGrowthToolkit/' . SEOPC_VERSION,
        ]);
        if (is_wp_error($response)) wp_send_json_error(['message' => $response->get_error_message()], 502);
        $status = (int) wp_remote_retrieve_response_code($response);
        $html = (string) wp_remote_retrieve_body($response);
        if ($status >= 400 || $html === '') wp_send_json_error(['message' => sprintf(__('Preview source returned HTTP %d.', 'seo-performance-checker'), $status)], 502);
        if (!class_exists('DOMDocument')) wp_send_json_error(['message' => __('The PHP DOM extension is required for safe preview rendering.', 'seo-performance-checker')], 500);

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        if (!$loaded) wp_send_json_error(['message' => __('Could not prepare a safe preview of this page.', 'seo-performance-checker')], 500);
        $xpath = new DOMXPath($dom);
        foreach (['//script','//iframe','//object','//embed','//meta[translate(@http-equiv,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="refresh"]','//meta[translate(@http-equiv,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="content-security-policy"]'] as $query) {
            $nodes = $xpath->query($query);
            if ($nodes) for ($i = $nodes->length - 1; $i >= 0; $i--) { $node = $nodes->item($i); if ($node && $node->parentNode) $node->parentNode->removeChild($node); }
        }
        $all = $xpath->query('//*');
        if ($all) foreach ($all as $node) {
            if (!$node->hasAttributes()) continue;
            $remove = [];
            foreach ($node->attributes as $attr) {
                if (stripos($attr->name, 'on') === 0 || strtolower($attr->name) === 'srcdoc') $remove[] = $attr->name;
            }
            foreach ($remove as $attr_name) $node->removeAttribute($attr_name);
            if ($node->hasAttribute('data-src') && !$node->hasAttribute('src')) $node->setAttribute('src', $node->getAttribute('data-src'));
            if ($node->hasAttribute('data-lazy-src') && !$node->hasAttribute('src')) $node->setAttribute('src', $node->getAttribute('data-lazy-src'));
        }
        $heads = $dom->getElementsByTagName('head');
        $head = $heads->length ? $heads->item(0) : null;
        if ($head) {
            $base = $dom->createElement('base');
            $base->setAttribute('href', $url);
            $head->insertBefore($base, $head->firstChild);
            $style = $dom->createElement('style', 'html,body{margin:0!important;min-height:100%;} *{box-sizing:border-box;} form{pointer-events:none!important;}');
            $head->appendChild($style);
        }
        $title_nodes = $dom->getElementsByTagName('title');
        $title = $title_nodes->length ? trim($title_nodes->item(0)->textContent) : '';
        $safe_html = $dom->saveHTML();
        $result = [
            'url' => $url, 'title' => $title, 'status' => $status, 'html' => $safe_html,
            'bytes' => strlen($safe_html), 'cached' => false,
            'note' => __('Preview is a sandboxed server-HTML snapshot with scripts disabled. JavaScript-rendered widgets may be incomplete, but X-Frame-Options/CSP cannot break this preview.', 'seo-performance-checker'),
        ];
        set_transient($cache_key, $result, max(5, (int) $settings['cache_minutes']) * MINUTE_IN_SECONDS);
        wp_send_json_success($result);
    }

    public function ajax_fetch_media() {
        $this->authorize('fetch_media', null, false);
        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        $trigger_url = isset($_POST['download_trigger_url']) ? esc_url_raw(wp_unslash($_POST['download_trigger_url'])) : '';
        $sig = sanitize_text_field(wp_unslash($_POST['media_sig'] ?? ''));
        $exp = absint($_POST['media_exp'] ?? 0);
        if (!$this->verify_media_signature($url, $trigger_url, $sig, $exp)) {
            status_header(403);
            echo 'Invalid media signature.';
            exit;
        }
        $url = $this->validated_url($url);
        $settings = $this->get_settings();

        if ($trigger_url !== '') {
            $trigger_host = strtolower((string) wp_parse_url($trigger_url, PHP_URL_HOST));
            $trigger_scheme = strtolower((string) wp_parse_url($trigger_url, PHP_URL_SCHEME));
            if ($trigger_scheme !== 'https' || $trigger_host !== 'api.unsplash.com' || empty($settings['unsplash_access_key'])) {
                status_header(403);
                echo 'Invalid download tracking endpoint.';
                exit;
            }
            $tracked_url = add_query_arg('client_id', $settings['unsplash_access_key'], $trigger_url);
            $tracked = wp_remote_get($tracked_url, ['timeout' => 10, 'redirection' => 1, 'headers' => ['Accept-Version' => 'v1']]);
            if (is_wp_error($tracked) || (int) wp_remote_retrieve_response_code($tracked) >= 400) {
                status_header(502);
                echo 'The media provider could not register this download.';
                exit;
            }
        }

        $limit = (int) $settings['max_media_mb'] * MB_IN_BYTES;
        $response = wp_safe_remote_get($url, [
            'timeout' => 35,
            'redirection' => 3,
            'limit_response_size' => $limit,
            'user-agent' => 'WP SEO Performance Checker/' . SEOPC_VERSION,
        ]);
        if (is_wp_error($response)) {
            status_header(502);
            echo esc_html($response->get_error_message());
            exit;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $content_type = (string) wp_remote_retrieve_header($response, 'content-type');
        $content_type = trim(explode(';', $content_type)[0]);
        if ($code >= 400 || (!$this->starts_with($content_type, 'image/') && !$this->starts_with($content_type, 'video/'))) {
            status_header(415);
            echo 'Remote file is not a supported image or video.';
            exit;
        }
        if (strlen($body) >= $limit) {
            status_header(413);
            echo 'Remote file exceeds the configured media limit.';
            exit;
        }

        nocache_headers();
        header('Content-Type: ' . $content_type);
        header('Content-Length: ' . strlen($body));
        header('Content-Disposition: inline; filename="' . sanitize_file_name(basename((string) wp_parse_url($url, PHP_URL_PATH)) ?: 'media') . '"');
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary response.
        exit;
    }

    public function body_classes($classes) {
        if (!$this->page_has_toolkit()) {
            return $classes;
        }
        $settings = $this->get_settings();
        $classes[] = 'seopc-toolkit-page';
        if (!empty($settings['full_screen'])) {
            $classes[] = 'seopc-toolkit-fullscreen';
        }
        if (!empty($settings['hide_theme_chrome'])) {
            $classes[] = 'seopc-toolkit-hide-theme-chrome';
        }
        return array_values(array_unique($classes));
    }

    private function page_has_toolkit() {
        if (!is_singular()) {
            return false;
        }
        $post = get_post();
        if (!$post instanceof WP_Post) {
            return false;
        }
        return has_shortcode($post->post_content, 'seopc_toolkit') || has_shortcode($post->post_content, 'seo_toolkit');
    }

    private function encrypt_secret($value) {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        $key = hash('sha256', wp_salt('auth') . '|seopc-toolkit-password', true);

        if (function_exists('sodium_crypto_secretbox') && defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES')) {
            try {
                $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                return 's1:' . base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, $key));
            } catch (Exception $e) {
                // Continue to OpenSSL fallback.
            }
        }

        if (function_exists('openssl_encrypt')) {
            try {
                $iv = random_bytes(12);
                $tag = '';
                $cipher = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
                if ($cipher !== false) {
                    return 'o1:' . base64_encode($iv . $tag . $cipher);
                }
            } catch (Exception $e) {
                // Continue to compatibility fallback.
            }
        }

        try {
            $nonce = random_bytes(16);
            return 'x1:' . base64_encode($nonce . $this->xor_secret($value, $key, $nonce));
        } catch (Exception $e) {
            return '';
        }
    }

    private function xor_secret($value, $key, $nonce) {
        $value = (string) $value;
        $stream = '';
        $counter = 0;
        while (strlen($stream) < strlen($value)) {
            $stream .= hash_hmac('sha256', $nonce . pack('N', $counter), $key, true);
            $counter++;
        }
        return $value ^ substr($stream, 0, strlen($value));
    }

    private function decrypt_secret($stored) {
        $stored = (string) $stored;
        if ($stored === '' || strpos($stored, ':') === false) {
            return '';
        }
        list($version, $payload) = explode(':', $stored, 2);
        $raw = base64_decode($payload, true);
        if ($raw === false) {
            return '';
        }
        $key = hash('sha256', wp_salt('auth') . '|seopc-toolkit-password', true);

        if ($version === 's1' && function_exists('sodium_crypto_secretbox_open') && defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES')) {
            $nonce_length = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            if (strlen($raw) <= $nonce_length) {
                return '';
            }
            $plain = sodium_crypto_secretbox_open(substr($raw, $nonce_length), substr($raw, 0, $nonce_length), $key);
            return $plain === false ? '' : (string) $plain;
        }

        if ($version === 'o1' && function_exists('openssl_decrypt') && strlen($raw) > 28) {
            $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
            return $plain === false ? '' : (string) $plain;
        }

        if ($version === 'x1' && strlen($raw) > 16) {
            return $this->xor_secret(substr($raw, 16), $key, substr($raw, 0, 16));
        }

        // Compatibility for an early development build that stored an encoded value.
        return $version === 'b1' ? (string) $raw : '';
    }

    private function check_nonce() {
        if (!check_ajax_referer('seopc_toolkit_public', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security token expired. Reload the page.', 'seo-performance-checker')], 403);
        }
    }

    private function authorize($bucket, $custom_limit = null, $json = true) {
        $this->check_nonce();
        $settings = $this->get_settings();
        if (!empty($settings['password_protection'])) {
            $token = isset($_POST['access_token']) ? (string) wp_unslash($_POST['access_token']) : '';
            if (!$this->validate_token($token)) {
                if ($json) {
                    wp_send_json_error(['message' => __('Your toolkit session expired. Unlock it again.', 'seo-performance-checker')], 403);
                }
                status_header(403);
                echo 'Toolkit session expired.';
                exit;
            }
        }
        $this->enforce_rate_limit($bucket, $custom_limit, $json);
    }

    private function issue_token() {
        $settings = $this->get_settings();
        $ttl = min(self::TOKEN_TTL_MAX, max(300, (int) $settings['session_minutes'] * MINUTE_IN_SECONDS));
        $payload = [
            'exp' => time() + $ttl,
            'iat' => time(),
            'fp' => $this->fingerprint(),
            'v' => 1,
        ];
        $encoded = $this->base64url_encode(wp_json_encode($payload));
        $signature = hash_hmac('sha256', $encoded, wp_salt('auth'));
        return $encoded . '.' . $signature;
    }

    private function validate_token($token) {
        if (!is_string($token) || strpos($token, '.') === false) {
            return false;
        }
        list($encoded, $signature) = explode('.', $token, 2);
        $expected = hash_hmac('sha256', $encoded, wp_salt('auth'));
        if (!hash_equals($expected, $signature)) {
            return false;
        }
        $payload = json_decode($this->base64url_decode($encoded), true);
        if (!is_array($payload) || empty($payload['exp']) || (int) $payload['exp'] < time()) {
            return false;
        }
        return !empty($payload['fp']) && hash_equals((string) $payload['fp'], $this->fingerprint());
    }

    private function fingerprint() {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        return hash_hmac('sha256', $ua, wp_salt('nonce'));
    }

    private function base64url_encode($data) {
        return rtrim(strtr(base64_encode((string) $data), '+/', '-_'), '=');
    }

    private function base64url_decode($data) {
        return (string) base64_decode(strtr((string) $data, '-_', '+/'));
    }

    private function enforce_rate_limit($bucket, $custom_limit = null, $json = true) {
        $settings = $this->get_settings();
        $limit = $custom_limit ?: (int) $settings['rate_limit_hour'];
        if (current_user_can('manage_options')) {
            $limit *= 5;
        }
        $key = 'seopc_rl_' . md5($bucket . '|' . $this->client_ip());
        $count = (int) get_transient($key);
        if ($count >= $limit) {
            if ($json) {
                wp_send_json_error(['message' => __('Rate limit reached. Try again later.', 'seo-performance-checker')], 429);
            }
            status_header(429);
            echo 'Rate limit reached.';
            exit;
        }
        set_transient($key, $count + 1, HOUR_IN_SECONDS);
    }

    private function client_ip() {
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
    }

    private function validated_url($raw) {
        $raw = trim((string) wp_unslash($raw));
        if (!preg_match('#^https?://#i', $raw)) {
            $raw = 'https://' . $raw;
        }
        $url = esc_url_raw($raw, ['http', 'https']);
        if (!$url || !wp_http_validate_url($url)) {
            wp_send_json_error(['message' => __('Enter a valid public HTTP or HTTPS URL.', 'seo-performance-checker')], 400);
        }
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if (!$host || $host === 'localhost' || substr($host, -6) === '.local' || $this->is_private_host($host)) {
            wp_send_json_error(['message' => __('Private, local and reserved network targets are blocked.', 'seo-performance-checker')], 400);
        }
        return $url;
    }

    private function is_private_host($host) {
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $a = gethostbynamel($host);
            if (is_array($a)) {
                $ips = array_merge($ips, $a);
            }
            if (function_exists('dns_get_record') && defined('DNS_AAAA')) {
                $aaaa = @dns_get_record($host, DNS_AAAA);
                if (is_array($aaaa)) {
                    foreach ($aaaa as $record) {
                        if (!empty($record['ipv6'])) {
                            $ips[] = $record['ipv6'];
                        }
                    }
                }
            }
        }
        if (empty($ips)) {
            return true;
        }
        foreach (array_unique($ips) as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return true;
            }
        }
        return false;
    }

    private function quick_audit($url, $settings) {
        if (!class_exists('DOMDocument')) {
            return new WP_Error('dom_missing', __('The PHP DOM extension is required for front-end HTML audits.', 'seo-performance-checker'));
        }

        $start = microtime(true);
        $response = wp_safe_remote_get($url, [
            'timeout' => 22,
            'redirection' => 5,
            'limit_response_size' => max(1, (int) $settings['max_html_mb']) * MB_IN_BYTES,
            'headers' => ['Accept' => 'text/html,application/xhtml+xml'],
            'user-agent' => 'Mozilla/5.0 (compatible; WP SEO Performance Checker/' . SEOPC_VERSION . '; +' . home_url('/') . ')',
        ]);
        $elapsed_ms = (int) round((microtime(true) - $start) * 1000);
        if (is_wp_error($response)) {
            return $response;
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $headers = wp_remote_retrieve_headers($response);
        $content_type = (string) wp_remote_retrieve_header($response, 'content-type');
        if (stripos($content_type, 'text/html') === false && stripos($content_type, 'application/xhtml') === false) {
            return new WP_Error('not_html', __('The target did not return an HTML document.', 'seo-performance-checker'));
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $body, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        if (!$loaded) {
            return new WP_Error('parse_error', __('The page HTML could not be parsed.', 'seo-performance-checker'));
        }
        $xpath = new DOMXPath($dom);
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));

        $title = $this->node_text($xpath, '//title');
        $description = $this->meta_content($xpath, 'name', 'description');
        $robots = $this->meta_content($xpath, 'name', 'robots');
        $canonical = $this->attribute_value($xpath, '//link[contains(concat(" ", translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"), " "), " canonical ")]', 'href');
        $viewport = $this->meta_content($xpath, 'name', 'viewport');
        $lang = $this->attribute_value($xpath, '//html', 'lang');
        $charset = $this->attribute_value($xpath, '//meta[@charset]', 'charset');
        if (!$charset) {
            $charset = $this->meta_content($xpath, 'http-equiv', 'content-type');
        }

        $headings = [];
        $heading_counts = [];
        $hierarchy_issues = [];
        $previous = 0;
        for ($level = 1; $level <= 6; $level++) {
            $heading_counts['h' . $level] = 0;
        }
        // Count literal HTML headings from the visible document body only. Do not fold
        // ARIA role=heading elements into H1 totals: SEO tools report actual <h1>
        // elements, and combining the two can create false multiple-H1 warnings.
        // Also exclude headings inside template/noscript/svg containers, which are
        // support/fallback markup rather than the normal rendered document structure.
        $heading_nodes = $xpath->query('//body//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6][not(ancestor::template) and not(ancestor::noscript) and not(ancestor::svg)]');
        if ($heading_nodes) {
            foreach ($heading_nodes as $node) {
                $level = (int) substr(strtolower($node->nodeName), 1);
                $text = trim(preg_replace('/\s+/', ' ', $node->textContent));
                $heading_counts['h' . $level]++;
                if ($previous && $level > ($previous + 1)) {
                    $hierarchy_issues[] = 'H' . $previous . ' jumps to H' . $level . ': ' . $this->text_substr($text, 0, 80);
                }
                $headings[] = ['level' => $level, 'text' => $this->text_substr($text, 0, 180)];
                $previous = $level;
            }
        }

        // Track ARIA level-one heading roles separately for accessibility diagnostics,
        // but never count them as literal H1 elements in the SEO result.
        $aria_level1_count = 0;
        $aria_h1_nodes = $xpath->query('//body//*[@role="heading" and @aria-level="1"][not(self::h1)][not(ancestor::template) and not(ancestor::noscript) and not(ancestor::svg)]');
        if ($aria_h1_nodes) {
            $aria_level1_count = (int) $aria_h1_nodes->length;
        }

        // Raw fallback is body-scoped and strips non-rendered/support containers so a
        // duplicate H1 embedded in template/noscript data cannot inflate the count.
        $heading_html = $body;
        if (preg_match('/<body\b[^>]*>(.*?)<\/body>/is', $heading_html, $heading_body_match)) {
            $heading_html = $heading_body_match[1];
        }
        $heading_html = preg_replace('#<(script|style|template|noscript|svg)\b[^>]*>.*?</\1>#is', '', $heading_html);
        $raw_h1_count = is_string($heading_html) ? preg_match_all('/<h1\b[^>]*>/i', $heading_html, $raw_h1_matches) : 0;
        if ($raw_h1_count > $heading_counts['h1']) {
            // DOMDocument can repair malformed markup in ways that hide a real literal H1.
            $heading_counts['h1'] = (int) $raw_h1_count;
        }
        $client_render_markers = preg_match('/(?:id=["\'](?:root|app|__next)["\']|data-reactroot|ng-version|__NEXT_DATA__|webpackJsonp)/i', $body) === 1;
        $challenge_markers = preg_match('/(?:just a moment|checking your browser|enable javascript and cookies|cf-chl-|captcha)/i', $body) === 1;
        $heading_detection = ($heading_counts['h1'] > 0) ? 'confirmed' : (($client_render_markers || $challenge_markers) ? 'unverified' : 'server-html');

        $images = ['count' => 0, 'missing_alt' => 0, 'empty_alt' => 0, 'missing_dimensions' => 0, 'without_lazy' => 0, 'modern_format' => 0, 'issues' => []];
        $resource_urls = [];
        $image_nodes = $xpath->query('//img');
        if ($image_nodes) {
            foreach ($image_nodes as $index => $node) {
                $images['count']++;
                if (!$node->hasAttribute('alt')) {
                    $images['missing_alt']++;
                } elseif (trim($node->getAttribute('alt')) === '') {
                    $images['empty_alt']++;
                }
                if (!$node->hasAttribute('width') || !$node->hasAttribute('height')) {
                    $images['missing_dimensions']++;
                }
                if ($index > 1 && strtolower($node->getAttribute('loading')) !== 'lazy') {
                    $images['without_lazy']++;
                }
                $src = $node->getAttribute('src');
                $absolute_src = $src ? $this->absolute_url($src, $url) : '';
                if ($src) {
                    $resource_urls[] = $absolute_src;
                    $ext = strtolower(pathinfo((string) wp_parse_url($src, PHP_URL_PATH), PATHINFO_EXTENSION));
                    if (in_array($ext, ['avif', 'webp', 'svg'], true)) {
                        $images['modern_format']++;
                    }
                }
                $image_problems = [];
                if (!$node->hasAttribute('alt')) $image_problems[] = 'Missing alt attribute';
                elseif (trim($node->getAttribute('alt')) === '') $image_problems[] = 'Empty alt text';
                if (!$node->hasAttribute('width') || !$node->hasAttribute('height')) $image_problems[] = 'Missing width/height';
                if ($index > 1 && strtolower($node->getAttribute('loading')) !== 'lazy') $image_problems[] = 'Not lazy-loaded';
                if ($image_problems && count($images['issues']) < 120) {
                    $images['issues'][] = [
                        'url' => $absolute_src,
                        'alt' => $node->hasAttribute('alt') ? $this->text_substr(trim($node->getAttribute('alt')), 0, 180) : '',
                        'width' => $node->getAttribute('width'),
                        'height' => $node->getAttribute('height'),
                        'loading' => $node->getAttribute('loading'),
                        'issues' => $image_problems,
                    ];
                }
            }
        }

        $scripts = [];
        $script_nodes = $xpath->query('//script[@src]');
        if ($script_nodes) {
            foreach ($script_nodes as $node) {
                $src = $this->absolute_url($node->getAttribute('src'), $url);
                if ($src) {
                    $scripts[] = $src;
                    $resource_urls[] = $src;
                }
            }
        }
        $stylesheets = [];
        $render_blocking = 0;
        $style_nodes = $xpath->query('//link[contains(concat(" ", translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"), " "), " stylesheet ")]');
        if ($style_nodes) {
            foreach ($style_nodes as $node) {
                $href = $this->absolute_url($node->getAttribute('href'), $url);
                if ($href) {
                    $stylesheets[] = $href;
                    $resource_urls[] = $href;
                    if (!$node->hasAttribute('media') || strtolower($node->getAttribute('media')) === 'all') {
                        $render_blocking++;
                    }
                }
            }
        }
        $iframes = [];
        $iframe_nodes = $xpath->query('//iframe[@src]');
        if ($iframe_nodes) {
            foreach ($iframe_nodes as $node) {
                $src = $this->absolute_url($node->getAttribute('src'), $url);
                if ($src) {
                    $iframes[] = $src;
                    $resource_urls[] = $src;
                }
            }
        }

        $links = ['internal' => 0, 'external' => 0, 'nofollow' => 0, 'empty' => 0];
        $link_nodes = $xpath->query('//a[@href]');
        if ($link_nodes) {
            foreach ($link_nodes as $node) {
                $href = trim($node->getAttribute('href'));
                if ($href === '' || $href === '#') {
                    $links['empty']++;
                    continue;
                }
                $absolute = $this->absolute_url($href, $url);
                $link_host = strtolower((string) wp_parse_url($absolute, PHP_URL_HOST));
                if (!$link_host || $link_host === $host) {
                    $links['internal']++;
                } else {
                    $links['external']++;
                }
                if (stripos(' ' . $node->getAttribute('rel') . ' ', ' nofollow ') !== false) {
                    $links['nofollow']++;
                }
            }
        }

        $body_node = $xpath->query('//body')->item(0);
        $visible_text = $body_node ? trim(preg_replace('/\s+/', ' ', $body_node->textContent)) : '';
        $word_count = $visible_text ? str_word_count(wp_strip_all_tags($visible_text)) : 0;
        $paragraph_nodes = $xpath->query('//p');
        $long_paragraphs = 0;
        if ($paragraph_nodes) {
            foreach ($paragraph_nodes as $paragraph) {
                if (str_word_count(trim($paragraph->textContent)) > 120) $long_paragraphs++;
            }
        }
        $vague_link_text = 0;
        if ($link_nodes) {
            foreach ($link_nodes as $node) {
                $anchor_text = strtolower(trim(preg_replace('/\s+/', ' ', $node->textContent)));
                if (in_array($anchor_text, ['click here', 'read more', 'learn more', 'more', 'here'], true)) $vague_link_text++;
            }
        }

        $social_platforms = $this->social_platform_domains();
        $social_profiles = [];
        if ($link_nodes) {
            foreach ($link_nodes as $node) {
                $href = $this->absolute_url($node->getAttribute('href'), $url);
                $link_host = strtolower((string) wp_parse_url($href, PHP_URL_HOST));
                if (!$link_host) continue;
                foreach ($social_platforms as $platform => $domains) {
                    foreach ($domains as $domain) {
                        if ($link_host === $domain || substr($link_host, -strlen('.' . $domain)) === '.' . $domain) {
                            $social_profiles[$platform] = $href;
                            break 2;
                        }
                    }
                }
            }
        }
        $og = [
            'title' => $this->meta_content($xpath, 'property', 'og:title'),
            'description' => $this->meta_content($xpath, 'property', 'og:description'),
            'image' => $this->meta_content($xpath, 'property', 'og:image'),
            'type' => $this->meta_content($xpath, 'property', 'og:type'),
            'site_name' => $this->meta_content($xpath, 'property', 'og:site_name'),
            'url' => $this->meta_content($xpath, 'property', 'og:url'),
        ];
        $twitter = [
            'card' => $this->meta_content($xpath, 'name', 'twitter:card'),
            'title' => $this->meta_content($xpath, 'name', 'twitter:title'),
            'description' => $this->meta_content($xpath, 'name', 'twitter:description'),
            'image' => $this->meta_content($xpath, 'name', 'twitter:image'),
            'site' => $this->meta_content($xpath, 'name', 'twitter:site'),
        ];
        $social_score = 0;
        $social_score += !empty($og['title']) ? 15 : 0;
        $social_score += !empty($og['description']) ? 15 : 0;
        $social_score += !empty($og['image']) ? 20 : 0;
        $social_score += !empty($twitter['card']) ? 15 : 0;
        $social_score += !empty($twitter['image']) ? 10 : 0;
        $social_score += min(25, count($social_profiles) * 5);
        $social_recommendations = [];

        $schema_count = 0;
        $schema_types = [];
        $schema_same_as = [];
        $schema_nodes = $xpath->query('//script[@type="application/ld+json"]');
        if ($schema_nodes) {
            foreach ($schema_nodes as $node) {
                $schema_count++;
                $json = json_decode(trim($node->textContent), true);
                $this->collect_schema_types($json, $schema_types);
                $this->collect_schema_same_as($json, $schema_same_as);
            }
        }

        $schema_same_as = array_values(array_unique(array_filter($schema_same_as)));
        $same_as_platforms = [];
        foreach ($schema_same_as as $same_url) {
            $same_host = strtolower((string) wp_parse_url($same_url, PHP_URL_HOST));
            if (!$same_host) continue;
            foreach ($social_platforms as $platform => $domains) {
                foreach ($domains as $domain) {
                    if ($same_host === $domain || substr($same_host, -strlen('.' . $domain)) === '.' . $domain) {
                        $same_as_platforms[$platform] = $same_url;
                        break 2;
                    }
                }
            }
        }
        $linked_not_in_schema = array_diff_key($social_profiles, $same_as_platforms);
        if (empty($og['title']) || empty($og['description']) || empty($og['image'])) $social_recommendations[] = __('Complete Open Graph title, description and image tags for richer sharing previews.', 'seo-performance-checker');
        if (!empty($og['title']) && ($this->text_length($og['title']) < 15 || $this->text_length($og['title']) > 70)) $social_recommendations[] = __('Tune og:title to a concise share headline, usually around 15–70 characters.', 'seo-performance-checker');
        if (!empty($og['description']) && ($this->text_length($og['description']) < 55 || $this->text_length($og['description']) > 200)) $social_recommendations[] = __('Tune og:description so social previews are informative without being cut off.', 'seo-performance-checker');
        if (!empty($og['image']) && stripos($og['image'], 'https://') !== 0) $social_recommendations[] = __('Serve the Open Graph image over HTTPS.', 'seo-performance-checker');
        if (empty($twitter['card'])) $social_recommendations[] = __('Add Twitter/X card metadata so shared links have a predictable preview.', 'seo-performance-checker');
        if (!empty($twitter['card']) && empty($twitter['image']) && empty($og['image'])) $social_recommendations[] = __('Add a dedicated X/Twitter image or an Open Graph image fallback.', 'seo-performance-checker');
        if (count($social_profiles) < 2) $social_recommendations[] = __('Link the website to the company social profiles so users and crawlers can confirm the brand footprint.', 'seo-performance-checker');
        if (!empty($linked_not_in_schema)) $social_recommendations[] = __('Add every confirmed social profile to Organization sameAs structured data for stronger entity consistency.', 'seo-performance-checker');
        if (empty($og['site_name'])) $social_recommendations[] = __('Add og:site_name to strengthen brand consistency in link previews.', 'seo-performance-checker');

        $x_frame_options = strtolower(trim((string) wp_remote_retrieve_header($response, 'x-frame-options')));
        $content_security_policy = (string) wp_remote_retrieve_header($response, 'content-security-policy');
        $frame_allowed = true;
        $frame_reason = '';
        $toolkit_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if (strpos($x_frame_options, 'deny') !== false) {
            $frame_allowed = false;
            $frame_reason = __('The website sends X-Frame-Options: DENY, so browsers block embedded preview.', 'seo-performance-checker');
        } elseif (strpos($x_frame_options, 'sameorigin') !== false && $toolkit_host !== $host) {
            $frame_allowed = false;
            $frame_reason = __('The website allows framing only from its own origin.', 'seo-performance-checker');
        }
        if ($frame_allowed && preg_match('/frame-ancestors\s+([^;]+)/i', $content_security_policy, $frame_match)) {
            $ancestors = strtolower(trim($frame_match[1]));
            $self_allowed = strpos($ancestors, "'self'") !== false && $toolkit_host === $host;
            $wildcard_allowed = preg_match('/(?:^|\s)\*(?:$|\s)/', $ancestors);
            $host_allowed = $toolkit_host && strpos($ancestors, $toolkit_host) !== false;
            if (strpos($ancestors, "'none'") !== false || (!$self_allowed && !$wildcard_allowed && !$host_allowed)) {
                $frame_allowed = false;
                $frame_reason = __('The website Content-Security-Policy blocks this toolkit from embedding the page.', 'seo-performance-checker');
            }
        }

        $fixed_width_count = 0;
        $max_fixed_width = 0;
        $tiny_text_count = 0;
        $extreme_z_count = 0;
        $styled_nodes = $xpath->query('//*[@style]');
        if ($styled_nodes) {
            foreach ($styled_nodes as $styled_node) {
                $style = strtolower((string) $styled_node->getAttribute('style'));
                if (preg_match_all('/(?:^|;)\s*(?:min-)?width\s*:\s*(\d{3,5})px/i', $style, $matches)) {
                    foreach ($matches[1] as $px) {
                        $px = (int) $px;
                        if ($px > 420) $fixed_width_count++;
                        if ($px > $max_fixed_width) $max_fixed_width = $px;
                    }
                }
                if (preg_match('/font-size\s*:\s*(\d+(?:\.\d+)?)px/i', $style, $font_match) && (float) $font_match[1] < 12) $tiny_text_count++;
                if (preg_match('/z-index\s*:\s*(\d+)/i', $style, $z_match) && (int) $z_match[1] > 9999) $extreme_z_count++;
            }
        }
        $missing_image_dimensions = 0;
        $image_nodes_for_design = $xpath->query('//img');
        if ($image_nodes_for_design) {
            foreach ($image_nodes_for_design as $image_node) {
                if (!$image_node->hasAttribute('width') || !$image_node->hasAttribute('height')) $missing_image_dimensions++;
            }
        }
        $unlabelled_controls = 0;
        $controls = $xpath->query('//input[not(translate(@type,"HIDDEN","hidden")="hidden")]|//select|//textarea');
        if ($controls) {
            foreach ($controls as $control) {
                if ($xpath->query('ancestor::label', $control)->length) continue;
                $id = trim((string) $control->getAttribute('id'));
                if ($id && $xpath->query('//label[@for=' . $this->xpath_literal($id) . ']')->length) continue;
                if ($control->hasAttribute('aria-label') || $control->hasAttribute('aria-labelledby')) continue;
                $unlabelled_controls++;
            }
        }
        $design_score = 100;
        $design_issues = [];
        if (!$viewport) { $design_score -= 30; $design_issues[] = __('Missing viewport meta tag can break mobile layout.', 'seo-performance-checker'); }
        if ($fixed_width_count > 0) { $design_score -= min(25, $fixed_width_count * 4); $design_issues[] = sprintf(_n('%d large fixed-width inline style may overflow small screens.', '%d large fixed-width inline styles may overflow small screens.', $fixed_width_count, 'seo-performance-checker'), $fixed_width_count); }
        if ($missing_image_dimensions > 0) { $design_score -= min(15, $missing_image_dimensions); $design_issues[] = sprintf(_n('%d image lacks width/height attributes, increasing layout-shift risk.', '%d images lack width/height attributes, increasing layout-shift risk.', $missing_image_dimensions, 'seo-performance-checker'), $missing_image_dimensions); }
        if ($tiny_text_count > 0) { $design_score -= min(12, $tiny_text_count * 2); $design_issues[] = __('Very small inline font sizes were detected.', 'seo-performance-checker'); }
        if ($unlabelled_controls > 0) { $design_score -= min(18, $unlabelled_controls * 3); $design_issues[] = sprintf(_n('%d form control appears to lack an accessible label.', '%d form controls appear to lack accessible labels.', $unlabelled_controls, 'seo-performance-checker'), $unlabelled_controls); }
        if ($extreme_z_count > 0) { $design_score -= min(8, $extreme_z_count * 2); $design_issues[] = __('Extreme z-index values were detected and may indicate stacking-context conflicts.', 'seo-performance-checker'); }
        $design_score = max(0, $design_score);

        $third_party = [];
        foreach (array_unique(array_filter($resource_urls)) as $resource_url) {
            $resource_host = strtolower((string) wp_parse_url($resource_url, PHP_URL_HOST));
            if ($resource_host && $resource_host !== $host) {
                $third_party[$resource_host] = isset($third_party[$resource_host]) ? $third_party[$resource_host] + 1 : 1;
            }
        }
        arsort($third_party);

        $security_headers = [
            'strict-transport-security' => (string) wp_remote_retrieve_header($response, 'strict-transport-security'),
            'content-security-policy' => (string) wp_remote_retrieve_header($response, 'content-security-policy'),
            'x-content-type-options' => (string) wp_remote_retrieve_header($response, 'x-content-type-options'),
            'x-frame-options' => (string) wp_remote_retrieve_header($response, 'x-frame-options'),
            'referrer-policy' => (string) wp_remote_retrieve_header($response, 'referrer-policy'),
            'permissions-policy' => (string) wp_remote_retrieve_header($response, 'permissions-policy'),
        ];
        $security_present = count(array_filter($security_headers));

        $seo_score = 100;
        $issues = [];
        $this->score_check($title !== '', 14, __('Missing title tag.', 'seo-performance-checker'), $seo_score, $issues);
        if ($title && ($this->text_length($title) < 25 || $this->text_length($title) > 65)) {
            $seo_score -= 5;
            $issues[] = __('Title length should usually be about 25–65 characters.', 'seo-performance-checker');
        }
        $this->score_check($description !== '', 12, __('Missing meta description.', 'seo-performance-checker'), $seo_score, $issues);
        if ($description && ($this->text_length($description) < 70 || $this->text_length($description) > 170)) {
            $seo_score -= 4;
            $issues[] = __('Meta description length is outside the usual 70–170 character range.', 'seo-performance-checker');
        }
        if ($heading_detection === 'unverified' && $heading_counts['h1'] === 0) {
            $seo_score -= 2;
            $issues[] = __('H1 could not be verified in the server HTML. The page appears to use client-side rendering or an anti-bot response; verify the rendered page before treating this as missing.', 'seo-performance-checker');
        } else {
            $this->score_check($heading_counts['h1'] === 1, 12, $heading_counts['h1'] === 0 ? __('Missing H1.', 'seo-performance-checker') : __('More than one H1 found.', 'seo-performance-checker'), $seo_score, $issues);
        }
        $this->score_check($canonical !== '', 6, __('Missing canonical link.', 'seo-performance-checker'), $seo_score, $issues);
        $this->score_check($viewport !== '', 5, __('Missing viewport meta tag.', 'seo-performance-checker'), $seo_score, $issues);
        $this->score_check($lang !== '', 4, __('Missing html lang attribute.', 'seo-performance-checker'), $seo_score, $issues);
        if ($images['missing_alt'] > 0) {
            $seo_score -= min(10, $images['missing_alt'] * 2);
            $issues[] = sprintf(_n('%d image is missing alt.', '%d images are missing alt.', $images['missing_alt'], 'seo-performance-checker'), $images['missing_alt']);
        }
        if (!empty($hierarchy_issues)) {
            $seo_score -= min(8, count($hierarchy_issues) * 2);
            $issues[] = __('Heading hierarchy has skipped levels.', 'seo-performance-checker');
        }
        if ($status < 200 || $status >= 400) {
            $seo_score -= 20;
            $issues[] = sprintf(__('HTTP status is %d.', 'seo-performance-checker'), $status);
        }
        $seo_score = max(0, $seo_score);

        $title_length = $this->text_length($title);
        $description_length = $this->text_length($description);
        $h1_count = (int) $heading_counts['h1'];
        $has_noindex = (bool) preg_match('/(?:^|[,\s])noindex(?:$|[,\s])/i', $robots);
        $missing_alt = (int) $images['missing_alt'];
        $hierarchy_count = count($hierarchy_issues);

        $seo_checks = [
            [
                'id' => 'h1',
                'label' => __('H1 headings', 'seo-performance-checker'),
                'status' => $h1_count === 1 ? 'good' : ($heading_detection === 'unverified' ? 'warn' : 'bad'),
                'value' => sprintf(_n('%d found', '%d found', $h1_count, 'seo-performance-checker'), $h1_count),
                'message' => $h1_count === 1
                    ? __('The page has one clear primary heading.', 'seo-performance-checker')
                    : ($heading_detection === 'unverified'
                        ? __('No H1 was found in the server response, but the page looks client-rendered or protected. This is not treated as a confirmed missing H1.', 'seo-performance-checker')
                        : ($h1_count === 0
                            ? __('The server-rendered page has no H1 heading.', 'seo-performance-checker')
                            : __('The page has multiple H1 headings.', 'seo-performance-checker'))),
                'ideal' => __('Recommended: exactly 1 H1 per page.', 'seo-performance-checker'),
            ],
            [
                'id' => 'title',
                'label' => __('Title tag', 'seo-performance-checker'),
                'status' => $title === '' ? 'bad' : (($title_length >= 25 && $title_length <= 65) ? 'good' : 'warn'),
                'value' => sprintf(__('%d characters', 'seo-performance-checker'), $title_length),
                'message' => $title === ''
                    ? __('The title tag is missing.', 'seo-performance-checker')
                    : (($title_length >= 25 && $title_length <= 65)
                        ? __('The title length is in the recommended range.', 'seo-performance-checker')
                        : __('The title is outside the usual recommended range.', 'seo-performance-checker')),
                'ideal' => __('Recommended: about 25–65 characters.', 'seo-performance-checker'),
            ],
            [
                'id' => 'description',
                'label' => __('Meta description', 'seo-performance-checker'),
                'status' => $description === '' ? 'bad' : (($description_length >= 70 && $description_length <= 170) ? 'good' : 'warn'),
                'value' => sprintf(__('%d characters', 'seo-performance-checker'), $description_length),
                'message' => $description === ''
                    ? __('The meta description is missing.', 'seo-performance-checker')
                    : (($description_length >= 70 && $description_length <= 170)
                        ? __('The description length is in the recommended range.', 'seo-performance-checker')
                        : __('The description is outside the usual recommended range.', 'seo-performance-checker')),
                'ideal' => __('Recommended: about 70–170 characters.', 'seo-performance-checker'),
            ],
            [
                'id' => 'canonical',
                'label' => __('Canonical URL', 'seo-performance-checker'),
                'status' => $canonical !== '' ? 'good' : 'warn',
                'value' => $canonical !== '' ? __('Present', 'seo-performance-checker') : __('Missing', 'seo-performance-checker'),
                'message' => $canonical !== ''
                    ? __('A canonical URL was detected.', 'seo-performance-checker')
                    : __('No canonical link was detected.', 'seo-performance-checker'),
                'ideal' => __('Recommended: a self-referencing or intentional canonical URL.', 'seo-performance-checker'),
            ],
            [
                'id' => 'image-alt',
                'label' => __('Image alt attributes', 'seo-performance-checker'),
                'status' => $missing_alt === 0 ? 'good' : ($missing_alt <= 2 ? 'warn' : 'bad'),
                'value' => sprintf(_n('%d missing', '%d missing', $missing_alt, 'seo-performance-checker'), $missing_alt),
                'message' => $missing_alt === 0
                    ? __('All detected images have an alt attribute.', 'seo-performance-checker')
                    : __('Some images are missing an alt attribute.', 'seo-performance-checker'),
                'ideal' => __('Recommended: meaningful alt text for informative images.', 'seo-performance-checker'),
            ],
            [
                'id' => 'heading-hierarchy',
                'label' => __('Heading hierarchy', 'seo-performance-checker'),
                'status' => $hierarchy_count === 0 ? 'good' : 'warn',
                'value' => sprintf(_n('%d issue', '%d issues', $hierarchy_count, 'seo-performance-checker'), $hierarchy_count),
                'message' => $hierarchy_count === 0
                    ? __('No skipped heading levels were detected.', 'seo-performance-checker')
                    : __('One or more heading levels are skipped.', 'seo-performance-checker'),
                'ideal' => __('Recommended: use headings in a logical H1 → H2 → H3 order.', 'seo-performance-checker'),
            ],
            [
                'id' => 'indexability',
                'label' => __('Indexability', 'seo-performance-checker'),
                'status' => $has_noindex ? 'bad' : 'good',
                'value' => $has_noindex ? __('Noindex found', 'seo-performance-checker') : __('Indexable', 'seo-performance-checker'),
                'message' => $has_noindex
                    ? __('A noindex directive can prevent search-engine indexing.', 'seo-performance-checker')
                    : __('No noindex directive was detected.', 'seo-performance-checker'),
                'ideal' => __('Confirm noindex is intentional before publishing.', 'seo-performance-checker'),
            ],
            [
                'id' => 'http-status',
                'label' => __('HTTP response', 'seo-performance-checker'),
                'status' => ($status >= 200 && $status < 400) ? 'good' : 'bad',
                'value' => (string) $status,
                'message' => ($status >= 200 && $status < 400)
                    ? __('The page returned a successful response.', 'seo-performance-checker')
                    : __('The page returned an error or unexpected response.', 'seo-performance-checker'),
                'ideal' => __('Recommended: HTTP 200 for an indexable page.', 'seo-performance-checker'),
            ],
        ];

        $request_count = count(array_unique(array_filter($resource_urls))) + 1;
        $html_bytes = strlen($body);
        $performance_score = 100;
        if ($elapsed_ms > 3000) {
            $performance_score -= 35;
        } elseif ($elapsed_ms > 1800) {
            $performance_score -= 22;
        } elseif ($elapsed_ms > 900) {
            $performance_score -= 10;
        }
        if ($html_bytes > 1000000) {
            $performance_score -= 25;
        } elseif ($html_bytes > 500000) {
            $performance_score -= 14;
        } elseif ($html_bytes > 200000) {
            $performance_score -= 6;
        }
        if ($request_count > 120) {
            $performance_score -= 25;
        } elseif ($request_count > 75) {
            $performance_score -= 14;
        } elseif ($request_count > 45) {
            $performance_score -= 7;
        }
        $content_encoding = (string) wp_remote_retrieve_header($response, 'content-encoding');
        if (!$content_encoding) {
            $performance_score -= 8;
        }
        if ($render_blocking > 6) {
            $performance_score -= min(12, $render_blocking);
        }
        $performance_score = max(0, $performance_score);

        return [
            'url' => $url,
            'final_url' => $url,
            'tested_at' => current_time('mysql'),
            'cached' => false,
            'scores' => [
                'seo' => $seo_score,
                'quick_performance' => $performance_score,
                'security_headers' => (int) round(($security_present / count($security_headers)) * 100),
                'social' => min(100, $social_score),
            ],
            'response' => [
                'status' => $status,
                'elapsed_ms' => $elapsed_ms,
                'html_bytes' => $html_bytes,
                'content_type' => $content_type,
                'content_encoding' => $content_encoding,
                'cache_control' => (string) wp_remote_retrieve_header($response, 'cache-control'),
                'server' => (string) wp_remote_retrieve_header($response, 'server'),
                'powered_by' => (string) wp_remote_retrieve_header($response, 'x-powered-by'),
                'x_frame_options' => (string) wp_remote_retrieve_header($response, 'x-frame-options'),
                'content_security_policy' => $content_security_policy,
            ],
            'design' => [
                'score' => $design_score,
                'frame_allowed' => $frame_allowed,
                'frame_reason' => $frame_reason,
                'viewport' => $viewport,
                'fixed_width_count' => $fixed_width_count,
                'max_fixed_width_px' => $max_fixed_width,
                'missing_image_dimensions' => $missing_image_dimensions,
                'tiny_text_count' => $tiny_text_count,
                'unlabelled_controls' => $unlabelled_controls,
                'extreme_z_index_count' => $extreme_z_count,
                'issues' => $design_issues,
            ],
            'seo' => [
                'title' => $title,
                'title_length' => $title_length,
                'description' => $description,
                'description_length' => $description_length,
                'canonical' => $canonical,
                'robots' => $robots,
                'viewport' => $viewport,
                'lang' => $lang,
                'charset' => $charset,
                'open_graph' => $og,
                'twitter_card' => $twitter['card'],
                'twitter' => $twitter,
                'heading_detection' => $heading_detection,
                'raw_h1_count' => (int) $raw_h1_count,
                'aria_level1_count' => (int) $aria_level1_count,
                'headings' => $headings,
                'heading_counts' => $heading_counts,
                'hierarchy_issues' => $hierarchy_issues,
                'images' => $images,
                'links' => $links,
                'schema_count' => $schema_count,
                'schema_types' => array_values(array_unique($schema_types)),
                'issues' => $issues,
                'checks' => $seo_checks,
                'content' => [
                    'word_count' => $word_count,
                    'long_paragraphs' => $long_paragraphs,
                    'vague_link_text' => $vague_link_text,
                ],
                'social' => [
                    'score' => min(100, $social_score),
                    'profiles' => $social_profiles,
                    'recommendations' => array_values(array_unique($social_recommendations)),
                    'open_graph' => $og,
                    'twitter' => $twitter,
                    'same_as' => $schema_same_as,
                    'same_as_platforms' => $same_as_platforms,
                    'linked_not_in_schema' => array_keys($linked_not_in_schema),
                ],
            ],
            'resources' => [
                'estimated_requests' => $request_count,
                'scripts' => count($scripts),
                'stylesheets' => count($stylesheets),
                'render_blocking_stylesheets' => $render_blocking,
                'images' => $images['count'],
                'iframes' => count($iframes),
                'third_party_hosts' => $third_party,
            ],
            'security_headers' => $security_headers,
            'external_tools' => [
                'pagespeed' => 'https://pagespeed.web.dev/analysis?url=' . rawurlencode($url),
                'pingdom' => 'https://tools.pingdom.com/',
                'speedvitals' => 'https://speedvitals.com/',
                'seo_extension' => 'https://seo-extension.com/',
            ],
            'notes' => [
                __('Quick performance is measured from the WordPress server and is not a replacement for a real browser Lighthouse test.', 'seo-performance-checker'),
                __('Resource totals are parsed from the initial HTML and may not include JavaScript-loaded assets.', 'seo-performance-checker'),
                __('H1 checks now distinguish a confirmed server-HTML result from pages where client-side rendering or anti-bot interstitials make the heading impossible to verify reliably.', 'seo-performance-checker'),
            ],
        ];
    }

    private function format_pagespeed($json, $strategy) {
        $lighthouse = $json['lighthouseResult'];
        $audits = $lighthouse['audits'] ?? [];
        $categories = [];
        $category_details = [];

        foreach (($lighthouse['categories'] ?? []) as $key => $category) {
            $categories[$key] = isset($category['score']) ? (int) round($category['score'] * 100) : null;
            $category_audits = [];
            foreach (($category['auditRefs'] ?? []) as $ref) {
                $audit_id = (string) ($ref['id'] ?? '');
                if ($audit_id === '' || empty($audits[$audit_id])) {
                    continue;
                }
                $audit = $audits[$audit_id];
                $score = isset($audit['score']) && is_numeric($audit['score']) ? (int) round($audit['score'] * 100) : null;
                $category_audits[] = [
                    'id' => $audit_id,
                    'title' => $audit['title'] ?? $audit_id,
                    'description' => wp_strip_all_tags($audit['description'] ?? ''),
                    'display_value' => $audit['displayValue'] ?? '',
                    'score' => $score,
                    'score_display_mode' => $audit['scoreDisplayMode'] ?? '',
                    'weight' => isset($ref['weight']) ? (float) $ref['weight'] : 0,
                    'group' => $ref['group'] ?? '',
                ];
            }
            $category_details[$key] = [
                'title' => $category['title'] ?? ucwords(str_replace('-', ' ', $key)),
                'description' => wp_strip_all_tags($category['description'] ?? ''),
                'score' => $categories[$key],
                'audits' => $category_audits,
            ];
        }

        $metric_ids = [
            'first-contentful-paint',
            'largest-contentful-paint',
            'speed-index',
            'total-blocking-time',
            'cumulative-layout-shift',
            'interactive',
        ];
        $metrics = [];
        foreach ($metric_ids as $id) {
            if (!empty($audits[$id])) {
                $audit = $audits[$id];
                $metrics[$id] = [
                    'id' => $id,
                    'title' => $audit['title'] ?? $id,
                    'display_value' => $audit['displayValue'] ?? '',
                    'numeric_value' => $audit['numericValue'] ?? null,
                    'numeric_unit' => $audit['numericUnit'] ?? '',
                    'score' => isset($audit['score']) ? (int) round($audit['score'] * 100) : null,
                ];
            }
        }

        $opportunities = [];
        $diagnostics = [];
        $passed_audits = [];
        foreach ($audits as $id => $audit) {
            $savings = (float) ($audit['details']['overallSavingsMs'] ?? 0);
            $score_mode = (string) ($audit['scoreDisplayMode'] ?? '');
            $score = isset($audit['score']) && is_numeric($audit['score']) ? (float) $audit['score'] : null;
            $entry = [
                'id' => $id,
                'title' => $audit['title'] ?? $id,
                'display_value' => $audit['displayValue'] ?? '',
                'savings_ms' => (int) round($savings),
                'description' => wp_strip_all_tags($audit['description'] ?? ''),
                'score' => $score !== null ? (int) round($score * 100) : null,
                'score_display_mode' => $score_mode,
            ];

            if ($savings > 50 || ($score_mode === 'numeric' && $score !== null && $score < 0.8)) {
                $opportunities[] = $entry;
            } elseif ($score !== null && $score < 1 && in_array($score_mode, ['numeric', 'binary'], true)) {
                $diagnostics[] = $entry;
            } elseif ($score !== null && $score >= 1 && in_array($score_mode, ['numeric', 'binary'], true)) {
                $passed_audits[] = $entry;
            }
        }
        usort($opportunities, function ($a, $b) {
            if ($a['savings_ms'] === $b['savings_ms']) {
                return ($a['score'] ?? 100) <=> ($b['score'] ?? 100);
            }
            return $b['savings_ms'] <=> $a['savings_ms'];
        });
        usort($diagnostics, function ($a, $b) {
            return ($a['score'] ?? 100) <=> ($b['score'] ?? 100);
        });

        $field_metric_map = [
            'LARGEST_CONTENTFUL_PAINT_MS' => ['Largest Contentful Paint', 'ms'],
            'INTERACTION_TO_NEXT_PAINT' => ['Interaction to Next Paint', 'ms'],
            'CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['Cumulative Layout Shift', 'score'],
            'FIRST_CONTENTFUL_PAINT_MS' => ['First Contentful Paint', 'ms'],
            'EXPERIMENTAL_TIME_TO_FIRST_BYTE' => ['Time to First Byte', 'ms'],
        ];
        $format_field_metrics = static function ($experience) use ($field_metric_map) {
            $formatted = [];
            foreach (($experience['metrics'] ?? []) as $id => $metric) {
                $label = $field_metric_map[$id][0] ?? ucwords(strtolower(str_replace('_', ' ', $id)));
                $unit = $field_metric_map[$id][1] ?? '';
                $percentile = $metric['percentile'] ?? null;
                $display_value = $percentile;
                if ($percentile !== null && $unit === 'ms') {
                    $display_value = $percentile >= 1000 ? round($percentile / 1000, 1) . ' s' : (int) round($percentile) . ' ms';
                } elseif ($percentile !== null && $unit === 'score') {
                    $display_value = round($percentile / 100, 3);
                }
                $formatted[$id] = [
                    'title' => $label,
                    'category' => $metric['category'] ?? '',
                    'percentile' => $percentile,
                    'display_value' => $display_value,
                ];
            }
            return $formatted;
        };

        return [
            'strategy' => $strategy,
            'tested_at' => current_time('mysql'),
            'cached' => false,
            'categories' => $categories,
            'category_details' => $category_details,
            'metrics' => $metrics,
            'opportunities' => array_slice($opportunities, 0, 15),
            'diagnostics' => array_slice($diagnostics, 0, 20),
            'passed_audits' => array_slice($passed_audits, 0, 25),
            'field_data' => [
                'origin_category' => $json['originLoadingExperience']['overall_category'] ?? '',
                'url_category' => $json['loadingExperience']['overall_category'] ?? '',
                'url_metrics' => $format_field_metrics($json['loadingExperience'] ?? []),
                'origin_metrics' => $format_field_metrics($json['originLoadingExperience'] ?? []),
            ],
            'lighthouse_version' => $lighthouse['lighthouseVersion'] ?? '',
            'final_url' => $lighthouse['finalUrl'] ?? '',
            'fetch_time' => $lighthouse['fetchTime'] ?? '',
            'agentic_browsing_available' => isset($categories['agentic-browsing']) || isset($categories['agentic_browsing']),
        ];
    }

    private function network_test($host, $url) {
        $dns_started = microtime(true);
        $records = ['A' => [], 'AAAA' => [], 'CNAME' => [], 'MX' => [], 'NS' => [], 'TXT' => [], 'SOA' => []];
        if (function_exists('dns_get_record')) {
            $types = [
                'A' => defined('DNS_A') ? DNS_A : 1,
                'AAAA' => defined('DNS_AAAA') ? DNS_AAAA : 134217728,
                'CNAME' => defined('DNS_CNAME') ? DNS_CNAME : 16,
                'MX' => defined('DNS_MX') ? DNS_MX : 16384,
                'NS' => defined('DNS_NS') ? DNS_NS : 2,
                'TXT' => defined('DNS_TXT') ? DNS_TXT : 32768,
                'SOA' => defined('DNS_SOA') ? DNS_SOA : 32,
            ];
            foreach ($types as $label => $constant) {
                $raw = @dns_get_record($host, $constant);
                if (is_array($raw)) {
                    foreach ($raw as $record) {
                        $records[$label][] = $this->simplify_dns_record($label, $record);
                    }
                }
            }
        }
        $dns_ms = (int) round((microtime(true) - $dns_started) * 1000);

        $ips = [];
        foreach ($records['A'] as $record) {
            if (!empty($record['ip'])) {
                $ips[] = $record['ip'];
            }
        }
        foreach ($records['AAAA'] as $record) {
            if (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
        if (empty($ips)) {
            $fallback = gethostbynamel($host);
            if (is_array($fallback)) {
                $ips = $fallback;
            }
        }
        $ips = array_values(array_unique($ips));

        $tcp = [];
        $socket_target = !empty($ips[0]) ? $ips[0] : $host;
        if (filter_var($socket_target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $socket_target = '[' . $socket_target . ']';
        }
        foreach ([443, 80] as $port) {
            $started = microtime(true);
            $errno = 0;
            $errstr = '';
            $socket = @fsockopen($socket_target, $port, $errno, $errstr, 4);
            $tcp[(string) $port] = [
                'open' => is_resource($socket),
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => is_resource($socket) ? '' : trim($errstr ?: ('Error ' . $errno)),
            ];
            if (is_resource($socket)) {
                fclose($socket);
            }
        }

        $http_start = microtime(true);
        $head = wp_safe_remote_head($url, ['timeout' => 10, 'redirection' => 5, 'user-agent' => 'WP SEO Performance Checker/' . SEOPC_VERSION]);
        $http_ms = (int) round((microtime(true) - $http_start) * 1000);
        $http = [];
        if (is_wp_error($head)) {
            $http['error'] = $head->get_error_message();
        } else {
            $http = [
                'status' => (int) wp_remote_retrieve_response_code($head),
                'latency_ms' => $http_ms,
                'server' => (string) wp_remote_retrieve_header($head, 'server'),
                'powered_by' => (string) wp_remote_retrieve_header($head, 'x-powered-by'),
                'content_type' => (string) wp_remote_retrieve_header($head, 'content-type'),
                'cache_control' => (string) wp_remote_retrieve_header($head, 'cache-control'),
                'cdn' => $this->detect_cdn($head),
            ];
        }

        $reverse = [];
        foreach (array_slice($ips, 0, 4) as $ip) {
            $name = @gethostbyaddr($ip);
            $reverse[$ip] = ($name && $name !== $ip) ? $name : '';
        }

        $tls = $this->tls_info($host, $ips[0] ?? '');
        $hosting = [];
        if (!empty($ips[0]) && filter_var($ips[0], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $geo_key = 'seopc_ipwho_' . md5($ips[0]);
            $hosting = get_transient($geo_key);
            if (!is_array($hosting)) {
                $geo_response = wp_remote_get('https://ipwho.is/' . rawurlencode($ips[0]), ['timeout' => 8, 'redirection' => 1]);
                if (!is_wp_error($geo_response) && (int) wp_remote_retrieve_response_code($geo_response) === 200) {
                    $geo = json_decode(wp_remote_retrieve_body($geo_response), true);
                    if (is_array($geo) && !empty($geo['success'])) {
                        $hosting = [
                            'ip' => $geo['ip'] ?? $ips[0],
                            'country' => $geo['country'] ?? '',
                            'region' => $geo['region'] ?? '',
                            'city' => $geo['city'] ?? '',
                            'continent' => $geo['continent'] ?? '',
                            'asn' => $geo['connection']['asn'] ?? '',
                            'org' => $geo['connection']['org'] ?? '',
                            'isp' => $geo['connection']['isp'] ?? '',
                            'domain' => $geo['connection']['domain'] ?? '',
                            'timezone' => $geo['timezone']['id'] ?? '',
                        ];
                        set_transient($geo_key, $hosting, 12 * HOUR_IN_SECONDS);
                    }
                }
            }
        }

        return [
            'host' => $host,
            'tested_at' => current_time('mysql'),
            'dns_latency_ms' => $dns_ms,
            'dns' => $records,
            'ips' => $ips,
            'reverse_dns' => $reverse,
            'tcp' => $tcp,
            'http' => $http,
            'tls' => $tls,
            'hosting' => is_array($hosting) ? $hosting : [],
            'note' => __('This is a DNS/HTTP/TCP reachability test, not raw ICMP ping.', 'seo-performance-checker'),
        ];
    }

    private function simplify_dns_record($type, $record) {
        switch ($type) {
            case 'A':
                return ['ip' => $record['ip'] ?? '', 'ttl' => $record['ttl'] ?? 0];
            case 'AAAA':
                return ['ipv6' => $record['ipv6'] ?? '', 'ttl' => $record['ttl'] ?? 0];
            case 'CNAME':
            case 'NS':
                return ['target' => $record['target'] ?? '', 'ttl' => $record['ttl'] ?? 0];
            case 'MX':
                return ['target' => $record['target'] ?? '', 'priority' => $record['pri'] ?? 0, 'ttl' => $record['ttl'] ?? 0];
            case 'TXT':
                return ['text' => $record['txt'] ?? implode('', $record['entries'] ?? []), 'ttl' => $record['ttl'] ?? 0];
            case 'SOA':
                return [
                    'mname' => $record['mname'] ?? '',
                    'rname' => $record['rname'] ?? '',
                    'serial' => $record['serial'] ?? '',
                    'refresh' => $record['refresh'] ?? '',
                    'retry' => $record['retry'] ?? '',
                    'expire' => $record['expire'] ?? '',
                    'minimum_ttl' => $record['minimum-ttl'] ?? '',
                ];
        }
        return $record;
    }

    private function tls_info($host, $ip = '') {
        if (!function_exists('stream_socket_client')) {
            return ['available' => false, 'error' => 'stream_socket_client unavailable'];
        }
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);
        $errno = 0;
        $errstr = '';
        $target = $ip ?: $host;
        if (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $target = '[' . $target . ']';
        }
        $client = @stream_socket_client('ssl://' . $target . ':443', $errno, $errstr, 6, STREAM_CLIENT_CONNECT, $context);
        if (!$client) {
            return ['available' => false, 'error' => trim($errstr ?: ('Error ' . $errno))];
        }
        $params = stream_context_get_params($client);
        fclose($client);
        if (empty($params['options']['ssl']['peer_certificate'])) {
            return ['available' => false, 'error' => 'Certificate not captured'];
        }
        $parsed = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
        if (!is_array($parsed)) {
            return ['available' => false, 'error' => 'Certificate parse failed'];
        }
        return [
            'available' => true,
            'subject' => $parsed['subject']['CN'] ?? '',
            'issuer' => $parsed['issuer']['CN'] ?? ($parsed['issuer']['O'] ?? ''),
            'valid_from' => !empty($parsed['validFrom_time_t']) ? gmdate('c', $parsed['validFrom_time_t']) : '',
            'valid_to' => !empty($parsed['validTo_time_t']) ? gmdate('c', $parsed['validTo_time_t']) : '',
            'days_remaining' => !empty($parsed['validTo_time_t']) ? (int) floor(($parsed['validTo_time_t'] - time()) / DAY_IN_SECONDS) : null,
            'san' => $parsed['extensions']['subjectAltName'] ?? '',
        ];
    }

    private function detect_cdn($response) {
        $headers = wp_remote_retrieve_headers($response);
        $map = [
            'cf-ray' => 'Cloudflare',
            'x-amz-cf-id' => 'Amazon CloudFront',
            'x-served-by' => 'Fastly or Varnish',
            'x-akamai-transformed' => 'Akamai',
            'x-cdn' => 'CDN',
            'x-cache' => 'Caching proxy/CDN',
        ];
        foreach ($map as $header => $label) {
            if (!empty($headers[$header])) {
                return $label;
            }
        }
        return '';
    }

    private function search_wp_photos($query, $page, &$items, &$errors) {
        // WordPress.org Photo Directory: moderated, high-resolution CC0 photography with no API key.
        // The public directory is intentionally consumed as a normal web search page so agencies
        // do not need to create or maintain external API credentials.
        $url = add_query_arg([
            's' => $query,
            'page' => max(1, (int) $page),
        ], 'https://wordpress.org/photos/');
        $response = wp_remote_get($url, [
            'timeout' => 14,
            'redirection' => 3,
            'user-agent' => 'Mozilla/5.0 (compatible; Website Growth Toolkit/' . SEOPC_VERSION . '; +' . home_url('/') . ')',
            'headers' => ['Accept' => 'text/html,application/xhtml+xml'],
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 400) {
            $errors[] = 'WordPress Photos: ' . (is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_message($response));
            return;
        }
        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '' || !class_exists('DOMDocument')) return;
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        if (!$dom->loadHTML('<?xml encoding="utf-8" ?>' . $body, LIBXML_NOWARNING | LIBXML_NOERROR)) { libxml_clear_errors(); return; }
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//main//img | //article//img | //figure//img');
        $seen = [];
        if ($nodes) foreach ($nodes as $img) {
            $src = trim((string) ($img->getAttribute('data-src') ?: $img->getAttribute('src')));
            $srcset = trim((string) $img->getAttribute('srcset'));
            if ($srcset !== '') {
                $candidates = array_filter(array_map('trim', explode(',', $srcset)));
                if ($candidates) {
                    $last = end($candidates);
                    $bits = preg_split('/\s+/', trim($last));
                    if (!empty($bits[0])) $src = $bits[0];
                }
            }
            if (!$src || strpos($src, 'wordpress.org') === false && strpos($src, 'wp.com') === false) continue;
            $src = esc_url_raw($src, ['http','https']);
            if (!$src || isset($seen[$src])) continue;
            $seen[$src] = true;
            $anchor = $img;
            for ($i=0; $i<5 && $anchor && strtolower($anchor->nodeName) !== 'a'; $i++) $anchor = $anchor->parentNode;
            $source = ($anchor && strtolower($anchor->nodeName) === 'a') ? $anchor->getAttribute('href') : 'https://wordpress.org/photos/';
            if ($source && strpos($source, 'http') !== 0) $source = 'https://wordpress.org' . '/' . ltrim($source, '/');
            $title = trim((string) ($img->getAttribute('alt') ?: $img->getAttribute('title')));
            if ($title === '') $title = 'WordPress Photo';
            $items[] = [
                'provider' => 'WordPress Photos', 'type' => 'image', 'title' => $title,
                'creator' => '', 'license' => 'CC0', 'license_url' => 'https://wordpress.org/photos/faq/',
                'source_url' => esc_url_raw($source, ['http','https']), 'preview_fast_url' => $src,
                'preview_url' => $src, 'download_url' => $src,
                'width' => absint($img->getAttribute('width')) ?: null,
                'height' => absint($img->getAttribute('height')) ?: null,
                'mime' => 'image/jpeg', 'no_key' => true,
            ];
            if (count($items) >= 24) break;
        }
        libxml_clear_errors();
    }

    private function search_openverse($query, $page, &$items, &$errors) {
        $url = add_query_arg([
            'q' => $query,
            'page' => $page,
            'page_size' => 12,
            'mature' => 'false',
        ], 'https://api.openverse.org/v1/images/');
        $response = wp_remote_get($url, ['timeout' => 15, 'redirection' => 2, 'user-agent' => 'WP SEO Performance Checker/' . SEOPC_VERSION]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 400) {
            $errors[] = 'Openverse: ' . (is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_message($response));
            return;
        }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        foreach (($json['results'] ?? []) as $result) {
            $items[] = [
                'provider' => 'Openverse',
                'type' => 'image',
                'title' => $result['title'] ?? __('Untitled image', 'seo-performance-checker'),
                'creator' => $result['creator'] ?? '',
                'license' => strtoupper((string) ($result['license'] ?? '')),
                'license_url' => $result['license_url'] ?? '',
                'source_url' => $result['foreign_landing_url'] ?? ($result['detail_url'] ?? ''),
                'preview_fast_url' => $result['thumbnail'] ?? ($result['url'] ?? ''),
                'preview_url' => $result['thumbnail'] ?? ($result['url'] ?? ''),
                'download_url' => $result['url'] ?? '',
                'width' => $result['width'] ?? null,
                'height' => $result['height'] ?? null,
                'mime' => '',
            ];
        }
    }

    private function search_wikimedia($query, $type, $page, &$items, &$errors) {
        $search = $query . ($type === 'video' ? ' filetype:video' : ' filetype:bitmap');
        $url = add_query_arg([
            'action' => 'query',
            'format' => 'json',
            'formatversion' => 2,
            'generator' => 'search',
            'gsrsearch' => $search,
            'gsrnamespace' => 6,
            'gsrlimit' => 12,
            'gsroffset' => ($page - 1) * 12,
            'prop' => 'imageinfo',
            'iiprop' => 'url|mime|size|extmetadata',
            'iiurlwidth' => 640,
            'origin' => '*',
        ], 'https://commons.wikimedia.org/w/api.php');
        $response = wp_remote_get($url, ['timeout' => 15, 'redirection' => 2, 'user-agent' => 'WP SEO Performance Checker/' . SEOPC_VERSION . ' (' . home_url('/') . ')']);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 400) {
            $errors[] = 'Wikimedia: ' . (is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_message($response));
            return;
        }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        foreach (($json['query']['pages'] ?? []) as $page_data) {
            $info = $page_data['imageinfo'][0] ?? [];
            $mime = $info['mime'] ?? '';
            if (($type === 'video' && !$this->starts_with($mime, 'video/')) || ($type === 'image' && !$this->starts_with($mime, 'image/'))) {
                continue;
            }
            $meta = $info['extmetadata'] ?? [];
            $items[] = [
                'provider' => 'Wikimedia Commons',
                'type' => $type,
                'title' => preg_replace('/^File:/', '', $page_data['title'] ?? ''),
                'creator' => wp_strip_all_tags($meta['Artist']['value'] ?? ''),
                'license' => wp_strip_all_tags($meta['LicenseShortName']['value'] ?? ''),
                'license_url' => $meta['LicenseUrl']['value'] ?? '',
                'source_url' => $info['descriptionurl'] ?? '',
                'preview_fast_url' => $info['thumburl'] ?? ($info['url'] ?? ''),
                'preview_url' => $info['thumburl'] ?? ($info['url'] ?? ''),
                'download_url' => $info['url'] ?? '',
                'width' => $info['width'] ?? null,
                'height' => $info['height'] ?? null,
                'mime' => $mime,
            ];
        }
    }

    private function search_pexels($query, $type, $page, $key, &$items, &$errors) {
        $endpoint = $type === 'video' ? 'https://api.pexels.com/v1/videos/search' : 'https://api.pexels.com/v1/search';
        $url = add_query_arg(['query' => $query, 'per_page' => 12, 'page' => $page], $endpoint);
        $response = wp_remote_get($url, ['timeout' => 15, 'headers' => ['Authorization' => $key]]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 400) {
            $errors[] = 'Pexels: ' . (is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_message($response));
            return;
        }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        if ($type === 'image') {
            foreach (($json['photos'] ?? []) as $photo) {
                $items[] = [
                    'provider' => 'Pexels', 'type' => 'image', 'title' => $photo['alt'] ?? 'Pexels image',
                    'creator' => $photo['photographer'] ?? '', 'license' => 'Pexels', 'license_url' => 'https://www.pexels.com/license/',
                    'source_url' => $photo['url'] ?? '', 'preview_fast_url' => $photo['src']['small'] ?? ($photo['src']['tiny'] ?? ''), 'preview_url' => $photo['src']['medium'] ?? ($photo['src']['small'] ?? ''), 'download_url' => $photo['src']['original'] ?? '',
                    'width' => $photo['width'] ?? null, 'height' => $photo['height'] ?? null, 'mime' => 'image/jpeg',
                ];
            }
        } else {
            foreach (($json['videos'] ?? []) as $video) {
                $files = $video['video_files'] ?? [];
                usort($files, function ($a, $b) { return (int) ($b['width'] ?? 0) <=> (int) ($a['width'] ?? 0); });
                $file = $files[0] ?? [];
                $items[] = [
                    'provider' => 'Pexels', 'type' => 'video', 'title' => 'Pexels video ' . ($video['id'] ?? ''),
                    'creator' => $video['user']['name'] ?? '', 'license' => 'Pexels', 'license_url' => 'https://www.pexels.com/license/',
                    'source_url' => $video['url'] ?? '', 'preview_fast_url' => $video['image'] ?? '', 'preview_url' => $video['image'] ?? '', 'download_url' => $file['link'] ?? '',
                    'width' => $file['width'] ?? ($video['width'] ?? null), 'height' => $file['height'] ?? ($video['height'] ?? null), 'mime' => $file['file_type'] ?? 'video/mp4',
                ];
            }
        }
    }

    private function search_pixabay($query, $type, $page, $key, &$items, &$errors) {
        $endpoint = $type === 'video' ? 'https://pixabay.com/api/videos/' : 'https://pixabay.com/api/';
        $url = add_query_arg(['key' => $key, 'q' => $query, 'per_page' => 12, 'page' => $page, 'safesearch' => 'true'], $endpoint);
        $provider_cache_key = 'seopc_pixabay_' . md5($url);
        $json = get_transient($provider_cache_key);
        if (!is_array($json)) {
            $response = wp_remote_get($url, ['timeout' => 15]);
            if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 400) {
                $errors[] = 'Pixabay: ' . (is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_message($response));
                return;
            }
            $json = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($json)) {
                set_transient($provider_cache_key, $json, DAY_IN_SECONDS);
            }
        }
        foreach (($json['hits'] ?? []) as $hit) {
            if ($type === 'image') {
                $items[] = [
                    'provider' => 'Pixabay', 'type' => 'image', 'title' => $hit['tags'] ?? 'Pixabay image',
                    'creator' => $hit['user'] ?? '', 'license' => 'Pixabay Content License', 'license_url' => 'https://pixabay.com/service/license-summary/',
                    'source_url' => $hit['pageURL'] ?? '', 'preview_fast_url' => $hit['previewURL'] ?? ($hit['webformatURL'] ?? ''), 'preview_url' => $hit['webformatURL'] ?? ($hit['previewURL'] ?? ''), 'download_url' => $hit['largeImageURL'] ?? ($hit['webformatURL'] ?? ''),
                    'width' => $hit['imageWidth'] ?? null, 'height' => $hit['imageHeight'] ?? null, 'mime' => 'image/jpeg',
                ];
            } else {
                $file = $hit['videos']['medium'] ?? ($hit['videos']['small'] ?? ($hit['videos']['large'] ?? []));
                $items[] = [
                    'provider' => 'Pixabay', 'type' => 'video', 'title' => $hit['tags'] ?? 'Pixabay video',
                    'creator' => $hit['user'] ?? '', 'license' => 'Pixabay Content License', 'license_url' => 'https://pixabay.com/service/license-summary/',
                    'source_url' => $hit['pageURL'] ?? '', 'preview_fast_url' => $hit['videos']['tiny']['thumbnail'] ?? '', 'preview_url' => $hit['videos']['tiny']['thumbnail'] ?? '', 'download_url' => $file['url'] ?? '',
                    'width' => $file['width'] ?? null, 'height' => $file['height'] ?? null, 'mime' => 'video/mp4',
                ];
            }
        }
    }

    private function search_unsplash($query, $page, $key, &$items, &$errors) {
        $url = add_query_arg(['query' => $query, 'per_page' => 12, 'page' => $page, 'client_id' => $key], 'https://api.unsplash.com/search/photos');
        $response = wp_remote_get($url, ['timeout' => 15, 'headers' => ['Accept-Version' => 'v1']]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 400) {
            $errors[] = 'Unsplash: ' . (is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_message($response));
            return;
        }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        foreach (($json['results'] ?? []) as $photo) {
            $items[] = [
                'provider' => 'Unsplash', 'type' => 'image', 'title' => $photo['alt_description'] ?? ($photo['description'] ?? 'Unsplash image'),
                'creator' => $photo['user']['name'] ?? '', 'license' => 'Unsplash License', 'license_url' => 'https://unsplash.com/license',
                'source_url' => $photo['links']['html'] ?? '', 'preview_fast_url' => $photo['urls']['thumb'] ?? ($photo['urls']['small'] ?? ''), 'preview_url' => $photo['urls']['small'] ?? ($photo['urls']['thumb'] ?? ''), 'download_url' => $photo['urls']['full'] ?? ($photo['urls']['regular'] ?? ''),
                'download_trigger_url' => $photo['links']['download_location'] ?? '',
                'width' => $photo['width'] ?? null, 'height' => $photo['height'] ?? null, 'mime' => 'image/jpeg',
            ];
        }
    }

    private function search_iconify($query, $page, &$items, &$errors) {
        $start = max(0, ($page - 1) * 24);
        $url = add_query_arg(['query' => $query, 'limit' => 24, 'start' => $start], 'https://api.iconify.design/search');
        $response = wp_remote_get($url, ['timeout' => 15, 'redirection' => 2, 'user-agent' => 'WP SEO Performance Checker/' . SEOPC_VERSION]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 400) {
            $errors[] = 'Iconify: ' . (is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_message($response));
            return;
        }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        foreach (($json['icons'] ?? []) as $icon) {
            if (!is_string($icon) || strpos($icon, ':') === false) continue;
            [$prefix, $name] = explode(':', $icon, 2);
            $svg = 'https://api.iconify.design/' . rawurlencode($prefix) . '/' . rawurlencode($name) . '.svg';
            $items[] = [
                'provider' => 'Iconify', 'type' => 'image', 'asset_kind' => 'icon',
                'title' => str_replace(['-', '_'], ' ', $name), 'creator' => $prefix,
                'license' => 'Icon set licence - verify source', 'license_url' => 'https://icon-sets.iconify.design/' . rawurlencode($prefix) . '/',
                'source_url' => 'https://icon-sets.iconify.design/' . rawurlencode($prefix) . '/' . rawurlencode($name) . '/',
                'preview_fast_url' => $svg, 'preview_url' => $svg, 'download_url' => $svg, 'width' => 512, 'height' => 512, 'mime' => 'image/svg+xml',
            ];
        }
    }

    private function media_quality_score($item, $requested_type) {
        $provider = strtolower((string) ($item['provider'] ?? ''));
        $weights = ['pexels' => 30, 'unsplash' => 30, 'pixabay' => 27, 'wordpress photos' => 34, 'wikimedia commons' => 19, 'openverse' => 16, 'iconify' => 28];
        $score = $weights[$provider] ?? 10;
        $width = max(0, (int) ($item['width'] ?? 0));
        $height = max(0, (int) ($item['height'] ?? 0));
        $pixels = $width * $height;
        if ($requested_type === 'icon' || ($item['asset_kind'] ?? '') === 'icon') {
            $score += 35;
        } elseif ($requested_type === 'video') {
            if ($width >= 1920) $score += 35;
            elseif ($width >= 1280) $score += 28;
            elseif ($width >= 720) $score += 20;
            else $score += 5;
        } else {
            if ($pixels >= 12000000) $score += 38;
            elseif ($pixels >= 6000000) $score += 32;
            elseif ($pixels >= 2500000) $score += 25;
            elseif ($pixels >= 1000000) $score += 16;
            else $score += 4;
            if ($width >= 1600 && $height >= 900) $score += 8;
        }
        if (!empty($item['creator'])) $score += 3;
        if (!empty($item['license'])) $score += 3;
        return min(100, $score);
    }

    private function sign_media_url($url, $trigger_url = '') {
        $exp = time() + 20 * MINUTE_IN_SECONDS;
        return [
            'exp' => $exp,
            'sig' => hash_hmac('sha256', $url . '|' . $trigger_url . '|' . $exp, wp_salt('secure_auth')),
        ];
    }

    private function verify_media_signature($url, $trigger_url, $sig, $exp) {
        if (!$url || !$sig || $exp < time() || $exp > time() + HOUR_IN_SECONDS) {
            return false;
        }
        $expected = hash_hmac('sha256', $url . '|' . $trigger_url . '|' . $exp, wp_salt('secure_auth'));
        return hash_equals($expected, $sig);
    }

    private function node_text($xpath, $query) {
        $nodes = $xpath->query($query);
        if (!$nodes || !$nodes->length) {
            return '';
        }
        return trim(preg_replace('/\s+/', ' ', $nodes->item(0)->textContent));
    }

    private function meta_content($xpath, $attribute, $value) {
        $query = '//meta[translate(@' . $attribute . ',"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="' . strtolower($value) . '"]';
        return $this->attribute_value($xpath, $query, 'content');
    }

    private function attribute_value($xpath, $query, $attribute) {
        $nodes = $xpath->query($query);
        if (!$nodes || !$nodes->length) {
            return '';
        }
        return trim($nodes->item(0)->getAttribute($attribute));
    }

    private function absolute_url($candidate, $base) {
        $candidate = trim((string) $candidate);
        if ($candidate === '' || $candidate[0] === '#' || preg_match('#^(data:|javascript:|mailto:|tel:)#i', $candidate)) {
            return '';
        }
        if (preg_match('#^https?://#i', $candidate)) {
            return $candidate;
        }
        $parts = wp_parse_url($base);
        if (empty($parts['host'])) {
            return '';
        }
        $scheme = $parts['scheme'] ?? 'https';
        if (strpos($candidate, '//') === 0) {
            return $scheme . ':' . $candidate;
        }
        if ($candidate[0] === '/') {
            return $scheme . '://' . $parts['host'] . (!empty($parts['port']) ? ':' . $parts['port'] : '') . $candidate;
        }
        $path = $parts['path'] ?? '/';
        $dir = trailingslashit(dirname($path));
        return $scheme . '://' . $parts['host'] . (!empty($parts['port']) ? ':' . $parts['port'] : '') . $dir . $candidate;
    }

    private function social_platform_domains() {
        return [
            'facebook' => ['facebook.com', 'fb.com'],
            'instagram' => ['instagram.com'],
            'linkedin' => ['linkedin.com'],
            'youtube' => ['youtube.com', 'youtu.be'],
            'x' => ['x.com', 'twitter.com'],
            'tiktok' => ['tiktok.com'],
            'pinterest' => ['pinterest.com'],
            'threads' => ['threads.net'],
        ];
    }

    private function collect_schema_same_as($data, &$urls) {
        if (!is_array($data)) return;
        if (isset($data['sameAs'])) {
            foreach ((array) $data['sameAs'] as $url) {
                if (is_string($url) && preg_match('#^https?://#i', $url)) $urls[] = esc_url_raw($url);
            }
        }
        foreach ($data as $value) {
            if (is_array($value)) $this->collect_schema_same_as($value, $urls);
        }
    }

    private function xpath_literal($value) {
        $value = (string) $value;
        if (strpos($value, "'") === false) return "'" . $value . "'";
        if (strpos($value, '"') === false) return '"' . $value . '"';
        $parts = explode("'", $value);
        return 'concat(' . implode(',"\'",', array_map(function ($part) { return "'" . $part . "'"; }, $parts)) . ')';
    }

    private function collect_schema_types($data, &$types) {
        if (!is_array($data)) {
            return;
        }
        if (isset($data['@type'])) {
            foreach ((array) $data['@type'] as $type) {
                if (is_string($type)) {
                    $types[] = $type;
                }
            }
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $this->collect_schema_types($value, $types);
            }
        }
    }

    private function score_check($passed, $deduction, $message, &$score, &$issues) {
        if (!$passed) {
            $score -= $deduction;
            $issues[] = $message;
        }
    }

    private function starts_with($haystack, $needle) {
        return substr((string) $haystack, 0, strlen((string) $needle)) === (string) $needle;
    }

    private function text_length($text) {
        return function_exists('mb_strlen') ? mb_strlen((string) $text) : strlen((string) $text);
    }

    private function text_substr($text, $start, $length) {
        return function_exists('mb_substr') ? mb_substr((string) $text, $start, $length) : substr((string) $text, $start, $length);
    }
}
