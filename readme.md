# SEO Performance Checker (Enhanced)

A WordPress plugin for SEO auditing, image diagnostics, schema checks, Google Analytics 4 and Search Console reporting, internal linking guidance, competitor benchmarking, and practical media cleanup tools inside WordPress admin.

## Version

**v1.10.0**

## What is new in v1.10.0

### Meta Import / Export for Yoast SEO migrations
A new **SEO Performance → Meta Import / Export** tab adds migration tools for moving SEO metadata from an old site into a new WordPress site.

Use it to import or export:
- SEO meta title
- meta description
- meta keywords / focus keywords

The tools update Yoast SEO-compatible fields where available, including:
- `_yoast_wpseo_title`
- `_yoast_wpseo_metadesc`
- `_yoast_wpseo_focuskw`
- `_yoast_wpseo_focuskeywords`
- `_yoast_wpseo_metakeywords`

The plugin also keeps its own tracked keyword field updated with imported or generated keyword data.

#### Import meta from an old WordPress site
Enter the old WordPress site URL and the plugin will:
- read public WordPress REST API data for posts and pages
- use Yoast REST metadata when available
- fall back to scraping each old page HTML for `<title>`, meta description, and meta keywords
- match old content to the new site by URL path, slug, and title
- update matched posts/pages on the new site

After the import runs, the admin screen shows a page-by-page results table with:
- old page URL/title
- matched new page
- status: updated or skipped
- fields updated
- skip reason, such as no matching page found or existing Yoast values already present

#### Export meta from any old website
Use **Export meta from any old website** to enter an old website URL and download a CSV for later import.

The exporter works with both WordPress and non-WordPress sites by trying:
- WordPress REST API data
- `/sitemap.xml`
- `/wp-sitemap.xml`
- internal homepage links as a fallback

For each discovered URL it exports:
- page title
- URL path
- meta title
- meta description
- meta keywords

Because this action downloads the CSV directly, it does not display an on-screen status table at the same time as the file download.

#### Import meta from CSV
Upload a CSV using the same format produced by the exporter. Supported columns include:
- `id`
- `post_type`
- `title`
- `slug`
- `url`
- `path`
- `meta_title`
- `meta_description`
- `keywords`

The importer matches rows to local WordPress content by path, slug, and title, then updates Yoast SEO metadata. After import, a results table shows which rows were updated or skipped.

#### Generate missing meta for this site
A separate **Generate missing meta for this site** tool can create basic SEO values from the current site's own post/page content when no import source is available.

It can generate:
- optional SEO title
- short meta description
- basic keyword list / focus keyword

Generation is safe by default: it fills missing values only. Use the overwrite option only when you intentionally want to replace existing Yoast SEO fields.

#### Safe overwrite controls
Old-site import, CSV import, and local generation all include overwrite controls. Leave overwrite disabled to preserve existing Yoast SEO values and only fill blanks.

## What is new in v1.9.0

### Dynamic Overrides for filter pages and landing URLs
A new **SEO Performance → Dynamic Overrides** section adds a repeater-style admin screen similar to ACF repeater workflows, but built directly into the plugin.

Use it to add rows for any page, post, or filter URL and override:
- meta title
- meta description
- meta keywords
- first H1 on the real front-end page

Features:
- add one or more rows with **Page or Post URL** matching
- supports exact paths like `/services/family-law/`
- supports filter URLs like `/cars/?make=bmw&location=london`
- supports `*` wildcards for broader matching
- supports dynamic tokens such as:
  - `{site_name}`
  - `{url}`
  - `{request_path}`
  - `{post_title}`
  - `{post_type}`
  - `{query:key}` for filter/query values
- outputs overrides on the live front-end so the plugin SEO checker can read the actual rendered result

This is especially useful for:
- faceted search pages
- filter/category landing pages
- dynamic archive URLs
- pages where you want a controlled H1 or meta set without editing templates manually

## What is new in v1.8.1

### Media Tools
A new **SEO Performance → Media Tools** section adds three practical workflows:

#### 1. Safe Media Optimizer
- optimize WordPress image attachments in very small batches
- resize originals down to a maximum width / height such as **1920px**
- fill safe missing attachment alt text
- regenerate attachment metadata after changes
- optional **AVIF conversion** when the server image editor supports it
- optional **replace original attachment with AVIF** after successful conversion
- optimize a **single media item by attachment ID**

#### 2. Template Image Checker
- scans **child theme** and **parent theme** template files
- reports raw `<img>` tags missing:
  - `alt`
  - `width`
  - `height`
- shows:
  - theme source (child or parent)
  - relative file path
  - line number
  - missing attributes
  - raw tag snippet

#### 3. Content Image DB Fixer
- scans raw `<img>` tags stored in `post_content`
- supports posts, pages, and products by default
- can write missing `alt`, `width`, and `height` back to the database
- links directly to Media Tools for any resolved attachment ID

### Better front-end image hardening
The plugin now improves front-end image output more reliably by:
- filling missing `width` and `height` on attachment image output
- filling safe fallback `alt` text for attachment images
- adding `loading="lazy"` to non-priority images where appropriate
- filtering raw content HTML image tags to add missing:
  - `alt`
  - `width`
  - `height`
  - `loading="lazy"` for non-logo attachment images

### Better real page heading checks
The main SEO analyzer now checks **rendered front-end HTML** first for headings and images. This improves false positives where the H1 or images come from:
- template parts
- theme layout files
- parent or child theme wrappers
- front-end output that is not visible in `post_content`

### SVG and AVIF improvements
- added better **SVG to AVIF** conversion handling for single-item optimization
- supports SVG raster conversion through **Imagick** when available on the server
- clearer failure messages when AVIF or SVG conversion is not supported by the hosting environment
- improved attachment replacement flow after AVIF conversion

### Better image attribute fixing
- empty `alt=""` is now treated as missing for non-decorative images in content and template scans
- SVG dimensions can now be detected from `width`, `height`, or `viewBox` values where possible
- content and theme image checks are more accurate for empty alt attributes

### Score explanation
- SEO analysis now includes a clearer **score breakdown** so it is easier to understand why a page shows values like **93/100** instead of 100
- the quick re-test output now shows a short explanation of the score calculation

## Core features

### SEO audits
- run on-demand SEO tests for posts, pages, and public post types
- save results for later review
- review score, issues, meta details, schema details, headings, links, and image diagnostics
- use real front-end page HTML for heading and image analysis where available

### Image analysis
- file size, dimensions, format, and image URL
- Media Library edit link for WordPress attachments
- direct **Media Tools** link when an attachment ID is detected
- missing alt text, short alt text, missing dimensions, missing lazy loading
- format recommendations for JPG / PNG images that could become WebP or AVIF
- total image weight per page

### Meta and schema analysis
- SEO meta score out of 100
- checks for title, description, canonical, robots, Open Graph, and Twitter tags
- Schema.org item discovery with property lists and parse issues
- direct link to validator.schema.org for the tested URL

## Google integrations

### Google Analytics 4
- OAuth connection flow from the plugin dashboard
- property selection
- KPI cards for users, sessions, views, and bounce rate
- trend charts with date-range controls
- top pages, device, country, and source / medium reports

### Google Search Console
- OAuth connection flow using the same Google account
- property selection
- KPI cards for clicks, impressions, CTR, and average position
- trend charts with date-range controls
- top queries, pages, device, and country reports

### Date filters and comparisons
- last 7, 28, 30, and 90 days
- month to date
- last month
- custom date range
- comparison against the previous equivalent period

## Features from earlier versions

### Keyword tracking
- add target keywords per post or page from the editor sidebar
- store multiple keywords per item
- pull Search Console data for tracked keywords
- dashboard table for keyword, page, clicks, impressions, and average position

### Content SEO scoring
Per-post score out of 100 based on:
- keyword in title
- keyword in heading
- keyword density
- keyword in meta description
- internal links count
- image alt coverage
- content length
- keyword in URL slug

### Editor checklist and suggestions
- content score badge inside the editor
- checklist of passed and missed SEO signals
- actionable suggestions such as improving headings, descriptions, internal links, and image alt text

### Landing page opportunity scoring
- highlights pages with strong impressions, weak CTR, and positions that are close to page-one gains
- helps prioritize the pages with the clearest growth opportunity

### Internal linking suggestions
- editor-side suggestions for related internal pages to link to
- proposed anchor text based on related page titles

### Per-post mini charts
- traffic trend chart inside the editor widget
- search trend chart inside the editor widget

### Competitor benchmarking
- add competitor URLs per post or page from the editor sidebar
- compare your page against competitor pages for title length, meta description length, word count, heading count, image count, and schema presence
- dashboard summary table for competitor gaps
- editor-side benchmark summary and action suggestions

### Automated title and meta rewrite suggestions
- suggested SEO title and meta description per tracked page
- recommendations use your current target keyword, content score, Search Console CTR context, and competitor gaps
- suggestions are shown in the post editor and in a dashboard queue

## Admin pages

### SEO Performance → Dashboard
Use the main dashboard to:
- run SEO analysis for a selected post or page
- review image issues, heading issues, link health, and speed results
- inspect saved historical results

### SEO Performance → Meta Analyzer
Use the meta analyzer to:
- test a post, page, or the homepage
- review title, description, Open Graph, Twitter, canonical, and robots coverage

### SEO Performance → Sitemap Manager
Use sitemap tools to:
- generate and review sitemaps
- check sitemap health
- detect orphaned pages

### SEO Performance → Media Tools
Use Media Tools to:
- scan parent and child theme templates for missing image attributes
- scan content database image HTML and apply safe fixes
- optimize by attachment ID
- create AVIF versions when supported by the server
- resize large images to a defined max size

### SEO Performance → Dynamic Overrides
Use Dynamic Overrides to:
- add repeater-style rules for specific URLs and filter pages
- override meta title, meta description, meta keywords, and the first H1
- use dynamic placeholders like `{query:location}` or `{site_name}`
- create SEO-friendly metadata for dynamic filter pages without editing theme files directly

### SEO Performance → Meta Import / Export
Use Meta Import / Export to:
- import SEO title, meta description, and keywords from an old WordPress site
- export metadata from any old website to a CSV for later import
- import metadata from a CSV into matched local posts/pages
- generate missing SEO title, description, and keywords from existing content
- update Yoast SEO metadata fields safely
- review page-by-page import results after old-site and CSV imports

Recommended migration flow:
1. On the new site, open **SEO Performance → Meta Import / Export**.
2. Use **Export meta from any old website** if you need a reusable CSV backup first.
3. Use **Import meta from an old WordPress site** for direct WordPress-to-WordPress migration.
4. Review the results table to confirm which pages were matched and updated.
5. Use **Generate missing meta for this site** to fill any remaining blanks.



## Post / page editor widgets

### Target Keywords box
Use the **SEO Performance: Target Keywords** box to add one or more keywords.

### Competitor URLs box
Use the **SEO Performance: Competitor URLs** box to add competing page URLs for lightweight benchmarking.

### Insights box
The **SEO Performance: Insights** box shows:
- content SEO score
- optimization checklist
- suggestions
- GA4 metrics
- mini trend charts
- internal link ideas
- suggested SEO title
- suggested meta description
- competitor benchmark gaps
- benchmark-driven improvement ideas

## Google API setup

Enable these APIs in Google Cloud:
- Google Analytics Data API
- Google Analytics Admin API

Then:
1. create OAuth credentials
2. add the redirect URI shown in the plugin dashboard
3. save client ID and client secret in plugin settings
4. connect Google account
5. select GA4 property

## Notes
- Meta Import / Export writes Yoast-compatible metadata fields but does not require Yoast to be active to store the post meta
- old-site imports and CSV imports show a temporary page-by-page status report after redirect
- old website CSV export downloads a file directly, so review the downloaded CSV to confirm exported URLs and metadata
- generated keywords and descriptions are basic content-derived suggestions and should be reviewed for important landing pages
- Dynamic Overrides outputs meta description and keywords in `wp_head` and filters the document title on matching URLs
- H1 override replaces the first rendered `<h1>` found in the final front-end HTML response for matching URLs
- if another SEO plugin prints its own description/keywords tags, review final HTML to avoid duplicate meta tags
- rendered page analysis is server-side HTML analysis; if a theme injects headings or images only after browser-side JavaScript runs, results may still be incomplete
- AVIF conversion depends on the WordPress image editor support available on your server
- external image sizes depend on remote server headers and may sometimes be unavailable
- content score is a practical optimization model, not a Google ranking guarantee
- competitor benchmarking is lightweight on-page comparison, not live rank scraping
- remote competitor pages may block requests or return incomplete HTML, which can limit benchmark accuracy

## Best use cases
- SEO audits for important landing pages
- fixing image alt and dimension issues in theme files and stored content
- resizing oversized media library assets safely in small batches
- converting selected attachments to AVIF where supported
- content refresh workflows
- editorial optimization inside WordPress
- prioritizing internal linking opportunities

- migrating SEO metadata from an old WordPress site to a new WordPress site
- exporting metadata from an old website into a reusable CSV
- filling missing Yoast SEO titles, descriptions, and focus keywords

## Recommended next upgrades
- optional WebP generation alongside AVIF
- image optimization queue with cron processing
- rendered page scan for `<picture>` and `srcset` reporting
- device and country filters directly on dashboard tables
- Core Web Vitals integration
- scheduled weekly SEO summary emails
