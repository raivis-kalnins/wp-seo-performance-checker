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
            var scoreClass = data.seo.overall_score >= 80 ? 'good' : (data.seo.overall_score >= 60 ? 'warning' : 'critical');
            var linkData = data.seo.internal_links || {};

            html += '<div class="seopc-score-circle ' + scoreClass + '">';
            html += '<span class="seopc-score-number">' + data.seo.overall_score + '</span>';
            html += '<span class="seopc-score-label">/100</span>';
            html += '</div>';

            html += '<div class="seopc-analysis-grid">';

            html += '<div class="seopc-analysis-category">';
            html += '<h4>🖼️ Images (' + data.seo.images.total + ')</h4>';
            if (data.seo.images.issues.length > 0) {
                data.seo.images.issues.forEach(function(issue) {
                    html += '<div class="seopc-issue-card ' + issue.severity + '">';
                    html += '<div class="seopc-issue-header">';
                    html += '<span class="seopc-severity-badge ' + issue.severity + '">' + issue.severity + '</span>';
                    html += '<strong>' + escapeHtml(issue.image) + '</strong>';
                    html += '</div>';
                    html += '<p>' + escapeHtml((issue.type || '').replace(/_/g, ' ')) + '</p>';
                    if (issue.fix) {
                        html += '<div class="seopc-fix-box"><strong>Fix:</strong> ' + escapeHtml(issue.fix) + '</div>';
                    }
                    html += '</div>';
                });
            } else {
                html += '<p class="seopc-tag-status present"><span class="dashicons dashicons-yes"></span> All images optimized</p>';
            }
            html += '</div>';

            html += '<div class="seopc-analysis-category">';
            html += '<h4>📑 Headings (Score: ' + data.seo.headings.score + ')</h4>';
            if (data.seo.headings.structure_errors.length > 0) {
                data.seo.headings.structure_errors.forEach(function(err) {
                    html += '<div class="seopc-issue-card critical">';
                    html += '<strong>' + escapeHtml((err.type || '').replace(/_/g, ' ')) + '</strong>';
                    if (err.text) html += '<p>"' + escapeHtml(err.text.substring(0, 50)) + '..."</p>';
                    html += '<div class="seopc-fix-box">' + escapeHtml(err.fix || '') + '</div>';
                    html += '</div>';
                });
            }

            html += '<div class="seopc-heading-map">';
            data.seo.headings.headings.forEach(function(h) {
                html += '<div class="seopc-heading-item level-' + h.level + '">';
                html += '<span class="seopc-h-tag">H' + h.level + '</span>';
                html += '<span>' + escapeHtml(h.text) + '</span>';
                html += '</div>';
            });
            html += '</div>';
            html += '</div>';

            html += '<div class="seopc-analysis-category">';
            html += '<h4>🔗 Link Health</h4>';
            html += '<div class="seopc-link-metric-grid">';
            html += '<div class="seopc-link-metric"><strong>' + parseInt(linkData.total || 0, 10) + '</strong><span>Total</span></div>';
            html += '<div class="seopc-link-metric"><strong>' + parseInt(linkData.internal || 0, 10) + '</strong><span>Internal</span></div>';
            html += '<div class="seopc-link-metric"><strong>' + parseInt(linkData.external || 0, 10) + '</strong><span>External</span></div>';
            html += '<div class="seopc-link-metric"><strong>' + parseInt(linkData.broken_count || 0, 10) + '</strong><span>Broken</span></div>';
            html += '</div>';
            if (linkData.scan_limited) {
                html += '<p class="seopc-muted">Broken-link validation checked the first ' + parseInt(linkData.check_limit || 0, 10) + ' unique links for speed.</p>';
            }
            html += renderLinkPanel('Broken links', linkData.broken_urls, true);
            html += renderLinkPanel('External links', linkData.external_urls, false);
            html += '</div>';

            html += '</div>';

            html += '<div class="seopc-section">';
            html += '<h3>⚡ Page Speed</h3>';
            html += '<p>Load Time: <strong>' + escapeHtml(data.speed.load_time.seconds + 's') + '</strong> (' + escapeHtml(data.speed.load_time.status) + ')</p>';
            html += '<p>Page Size: <strong>' + escapeHtml(String(data.speed.page_size.total_kb)) + ' KB</strong></p>';
            html += '<p>Requests: <strong>' + getTotalRequests(data.speed) + '</strong></p>';
            html += '<p>Score: <strong>' + parseInt(data.speed.score || 0, 10) + '/100</strong></p>';

            if (data.speed.recommendations.length > 0) {
                html += '<h4>Recommendations:</h4><ul>';
                data.speed.recommendations.forEach(function(rec) {
                    html += '<li><strong>' + escapeHtml(rec.issue) + ':</strong> ' + escapeHtml(rec.suggestion) + '</li>';
                });
                html += '</ul>';
            }
            html += '</div>';

            html += '</div>';
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

        function escapeHtml(value) {
            return $('<div>').text(value || '').html();
        }

        function getTotalRequests(speed) {
            if (!speed || !speed.resource_count) {
                return 0;
            }
            return (parseInt(speed.resource_count.images || 0, 10)
                + parseInt(speed.resource_count.scripts || 0, 10)
                + parseInt(speed.resource_count.stylesheets || 0, 10)
                + parseInt(speed.resource_count.iframes || 0, 10));
        }

        function renderLinkPanel(title, items, showStatus) {
            var html = '<div class="seopc-link-panel">';
            html += '<h5>' + escapeHtml(title) + '</h5>';
            if (!items || !items.length) {
                html += '<p class="seopc-muted">None found in the latest saved scan.</p>';
                html += '</div>';
                return html;
            }

            html += '<ul class="seopc-link-list">';
            items.slice(0, 5).forEach(function(item) {
                html += '<li>';
                html += '<a href="' + escapeHtml(item.url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(item.anchor || item.url) + '</a>';
                if (showStatus) {
                    html += '<small>' + escapeHtml(item.status_code ? ('HTTP ' + item.status_code) : (item.message || 'Error')) + '</small>';
                } else {
                    html += '<small>' + escapeHtml(item.url) + '</small>';
                }
                html += '</li>';
            });
            html += '</ul>';
            html += '</div>';
            return html;
        }

        function renderSavedResults(entry) {
            var score = parseInt(entry.overall_score || 0, 10);
            var scoreClass = score >= 80 ? 'good' : (score >= 60 ? 'warning' : 'critical');
            var requests = entry.requests_count ? parseInt(entry.requests_count, 10) : null;
            var linkData = entry.internal_links || {};
            var html = '<div class="seopc-analysis-results seopc-analysis-results-compact">';
            html += '<div class="seopc-score-circle ' + scoreClass + '"><span class="seopc-score-number">' + score + '</span><span class="seopc-score-label">/100</span></div>';
            html += '<div class="seopc-section">';
            html += '<p><strong>Item:</strong> ' + escapeHtml(entry.post_title || '') + '</p>';
            if (entry.slug) {
                html += '<p><strong>Slug:</strong> <code>/' + escapeHtml(String(entry.slug).replace(/^\/+|\/+$/g, '')) + '/</code></p>';
            }
            if (entry.post_type) {
                html += '<p><strong>Type:</strong> ' + escapeHtml(entry.post_type) + '</p>';
            }
            html += '<p><strong>Critical issues:</strong> ' + parseInt(entry.critical_issues || 0, 10) + '</p>';
            if (requests !== null && !isNaN(requests)) {
                html += '<p><strong>Requests:</strong> ' + requests + '</p>';
            }
            html += '<p><strong>Broken links:</strong> ' + parseInt(linkData.broken_count || 0, 10) + '</p>';
            html += '<p><strong>External links:</strong> ' + parseInt(linkData.external || 0, 10) + '</p>';
            if (entry.timestamp) {
                html += '<p><strong>Checked:</strong> ' + escapeHtml(entry.timestamp) + '</p>';
            }
            if (entry.images && entry.images.issues && entry.images.issues.length) {
                html += '<h4>Image issues</h4><ul>';
                entry.images.issues.slice(0, 5).forEach(function(issue) {
                    html += '<li>' + escapeHtml((issue.type || '').replace(/_/g, ' ')) + (issue.fix ? ' - ' + escapeHtml(issue.fix) : '') + '</li>';
                });
                html += '</ul>';
            }
            if (entry.headings && entry.headings.structure_errors && entry.headings.structure_errors.length) {
                html += '<h4>Heading issues</h4><ul>';
                entry.headings.structure_errors.slice(0, 5).forEach(function(issue) {
                    html += '<li>' + escapeHtml((issue.type || '').replace(/_/g, ' ')) + (issue.fix ? ' - ' + escapeHtml(issue.fix) : '') + '</li>';
                });
                html += '</ul>';
            }
            html += renderLinkPanel('Broken links', linkData.broken_urls, true);
            html += renderLinkPanel('External links', linkData.external_urls, false);
            if (linkData.redirect_suggestions && linkData.redirect_suggestions.length) {
                html += '<div class="seopc-link-panel"><h5>Needed redirects</h5><ul class="seopc-link-list">';
                linkData.redirect_suggestions.slice(0, 8).forEach(function(item) {
                    html += '<li>';
                    html += '<strong>' + escapeHtml(item.from_path || item.from_url) + '</strong>';
                    if (item.to_url) {
                        html += '<small>→ <a href="' + escapeHtml(item.to_url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(item.to_title || item.to_url) + '</a> (' + escapeHtml(item.confidence_label || 'low') + ')</small>';
                    } else {
                        html += '<small>No target suggestion yet</small>';
                    }
                    html += '</li>';
                });
                html += '</ul></div>';
            }
            html += '</div></div>';
            return html;
        }

        function openResultsModal(html) {
            var $modal = $('#seopc-results-modal');
            if (!$modal.length) {
                alert('Results modal not found');
                return;
            }
            $modal.find('.seopc-modal-body').html(html);
            $modal.show();
        }

        function fetchSavedResults(postId, callback) {
            $.ajax({
                url: seopc_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'seopc_get_saved_result',
                    post_id: postId,
                    nonce: seopc_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        callback(null, response.data);
                    } else {
                        callback(response.data || 'No saved results found');
                    }
                },
                error: function() {
                    callback('Server error occurred');
                }
            });
        }

        $(document).on('click', '.seopc-view-results-btn', function() {
            var postId = $(this).data('post-id');
            fetchSavedResults(postId, function(err, entry) {
                if (err) {
                    alert(err);
                    return;
                }
                openResultsModal(renderSavedResults(entry));
            });
        });

        $(document).on('click', '.seopc-modal-close, .seopc-modal-backdrop', function() {
            $('#seopc-results-modal').hide();
        });


        function getSelectedHistoryPostIds() {
            var ids = [];
            $('.seopc-row-checkbox:checked').each(function() {
                ids.push(parseInt($(this).val(), 10));
            });
            return ids.filter(function(id) { return !isNaN(id) && id > 0; });
        }

        function updateBulkRetestButton() {
            var count = getSelectedHistoryPostIds().length;
            $('#seopc-bulk-retest-btn').prop('disabled', count === 0);
        }

        function applyHistoryFilters() {
            var postType = $('#seopc-filter-post-type').val() || 'all';
            var scoreBand = $('#seopc-filter-score').val() || 'all';
            var brokenFilter = $('#seopc-filter-broken').val() || 'all';
            var searchTerm = ($('#seopc-filter-search').val() || '').toLowerCase().trim();
            var visibleCount = 0;

            $('.seopc-history-row').each(function() {
                var $row = $(this);
                var matches = true;
                var rowPostType = String($row.data('post-type') || '');
                var rowScoreBand = String($row.data('score-band') || '');
                var rowBroken = parseInt($row.data('broken') || 0, 10);
                var rowSearch = String($row.data('search') || '');

                if (postType !== 'all' && rowPostType !== postType) {
                    matches = false;
                }
                if (matches && scoreBand !== 'all' && rowScoreBand !== scoreBand) {
                    matches = false;
                }
                if (matches && brokenFilter === 'has-broken' && !(rowBroken > 0)) {
                    matches = false;
                }
                if (matches && brokenFilter === 'no-broken' && rowBroken > 0) {
                    matches = false;
                }
                if (matches && searchTerm && rowSearch.indexOf(searchTerm) === -1) {
                    matches = false;
                }

                $row.toggle(matches);
                if (matches) {
                    visibleCount++;
                } else {
                    $row.find('.seopc-row-checkbox').prop('checked', false);
                }
            });

            $('#seopc-filter-summary').text(visibleCount + (visibleCount === 1 ? ' item shown' : ' items shown'));
            $('#seopc-select-all').prop('checked', false);
            updateBulkRetestButton();
        }

        function runBulkRetest(postIds, $button, done) {
            var $status = $('#seopc-bulk-retest-status');
            $button.prop('disabled', true).text(postIds.length > 1 ? 'Re-testing...' : 'Testing...');
            $status.removeClass('notice-success notice-error').addClass('notice notice-info').show().html('<p>Running SEO analysis for ' + postIds.length + ' item' + (postIds.length === 1 ? '' : 's') + '...</p>');

            $.ajax({
                url: seopc_ajax.ajax_url,
                type: 'POST',
                traditional: true,
                data: {
                    action: 'seopc_bulk_retest',
                    post_ids: postIds,
                    nonce: seopc_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var processed = parseInt(response.data.processed || 0, 10);
                        $status.removeClass('notice-info notice-error').addClass('notice-success').html('<p>Re-tested ' + processed + ' item' + (processed === 1 ? '' : 's') + '. Refreshing the page will show the newest saved dashboard values.</p>');
                        if (typeof done === 'function') {
                            done(null, response.data);
                        }
                    } else {
                        $status.removeClass('notice-info notice-success').addClass('notice-error').html('<p>' + escapeHtml(response.data || 'Bulk re-test failed') + '</p>');
                        if (typeof done === 'function') {
                            done(response.data || 'Bulk re-test failed');
                        }
                    }
                },
                error: function() {
                    $status.removeClass('notice-info notice-success').addClass('notice-error').html('<p>Server error occurred while re-testing.</p>');
                    if (typeof done === 'function') {
                        done('Server error occurred while re-testing.');
                    }
                },
                complete: function() {
                    $button.prop('disabled', false).text($button.data('default-label') || 'Bulk Re-Test Selected');
                    updateBulkRetestButton();
                }
            });
        }

        $('#seopc-filter-post-type, #seopc-filter-score, #seopc-filter-broken').on('change', applyHistoryFilters);
        $('#seopc-filter-search').on('input', applyHistoryFilters);

        $('#seopc-select-all').on('change', function() {
            var checked = $(this).is(':checked');
            $('.seopc-history-row:visible .seopc-row-checkbox').prop('checked', checked);
            updateBulkRetestButton();
        });

        $(document).on('change', '.seopc-row-checkbox', updateBulkRetestButton);

        $('#seopc-bulk-retest-btn').each(function() {
            $(this).data('default-label', $(this).text());
        }).on('click', function() {
            var postIds = getSelectedHistoryPostIds();
            if (!postIds.length) {
                alert('Select at least one item first.');
                return;
            }
            runBulkRetest(postIds, $(this));
        });

        $(document).on('click', '.seopc-run-row-retest', function() {
            var postId = parseInt($(this).data('post-id'), 10);
            if (!postId) {
                return;
            }
            var $button = $(this);
            $button.data('default-label', $button.text());
            runBulkRetest([postId], $button, function(err, data) {
                if (!err && data && data.results && data.results.length) {
                    fetchSavedResults(postId, function(fetchErr, entry) {
                        if (!fetchErr) {
                            openResultsModal(renderSavedResults(entry));
                        }
                    });
                }
            });
        });

        applyHistoryFilters();

        $(document).on('click', '.seopc-test-again-btn', function() {
            var postId = $(this).data('post-id');
            var $btn = $(this);
            var $box = $btn.closest('.seopc-single-box');
            var $output = $box.find('.seopc-single-results-output');

            $btn.prop('disabled', true).text('Testing...');
            $output.html('<p>Running analysis...</p>');

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
                        var data = response.data;
                        $output.html(
                            '<p><strong>Score:</strong> ' + parseInt(data.seo.overall_score || 0, 10) + '/100</p>' +
                            '<p><strong>Critical issues:</strong> ' + parseInt(data.seo.critical_issues || 0, 10) + '</p>' +
                            '<p><strong>Requests:</strong> ' + getTotalRequests(data.speed) + '</p>' +
                            '<p><strong>Load time:</strong> ' + escapeHtml(data.speed.load_time.seconds + 's') + '</p>' +
                            '<p><button type="button" class="button button-secondary seopc-view-results-btn" data-post-id="' + postId + '">View Results</button></p>'
                        );
                    } else {
                        $output.html('<p>' + escapeHtml(response.data || 'Analysis failed') + '</p>');
                    }
                },
                error: function() {
                    $output.html('<p>Server error occurred</p>');
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Test Again');
                }
            });
        });
    });
    
})(jQuery);