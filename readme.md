# SEO Performance Checker (Enhanced)

A WordPress website-growth toolkit for SEO auditing, visual sitemap QA, social brand checks, design QA, free-media search, image/video editing and optimization, schema checks, reporting, internal linking guidance, competitor benchmarking, and agency workflows.

## Version

### 2.5.5
- Rebuilt the website-audit PDF layout for clean A4 alignment and client-ready visual hierarchy.
- Fixed the report title collision in the header and prevented continuation content from starting underneath the repeated page header.
- Added a structured audit hero, aligned score cards, two-column technical summary cards, status-based SEO check panels, clearer action callouts, image-issue rows, Lighthouse metric cards, and compact security-header rows.
- Improved wrapping for long domains, URLs, issue text, and Lighthouse labels using width-aware line breaking instead of fixed character counts.
- Added safer section/page-break rules so headings stay with their content and long audit sections continue cleanly across pages.
- Refined report footer alignment and consistent page numbering.

### 2.5.4
- Fixed false multiple-H1 reports by counting only literal visible-body `<h1>` elements for SEO.
- ARIA `role="heading" aria-level="1"` elements are now tracked separately instead of being added to the H1 total.
- Headings inside `template`, `noscript`, `svg`, `script`, and `style` support markup no longer inflate heading counts.
- Bumped the website-audit cache namespace so previously cached incorrect H1 totals are not reused.

### 2.5.3
- Fixed the front-end lock regression from v2.5.2: fresh activations could still store the legacy `SEO@password` hash even though the documented password was `Seo@test`.
- Added an automatic migration and login self-repair so `Seo@test` unlocks affected installations without requiring a database/settings reset.
- New activations now seed `Seo@test` correctly.

### 2.5.2
- Enabled front-end password protection and migrated the agency password to `Seo@test`.
- Added no-key WordPress.org Photo Directory search/download alongside Openverse/Wikimedia/Iconify.
- Replaced the audit PDF CDN dependency with an internal dependency-free vector PDF writer.
- Added detailed per-image SEO issue rows and CSV export.


**v2.5.5**



## What is new in v2.5.1

- Free Media now uses AJAX **Load more** instead of page navigation. Connected providers are requested in parallel, results append in place, and faster/lower-resolution previews are used first when providers expose them.
- Expanded the free-media directory with Picjumbo, Kaboompics, Gratisography, Nappy, Foodiesfeed, ISO Republic, Life of Pix, Burst, StockSnap, Reshot, Mixkit, Coverr, SVG Repo, unDraw, Freepik and Rawpixel discovery links.
- Added a direct settings shortcut when Pexels/Pixabay/Unsplash are not connected, so agency users can enable higher-quality API-backed photography.
- Sitemap discovery now avoids probing redundant common sitemap roots after a working root is found, uses shorter bounded retries, and turns cURL timeout noise into a clear skipped-source warning while continuing the crawl.
- Increased sitemap and social-audit typography, spacing and card readability.
- Design QA now includes **Reload preview**, **Refresh snapshot**, and **Open live page** controls.
- Image Studio gained rotate-90, fit-to-canvas, grayscale, sepia and hue controls plus larger buttons and clearer adjustment panels.
- Image Optimizer now reports original size, optimized size, KB/MB saved and percentage saved, and defaults away from AVIF when the browser cannot encode it.
- Replaced CSS-triangle select arrows with a fixed SVG chevron to stop dropdown arrows shifting at different zoom/font metrics.

- Replaced the screenshot-based website-audit PDF exporter with a **direct vector jsPDF report engine** so client PDFs contain the audit data instead of an empty captured page. The PDF now draws branded scorecards, score bars, page/SEO details, Lighthouse metrics, opportunities, diagnostics, category findings, security headers, and page numbers directly into A4 pages.
- Added richer **web-report visual analytics** for issue severity, resource mix and heading structure alongside the existing Lighthouse/Core Web Vitals dashboard.
- Deepened **Sitemap Intelligence** with resilient sitemap retries, longer timeouts, up to 1,000 URLs, sitemap response-health data, URL-family distribution, freshness buckets, scan progress, better CSV columns and page-level issue cards. Partial sitemap failures no longer stop alternate sitemap discovery.
- Deepened **Social / Brand Footprint** checks with eight-network coverage, profile-handle extraction, public profile metadata checks, per-profile quality where verification is possible, blocked-platform handling, schema/sameAs consistency and clearer profile/share recommendations.
- Reworked **Design QA preview** to use a server-fetched, script-disabled sandbox snapshot instead of a cross-origin iframe. This avoids the broken grey preview caused by X-Frame-Options/CSP while keeping automated responsive/layout checks and the screenshot pixel ruler.
- Expanded **Image Studio** with grouped layer controls, flip/centre/reset tools, brightness, contrast, saturation and blur, improved crop controls, transparent/solid background export, clipboard PNG and cleaner responsive panel styling.
- Improved **Free Media** ranking so higher-resolution Pexels, Pixabay and Unsplash results are not buried behind lower-quality sources when their API keys are configured. Added searchable Iconify design assets and a larger integrated source directory including Picjumbo, Burst, StockSnap, Reshot, Mixkit, Coverr, SVG Repo, unDraw and Freepik search links.
- Replaced the letter/Unicode brand and active-tab marks with a consistent inline-SVG icon system and refined green/purple active states.
- Preserved the v2.4 Lighthouse coverage for **Performance, Accessibility, Best Practices, SEO and Agentic Browsing**. Agentic category details automatically surface WebMCP and related Lighthouse findings when returned by the current PageSpeed endpoint.

## What is new in v2.4.0

- Rebuilt the **website audit report** into a client-ready web dashboard with a branded report cover, overall snapshot, score gauges, richer score bars, Lighthouse metric cards, opportunities, diagnostics and per-category audit panels.
- Added a direct **Download PDF** action using an A4-optimized report layout with page-safe cards, consistent typography, report footer and page numbering. The existing browser Print option remains available.
- Expanded PageSpeed/Lighthouse coverage to request **Performance, Accessibility, Best Practices, SEO and the new Agentic Browsing category**. The Agentic request gracefully falls back when Google has not enabled the category on a particular PageSpeed API node.
- Added detailed Lighthouse category audit data so the report can show the actual failed/partial checks behind each category rather than scores alone.
- Added lab metric presentation plus available Chrome UX Report field metrics for LCP, INP, CLS, FCP and TTFB.
- Added richer performance **Opportunities** and **Diagnostics** sections for both the on-screen audit and exported PDF.

## What is new in v2.3.0

- Rebuilt **Sitemap** as an interactive URL relationship map with expandable parent/child boxes, per-page scan buttons, inline SEO/HTTP issue details, sitemap-file chips, 100/250/500 URL map sizes and CSV export.
- Expanded **Social** into a brand-footprint audit: linked profile discovery, Organization `sameAs` comparison, richer share-preview checks, automated profile URL reachability checks where networks permit them, and clearer recommendations when a platform blocks bot verification.
- Reworked **Design QA** so it tests `X-Frame-Options`/CSP before rendering a preview instead of showing a broken iframe. It now adds automated responsive-layout risk checks, image-dimension/layout-shift checks, form-labelling checks, fixed-width/tiny-text diagnostics and a more accurate screenshot pixel ruler.
- Rebuilt **Image Studio** around layers. Add images and text, reorder/duplicate/hide layers, drag selected layers, control opacity/scale/rotation/blend modes, remove simple flat backgrounds with tolerance, crop output and export PNG/JPG/WebP/AVIF at selectable quality/size.
- Merged the old resource-library concept into **Free Media**. Connected Openverse/Wikimedia/Pexels/Pixabay/Unsplash providers can be filtered and searched in-tool, with download/optimize/edit actions; popular direct-search sources such as Picjumbo, Burst, StockSnap, Reshot, Mixkit, Coverr and SVG Repo are shown in the same workspace when no suitable public API is available.
- Added provider-aware media AJAX caching/filtering and surfaced which API providers are actually configured.

## What is new in v2.2.1

- Reworked all toolkit text, URL, search, number and select controls with rounded 14–15px corners, green focus rings, cleaner placeholders and stronger theme isolation so WordPress/theme styles cannot make inputs look plain.
- Added a custom green range slider with a filled track, polished thumb and live value pill for image quality and crop controls.
- Added an optional **Fresh / skip cache** AJAX switch to Website Audit, DNS & Server, Sitemap and Social checks. When enabled, the server bypasses temporary transients and runs a new check without reloading the page.
- AJAX requests now explicitly use no-store semantics and the `X-Requested-With` header.
- URL/search fields now use better browser input modes and autocomplete/spellcheck behaviour.

## What is new in v2.2.0

### Agency growth workspace redesign

The public toolkit now uses a cleaner green / deep-ink visual system, denser but friendlier navigation, polished input controls, responsive cards, chart-style score summaries, and print-ready client reporting.

### More reliable H1 detection

H1 checks now combine DOM headings, ARIA level-one headings, and a raw-markup fallback. Pages that appear client-rendered or protected by an anti-bot interstitial are marked as **unverified** instead of incorrectly reporting a confirmed missing H1.

### Visual sitemap and multi-page QA

A new **Sitemap** tab discovers robots.txt sitemap declarations and common WordPress sitemap paths, displays sitemap/index structure visually, lists discovered URLs, and can quick-audit up to 25 pages with SEO score, HTTP status, and issue counts. Sitemap responses use temporary cache and can be exported to CSV.

### Social media and share readiness

A new **Social** tab checks Open Graph and X/Twitter cards, detects social profiles linked by the website, produces a share-readiness score, and gives practical recommendations for improving branded link previews and profile connections.

### Reports and charts

Website audits now include a lightweight score chart plus CSV and JSON export. The **PDF / Print report** action switches to a print-friendly agency report layout that browsers can save as PDF.

### Design QA and media workflow

New tools include:
- responsive website iframe preview at common breakpoints
- screenshot pixel measurement with an optional 8px spacing grid
- lightweight image studio for crop, rotate, flip and simple background transparency
- batch JPG / PNG / WebP / AVIF browser conversion
- DOCX to PDF browser conversion (document is not uploaded to WordPress)
- expanded free-media resource directory for photos, video, SVG and illustration sources

### Larger agency file limits

The media proxy limit can now be configured up to 500 MB. Browser-local image/video processing is not constrained by the WordPress upload limit. HTML audit fetch limits default to 6 MB.

## What is new in v2.1.3

### Google-style SEO score colours

Audit score cards now use familiar traffic-light thresholds: **green for 90–100**, **amber for 50–89**, and **red for 0–49**. Each score has a circular progress gauge plus a text label, so the result does not rely on colour alone.

### SEO health-check indicators

The website audit now shows individual green, amber, or red cards for H1 usage, title length, meta-description length, canonical URL, image alt attributes, heading hierarchy, indexability, and HTTP response. H1 is red unless the page contains exactly one H1. Heading-count boxes also highlight H1 and H2 status directly.

## What is new in v2.1.2

### Twenty Twenty-Five full-screen layout fix

The full-screen shortcode layout now stays at `left: 0` and uses normal 100% widths. It no longer depends on `left: 50%`, negative viewport margins, or `calc(50% - 50vw)`. Global block-theme padding and constrained wrappers are removed only on toolkit pages, including Twenty Twenty-Five.

The toolkit shell uses a viewport-height flex layout, so the privacy footer remains at the bottom of the screen when a tab has little or no result content.

### Cleaner, wider search controls

URL, domain, and free-media search forms now give the primary text field more width. Inputs and buttons use a smaller 8px radius, consistent borders, clearer focus states, and full-width mobile stacking.

## What is new in v2.1.0

### Public by default, optional password protection

The front-end toolkit is public by default. Administrators can enable password protection from **Settings → SEO Checker → Front-end Toolkit**. The current password remains visible and editable on that administrator screen.

### Full-width and full-height application mode

The shortcode can fill the browser width and at least the viewport height. A separate setting can hide the theme header and footer only on pages containing the toolkit shortcode.

### Front-end shortcode toolkit

Add either shortcode to a WordPress page:

```text
[seopc_toolkit]
[seo_toolkit]
```

The interface uses WordPress' bundled React package (`wp-element`) and Ajax endpoints, so no separate React CDN or front-end build step is required.

### Main capabilities

- live SEO, heading, resource, security-header, and PageSpeed auditing
- DNS, hosting, HTTP, TCP, TLS, and server-information checks
- client-side image conversion and optimization
- client-side WebM video compression
- openly licensed image and video search through enabled providers
- short transient caching without adding downloaded media to the WordPress Media Library

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
