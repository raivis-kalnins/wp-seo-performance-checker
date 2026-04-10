# === SEO Performance Checker ===
Contributors: Raivis Kalnins
Tags: seo, performance, sitemap, meta tags, optimization
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Comprehensive SEO analysis, page speed testing, meta tag validation, and sitemap management for WordPress.

== Description ==

SEO Performance Checker is a complete SEO toolkit for WordPress administrators. It provides detailed analysis of your content's SEO performance, page speed metrics, meta tag validation, and automated sitemap generation.

= Features =

**SEO Analysis**
- Image optimization checks (alt text, dimensions, lazy loading, WebP conversion)
- Heading structure validation (H1 uniqueness, hierarchy, skipped levels)
- HTML semantics verification (semantic tags, ARIA landmarks)
- Accessibility checks (skip links, focus indicators)
- Clickability analysis (touch targets, link visibility)
- Internal/external link counting

**Page Speed Analysis**
- Load time measurement
- Page size breakdown
- Resource counting (images, scripts, stylesheets)
- Server response checking
- GZIP compression detection
- Performance recommendations

**Meta Tag Analyzer**
- Title & description length validation
- Open Graph tag verification
- Twitter Cards support
- Canonical URL checking
- Robots meta analysis
- Google SERP preview
- Facebook/Twitter share previews

**Sitemap Manager**
- Automatic XML sitemap generation
- Sitemap index support
- Post type & taxonomy inclusion
- Orphaned page detection
- Health monitoring
- robots.txt integration checking

**Dashboard Widget**
- Site health overview
- Recent analysis history
- Quick stats display

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/seo-performance-checker/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Access the tools via the 'SEO Performance' menu in your admin panel
4. Sitemap is automatically available at yoursite.com/sitemap.xml

== Frequently Asked Questions ==

= Does this plugin modify my content? =

No, this plugin is read-only. It analyzes your content but never modifies it automatically.

= Will this slow down my site? =

No. All analysis runs in the admin area only. The public sitemap is cached and served efficiently.

= Can I customize the sitemap? =

Yes, go to SEO Performance > Sitemap Manager to configure which post types and taxonomies to include.

== Changelog ==

= 1.0.0 =
* Initial release
* SEO analysis engine
* Page speed testing
* Meta tag analyzer
* Sitemap generator
* Dashboard widget

seo-performance-checker/
├── seo-performance-checker.php          # Main plugin file
├── includes/
│   ├── class-plugin.php                 # Main plugin class
│   ├── class-seo-analyzer.php           # SEO analysis engine
│   ├── class-speed-analyzer.php         # Page speed analysis
│   ├── class-meta-analyzer.php          # Meta tags analysis
│   ├── class-sitemap-manager.php        # Sitemap generation & analysis
│   ├── class-dashboard-widget.php       # Admin dashboard widget
│   ├── class-admin-menu.php             # Admin menu registration
│   └── class-ajax-handler.php           # AJAX endpoints
├── assets/
│   ├── css/
│   │   └── admin.css                    # Admin styles
│   └── js/
│       └── admin.js                     # Admin scripts
├── templates/
│   ├── dashboard-widget.php             # Dashboard widget template
│   ├── main-page.php                    # Main analysis page
│   ├── meta-analyzer.php                # Meta tags tool page
│   └── sitemap-manager.php              # Sitemap tool page
└── readme.txt                           # Plugin documentation