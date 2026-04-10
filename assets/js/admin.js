(function($) {
    'use strict';
    
    // Tab switching
    window.seopcShowTab = function(tabId, btn) {
        $('.seopc-tab-content').removeClass('active');
        $('.seopc-tab-button').removeClass('active');
        
        $('#' + tabId).addClass('active');
        $(btn).addClass('active');
    };
    
    $(document).ready(function() {
        
        // Analyze single post
        $('#seopc-analyze-btn').on('click', function() {
            var postId = $('#seopc-post-select').val();
            if (!postId) {
                alert('Please select a post to analyze');
                return;
            }
            
            runAnalysis(postId);
        });
        
        function runAnalysis(postId) {
            var $btn = $('#seopc-analyze-btn');
            var $results = $('#seopc-results');
            
            $btn.prop('disabled', true).text('Analyzing...');
            $results.addClass('seopc-loading');
            
            $.ajax({
                url: seopc_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'seopc_analyze_post',
                    post_id: postId,
                    nonce: seopc_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        displayResults(response.data);
                    } else {
                        alert('Analysis failed: ' + response.data);
                    }
                },
                error: function() {
                    alert('Server error occurred');
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Analyze');
                    $results.removeClass('seopc-loading');
                }
            });
        }
        
        function displayResults(data) {
            var html = '<div class="seopc-analysis-results">';
            
            // Overall Score
            var scoreClass = data.seo.overall_score >= 80 ? 'good' : (data.seo.overall_score >= 60 ? 'warning' : 'critical');
            html += '<div class="seopc-score-circle ' + scoreClass + '">';
            html += '<span class="seopc-score-number">' + data.seo.overall_score + '</span>';
            html += '<span class="seopc-score-label">/100</span>';
            html += '</div>';
            
            // SEO Analysis
            html += '<div class="seopc-analysis-grid">';
            
            // Images
            html += '<div class="seopc-analysis-category">';
            html += '<h4>🖼️ Images (' + data.seo.images.total + ')</h4>';
            if (data.seo.images.issues.length > 0) {
                data.seo.images.issues.forEach(function(issue) {
                    html += '<div class="seopc-issue-card ' + issue.severity + '">';
                    html += '<div class="seopc-issue-header">';
                    html += '<span class="seopc-severity-badge ' + issue.severity + '">' + issue.severity + '</span>';
                    html += '<strong>' + issue.image + '</strong>';
                    html += '</div>';
                    html += '<p>' + issue.type.replace(/_/g, ' ') + '</p>';
                    if (issue.fix) {
                        html += '<div class="seopc-fix-box"><strong>Fix:</strong> ' + issue.fix + '</div>';
                    }
                    html += '</div>';
                });
            } else {
                html += '<p class="seopc-tag-status present"><span class="dashicons dashicons-yes"></span> All images optimized</p>';
            }
            html += '</div>';
            
            // Headings
            html += '<div class="seopc-analysis-category">';
            html += '<h4>📑 Headings (Score: ' + data.seo.headings.score + ')</h4>';
            if (data.seo.headings.structure_errors.length > 0) {
                data.seo.headings.structure_errors.forEach(function(err) {
                    html += '<div class="seopc-issue-card critical">';
                    html += '<strong>' + err.type.replace(/_/g, ' ') + '</strong>';
                    if (err.text) html += '<p>"' + err.text.substring(0, 50) + '..."</p>';
                    html += '<div class="seopc-fix-box">' + err.fix + '</div>';
                    html += '</div>';
                });
            }
            
            // Heading map
            html += '<div class="seopc-heading-map">';
            data.seo.headings.headings.forEach(function(h) {
                html += '<div class="seopc-heading-item level-' + h.level + '">';
                html += '<span class="seopc-h-tag">H' + h.level + '</span>';
                html += '<span>' + h.text + '</span>';
                html += '</div>';
            });
            html += '</div>';
            html += '</div>';
            
            html += '</div>'; // Close grid
            
            // Speed Results
            html += '<div class="seopc-section">';
            html += '<h3>⚡ Page Speed</h3>';
            html += '<p>Load Time: <strong>' + data.speed.load_time.seconds + 's</strong> (' + data.speed.load_time.status + ')</p>';
            html += '<p>Page Size: <strong>' + data.speed.page_size.total_kb + ' KB</strong></p>';
            html += '<p>Score: <strong>' + data.speed.score + '/100</strong></p>';
            
            if (data.speed.recommendations.length > 0) {
                html += '<h4>Recommendations:</h4><ul>';
                data.speed.recommendations.forEach(function(rec) {
                    html += '<li><strong>' + rec.issue + ':</strong> ' + rec.suggestion + '</li>';
                });
                html += '</ul>';
            }
            html += '</div>';
            
            html += '</div>'; // Close results
            
            $('#seopc-results').html(html).show();
        }
        
        // Meta Analyzer
        $('#seopc-analyze-meta-btn').on('click', function() {
            var postId = $('#seopc-meta-post-select').val();
            runMetaAnalysis(postId || null);
        });
        
        $('#seopc-analyze-home-btn').on('click', function() {
            runMetaAnalysis(null);
        });
        
        function runMetaAnalysis(postId) {
            var $btn = $('#seopc-analyze-meta-btn');
            $btn.prop('disabled', true).text('Analyzing...');
            
            $.ajax({
                url: seopc_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'seopc_analyze_meta',
                    post_id: postId,
                    nonce: seopc_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        displayMetaResults(response.data);
                    }
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Analyze Meta Tags');
                }
            });
        }
        
        function displayMetaResults(data) {
            var html = '<div class="seopc-analysis-grid">';
            
            // Basic Meta
            html += '<div class="seopc-analysis-category">';
            html += '<h4>Basic Meta</h4>';
            
            // Title
            var titleStatus = data.basic_meta.title.status;
            html += '<div class="seopc-tag-status ' + titleStatus + '">';
            html += '<span class="dashicons dashicons-' + (titleStatus === 'good' ? 'yes' : 'warning') + '"></span>';
            html += '<div><strong>Title:</strong> ' + data.basic_meta.title.content.substring(0, 60);
            html += '<br><small>' + data.basic_meta.title.length + ' chars</small></div>';
            html += '</div>';
            
            // Description
            var descStatus = data.basic_meta.description.status;
            html += '<div class="seopc-tag-status ' + descStatus + '">';
            html += '<span class="dashicons dashicons-' + (descStatus === 'good' ? 'yes' : (descStatus === 'critical' ? 'no' : 'warning')) + '"></span>';
            html += '<div><strong>Description:</strong> ' + (data.basic_meta.description.content ? data.basic_meta.description.content.substring(0, 80) + '...' : 'MISSING');
            html += '<br><small>' + data.basic_meta.description.length + ' chars</small></div>';
            html += '</div>';
            
            html += '</div>';
            
            // Open Graph
            html += '<div class="seopc-analysis-category">';
            html += '<h4>Open Graph</h4>';
            if (data.open_graph.complete) {
                html += '<div class="seopc-tag-status present"><span class="dashicons dashicons-yes"></span> All tags present</div>';
            } else {
                html += '<div class="seopc-tag-status warning"><span class="dashicons dashicons-warning"></span> Missing: ' + data.open_graph.missing.join(', ') + '</div>';
            }
            html += '</div>';
            
            // Twitter Cards
            html += '<div class="seopc-analysis-category">';
            html += '<h4>Twitter Cards</h4>';
            if (data.twitter_cards.card_type !== 'none') {
                html += '<div class="seopc-tag-status present"><span class="dashicons dashicons-yes"></span> Card type: ' + data.twitter_cards.card_type + '</div>';
            } else {
                html += '<div class="seopc-tag-status missing"><span class="dashicons dashicons-no"></span> No Twitter Card tags</div>';
            }
            html += '</div>';
            
            html += '</div>';
            
            $('#seopc-meta-results').html(html).show();
            
            // Update previews
            updatePreviews(data);
        }
        
        function updatePreviews(data) {
            // Google preview
            var title = data.basic_meta.title.content;
            var url = window.location.origin;
            var desc = data.basic_meta.description.content || 'No description provided...';
            
            $('.seopc-serp-title').text(title.substring(0, 60) + (title.length > 60 ? '...' : ''));
            $('.seopc-serp-url').text(url);
            $('.seopc-serp-description').text(desc.substring(0, 160));
            
            // Facebook preview
            if (data.open_graph.present['og:image']) {
                $('.seopc-og-image').css('background-image', 'url(' + data.open_graph.present['og:image'] + ')');
            }
            $('.seopc-og-site').text(data.open_graph.present['og:site_name'] || window.location.hostname);
            $('.seopc-og-title').text(data.open_graph.present['og:title'] || title);
            $('.seopc-og-desc').text(data.open_graph.present['og:description'] || desc);
        }
        
        // Sitemap Health Check
        $('#seopc-check-sitemap-btn').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text('Checking...');
            
            $.ajax({
                url: seopc_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'seopc_check_sitemap',
                    nonce: seopc_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        displaySitemapHealth(response.data);
                    }
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Check Sitemap Health');
                }
            });
        });
        
        function displaySitemapHealth(data) {
            var html = '<div class="seopc-section">';
            
            if (data.exists) {
                html += '<div class="seopc-status-indicator good">✓ Sitemap exists</div>';
                html += '<p>Type: ' + data.type + '</p>';
                html += '<p>Valid XML: ' + (data.valid ? 'Yes' : 'No') + '</p>';
                
                if (data.stats.urls) {
                    html += '<p>URLs: ' + data.stats.urls + '</p>';
                }
                
                if (!data.robots_has_sitemap) {
                    html += '<div class="seopc-issue-card warning">';
                    html += '<strong>Warning:</strong> Sitemap not referenced in robots.txt';
                    html += '<div class="seopc-fix-box">Add: Sitemap: ' + window.location.origin + '/sitemap.xml</div>';
                    html += '</div>';
                }
            } else {
                html += '<div class="seopc-status-indicator error">✗ Sitemap not found</div>';
            }
            
            html += '</div>';
            $('#seopc-sitemap-details').html(html);
        }
        
        // Find orphaned pages
        $('#seopc-find-orphaned-btn').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text('Scanning...');
            
            $.ajax({
                url: seopc_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'seopc_find_orphaned',
                    nonce: seopc_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        var html = '<div class="seopc-section">';
                        html += '<h3>Orphaned Pages</h3>';
                        html += '<p>Total pages: ' + data.total + '</p>';
                        html += '<p>Orphaned: ' + data.orphaned_count + ' (' + Math.round((data.orphaned_count / data.total) * 100) + '%)</p>';
                        
                        if (data.orphaned_ids.length > 0) {
                            html += '<h4>Sample orphaned pages:</h4><ul>';
                            data.orphaned_ids.forEach(function(id) {
                                html += '<li>Post ID: ' + id + ' <a href="' + seopc_ajax.ajax_url.replace('admin-ajax.php', 'post.php?post=' + id + '&action=edit') + '" target="_blank">Edit</a></li>';
                            });
                            html += '</ul>';
                        }
                        
                        html += '</div>';
                        $('#seopc-sitemap-details').html(html);
                    }
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Find Orphaned Pages');
                }
            });
        });
        
        // Bulk Analysis
        $('#seopc-bulk-analyze-btn').on('click', function() {
            var postType = $('#seopc-bulk-post-type').val();
            var issueType = $('#seopc-bulk-issue-type').val();
            
            var $btn = $(this);
            $btn.prop('disabled', true).text('Analyzing...');
            
            $.ajax({
                url: seopc_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'seopc_bulk_analysis',
                    post_type: postType,
                    issue_type: issueType,
                    nonce: seopc_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        displayBulkResults(response.data);
                    }
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Run Analysis');
                }
            });
        });
        
        function displayBulkResults(data) {
            var html = '<table class="seopc-table wp-list-table widefat fixed striped">';
            html += '<thead><tr><th>Page</th><th>Title Length</th><th>Description</th><th>OG Image</th><th>Issues</th></tr></thead>';
            html += '<tbody>';
            
            data.forEach(function(item) {
                html += '<tr>';
                html += '<td><a href="post.php?post=' + item.id + '&action=edit" target="_blank">' + item.title + '</a></td>';
                html += '<td>' + item.title_length + '</td>';
                html += '<td>' + (item.has_description ? '✓' : '✗') + '</td>';
                html += '<td>' + (item.has_og_image ? '✓' : '✗') + '</td>';
                html += '<td>' + (item.issues.length > 0 ? item.issues.join(', ') : 'None') + '</td>';
                html += '</tr>';
            });
            
            html += '</tbody></table>';
            $('#seopc-bulk-results').html(html);
        }
        
    });
    
})(jQuery);