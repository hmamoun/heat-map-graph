<?php
/**
 * Export Handler
 *
 * Handles CSV export functionality.
 *
 * @package Heat_Map_Graph
 */

if (!defined('ABSPATH')) {
	exit;
}

class EXAIG_Heat_Map_Graph_Export_Handler {

	/**
	 * Register REST API routes for export.
	 */
	public static function register_routes() {
		if (!EXAIG_Heat_Map_Graph_Premium_Features::has_feature('export')) {
			return;
		}

		register_rest_route('exaig-heatmap/v1', '/export/(?P<id>\d+)', [
			'methods' => 'GET',
			'callback' => [__CLASS__, 'export_csv'],
			'permission_callback' => '__return_true',
			'args' => [
				'id' => [
					'required' => true,
					'type' => 'integer',
					'sanitize_callback' => 'absint',
				],
				'format' => [
					'default' => 'csv',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		]);
	}

	/**
	 * Export heat map data as CSV.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function export_csv($request) {
		$id = $request->get_param('id');
		$format = $request->get_param('format');

		if ($format !== 'csv') {
			return new WP_Error('invalid_format', 'Only CSV format is supported.', ['status' => 400]);
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'heatmap_graphs';
		
		// Table name is validated (plugin prefix + constant string) and safe
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$conf = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $table_name . ' WHERE id = %d', $id), ARRAY_A);

		if (!$conf) {
			return new WP_Error('not_found', 'Heat map not found.', ['status' => 404]);
		}


		// Get data - handle both SQL and external data sources
		$data_source_type = isset($conf['data_source_type']) ? $conf['data_source_type'] : 'sql';
		$results = null;

		if ($data_source_type === 'sql') {
			$sql = $conf['sql_query'];
			$sql = str_replace(['{prefix}', '{{prefix}}'], $wpdb->prefix, $sql);
			
			try {
				// SQL is validated before use and only contains SELECT queries
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$results = $wpdb->get_results($sql, ARRAY_A);
			} catch (Exception $e) {
				return new WP_Error('query_error', 'Failed to execute query: ' . $e->getMessage(), ['status' => 500]);
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
					return new WP_Error('external_data_error', 'Failed to fetch external data: ' . $external_data->get_error_message(), ['status' => 500]);
				}
				$results = $external_data;
			} else {
				return new WP_Error('external_data_unavailable', 'External data sources require Premium.', ['status' => 403]);
			}
		}

		if (empty($results)) {
			return new WP_Error('no_data', 'No data to export.', ['status' => 404]);
		}

		// Limit export size for performance (prevent memory issues)
		$max_rows = 100000;
		if (count($results) > $max_rows) {
			$results = array_slice($results, 0, $max_rows);
		}

		// Generate CSV content
		$filename = sanitize_file_name($conf['name']) . '_' . gmdate('Y-m-d_His') . '.csv';
		
		// Build CSV content
		$csv_content = '';
		
		// Add BOM for Excel compatibility
		$csv_content .= chr(0xEF) . chr(0xBB) . chr(0xBF);

		// Headers
		$headers = [
			$conf['row_field'] ?: 'Row',
			$conf['col_field'] ?: 'Column',
			$conf['value_field'] ?: 'Value'
		];
		
		// Use output buffering to capture CSV
		// php://temp is a memory stream, not a file system operation, so WP_Filesystem doesn't apply
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		ob_start();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$output = fopen('php://temp', 'r+');
		
		fputcsv($output, $headers);

		// Data rows (fputcsv handles escaping automatically)
		foreach ($results as $row) {
			$csv_row = [
				isset($row[$conf['row_field']]) ? (string)$row[$conf['row_field']] : '',
				isset($row[$conf['col_field']]) ? (string)$row[$conf['col_field']] : '',
				isset($row[$conf['value_field']]) ? (string)$row[$conf['value_field']] : '',
			];
			fputcsv($output, $csv_row);
		}
		
		rewind($output);
		$csv_content .= stream_get_contents($output);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose($output);
		ob_end_clean();

		// Return response with CSV content and proper headers
		$response = new WP_REST_Response($csv_content, 200);
		$response->header('Content-Type', 'text/csv; charset=utf-8');
		$response->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
		$response->header('Pragma', 'no-cache');
		$response->header('Expires', '0');
		$response->header('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
		
		return $response;
	}

	/**
	 * Escape CSV value properly.
	 *
	 * @param mixed $value Value to escape.
	 * @return string Escaped value.
	 */
	private static function escape_csv_value($value) {
		if (is_null($value)) {
			return '';
		}
		
		// Convert to string
		$value = (string)$value;
		
		// If value contains comma, quote, or newline, wrap in quotes and escape quotes
		if (strpos($value, ',') !== false || strpos($value, '"') !== false || strpos($value, "\n") !== false) {
			$value = '"' . str_replace('"', '""', $value) . '"';
		}
		
		return $value;
	}

	/**
	 * Render export button.
	 *
	 * @param int $heatmap_id Heat map ID.
	 * @return string HTML.
	 */
	public static function render_export_button($heatmap_id) {
		if (!EXAIG_Heat_Map_Graph_Premium_Features::has_feature('export')) {
			return '';
		}

		$export_url = rest_url('exaig-heatmap/v1/export/' . $heatmap_id . '?format=csv');
		$nonce = wp_create_nonce('wp_rest');
		ob_start();
		?>
		<div class="exaig-export-wrapper">
			<button type="button" class="button button-secondary exaig-export-btn" data-heatmap-id="<?php echo esc_attr($heatmap_id); ?>" data-export-url="<?php echo esc_url($export_url); ?>">
				<span class="dashicons dashicons-download" style="vertical-align:middle;margin-right:4px;"></span>
				Export as CSV
			</button>
			<span class="exaig-export-loading" style="margin-left:8px;">
				<span class="spinner is-active" style="float:none;margin:0;"></span>
				Preparing download...
			</span>
		</div>
		<script type="text/javascript">
		(function() {
			'use strict';
			
			function initExportButtons() {
				// Hide all loading indicators
				var loadingElements = document.querySelectorAll('.exaig-export-loading');
				loadingElements.forEach(function(el) {
					el.style.display = 'none';
				});
				
				// Attach click handlers to all export buttons
				var exportButtons = document.querySelectorAll('.exaig-export-btn');
				exportButtons.forEach(function(btn) {
					// Remove any existing listeners to prevent duplicates
					var newBtn = btn.cloneNode(true);
					btn.parentNode.replaceChild(newBtn, btn);
					
					// Attach click handler
					newBtn.addEventListener('click', function(e) {
						e.preventDefault();
						e.stopPropagation();
						
						var button = this;
						var exportUrl = button.getAttribute('data-export-url');
						var loadingEl = button.parentElement.querySelector('.exaig-export-loading');
						
						// Check if already processing
						if (button.classList.contains('exaig-export-processing') || !exportUrl) {
							return false;
						}
						
						// Mark as processing
						button.classList.add('exaig-export-processing');
						button.disabled = true;
						if (loadingEl) {
							loadingEl.style.display = 'flex';
							loadingEl.classList.add('show');
						}
						
						// Use Fetch API to download the file
						fetch(exportUrl)
							.then(function(response) {
								if (!response.ok) {
									return response.json().then(function(data) {
										throw new Error(data.message || 'Export failed: ' + response.statusText);
									}).catch(function() {
										throw new Error('Export failed: ' + response.statusText);
									});
								}
								
								// Try to get filename from Content-Disposition header
								var contentDisposition = response.headers.get('Content-Disposition');
								var filename = 'export_' + new Date().getTime() + '.csv';
								if (contentDisposition) {
									var filenameMatch = contentDisposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/);
									if (filenameMatch && filenameMatch[1]) {
										filename = filenameMatch[1].replace(/['"]/g, '');
									}
								}
								
								return response.blob().then(function(blob) {
									return { blob: blob, filename: filename };
								});
							})
							.then(function(data) {
								// Create a temporary URL for the blob
								var url = window.URL.createObjectURL(data.blob);
								
								// Create a temporary anchor element to trigger download
								var tempLink = document.createElement('a');
								tempLink.href = url;
								tempLink.download = data.filename;
								tempLink.style.display = 'none';
								document.body.appendChild(tempLink);
								tempLink.click();
								
								// Clean up
								setTimeout(function() {
									if (tempLink.parentNode) {
										document.body.removeChild(tempLink);
									}
									window.URL.revokeObjectURL(url);
									button.classList.remove('exaig-export-processing');
									button.disabled = false;
									if (loadingEl) {
										loadingEl.style.display = 'none';
										loadingEl.classList.remove('show');
									}
								}, 100);
							})
							.catch(function(error) {
								console.error('Export error:', error);
								alert('Failed to export CSV: ' + error.message);
								button.classList.remove('exaig-export-processing');
								button.disabled = false;
								if (loadingEl) {
									loadingEl.style.display = 'none';
									loadingEl.classList.remove('show');
								}
							});
					});
				});
			}
			
			// Initialize when DOM is ready
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', initExportButtons);
			} else {
				// DOM already ready, initialize immediately
				initExportButtons();
			}
			
			// Also initialize after a short delay to catch dynamically added buttons
			setTimeout(initExportButtons, 500);
			
			// Use MutationObserver to catch dynamically added buttons
			if (typeof MutationObserver !== 'undefined') {
				var observer = new MutationObserver(function(mutations) {
					var shouldReinit = false;
					mutations.forEach(function(mutation) {
						if (mutation.addedNodes.length > 0) {
							for (var i = 0; i < mutation.addedNodes.length; i++) {
								var node = mutation.addedNodes[i];
								if (node.nodeType === 1) { // Element node
									if (node.classList && node.classList.contains('exaig-export-btn')) {
										shouldReinit = true;
										break;
									}
									if (node.querySelector && node.querySelector('.exaig-export-btn')) {
										shouldReinit = true;
										break;
									}
								}
							}
						}
					});
					if (shouldReinit) {
						setTimeout(initExportButtons, 100);
					}
				});
				
				observer.observe(document.body, {
					childList: true,
					subtree: true
				});
			}
		})();
		</script>
		<?php
		return ob_get_clean();
	}
}

// Register routes on REST API init
add_action('rest_api_init', ['EXAIG_Heat_Map_Graph_Export_Handler', 'register_routes']);

