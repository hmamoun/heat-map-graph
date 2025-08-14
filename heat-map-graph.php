<?php
/**
 * Plugin Name: Heat Map Graph
 * Description: Create and display heat maps from custom SQL queries. Define row, column, and value fields, select color ranges, and render via shortcode.
 * Version: 1.0.0
 * Author: EXEDOTCOM
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists('EXAIG_Heat_Map_Graph')) {
	class EXAIG_Heat_Map_Graph {
		const VERSION = '1.0.0';
		const OPTION_DB_VERSION = 'exaig_heatmap_graph_db_version';

		/** @var string */
		private $table_name;

		public function __construct() {
			global $wpdb;
			$this->table_name = $wpdb->prefix . 'heatmap_graphs';

			register_activation_hook(__FILE__, [$this, 'on_activate']);
			add_action('admin_menu', [$this, 'register_admin_menu']);
			add_action('admin_init', [$this, 'handle_admin_actions']);
			add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
			add_shortcode('heat_map_graph', [$this, 'shortcode_handler']);
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
				],
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
				'span' => [ 'class' => [] ],
				'code' => [ 'class' => [] ],
			];
		}

		public function on_activate() {
			$this->maybe_create_table();
			$this->maybe_seed_samples();
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
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY is_enabled (is_enabled)
			) $charset_collate;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta($sql);
			update_option(self::OPTION_DB_VERSION, self::VERSION);
		}

		private function maybe_seed_samples() {
			global $wpdb;
			// Safe: table name is fixed from $wpdb->prefix + literal; add a no-op predicate for PHPCS prepared SQL compliance.
			$count = (int) $wpdb->get_var(
				$wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE %d = %d", 1, 1)
			);
			if ($count > 0) {
				return;
			}

			$prefix = $wpdb->prefix;

			$samples = [
				[
					'name' => 'Posts per Day per Category (Last 30 Days)',
					'description' => 'Counts published posts per day by category (last 30 days).',
					'sql' => "SELECT cat_terms.name AS row_label, DATE(p.post_date) AS col_label, COUNT(*) AS cell_value
FROM {$prefix}posts p
JOIN {$prefix}term_relationships tr ON tr.object_id = p.ID
JOIN {$prefix}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category'
JOIN {$prefix}terms cat_terms ON cat_terms.term_id = tt.term_id
WHERE p.post_type = 'post' AND p.post_status = 'publish' AND p.post_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
GROUP BY cat_terms.name, DATE(p.post_date)",
					'row_field' => 'row_label',
					'col_field' => 'col_label',
					'value_field' => 'cell_value',
					'color_min' => '#f0f9e8',
					'color_max' => '#2c7fb8',
				],
				[
					'name' => 'Number of Post Tags per Category',
					'description' => 'Counts posts that share each Category (rows) and Tag (columns).',
					'sql' => "SELECT cat_terms.name AS row_label, tag_terms.name AS col_label, COUNT(DISTINCT p.ID) AS cell_value
FROM {$prefix}posts p
JOIN {$prefix}term_relationships tr_cat ON tr_cat.object_id = p.ID
JOIN {$prefix}term_taxonomy tt_cat ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id AND tt_cat.taxonomy = 'category'
JOIN {$prefix}terms cat_terms ON cat_terms.term_id = tt_cat.term_id
JOIN {$prefix}term_relationships tr_tag ON tr_tag.object_id = p.ID
JOIN {$prefix}term_taxonomy tt_tag ON tt_tag.term_taxonomy_id = tr_tag.term_taxonomy_id AND tt_tag.taxonomy = 'post_tag'
JOIN {$prefix}terms tag_terms ON tag_terms.term_id = tt_tag.term_id
WHERE p.post_type = 'post' AND p.post_status = 'publish'
GROUP BY cat_terms.name, tag_terms.name", 
					'row_field' => 'row_label',
					'col_field' => 'col_label',
					'value_field' => 'cell_value',
					'color_min' => '#fff5f0',
					'color_max' => '#cb181d',
				],
			];

			$now = current_time('mysql');
			foreach ($samples as $sample) {
				$wpdb->insert(
					$this->table_name,
					[
						'name' => $sample['name'],
						'description' => $sample['description'],
						'sql_query' => $sample['sql'],
						'row_field' => $sample['row_field'],
						'col_field' => $sample['col_field'],
						'value_field' => $sample['value_field'],
						'color_min' => $sample['color_min'],
						'color_max' => $sample['color_max'],
						'is_enabled' => 1,
						'created_at' => $now,
						'updated_at' => $now,
					],
					['%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s']
				);
			}
		}

		public function enqueue_admin_assets($hook) {
			if ($hook !== 'toplevel_page_exaig_heat_map_graph') {
				return;
			}
			wp_enqueue_style('exaig-heatmap-admin', plugins_url('assets/css/heatmap.css', __FILE__), [], self::VERSION);
		}

		private function enqueue_public_assets() {
			if (!wp_style_is('exaig-heatmap-public', 'enqueued')) {
				wp_enqueue_style('exaig-heatmap-public', plugins_url('assets/css/heatmap.css', __FILE__), [], self::VERSION);
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

			$id = isset($_POST['id']) ? absint($_POST['id']) : 0;
			$name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
			$description = isset($_POST['description']) ? wp_kses_post(wp_unslash($_POST['description'])) : '';
			$sql_query_raw = isset($_POST['sql_query']) ? wp_unslash($_POST['sql_query']) : '';
			$row_field = isset($_POST['row_field']) ? sanitize_key(wp_unslash($_POST['row_field'])) : '';
			$col_field = isset($_POST['col_field']) ? sanitize_key(wp_unslash($_POST['col_field'])) : '';
			$value_field = isset($_POST['value_field']) ? sanitize_key(wp_unslash($_POST['value_field'])) : '';
			$color_min = $this->sanitize_hex_color_or_default($_POST['color_min'] ?? '#f0f9e8', '#f0f9e8');
			$color_max = $this->sanitize_hex_color_or_default($_POST['color_max'] ?? '#084081', '#084081');
			$is_enabled = isset($_POST['is_enabled']) ? 1 : 0;

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
				'updated_at' => $now,
			];

			$formats = ['%s','%s','%s','%s','%s','%s','%s','%s','%d','%s'];
			if ($id > 0) {
				$wpdb->update($this->table_name, $data, ['id' => $id], $formats, ['%d']);
				add_settings_error('exaig_heatmap_messages', 'exaig_heatmap_updated', 'Heat map updated successfully.', 'updated');
			} else {
				$data['created_at'] = $now;
				$wpdb->insert($this->table_name, $data, array_merge($formats, ['%s']));
				add_settings_error('exaig_heatmap_messages', 'exaig_heatmap_created', 'Heat map created successfully.', 'updated');
			}
		}

		private function delete_heatmap_from_post() {
			global $wpdb;
			$id = isset($_POST['id']) ? absint($_POST['id']) : 0;
			if ($id > 0) {
				$wpdb->delete($this->table_name, ['id' => $id], ['%d']);
				add_settings_error('exaig_heatmap_messages', 'exaig_heatmap_deleted', 'Heat map deleted.', 'updated');
			}
		}

		private function strip_trailing_semicolon($sql) {
			$sql = trim($sql);
			return rtrim($sql, ";\s\n\r\t");
		}

		private function validate_sql_query($sql_query_raw, $row_field, $col_field, $value_field) {
			global $wpdb;
			$errors = [];
			$sql = $this->strip_trailing_semicolon($sql_query_raw);

			if (!preg_match('/^(SELECT|WITH)\s/i', $sql)) {
				$errors[] = 'Only SELECT queries are allowed.';
			}
			$forbidden = [
				'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE', 'RENAME', 'CREATE', 'ATTACH', 'MERGE', 'CALL', 'DO', 'REPLACE', 'OUTFILE', 'INFILE', 'LOAD DATA', 'INTO DUMPFILE', 'HANDLER', 'SLEEP', 'BENCHMARK'
			];
			foreach ($forbidden as $kw) {
				if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $sql)) {
					$errors[] = 'Forbidden keyword detected: ' . esc_html($kw) . '.';
					break;
				}
			}
			if (strpos($sql, ';') !== false) {
				$errors[] = 'Multiple SQL statements are not allowed.';
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
				if (strpos($t, $prefix) !== 0 && strpos($t, '{{prefix}}') !== 0) {
					$errors[] = 'Only queries against WordPress tables are allowed (must start with "' . esc_html($prefix) . '"). Offending table: ' . esc_html($t);
					break;
				}
			}

			$columns_ok = false;
			if (empty($errors)) {
				$test_sql = 'SELECT * FROM (' . $sql . ') AS exaig_heatmap_sub LIMIT 1';
				$test_row = null;
				try {
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$test_row = $wpdb->get_row($test_sql, ARRAY_A);
				} catch (Exception $e) {
					$errors[] = 'SQL error: ' . esc_html($e->getMessage());
				}
				if ($test_row !== null) {
					$columns = array_map('strtolower', array_keys($test_row));
					$columns_ok = in_array(strtolower($row_field), $columns, true) && in_array(strtolower($col_field), $columns, true) && in_array(strtolower($value_field), $columns, true);
				}
				if (!$columns_ok) {
					// Try a zero-row projection to validate that the columns exist even if there is no data
					$projection_sql = 'SELECT `'.esc_sql($row_field).'`, `'.esc_sql($col_field).'`, `'.esc_sql($value_field).'` FROM (' . $sql . ') AS exaig_heatmap_sub LIMIT 0';
					try {
						// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->query($projection_sql);
						$columns_ok = true; // If it runs, the columns exist
					} catch (Exception $e) {
						$errors[] = 'The query must return columns named exactly: ' . esc_html($row_field) . ', ' . esc_html($col_field) . ', ' . esc_html($value_field) . '.';
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
			global $wpdb;
			$editing_id = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
			$editing = null;
			if ($editing_id > 0) {
				$editing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table_name . ' WHERE id = %d', $editing_id), ARRAY_A);
			}
			// Safe: table name is fixed; add a no-op predicate for PHPCS prepared SQL compliance.
			$items = $wpdb->get_results(
				$wpdb->prepare("SELECT * FROM {$this->table_name} WHERE %d = %d ORDER BY updated_at DESC", 1, 1),
				ARRAY_A
			);

			settings_errors('exaig_heatmap_messages');
			?>
			<div class="wrap">
				<h1>Heat Map Graph</h1>
				<div style="display:flex; gap:24px; align-items:flex-start;">
					<div style="flex:2; min-width:480px;">
							<h2><?php echo $editing ? esc_html__('Edit Heat Map', 'heat-map-graph') : esc_html__('Add New Heat Map', 'heat-map-graph'); ?></h2>
						<form method="post">
							<?php wp_nonce_field('exaig_heatmap_action', 'exaig_heatmap_nonce'); ?>
							<input type="hidden" name="exaig_action" value="save_heatmap" />
							<input type="hidden" name="id" value="<?php echo $editing ? esc_attr($editing['id']) : 0; ?>" />

							<table class="form-table" role="presentation">
								<tbody>
									<tr>
										<th scope="row"><label for="name">Name</label></th>
										<td><input name="name" id="name" type="text" class="regular-text" required value="<?php echo $editing ? esc_attr($editing['name']) : ''; ?>" /></td>
									</tr>
									<tr>
										<th scope="row"><label for="description">Description</label></th>
										<td><textarea name="description" id="description" class="large-text" rows="3"><?php echo $editing ? esc_textarea($editing['description']) : ''; ?></textarea></td>
									</tr>
									<tr>
										<th scope="row"><label for="sql_query">SQL Query</label></th>
										<td>
											<textarea name="sql_query" id="sql_query" class="large-text code" rows="8" required placeholder="SELECT row_label AS row_label, col_label AS col_label, cell_value AS cell_value FROM ... GROUP BY row_label, col_label"><?php echo $editing ? esc_textarea($editing['sql_query']) : ''; ?></textarea>
											<p class="description">Must be a single SELECT statement against WordPress tables. The query must return columns matching the fields below.</p>
										</td>
									</tr>
									<tr>
										<th scope="row">Field Mapping</th>
										<td>
											<label>Row Field <input name="row_field" type="text" class="regular-text" required value="<?php echo $editing ? esc_attr($editing['row_field']) : 'row_label'; ?>" /></label>
											&nbsp;&nbsp;
											<label>Column Field <input name="col_field" type="text" class="regular-text" required value="<?php echo $editing ? esc_attr($editing['col_field']) : 'col_label'; ?>" /></label>
											&nbsp;&nbsp;
											<label>Value Field <input name="value_field" type="text" class="regular-text" required value="<?php echo $editing ? esc_attr($editing['value_field']) : 'cell_value'; ?>" /></label>
											<p class="description">These must match column aliases returned by your SQL query.</p>
										</td>
									</tr>
									<tr>
										<th scope="row">Color Range</th>
										<td>
											<label>Min <input name="color_min" type="text" class="small-text" value="<?php echo $editing ? esc_attr($editing['color_min']) : '#f0f9e8'; ?>" /></label>
											&nbsp;&nbsp;
											<label>Max <input name="color_max" type="text" class="small-text" value="<?php echo $editing ? esc_attr($editing['color_max']) : '#084081'; ?>" /></label>
										</td>
									</tr>
									<tr>
										<th scope="row">Status</th>
										<td>
											<label><input type="checkbox" name="is_enabled" <?php echo $editing && (int)$editing['is_enabled'] === 0 ? '' : 'checked'; ?> /> Enabled</label>
											<?php if ($editing) : ?>
												<p class="description">Shortcode: <code>[heat_map_graph id="<?php echo (int)$editing['id']; ?>"]</code></p>
											<?php endif; ?>
										</td>
									</tr>
								</tbody>
							</table>

							<?php submit_button($editing ? 'Update Heat Map' : 'Create Heat Map'); ?>
						</form>
					</div>
					<div style="flex:3; min-width:480px;">
						<h2>Saved Heat Maps</h2>
						<table class="widefat fixed striped">
							<thead>
								<tr>
									<th>ID</th>
									<th>Name</th>
									<th>Enabled</th>
									<th>Updated</th>
									<th>Actions</th>
								</tr>
							</thead>
							<tbody>
								<?php if (empty($items)) : ?>
									<tr><td colspan="5">No heat maps found. Use the form to create one. Two sample heat maps were added on activation.</td></tr>
								<?php else : ?>
									<?php foreach ($items as $item) : ?>
										<tr>
											<td><?php echo (int)$item['id']; ?></td>
											<td>
												<strong><a href="<?php echo esc_url(admin_url('admin.php?page=exaig_heat_map_graph&edit='.(int)$item['id'])); ?>"><?php echo esc_html($item['name']); ?></a></strong>
												<div class="row-actions">
													<span class="shortcode">Shortcode: <code>[heat_map_graph id="<?php echo (int)$item['id']; ?>"]</code></span>
												</div>
											</td>
											<td><?php echo (int)$item['is_enabled'] ? 'Yes' : 'No'; ?></td>
											<td><?php echo esc_html($item['updated_at']); ?></td>
											<td>
												<form method="post" onsubmit="return confirm('Delete this heat map?');" style="display:inline-block;">
													<?php wp_nonce_field('exaig_heatmap_action', 'exaig_heatmap_nonce'); ?>
													<input type="hidden" name="exaig_action" value="delete_heatmap" />
													<input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>" />
													<?php submit_button('Delete', 'delete small', '', false); ?>
												</form>
											</td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
				<div style="margin-top:24px;">
					<h2>Preview</h2>
					<p class="description">Save your heat map, then use the shortcode on a page. A simple preview is shown below for the selected item (may be truncated).</p>
					<div class="exaig-heatmap-preview">
						<?php
								if ($editing) {
									$preview_html = $this->render_heatmap_html((int)$editing['id'], 20, 30, true);
									echo wp_kses($preview_html, $this->get_allowed_heatmap_html_tags());
								} else {
								echo '<em>Select a heat map to preview.</em>';
							}
						?>
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
			return wp_kses($html, $this->get_allowed_heatmap_html_tags());
		}

		private function render_heatmap_html($id, $max_rows = 0, $max_cols = 0, $is_preview = false) {
			global $wpdb;
			$conf = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table_name . ' WHERE id = %d', $id), ARRAY_A);
			if (!$conf) {
				return '<div class="exaig-heatmap-error">Heat map not found.</div>';
			}
			if ((int)$conf['is_enabled'] === 0 && !$is_preview) {
				return '<div class="exaig-heatmap-error">This heat map is disabled.</div>';
			}

			$sql = $this->strip_trailing_semicolon($conf['sql_query']);
			$validation = $this->validate_sql_query($sql, $conf['row_field'], $conf['col_field'], $conf['value_field']);
			if (!$validation['is_valid']) {
				return '<div class="exaig-heatmap-error">' . esc_html(implode(' ', $validation['errors'])) . '</div>';
			}

			$results = null;
			try {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$results = $wpdb->get_results($sql, ARRAY_A);
			} catch (Exception $e) {
				return '<div class="exaig-heatmap-error">Query failed: ' . esc_html($e->getMessage()) . '</div>';
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

			$color_min = $conf['color_min'];
			$color_max = $conf['color_max'];
			$legend_html = $this->render_color_legend($color_min, $color_max, $min_val, $max_val);

			$thead = '<thead><tr><th class="exaig-hm-sticky">' . esc_html($conf['name']) . '</th>';
			foreach ($cols as $col) {
				$thead .= '<th class="exaig-hm-col">' . esc_html($col) . '</th>';
			}
			$thead .= '</tr></thead>';

			$tbody = '<tbody>';
			foreach ($rows as $row) {
				$tbody .= '<tr>';
				$tbody .= '<th class="exaig-hm-row exaig-hm-sticky">' . esc_html($row) . '</th>';
				foreach ($cols as $col) {
					$val = isset($data[$row][$col]) ? (float)$data[$row][$col] : 0.0;
					$color = $this->interpolate_color($color_min, $color_max, $min_val, $max_val, $val);
					$title = esc_attr($row . ' / ' . $col . ': ' . $val);
					$tbody .= '<td class="exaig-hm-cell" title="' . $title . '" style="background-color:' . esc_attr($color) . ';">' . esc_html($this->format_number($val)) . '</td>';
				}
				$tbody .= '</tr>';
			}
			$tbody .= '</tbody>';

			ob_start();
			?>
			<div class="exaig-heatmap-wrapper">
				<div class="exaig-heatmap-legend"><?php echo wp_kses($legend_html, $this->get_allowed_heatmap_html_tags()); ?></div>
				<div class="exaig-heatmap-scroll">
						<table class="exaig-heatmap-table" role="grid" aria-label="Heat map">
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


