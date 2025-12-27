<?php
/**
 * Chart Linking
 *
 * Handles linked charts functionality.
 *
 * @package Heat_Map_Graph
 */

if (!defined('ABSPATH')) {
	exit;
}

class EXAIG_Heat_Map_Graph_Chart_Linking {

	/**
	 * Initialize chart linking for a group.
	 *
	 * @param string $linked_group Group identifier.
	 * @param int    $chart_id Chart ID.
	 * @param string $canvas_id Canvas element ID.
	 * @return string JavaScript initialization code.
	 */
	public static function init_linking($linked_group, $chart_id, $canvas_id = '') {
		if (!EXAIG_Heat_Map_Graph_Premium_Features::has_feature('linked_charts')) {
			return '';
		}

		if (empty($linked_group)) {
			return '';
		}

		ob_start();
		?>
		<script>
		(function() {
			if (typeof window.exaigChartLinking === 'undefined') {
				window.exaigChartLinking = {};
			}
			
			var group = '<?php echo esc_js($linked_group); ?>';
			var chartId = '<?php echo esc_js($chart_id); ?>';
			var canvasId = '<?php echo esc_js($canvas_id); ?>';
			
			// Initialize group if it doesn't exist
			if (!window.exaigChartLinking[group]) {
				window.exaigChartLinking[group] = {
					charts: [],
					selectedData: null,
					filters: {
						rows: [],
						cols: [],
						valueRange: { min: null, max: null }
					}
				};
			}
			
			// Register this chart
			var chartInfo = {
				id: chartId,
				canvasId: canvasId,
				instance: null
			};
			
			window.exaigChartLinking[group].charts.push(chartInfo);
			
			// Wait for chart to be initialized
			setTimeout(function() {
				if (canvasId) {
					var canvas = document.getElementById(canvasId);
					if (canvas && canvas.chart) {
						chartInfo.instance = canvas.chart;
						setupChartLinking(chartInfo, group);
					}
				}
			}, 1000);
			
			function setupChartLinking(chartInfo, group) {
				if (!chartInfo.instance) return;
				
				var chart = chartInfo.instance;
				var canvas = document.getElementById(chartInfo.canvasId);
				if (!canvas) return;
				
				// Listen for clicks on this chart
				canvas.addEventListener('click', function(evt) {
					var points = chart.getElementsAtEventForMode(evt, 'nearest', { intersect: true }, true);
					if (points.length) {
						var point = points[0];
						var index = point.index;
						var label = chart.data.labels[index];
						
						// Parse label to get row/col
						var parts = label.split(' / ');
						var row = parts[0] || '';
						var col = parts[1] || '';
						
						// Update filters for the group
						var groupData = window.exaigChartLinking[group];
						groupData.filters.rows = [row];
						groupData.filters.cols = [col];
						
						// Apply filters to all charts in group
						applyGroupFilters(group);
					}
				});
				
				// Listen for external filter changes
				canvas.addEventListener('exaigFilterChange', function(event) {
					var groupData = window.exaigChartLinking[group];
					groupData.filters = event.detail.filters || groupData.filters;
					applyGroupFilters(group);
				});
			}
			
			function applyGroupFilters(group) {
				var groupData = window.exaigChartLinking[group];
				if (!groupData) return;
				
				groupData.charts.forEach(function(chartInfo) {
					if (!chartInfo.instance) return;
					
					var chart = chartInfo.instance;
					var filters = groupData.filters;
					
					// Filter chart data
					var filteredLabels = [];
					var filteredData = [];
					var filteredColors = [];
					
					for (var i = 0; i < chart.data.labels.length; i++) {
						var label = chart.data.labels[i];
						var value = chart.data.datasets[0].data[i];
						var parts = label.split(' / ');
						var row = parts[0] || '';
						var col = parts[1] || '';
						
						var showRow = filters.rows.length === 0 || filters.rows.indexOf(row) !== -1;
						var showCol = filters.cols.length === 0 || filters.cols.indexOf(col) !== -1;
						var showValue = true;
						
						if (filters.valueRange.min !== null && value < filters.valueRange.min) {
							showValue = false;
						}
						if (filters.valueRange.max !== null && value > filters.valueRange.max) {
							showValue = false;
						}
						
						if (showRow && showCol && showValue) {
							filteredLabels.push(label);
							filteredData.push(value);
							if (chart.data.datasets[0].backgroundColor && chart.data.datasets[0].backgroundColor[i]) {
								filteredColors.push(chart.data.datasets[0].backgroundColor[i]);
							}
						}
					}
					
					chart.data.labels = filteredLabels;
					chart.data.datasets[0].data = filteredData;
					if (filteredColors.length > 0) {
						chart.data.datasets[0].backgroundColor = filteredColors;
					}
					chart.update('active');
				});
			}
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render linked charts dashboard.
	 *
	 * @param array $chart_ids Array of chart IDs to link.
	 * @param string $linked_group Group identifier.
	 * @return string HTML output.
	 */
	public static function render_dashboard($chart_ids, $linked_group) {
		if (!EXAIG_Heat_Map_Graph_Premium_Features::has_feature('linked_charts')) {
			return EXAIG_Heat_Map_Graph_Premium_Features::render_upgrade_notice(
				'Linked Charts Dashboard',
				'Upgrade to Premium to create dashboards with multiple linked charts that filter each other.'
			);
		}

		ob_start();
		?>
		<div class="exaig-linked-charts-dashboard" data-linked-group="<?php echo esc_attr($linked_group); ?>">
			<div class="exaig-dashboard-header">
				<h3>Linked Charts Dashboard</h3>
				<p class="description">Charts in this group are linked - clicking on one chart filters the others.</p>
			</div>
			<div class="exaig-dashboard-charts">
				<?php foreach ($chart_ids as $chart_id) : ?>
					<div class="exaig-dashboard-chart-item">
						<?php echo do_shortcode('[heat_map_graph id="' . esc_attr($chart_id) . '"]'); ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}

