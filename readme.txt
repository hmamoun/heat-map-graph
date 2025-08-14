=== Heat Map Graph ===
Contributors: exedotcom.ca
Tags: heatmap, charts, analytics, sql, shortcode
Requires at least: 6.0
Tested up to: 6.6
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Plugin URI: https://hayan.mamouns.xyz/heat-map-graph-plugin/
Author: Hayan Mamoun
Author URI: https://hayan.mamouns.xyz/

Create and display heat maps from custom SQL queries. Define row, column, and value fields, select color ranges, and render via shortcode.

== Description ==
Heat Map Graph lets administrators build data heat maps backed by SQL SELECT queries on WordPress tables. Configure:

- Query: a single SELECT statement targeting WP tables
- Field mapping: row, column, and value fields produced by the query
- Color range: hex colors for min and max
- Status: enable/disable

Use the shortcode on pages/posts:

`[heat_map_graph id="123"]`

Security features:
- Validates SQL is a single SELECT against WP tables only
- Blocks DML/DDL keywords
- No multiple statements
- Admin-only UI with nonces and strict sanitization

On activation, two sample heat maps are created:
- Posts per Day per Category (Last 30 Days)
- Number of Post Tags per Category

== Installation ==
1. Upload the plugin folder `heat-map-graph` to `/wp-content/plugins/`
2. Activate the plugin
3. Under Heat Map Graph in the admin menu, create a heat map or use samples
4. Place the shortcode `[heat_map_graph id="<ID>"]` where you want the heat map

== Frequently Asked Questions ==
= Which tables can I query? =
Only WordPress core tables, prefixed by your site’s `$wpdb->prefix`.

= Can I pass parameters? =
Use static queries or views; dynamic user input is not supported for security.

== Changelog ==
= 1.0.0 =
- Initial release

== Upgrade Notice ==
= 1.0.0 =
Initial release.

