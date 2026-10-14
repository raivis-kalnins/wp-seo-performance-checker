<?php

if (!defined('ABSPATH')) {
    exit;
}

class SEOPC_Responsive_Browser_Test {
    public function render_admin_page() {
        $default_url = home_url('/');
        ?>
        <div class="wrap seopc-wrap seopc-responsive-browser-page">
            <h1><?php esc_html_e('Responsive & Browser Test', 'seo-performance-checker'); ?></h1>
            <?php SEOPC_Admin_Menu::render_tabs('responsive-browser'); ?>

            <div class="seopc-rbt-intro">
                <div>
                    <h2><?php esc_html_e('Responsive preview lab', 'seo-performance-checker'); ?></h2>
                    <p><?php esc_html_e('Preview any public URL or your local site at common phone, tablet, laptop and desktop sizes. Use the external browser tools below when you need a real Safari, Firefox, Edge or physical-device engine.', 'seo-performance-checker'); ?></p>
                </div>
                <span class="seopc-rbt-badge"><?php esc_html_e('No API key required', 'seo-performance-checker'); ?></span>
            </div>

            <div class="seopc-rbt-toolbar">
                <div class="seopc-rbt-url-field">
                    <label for="seopc-rbt-url"><?php esc_html_e('Website URL', 'seo-performance-checker'); ?></label>
                    <div class="seopc-rbt-url-row">
                        <input type="url" id="seopc-rbt-url" value="<?php echo esc_attr($default_url); ?>" placeholder="https://example.com/" spellcheck="false">
                        <button type="button" class="button button-primary" id="seopc-rbt-load"><?php esc_html_e('Load preview', 'seo-performance-checker'); ?></button>
                    </div>
                    <p class="description"><?php esc_html_e('Tip: localhost URLs work when this WordPress admin is running on the same computer. Some external sites block iframe previews; use Open page or an external browser tester in that case.', 'seo-performance-checker'); ?></p>
                </div>

                <div class="seopc-rbt-actions">
                    <button type="button" class="button" id="seopc-rbt-reload"><span class="dashicons dashicons-update"></span><?php esc_html_e('Reload', 'seo-performance-checker'); ?></button>
                    <button type="button" class="button" id="seopc-rbt-rotate"><span class="dashicons dashicons-image-rotate"></span><?php esc_html_e('Rotate', 'seo-performance-checker'); ?></button>
                    <button type="button" class="button" id="seopc-rbt-open"><span class="dashicons dashicons-external"></span><?php esc_html_e('Open page', 'seo-performance-checker'); ?></button>
                    <button type="button" class="button" id="seopc-rbt-compare"><span class="dashicons dashicons-screenoptions"></span><?php esc_html_e('Compare 3 devices', 'seo-performance-checker'); ?></button>
                    <button type="button" class="button button-primary" id="seopc-rbt-export-pdf"><span class="dashicons dashicons-pdf"></span><?php esc_html_e('Export PDF report', 'seo-performance-checker'); ?></button>
                </div>
            </div>

            <div class="seopc-rbt-layout">
                <aside class="seopc-rbt-sidebar">
                    <div class="seopc-rbt-panel">
                        <h3><?php esc_html_e('Device presets', 'seo-performance-checker'); ?></h3>
                        <div class="seopc-rbt-device-list" id="seopc-rbt-device-list">
                            <?php
                            $devices = [
                                ['iPhone SE', 375, 667, 'phone'],
                                ['iPhone 15', 393, 852, 'phone'],
                                ['iPhone 15 Pro Max', 430, 932, 'phone'],
                                ['Pixel 8', 412, 915, 'phone'],
                                ['iPad Mini', 768, 1024, 'tablet'],
                                ['iPad Air', 820, 1180, 'tablet'],
                                ['iPad Pro 11', 834, 1194, 'tablet'],
                                ['Laptop', 1366, 768, 'desktop'],
                                ['MacBook Air 13', 1440, 900, 'desktop'],
                                ['Desktop HD', 1920, 1080, 'desktop'],
                            ];
                            foreach ($devices as $index => $device) :
                                ?>
                                <button type="button" class="seopc-rbt-device<?php echo $device[0] === 'iPhone 15' ? ' is-active' : ''; ?>" data-name="<?php echo esc_attr($device[0]); ?>" data-width="<?php echo esc_attr($device[1]); ?>" data-height="<?php echo esc_attr($device[2]); ?>">
                                    <span class="dashicons <?php echo $device[3] === 'phone' ? 'dashicons-smartphone' : ($device[3] === 'tablet' ? 'dashicons-tablet' : 'dashicons-desktop'); ?>"></span>
                                    <span><strong><?php echo esc_html($device[0]); ?></strong><small><?php echo esc_html($device[1] . ' × ' . $device[2]); ?></small></span>
                                </button>
                                <?php
                            endforeach;
                            ?>
                        </div>
                    </div>

                    <div class="seopc-rbt-panel">
                        <h3><?php esc_html_e('Custom viewport', 'seo-performance-checker'); ?></h3>
                        <div class="seopc-rbt-custom-grid">
                            <label><?php esc_html_e('Width', 'seo-performance-checker'); ?><input type="number" id="seopc-rbt-width" min="240" max="3840" value="393"></label>
                            <label><?php esc_html_e('Height', 'seo-performance-checker'); ?><input type="number" id="seopc-rbt-height" min="320" max="2160" value="852"></label>
                        </div>
                        <button type="button" class="button" id="seopc-rbt-apply-size"><?php esc_html_e('Apply custom size', 'seo-performance-checker'); ?></button>
                    </div>

                    <div class="seopc-rbt-panel seopc-rbt-checklist">
                        <h3><?php esc_html_e('Visual QA checklist', 'seo-performance-checker'); ?></h3>
                        <label data-report-label="No horizontal overflow"><input type="checkbox"> <?php esc_html_e('No horizontal overflow', 'seo-performance-checker'); ?></label>
                        <label data-report-label="Header and menu fit correctly"><input type="checkbox"> <?php esc_html_e('Header and menu fit correctly', 'seo-performance-checker'); ?></label>
                        <label data-report-label="Logo and icons remain sharp"><input type="checkbox"> <?php esc_html_e('Logo and icons remain sharp', 'seo-performance-checker'); ?></label>
                        <label data-report-label="Hero artwork is not cropped incorrectly"><input type="checkbox"> <?php esc_html_e('Hero artwork is not cropped incorrectly', 'seo-performance-checker'); ?></label>
                        <label data-report-label="Buttons and forms remain usable"><input type="checkbox"> <?php esc_html_e('Buttons and forms remain usable', 'seo-performance-checker'); ?></label>
                        <label data-report-label="No overlapping fixed/sticky elements"><input type="checkbox"> <?php esc_html_e('No overlapping fixed/sticky elements', 'seo-performance-checker'); ?></label>
                    </div>
                </aside>

                <main class="seopc-rbt-stage-wrap">
                    <div class="seopc-rbt-stage-header">
                        <div>
                            <strong id="seopc-rbt-device-name">iPhone 15</strong>
                            <span id="seopc-rbt-dimensions">393 × 852</span>
                        </div>
                        <div class="seopc-rbt-scale-wrap">
                            <label for="seopc-rbt-scale"><?php esc_html_e('Scale', 'seo-performance-checker'); ?></label>
                            <select id="seopc-rbt-scale">
                                <option value="1">100%</option>
                                <option value="0.85">85%</option>
                                <option value="0.75" selected>75%</option>
                                <option value="0.6">60%</option>
                                <option value="0.5">50%</option>
                            </select>
                        </div>
                    </div>

                    <div class="seopc-rbt-stage" id="seopc-rbt-stage">
                        <div class="seopc-rbt-browser-shell" id="seopc-rbt-shell">
                            <div class="seopc-rbt-browser-bar">
                                <span class="seopc-rbt-dot"></span><span class="seopc-rbt-dot"></span><span class="seopc-rbt-dot"></span>
                                <span class="seopc-rbt-address" id="seopc-rbt-address"><?php echo esc_html($default_url); ?></span>
                            </div>
                            <iframe id="seopc-rbt-frame" src="<?php echo esc_url($default_url); ?>" title="<?php esc_attr_e('Responsive website preview', 'seo-performance-checker'); ?>" loading="eager" referrerpolicy="no-referrer-when-downgrade"></iframe>
                        </div>
                    </div>
                    <div class="seopc-rbt-frame-note" id="seopc-rbt-frame-note">
                        <span class="dashicons dashicons-info-outline"></span>
                        <?php esc_html_e('If this area stays blank or shows a browser refusal message, the tested website blocks embedding with X-Frame-Options or CSP. Open it in a new tab or use one of the browser services below.', 'seo-performance-checker'); ?>
                    </div>
                </main>
            </div>

            <section class="seopc-rbt-compare-section" id="seopc-rbt-compare-section" hidden>
                <div class="seopc-rbt-section-heading">
                    <div><h2><?php esc_html_e('Three-device comparison', 'seo-performance-checker'); ?></h2><p><?php esc_html_e('Quickly spot breakpoint, wrapping and artwork-position changes.', 'seo-performance-checker'); ?></p></div>
                    <button type="button" class="button" id="seopc-rbt-close-compare"><?php esc_html_e('Close comparison', 'seo-performance-checker'); ?></button>
                </div>
                <div class="seopc-rbt-compare-grid" id="seopc-rbt-compare-grid"></div>
            </section>

            <section class="seopc-rbt-services">
                <div class="seopc-rbt-section-heading">
                    <div>
                        <h2><?php esc_html_e('Real browser & device testing', 'seo-performance-checker'); ?></h2>
                        <p><?php esc_html_e('These services render the page outside WordPress and are useful when you need real browser engines or additional responsive screenshots.', 'seo-performance-checker'); ?></p>
                    </div>
                </div>
                <div class="seopc-rbt-service-grid">
                    <article class="seopc-rbt-service-card">
                        <h3>ResponsiveTool</h3>
                        <p><?php esc_html_e('Fast multi-device responsive view. The current URL can be passed directly to the tool.', 'seo-performance-checker'); ?></p>
                        <button type="button" class="button button-primary seopc-rbt-service" data-service="responsivetool"><?php esc_html_e('Test current URL', 'seo-performance-checker'); ?></button>
                    </article>
                    <article class="seopc-rbt-service-card">
                        <h3>Responsivly</h3>
                        <p><?php esc_html_e('Responsive testing and result views. Useful for a second external rendering check.', 'seo-performance-checker'); ?></p>
                        <button type="button" class="button seopc-rbt-service" data-service="responsivly"><?php esc_html_e('Open Responsivly', 'seo-performance-checker'); ?></button>
                    </article>
                    <article class="seopc-rbt-service-card">
                        <h3>WaveSpeed</h3>
                        <p><?php esc_html_e('Free responsive tester with many viewport presets.', 'seo-performance-checker'); ?></p>
                        <button type="button" class="button seopc-rbt-service" data-service="wavespeed"><?php esc_html_e('Open WaveSpeed', 'seo-performance-checker'); ?></button>
                    </article>
                    <article class="seopc-rbt-service-card">
                        <h3>EazyStudio</h3>
                        <p><?php esc_html_e('Device preview tool for checking layouts at common phone, tablet and desktop sizes.', 'seo-performance-checker'); ?></p>
                        <button type="button" class="button seopc-rbt-service" data-service="eazystudio"><?php esc_html_e('Open EazyStudio', 'seo-performance-checker'); ?></button>
                    </article>
                    <article class="seopc-rbt-service-card">
                        <h3>BrowserStack</h3>
                        <p><?php esc_html_e('Use when you need actual Chrome, Edge, Firefox, Safari or real-device sessions. Account/free-trial availability depends on BrowserStack.', 'seo-performance-checker'); ?></p>
                        <button type="button" class="button seopc-rbt-service" data-service="browserstack"><?php esc_html_e('Open BrowserStack', 'seo-performance-checker'); ?></button>
                    </article>
                </div>
                <p class="description seopc-rbt-disclaimer"><?php esc_html_e('WP SEO Performance Checker is not affiliated with these third-party services. Their availability, free limits and URL parameters can change.', 'seo-performance-checker'); ?></p>
            </section>
        </div>
        <?php
    }
}
