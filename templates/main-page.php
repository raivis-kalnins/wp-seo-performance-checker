<div class="wrap seopc-wrap">
    <?php if (isset($_GET['history_cleared']) && $_GET['history_cleared'] === '1'): ?>
    <div class="notice notice-success is-dismissible">
        <p><?php _e('Analysis history cleared successfully.', 'seo-performance-checker'); ?></p>
    </div>
    <?php endif; ?>

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
