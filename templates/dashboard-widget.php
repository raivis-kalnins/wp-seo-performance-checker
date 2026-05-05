<?php
$recent_checks = SEOPC_Dashboard_Widget::get_recent_checks();
$site_score = SEOPC_Dashboard_Widget::get_site_health_score();
$critical_issues = SEOPC_Dashboard_Widget::get_critical_issues_count();
?>

<div class="seopc-widget-content">
    
    <?php if ($site_score !== null): ?>
    <div class="seopc-score-circle <?php echo $site_score >= 80 ? 'good' : ($site_score >= 60 ? 'warning' : 'critical'); ?>" style="width: 80px; height: 80px; margin-bottom: 15px;">
        <span class="seopc-score-number" style="font-size: 28px;"><?php echo $site_score; ?></span>
    </div>
    <?php endif; ?>
    
    <div class="seopc-widget-stats">
        <div class="seopc-widget-stat">
            <span class="seopc-widget-stat-value"><?php echo count($recent_checks); ?></span>
            <span class="seopc-widget-stat-label">Pages Checked</span>
        </div>
        <div class="seopc-widget-stat">
            <span class="seopc-widget-stat-value" style="color: <?php echo $critical_issues > 0 ? '#d63638' : '#00a32a'; ?>"><?php echo $critical_issues; ?></span>
            <span class="seopc-widget-stat-label">Critical Issues</span>
        </div>
        <div class="seopc-widget-stat">
            <span class="seopc-widget-stat-value"><?php echo round(array_sum(array_column($recent_checks, 'overall_score')) / max(count($recent_checks), 1)); ?></span>
            <span class="seopc-widget-stat-label">Avg Score</span>
        </div>
    </div>
    
    <?php if (!empty($recent_checks)): ?>
    <div class="seopc-widget-recent">
        <h4>Recent Checks</h4>
        <ul>
            <?php foreach (array_slice($recent_checks, 0, 5) as $check): ?>
            <li>
                <a href="<?php echo get_edit_post_link($check['post_id']); ?>">
                    <?php echo esc_html(mb_strimwidth($check['post_title'], 0, 30, '...')); ?>
                </a>
                <span class="seopc-score-badge <?php echo $check['overall_score'] >= 80 ? 'good' : ($check['overall_score'] >= 60 ? 'warning' : 'critical'); ?>">
                    <?php echo $check['overall_score']; ?>
                </span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    
    <p style="margin-top: 15px; text-align: center;">
        <a href="<?php echo admin_url('options-general.php?page=seo-performance'); ?>" class="button">
            Open SEO Dashboard
        </a>
    </p>
</div>