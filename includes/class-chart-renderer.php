<?php
/**
 * Chart Renderer
 *
 * Handles rendering of different chart types (bar, pie, line).
 *
 * @package Heat_Map_Graph
 */

if (!defined('ABSPATH')) {
	exit;
}

class EXAIG_Heat_Map_Graph_Chart_Renderer {

	/**
	 * Render chart based on chart type.
	 *
	 * @param array  $data Chart data.
	 * @param string $chart_type Chart type (heatmap, bar, pie, line).
	 * @param array  $config Chart configuration.
	 * @return string HTML output.
	 */
	public static function render($data, $chart_type = 'heatmap', $config = []) {
		if (!EXAIG_Heat_Map_Graph_Premium_Features::has_feature('charts')) {
			// In preview mode, don't show the upgrade notice
			$is_preview = isset($config['is_preview']) && $config['is_preview'];
			if ($is_preview) {
				return '';
			}
			return EXAIG_Heat_Map_Graph_Premium_Features::render_upgrade_notice(
				'Multiple Chart Types',
				'Upgrade to Premium to use bar charts, pie charts, and line charts.'
			);
		}

		// Validate data structure
		if (empty($data) || !is_array($data)) {
			return '<div class="exaig-heatmap-error">No data available to render chart.</div>';
		}

		// Normalize data structure - ensure it's in $data[$row][$col] format
		$normalized_data = self::normalize_data($data);
		if (empty($normalized_data)) {
			return '<div class="exaig-heatmap-error">Invalid data structure for chart rendering.</div>';
		}

		switch ($chart_type) {
			case 'bar':
				return self::render_bar_chart($normalized_data, $config);
			case 'pie':
				return self::render_pie_chart($normalized_data, $config);
			case 'line':
				return self::render_line_chart($normalized_data, $config);
			case 'heatmap':
			default:
				return ''; // Heat map is rendered by main class
		}
	}

	/**
	 * Normalize data structure to ensure consistent format.
	 *
	 * @param array $data Raw data.
	 * @return array Normalized data in $data[$row][$col] format.
	 */
	private static function normalize_data($data) {
		$normalized = [];

		// Check if data is already in correct format
		$first_key = array_key_first($data);
		if ($first_key !== null && is_array($data[$first_key])) {
			// Already in $data[$row][$col] format
			return $data;
		}

		// Try to normalize from other formats
		foreach ($data as $key => $value) {
			if (is_array($value)) {
				$normalized[$key] = $value;
			} elseif (is_numeric($value)) {
				// Single level array - treat as row with single column
				$normalized[$key] = ['value' => $value];
			}
		}

		return $normalized;
	}

	/**
	 * Render bar chart.
	 *
	 * @param array $data Chart data.
	 * @param array $config Configuration.
	 * @return string HTML.
	 */
	private static function render_bar_chart($data, $config) {
		$chart_id = 'exaig-chart-' . uniqid();
		$orientation = isset($config['orientation']) ? $config['orientation'] : 'vertical';
		$color_min = isset($config['color_min']) ? $config['color_min'] : '#3b82f6';
		$color_max = isset($config['color_max']) ? $config['color_max'] : '#1e40af';
		
		// Transform data for Chart.js
		$labels = [];
		$values = [];
		$tooltips = [];
		$dataset_label = 'Values'; // Default label
		
		// Check if col_label is static (same value for all rows)
		$col_values = [];
		foreach ($data as $row => $cols) {
			if (is_array($cols)) {
				foreach ($cols as $col => $value) {
					$col_values[] = $col;
				}
			}
		}
		$unique_cols = array_unique($col_values);
		$is_static_col = count($unique_cols) === 1;
		
		// Use column label as dataset label if it's static
		if ($is_static_col && !empty($unique_cols)) {
			$dataset_label = reset($unique_cols);
		}
		
		foreach ($data as $row => $cols) {
			if (!is_array($cols)) {
				continue;
			}
			foreach ($cols as $col => $value) {
				$numeric_value = is_numeric($value) ? (float)$value : 0.0;
				// For bar charts, if col_label is static, use only row_label
				if ($is_static_col) {
					$labels[] = esc_js($row);
					$tooltips[] = esc_js($row) . ': ' . number_format($numeric_value, 2);
				} else {
					$labels[] = esc_js($row) . ' / ' . esc_js($col);
					$tooltips[] = esc_js($row) . ' / ' . esc_js($col) . ': ' . number_format($numeric_value, 2);
				}
				$values[] = $numeric_value;
			}
		}

		if (empty($labels)) {
			return '<div class="exaig-heatmap-error">No data available for bar chart.</div>';
		}

		// Generate color gradient
		$colors = self::generate_color_gradient($color_min, $color_max, count($values));

		ob_start();
		?>
		<div class="exaig-chart-wrapper" data-chart-type="bar" data-chart-id="<?php echo esc_attr($chart_id); ?>" style="position: relative; height: 400px;">
			<div class="exaig-chart-loading" id="<?php echo esc_attr($chart_id); ?>-loading">
				<div class="exaig-loading-spinner"></div>
				<div class="exaig-loading-text">Loading chart...</div>
			</div>
			<canvas id="<?php echo esc_attr($chart_id); ?>" style="display: none;"></canvas>
		</div>
		<script>
		(function() {
			var maxRetries = 50; // 5 seconds max wait
			var retryCount = 0;
			var chartId = '<?php echo esc_js($chart_id); ?>';
			
			function initBarChart() {
				retryCount++;
				
				if (typeof Chart === 'undefined') {
					if (retryCount < maxRetries) {
						setTimeout(initBarChart, 100);
					} else {
						console.error('Chart.js failed to load for chart: ' + chartId);
						// Try to load Chart.js manually as fallback
						var script = document.createElement('script');
						script.src = 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js';
						script.onload = function() {
							setTimeout(initBarChart, 100);
						};
						document.head.appendChild(script);
					}
					return;
				}
				
				var ctx = document.getElementById(chartId);
				if (!ctx) {
					if (retryCount < maxRetries) {
						setTimeout(initBarChart, 100);
					} else {
						console.error('Canvas element not found: ' + chartId);
						// Show error message
						var loadingEl = document.getElementById(chartId + '-loading');
						if (loadingEl) {
							loadingEl.innerHTML = '<div class="exaig-loading-error">Failed to load chart</div>';
						}
					}
					return;
				}
				
				try {
					// Hide loading indicator and show canvas
					var loadingEl = document.getElementById(chartId + '-loading');
					if (loadingEl) {
						loadingEl.style.display = 'none';
					}
					ctx.style.display = 'block';
					
					var chartInstance = new Chart(ctx, {
					type: '<?php echo esc_js($orientation === 'horizontal' ? 'bar' : 'bar'); ?>',
					data: {
						labels: <?php echo wp_json_encode($labels); ?>,
						datasets: [{
							label: <?php echo wp_json_encode($dataset_label); ?>,
							data: <?php echo wp_json_encode($values); ?>,
							backgroundColor: <?php echo wp_json_encode($colors); ?>,
							borderColor: '<?php echo esc_js($color_max); ?>',
							borderWidth: 1
						}]
					},
					options: {
						responsive: true,
						maintainAspectRatio: false,
						animation: {
							duration: 1000,
							easing: 'easeInOutQuart'
						},
						plugins: {
							tooltip: {
								enabled: true,
								mode: 'index',
								intersect: false,
								backgroundColor: 'rgba(0, 0, 0, 0.8)',
								padding: 12,
								titleFont: {
									size: 14,
									weight: 'bold'
								},
								bodyFont: {
									size: 13
								},
								borderColor: 'rgba(255, 255, 255, 0.1)',
								borderWidth: 1,
								cornerRadius: 6,
								displayColors: true,
								callbacks: {
									title: function(context) {
										return context[0].label || '';
									},
									label: function(context) {
										return context.dataset.label + ': ' + context.parsed.y.toLocaleString();
									}
								}
							},
							legend: {
								display: false
							}
						},
						scales: {
							<?php if ($orientation === 'horizontal') : ?>
							x: {
								beginAtZero: true
							},
							y: {
								ticks: {
									maxRotation: 45,
									minRotation: 0
								}
							}
							<?php else : ?>
							y: {
								beginAtZero: true,
								ticks: {
									callback: function(value) {
										return value.toLocaleString();
									}
								}
							},
							x: {
								ticks: {
									maxRotation: 45,
									minRotation: 0
								}
							}
							<?php endif; ?>
						},
						onHover: function(event, activeElements) {
							event.native.target.style.cursor = activeElements.length > 0 ? 'pointer' : 'default';
						}
					}
				});
				
				// Store chart instance on canvas for later access
				ctx.chart = chartInstance;
				} catch (error) {
					console.error('Error initializing chart:', error);
				}
			}
			
			// Wait for DOM and Chart.js to be ready
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', initBarChart);
			} else {
				// DOM is ready, but Chart.js might not be loaded yet
				setTimeout(initBarChart, 100);
			}
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Generate color gradient array.
	 *
	 * @param string $min_color Hex color for minimum value.
	 * @param string $max_color Hex color for maximum value.
	 * @param int    $count Number of colors to generate.
	 * @return array Array of hex colors.
	 */
	private static function generate_color_gradient($min_color, $max_color, $count) {
		if ($count <= 0) {
			return [];
		}

		$min_rgb = self::hex_to_rgb($min_color);
		$max_rgb = self::hex_to_rgb($max_color);
		$colors = [];

		for ($i = 0; $i < $count; $i++) {
			$t = $count > 1 ? $i / ($count - 1) : 0;
			$r = (int)round($min_rgb[0] + $t * ($max_rgb[0] - $min_rgb[0]));
			$g = (int)round($min_rgb[1] + $t * ($max_rgb[1] - $min_rgb[1]));
			$b = (int)round($min_rgb[2] + $t * ($max_rgb[2] - $min_rgb[2]));
			$colors[] = sprintf('rgba(%d, %d, %d, 0.7)', $r, $g, $b);
		}

		return $colors;
	}

	/**
	 * Convert hex color to RGB array.
	 *
	 * @param string $hex Hex color.
	 * @return array RGB values [r, g, b].
	 */
	private static function hex_to_rgb($hex) {
		$hex = ltrim($hex, '#');
		if (strlen($hex) === 3) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return [
			hexdec(substr($hex, 0, 2)),
			hexdec(substr($hex, 2, 2)),
			hexdec(substr($hex, 4, 2)),
		];
	}

	/**
	 * Render pie chart.
	 *
	 * @param array $data Chart data.
	 * @param array $config Configuration.
	 * @return string HTML.
	 */
	private static function render_pie_chart($data, $config) {
		$chart_id = 'exaig-chart-' . uniqid();
		$color_min = isset($config['color_min']) ? $config['color_min'] : '#3b82f6';
		$color_max = isset($config['color_max']) ? $config['color_max'] : '#1e40af';
		
		// Transform data for Chart.js
		$labels = [];
		$values = [];
		$total = 0;
		
		// Check if col_label is static (same value for all rows)
		$col_values = [];
		foreach ($data as $row => $cols) {
			if (is_array($cols)) {
				foreach ($cols as $col => $value) {
					$col_values[] = $col;
				}
			}
		}
		$unique_cols = array_unique($col_values);
		$is_static_col = count($unique_cols) === 1;
		
		foreach ($data as $row => $cols) {
			if (!is_array($cols)) {
				continue;
			}
			foreach ($cols as $col => $value) {
				$numeric_value = is_numeric($value) ? (float)$value : 0.0;
				if ($numeric_value > 0) {
					// For pie charts, if col_label is static, use only row_label
					if ($is_static_col) {
						$labels[] = esc_js($row);
					} else {
						$labels[] = esc_js($row) . ' / ' . esc_js($col);
					}
					$values[] = $numeric_value;
					$total += $numeric_value;
				}
			}
		}

		if (empty($labels)) {
			return '<div class="exaig-heatmap-error">No data available for pie chart.</div>';
		}

		// Generate colors
		$colors = self::generate_pie_colors(count($values), $color_min, $color_max);

		ob_start();
		?>
		<div class="exaig-chart-wrapper" data-chart-type="pie" data-chart-id="<?php echo esc_attr($chart_id); ?>" style="position: relative; height: 400px;">
			<div class="exaig-chart-loading" id="<?php echo esc_attr($chart_id); ?>-loading">
				<div class="exaig-loading-spinner"></div>
				<div class="exaig-loading-text">Loading chart...</div>
			</div>
			<canvas id="<?php echo esc_attr($chart_id); ?>" style="display: none;"></canvas>
		</div>
		<script>
		(function() {
			var maxRetries = 50; // 5 seconds max wait
			var retryCount = 0;
			var chartId = '<?php echo esc_js($chart_id); ?>';
			
			function initPieChart() {
				retryCount++;
				
				if (typeof Chart === 'undefined') {
					if (retryCount < maxRetries) {
						setTimeout(initPieChart, 100);
					} else {
						console.error('Chart.js failed to load after ' + (maxRetries * 100) + 'ms');
					}
					return;
				}
				
				var ctx = document.getElementById(chartId);
				if (!ctx) {
					if (retryCount < maxRetries) {
						setTimeout(initPieChart, 100);
					} else {
						console.error('Canvas element not found: ' + chartId);
						// Show error message
						var loadingEl = document.getElementById(chartId + '-loading');
						if (loadingEl) {
							loadingEl.innerHTML = '<div class="exaig-loading-error">Failed to load chart</div>';
						}
					}
					return;
				}
				
				try {
					// Hide loading indicator and show canvas
					var loadingEl = document.getElementById(chartId + '-loading');
					if (loadingEl) {
						loadingEl.style.display = 'none';
					}
					ctx.style.display = 'block';
					
					var chartInstance = new Chart(ctx, {
					type: 'pie',
					data: {
						labels: <?php echo wp_json_encode($labels); ?>,
						datasets: [{
							data: <?php echo wp_json_encode($values); ?>,
							backgroundColor: <?php echo wp_json_encode($colors); ?>,
							borderWidth: 2,
							borderColor: '#ffffff'
						}]
					},
					options: {
						responsive: true,
						maintainAspectRatio: false,
						animation: {
							animateRotate: true,
							animateScale: true,
							duration: 1000,
							easing: 'easeInOutQuart'
						},
						plugins: {
							tooltip: {
								enabled: true,
								backgroundColor: 'rgba(0, 0, 0, 0.8)',
								padding: 12,
								titleFont: {
									size: 14,
									weight: 'bold'
								},
								bodyFont: {
									size: 13
								},
								borderColor: 'rgba(255, 255, 255, 0.1)',
								borderWidth: 1,
								cornerRadius: 6,
								callbacks: {
									title: function(context) {
										return context[0].label || '';
									},
									label: function(context) {
										var label = context.label || '';
										var value = context.parsed || 0;
										var total = context.dataset.data.reduce(function(a, b) { return a + b; }, 0);
										var percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
										return label + ': ' + value.toLocaleString() + ' (' + percentage + '%)';
									}
								}
							},
							legend: {
								position: 'right',
								labels: {
									padding: 15,
									usePointStyle: true
								}
							}
						},
						onHover: function(event, activeElements) {
							event.native.target.style.cursor = activeElements.length > 0 ? 'pointer' : 'default';
						}
					}
				});
				
				// Store chart instance on canvas for later access
				ctx.chart = chartInstance;
				} catch (error) {
					console.error('Error initializing pie chart:', error);
				}
			}
			
			// Wait for DOM and Chart.js to be ready
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', initPieChart);
			} else {
				// DOM is ready, but Chart.js might not be loaded yet
				setTimeout(initPieChart, 100);
			}
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Generate colors for pie chart.
	 *
	 * @param int    $count Number of colors needed.
	 * @param string $min_color Base color.
	 * @param string $max_color End color.
	 * @return array Array of hex colors.
	 */
	private static function generate_pie_colors($count, $min_color, $max_color) {
		$base_colors = [
			'rgba(59, 130, 246, 0.8)',   // Blue
			'rgba(16, 185, 129, 0.8)',   // Green
			'rgba(245, 158, 11, 0.8)',   // Yellow
			'rgba(239, 68, 68, 0.8)',    // Red
			'rgba(139, 92, 246, 0.8)',   // Purple
			'rgba(236, 72, 153, 0.8)',   // Pink
			'rgba(20, 184, 166, 0.8)',   // Teal
			'rgba(251, 146, 60, 0.8)',   // Orange
		];

		$colors = [];
		for ($i = 0; $i < $count; $i++) {
			$colors[] = $base_colors[$i % count($base_colors)];
		}

		return $colors;
	}

	/**
	 * Render line chart.
	 *
	 * @param array $data Chart data.
	 * @param array $config Configuration.
	 * @return string HTML.
	 */
	private static function render_line_chart($data, $config) {
		$chart_id = 'exaig-chart-' . uniqid();
		$color_min = isset($config['color_min']) ? $config['color_min'] : '#3b82f6';
		$color_max = isset($config['color_max']) ? $config['color_max'] : '#1e40af';
		
		// Transform data for Chart.js
		$labels = [];
		$datasets = [];
		$base_colors = [
			'rgba(59, 130, 246, 1)',   // Blue
			'rgba(16, 185, 129, 1)',   // Green
			'rgba(245, 158, 11, 1)',   // Yellow
			'rgba(239, 68, 68, 1)',    // Red
			'rgba(139, 92, 246, 1)',   // Purple
			'rgba(236, 72, 153, 1)',   // Pink
		];
		$color_index = 0;

		foreach ($data as $row => $cols) {
			if (!is_array($cols)) {
				continue;
			}
			
			$values = [];
			$col_labels = [];
			
			foreach ($cols as $col => $value) {
				$numeric_value = is_numeric($value) ? (float)$value : 0.0;
				$col_labels[] = esc_js($col);
				$values[] = $numeric_value;
			}
			
			if (empty($labels)) {
				$labels = $col_labels;
			}
			
			$border_color = $base_colors[$color_index % count($base_colors)];
			$bg_color = str_replace('1)', '0.1)', $border_color);
			
			$datasets[] = [
				'label' => esc_js($row),
				'data' => $values,
				'borderColor' => $border_color,
				'backgroundColor' => $bg_color,
				'tension' => 0.4,
				'fill' => true,
				'pointRadius' => 4,
				'pointHoverRadius' => 6,
				'pointBackgroundColor' => $border_color,
				'pointBorderColor' => '#ffffff',
				'pointBorderWidth' => 2
			];
			$color_index++;
		}

		if (empty($labels) || empty($datasets)) {
			return '<div class="exaig-heatmap-error">No data available for line chart.</div>';
		}

		ob_start();
		?>
		<div class="exaig-chart-wrapper" data-chart-type="line" data-chart-id="<?php echo esc_attr($chart_id); ?>" style="position: relative; height: 400px;">
			<div class="exaig-chart-loading" id="<?php echo esc_attr($chart_id); ?>-loading">
				<div class="exaig-loading-spinner"></div>
				<div class="exaig-loading-text">Loading chart...</div>
			</div>
			<canvas id="<?php echo esc_attr($chart_id); ?>" style="display: none;"></canvas>
		</div>
		<script>
		(function() {
			var maxRetries = 50; // 5 seconds max wait
			var retryCount = 0;
			var chartId = '<?php echo esc_js($chart_id); ?>';
			
			function initLineChart() {
				retryCount++;
				
				if (typeof Chart === 'undefined') {
					if (retryCount < maxRetries) {
						setTimeout(initLineChart, 100);
					} else {
						console.error('Chart.js failed to load after ' + (maxRetries * 100) + 'ms');
					}
					return;
				}
				
				var ctx = document.getElementById(chartId);
				if (!ctx) {
					if (retryCount < maxRetries) {
						setTimeout(initLineChart, 100);
					} else {
						console.error('Canvas element not found: ' + chartId);
						// Show error message
						var loadingEl = document.getElementById(chartId + '-loading');
						if (loadingEl) {
							loadingEl.innerHTML = '<div class="exaig-loading-error">Failed to load chart</div>';
						}
					}
					return;
				}
				
				try {
					// Hide loading indicator and show canvas
					var loadingEl = document.getElementById(chartId + '-loading');
					if (loadingEl) {
						loadingEl.style.display = 'none';
					}
					ctx.style.display = 'block';
					
					var chartInstance = new Chart(ctx, {
					type: 'line',
					data: {
						labels: <?php echo wp_json_encode($labels); ?>,
						datasets: <?php echo wp_json_encode($datasets); ?>
					},
					options: {
						responsive: true,
						maintainAspectRatio: false,
						animation: {
							duration: 1000,
							easing: 'easeInOutQuart'
						},
						interaction: {
							mode: 'index',
							intersect: false
						},
						plugins: {
							tooltip: {
								enabled: true,
								mode: 'index',
								intersect: false,
								backgroundColor: 'rgba(0, 0, 0, 0.8)',
								padding: 12,
								titleFont: {
									size: 14,
									weight: 'bold'
								},
								bodyFont: {
									size: 13
								},
								borderColor: 'rgba(255, 255, 255, 0.1)',
								borderWidth: 1,
								cornerRadius: 6,
								callbacks: {
									title: function(context) {
										return context[0].label || '';
									},
									label: function(context) {
										return context.dataset.label + ': ' + context.parsed.y.toLocaleString();
									}
								}
							},
							legend: {
								display: true,
								position: 'top',
								labels: {
									usePointStyle: true,
									padding: 15
								}
							}
						},
						scales: {
							y: {
								beginAtZero: true,
								ticks: {
									callback: function(value) {
										return value.toLocaleString();
									}
								},
								grid: {
									color: 'rgba(0, 0, 0, 0.05)'
								}
							},
							x: {
								grid: {
									display: false
								},
								ticks: {
									maxRotation: 45,
									minRotation: 0
								}
							}
						},
						onHover: function(event, activeElements) {
							event.native.target.style.cursor = activeElements.length > 0 ? 'pointer' : 'default';
						}
					}
				});
				
				// Store chart instance on canvas for later access
				ctx.chart = chartInstance;
				} catch (error) {
					console.error('Error initializing line chart:', error);
				}
			}
			
			// Wait for DOM and Chart.js to be ready
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', initLineChart);
			} else {
				// DOM is ready, but Chart.js might not be loaded yet
				setTimeout(initLineChart, 100);
			}
		})();
		</script>
		<?php
		return ob_get_clean();
	}
}

