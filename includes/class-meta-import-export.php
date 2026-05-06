<?php

if (!defined('ABSPATH')) {
    exit;
}

class SEOPC_Meta_Import_Export {

    const NONCE_ACTION = 'seopc_meta_import_export';
    const TAB_SLUG = 'meta-import-export';

    public function __construct() {
        add_action('admin_post_seopc_meta_export_csv', [$this, 'handle_export_csv']);
        add_action('admin_post_seopc_meta_export_old_site_csv', [$this, 'handle_export_old_site_csv']);
        add_action('admin_post_seopc_meta_import_csv', [$this, 'handle_import_csv']);
        add_action('admin_post_seopc_meta_import_old_site', [$this, 'handle_import_old_site']);
        add_action('admin_post_seopc_generate_missing_meta', [$this, 'handle_generate_missing_meta']);
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'seo-performance-checker'));
        }

        $message = isset($_GET['seopc_message']) ? sanitize_key(wp_unslash($_GET['seopc_message'])) : '';
        $count = isset($_GET['seopc_count']) ? absint($_GET['seopc_count']) : 0;
        $skipped = isset($_GET['seopc_skipped']) ? absint($_GET['seopc_skipped']) : 0;
        $error = isset($_GET['seopc_error']) ? sanitize_text_field(wp_unslash($_GET['seopc_error'])) : '';
        $report = $this->get_result_report();
        $post_types = $this->get_supported_post_types();
        ?>
        <div class="wrap seopc-wrap">
            <h1><?php esc_html_e('SEO Performance Checker', 'seo-performance-checker'); ?></h1>
            <?php if (class_exists('SEOPC_Admin_Menu')) { SEOPC_Admin_Menu::render_tabs(self::TAB_SLUG); } ?>

            <?php if ($message === 'imported') : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html(sprintf(__('Updated %1$d item(s). Skipped %2$d row(s).', 'seo-performance-checker'), $count, $skipped)); ?></p></div>
            <?php elseif ($message === 'generated') : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html(sprintf(__('Generated SEO meta for %1$d item(s). Skipped %2$d item(s).', 'seo-performance-checker'), $count, $skipped)); ?></p></div>
            <?php elseif ($message === 'exported') : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Export prepared.', 'seo-performance-checker'); ?></p></div>
            <?php elseif ($error !== '') : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <?php $this->render_result_report($report); ?>

            <div class="seopc-card">
                <h2><?php esc_html_e('Import meta from an old WordPress site', 'seo-performance-checker'); ?></h2>
                <p><?php esc_html_e('Enter the old site URL. The tool reads public WordPress REST data and Yoast REST fields when available, then falls back to each page HTML meta tags. Matching is done by URL path, slug, and title.', 'seo-performance-checker'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>
                    <input type="hidden" name="action" value="seopc_meta_import_old_site" />
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="seopc_old_site_url"><?php esc_html_e('Old WordPress site URL', 'seo-performance-checker'); ?></label></th>
                                <td><input type="url" class="regular-text" id="seopc_old_site_url" name="old_site_url" placeholder="https://old-site.com" required /></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Post types', 'seo-performance-checker'); ?></th>
                                <td>
                                    <?php foreach ($post_types as $post_type => $label) : ?>
                                        <label style="display:inline-block;margin-right:16px;"><input type="checkbox" name="post_types[]" value="<?php echo esc_attr($post_type); ?>" <?php checked(in_array($post_type, ['post', 'page'], true)); ?> /> <?php echo esc_html($label); ?></label>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="seopc_old_site_limit"><?php esc_html_e('Maximum items per type', 'seo-performance-checker'); ?></label></th>
                                <td><input type="number" id="seopc_old_site_limit" name="limit" value="200" min="1" max="1000" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Generate missing imported values', 'seo-performance-checker'); ?></th>
                                <td><label><input type="checkbox" name="generate_missing" value="1" checked /> <?php esc_html_e('If the old site has no title, description, or keywords, generate basic SEO values from the matched post content on this site.', 'seo-performance-checker'); ?></label></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Overwrite existing Yoast values', 'seo-performance-checker'); ?></th>
                                <td><label><input type="checkbox" name="overwrite" value="1" /> <?php esc_html_e('Replace existing SEO title, meta description, and focus keywords on matched posts.', 'seo-performance-checker'); ?></label></td>
                            </tr>
                        </tbody>
                    </table>
                    <?php submit_button(__('Import from old site', 'seo-performance-checker')); ?>
                </form>
            </div>

            <div class="seopc-card">
                <h2><?php esc_html_e('Export meta from any old website', 'seo-performance-checker'); ?></h2>
                <p><?php esc_html_e('Enter an old website URL to export detected meta title, meta description, and meta keywords as a CSV for later import. The tool first tries WordPress REST data, then falls back to sitemap.xml and homepage links for non-WordPress sites.', 'seo-performance-checker'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>
                    <input type="hidden" name="action" value="seopc_meta_export_old_site_csv" />
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="seopc_export_old_site_url"><?php esc_html_e('Old website URL', 'seo-performance-checker'); ?></label></th>
                                <td><input type="url" class="regular-text" id="seopc_export_old_site_url" name="old_site_url" placeholder="https://old-site.com" required /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="seopc_export_old_site_limit"><?php esc_html_e('Maximum URLs to scan', 'seo-performance-checker'); ?></label></th>
                                <td>
                                    <input type="number" id="seopc_export_old_site_limit" name="limit" value="300" min="1" max="2000" />
                                    <p class="description"><?php esc_html_e('Large sites can take longer. Start with 300, then increase if needed.', 'seo-performance-checker'); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <?php submit_button(__('Export old website CSV', 'seo-performance-checker'), 'secondary'); ?>
                </form>
            </div>

            <div class="seopc-card">
                <h2><?php esc_html_e('Import meta from CSV', 'seo-performance-checker'); ?></h2>
                <p><?php esc_html_e('Upload a CSV with columns: url, path, slug, title, post_type, meta_title, meta_description, keywords. The export below uses the same format.', 'seo-performance-checker'); ?></p>
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>
                    <input type="hidden" name="action" value="seopc_meta_import_csv" />
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="seopc_meta_csv"><?php esc_html_e('CSV file', 'seo-performance-checker'); ?></label></th>
                                <td><input type="file" id="seopc_meta_csv" name="meta_csv" accept=".csv,text/csv" required /></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Generate missing imported values', 'seo-performance-checker'); ?></th>
                                <td><label><input type="checkbox" name="generate_missing" value="1" checked /> <?php esc_html_e('If CSV title, description, or keywords are empty, generate basic SEO values from the matched post content on this site.', 'seo-performance-checker'); ?></label></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Overwrite existing Yoast values', 'seo-performance-checker'); ?></th>
                                <td><label><input type="checkbox" name="overwrite" value="1" /> <?php esc_html_e('Replace existing SEO title, meta description, and focus keywords on matched posts.', 'seo-performance-checker'); ?></label></td>
                            </tr>
                        </tbody>
                    </table>
                    <?php submit_button(__('Import CSV', 'seo-performance-checker')); ?>
                </form>
            </div>

            <div class="seopc-card">
                <h2><?php esc_html_e('Generate missing meta for this site', 'seo-performance-checker'); ?></h2>
                <p><?php esc_html_e('Use this separate tool when you are not importing from an old site. It creates a basic SEO title, short meta description, and focus keywords from each post/page title and content, then saves them into Yoast fields only where values are missing unless overwrite is selected.', 'seo-performance-checker'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>
                    <input type="hidden" name="action" value="seopc_generate_missing_meta" />
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e('Post types', 'seo-performance-checker'); ?></th>
                                <td>
                                    <?php foreach ($post_types as $post_type => $label) : ?>
                                        <label style="display:inline-block;margin-right:16px;"><input type="checkbox" name="post_types[]" value="<?php echo esc_attr($post_type); ?>" <?php checked(in_array($post_type, ['post', 'page'], true)); ?> /> <?php echo esc_html($label); ?></label>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="seopc_generate_limit"><?php esc_html_e('Maximum items', 'seo-performance-checker'); ?></label></th>
                                <td><input type="number" id="seopc_generate_limit" name="limit" value="300" min="1" max="2000" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Fields to generate', 'seo-performance-checker'); ?></th>
                                <td>
                                    <label style="display:inline-block;margin-right:16px;"><input type="checkbox" name="fields[]" value="title" checked /> <?php esc_html_e('SEO title', 'seo-performance-checker'); ?></label>
                                    <label style="display:inline-block;margin-right:16px;"><input type="checkbox" name="fields[]" value="description" checked /> <?php esc_html_e('Meta description', 'seo-performance-checker'); ?></label>
                                    <label style="display:inline-block;margin-right:16px;"><input type="checkbox" name="fields[]" value="keywords" checked /> <?php esc_html_e('Keywords', 'seo-performance-checker'); ?></label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Overwrite existing Yoast values', 'seo-performance-checker'); ?></th>
                                <td><label><input type="checkbox" name="overwrite" value="1" /> <?php esc_html_e('Replace existing values. Leave unchecked to fill missing values only.', 'seo-performance-checker'); ?></label></td>
                            </tr>
                        </tbody>
                    </table>
                    <?php submit_button(__('Generate missing meta', 'seo-performance-checker'), 'secondary'); ?>
                </form>
            </div>

            <div class="seopc-card">
                <h2><?php esc_html_e('Export current site meta', 'seo-performance-checker'); ?></h2>
                <p><?php esc_html_e('Download current WordPress and Yoast meta values as a CSV you can edit or import into another site.', 'seo-performance-checker'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>
                    <input type="hidden" name="action" value="seopc_meta_export_csv" />
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e('Post types', 'seo-performance-checker'); ?></th>
                                <td>
                                    <?php foreach ($post_types as $post_type => $label) : ?>
                                        <label style="display:inline-block;margin-right:16px;"><input type="checkbox" name="post_types[]" value="<?php echo esc_attr($post_type); ?>" <?php checked(in_array($post_type, ['post', 'page'], true)); ?> /> <?php echo esc_html($label); ?></label>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <?php submit_button(__('Export CSV', 'seo-performance-checker'), 'secondary'); ?>
                </form>
            </div>
        </div>
        <?php
    }

    public function handle_export_csv() {
        $this->verify_request();
        $post_types = $this->sanitize_post_types($_POST['post_types'] ?? ['post', 'page']);

        $filename = 'seo-meta-export-' . gmdate('Y-m-d-H-i-s') . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $out = fopen('php://output', 'w');
        fputcsv($out, ['id', 'post_type', 'title', 'slug', 'url', 'path', 'meta_title', 'meta_description', 'keywords']);

        $query = new WP_Query([
            'post_type' => $post_types,
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        foreach ($query->posts as $post_id) {
            $url = get_permalink($post_id);
            fputcsv($out, [
                $post_id,
                get_post_type($post_id),
                get_the_title($post_id),
                get_post_field('post_name', $post_id),
                $url,
                $this->path_from_url($url),
                $this->get_meta_title($post_id),
                $this->get_meta_description($post_id),
                implode(', ', $this->get_keywords($post_id)),
            ]);
        }
        fclose($out);
        exit;
    }

    public function handle_export_old_site_csv() {
        $this->verify_request();

        $old_site_url = isset($_POST['old_site_url']) ? esc_url_raw(wp_unslash($_POST['old_site_url'])) : '';
        if (!$old_site_url) {
            $this->redirect_with_error(__('Please enter a valid old website URL.', 'seo-performance-checker'));
        }

        $limit = isset($_POST['limit']) ? min(2000, max(1, absint($_POST['limit']))) : 300;
        $rows = $this->fetch_any_old_site_rows($old_site_url, $limit);

        if (is_wp_error($rows)) {
            $this->redirect_with_error($rows->get_error_message());
        }

        $host = wp_parse_url($old_site_url, PHP_URL_HOST);
        $host = $host ? sanitize_title($host) : 'old-website';
        $filename = 'old-site-seo-meta-' . $host . '-' . gmdate('Y-m-d-H-i-s') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $out = fopen('php://output', 'w');
        fputcsv($out, ['id', 'post_type', 'title', 'slug', 'url', 'path', 'meta_title', 'meta_description', 'keywords']);

        foreach ($rows as $row) {
            fputcsv($out, [
                $row['id'] ?? '',
                $row['post_type'] ?? 'page',
                $row['title'] ?? '',
                $row['slug'] ?? '',
                $row['url'] ?? '',
                $row['path'] ?? '',
                $row['meta_title'] ?? '',
                $row['meta_description'] ?? '',
                $row['keywords'] ?? '',
            ]);
        }

        fclose($out);
        exit;
    }

    public function handle_import_csv() {
        $this->verify_request();

        if (empty($_FILES['meta_csv']['tmp_name'])) {
            $this->redirect_with_error(__('Please upload a CSV file.', 'seo-performance-checker'));
        }

        $overwrite = !empty($_POST['overwrite']);
        $generate_missing = !empty($_POST['generate_missing']);
        $handle = fopen(sanitize_text_field(wp_unslash($_FILES['meta_csv']['tmp_name'])), 'r');
        if (!$handle) {
            $this->redirect_with_error(__('Could not read the uploaded CSV file.', 'seo-performance-checker'));
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            $this->redirect_with_error(__('CSV file is empty.', 'seo-performance-checker'));
        }

        $header = array_map([$this, 'normalize_header'], $header);
        $updated = 0;
        $skipped = 0;
        $report = [];

        while (($data = fgetcsv($handle)) !== false) {
            $row = [];
            foreach ($header as $index => $key) {
                $row[$key] = isset($data[$index]) ? $data[$index] : '';
            }
            $post_id = $this->find_matching_post($row);
            if (!$post_id) {
                $skipped++;
                $report[] = $this->build_report_row($row, 0, 'skipped', __('No matching post/page found on this site.', 'seo-performance-checker'), []);
                continue;
            }
            if ($generate_missing) {
                $row = $this->fill_missing_row_meta_from_post($post_id, $row);
            }
            $applied_fields = [];
            if ($this->update_post_seo_meta($post_id, $row, $overwrite, $applied_fields)) {
                $updated++;
                $report[] = $this->build_report_row($row, $post_id, 'updated', __('Yoast SEO meta updated.', 'seo-performance-checker'), $applied_fields);
            } else {
                $skipped++;
                $report[] = $this->build_report_row($row, $post_id, 'skipped', __('Matched, but no empty fields needed updating. Enable overwrite to replace existing values.', 'seo-performance-checker'), []);
            }
        }

        fclose($handle);
        $this->redirect_with_result($updated, $skipped, $report);
    }

    public function handle_import_old_site() {
        $this->verify_request();

        $old_site_url = isset($_POST['old_site_url']) ? esc_url_raw(wp_unslash($_POST['old_site_url'])) : '';
        if (!$old_site_url) {
            $this->redirect_with_error(__('Please enter a valid old site URL.', 'seo-performance-checker'));
        }

        $post_types = $this->sanitize_post_types($_POST['post_types'] ?? ['post', 'page']);
        $limit = isset($_POST['limit']) ? min(1000, max(1, absint($_POST['limit']))) : 200;
        $overwrite = !empty($_POST['overwrite']);
        $generate_missing = !empty($_POST['generate_missing']);
        $rows = $this->fetch_old_site_rows($old_site_url, $post_types, $limit);

        if (is_wp_error($rows)) {
            $this->redirect_with_error($rows->get_error_message());
        }

        $updated = 0;
        $skipped = 0;
        $report = [];
        foreach ($rows as $row) {
            $post_id = $this->find_matching_post($row);
            if (!$post_id) {
                $skipped++;
                $report[] = $this->build_report_row($row, 0, 'skipped', __('No matching post/page found on this site.', 'seo-performance-checker'), []);
                continue;
            }
            if ($generate_missing) {
                $row = $this->fill_missing_row_meta_from_post($post_id, $row);
            }
            $applied_fields = [];
            if ($this->update_post_seo_meta($post_id, $row, $overwrite, $applied_fields)) {
                $updated++;
                $report[] = $this->build_report_row($row, $post_id, 'updated', __('Yoast SEO meta updated.', 'seo-performance-checker'), $applied_fields);
            } else {
                $skipped++;
                $report[] = $this->build_report_row($row, $post_id, 'skipped', __('Matched, but no empty fields needed updating. Enable overwrite to replace existing values.', 'seo-performance-checker'), []);
            }
        }

        $this->redirect_with_result($updated, $skipped, $report);
    }


    public function handle_generate_missing_meta() {
        $this->verify_request();

        $post_types = $this->sanitize_post_types($_POST['post_types'] ?? ['post', 'page']);
        $limit = isset($_POST['limit']) ? min(2000, max(1, absint($_POST['limit']))) : 300;
        $overwrite = !empty($_POST['overwrite']);
        $fields = isset($_POST['fields']) ? array_map('sanitize_key', (array) $_POST['fields']) : ['title', 'description', 'keywords'];
        $fields = array_values(array_intersect($fields, ['title', 'description', 'keywords']));
        if (empty($fields)) {
            $fields = ['title', 'description', 'keywords'];
        }

        $query = new WP_Query([
            'post_type' => $post_types,
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => $limit,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        $updated = 0;
        $skipped = 0;
        foreach ($query->posts as $post_id) {
            $generated = $this->generate_meta_from_post($post_id);
            $row = [
                'meta_title' => in_array('title', $fields, true) ? $generated['meta_title'] : '',
                'meta_description' => in_array('description', $fields, true) ? $generated['meta_description'] : '',
                'keywords' => in_array('keywords', $fields, true) ? $generated['keywords'] : '',
            ];

            if ($this->update_post_seo_meta($post_id, $row, $overwrite)) {
                $updated++;
            } else {
                $skipped++;
            }
        }

        wp_safe_redirect(add_query_arg([
            'page' => 'seo-performance',
            'tab' => self::TAB_SLUG,
            'seopc_message' => 'generated',
            'seopc_count' => absint($updated),
            'seopc_skipped' => absint($skipped),
        ], admin_url('options-general.php')));
        exit;
    }

    private function verify_request() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'seo-performance-checker'));
        }
        check_admin_referer(self::NONCE_ACTION);
    }

    private function fetch_any_old_site_rows($site_url, $limit) {
        $site_url = untrailingslashit($site_url);

        $wp_rows = $this->fetch_old_site_rows($site_url, ['post', 'page'], $limit);
        if (!is_wp_error($wp_rows) && !empty($wp_rows)) {
            return array_slice($wp_rows, 0, $limit);
        }

        $generic_rows = $this->fetch_generic_site_rows($site_url, $limit);
        if (!is_wp_error($generic_rows) && !empty($generic_rows)) {
            return $generic_rows;
        }

        return new WP_Error('seopc_no_old_site_rows', __('Could not find exportable pages on the old website. Check that the site is publicly accessible and has a sitemap, WordPress REST API, or internal homepage links.', 'seo-performance-checker'));
    }

    private function fetch_generic_site_rows($site_url, $limit) {
        $urls = $this->discover_old_site_urls($site_url, $limit);
        if (is_wp_error($urls)) {
            return $urls;
        }
        if (empty($urls)) {
            return new WP_Error('seopc_no_urls', __('No URLs were found on the old website.', 'seo-performance-checker'));
        }

        $rows = [];
        foreach (array_slice($urls, 0, $limit) as $url) {
            $html_meta = $this->fetch_html_meta($url);
            if (is_wp_error($html_meta)) {
                continue;
            }

            $path = $this->path_from_url($url);
            $slug = basename(trim($path, '/'));
            $title = $html_meta['meta_title'] !== '' ? $html_meta['meta_title'] : $slug;

            $rows[] = [
                'id' => '',
                'post_type' => 'page',
                'title' => $title,
                'slug' => sanitize_title($slug),
                'url' => esc_url_raw($url),
                'path' => $path,
                'meta_title' => $html_meta['meta_title'],
                'meta_description' => $html_meta['meta_description'],
                'keywords' => $html_meta['keywords'],
            ];
        }

        return $rows;
    }

    private function discover_old_site_urls($site_url, $limit) {
        $site_url = untrailingslashit($site_url);
        $home_url = trailingslashit($site_url);
        $urls = [$home_url];

        $sitemap_urls = $this->fetch_sitemap_urls($site_url . '/sitemap.xml', $limit);
        if (!is_wp_error($sitemap_urls) && !empty($sitemap_urls)) {
            $urls = array_merge($urls, $sitemap_urls);
        }

        if (count($urls) < $limit) {
            $wp_sitemap_urls = $this->fetch_sitemap_urls($site_url . '/wp-sitemap.xml', $limit);
            if (!is_wp_error($wp_sitemap_urls) && !empty($wp_sitemap_urls)) {
                $urls = array_merge($urls, $wp_sitemap_urls);
            }
        }

        if (count($urls) < 2) {
            $homepage_links = $this->fetch_internal_links_from_page($home_url, $limit);
            if (!is_wp_error($homepage_links) && !empty($homepage_links)) {
                $urls = array_merge($urls, $homepage_links);
            }
        }

        $urls = $this->filter_unique_site_urls($urls, $site_url);
        return array_slice($urls, 0, $limit);
    }

    private function fetch_sitemap_urls($sitemap_url, $limit, $depth = 0) {
        if ($depth > 2) {
            return [];
        }

        $response = wp_remote_get($sitemap_url, ['timeout' => 20, 'redirection' => 5]);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        if ($body === '') {
            return [];
        }

        $urls = [];
        if (preg_match_all('/<loc>\s*([^<]+)\s*<\/loc>/i', $body, $matches)) {
            foreach ($matches[1] as $loc) {
                $loc = esc_url_raw(html_entity_decode(trim($loc), ENT_QUOTES, get_bloginfo('charset')));
                if ($loc === '') {
                    continue;
                }

                if (preg_match('/\.xml(\.gz)?($|[?#])/i', $loc)) {
                    $child_urls = $this->fetch_sitemap_urls($loc, $limit, $depth + 1);
                    if (!is_wp_error($child_urls)) {
                        $urls = array_merge($urls, $child_urls);
                    }
                } else {
                    $urls[] = $loc;
                }

                if (count($urls) >= $limit) {
                    break;
                }
            }
        }

        return array_slice($urls, 0, $limit);
    }

    private function fetch_internal_links_from_page($url, $limit) {
        $response = wp_remote_get($url, ['timeout' => 20, 'redirection' => 5]);
        if (is_wp_error($response)) {
            return $response;
        }

        $html = wp_remote_retrieve_body($response);
        if ($html === '') {
            return [];
        }

        $links = [];
        if (preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            foreach ($matches[1] as $href) {
                $absolute = $this->absolute_url($href, $url);
                if ($absolute !== '') {
                    $links[] = $absolute;
                }
                if (count($links) >= $limit) {
                    break;
                }
            }
        }

        return $links;
    }

    private function absolute_url($href, $base_url) {
        $href = trim(html_entity_decode($href, ENT_QUOTES, get_bloginfo('charset')));
        if ($href === '' || strpos($href, '#') === 0 || preg_match('/^(mailto|tel|javascript):/i', $href)) {
            return '';
        }

        if (preg_match('/^https?:\/\//i', $href)) {
            return esc_url_raw($href);
        }

        $scheme = wp_parse_url($base_url, PHP_URL_SCHEME) ?: 'https';
        $host = wp_parse_url($base_url, PHP_URL_HOST);
        if (!$host) {
            return '';
        }

        if (strpos($href, '//') === 0) {
            return esc_url_raw($scheme . ':' . $href);
        }

        if (strpos($href, '/') === 0) {
            return esc_url_raw($scheme . '://' . $host . $href);
        }

        $base_path = wp_parse_url($base_url, PHP_URL_PATH) ?: '/';
        $base_dir = trailingslashit(dirname($base_path));
        return esc_url_raw($scheme . '://' . $host . $base_dir . $href);
    }

    private function filter_unique_site_urls($urls, $site_url) {
        $site_host = strtolower((string) wp_parse_url($site_url, PHP_URL_HOST));
        $seen = [];
        $out = [];

        foreach ($urls as $url) {
            $url = esc_url_raw($url);
            if ($url === '') {
                continue;
            }

            $parts = wp_parse_url($url);
            if (empty($parts['host']) || strtolower($parts['host']) !== $site_host) {
                continue;
            }

            $path = $parts['path'] ?? '/';
            if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg|pdf|zip|rar|css|js|ico|xml|txt)$/i', $path)) {
                continue;
            }

            $normalized = untrailingslashit($parts['scheme'] . '://' . $parts['host'] . $path);
            if ($normalized === 'http://' . $site_host || $normalized === 'https://' . $site_host) {
                $normalized = trailingslashit($normalized);
            }

            $key = strtolower($normalized);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $normalized;
        }

        return $out;
    }

    private function fetch_old_site_rows($site_url, $post_types, $limit) {
        $site_url = untrailingslashit($site_url);
        $rows = [];
        $rest_type_map = $this->fetch_rest_type_map($site_url);
        if (is_wp_error($rest_type_map)) {
            $rest_type_map = ['post' => 'posts', 'page' => 'pages'];
        }

        foreach ($post_types as $post_type) {
            $rest_type = $rest_type_map[$post_type] ?? ($post_type === 'post' ? 'posts' : ($post_type === 'page' ? 'pages' : $post_type));
            $page = 1;
            $fetched_for_type = 0;

            do {
                $per_page = min(100, $limit - $fetched_for_type);
                if ($per_page <= 0) {
                    break;
                }

                $endpoint = add_query_arg([
                    'per_page' => $per_page,
                    'page' => $page,
                    'status' => 'publish',
                    '_fields' => 'id,link,slug,title,yoast_head_json',
                ], $site_url . '/wp-json/wp/v2/' . rawurlencode($rest_type));

                $response = wp_remote_get($endpoint, ['timeout' => 20, 'redirection' => 5]);
                if (is_wp_error($response)) {
                    return $response;
                }

                $code = (int) wp_remote_retrieve_response_code($response);
                if ($code < 200 || $code >= 300) {
                    if ($page === 1) {
                        break;
                    }
                    break;
                }

                $items = json_decode(wp_remote_retrieve_body($response), true);
                if (!is_array($items) || empty($items)) {
                    break;
                }

                foreach ($items as $item) {
                    $link = isset($item['link']) ? esc_url_raw($item['link']) : '';
                    $title = isset($item['title']['rendered']) ? wp_strip_all_tags($item['title']['rendered']) : '';
                    $yoast = isset($item['yoast_head_json']) && is_array($item['yoast_head_json']) ? $item['yoast_head_json'] : [];
                    $meta_title = isset($yoast['title']) ? wp_strip_all_tags($yoast['title']) : '';
                    $meta_description = isset($yoast['description']) ? wp_strip_all_tags($yoast['description']) : '';
                    $keywords = isset($yoast['schema']['@graph']) ? '' : '';

                    if (($meta_title === '' || $meta_description === '' || $keywords === '') && $link !== '') {
                        $html_meta = $this->fetch_html_meta($link);
                        if (!is_wp_error($html_meta)) {
                            $meta_title = $meta_title !== '' ? $meta_title : $html_meta['meta_title'];
                            $meta_description = $meta_description !== '' ? $meta_description : $html_meta['meta_description'];
                            $keywords = $keywords !== '' ? $keywords : $html_meta['keywords'];
                        }
                    }

                    $rows[] = [
                        'post_type' => $post_type,
                        'title' => $title,
                        'slug' => isset($item['slug']) ? sanitize_title($item['slug']) : '',
                        'url' => $link,
                        'path' => $this->path_from_url($link),
                        'meta_title' => $meta_title,
                        'meta_description' => $meta_description,
                        'keywords' => $keywords,
                    ];
                    $fetched_for_type++;
                }

                $total_pages = (int) wp_remote_retrieve_header($response, 'x-wp-totalpages');
                $page++;
            } while ($fetched_for_type < $limit && ($total_pages === 0 || $page <= $total_pages));
        }

        if (empty($rows)) {
            return new WP_Error('seopc_no_rows', __('No public WordPress REST items were found on the old site. Confirm the URL is correct and REST API is available.', 'seo-performance-checker'));
        }

        return $rows;
    }

    private function fetch_rest_type_map($site_url) {
        $response = wp_remote_get($site_url . '/wp-json/wp/v2/types', ['timeout' => 15, 'redirection' => 5]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('seopc_types_unavailable', __('Could not read REST post types from the old site.', 'seo-performance-checker'));
        }
        $types = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($types)) {
            return new WP_Error('seopc_types_invalid', __('Old site REST post type response was invalid.', 'seo-performance-checker'));
        }
        $map = [];
        foreach ($types as $type => $data) {
            if (!empty($data['rest_base'])) {
                $map[sanitize_key($type)] = sanitize_key($data['rest_base']);
            }
        }
        return $map;
    }

    private function fetch_html_meta($url) {
        $response = wp_remote_get($url, ['timeout' => 15, 'redirection' => 5]);
        if (is_wp_error($response)) {
            return $response;
        }
        $html = wp_remote_retrieve_body($response);
        if (!$html) {
            return new WP_Error('seopc_empty_html', __('Empty page HTML.', 'seo-performance-checker'));
        }

        $title = '';
        $description = '';
        $keywords = '';

        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES, get_bloginfo('charset'));
        }
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/is', $html, $m) || preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']description["\'][^>]*>/is', $html, $m)) {
            $description = html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES, get_bloginfo('charset'));
        }
        if (preg_match('/<meta[^>]+name=["\']keywords["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/is', $html, $m) || preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']keywords["\'][^>]*>/is', $html, $m)) {
            $keywords = html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES, get_bloginfo('charset'));
        }

        return [
            'meta_title' => sanitize_text_field($title),
            'meta_description' => sanitize_textarea_field($description),
            'keywords' => sanitize_text_field($keywords),
        ];
    }


    private function fill_missing_row_meta_from_post($post_id, $row) {
        $generated = $this->generate_meta_from_post($post_id);
        foreach (['meta_title', 'meta_description', 'keywords'] as $key) {
            if (empty($row[$key])) {
                $row[$key] = $generated[$key];
            }
        }
        return $row;
    }

    private function generate_meta_from_post($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return ['meta_title' => '', 'meta_description' => '', 'keywords' => ''];
        }

        $plain_content = $this->get_plain_post_content($post);
        $post_title = wp_strip_all_tags(get_the_title($post_id));
        $site_name = wp_strip_all_tags(get_bloginfo('name'));
        $meta_title = $this->generate_meta_title($post_title, $site_name);
        $meta_description = $this->generate_meta_description($post, $plain_content);
        $keywords = $this->generate_keywords($post_title . ' ' . $plain_content);

        return [
            'meta_title' => $meta_title,
            'meta_description' => $meta_description,
            'keywords' => implode(', ', $keywords),
        ];
    }

    private function get_plain_post_content($post) {
        $content = $post->post_excerpt !== '' ? $post->post_excerpt . ' ' . $post->post_content : $post->post_content;
        $content = strip_shortcodes($content);
        $content = wp_strip_all_tags($content, true);
        $content = html_entity_decode($content, ENT_QUOTES, get_bloginfo('charset'));
        $content = preg_replace('/\s+/', ' ', $content);
        return trim($content);
    }

    private function generate_meta_title($post_title, $site_name) {
        $title = trim($post_title);
        if ($title === '') {
            $title = $site_name;
        } elseif ($site_name !== '' && stripos($title, $site_name) === false) {
            $candidate = $title . ' | ' . $site_name;
            if (mb_strlen($candidate) <= 60) {
                $title = $candidate;
            }
        }
        return $this->trim_to_length($title, 60);
    }

    private function generate_meta_description($post, $plain_content) {
        $source = trim($post->post_excerpt !== '' ? wp_strip_all_tags($post->post_excerpt, true) : $plain_content);
        if ($source === '') {
            $source = wp_strip_all_tags(get_the_title($post->ID));
        }
        $source = preg_replace('/\s+/', ' ', $source);
        return $this->trim_to_length($source, 155);
    }

    private function generate_keywords($text) {
        $text = strtolower(wp_strip_all_tags($text));
        $text = html_entity_decode($text, ENT_QUOTES, get_bloginfo('charset'));
        $text = preg_replace('/[^a-z0-9\s-]/', ' ', $text);
        $parts = preg_split('/\s+/', $text);
        $stopwords = $this->get_keyword_stopwords();
        $scores = [];
        $last = '';

        foreach ($parts as $word) {
            $word = trim($word, '-');
            if (strlen($word) < 3 || isset($stopwords[$word]) || is_numeric($word)) {
                $last = '';
                continue;
            }
            if (!isset($scores[$word])) {
                $scores[$word] = 0;
            }
            $scores[$word] += 1;

            if ($last !== '') {
                $phrase = $last . ' ' . $word;
                if (!isset($scores[$phrase])) {
                    $scores[$phrase] = 0;
                }
                $scores[$phrase] += 2;
            }
            $last = $word;
        }

        arsort($scores);
        $keywords = [];
        foreach (array_keys($scores) as $keyword) {
            if (count($keywords) >= 8) {
                break;
            }
            if ($this->keyword_is_duplicate($keyword, $keywords)) {
                continue;
            }
            $keywords[] = sanitize_text_field($keyword);
        }
        return $keywords;
    }

    private function keyword_is_duplicate($keyword, $existing) {
        foreach ($existing as $item) {
            if ($keyword === $item || strpos($item, $keyword) !== false || strpos($keyword, $item) !== false) {
                return true;
            }
        }
        return false;
    }

    private function trim_to_length($text, $length) {
        $text = trim(preg_replace('/\s+/', ' ', (string) $text));
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $trimmed = mb_substr($text, 0, $length - 1);
        $last_space = mb_strrpos($trimmed, ' ');
        if ($last_space !== false && $last_space > 30) {
            $trimmed = mb_substr($trimmed, 0, $last_space);
        }
        return rtrim($trimmed, '.,;:- ') . '…';
    }

    private function get_keyword_stopwords() {
        $words = ['the','and','for','with','that','this','from','are','was','were','you','your','our','their','they','them','his','her','its','has','have','had','not','but','all','any','can','will','about','into','over','more','most','new','old','site','page','post','home','what','when','where','why','how','who','which','there','these','those','than','then','also','very','just','each','such','use','using','used','get','make','made','may','per','via','within','without','between','because','while','during','before','after','below','above','wordpress','read','learn','find','best'];
        return array_fill_keys($words, true);
    }

    private function update_post_seo_meta($post_id, $row, $overwrite, &$applied_fields = []) {
        $meta_title = isset($row['meta_title']) ? sanitize_text_field($row['meta_title']) : '';
        $meta_description = isset($row['meta_description']) ? sanitize_textarea_field($row['meta_description']) : '';
        $keywords_raw = isset($row['keywords']) ? sanitize_text_field($row['keywords']) : '';
        $changed = false;
        $applied_fields = [];

        if ($meta_title !== '' && ($overwrite || get_post_meta($post_id, '_yoast_wpseo_title', true) === '')) {
            update_post_meta($post_id, '_yoast_wpseo_title', $meta_title);
            $applied_fields[] = __('SEO title', 'seo-performance-checker');
            $changed = true;
        }

        if ($meta_description !== '' && ($overwrite || get_post_meta($post_id, '_yoast_wpseo_metadesc', true) === '')) {
            update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_description);
            $applied_fields[] = __('Meta description', 'seo-performance-checker');
            $changed = true;
        }

        $keywords = class_exists('SEOPC_Keyword_Tracker') ? SEOPC_Keyword_Tracker::normalize_keywords($keywords_raw) : array_filter(array_map('trim', preg_split('/[,\n]+/', $keywords_raw)));
        if (!empty($keywords)) {
            if ($overwrite || get_post_meta($post_id, '_yoast_wpseo_focuskw', true) === '') {
                update_post_meta($post_id, '_yoast_wpseo_focuskw', $keywords[0]);
                $applied_fields[] = __('Focus keyword', 'seo-performance-checker');
                $changed = true;
            }
            if ($overwrite || get_post_meta($post_id, '_yoast_wpseo_metakeywords', true) === '') {
                update_post_meta($post_id, '_yoast_wpseo_metakeywords', implode(', ', $keywords));
                $applied_fields[] = __('Meta keywords', 'seo-performance-checker');
                $changed = true;
            }
            if ($overwrite || get_post_meta($post_id, '_yoast_wpseo_focuskeywords', true) === '') {
                $focus_keywords = [];
                foreach ($keywords as $keyword) {
                    $focus_keywords[] = ['keyword' => $keyword, 'score' => 0];
                }
                update_post_meta($post_id, '_yoast_wpseo_focuskeywords', wp_json_encode($focus_keywords));
                $applied_fields[] = __('Additional focus keywords', 'seo-performance-checker');
                $changed = true;
            }
            if ($overwrite || empty(get_post_meta($post_id, '_seopc_target_keywords', true))) {
                update_post_meta($post_id, '_seopc_target_keywords', array_values($keywords));
                $applied_fields[] = __('Tracked keywords', 'seo-performance-checker');
                $changed = true;
            }
        }

        if ($changed && function_exists('wp_cache_delete')) {
            wp_cache_delete($post_id, 'post_meta');
        }

        return $changed;
    }

    private function find_matching_post($row) {
        if (!empty($row['id'])) {
            $id = absint($row['id']);
            if ($id && get_post($id)) {
                return $id;
            }
        }

        $post_type = !empty($row['post_type']) ? sanitize_key($row['post_type']) : '';
        $post_types = $post_type && post_type_exists($post_type) ? [$post_type] : array_keys($this->get_supported_post_types());
        $path = !empty($row['path']) ? $this->normalize_path($row['path']) : $this->path_from_url($row['url'] ?? '');

        if ($path !== '') {
            foreach ($post_types as $type) {
                $candidate = get_page_by_path(trim($path, '/'), OBJECT, $type);
                if ($candidate) {
                    return (int) $candidate->ID;
                }
            }
        }

        $slug = !empty($row['slug']) ? sanitize_title($row['slug']) : '';
        if ($slug !== '') {
            foreach ($post_types as $type) {
                $candidate = get_page_by_path($slug, OBJECT, $type);
                if ($candidate) {
                    return (int) $candidate->ID;
                }
            }
        }

        $title = !empty($row['title']) ? sanitize_text_field($row['title']) : '';
        if ($title !== '') {
            $matches = get_posts([
                'post_type' => $post_types,
                'post_status' => 'any',
                'title' => $title,
                'posts_per_page' => 1,
                'fields' => 'ids',
                'no_found_rows' => true,
            ]);
            if (!empty($matches)) {
                return (int) $matches[0];
            }
        }

        return 0;
    }

    private function get_supported_post_types() {
        $types = get_post_types(['public' => true], 'objects');
        $out = [];
        foreach ($types as $type => $object) {
            if ($type === 'attachment') {
                continue;
            }
            $out[$type] = $object->labels->singular_name ?: $type;
        }
        return $out;
    }

    private function sanitize_post_types($post_types) {
        $available = $this->get_supported_post_types();
        $post_types = array_map('sanitize_key', (array) $post_types);
        $post_types = array_values(array_intersect($post_types, array_keys($available)));
        return !empty($post_types) ? $post_types : ['post', 'page'];
    }

    private function get_meta_title($post_id) {
        $title = get_post_meta($post_id, '_yoast_wpseo_title', true);
        return $title !== '' ? $title : get_the_title($post_id);
    }

    private function get_meta_description($post_id) {
        $keys = ['_yoast_wpseo_metadesc', '_rank_math_description', '_aioseo_description'];
        foreach ($keys as $key) {
            $value = get_post_meta($post_id, $key, true);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function get_keywords($post_id) {
        $keywords = [];
        $yoast_kw = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
        if ($yoast_kw !== '') {
            $keywords[] = $yoast_kw;
        }
        $tracked = get_post_meta($post_id, '_seopc_target_keywords', true);
        if (is_array($tracked)) {
            $keywords = array_merge($keywords, $tracked);
        }
        return array_values(array_unique(array_filter(array_map('sanitize_text_field', $keywords))));
    }

    private function path_from_url($url) {
        $path = wp_parse_url($url, PHP_URL_PATH);
        return $this->normalize_path($path ?: '');
    }

    private function normalize_path($path) {
        $path = '/' . trim((string) $path, '/');
        return $path === '/' ? '' : $path;
    }

    private function normalize_header($header) {
        return sanitize_key(str_replace([' ', '-'], '_', strtolower((string) $header)));
    }

    private function build_report_row($row, $post_id, $status, $message, $applied_fields) {
        $matched_title = $post_id ? get_the_title($post_id) : '';
        $matched_url = $post_id ? get_permalink($post_id) : '';
        return [
            'source_title' => sanitize_text_field($row['title'] ?? ''),
            'source_url' => esc_url_raw($row['url'] ?? ''),
            'source_path' => sanitize_text_field($row['path'] ?? ''),
            'matched_id' => absint($post_id),
            'matched_title' => sanitize_text_field($matched_title),
            'matched_url' => esc_url_raw($matched_url),
            'status' => sanitize_key($status),
            'message' => sanitize_text_field($message),
            'fields' => array_values(array_unique(array_map('sanitize_text_field', (array) $applied_fields))),
        ];
    }

    private function store_result_report($report) {
        $report = array_slice((array) $report, 0, 500);
        $key = 'seopc_meta_report_' . wp_generate_password(12, false, false);
        set_transient($key, $report, HOUR_IN_SECONDS);
        return $key;
    }

    private function get_result_report() {
        $key = isset($_GET['seopc_report']) ? sanitize_key(wp_unslash($_GET['seopc_report'])) : '';
        if ($key === '') {
            return [];
        }
        $report = get_transient($key);
        if (!is_array($report)) {
            return [];
        }
        delete_transient($key);
        return $report;
    }

    private function render_result_report($report) {
        if (empty($report) || !is_array($report)) {
            return;
        }
        $total = count($report);
        ?>
        <div class="seopc-card">
            <h2><?php esc_html_e('Page import results', 'seo-performance-checker'); ?></h2>
            <p><?php echo esc_html(sprintf(__('Showing %d processed page(s).', 'seo-performance-checker'), $total)); ?></p>
            <div style="max-height:420px;overflow:auto;border:1px solid #ccd0d4;background:#fff;">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Status', 'seo-performance-checker'); ?></th>
                            <th><?php esc_html_e('Old page', 'seo-performance-checker'); ?></th>
                            <th><?php esc_html_e('Matched new page', 'seo-performance-checker'); ?></th>
                            <th><?php esc_html_e('Updated fields', 'seo-performance-checker'); ?></th>
                            <th><?php esc_html_e('Notes', 'seo-performance-checker'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report as $item) :
                            $status = $item['status'] ?? 'skipped';
                            $badge_color = $status === 'updated' ? '#008a20' : '#996800';
                            $old_label = !empty($item['source_title']) ? $item['source_title'] : (!empty($item['source_path']) ? $item['source_path'] : $item['source_url']);
                            $new_label = !empty($item['matched_title']) ? $item['matched_title'] : '';
                            ?>
                            <tr>
                                <td><strong style="color:<?php echo esc_attr($badge_color); ?>;"><?php echo esc_html(ucfirst($status)); ?></strong></td>
                                <td>
                                    <?php if (!empty($item['source_url'])) : ?>
                                        <a href="<?php echo esc_url($item['source_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($old_label); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html($old_label); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($item['matched_url'])) : ?>
                                        <a href="<?php echo esc_url($item['matched_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($new_label); ?></a>
                                        <br><small><?php echo esc_html(sprintf(__('ID %d', 'seo-performance-checker'), absint($item['matched_id'] ?? 0))); ?></small>
                                    <?php else : ?>
                                        <span aria-hidden="true">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo !empty($item['fields']) ? esc_html(implode(', ', (array) $item['fields'])) : esc_html__('None', 'seo-performance-checker'); ?></td>
                                <td><?php echo esc_html($item['message'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    private function redirect_with_result($updated, $skipped, $report = []) {
        $args = [
            'page' => 'seo-performance',
            'tab' => self::TAB_SLUG,
            'seopc_message' => 'imported',
            'seopc_count' => absint($updated),
            'seopc_skipped' => absint($skipped),
        ];
        if (!empty($report)) {
            $args['seopc_report'] = $this->store_result_report($report);
        }
        wp_safe_redirect(add_query_arg($args, admin_url('options-general.php')));
        exit;
    }

    private function redirect_with_error($message) {
        wp_safe_redirect(add_query_arg([
            'page' => 'seo-performance',
            'tab' => self::TAB_SLUG,
            'seopc_error' => rawurlencode($message),
        ], admin_url('options-general.php')));
        exit;
    }
}
