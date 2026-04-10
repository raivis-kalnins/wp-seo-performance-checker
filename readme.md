# === SEO Performance Checker ===
Contributors: Raivis Kalnins
Tags: seo, technical seo, page speed, meta tags, sitemap, custom post types
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A practical WordPress admin toolkit for SEO checks, page speed reviews, link health validation, meta tag previews, sitemap monitoring, and saved result history for posts, pages, and custom post types.

== Description ==

SEO Performance Checker helps WordPress admins review on-page SEO and basic performance signals without leaving wp-admin. The plugin is designed for quick editorial checks, recurring content audits, and simple technical SEO reviews across posts, pages, and public custom post types.

Instead of showing a single raw score, the plugin stores detailed analysis results so you can return to them later from the main dashboard, the WordPress dashboard widget, and the editor sidebar for individual items.

= What the plugin does =

**SEO Performance Dashboard**
- Run quick analysis on posts, pages, and public custom post types
- Save item history with score, issue count, requests, and link metrics
- Re-open saved results in a modal without re-running the test
- Review broken links and external links for recently checked items
- Clear saved history when you want a fresh dashboard state

**Content analysis**
- Checks heading structure and missing or duplicate H1 usage
- Reviews image SEO such as missing alt text and missing dimensions
- Looks at semantic markup and basic accessibility signals
- Counts internal and external links inside content
- Validates a limited set of links to surface possible broken links

**Page speed review**
- Measures load time
- Estimates page size
- Counts requests like images, scripts, stylesheets, and iframes
- Highlights simple optimization opportunities

**Meta tag analyzer**
- Reviews title and meta description length
- Checks Open Graph coverage
- Checks Twitter Card coverage
- Shows search and social preview blocks

**Sitemap manager**
- Generates XML sitemap output
- Reviews sitemap availability and basic health
- Helps detect orphaned pages

**WordPress admin integrations**
- Adds an SEO Score column to post, page, and public CPT list tables
- Adds an SEO Results sidebar box on the edit screen
- Includes View Results and Test Again buttons on single items
- Adds a WordPress dashboard widget for quick monitoring

= Broken link and external link functionality =

The plugin now stores link-health information for analyzed items:
- **Broken links**: links that return an error or HTTP status 400+
- **External links**: links pointing away from your site domain
- **Internal links**: links pointing to your own site

For speed and reliability, broken-link validation checks a limited number of unique links from the content during each scan. Counts still reflect the content found in the item, while saved results show the latest checked samples.

= Best use cases =
- Editorial QA before publishing
- Periodic audits of landing pages and CPT entries
- Spot-checking link health after migrations or URL changes
- Reviewing saved SEO history from one central dashboard
- Quickly re-testing a single post from the editor sidebar

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/seo-performance-checker/`.
2. Activate the plugin in **Plugins**.
3. Open **SEO Performance** in wp-admin.
4. Run your first analysis from **SEO Performance Dashboard**.
5. Optionally review the WordPress dashboard widget and the right sidebar on post, page, or CPT edit screens.

== How to use ==

= Quick Analysis =
1. Go to **SEO Performance > Dashboard**.
2. Select any published post, page, or public custom post type.
3. Click **Analyze**.
4. Review the SEO score, image checks, heading structure, link health, and page speed summary.

= Saved item history =
- The **Site Overview** table stores recent results.
- Use **Results** to open the saved report again.
- Use **Edit** to jump back to the content item.
- Use **Clear History** to wipe saved dashboard history.

= Single item workflow =
- Open a post, page, or public CPT in the editor.
- Use the **SEO Results** box in the right sidebar.
- Click **View Results** to re-open the last saved report.
- Click **Test Again** to run a fresh check for that item.

= Link Health section =
- The main dashboard includes a **Broken & External Links by Item** section.
- This lets you compare recent items and quickly see which content needs link cleanup.

== Frequently Asked Questions ==

= Does the plugin change my content automatically? =
No. The plugin is read-only. It analyzes content and stores results, but it does not edit posts or meta data for you.

= Does it work with custom post types? =
Yes. Public custom post types are included in the dashboard selector, admin score columns, and editor sidebar box.

= Are all links checked every time? =
Not always. To keep scans practical inside wp-admin, broken-link validation checks a limited number of unique links in each saved run. The saved results still help you identify the most important issues quickly.

= Why does a score only appear after testing? =
The plugin uses saved analysis history. A score appears after that item has been analyzed at least once.

= Does it replace dedicated enterprise crawling tools? =
No. It is intended as an admin-side audit helper for everyday content checks inside WordPress.

== Changelog ==

= 1.1.0 =
* Added richer readme documentation
* Added broken and external link reporting to dashboard results
* Added a dashboard section for link-health review by item
* Added broken and external link counts into saved history and single-item sidebar
* Improved CPT handling for bulk analysis and dashboard selection

= 1.0.0 =
* Initial release
* SEO analysis engine
* Page speed testing
* Meta tag analyzer
* Sitemap generator
* Dashboard widget

== Plugin structure ==

seo-performance-checker/
├── wp-seo-performance-checker.php       Main plugin bootstrap
├── includes/
│   ├── class-plugin.php                 Asset loading and bootstrap wiring
│   ├── class-seo-analyzer.php           SEO, link, and content analysis
│   ├── class-speed-analyzer.php         Speed and request analysis
│   ├── class-meta-analyzer.php          Meta tag analysis
│   ├── class-sitemap-manager.php        Sitemap generation and checks
│   ├── class-dashboard-widget.php       WordPress dashboard widget
│   ├── class-admin-menu.php             Admin menu and history actions
│   ├── class-admin-columns.php          Score column and editor sidebar box
│   └── class-ajax-handler.php           AJAX endpoints for analysis and saved results
├── assets/
│   ├── css/admin.css                    Admin styles
│   └── js/admin.js                      Admin interactivity
├── templates/
│   ├── dashboard-widget.php             Dashboard widget layout
│   ├── main-page.php                    Main SEO dashboard
│   ├── meta-analyzer.php                Meta analyzer screen
│   └── sitemap-manager.php              Sitemap manager screen
└── readme.md                            Plugin documentation


## Redirect suggestions and CSV export

The dashboard now surfaces suggested redirects for broken internal links found during analysis.

- **Needed Redirects** section on the SEO Performance Dashboard
- **Redirect count** column in Site Overview
- **Export Redirects CSV** button for handing suggestions to developers or redirect plugins
- Suggestions include source item, broken URL, suggested target URL, confidence, and reason

Re-run an item with **Test Again** to populate redirect suggestions for older saved scans.
