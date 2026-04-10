<div class="wrap seopc-wrap">
    <h1><?php _e('Sitemap Manager', 'seo-performance-checker'); ?></h1>
    
    <div class="seopc-status-dashboard">
        <div class="seopc-status-card">
            <span class="dashicons dashicons-media-document"></span>
            <h4><?php _e('Sitemap Status', 'seo-performance-checker'); ?></h4>
            <span class="seopc-status-indicator" id="seopc-sitemap-status">Unknown</span>
        </div>
        
        <div class="seopc-status-card">
            <span class="dashicons dashicons-admin-tools"></span>
            <h4><?php _e('robots.txt', 'seo-performance-checker'); ?></h4>
            <span class="seopc-status-indicator" id="seopc-robots-status">Unknown</span>
        </div>
        
        <div class="seopc-status-card">
            <span class="dashicons dashicons-admin-links"></span>
            <h4><?php _e('Indexed URLs', 'seo-performance-checker'); ?></h4>
            <span class="seopc-status-indicator" id="seopc-url-count">-</span>
        </div>
    </div>
    
    <div class="seopc-section">
        <h2><?php _e('Actions', 'seo-performance-checker'); ?></h2>
        
        <div class="seopc-button-group">
            <button id="seopc-check-sitemap-btn" class="button button-primary">
                <span class="dashicons dashicons-update"></span>
                <?php _e('Check Sitemap Health', 'seo-performance-checker'); ?>
            </button>
            
            <button id="seopc-find-orphaned-btn" class="button">
                <span class="dashicons dashicons-search"></span>
                <?php _e('Find Orphaned Pages', 'seo-performance-checker'); ?>
            </button>
            
            <a href="<?php echo home_url('/sitemap.xml'); ?>" target="_blank" class="button">
                <span class="dashicons dashicons-external"></span>
                <?php _e('View Sitemap', 'seo-performance-checker'); ?>
            </a>
        </div>
        
        <div id="seopc-sitemap-details"></div>
    </div>
    
    <div class="seopc-section">
        <h2><?php _e('Sitemap Configuration', 'seo-performance-checker'); ?></h2>
        
        <form method="post" action="options.php">
            <?php
            settings_fields('seopc_settings');
            $settings = get_option('seopc_settings', []);
            ?>
            
            <table class="form-table">
                <tr>
                    <th><?php _e('Include Post Types', 'seo-performance-checker'); ?></th>
                    <td>
                        <?php foreach (get_post_types(['public' => true], 'objects') as $pt): ?>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="seopc_settings[post_types][]" value="<?php echo $pt->name; ?>" 
                                <?php checked(in_array($pt->name, $settings['post_types'] ?? ['post', 'page'])); ?>>
                            <?php echo $pt->label; ?>
                        </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                
                <tr>
                    <th><?php _e('Include Taxonomies', 'seo-performance-checker'); ?></th>
                    <td>
                        <?php foreach (get_taxonomies(['public' => true], 'objects') as $tax): ?>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="seopc_settings[taxonomies][]" value="<?php echo $tax->name; ?>"
                                <?php checked(in_array($tax->name, $settings['taxonomies'] ?? ['category', 'post_tag'])); ?>>
                            <?php echo $tax->label; ?>
                        </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                
                <tr>
                    <th><?php _e('Advanced Options', 'seo-performance-checker'); ?></th>
                    <td>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="seopc_settings[include_lastmod]" value="1" 
                                <?php checked($settings['include_lastmod'] ?? true); ?>>
                            <?php _e('Include last modification dates', 'seo-performance-checker'); ?>
                        </label>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="seopc_settings[ping_search_engines]" value="1"
                                <?php checked($settings['ping_search_engines'] ?? true); ?>>
                            <?php _e('Ping Google & Bing on update', 'seo-performance-checker'); ?>
                        </label>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(__('Save Settings', 'seo-performance-checker')); ?>
        </form>
    </div>
</div>