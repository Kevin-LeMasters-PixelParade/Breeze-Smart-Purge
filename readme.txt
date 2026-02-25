=== Breeze Smart Purge ===
Contributors: pixelparade
Tags: cache, purge, breeze, cloudflare, performance
Requires at least: 6.0
Tested up to: 6.4
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Intelligently purges CPT Archives, Taxonomies, and Custom Page Builder Hubs via Breeze and Cloudflare.

== Description ==

By default, Breeze aggressively caches content. When you update a custom post type, it only clears the cache for that specific post. This leaves your important hub pages—like post grids, custom taxonomy archives, and page builder layouts—serving stale content to users.

**Breeze Smart Purge** acts as a traffic controller for your cache. The built-in Auto-Scanner detects which pages are querying specific Post Types, ensuring Breeze safely clears the cache for the parent pages and associated taxonomies whenever a post is updated.

### Key Features
* **Smart Auto-Scanner:** Automatically detects Gutenberg, Elementor, Bricks, and Beaver Builder post grids to map custom post types to their respective hub pages.
* **Manual Overrides:** Easily define custom URL paths that should be purged when a specific post type is updated.
* **Synchronous Cloudflare Purging:** Optionally bypass the default WP-Cron delay so cache purges happen instantly on "Update".
* **Automated Taxonomy Purging:** Automatically detects and purges associated taxonomy archive URLs when a post is modified.

== Installation ==

1. Upload the entire `breeze-smart-purge` folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen directly via a zip file.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to **Settings > Breeze Smart Purge** to run your first Smart Scan and configure your mappings.

== Frequently Asked Questions ==

= Does this replace the Breeze cache plugin? =
No, this is an add-on utility. You must have the Breeze plugin installed and active for this to work.

= Which page builders does the Auto-Scanner detect? =
Currently, the Auto-Scanner officially supports native Gutenberg blocks, Elementor, Bricks Builder, and Beaver Builder. If you use a different builder, you can map your URLs manually in the settings.

== Changelog ==

= 1.0.0 =
* Initial release.
* Security Patch: Added `wp_unslash()` to form submission handlers.
* Fix: Replaced standard `parse_url` with `wp_parse_url` for strict environment consistency.
* Fix: Added missing text domains for i18n translation compatibility.
* Enhancement: Added clean uninstall logic to prevent database bloat upon plugin deletion.