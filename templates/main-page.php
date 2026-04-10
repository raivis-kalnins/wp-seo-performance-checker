<div class="wrap seopc-wrap">
    <h1><?php _e('SEO Performance Dashboard', 'seo-performance-checker'); ?></h1>
    
    <div class="seopc-section">
        <h2><?php _e('Quick Analysis', 'seo-performance-checker'); ?></h2>
        
        <div style="display: flex; gap: 15px; align-items: center; margin: 20px 0;">
            <select id="seopc-post-select" style="min-width: 300px;">
                <option value=""><?php _e('Select page/post...', 'seo-performance-checker'); ?></option>
                <?php
                $posts = get_posts([
                    'posts_per_page' => 100,
                    'post_type' => 'any',
                    'post_status' => 'publish'
                ]);
                foreach ($posts as $post):
                ?>
                <option value="<?php echo $post->ID; ?>">
                    <?php echo esc_html($post->post_title); ?> (<?php echo $post->post_type; ?>)
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
        <h2><?php _e('Site Overview', 'seo-performance-checker'); ?></h2>
        
        <?php
        $history = get_option('seopc_analysis_history', []);
        if (!empty($history)):
        ?>
        <table class="seopc-table">
            <thead>
                <tr>
                    <th><?php _e('Page', 'seo-performance-checker'); ?></th>
                    <th><?php _e('Score', 'seo-performance-checker'); ?></th>
                    <th><?php _e('Issues', 'seo-performance-checker'); ?></th>
                    <th><?php _e('Date', 'seo-performance-checker'); ?></th>
                    <th><?php _e('Action', 'seo-performance-checker'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($history, 0, 10) as $check): ?>
                <tr>
                    <td><?php echo esc_html($check['post_title']); ?></td>
                    <td>
                        <span class="seopc-score-badge <?php echo $check['overall_score'] >= 80 ? 'good' : ($check['overall_score'] >= 60 ? 'warning' : 'critical'); ?>">
                            <?php echo $check['overall_score']; ?>/100
                        </span>
                    </td>
                    <td><?php echo $check['critical_issues']; ?> critical</td>
                    <td><?php echo human_time_diff(strtotime($check['timestamp']), current_time('timestamp')); ?> ago</td>
                    <td>
                        <a href="<?php echo get_edit_post_link($check['post_id']); ?>" class="button button-small">
                            <?php _e('Edit', 'seo-performance-checker'); ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p><?php _e('No analyses yet. Run your first check above!', 'seo-performance-checker'); ?></p>
        <?php endif; ?>
    </div>
</div>