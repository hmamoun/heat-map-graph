<?php
/**
 * Plugin Name: Heat Map Graph
 * Plugin URI: https://hayan.mamouns.xyz/heat-map-graph-plugin/
 * Description: Transform your WordPress data into stunning visualizations! Create interactive heat maps, bar charts, pie charts, and line graphs from SQL queries or external APIs. Free version includes heat maps with SQL data. Premium unlocks multiple chart types, external data sources, interactive filters, CSV export, and linked dashboards.
 * Version: 2.0.0
 * Author: Hayan Mamoun
 * Contributors: hmamoun, exedotcom.ca
 * Tested up to: 6.9
 * Author URI: https://hayan.mamouns.xyz/ 
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) {
	exit;
}

// Load premium features class
require_once plugin_dir_path(__FILE__) . 'includes/class-premium-features.php';

// Load Freemius integration (if available)
if (file_exists(plugin_dir_path(__FILE__) . 'includes/class-freemius-integration.php')) {
	require_once plugin_dir_path(__FILE__) . 'includes/class-freemius-integration.php';
}

if (!class_exists('EXAIG_Heat_Map_Graph')) {
	class EXAIG_Heat_Map_Graph {
		const VERSION = '2.0.0';
		const OPTION_DB_VERSION = 'exaig_heatmap_graph_db_version';

		/** @var string */
		private $table_name;

		/** @var EXAIG_Heat_Map_Graph_Premium_Features */
		private $premium;

		public function __construct() {
			global $wpdb;
			$this->table_name = $wpdb->prefix . 'heatmap_graphs';
			$this->premium = new EXAIG_Heat_Map_Graph_Premium_Features();

			register_activation_hook(__FILE__, [$this, 'on_activate']);
			add_action('admin_menu', [$this, 'register_admin_menu']);
			add_action('admin_init', [$this, 'handle_admin_actions']);
			add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
			add_action('wp_ajax_exaig_validate_sql', [$this, 'ajax_validate_sql']);
			add_action('wp_ajax_exaig_preview_graph', [$this, 'ajax_preview_graph']);
			add_action('wp_ajax_exaig_test_external_connection', [$this, 'ajax_test_external_connection']);
			add_shortcode('heat_map_graph', [$this, 'shortcode_handler']);

			// Load premium feature classes (always load for upgrade notices)
			$this->load_premium_features();
		}

		/**
		 * Load premium feature classes.
		 */
		private function load_premium_features() {
			$premium_classes = [
				'class-chart-renderer.php',
				'class-export-handler.php',
				'class-slicer-handler.php',
				'class-chart-linking.php',
				'class-external-data.php',
			];

			foreach ($premium_classes as $class_file) {
				$file_path = plugin_dir_path(__FILE__) . 'includes/' . $class_file;
				if (file_exists($file_path)) {
					require_once $file_path;
				}
			}
		}

		/**
		 * Allowed HTML tags/attrs for rendering heatmap HTML safely through wp_kses.
		 */
		private function get_allowed_heatmap_html_tags() {
			return [
				'div' => [
					'class' => [],
					'style' => [],
					'role' => [],
					'aria-label' => [],
					'data-chart-type' => [],
					'data-chart-id' => [],
					'data-heatmap-id' => [],
					'id' => [],
				],
				'canvas' => [
					'id' => [],
					'width' => [],
					'height' => [],
				],
				'script' => [],
				'table' => [
					'class' => [],
					'role' => [],
					'aria-label' => [],
				],
				'thead' => [ 'class' => [] ],
				'tbody' => [ 'class' => [] ],
				'tr' => [ 'class' => [] ],
				'th' => [ 'class' => [] ],
				'td' => [ 'class' => [], 'title' => [], 'style' => [] ],
				'span' => [ 'class' => [], 'id' => [] ],
				'code' => [ 'class' => [] ],
				'select' => [
					'id' => [],
					'class' => [],
					'name' => [],
					'multiple' => [],
					'size' => [],
				],
				'option' => [
					'value' => [],
					'selected' => [],
				],
				'button' => [
					'type' => [],
					'id' => [],
					'class' => [],
					'data-heatmap-id' => [],
					'data-export-url' => [],
					'style' => [],
					'aria-expanded' => [],
					'aria-controls' => [],
				],
				'input' => [
					'type' => [],
					'id' => [],
					'class' => [],
					'name' => [],
					'value' => [],
					'min' => [],
					'max' => [],
					'step' => [],
					'data-heatmap-id' => [],
					'data-export-url' => [],
				],
				'label' => [
					'for' => [],
					'class' => [],
				],
				'h4' => [
					'class' => [],
				],
				'p' => [
					'class' => [],
				],
			];
		}

	public function on_activate() {
		$this->maybe_create_table();
		// Default graphs are only created via "Restore Default Graphs" button
	}

		private function maybe_create_table() {
			global $wpdb;
			$current_version = get_option(self::OPTION_DB_VERSION);
			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE {$this->table_name} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(191) NOT NULL,
				description TEXT NULL,
				sql_query LONGTEXT NOT NULL,
				row_field VARCHAR(191) NOT NULL,
				col_field VARCHAR(191) NOT NULL,
				value_field VARCHAR(191) NOT NULL,
				color_min VARCHAR(7) NOT NULL DEFAULT '#f0f9e8',
				color_max VARCHAR(7) NOT NULL DEFAULT '#084081',
				is_enabled TINYINT(1) NOT NULL DEFAULT 1,
				chart_type VARCHAR(50) DEFAULT 'heatmap',
				export_enabled TINYINT(1) DEFAULT 0,
				slicers_config TEXT NULL,
				is_interactive TINYINT(1) DEFAULT 0,
				linked_group VARCHAR(100) NULL,
				data_source_type VARCHAR(50) DEFAULT 'sql',
				external_config TEXT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY is_enabled (is_enabled)
			) $charset_collate;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta($sql);
			
			// Add new columns if table already exists (for upgrades)
			// Table names cannot be prepared - they are validated identifiers
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name} LIKE 'chart_type'");
			if (empty($column_exists)) {
				// Table names cannot be prepared - they are validated identifiers
				// Schema changes during plugin activation/upgrade are acceptable
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN chart_type VARCHAR(50) DEFAULT 'heatmap'");
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN export_enabled TINYINT(1) DEFAULT 0");
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN slicers_config TEXT NULL");
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN is_interactive TINYINT(1) DEFAULT 0");
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN linked_group VARCHAR(100) NULL");
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN data_source_type VARCHAR(50) DEFAULT 'sql'");
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN external_config TEXT NULL");
			}
			
			update_option(self::OPTION_DB_VERSION, self::VERSION);
		}

	private function maybe_seed_samples($force = false, $update_existing = false) {
		global $wpdb;
		
		if (!$force) {
			// Don't seed automatically - only when forced via restore button
			return;
		}
		
		// When forced, always seed all samples
		$add_api_examples_only = false;
		$prefix = $wpdb->prefix;

		$samples = [
			[
				'name' => 'Posts Published Per Month (Bar Chart)',
				'description' => 'Visualizes your WordPress publishing activity over time. This bar chart displays the number of posts published each month, making it easy to identify publishing trends, seasonal patterns, and content production cycles. Perfect for content managers and site administrators who want to track editorial activity and plan future content strategies. The chart automatically groups posts by month and counts published posts, providing a clear view of your content calendar performance.',
				'sql' => "SELECT \n    DATE_FORMAT(post_date, '%Y-%m') AS row_label,\n    'Posts Published' AS col_label,\n    COUNT(*) AS cell_value\nFROM {prefix}posts\nWHERE post_type = 'post' \n    AND post_status = 'publish'\nGROUP BY DATE_FORMAT(post_date, '%Y-%m')\nORDER BY row_label ASC",
				'row_field' => 'row_label',
				'col_field' => 'col_label',
				'value_field' => 'cell_value',
				'color_min' => '#3b82f6',
				'color_max' => '#1e40af',
				'chart_type' => 'bar',
				'export_enabled' => 1,
			],
			[
				'name' => 'Post Status Distribution (Pie Chart)',
				'description' => 'Provides a comprehensive overview of your WordPress content workflow by showing the distribution of posts across different statuses (published, draft, pending, etc.). This pie chart helps you understand content production efficiency, identify bottlenecks in your editorial process, and track how much content is in various stages of completion. Ideal for content teams to visualize workflow balance and ensure content is moving through the pipeline effectively. Each slice represents a post status, with the size indicating the proportion of posts in that state.',
				'sql' => "SELECT \n    post_status AS row_label,\n    'Status' AS col_label,\n    COUNT(*) AS cell_value\nFROM {prefix}posts\nWHERE post_type = 'post'\nGROUP BY post_status\nORDER BY cell_value DESC",
				'row_field' => 'row_label',
				'col_field' => 'col_label',
				'value_field' => 'cell_value',
				'color_min' => '#3b82f6',
				'color_max' => '#1e40af',
				'chart_type' => 'pie',
				'export_enabled' => 1,
			],
			[
				'name' => 'Posts Over Time by Post Type (Line Chart)',
				'description' => 'Tracks publishing trends across different post types (posts, pages, custom post types) over time. This multi-line chart displays separate lines for each post type, allowing you to compare publishing activity patterns and identify which content types are being published most frequently. Perfect for sites with multiple content types who want to analyze publishing trends, plan content strategies, and understand how different content types contribute to overall site activity. The chart groups posts by month and post type, showing how each type evolves over time.',
				'sql' => "SELECT \n    DATE_FORMAT(post_date, '%Y-%m') AS row_label,\n    post_type AS col_label,\n    COUNT(*) AS cell_value\nFROM {prefix}posts\nWHERE post_status = 'publish'\nGROUP BY DATE_FORMAT(post_date, '%Y-%m'), post_type\nORDER BY row_label ASC, col_label ASC",
				'row_field' => 'row_label',
				'col_field' => 'col_label',
				'value_field' => 'cell_value',
				'color_min' => '#3b82f6',
				'color_max' => '#1e40af',
				'chart_type' => 'line',
				'export_enabled' => 1,
			],
			[
				'name' => 'Posts by Category and Month (Heat Map)',
				'description' => 'A powerful visualization that reveals content distribution patterns across your WordPress categories and time periods. This heat map displays posts organized by category (rows) and month (columns), with color intensity indicating the volume of posts. Darker colors represent higher post counts, making it easy to spot content concentration, identify which categories are most active during specific periods, and discover seasonal content trends. Perfect for content strategists who want to ensure balanced category coverage, identify content gaps, and plan editorial calendars based on historical patterns. The visualization helps answer questions like "Which categories need more content?" and "When do we publish most in each category?"',
				'sql' => "SELECT \n    t.name AS row_label,\n    DATE_FORMAT(p.post_date, '%Y-%m') AS col_label,\n    COUNT(p.ID) AS cell_value\nFROM {prefix}posts p\nINNER JOIN {prefix}term_relationships tr ON tr.object_id = p.ID\nINNER JOIN {prefix}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category'\nINNER JOIN {prefix}terms t ON t.term_id = tt.term_id\nWHERE p.post_type = 'post' \n    AND p.post_status = 'publish'\nGROUP BY t.term_id, t.name, DATE_FORMAT(p.post_date, '%Y-%m')\nORDER BY row_label ASC, col_label ASC",
				'row_field' => 'row_label',
				'col_field' => 'col_label',
				'value_field' => 'cell_value',
				'color_min' => '#f0f9e8',
				'color_max' => '#084081',
				'chart_type' => 'heatmap',
				'export_enabled' => 1,
			],
			[
				'name' => 'Comments by Post and Month (Heat Map)',
				'description' => 'Visualizes comment activity across your WordPress posts over time. This heat map shows which posts receive the most comments in each month, helping you identify your most engaging content and understand when discussions are most active. Darker colors indicate higher comment counts, making it easy to spot your most popular posts and discover patterns in reader engagement. Perfect for content creators and community managers who want to understand what content resonates with their audience, identify posts that generate discussion, and plan future content based on what drives engagement. Use this to answer questions like "Which posts generate the most discussion?" and "When do readers engage most with our content?"',
				'sql' => "SELECT \n    p.post_title AS row_label,\n    DATE_FORMAT(c.comment_date, '%Y-%m') AS col_label,\n    COUNT(c.comment_ID) AS cell_value\nFROM {prefix}comments c\nINNER JOIN {prefix}posts p ON p.ID = c.comment_post_ID\nWHERE c.comment_approved = '1'\n    AND p.post_status = 'publish'\nGROUP BY p.ID, p.post_title, DATE_FORMAT(c.comment_date, '%Y-%m')\nORDER BY row_label ASC, col_label ASC",
				'row_field' => 'row_label',
				'col_field' => 'col_label',
				'value_field' => 'cell_value',
				'color_min' => '#f0f9e8',
				'color_max' => '#084081',
				'chart_type' => 'heatmap',
				'export_enabled' => 1,
			],
			[
				'name' => 'Posts by Author and Category (Heat Map)',
				'description' => 'Reveals content distribution patterns across your WordPress authors and categories. This heat map displays which authors publish in which categories, helping you understand content specialization, identify author expertise areas, and ensure balanced category coverage across your team. Darker colors represent higher post counts, making it easy to see which authors are most active in each category and identify potential content gaps. Ideal for editorial teams and content managers who want to balance workloads, leverage author expertise, and ensure comprehensive category coverage. Use this visualization to answer questions like "Which authors specialize in which topics?" and "Are all categories being covered by our team?"',
				'sql' => "SELECT \n    u.display_name AS row_label,\n    t.name AS col_label,\n    COUNT(p.ID) AS cell_value\nFROM {prefix}posts p\nINNER JOIN {prefix}users u ON u.ID = p.post_author\nINNER JOIN {prefix}term_relationships tr ON tr.object_id = p.ID\nINNER JOIN {prefix}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category'\nINNER JOIN {prefix}terms t ON t.term_id = tt.term_id\nWHERE p.post_type = 'post'\n    AND p.post_status = 'publish'\nGROUP BY u.ID, u.display_name, t.term_id, t.name\nORDER BY row_label ASC, col_label ASC",
				'row_field' => 'row_label',
				'col_field' => 'col_label',
				'value_field' => 'cell_value',
				'color_min' => '#f0f9e8',
				'color_max' => '#084081',
				'chart_type' => 'heatmap',
				'export_enabled' => 1,
			],
			[
				'name' => 'User Registrations by Month and Year (Heat Map)',
				'description' => 'Tracks user growth patterns over time, showing when users registered on your WordPress site. This heat map displays user registrations organized by year (rows) and month (columns), helping you understand user acquisition trends, identify growth periods, and analyze registration patterns. Darker colors indicate more registrations, making it easy to spot registration spikes and understand how your user base has grown. Perfect for site administrators and community managers who want to track user growth, understand registration patterns, and identify periods of high user acquisition. Use this to answer questions like "When did we see the most user growth?" and "Are there seasonal patterns in user registrations?"',
				'sql' => "SELECT \n    YEAR(u.user_registered) AS row_label,\n    MONTHNAME(u.user_registered) AS col_label,\n    COUNT(u.ID) AS cell_value\nFROM {prefix}users u\nWHERE u.user_registered IS NOT NULL\nGROUP BY YEAR(u.user_registered), MONTHNAME(u.user_registered)\nORDER BY row_label ASC, MONTH(u.user_registered) ASC",
				'row_field' => 'row_label',
				'col_field' => 'col_label',
				'value_field' => 'cell_value',
				'color_min' => '#f0f9e8',
				'color_max' => '#084081',
				'chart_type' => 'heatmap',
				'export_enabled' => 1,
			],
			[
				'name' => 'Comments by Post Type and Status (Heat Map)',
				'description' => 'Analyzes comment distribution across different post types and comment statuses. This heat map displays approved, pending, spam, and trashed comments organized by the type of content they were posted on (posts, pages, custom post types). Darker colors represent higher comment counts, helping you understand where engagement happens most and identify moderation patterns. Perfect for content managers and community moderators who want to understand comment activity patterns, identify which content types generate the most discussion, and track comment moderation workload. Use this visualization to answer questions like "Which post types receive the most comments?" and "What is our comment moderation workload distribution?"',
				'sql' => "SELECT \n    p.post_type AS row_label,\n    c.comment_approved AS col_label,\n    COUNT(c.comment_ID) AS cell_value\nFROM {prefix}comments c\nINNER JOIN {prefix}posts p ON p.ID = c.comment_post_ID\nWHERE p.post_status = 'publish'\nGROUP BY p.post_type, c.comment_approved\nORDER BY row_label ASC, col_label ASC",
				'row_field' => 'row_label',
				'col_field' => 'col_label',
				'value_field' => 'cell_value',
				'color_min' => '#f0f9e8',
				'color_max' => '#084081',
				'chart_type' => 'heatmap',
				'export_enabled' => 1,
			],
			[
				'name' => 'Media Uploads by Month and File Type (Heat Map)',
				'description' => 'Visualizes media library activity showing when different file types were uploaded to your WordPress site. This heat map displays images, documents, videos, and other media organized by upload month, helping you understand media usage patterns, identify peak upload periods, and track media library growth. Darker colors indicate more uploads, making it easy to spot busy periods and understand how your media library has grown over time. Perfect for site administrators and content managers who want to track media usage, plan storage needs, and understand content creation patterns. Use this to answer questions like "When do we upload the most media?" and "What types of files do we use most frequently?"',
				'sql' => "SELECT \n    DATE_FORMAT(p.post_date, '%Y-%m') AS row_label,\n    SUBSTRING_INDEX(p.post_mime_type, '/', 1) AS col_label,\n    COUNT(p.ID) AS cell_value\nFROM {prefix}posts p\nWHERE p.post_type = 'attachment'\n    AND p.post_mime_type IS NOT NULL\n    AND p.post_mime_type != ''\nGROUP BY DATE_FORMAT(p.post_date, '%Y-%m'), SUBSTRING_INDEX(p.post_mime_type, '/', 1)\nORDER BY row_label ASC, col_label ASC",
				'row_field' => 'row_label',
				'col_field' => 'col_label',
				'value_field' => 'cell_value',
				'color_min' => '#f0f9e8',
				'color_max' => '#084081',
				'chart_type' => 'heatmap',
				'export_enabled' => 1,
			],
			[
				'name' => 'Tags Usage by Month (Heat Map)',
				'description' => 'Shows tag usage patterns over time, revealing which tags are used most frequently and when. This heat map displays tags (rows) organized by month (columns), with color intensity indicating how many posts were tagged with each tag. Darker colors represent higher tag usage, making it easy to identify trending tags, discover seasonal content themes, and understand tag popularity over time. Perfect for content strategists and SEO managers who want to track tag trends, identify popular topics, and ensure consistent tagging practices. Use this visualization to answer questions like "Which tags are trending?" and "How has tag usage changed over time?"',
				'sql' => "SELECT \n    t.name AS row_label,\n    DATE_FORMAT(p.post_date, '%Y-%m') AS col_label,\n    COUNT(p.ID) AS cell_value\nFROM {prefix}posts p\nINNER JOIN {prefix}term_relationships tr ON tr.object_id = p.ID\nINNER JOIN {prefix}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'post_tag'\nINNER JOIN {prefix}terms t ON t.term_id = tt.term_id\nWHERE p.post_type = 'post'\n    AND p.post_status = 'publish'\nGROUP BY t.term_id, t.name, DATE_FORMAT(p.post_date, '%Y-%m')\nORDER BY row_label ASC, col_label ASC",
				'row_field' => 'row_label',
				'col_field' => 'col_label',
				'value_field' => 'cell_value',
				'color_min' => '#f0f9e8',
				'color_max' => '#084081',
				'chart_type' => 'heatmap',
				'export_enabled' => 1,
			],
			[
				'name' => 'Posts by User (API Example)',
				'description' => 'Demonstrates the Premium feature of connecting to external APIs for data visualization. This example uses the JSONPlaceholder API (a free fake REST API for testing) to fetch and display posts organized by user. It showcases how you can visualize data from any REST API endpoint, making it possible to create charts from external services, third-party APIs, or your own custom API endpoints. This is particularly useful for integrating data from external systems, displaying analytics from other platforms, or creating dashboards that combine data from multiple sources. The chart displays post distribution across different users, helping you understand content authorship patterns in external systems.',
				'sql' => '',
				'row_field' => 'userId',
				'col_field' => 'id',
				'value_field' => 'id',
				'color_min' => '#3b82f6',
				'color_max' => '#1e40af',
				'chart_type' => 'bar',
				'export_enabled' => 1,
				'data_source_type' => 'api',
				'external_config' => [
					'url' => 'https://jsonplaceholder.typicode.com/posts',
					'auth_type' => 'none',
					'auth_token' => '',
				],
			],
			[
				'name' => 'Canada Open Data - Employee Count by Year and Universe',
				'description' => 'An advanced example demonstrating Premium features including external API integration and interactive filters (slicers). This chart connects to the Canada Open Data Portal (CKAN) to fetch and visualize government employee data by year and universe type (APC/OD). It showcases real-world open data visualization, allowing you to explore government datasets, filter data interactively, and export results. The slicers feature enables users to filter by year, universe type, and value ranges directly on the chart, making it perfect for data exploration and analysis. This example is ideal for government agencies, researchers, or anyone working with open data who wants to create interactive visualizations that allow end-users to explore datasets dynamically. The chart demonstrates how to work with complex government APIs and create user-friendly data exploration tools.',
				'sql' => '',
				'row_field' => 'Annee',
				'col_field' => 'Univers',
				'value_field' => 'Nombre des employes',
				'color_min' => '#3b82f6',
				'color_max' => '#1e40af',
				'chart_type' => 'bar',
				'export_enabled' => 1,
				'data_source_type' => 'api',
				'external_config' => [
					'url' => 'https://open.canada.ca/data/en/api/3/action/datastore_search?resource_id=d8e4906d-54a5-4d4b-8fca-9a7dd1bfa72e&limit=100',
					'auth_type' => 'none',
					'auth_token' => '',
				],
				'slicers_config' => [
					'enabled' => true,
					'filter_rows' => true,
					'filter_cols' => true,
					'filter_values' => true,
				],
			],
		];

		$now = current_time('mysql');
		foreach ($samples as $sample) {
			$sample_name = $sample['name'];
			
			// Check if graph with this name already exists
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing_id = (int) $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare("SELECT id FROM {$this->table_name} WHERE name = %s LIMIT 1", $sample_name)
			);
			
			// Skip if already exists and not forcing update
			if ($existing_id > 0 && !$force) {
				continue;
			}
			
			// Skip if exists but update_existing is false (don't update, don't insert duplicate)
			if ($existing_id > 0 && !$update_existing) {
				continue;
			}
			
			$sql_query = str_replace('{prefix}', $prefix, $sample['sql']);
			$data_source_type = isset($sample['data_source_type']) ? $sample['data_source_type'] : 'sql';
			$external_config = isset($sample['external_config']) ? wp_json_encode($sample['external_config']) : null;
			$slicers_config = isset($sample['slicers_config']) ? wp_json_encode($sample['slicers_config']) : null;
			
			$data = [
				'name' => $sample['name'],
				'description' => $sample['description'],
				'sql_query' => $sql_query,
				'row_field' => $sample['row_field'],
				'col_field' => $sample['col_field'],
				'value_field' => $sample['value_field'],
				'color_min' => $sample['color_min'],
				'color_max' => $sample['color_max'],
				'is_enabled' => 1,
				'chart_type' => isset($sample['chart_type']) ? $sample['chart_type'] : 'heatmap',
				'export_enabled' => isset($sample['export_enabled']) ? $sample['export_enabled'] : 0,
				'data_source_type' => $data_source_type,
				'external_config' => $external_config,
				'slicers_config' => $slicers_config,
				'updated_at' => $now,
			];
			
			$formats = ['%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%d','%s','%s','%s','%s'];
			
			if ($existing_id > 0 && $update_existing) {
				// Update existing graph
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$this->table_name,
					$data,
					['id' => $existing_id],
					$formats,
					['%d']
				);
			} else {
				// Insert new graph (only if doesn't exist)
				$data['created_at'] = $now;
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->insert(
					$this->table_name,
					$data,
					array_merge($formats, ['%s'])
				);
			}
		}
	}

	public function enqueue_admin_assets($hook) {
		if ($hook !== 'toplevel_page_exaig_heat_map_graph') {
			return;
		}
		wp_enqueue_style('exaig-heatmap-admin', plugins_url('assets/css/heatmap.css', __FILE__), [], self::VERSION);
		// Enable WP color picker for color range fields
		wp_enqueue_style('wp-color-picker');
		wp_enqueue_script('wp-color-picker');
		wp_enqueue_script('jquery');
		
		// Enqueue premium assets for preview functionality
		if ($this->premium->is_premium()) {
			// Chart.js for chart types
			// Chart.js is a large library (200KB+) - using CDN is acceptable for performance
			// phpcs:ignore PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent
			wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js', [], '3.9.1', true);
			
			// Slicers JavaScript for preview
			if ($this->premium->has_feature('slicers')) {
				wp_enqueue_script('exaig-slicers', plugins_url('public/assets/js/slicers.js', __FILE__), ['jquery'], self::VERSION, true);
			}
		}
			
			// Inline scripts for color picker and SQL validation
			$ajax_nonce = wp_create_nonce('exaig_validate_sql_nonce');
			$heatmap_nonce = wp_create_nonce('exaig_heatmap_action');
			$ajax_url = admin_url('admin-ajax.php');
			
			// Get editing ID from URL if present
			$editing_id = isset($_GET['edit']) ? absint($_GET['edit']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$has_editing = $editing_id > 0;
			
			// Get preview ID from URL if present
			$preview_id = isset($_GET['preview']) ? absint($_GET['preview']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			
			$scripts = "
			jQuery(function($){
				// Modal functionality
				var \$modal = $('#exaig-modal-overlay');
				var \$modalTitle = $('#exaig-modal-title');
				var \$form = $('#exaig-heatmap-form');
				
				// Open modal
				function openModal(editId) {
					if (editId && editId > 0) {
						// Load edit data via redirect
						window.location.href = '?page=exaig_heat_map_graph&edit=' + editId;
					} else {
						// Reset form for new graph
						\$form[0].reset();
						\$form.find('input[name=\"id\"]').val(0);
						\$modalTitle.text('Add New Graph');
						\$modal.fadeIn(200);
						$('body').addClass('exaig-modal-open');
					}
				}
				
				// Close modal
				function closeModal() {
					\$modal.fadeOut(200);
					$('body').removeClass('exaig-modal-open');
					// Clear edit ID from URL
					if (window.location.search.includes('edit=')) {
						window.history.replaceState({}, '', window.location.pathname + '?page=exaig_heat_map_graph');
					}
				}
				
				// Preview modal functionality
				var \$previewModal = $('#exaig-preview-modal-overlay');
				var \$previewModalTitle = $('#exaig-preview-modal-title');
				var \$previewModalContainer = $('#exaig-preview-modal-container');
				
				// Open preview modal
				function openPreviewModal(previewId, graphName) {
					if (!previewId || previewId <= 0) {
						return;
					}
					
					\$previewModalTitle.text('Preview: ' + (graphName || 'Graph'));
					\$previewModalContainer.html('<p style=\"text-align:center;color:#646970;\"><em>Loading preview...</em></p>');
					\$previewModal.fadeIn(200);
					$('body').addClass('exaig-modal-open');
					
					// Load preview via AJAX
					$.ajax({
						url: '{$ajax_url}',
						type: 'POST',
						data: {
							action: 'exaig_preview_graph',
							nonce: '{$ajax_nonce}',
							graph_id: previewId
						},
						success: function(response) {
							if (response.success && response.data.html) {
								var html = response.data.html;
								
								// Extract scripts before inserting HTML
								var scripts = [];
								var htmlWithoutScripts = html.replace(/<script[^>]*>([\s\S]*?)<\/script>/gi, function(match, scriptContent) {
									scripts.push(scriptContent);
									return '<!--SCRIPT_PLACEHOLDER-->';
								});
								
								// Insert HTML
								\$previewModalContainer.html(htmlWithoutScripts);
								
								// Execute scripts
								scripts.forEach(function(scriptContent) {
									var script = document.createElement('script');
									script.textContent = scriptContent;
									\$previewModalContainer[0].appendChild(script);
								});
								
								// Reinitialize slicers after content is loaded
								// Wait for scripts to execute and DOM to be ready
								setTimeout(function() {
									if (typeof jQuery !== 'undefined') {
										// Clear any previous initialization flags to allow re-initialization
										// This must be done before triggering the event
										jQuery('#exaig-preview-modal-container .exaig-slicers-wrapper').each(function() {
											jQuery(this).removeData('exaig-slicers-full-initialized');
										});
										
										// Trigger the event that slicers.js listens to
										// This will call initSlicers() which will properly initialize all slicers
										jQuery(document).trigger('exaig-slicers-content-loaded');
										
										// Double-check that toggle buttons are working
										// Sometimes the event might not fire or slicers.js might not be loaded yet
										setTimeout(function() {
											jQuery('#exaig-preview-modal-container .exaig-slicers-toggle').each(function() {
												var \$toggle = jQuery(this);
												if (!\$toggle.data('exaig-toggle-bound')) {
													\$toggle.data('exaig-toggle-bound', true);
													var wrapperId = \$toggle.attr('id').replace('-toggle', '');
													var \$wrapper = jQuery('#' + wrapperId);
													var \$filters = jQuery('#' + wrapperId + '-filters');
													
													if (\$wrapper.length && \$filters.length) {
														\$toggle.off('click.exaig-preview-slicers').on('click.exaig-preview-slicers', function(e) {
															e.preventDefault();
															var isExpanded = \$toggle.attr('aria-expanded') === 'true';
															var \$toggleText = \$toggle.find('.exaig-slicers-toggle-text');
															
															if (isExpanded) {
																\$filters.slideUp(300);
																\$toggle.attr('aria-expanded', 'false');
																\$toggle.find('.dashicons').removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
																\$toggleText.text('Show Filters');
																\$wrapper.addClass('exaig-slicers-collapsed');
															} else {
																\$filters.slideDown(300);
																\$toggle.attr('aria-expanded', 'true');
																\$toggle.find('.dashicons').removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
																\$toggleText.text('Hide Filters');
																\$wrapper.removeClass('exaig-slicers-collapsed');
															}
														});
													}
												}
											});
										}, 100);
									}
								}, 500);
							} else {
								\$previewModalContainer.html('<em style=\"text-align:center;color:#dc2626;\">Failed to load preview.</em>');
							}
						},
						error: function() {
							\$previewModalContainer.html('<em style=\"text-align:center;color:#dc2626;\">Error loading preview.</em>');
						}
					});
				}
				
				// Close preview modal
				function closePreviewModal() {
					\$previewModal.fadeOut(200);
					$('body').removeClass('exaig-modal-open');
					\$previewModalContainer.html('');
				}
				
				// Preview link handler (row-actions)
				$(document).on('click', '.exaig-preview-link', function(e) {
					e.preventDefault();
					var previewId = $(this).data('preview-id') || 0;
					var graphName = $(this).closest('.exaig-graph-row').find('strong a').text();
					if (previewId > 0) {
						openPreviewModal(previewId, graphName);
					}
				});
				
				// Close preview modal buttons
				$('#exaig-close-preview-modal, #exaig-preview-modal-overlay').on('click', function(e) {
					if ($(e.target).is('#exaig-preview-modal-overlay') || $(e.target).closest('.exaig-modal-close').length) {
						closePreviewModal();
					}
				});
				
				// Close preview modal on Escape key
				$(document).on('keydown', function(e) {
					if (e.key === 'Escape' && \$previewModal.is(':visible')) {
						closePreviewModal();
					}
				});
				
				// Edit button handler
				$('#exaig-open-modal, .exaig-edit-link').on('click', function(e) {
					e.preventDefault();
					var editId = $(this).data('edit-id') || 0;
					if (editId > 0) {
						window.location.href = '?page=exaig_heat_map_graph&edit=' + editId;
					} else {
						openModal(0);
					}
				});
				
				// Restore Default Graphs link handler
				$('#exaig-restore-defaults-link').on('click', function(e) {
					e.preventDefault();
					if (confirm('This will delete and recreate all default sample graphs. Continue?')) {
						$('#exaig-restore-defaults-form').submit();
					}
				});
				
				// Delete link handler (for row-actions)
				var heatmapNonce = '{$heatmap_nonce}';
				$(document).on('click', '.exaig-delete-link', function(e) {
					e.preventDefault();
					var deleteId = $(this).data('delete-id');
					var graphName = $(this).data('graph-name') || 'this graph';
					if (deleteId && confirm('Delete \"' + graphName + '\"? This action cannot be undone.')) {
						var \$form = $('<form>').attr({
							'method': 'post',
							'action': ''
						});
						\$form.append($('<input>').attr({'type': 'hidden', 'name': 'exaig_action', 'value': 'delete_heatmap'}));
						\$form.append($('<input>').attr({'type': 'hidden', 'name': 'id', 'value': deleteId}));
						\$form.append($('<input>').attr({'type': 'hidden', 'name': 'exaig_heatmap_nonce', 'value': heatmapNonce}));
						\$form.appendTo('body').submit();
					}
				});
				
				// Close modal buttons
				$('#exaig-close-modal, .exaig-modal-overlay').on('click', function(e) {
					if ($(e.target).is('.exaig-modal-overlay') || $(e.target).closest('.exaig-modal-close').length) {
						closeModal();
					}
				});
				
				// Close on Escape key
				$(document).on('keydown', function(e) {
					if (e.key === 'Escape' && \$modal.is(':visible')) {
						closeModal();
					}
				});
				
				// Open modal if editing
				" . ($has_editing ? "
				setTimeout(function() {
					\$modalTitle.text('Edit Graph');
					\$modal.fadeIn(200);
					$('body').addClass('exaig-modal-open');
					// Highlight the row being edited
					$('.exaig-graph-row').removeClass('exaig-row-selected');
					$('.exaig-graph-row[data-graph-id=\"' + {$editing_id} + '\"]').addClass('exaig-row-selected');
				}, 100);
				" : '') . "
				
				
				// Initialize color picker
				function initColorPicker() {
					$('.exaig-color-field').each(function(){
						var \$el = $(this);
						if (!\$el.data('colorpicker-initialized')) {
							\$el.wpColorPicker({
								defaultColor: \$el.data('default-color') || false,
								change: function(event, ui) {
									var color = ui.color.toString();
									\$el.closest('.exaig-color-field-wrapper').find('.exaig-color-preview').css('background-color', color);
								}
							});
							\$el.data('colorpicker-initialized', true);
							// Update preview on load
							var initialColor = \$el.val() || \$el.data('default-color') || '#f0f9e8';
							\$el.closest('.exaig-color-field-wrapper').find('.exaig-color-preview').css('background-color', initialColor);
						}
					});
				}
				initColorPicker();
				
				// Data source type toggle
				function toggleDataSourceFields() {
					var sourceType = $('#data_source_type').val();
					var \$externalConfig = $('#exaig-external-data-config');
					var \$sqlSection = $('#exaig-sql-query-section');
					var \$sqlQuery = $('#sql_query');
					
					if (sourceType === 'sql') {
						\$externalConfig.hide();
						\$sqlSection.show();
						\$sqlQuery.prop('required', true);
					} else {
						\$externalConfig.show();
						\$sqlSection.hide();
						\$sqlQuery.prop('required', false);
					}
				}
				
				$('#data_source_type').on('change', toggleDataSourceFields);
				// Trigger on page load to set initial state
				toggleDataSourceFields();
				
				// Auth type toggle
				function toggleAuthTokenField() {
					if ($('#external_auth_type').val() === 'bearer') {
						$('#exaig-auth-token-group').show();
					} else {
						$('#exaig-auth-token-group').hide();
					}
				}
				
				$('#external_auth_type').on('change', toggleAuthTokenField);
				// Trigger on page load to set initial state
				toggleAuthTokenField();
				
				// Test connection button
				$('#exaig-test-connection').on('click', function() {
					var \$btn = $(this);
					var \$status = $('#exaig-connection-status');
					var url = $('#external_api_url').val();
					var authType = $('#external_auth_type').val();
					var authToken = $('#external_auth_token').val();
					var sourceType = $('#data_source_type').val();
					
					if (!url) {
						\$status.html('<span style=\"color:#dc2626;\">Please enter a URL</span>');
						return;
					}
					
					\$btn.prop('disabled', true).html('<span class=\"spinner is-active\" style=\"float:none;margin:0;\"></span> Testing...');
					\$status.html('');
					
					$.ajax({
						url: '{$ajax_url}',
						type: 'POST',
						data: {
							action: 'exaig_test_external_connection',
							nonce: '{$ajax_nonce}',
							url: url,
							auth_type: authType,
							auth_token: authToken,
							source_type: sourceType
						},
						success: function(response) {
							if (response.success) {
								\$status.html('<span style=\"color:#059669;\"><span class=\"dashicons dashicons-yes\" style=\"vertical-align:middle;\"></span> Connection successful! ' + (response.data.message || '') + '</span>');
							} else {
								\$status.html('<span style=\"color:#dc2626;\"><span class=\"dashicons dashicons-no\" style=\"vertical-align:middle;\"></span> ' + (response.data.message || 'Connection failed') + '</span>');
							}
						},
						error: function() {
							\$status.html('<span style=\"color:#dc2626;\"><span class=\"dashicons dashicons-no\" style=\"vertical-align:middle;\"></span> Connection error</span>');
						},
						complete: function() {
							\$btn.prop('disabled', false).html('<span class=\"dashicons dashicons-yes\" style=\"vertical-align:middle;\"></span> Test Connection</span>');
						}
					});
				});
				
				// Real-time SQL validation
				var validationTimeout;
				\$('#sql_query').on('input', function() {
					clearTimeout(validationTimeout);
					var \$textarea = $(this);
					var \$status = \$textarea.siblings('.exaig-sql-validation-status');
					var \$messages = \$textarea.siblings('.exaig-validation-messages');
					
					if (\$textarea.val().trim() === '') {
						\$status.removeClass('valid invalid loading').addClass('dashicons-editor-help');
						\$messages.hide();
						return;
					}
					
					\$status.removeClass('valid invalid').addClass('loading dashicons-update');
					\$messages.hide();
					
					validationTimeout = setTimeout(function() {
						\$.ajax({
							url: '{$ajax_url}',
							type: 'POST',
							data: {
								action: 'exaig_validate_sql',
								nonce: '{$ajax_nonce}',
								sql_query: \$textarea.val(),
								row_field: \$('#row_field').val() || 'row_label',
								col_field: \$('#col_field').val() || 'col_label',
								value_field: \$('#value_field').val() || 'cell_value'
							},
							success: function(response) {
								\$status.removeClass('loading dashicons-update');
								if (response.success && response.data.is_valid) {
									\$status.addClass('valid dashicons-yes-alt');
									\$messages.removeClass('has-errors').addClass('has-success').html(
										'<div class=\"exaig-validation-message success\">SQL query is valid!</div>'
									).show();
								} else {
									\$status.addClass('invalid dashicons-dismiss');
									var errors = response.success ? response.data.errors : [response.data.message || 'Validation failed'];
									var errorHtml = '';
									errors.forEach(function(error) {
										errorHtml += '<div class=\"exaig-validation-message error\">' + error + '</div>';
									});
									\$messages.removeClass('has-success').addClass('has-errors').html(errorHtml).show();
								}
							},
							error: function() {
								\$status.removeClass('loading dashicons-update').addClass('invalid dashicons-dismiss');
								\$messages.removeClass('has-success').addClass('has-errors').html(
									'<div class=\"exaig-validation-message error\">Failed to validate SQL query. Please check your connection.</div>'
								).show();
							}
						});
					}, 500);
				});
				
				// Search functionality for graphs list
				$('#exaig-search-graphs').on('input', function() {
					var searchTerm = $(this).val().toLowerCase().trim();
					var \$rows = $('.exaig-graph-row');
					var visibleCount = 0;
					
					if (searchTerm === '') {
						\$rows.show();
						visibleCount = \$rows.length;
					} else {
						\$rows.each(function() {
							var \$row = $(this);
							var graphName = \$row.data('graph-name') || '';
							var graphId = \$row.data('graph-id') || '';
							
							if (graphName.indexOf(searchTerm) !== -1 || graphId.toString().indexOf(searchTerm) !== -1) {
								\$row.show();
								visibleCount++;
							} else {
								\$row.hide();
							}
						});
					}
					
					// Show message if no results
					var \$tbody = $('#exaig-graphs-tbody');
					var \$noResults = \$tbody.find('.exaig-no-search-results');
					if (visibleCount === 0 && searchTerm !== '') {
						if (\$noResults.length === 0) {
							\$tbody.append('<tr class=\"exaig-no-search-results\"><td colspan=\"5\" style=\"text-align:center;padding:20px;color:#646970;\">No graphs found matching \"' + $('<div>').text(searchTerm).html() + '\"</td></tr>');
						}
					} else {
						\$noResults.remove();
					}
					
					// Update select all checkbox state
					updateSelectAllState();
				});
				
				// Select all checkbox
				$('#exaig-select-all').on('change', function() {
					var isChecked = $(this).is(':checked');
					$('.exaig-graph-checkbox:visible').prop('checked', isChecked);
					updateBulkDeleteButton();
				});
				
				// Individual checkbox change
				$(document).on('change', '.exaig-graph-checkbox', function() {
					updateSelectAllState();
					updateBulkDeleteButton();
				});
				
				// Update select all checkbox state
				function updateSelectAllState() {
					var \$visibleCheckboxes = $('.exaig-graph-checkbox:visible');
					var \$checkedVisible = \$visibleCheckboxes.filter(':checked');
					var \$selectAll = $('#exaig-select-all');
					
					if (\$visibleCheckboxes.length === 0) {
						\$selectAll.prop('checked', false).prop('indeterminate', false);
					} else if (\$checkedVisible.length === \$visibleCheckboxes.length) {
						\$selectAll.prop('checked', true).prop('indeterminate', false);
					} else if (\$checkedVisible.length > 0) {
						\$selectAll.prop('checked', false).prop('indeterminate', true);
					} else {
						\$selectAll.prop('checked', false).prop('indeterminate', false);
					}
				}
				
				// Update bulk delete button visibility
				function updateBulkDeleteButton() {
					var \$checked = $('.exaig-graph-checkbox:checked');
					var \$bulkDeleteBtn = $('#exaig-bulk-delete-btn');
					
					if (\$checked.length > 0) {
						\$bulkDeleteBtn.show().text('Delete Selected (' + \$checked.length + ')');
					} else {
						\$bulkDeleteBtn.hide();
					}
				}
				
				// Bulk delete handler
				$('#exaig-bulk-delete-btn').on('click', function() {
					var \$checked = $('.exaig-graph-checkbox:checked');
					var count = \$checked.length;
					
					if (count === 0) {
						alert('Please select at least one graph to delete.');
						return;
					}
					
					if (!confirm('Delete ' + count + ' selected graph(s)? This action cannot be undone.')) {
						return;
					}
					
					var \$form = $('<form>').attr({
						'method': 'post',
						'action': ''
					});
					
					\$checked.each(function() {
						\$form.append($('<input>').attr({
							'type': 'hidden',
							'name': 'graph_ids[]',
							'value': $(this).val()
						}));
					});
					
					\$form.append($('<input>').attr({'type': 'hidden', 'name': 'exaig_action', 'value': 'bulk_delete'}));
					\$form.append($('<input>').attr({'type': 'hidden', 'name': 'exaig_heatmap_nonce', 'value': heatmapNonce}));
					\$form.appendTo('body').submit();
				});
				
				// Form submission loading state
				\$form.on('submit', function() {
					var \$submit = $(this).find('input[type=\"submit\"], button[type=\"submit\"]');
					\$submit.addClass('exaig-submit-loading').prop('disabled', true);
				});
				
				// Close modal after successful save (check for success messages)
				setTimeout(function() {
					if ($('.notice-success').length > 0) {
						setTimeout(function() {
							closeModal();
							location.reload();
						}, 1000);
					}
				}, 500);
			});
			";
			wp_add_inline_script('wp-color-picker', $scripts);
		}

	private function enqueue_public_assets() {
		if (!wp_style_is('exaig-heatmap-public', 'enqueued')) {
			wp_enqueue_style('exaig-heatmap-public', plugins_url('assets/css/heatmap.css', __FILE__), [], self::VERSION);
		}

		// Enqueue premium assets if available
		if ($this->premium->is_premium()) {
			// Enqueue jQuery if export feature is available (export button script needs it)
			if ($this->premium->has_feature('export')) {
				wp_enqueue_script('jquery');
			}

			// Chart.js for chart types - always load if premium (charts may be used)
			// Chart.js is a large library (200KB+) - using CDN is acceptable for performance
			// phpcs:ignore PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent
			wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js', [], '3.9.1', true);

			// Slicers JavaScript
			if ($this->premium->has_feature('slicers')) {
				wp_enqueue_script('exaig-slicers', plugins_url('public/assets/js/slicers.js', __FILE__), ['jquery'], self::VERSION, true);
			}

			// Interactive charts JavaScript
			if (file_exists(plugin_dir_path(__FILE__) . 'public/assets/js/interactive-charts.js')) {
				wp_enqueue_script('exaig-interactive-charts', plugins_url('public/assets/js/interactive-charts.js', __FILE__), ['jquery', 'chart-js'], self::VERSION, true);
			}
		}
	}

		public function register_admin_menu() {
			add_menu_page(
				'Heat Map Graph',
				'Heat Map Graph',
				'manage_options',
				'exaig_heat_map_graph',
				[$this, 'render_admin_page'],
				'dashicons-chart-area',
				56
			);
		}

	public function handle_admin_actions() {
		if (!is_admin() || !current_user_can('manage_options')) {
			return;
		}

		$action = isset($_POST['exaig_action']) ? sanitize_text_field(wp_unslash($_POST['exaig_action'])) : '';
		if (empty($action)) {
			return;
		}

		check_admin_referer('exaig_heatmap_action', 'exaig_heatmap_nonce');

		if ($action === 'save_heatmap') {
			$this->save_heatmap_from_post();
		} elseif ($action === 'delete_heatmap') {
			$this->delete_heatmap_from_post();
		} elseif ($action === 'bulk_delete') {
			$this->bulk_delete_graphs();
		} elseif ($action === 'restore_defaults') {
			$this->restore_default_graphs();
		}
	}

		public function ajax_validate_sql() {
			check_ajax_referer('exaig_validate_sql_nonce', 'nonce');

			if (!current_user_can('manage_options')) {
				wp_send_json_error(['message' => 'Insufficient permissions.']);
				return;
			}

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$sql_query = isset($_POST['sql_query']) ? wp_unslash($_POST['sql_query']) : '';
			$row_field = isset($_POST['row_field']) ? sanitize_key(wp_unslash($_POST['row_field'])) : 'row_label';
			$col_field = isset($_POST['col_field']) ? sanitize_key(wp_unslash($_POST['col_field'])) : 'col_label';
			$value_field = isset($_POST['value_field']) ? sanitize_key(wp_unslash($_POST['value_field'])) : 'cell_value';

			if (empty($sql_query)) {
				wp_send_json_error(['message' => 'SQL query is required.']);
				return;
			}

			$validation = $this->validate_sql_query($sql_query, $row_field, $col_field, $value_field);
			wp_send_json_success($validation);
		}

		public function ajax_preview_graph() {
			check_ajax_referer('exaig_validate_sql_nonce', 'nonce');

			if (!current_user_can('manage_options')) {
				wp_send_json_error(['message' => 'Insufficient permissions.']);
				return;
			}

			$graph_id = isset($_POST['graph_id']) ? absint($_POST['graph_id']) : 0;

			if ($graph_id <= 0) {
				wp_send_json_error(['message' => 'Invalid graph ID.']);
				return;
			}

			$preview_html = $this->render_heatmap_html($graph_id, 20, 30, true);
			
			// Extract script tags before sanitization (wp_kses strips scripts)
			preg_match_all('/<script[^>]*>(.*?)<\/script>/is', $preview_html, $script_matches);
			$scripts = $script_matches[0];
			$html_no_scripts = preg_replace('/<script[^>]*>.*?<\/script>/is', '<!--EXAIG_SCRIPT_PLACEHOLDER-->', $preview_html);
			
			// Sanitize HTML (without scripts)
			$allowed_tags = $this->get_allowed_heatmap_html_tags();
			$allowed_tags['canvas'] = ['id' => []];
			$html_sanitized = wp_kses($html_no_scripts, $allowed_tags);
			
			// Restore scripts (they're already properly escaped in the renderer)
			foreach ($scripts as $script) {
				$html_sanitized = preg_replace('/<!--EXAIG_SCRIPT_PLACEHOLDER-->/', $script, $html_sanitized, 1);
			}
			
			wp_send_json_success(['html' => $html_sanitized]);
		}

		/**
		 * AJAX handler for testing external data source connections.
		 */
		public function ajax_test_external_connection() {
			check_ajax_referer('exaig_validate_sql_nonce', 'nonce');

			if (!current_user_can('manage_options')) {
				wp_send_json_error(['message' => 'Insufficient permissions.']);
				return;
			}

			if (!$this->premium->is_premium()) {
				wp_send_json_error(['message' => 'Premium feature required.']);
				return;
			}

			$url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
			$auth_type = isset($_POST['auth_type']) ? sanitize_text_field(wp_unslash($_POST['auth_type'])) : 'none';
			$auth_token = isset($_POST['auth_token']) ? sanitize_text_field(wp_unslash($_POST['auth_token'])) : '';
			$source_type = isset($_POST['source_type']) ? sanitize_text_field(wp_unslash($_POST['source_type'])) : 'api';

			if (empty($url)) {
				wp_send_json_error(['message' => 'URL is required.']);
				return;
			}

			// Prepare request args
			$args = [
				'timeout' => 10,
				'sslverify' => true,
			];

			// Add authentication if needed
			if ($auth_type === 'bearer' && !empty($auth_token)) {
				$args['headers'] = [
					'Authorization' => 'Bearer ' . $auth_token,
				];
			}

			// Make request
			$response = wp_remote_get($url, $args);

			if (is_wp_error($response)) {
				wp_send_json_error(['message' => 'Connection failed: ' . $response->get_error_message()]);
				return;
			}

			$status_code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);

			if ($status_code >= 200 && $status_code < 300) {
				// Try to parse response
				if ($source_type === 'csv_url') {
					$lines = explode("\n", $body);
					$row_count = count(array_filter($lines));
					wp_send_json_success(['message' => "Connected successfully. Found {$row_count} rows in CSV."]);
				} else {
					$json = json_decode($body, true);
					if (json_last_error() === JSON_ERROR_NONE) {
						wp_send_json_success(['message' => 'Connected successfully. JSON data received.']);
					} else {
						wp_send_json_success(['message' => 'Connected successfully, but response is not valid JSON.']);
					}
				}
			} else {
				wp_send_json_error(['message' => "Connection failed with status code: {$status_code}"]);
			}
		}

		private function sanitize_hex_color_or_default($color, $default) {
			$color = trim((string)$color);
			if (preg_match('/^#([A-Fa-f0-9]{6})$/', $color)) {
				return $color;
			}
			return $default;
		}

		private function save_heatmap_from_post() {
			global $wpdb;
			// Verify nonce for security (redundant with caller, satisfies linters)
			check_admin_referer('exaig_heatmap_action', 'exaig_heatmap_nonce');

			$id = isset($_POST['id']) ? absint($_POST['id']) : 0;
			$name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
			$description = isset($_POST['description']) ? wp_kses_post(wp_unslash($_POST['description'])) : '';
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$sql_query_raw = isset($_POST['sql_query']) ? wp_unslash($_POST['sql_query']) : '';
			$row_field = isset($_POST['row_field']) ? sanitize_key(wp_unslash($_POST['row_field'])) : '';
			$col_field = isset($_POST['col_field']) ? sanitize_key(wp_unslash($_POST['col_field'])) : '';
			$value_field = isset($_POST['value_field']) ? sanitize_key(wp_unslash($_POST['value_field'])) : '';
			$color_min_input = isset($_POST['color_min']) ? sanitize_text_field(wp_unslash($_POST['color_min'])) : '#f0f9e8';
			$color_min = $this->sanitize_hex_color_or_default($color_min_input, '#f0f9e8');
			$color_max_input = isset($_POST['color_max']) ? sanitize_text_field(wp_unslash($_POST['color_max'])) : '#084081';
			$color_max = $this->sanitize_hex_color_or_default($color_max_input, '#084081');
			$is_enabled = isset($_POST['is_enabled']) ? 1 : 0;
			$chart_type = isset($_POST['chart_type']) ? sanitize_text_field(wp_unslash($_POST['chart_type'])) : 'heatmap';
			$chart_type = in_array($chart_type, ['heatmap', 'bar', 'pie', 'line'], true) ? $chart_type : 'heatmap';
			$data_source_type = isset($_POST['data_source_type']) ? sanitize_text_field(wp_unslash($_POST['data_source_type'])) : 'sql';
			$external_config = [];
			if ($data_source_type !== 'sql' && $this->premium->is_premium()) {
				$external_url = isset($_POST['external_api_url']) ? esc_url_raw(wp_unslash($_POST['external_api_url'])) : '';
				$external_auth_type = isset($_POST['external_auth_type']) ? sanitize_text_field(wp_unslash($_POST['external_auth_type'])) : 'none';
				$external_auth_token = isset($_POST['external_auth_token']) ? sanitize_text_field(wp_unslash($_POST['external_auth_token'])) : '';
				$external_config = [
					'url' => $external_url,
					'auth_type' => $external_auth_type,
					'auth_token' => $external_auth_token,
				];
			}

			$errors = [];
			if ($name === '') {
				$errors[] = 'Name is required.';
			}
			if ($sql_query_raw === '') {
				$errors[] = 'SQL query is required.';
			}
			if ($row_field === '' || $col_field === '' || $value_field === '') {
				$errors[] = 'Row, Column, and Value field names are required.';
			}

			$validation = $this->validate_sql_query($sql_query_raw, $row_field, $col_field, $value_field);
			if (!$validation['is_valid']) {
				$errors = array_merge($errors, $validation['errors']);
			}

			if (!empty($errors)) {
				add_settings_error('exaig_heatmap_messages', 'exaig_heatmap_error', implode(' ', array_map('esc_html', $errors)), 'error');
				return;
			}

			$now = current_time('mysql');
			$data = [
				'name' => $name,
				'description' => $description,
				'sql_query' => $this->strip_trailing_semicolon($sql_query_raw),
				'row_field' => $row_field,
				'col_field' => $col_field,
				'value_field' => $value_field,
				'color_min' => $color_min,
				'color_max' => $color_max,
				'is_enabled' => $is_enabled,
				'chart_type' => $chart_type,
				'data_source_type' => $data_source_type,
				'external_config' => wp_json_encode($external_config),
				'updated_at' => $now,
			];

			$formats = ['%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s'];
			if ($id > 0) {
				// don’t need to switch to $wpdb->prepare() — because $wpdb->update() already builds and runs a prepared statement under the hood.
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update($this->table_name, $data, ['id' => $id], $formats, ['%d']);
				add_settings_error('exaig_heatmap_messages', 'exaig_heatmap_updated', 'Heat map updated successfully.', 'updated');
			} else {
				$data['created_at'] = $now;
				// don’t need to switch to $wpdb->prepare() — because $wpdb->insert() already builds and runs a prepared statement under the hood.
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->insert($this->table_name, $data, array_merge($formats, ['%s']));
				add_settings_error('exaig_heatmap_messages', 'exaig_heatmap_created', 'Heat map created successfully.', 'updated');
			}
		}

	private function delete_heatmap_from_post() {
		global $wpdb;
		// Verify nonce for security (redundant with caller, satisfies linters)
		check_admin_referer('exaig_heatmap_action', 'exaig_heatmap_nonce');
		$id = isset($_POST['id']) ? absint($_POST['id']) : 0;
		if ($id > 0) {
			// don't need to switch to $wpdb->prepare() — because $wpdb->delete() already builds and runs a prepared statement under the hood.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete($this->table_name, ['id' => $id], ['%d']);
			add_settings_error('exaig_heatmap_messages', 'exaig_heatmap_deleted', 'Heat map deleted.', 'updated');
		}
	}

	private function bulk_delete_graphs() {
		global $wpdb;
		// Verify nonce for security
		check_admin_referer('exaig_heatmap_action', 'exaig_heatmap_nonce');
		
		$ids = isset($_POST['graph_ids']) ? array_map('absint', (array)$_POST['graph_ids']) : [];
		
		if (empty($ids)) {
			add_settings_error('exaig_heatmap_messages', 'exaig_heatmap_no_selection', 'No graphs selected for deletion.', 'error');
			return;
		}
		
		$deleted_count = 0;
		foreach ($ids as $id) {
			if ($id > 0) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->delete($this->table_name, ['id' => $id], ['%d']);
				if ($result !== false) {
					$deleted_count++;
				}
			}
		}
		
		if ($deleted_count > 0) {
			add_settings_error('exaig_heatmap_messages', 'exaig_heatmap_bulk_deleted', sprintf('%d graph(s) deleted successfully.', $deleted_count), 'updated');
		} else {
			add_settings_error('exaig_heatmap_messages', 'exaig_heatmap_bulk_delete_failed', 'No graphs were deleted.', 'error');
		}
	}

	private function restore_default_graphs() {
		// Seed samples with force=true and update_existing=true to update existing or insert missing defaults
		$this->maybe_seed_samples(true, true);
		
		add_settings_error('exaig_heatmap_messages', 'exaig_heatmap_restored', 'Default graphs have been restored successfully.', 'updated');
	}

		private function strip_trailing_semicolon($sql) {
			$sql = trim($sql);
			return rtrim($sql, ";\s\n\r\t");
		}

		private function replace_prefix_tag($sql) {
			global $wpdb;
			$prefix = isset($wpdb->prefix) ? (string) $wpdb->prefix : 'wp_';
			// Support both {prefix} and legacy {{prefix}} tokens.
			return str_replace(array('{prefix}', '{{prefix}}'), $prefix, (string) $sql);
		}

		/**
		 * Strictly sanitize SQL identifiers (column aliases) to be alphanumeric/underscore only.
		 * Returns the identifier wrapped in backticks, or an empty string if invalid.
		 */
		private function sanitize_identifier($identifier) {
			$identifier = (string) $identifier;
			if ($identifier === '') {
				return '';
			}
			if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
				return '';
			}
			return '`' . $identifier . '`';
		}

		private function validate_sql_query($sql_query_raw, $row_field, $col_field, $value_field) {
			global $wpdb;
			$errors = [];
			$sql = $this->strip_trailing_semicolon($this->replace_prefix_tag($sql_query_raw));

			if (empty(trim($sql))) {
				$errors[] = 'SQL query cannot be empty.';
				return ['is_valid' => false, 'errors' => $errors];
			}

			if (!preg_match('/^(SELECT|WITH)\s/i', $sql)) {
				$errors[] = 'Only SELECT or WITH queries are allowed. Your query must start with SELECT or WITH. Example: SELECT column1 AS row_label, column2 AS col_label, COUNT(*) AS cell_value FROM {prefix}posts GROUP BY column1, column2';
			}
			$forbidden = [
				'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE', 'RENAME', 'CREATE', 'ATTACH', 'MERGE', 'CALL', 'DO', 'REPLACE', 'OUTFILE', 'INFILE', 'LOAD DATA', 'INTO DUMPFILE', 'HANDLER', 'SLEEP', 'BENCHMARK'
			];
			foreach ($forbidden as $kw) {
				if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $sql)) {
					$errors[] = 'Forbidden keyword detected: ' . esc_html($kw) . '. Only SELECT queries are allowed for security reasons.';
					break;
				}
			}
			if (strpos($sql, ';') !== false && preg_match('/;\s*(SELECT|INSERT|UPDATE|DELETE|DROP|ALTER|CREATE)/i', $sql)) {
				$errors[] = 'Multiple SQL statements are not allowed. Please use a single SELECT statement only.';
			}

			$prefix = $wpdb->prefix;
			$tables = [];
			if (preg_match_all('/\bfrom\s+([^\s,;]+)|\bjoin\s+([^\s,;]+)/i', $sql, $matches)) {
				foreach ($matches[0] as $i => $m) {
					$fromToken = isset($matches[1][$i]) ? $matches[1][$i] : '';
					$joinToken = isset($matches[2][$i]) ? $matches[2][$i] : '';
					$t = trim((string)($fromToken !== '' ? $fromToken : $joinToken));
					$t = preg_replace('/[`\(].*$/', '', $t);
					if ($t !== '' && !preg_match('/^\(/', $t)) {
						$tables[] = $t;
					}
				}
			}
			$tables = array_unique($tables);
			foreach ($tables as $t) {
				if (strpos($t, $prefix) !== 0) {
					$errors[] = 'Only queries against WordPress tables are allowed (must start with "' . esc_html($prefix) . '"). Offending table: ' . esc_html($t) . '. Use {prefix} in your query (e.g., {prefix}posts) and it will be automatically replaced.';
					break;
				}
			}

			$columns_ok = false;
			if (empty($errors)) {
				$test_sql = 'SELECT * FROM (' . $sql . ') AS exaig_heatmap_sub LIMIT %d';
				$test_row = null;
				try {
					// SQL is validated before use and only contains SELECT queries
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
					$test_row = $wpdb->get_row($wpdb->prepare($test_sql, 1), ARRAY_A);
				} catch (Exception $e) {
					$error_msg = esc_html($e->getMessage());
					$errors[] = 'SQL execution error: ' . $error_msg . '. Please check your query syntax and ensure all table names use {prefix} placeholder.';
				}
				if ($test_row !== null) {
					$columns = array_map('strtolower', array_keys($test_row));
					$columns_ok = in_array(strtolower($row_field), $columns, true) && in_array(strtolower($col_field), $columns, true) && in_array(strtolower($value_field), $columns, true);
				}
				if (!$columns_ok) {
					// Try a zero-row projection to validate that the columns exist even if there is no data
					$rf = $this->sanitize_identifier($row_field);
					$cf = $this->sanitize_identifier($col_field);
					$vf = $this->sanitize_identifier($value_field);
					if ($rf === '' || $cf === '' || $vf === '') {
						$errors[] = 'Row/Column/Value field names may only contain letters, numbers, and underscores. Current values: Row="' . esc_html($row_field) . '", Column="' . esc_html($col_field) . '", Value="' . esc_html($value_field) . '".';
					} else {
						$projection_sql = 'SELECT ' . $rf . ', ' . $cf . ', ' . $vf . ' FROM (' . $sql . ') AS exaig_heatmap_sub LIMIT %d';
						try {
							// SQL and identifiers are validated and sanitized before use
							// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
							$wpdb->query($wpdb->prepare($projection_sql, 0));
							$columns_ok = true; // If it runs, the columns exist
						} catch (Exception $e) {
							$errors[] = 'The query must return columns named exactly: "' . esc_html($row_field) . '", "' . esc_html($col_field) . '", and "' . esc_html($value_field) . '". Use AS aliases in your SELECT statement (e.g., SELECT column1 AS ' . esc_html($row_field) . ', column2 AS ' . esc_html($col_field) . ', COUNT(*) AS ' . esc_html($value_field) . ').';
						}
					}
				}
			}

			return [
				'is_valid' => empty($errors),
				'errors' => $errors,
			];
		}

		public function render_admin_page() {
			if (!current_user_can('manage_options')) {
				wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'heat-map-graph'));
			}
			
			// Check and add API example if missing (for existing installations)
			$this->maybe_seed_samples();
			
			global $wpdb;
			$editing_id = isset($_GET['edit']) ? absint($_GET['edit']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$editing = null;
			$external_config = [];
			if ($editing_id > 0) {
				// don't need to switch to $wpdb->prepare() — because $wpdb->get_row() already builds and runs a prepared statement under the hood.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$editing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table_name . ' WHERE id = %d', $editing_id), ARRAY_A);
				// Parse external_config if it exists
				if ($editing && isset($editing['external_config']) && !empty($editing['external_config'])) {
					$external_config = json_decode($editing['external_config'], true);
					if (!is_array($external_config)) {
						$external_config = [];
					}
				}
			}

			// don’t need to switch to $wpdb->prepare() — because $wpdb->get_results() already builds and runs a prepared statement under the hood.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$items = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare("SELECT * FROM {$this->table_name} WHERE %d = %d ORDER BY updated_at DESC", 1, 1),
				ARRAY_A
			);

			settings_errors('exaig_heatmap_messages');
			?>
			<div class="wrap">
				<h1 class="wp-heading-inline">Heat Map Graph</h1>
				<div style="display:inline-flex;align-items:center;gap:8px;vertical-align:middle;">
					<button type="button" class="page-title-action" id="exaig-open-modal" data-edit-id="0"><?php esc_html_e('Add New Graph', 'heat-map-graph'); ?></button>
					<form method="post" id="exaig-restore-defaults-form" style="display:inline-block;margin:0;vertical-align:middle;">
						<?php wp_nonce_field('exaig_heatmap_action', 'exaig_heatmap_nonce'); ?>
						<input type="hidden" name="exaig_action" value="restore_defaults" />
						<a href="#" class="page-title-action" id="exaig-restore-defaults-link" style="text-decoration:none;">Restore Default Graphs</a>
					</form>
				</div>
				<hr class="wp-header-end">
				
				<!-- Modal Overlay for Edit Form -->
				<div id="exaig-modal-overlay" class="exaig-modal-overlay" style="display:none;">
					<div class="exaig-modal-container">
						<div class="exaig-modal-header">
							<h2 id="exaig-modal-title"><?php echo $editing ? esc_html__('Edit Graph', 'heat-map-graph') : esc_html__('Add New Graph', 'heat-map-graph'); ?></h2>
							<button type="button" class="exaig-modal-close" id="exaig-close-modal" aria-label="Close">
								<span class="dashicons dashicons-no"></span>
							</button>
						</div>
						<div class="exaig-modal-content">
							<div class="exaig-admin-form-wrapper" style="padding:24px;">
							<form method="post" id="exaig-heatmap-form">
								<?php wp_nonce_field('exaig_heatmap_action', 'exaig_heatmap_nonce'); ?>
								<input type="hidden" name="exaig_action" value="save_heatmap" />
								<input type="hidden" name="id" value="<?php echo $editing ? esc_attr($editing['id']) : 0; ?>" />

								<div class="exaig-form-section">
									<h3 class="exaig-form-section-title">Basic Information</h3>
									<div class="exaig-field-group">
										<label for="name">
											<span class="dashicons dashicons-edit"></span>
											Name <span style="color:#dc2626;">*</span>
										</label>
										<input name="name" id="name" type="text" class="regular-text" required value="<?php echo $editing ? esc_attr($editing['name']) : ''; ?>" aria-required="true" />
										<p class="exaig-field-help">A descriptive name for your heat map (e.g., "Posts per Category per Day")</p>
									</div>
									<div class="exaig-field-group">
										<label for="description">
											<span class="dashicons dashicons-text"></span>
											Description
										</label>
										<textarea name="description" id="description" class="large-text" rows="3" aria-describedby="description-help"><?php echo $editing ? esc_textarea($editing['description']) : ''; ?></textarea>
										<p class="exaig-field-help" id="description-help">Optional description explaining what this heat map displays</p>
									</div>
								</div>

								<?php if ($this->premium->is_premium()) : ?>
								<div class="exaig-form-section">
									<h3 class="exaig-form-section-title">Data Source (Premium)</h3>
									<div class="exaig-field-group">
										<label for="data_source_type">
											<span class="dashicons dashicons-database"></span>
											Data Source Type
										</label>
										<select name="data_source_type" id="data_source_type" class="regular-text">
											<option value="sql" <?php selected(isset($editing['data_source_type']) ? $editing['data_source_type'] : 'sql', 'sql'); ?>>SQL Query</option>
											<option value="api" <?php selected(isset($editing['data_source_type']) ? $editing['data_source_type'] : '', 'api'); ?>>REST API</option>
											<option value="csv_url" <?php selected(isset($editing['data_source_type']) ? $editing['data_source_type'] : '', 'csv_url'); ?>>CSV URL</option>
										</select>
										<p class="exaig-field-help">Choose where to get your data from</p>
									</div>
									<div id="exaig-external-data-config" style="display:none;">
										<div class="exaig-field-group">
											<label for="external_api_url">
												<span class="dashicons dashicons-admin-links"></span>
												API/CSV URL <span style="color:#dc2626;">*</span>
											</label>
											<input type="url" name="external_api_url" id="external_api_url" class="regular-text" 
												value="<?php echo isset($external_config['url']) ? esc_attr($external_config['url']) : ''; ?>" 
												placeholder="https://api.example.com/data.json" />
											<p class="exaig-field-help">Enter the URL to fetch data from</p>
										</div>
										<div class="exaig-field-group">
											<label for="external_auth_type">
												<span class="dashicons dashicons-lock"></span>
												Authentication Type
											</label>
											<select name="external_auth_type" id="external_auth_type" class="regular-text">
												<option value="none" <?php selected(isset($external_config['auth_type']) ? $external_config['auth_type'] : 'none', 'none'); ?>>None</option>
												<option value="bearer" <?php selected(isset($external_config['auth_type']) ? $external_config['auth_type'] : '', 'bearer'); ?>>Bearer Token</option>
											</select>
										</div>
										<div class="exaig-field-group" id="exaig-auth-token-group" style="display:none;">
											<label for="external_auth_token">
												<span class="dashicons dashicons-admin-network"></span>
												Auth Token
											</label>
											<input type="text" name="external_auth_token" id="external_auth_token" class="regular-text" 
												value="<?php echo isset($external_config['auth_token']) ? esc_attr($external_config['auth_token']) : ''; ?>" 
												placeholder="Your API token" />
										</div>
										<div class="exaig-field-group">
											<button type="button" class="button button-secondary" id="exaig-test-connection">
												<span class="dashicons dashicons-yes" style="vertical-align:middle;"></span>
												Test Connection
											</button>
											<span id="exaig-connection-status" style="margin-left:12px;"></span>
										</div>
									</div>
								</div>
								<?php else : ?>
									<?php echo wp_kses($this->premium->render_upgrade_notice('External Data Sources', 'Upgrade to Premium to connect to external APIs and CSV URLs.'), ['div' => ['class' => [], 'style' => []], 'h3' => ['style' => []], 'p' => ['style' => []], 'span' => ['class' => [], 'style' => []], 'a' => ['href' => [], 'class' => [], 'target' => []]]); ?>
								<?php endif; ?>

								<div class="exaig-form-section" id="exaig-sql-query-section">
									<h3 class="exaig-form-section-title">SQL Query</h3>
									<div class="exaig-field-group exaig-sql-validation">
										<label for="sql_query">
											<span class="dashicons dashicons-editor-code"></span>
											SQL Query <span style="color:#dc2626;">*</span>
										</label>
										<textarea name="sql_query" id="sql_query" class="large-text code" rows="10" required placeholder="SELECT cat_terms.name AS row_label, DATE(p.post_date) AS col_label, COUNT(*) AS cell_value&#10;FROM {prefix}posts p&#10;JOIN {prefix}term_relationships tr ON tr.object_id = p.ID&#10;JOIN {prefix}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category'&#10;JOIN {prefix}terms cat_terms ON cat_terms.term_id = tt.term_id&#10;WHERE p.post_type = 'post' AND p.post_status = 'publish'&#10;GROUP BY cat_terms.name, DATE(p.post_date)" aria-required="true" aria-describedby="sql-query-help sql-validation-messages"><?php echo $editing ? esc_textarea($editing['sql_query']) : ''; ?></textarea>
										<span class="exaig-sql-validation-status dashicons" role="status" aria-live="polite" aria-atomic="true"></span>
										<div class="exaig-validation-messages" id="sql-validation-messages" role="alert" aria-live="polite" style="display:none;"></div>
										<p class="exaig-field-help" id="sql-query-help">
											Must be a single <code>SELECT</code> statement against WordPress tables. Use <code>{prefix}</code> for the site's table prefix (e.g., <code>{prefix}posts</code>). 
											The query must return columns matching the field names below. Forbidden keywords: ALTER, UPDATE, DROP, DELETE, INSERT, TRUNCATE, etc.
										</p>
									</div>
								</div>

								<div class="exaig-form-section">
									<h3 class="exaig-form-section-title">Field Mapping</h3>
									<p class="exaig-field-help" style="margin-bottom:16px;">These field names must match the column aliases returned by your SQL query.</p>
									<div class="exaig-field-group">
										<label for="row_field">
											<span class="dashicons dashicons-list-view"></span>
											Row Field <span style="color:#dc2626;">*</span>
										</label>
										<input name="row_field" id="row_field" type="text" class="regular-text" required value="<?php echo $editing ? esc_attr($editing['row_field']) : 'row_label'; ?>" aria-required="true" />
										<p class="exaig-field-help">Column name that will appear as rows in the heat map (e.g., "row_label")</p>
									</div>
									<div class="exaig-field-group">
										<label for="col_field">
											<span class="dashicons dashicons-grid-view"></span>
											Column Field <span style="color:#dc2626;">*</span>
										</label>
										<input name="col_field" id="col_field" type="text" class="regular-text" required value="<?php echo $editing ? esc_attr($editing['col_field']) : 'col_label'; ?>" aria-required="true" />
										<p class="exaig-field-help">Column name that will appear as columns in the heat map (e.g., "col_label")</p>
									</div>
									<div class="exaig-field-group">
										<label for="value_field">
											<span class="dashicons dashicons-chart-line"></span>
											Value Field <span style="color:#dc2626;">*</span>
										</label>
										<input name="value_field" id="value_field" type="text" class="regular-text" required value="<?php echo $editing ? esc_attr($editing['value_field']) : 'cell_value'; ?>" aria-required="true" />
										<p class="exaig-field-help">Column name containing the numeric values to display (e.g., "cell_value")</p>
									</div>
								</div>

								<div class="exaig-form-section">
									<h3 class="exaig-form-section-title">Color Range</h3>
									<div class="exaig-color-field-group">
										<div class="exaig-color-field-wrapper">
											<label for="color_min">Min Color</label>
											<div class="exaig-color-preview" style="background-color:<?php echo $editing ? esc_attr($editing['color_min']) : '#f0f9e8'; ?>"></div>
											<input name="color_min" id="color_min" type="text" class="exaig-color-field" data-default-color="#f0f9e8" value="<?php echo $editing ? esc_attr($editing['color_min']) : '#f0f9e8'; ?>" />
										</div>
										<div class="exaig-color-field-wrapper">
											<label for="color_max">Max Color</label>
											<div class="exaig-color-preview" style="background-color:<?php echo $editing ? esc_attr($editing['color_max']) : '#084081'; ?>"></div>
											<input name="color_max" id="color_max" type="text" class="exaig-color-field" data-default-color="#084081" value="<?php echo $editing ? esc_attr($editing['color_max']) : '#084081'; ?>" />
										</div>
									</div>
									<p class="exaig-field-help">Colors will be interpolated between min (low values) and max (high values)</p>
								</div>

								<?php if ($this->premium->is_premium()) : ?>
								<div class="exaig-form-section">
									<h3 class="exaig-form-section-title">Chart Type (Premium)</h3>
									<div class="exaig-field-group">
										<label for="chart_type">
											<span class="dashicons dashicons-chart-bar"></span>
											Chart Type
										</label>
										<select name="chart_type" id="chart_type" class="regular-text">
											<option value="heatmap" <?php selected(isset($editing['chart_type']) ? $editing['chart_type'] : 'heatmap', 'heatmap'); ?>>Heat Map</option>
											<option value="bar" <?php selected(isset($editing['chart_type']) ? $editing['chart_type'] : '', 'bar'); ?>>Bar Chart</option>
											<option value="pie" <?php selected(isset($editing['chart_type']) ? $editing['chart_type'] : '', 'pie'); ?>>Pie Chart</option>
											<option value="line" <?php selected(isset($editing['chart_type']) ? $editing['chart_type'] : '', 'line'); ?>>Line Chart</option>
										</select>
										<p class="exaig-field-help">Choose how to visualize your data</p>
									</div>
								</div>
								<?php else : ?>
									<?php echo wp_kses($this->premium->render_upgrade_notice('Multiple Chart Types', 'Upgrade to Premium to use bar charts, pie charts, and line charts.'), ['div' => ['class' => [], 'style' => []], 'h3' => ['style' => []], 'p' => ['style' => []], 'span' => ['class' => [], 'style' => []], 'a' => ['href' => [], 'class' => [], 'target' => []]]); ?>
								<?php endif; ?>

								<div class="exaig-form-section">
									<h3 class="exaig-form-section-title">Status</h3>
									<div class="exaig-field-group">
										<label>
											<input type="checkbox" name="is_enabled" <?php echo $editing && (int)$editing['is_enabled'] === 0 ? '' : 'checked'; ?> />
											Enable this heat map
										</label>
										<?php if ($editing) : ?>
											<p class="exaig-field-help">
												Shortcode: <code>[heat_map_graph id="<?php echo (int)$editing['id']; ?>"]</code>
												<button type="button" class="button button-small" onclick="navigator.clipboard.writeText('[heat_map_graph id=&quot;<?php echo (int)$editing['id']; ?>&quot;]'); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy', 2000);" style="margin-left:8px;">Copy</button>
											</p>
										<?php endif; ?>
									</div>
								</div>

								<?php submit_button($editing ? 'Update Graph' : 'Create Graph', 'primary', 'submit', false); ?>
							</form>
						</div>
						</div>
					</div>
				</div>

				<!-- Preview Modal Overlay -->
				<div id="exaig-preview-modal-overlay" class="exaig-modal-overlay" style="display:none;">
					<div class="exaig-modal-container" style="max-width:1200px;">
						<div class="exaig-modal-header">
							<h2 id="exaig-preview-modal-title">Preview Graph</h2>
							<button type="button" class="exaig-modal-close" id="exaig-close-preview-modal" aria-label="Close">
								<span class="dashicons dashicons-no"></span>
							</button>
						</div>
						<div class="exaig-modal-content" style="padding:24px;">
							<div id="exaig-preview-modal-container">
								<p style="text-align:center;color:#646970;"><em>Loading preview...</em></p>
							</div>
						</div>
					</div>
				</div>

				<div style="display:flex;gap:24px;align-items:flex-start;margin-top:24px;">
					<div style="flex:1; min-width:480px;display:flex;flex-direction:column;">

						<?php if (!$this->premium->is_premium()) : ?>
							<div class="exaig-premium-notice" style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:12px;margin-bottom:16px;">
								<p style="margin:0;color:#856404;font-size:13px;">
									<span class="dashicons dashicons-star-filled" style="vertical-align:middle;color:#ffc107;"></span>
									<strong>Premium Features Available:</strong> Upgrade to Premium to unlock multiple chart types (bar, pie, line), external API/CSV data sources, interactive filters (slicers), and CSV export functionality.
									<?php
									$upgrade_url = EXAIG_Heat_Map_Graph_Premium_Features::get_upgrade_url();
									if ($upgrade_url) :
										?>
										<a href="<?php echo esc_url($upgrade_url); ?>" target="_blank" style="color:#856404;text-decoration:underline;margin-left:8px;">Learn More →</a>
									<?php endif; ?>
								</p>
							</div>
						<?php endif; ?>
						<div style="margin-bottom:16px;">
							<input type="text" id="exaig-search-graphs" placeholder="Search graphs..." class="regular-text" style="max-width:300px;" />
						</div>
						<div style="flex:1;display:flex;flex-direction:column;min-height:0;">
							<div style="flex:1;overflow-y:auto;max-height:calc(100vh - 300px);">
								<table class="widefat fixed striped" id="exaig-graphs-table">
									<thead>
										<tr>
											<th class="check-column" style="width:2%;"><input type="checkbox" id="exaig-select-all" /></th>
											<th style="width:38%;">Name</th>
											<th style="width:10%;">Enabled</th>
											<th style="width:15%;">Updated</th>
											<th style="width:35%;">Shortcode</th>
										</tr>
									</thead>
									<tbody id="exaig-graphs-tbody">
										<?php if (empty($items)) : ?>
											<tr><td colspan="5">No graphs found. Use the form to create one, or click "Restore Default Graphs" to add sample graphs.</td></tr>
										<?php else : ?>
											<?php foreach ($items as $item) : ?>
												<?php
												// Format date for display
												$updated_datetime = mysql2date('Y-m-d H:i:s', $item['updated_at'], false);
												$updated_date = mysql2date('Y-m-d', $item['updated_at'], false);
												$updated_time = mysql2date('H:i:s', $item['updated_at'], false);
												$updated_formatted = mysql2date(get_option('date_format'), $item['updated_at'], false);
												
												// Check if graph uses premium features
												$chart_type = isset($item['chart_type']) ? $item['chart_type'] : 'heatmap';
												$data_source_type = isset($item['data_source_type']) ? $item['data_source_type'] : 'sql';
												$slicers_config = isset($item['slicers_config']) && !empty($item['slicers_config']) ? json_decode($item['slicers_config'], true) : null;
												$has_slicers = $slicers_config && isset($slicers_config['enabled']) && $slicers_config['enabled'];
												$is_premium_chart = ($chart_type !== 'heatmap') || ($data_source_type !== 'sql') || $has_slicers;
												?>
												<tr class="exaig-graph-row" data-graph-id="<?php echo (int)$item['id']; ?>" data-graph-name="<?php echo esc_attr(strtolower($item['name'])); ?>" <?php echo ($editing && (int)$editing['id'] === (int)$item['id']) ? 'data-selected="true"' : ''; ?>>
													<th scope="row" class="check-column">
														<input type="checkbox" name="graph_ids[]" value="<?php echo (int)$item['id']; ?>" class="exaig-graph-checkbox" />
													</th>
													<td>
														<strong><a href="#" class="exaig-edit-link" data-edit-id="<?php echo (int)$item['id']; ?>" title="<?php echo esc_attr($item['name']); ?>"><?php echo esc_html($item['name']); ?></a></strong>
														<?php if ($is_premium_chart) : ?>
															<span class="exaig-premium-badge" style="display:inline-block;margin-left:8px;padding:2px 8px;background:#ffc107;color:#856404;font-size:11px;font-weight:600;border-radius:3px;vertical-align:middle;">Premium</span>
														<?php endif; ?>
														<div class="row-actions">
															<span class="preview"><a href="#" class="exaig-preview-link" data-preview-id="<?php echo (int)$item['id']; ?>">Preview</a> |</span>
															<span class="edit"><a href="#" class="exaig-edit-link" data-edit-id="<?php echo (int)$item['id']; ?>">Edit</a> |</span>
															<span class="trash"><a href="#" class="exaig-delete-link submitdelete" data-delete-id="<?php echo (int)$item['id']; ?>" data-graph-name="<?php echo esc_attr($item['name']); ?>">Delete</a></span>
														</div>
													</td>
													<td><?php echo (int)$item['is_enabled'] ? 'Yes' : 'No'; ?></td>
													<td><span title="<?php echo esc_attr($updated_datetime); ?>"><?php echo esc_html($updated_formatted); ?></span></td>
													<td>
														<code style="font-size:12px;">[heat_map_graph id="<?php echo (int)$item['id']; ?>"]</code>
													</td>
												</tr>
											<?php endforeach; ?>
										<?php endif; ?>
									</tbody>
								</table>
							</div>
							<div style="margin-top:16px;padding-top:16px;border-top:1px solid #c3c4c7;display:flex;justify-content:flex-start;align-items:center;">
								<div>
									<button type="button" class="button" id="exaig-bulk-delete-btn" style="display:none;">Delete Selected</button>
								</div>
							</div>
						</div>
					</div>
					<div class="exaig-preview-section" style="display:none; flex:1; min-width:400px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px;box-shadow:0 1px 1px rgba(0,0,0,.04);">
						<h2>Preview</h2>
						<p class="description">Click on a graph name to preview it below. A simple preview is shown (may be truncated).</p>
						<div class="exaig-heatmap-preview" id="exaig-preview-container">
							<?php
									$preview_id = isset($_GET['preview']) ? absint($_GET['preview']) : ($editing ? (int)$editing['id'] : 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
									if ($preview_id > 0) {
										global $wpdb;
										// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
										$preview_item = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table_name . ' WHERE id = %d', $preview_id), ARRAY_A);
										if ($preview_item) {
											$preview_html = $this->render_heatmap_html((int)$preview_item['id'], 20, 30, true);
											echo wp_kses($preview_html, $this->get_allowed_heatmap_html_tags());
										} else {
											echo '<em>Graph not found.</em>';
										}
									} else {
										echo '<em>Click on a graph name to preview it here.</em>';
									}
							?>
						</div>
					</div>
				</div>
			</div>
			<?php
		}

		public function shortcode_handler($atts) {
			$atts = shortcode_atts([
				'id' => 0,
			], $atts, 'heat_map_graph');
			$id = absint($atts['id']);
			if ($id <= 0) {
				return '<div class="exaig-heatmap-error">Invalid heat map id.</div>';
			}
			$this->enqueue_public_assets();
			$html = $this->render_heatmap_html($id);
			
			// Extract script tags before sanitization (wp_kses strips scripts)
			preg_match_all('/<script[^>]*>(.*?)<\/script>/is', $html, $script_matches);
			$scripts = $script_matches[0];
			$html_no_scripts = preg_replace('/<script[^>]*>.*?<\/script>/is', '<!--EXAIG_SCRIPT_PLACEHOLDER-->', $html);
			
			// Sanitize HTML (without scripts)
			$allowed_tags = $this->get_allowed_heatmap_html_tags();
			$allowed_tags['canvas'] = ['id' => []];
			$html_sanitized = wp_kses($html_no_scripts, $allowed_tags);
			
			// Restore scripts (they're already properly escaped in the renderer)
			foreach ($scripts as $script) {
				$html_sanitized = preg_replace('/<!--EXAIG_SCRIPT_PLACEHOLDER-->/', $script, $html_sanitized, 1);
			}
			
			return $html_sanitized;
		}

		private function render_heatmap_html($id, $max_rows = 0, $max_cols = 0, $is_preview = false) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$conf = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table_name . ' WHERE id = %d', $id), ARRAY_A);
			if (!$conf) {
				return '<div class="exaig-heatmap-error">Heat map not found.</div>';
			}
			if ((int)$conf['is_enabled'] === 0 && !$is_preview) {
				return '<div class="exaig-heatmap-error">This heat map is disabled.</div>';
			}

			// Check data source type
			$data_source_type = isset($conf['data_source_type']) ? $conf['data_source_type'] : 'sql';
			$results = null;

			if ($data_source_type === 'sql') {
				$sql = $this->strip_trailing_semicolon($conf['sql_query']);
				$sql = $this->replace_prefix_tag($sql);
				$validation = $this->validate_sql_query($sql, $conf['row_field'], $conf['col_field'], $conf['value_field']);
				if (!$validation['is_valid']) {
					return '<div class="exaig-heatmap-error">' . esc_html(implode(' ', $validation['errors'])) . '</div>';
				}

				try {
					// SQL is validated before use and only contains SELECT queries
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
					$results = $wpdb->get_results($sql, ARRAY_A);
				} catch (Exception $e) {
					return '<div class="exaig-heatmap-error">Query failed: ' . esc_html($e->getMessage()) . '</div>';
				}
			} else {
				// External data source
				if (class_exists('EXAIG_Heat_Map_Graph_External_Data')) {
					$external_config = isset($conf['external_config']) ? json_decode($conf['external_config'], true) : [];
					$external_config['row_field'] = $conf['row_field'];
					$external_config['col_field'] = $conf['col_field'];
					$external_config['value_field'] = $conf['value_field'];
					$external_data = EXAIG_Heat_Map_Graph_External_Data::fetch_data($data_source_type, $external_config);
					if (is_wp_error($external_data)) {
						return '<div class="exaig-heatmap-error">External data error: ' . esc_html($external_data->get_error_message()) . '</div>';
					}
					$results = $external_data;
				} else {
					return '<div class="exaig-heatmap-error">External data sources require Premium.</div>';
				}
			}

			if (!$results) {
				return '<div class="exaig-heatmap-info">No data to display.</div>';
			}

			$row_field = $conf['row_field'];
			$col_field = $conf['col_field'];
			$val_field = $conf['value_field'];

			$rows = [];
			$cols = [];
			$data = [];
			$min_val = PHP_FLOAT_MAX;
			$max_val = -PHP_FLOAT_MAX;

			foreach ($results as $r) {
				$row = (string)$r[$row_field];
				$col = (string)$r[$col_field];
				$val = is_numeric($r[$val_field]) ? (float)$r[$val_field] : 0.0;
				$rows[$row] = true;
				$cols[$col] = true;
				$data[$row][$col] = $val;
				if ($val < $min_val) { $min_val = $val; }
				if ($val > $max_val) { $max_val = $val; }
			}

			$rows = array_keys($rows);
			$cols = array_keys($cols);
			sort($rows);
			sort($cols);

			if ($max_rows > 0 && count($rows) > $max_rows) {
				$rows = array_slice($rows, 0, $max_rows);
			}
			if ($max_cols > 0 && count($cols) > $max_cols) {
				$cols = array_slice($cols, 0, $max_cols);
			}

			// Check chart type
			$chart_type = isset($conf['chart_type']) ? $conf['chart_type'] : 'heatmap';

			// Render different chart types
			if ($chart_type !== 'heatmap' && class_exists('EXAIG_Heat_Map_Graph_Chart_Renderer')) {
				$chart_html = EXAIG_Heat_Map_Graph_Chart_Renderer::render($data, $chart_type, [
					'color_min' => $conf['color_min'],
					'color_max' => $conf['color_max'],
					'id' => $id,
					'is_preview' => $is_preview,
				]);
				
				// Extract canvas ID from chart HTML for linking
				preg_match('/id="([^"]*exaig-chart-[^"]*)"/', $chart_html, $matches);
				$canvas_id = isset($matches[1]) ? $matches[1] : '';
				
				ob_start();
				?>
				<div class="exaig-heatmap-wrapper">
					<?php if ($this->premium->has_feature('export') && class_exists('EXAIG_Heat_Map_Graph_Export_Handler')) : ?>
						<?php 
						$export_button_html = EXAIG_Heat_Map_Graph_Export_Handler::render_export_button($id);
						echo wp_kses($export_button_html, $this->get_allowed_heatmap_html_tags());
						?>
					<?php endif; ?>
					<?php if ($this->premium->has_feature('slicers') && class_exists('EXAIG_Heat_Map_Graph_Slicer_Handler')) : ?>
						<?php 
						$slicer_html = EXAIG_Heat_Map_Graph_Slicer_Handler::render($data, ['id' => absint($id)]);
						echo wp_kses($slicer_html, $this->get_allowed_heatmap_html_tags());
						?>
					<?php endif; ?>
					<?php echo wp_kses($chart_html, $this->get_allowed_heatmap_html_tags()); ?>
					<?php
					// Initialize linked charts if group is set
					if ($this->premium->has_feature('linked_charts') && class_exists('EXAIG_Heat_Map_Graph_Chart_Linking')) {
						$linked_group = isset($conf['linked_group']) ? $conf['linked_group'] : '';
						if (!empty($linked_group)) {
							$linking_html = EXAIG_Heat_Map_Graph_Chart_Linking::init_linking($linked_group, absint($id), esc_attr($canvas_id));
							echo wp_kses($linking_html, $this->get_allowed_heatmap_html_tags());
						}
					}
					?>
				</div>
				<?php
				// Chart.js should already be enqueued via enqueue_public_assets()
				// But ensure it's loaded if not already
				if (!wp_script_is('chart-js', 'enqueued')) {
					// Chart.js is a large library (200KB+) - using CDN is acceptable for performance
					// phpcs:ignore PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent
					wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js', [], '3.9.1', true);
				}
				// Ensure Chart.js loads before our inline scripts
				wp_enqueue_script('chart-js');
				wp_script_add_data('chart-js', 'defer', false);
				return ob_get_clean();
			}

			// Render heat map (default)
			$color_min = $conf['color_min'];
			$color_max = $conf['color_max'];
			$legend_html = $this->render_color_legend($color_min, $color_max, $min_val, $max_val);

			$thead = '<thead><tr><th class="exaig-hm-sticky" scope="col">' . esc_html($conf['name']) . '</th>';
			foreach ($cols as $col) {
				$thead .= '<th class="exaig-hm-col" scope="col">' . esc_html($col) . '</th>';
			}
			$thead .= '</tr></thead>';

			$tbody = '<tbody>';
			foreach ($rows as $row) {
				$tbody .= '<tr>';
				$tbody .= '<th class="exaig-hm-row exaig-hm-sticky" scope="row">' . esc_html($row) . '</th>';
				foreach ($cols as $col) {
					$val = isset($data[$row][$col]) ? (float)$data[$row][$col] : 0.0;
					$color = $this->interpolate_color($color_min, $color_max, $min_val, $max_val, $val);
					$title = esc_attr($row . ' / ' . $col . ': ' . $val);
					$tbody .= '<td class="exaig-hm-cell" title="' . $title . '" style="background-color:' . esc_attr($color) . ';" role="gridcell" tabindex="0">' . esc_html($this->format_number($val)) . '</td>';
				}
				$tbody .= '</tr>';
			}
			$tbody .= '</tbody>';

			ob_start();
			?>
			<div class="exaig-heatmap-wrapper">
				<?php if ($this->premium->has_feature('export') && class_exists('EXAIG_Heat_Map_Graph_Export_Handler')) : ?>
					<?php 
					$export_button_html = EXAIG_Heat_Map_Graph_Export_Handler::render_export_button($id);
					echo wp_kses($export_button_html, $this->get_allowed_heatmap_html_tags());
					?>
				<?php endif; ?>
				<?php if ($this->premium->has_feature('slicers') && class_exists('EXAIG_Heat_Map_Graph_Slicer_Handler')) : ?>
					<?php 
					$slicer_html = EXAIG_Heat_Map_Graph_Slicer_Handler::render($data, ['id' => absint($id)]);
					echo wp_kses($slicer_html, $this->get_allowed_heatmap_html_tags());
					?>
				<?php endif; ?>
				<div class="exaig-heatmap-legend"><?php echo wp_kses($legend_html, $this->get_allowed_heatmap_html_tags()); ?></div>
				<div class="exaig-heatmap-scroll">
						<table class="exaig-heatmap-table" role="grid" aria-label="Heat map: <?php echo esc_attr($conf['name']); ?>">
							<?php echo wp_kses($thead . $tbody, $this->get_allowed_heatmap_html_tags()); ?>
					</table>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}

		private function format_number($num) {
			if (abs($num) >= 1000000) {
				return number_format_i18n($num / 1000000, 1) . 'M';
			}
			if (abs($num) >= 1000) {
				return number_format_i18n($num / 1000, 1) . 'K';
			}
			return number_format_i18n($num, 0);
		}

		private function render_color_legend($min_hex, $max_hex, $min_val, $max_val) {
			$min_hex = $this->sanitize_hex_color_or_default($min_hex, '#f0f9e8');
			$max_hex = $this->sanitize_hex_color_or_default($max_hex, '#084081');
			$min_label = esc_html($this->format_number($min_val));
			$max_label = esc_html($this->format_number($max_val));
			$style = 'background: linear-gradient(90deg, ' . esc_attr($min_hex) . ', ' . esc_attr($max_hex) . ');';
			return '<div class="exaig-hm-legend-bar" style="' . esc_attr($style) . '"></div><div class="exaig-hm-legend-labels"><span>' . $min_label . '</span><span>' . $max_label . '</span></div>';
		}

		private function interpolate_color($min_hex, $max_hex, $min_val, $max_val, $value) {
			$min_hex = $this->sanitize_hex_color_or_default($min_hex, '#f0f9e8');
			$max_hex = $this->sanitize_hex_color_or_default($max_hex, '#084081');
			$min_rgb = $this->hex_to_rgb($min_hex);
			$max_rgb = $this->hex_to_rgb($max_hex);
			if ($max_val <= $min_val) {
				$t = 1.0;
			} else {
				$t = ($value - $min_val) / ($max_val - $min_val);
				if ($t < 0) { $t = 0; }
				if ($t > 1) { $t = 1; }
			}
			$r = (int) round($min_rgb[0] + $t * ($max_rgb[0] - $min_rgb[0]));
			$g = (int) round($min_rgb[1] + $t * ($max_rgb[1] - $min_rgb[1]));
			$b = (int) round($min_rgb[2] + $t * ($max_rgb[2] - $min_rgb[2]));
			return sprintf('#%02x%02x%02x', $r, $g, $b);
		}

		private function hex_to_rgb($hex) {
			$hex = ltrim($hex, '#');
			return [
				hexdec(substr($hex, 0, 2)),
				hexdec(substr($hex, 2, 2)),
				hexdec(substr($hex, 4, 2)),
			];
		}
	}

	new EXAIG_Heat_Map_Graph();
}


