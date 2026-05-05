<?php

class SEOPC_Dynamic_Overrides {

    private $matched_override = null;
    private $matched_context = [];

    public function __construct() {
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('template_redirect', [$this, 'setup_frontend_override_hooks'], 1);
        add_filter('pre_get_document_title', [$this, 'filter_document_title'], 99);
        add_action('wp_head', [$this, 'output_meta_tags'], 1);
    }

    public function register_admin_page() {
        add_submenu_page(
            null,
            __('Dynamic Overrides', 'seo-performance-checker'),
            __('Dynamic Overrides', 'seo-performance-checker'),
            'manage_options',
            'seo-dynamic-overrides',
            [$this, 'render_admin_page']
        );
    }

    private function get_option_rows() {
        $rows = get_option('seopc_dynamic_overrides', []);
        return is_array($rows) ? array_values($rows) : [];
    }

    private function normalize_url_value($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        $value = str_replace(' ', '%20', $value);

        if (strpos($value, 'http://') === 0 || strpos($value, 'https://') === 0) {
            $parts = wp_parse_url($value);
            if (!is_array($parts)) {
                return untrailingslashit($value);
            }
            $path = isset($parts['path']) ? $parts['path'] : '/';
            $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
            return untrailingslashit($path) . $query;
        }

        return untrailingslashit($value);
    }

    private function sanitize_row($row) {
        $enabled = !empty($row['enabled']) ? 1 : 0;
        $target_url = $this->normalize_url_value($row['target_url'] ?? '');

        return [
            'enabled' => $enabled,
            'target_url' => $target_url,
            'meta_title' => sanitize_text_field($row['meta_title'] ?? ''),
            'meta_description' => sanitize_textarea_field($row['meta_description'] ?? ''),
            'keywords' => sanitize_text_field($row['keywords'] ?? ''),
            'h1_override' => sanitize_text_field($row['h1_override'] ?? ''),
        ];
    }

    private function get_current_request_path_query() {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $request_uri = strtok($request_uri, '#');
        return $this->normalize_url_value($request_uri ?: '/');
    }

    private function get_current_request_url() {
        $path_query = $this->get_current_request_path_query();
        return home_url($path_query ?: '/');
    }

    private function match_override_for_current_request() {
        if ($this->matched_override !== null) {
            return $this->matched_override;
        }

        $rows = $this->get_option_rows();
        $current_path_query = $this->get_current_request_path_query();
        $current_path = untrailingslashit(strtok($current_path_query, '?'));

        foreach ($rows as $row) {
            $row = $this->sanitize_row($row);
            if (empty($row['enabled']) || empty($row['target_url'])) {
                continue;
            }

            $target = $row['target_url'];
            $target_path = untrailingslashit(strtok($target, '?'));

            $is_match = false;
            if ($target === $current_path_query || $target === $current_path) {
                $is_match = true;
            } elseif (strpos($target, '*') !== false) {
                $pattern = '#^' . str_replace('\*', '.*', preg_quote($target, '#')) . '$#i';
                $is_match = (bool) preg_match($pattern, $current_path_query) || (bool) preg_match($pattern, $current_path);
            }

            if ($is_match) {
                $this->matched_override = $row;
                $this->matched_context = $this->build_context();
                return $this->matched_override;
            }
        }

        $this->matched_override = false;
        return false;
    }

    private function build_context() {
        $post = get_queried_object();
        $context = [
            'site_name' => get_bloginfo('name'),
            'url' => $this->get_current_request_url(),
            'request_path' => $this->get_current_request_path_query(),
            'year' => gmdate('Y'),
            'month' => gmdate('m'),
            'day' => gmdate('d'),
            'post_title' => '',
            'post_type' => '',
        ];

        if ($post instanceof WP_Post) {
            $context['post_title'] = get_the_title($post);
            $context['post_type'] = get_post_type($post);
        } elseif ($post instanceof WP_Term) {
            $context['post_title'] = $post->name;
            $context['post_type'] = $post->taxonomy;
        }

        if (!empty($_GET) && is_array($_GET)) {
            foreach ($_GET as $key => $value) {
                if (is_scalar($value)) {
                    $context['query:' . sanitize_key($key)] = sanitize_text_field(wp_unslash($value));
                }
            }
        }

        return $context;
    }

    private function replace_tokens($text) {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }

        $context = !empty($this->matched_context) ? $this->matched_context : $this->build_context();

        $text = preg_replace_callback('/\{([a-zA-Z0-9:_-]+)\}/', function($matches) use ($context) {
            $key = $matches[1];
            return isset($context[$key]) ? (string) $context[$key] : '';
        }, $text);

        $text = preg_replace('/\s+/', ' ', trim($text));
        return $text;
    }

    public function setup_frontend_override_hooks() {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || is_feed()) {
            return;
        }

        $override = $this->match_override_for_current_request();
        if (!$override) {
            return;
        }

        if (!empty($override['h1_override'])) {
            ob_start([$this, 'replace_h1_in_buffer']);
        }
    }

    public function replace_h1_in_buffer($html) {
        $override = $this->match_override_for_current_request();
        if (!$override || empty($override['h1_override']) || !is_string($html) || stripos($html, '<h1') === false) {
            return $html;
        }

        $replacement = $this->replace_tokens($override['h1_override']);
        if ($replacement === '') {
            return $html;
        }

        return preg_replace('/<h1\b([^>]*)>.*?<\/h1>/is', '<h1$1>' . esc_html($replacement) . '</h1>', $html, 1);
    }

    public function filter_document_title($title) {
        $override = $this->match_override_for_current_request();
        if (!$override || empty($override['meta_title'])) {
            return $title;
        }

        $resolved = $this->replace_tokens($override['meta_title']);
        return $resolved !== '' ? $resolved : $title;
    }

    public function output_meta_tags() {
        $override = $this->match_override_for_current_request();
        if (!$override) {
            return;
        }

        $description = $this->replace_tokens($override['meta_description']);
        $keywords = $this->replace_tokens($override['keywords']);

        if ($description !== '') {
            echo "\n" . '<meta name="description" content="' . esc_attr($description) . '" />' . "\n";
        }

        if ($keywords !== '') {
            echo '<meta name="keywords" content="' . esc_attr($keywords) . '" />' . "\n";
        }
    }

    private function get_example_rows() {
        return [
            [
                'target_url' => '/cars/?make=bmw&location=london',
                'meta_title' => 'Used BMW Cars in {query:location} | {site_name}',
                'meta_description' => 'Browse used BMW cars in {query:location}. Updated filter page for {site_name}.',
                'keywords' => 'bmw, used bmw, {query:location}, cars',
                'h1_override' => 'Used BMW Cars in {query:location}',
            ],
            [
                'target_url' => '/services/family-law/',
                'meta_title' => '{post_title} | {site_name}',
                'meta_description' => 'Learn more about {post_title} from {site_name}.',
                'keywords' => '{post_title}, legal services',
                'h1_override' => '{post_title}',
            ],
        ];
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'seo-performance-checker'));
        }

        $saved_notice = false;
        if (isset($_POST['seopc_save_dynamic_overrides']) && check_admin_referer('seopc_save_dynamic_overrides_action', 'seopc_save_dynamic_overrides_nonce')) {
            $rows = isset($_POST['seopc_dynamic_rows']) && is_array($_POST['seopc_dynamic_rows']) ? wp_unslash($_POST['seopc_dynamic_rows']) : [];
            $clean = [];
            foreach ($rows as $row) {
                $row = $this->sanitize_row($row);
                if (empty($row['target_url']) && empty($row['meta_title']) && empty($row['meta_description']) && empty($row['keywords']) && empty($row['h1_override'])) {
                    continue;
                }
                $clean[] = $row;
            }
            update_option('seopc_dynamic_overrides', $clean, false);
            $saved_notice = true;
        }

        $rows = $this->get_option_rows();
        if (empty($rows)) {
            $rows[] = ['enabled' => 1, 'target_url' => '', 'meta_title' => '', 'meta_description' => '', 'keywords' => '', 'h1_override' => ''];
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('SEO Checker', 'seo-performance-checker'); ?></h1>
            <?php if (class_exists('SEOPC_Admin_Menu')) { SEOPC_Admin_Menu::render_tabs('dynamic-overrides'); } ?>
            <h2><?php esc_html_e('Dynamic Overrides', 'seo-performance-checker'); ?></h2>
            <p><?php esc_html_e('Add repeater-style rules for filter pages or specific URLs to override title, meta description, keywords, and the first H1 on the real front-end page.', 'seo-performance-checker'); ?></p>

            <?php if ($saved_notice) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Dynamic overrides saved.', 'seo-performance-checker'); ?></p></div>
            <?php endif; ?>

            <div class="notice notice-info"><p><strong><?php esc_html_e('How matching works:', 'seo-performance-checker'); ?></strong> <?php esc_html_e('Use a full site-relative URL like /cars/?make=bmw, or a path like /services/family-law/. You can also use * as a wildcard.', 'seo-performance-checker'); ?></p>
            <p><strong><?php esc_html_e('Supported dynamic tokens:', 'seo-performance-checker'); ?></strong> <code>{site_name}</code> <code>{url}</code> <code>{request_path}</code> <code>{post_title}</code> <code>{post_type}</code> <code>{year}</code> <code>{month}</code> <code>{day}</code> <code>{query:key}</code></p></div>

            <form method="post">
                <?php wp_nonce_field('seopc_save_dynamic_overrides_action', 'seopc_save_dynamic_overrides_nonce'); ?>
                <table class="widefat striped" id="seopc-dynamic-overrides-table">
                    <thead>
                        <tr>
                            <th style="width:70px;"><?php esc_html_e('Enabled', 'seo-performance-checker'); ?></th>
                            <th><?php esc_html_e('Page or Post URL', 'seo-performance-checker'); ?></th>
                            <th><?php esc_html_e('Meta Title', 'seo-performance-checker'); ?></th>
                            <th><?php esc_html_e('Meta Description', 'seo-performance-checker'); ?></th>
                            <th><?php esc_html_e('Keywords', 'seo-performance-checker'); ?></th>
                            <th><?php esc_html_e('H1 Override', 'seo-performance-checker'); ?></th>
                            <th style="width:90px;"><?php esc_html_e('Action', 'seo-performance-checker'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $index => $row) : ?>
                            <tr>
                                <td><input type="checkbox" name="seopc_dynamic_rows[<?php echo (int) $index; ?>][enabled]" value="1" <?php checked(!empty($row['enabled'])); ?> /></td>
                                <td><input type="text" class="widefat" name="seopc_dynamic_rows[<?php echo (int) $index; ?>][target_url]" value="<?php echo esc_attr($row['target_url'] ?? ''); ?>" placeholder="/cars/?make=bmw&amp;location=london" /></td>
                                <td><textarea class="widefat" rows="2" name="seopc_dynamic_rows[<?php echo (int) $index; ?>][meta_title]"><?php echo esc_textarea($row['meta_title'] ?? ''); ?></textarea></td>
                                <td><textarea class="widefat" rows="3" name="seopc_dynamic_rows[<?php echo (int) $index; ?>][meta_description]"><?php echo esc_textarea($row['meta_description'] ?? ''); ?></textarea></td>
                                <td><textarea class="widefat" rows="2" name="seopc_dynamic_rows[<?php echo (int) $index; ?>][keywords]"><?php echo esc_textarea($row['keywords'] ?? ''); ?></textarea></td>
                                <td><textarea class="widefat" rows="2" name="seopc_dynamic_rows[<?php echo (int) $index; ?>][h1_override]"><?php echo esc_textarea($row['h1_override'] ?? ''); ?></textarea></td>
                                <td><button type="button" class="button seopc-remove-dynamic-row"><?php esc_html_e('Remove', 'seo-performance-checker'); ?></button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
                    <button type="button" class="button" id="seopc-add-dynamic-row"><?php esc_html_e('Add row', 'seo-performance-checker'); ?></button>
                    <button type="submit" name="seopc_save_dynamic_overrides" class="button button-primary"><?php esc_html_e('Save dynamic overrides', 'seo-performance-checker'); ?></button>
                </p>
            </form>

            <hr />
            <h2><?php esc_html_e('Example rules', 'seo-performance-checker'); ?></h2>
            <table class="widefat striped"><thead><tr><th><?php esc_html_e('Target URL', 'seo-performance-checker'); ?></th><th><?php esc_html_e('Meta Title', 'seo-performance-checker'); ?></th><th><?php esc_html_e('Meta Description', 'seo-performance-checker'); ?></th><th><?php esc_html_e('Keywords', 'seo-performance-checker'); ?></th><th><?php esc_html_e('H1 Override', 'seo-performance-checker'); ?></th></tr></thead><tbody>
            <?php foreach ($this->get_example_rows() as $example) : ?>
                <tr>
                    <td><code><?php echo esc_html($example['target_url']); ?></code></td>
                    <td><?php echo esc_html($example['meta_title']); ?></td>
                    <td><?php echo esc_html($example['meta_description']); ?></td>
                    <td><?php echo esc_html($example['keywords']); ?></td>
                    <td><?php echo esc_html($example['h1_override']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>

        <script>
        (function(){
            const table = document.getElementById('seopc-dynamic-overrides-table');
            if (!table) return;
            const tbody = table.querySelector('tbody');
            const addBtn = document.getElementById('seopc-add-dynamic-row');

            function nextIndex() {
                return tbody.querySelectorAll('tr').length;
            }

            function bindRemoveButtons(scope) {
                scope.querySelectorAll('.seopc-remove-dynamic-row').forEach(function(btn) {
                    btn.onclick = function() {
                        const rows = tbody.querySelectorAll('tr');
                        if (rows.length === 1) {
                            rows[0].querySelectorAll('input[type="text"], textarea').forEach(function(el){ el.value=''; });
                            rows[0].querySelectorAll('input[type="checkbox"]').forEach(function(el){ el.checked=false; });
                            return;
                        }
                        btn.closest('tr').remove();
                    };
                });
            }

            bindRemoveButtons(table);

            addBtn.addEventListener('click', function(){
                const i = nextIndex();
                const tr = document.createElement('tr');
                tr.innerHTML = '' +
                    '<td><input type="checkbox" name="seopc_dynamic_rows[' + i + '][enabled]" value="1" checked /></td>' +
                    '<td><input type="text" class="widefat" name="seopc_dynamic_rows[' + i + '][target_url]" placeholder="/cars/?make=bmw&amp;location=london" /></td>' +
                    '<td><textarea class="widefat" rows="2" name="seopc_dynamic_rows[' + i + '][meta_title]"></textarea></td>' +
                    '<td><textarea class="widefat" rows="3" name="seopc_dynamic_rows[' + i + '][meta_description]"></textarea></td>' +
                    '<td><textarea class="widefat" rows="2" name="seopc_dynamic_rows[' + i + '][keywords]"></textarea></td>' +
                    '<td><textarea class="widefat" rows="2" name="seopc_dynamic_rows[' + i + '][h1_override]"></textarea></td>' +
                    '<td><button type="button" class="button seopc-remove-dynamic-row">Remove</button></td>';
                tbody.appendChild(tr);
                bindRemoveButtons(tr);
            });
        })();
        </script>
        <?php
    }
}
