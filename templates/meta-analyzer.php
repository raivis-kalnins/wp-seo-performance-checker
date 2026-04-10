<div class="wrap seopc-wrap">
    <div class="seopc-page-header">
        <h1><?php _e('Meta Tag Analyzer', 'seo-performance-checker'); ?></h1>

        <div class="seopc-page-actions">
            <a href="<?php echo admin_url('admin.php?page=seo-performance'); ?>" class="button button-primary seopc-dashboard-button">
                <span class="dashicons dashicons-chart-line"></span>
                <?php _e('Dashboard', 'seo-performance-checker'); ?>
            </a>
            <a href="<?php echo admin_url(); ?>" class="button seopc-dashboard-button">
                <span class="dashicons dashicons-dashboard"></span>
                <?php _e('WordPress Dashboard', 'seo-performance-checker'); ?>
            </a>
        </div>
    </div>
    
    <div class="seopc-tabs">
        <button class="seopc-tab-button active" onclick="seopcShowTab('seopc-tab-single', this)"><?php _e('Single Page', 'seo-performance-checker'); ?></button>
        <button class="seopc-tab-button" onclick="seopcShowTab('seopc-tab-bulk', this)"><?php _e('Bulk Analysis', 'seo-performance-checker'); ?></button>
    </div>
    
    <!-- Single Page Tab -->
    <div id="seopc-tab-single" class="seopc-tab-content active">
        <div class="seopc-section">
            <h2><?php _e('Analyze Meta Tags', 'seo-performance-checker'); ?></h2>
            
            <div style="display: flex; gap: 15px; align-items: center; margin: 20px 0;">
                <select id="seopc-meta-post-select" style="min-width: 300px;">
                    <option value=""><?php _e('Select page/post...', 'seo-performance-checker'); ?></option>
                    <?php
                    $posts = get_posts(['posts_per_page' => 100, 'post_type' => 'any', 'post_status' => 'publish']);
                    foreach ($posts as $post):
                    ?>
                    <option value="<?php echo $post->ID; ?>"><?php echo esc_html($post->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
                
                <button id="seopc-analyze-meta-btn" class="button button-primary">
                    <?php _e('Analyze Meta Tags', 'seo-performance-checker'); ?>
                </button>
                
                <button id="seopc-analyze-home-btn" class="button">
                    <?php _e('Analyze Homepage', 'seo-performance-checker'); ?>
                </button>
            </div>
            
            <div id="seopc-meta-results" style="display: none; margin-top: 20px;">
                <!-- Results populated by JavaScript -->
            </div>
        </div>
        
        <!-- Previews -->
        <div class="seopc-section" id="seopc-previews-section" style="display: none;">
            <h2><?php _e('Previews', 'seo-performance-checker'); ?></h2>
            
            <div class="seopc-preview-grid">
                <div class="seopc-preview-card">
                    <h4><?php _e('Google Search', 'seo-performance-checker'); ?></h4>
                    <div class="seopc-serp-preview">
                        <div class="seopc-serp-title"></div>
                        <div class="seopc-serp-url"></div>
                        <div class="seopc-serp-description"></div>
                    </div>
                </div>
                
                <div class="seopc-preview-card">
                    <h4><?php _e('Facebook Share', 'seo-performance-checker'); ?></h4>
                    <div class="seopc-og-preview">
                        <div class="seopc-og-image"></div>
                        <div class="seopc-og-content">
                            <div class="seopc-og-site"></div>
                            <div class="seopc-og-title"></div>
                            <div class="seopc-og-desc"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bulk Analysis Tab -->
    <div id="seopc-tab-bulk" class="seopc-tab-content">
        <div class="seopc-section">
            <h2><?php _e('Bulk Meta Analysis', 'seo-performance-checker'); ?></h2>
            
            <div style="display: flex; gap: 15px; align-items: center; margin: 20px 0;">
                <select id="seopc-bulk-post-type">
                    <option value="all"><?php _e('All Content Types', 'seo-performance-checker'); ?></option>
                    <?php foreach (get_post_types(['public' => true], 'objects') as $pt): ?>
                    <option value="<?php echo $pt->name; ?>"><?php echo $pt->label; ?></option>
                    <?php endforeach; ?>
                </select>
                
                <select id="seopc-bulk-issue-type">
                    <option value="all"><?php _e('All Issues', 'seo-performance-checker'); ?></option>
                    <option value="missing_title"><?php _e('Missing Titles', 'seo-performance-checker'); ?></option>
                    <option value="long_title"><?php _e('Long Titles (>60)', 'seo-performance-checker'); ?></option>
                    <option value="missing_description"><?php _e('Missing Descriptions', 'seo-performance-checker'); ?></option>
                </select>
                
                <button id="seopc-bulk-analyze-btn" class="button button-primary">
                    <?php _e('Run Analysis', 'seo-performance-checker'); ?>
                </button>
            </div>
            
            <div id="seopc-bulk-results"></div>
        </div>
    </div>
</div>