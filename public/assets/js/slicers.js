/**
 * Slicers JavaScript
 *
 * Handles client-side filtering for heat maps and charts.
 */

(function($) {
	'use strict';

	function initSlicers() {
		$('.exaig-slicers-wrapper').each(function() {
			var $wrapper = $(this);
			// Skip if already initialized
			if ($wrapper.data('exaig-slicers-full-initialized')) {
				return;
			}
			$wrapper.data('exaig-slicers-full-initialized', true);
			var $wrapper = $(this);
			var wrapperId = $wrapper.attr('id');
			var heatmapId = $wrapper.data('heatmap-id');
			var $heatmap = $wrapper.closest('.exaig-heatmap-wrapper').find('.exaig-heatmap-table');
			var $chart = $wrapper.closest('.exaig-heatmap-wrapper').find('.exaig-chart-wrapper canvas');
			var chartInstance = null;
			
			// Get filter element IDs
			var rowFilterId = wrapperId + '-filter-rows';
			var colFilterId = wrapperId + '-filter-cols';
			var rangeFilterId = wrapperId + '-filter-value-range';
			var rangeMinId = rangeFilterId + '-min';
			var rangeMaxId = rangeFilterId + '-max';
			var rangeMinLabelId = wrapperId + '-range-min-label';
			var rangeMaxLabelId = wrapperId + '-range-max-label';
			var valueDisplayId = wrapperId + '-value-display';
			var resetBtnId = wrapperId + '-reset-filters';
			var statusId = wrapperId + '-status';
			var toggleId = wrapperId + '-toggle';
			var filtersId = wrapperId + '-filters';
			
			// Initialize collapsed state
			var $filters = $wrapper.find('#' + filtersId);
			var $toggle = $wrapper.find('#' + toggleId);
			$filters.removeClass('exaig-slicers-collapsed-init');
			$wrapper.addClass('exaig-slicers-collapsed');
			
			// Toggle collapse/expand
			$toggle.on('click', function() {
				var isExpanded = $toggle.attr('aria-expanded') === 'true';
				var $toggleText = $toggle.find('.exaig-slicers-toggle-text');
				
				if (isExpanded) {
					$filters.slideUp(300);
					$toggle.attr('aria-expanded', 'false');
					$toggle.find('.dashicons').removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
					$toggleText.text('Show Filters');
					$wrapper.addClass('exaig-slicers-collapsed');
				} else {
					$filters.slideDown(300);
					$toggle.attr('aria-expanded', 'true');
					$toggle.find('.dashicons').removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
					$toggleText.text('Hide Filters');
					$wrapper.removeClass('exaig-slicers-collapsed');
				}
			});
			
			// Get chart instance if Chart.js is available
			if ($chart.length && typeof Chart !== 'undefined') {
				var chartId = $chart.attr('id');
				if (chartId) {
					var chartElement = document.getElementById(chartId);
					if (chartElement && chartElement.chart) {
						chartInstance = chartElement.chart;
					}
				}
			}

			// Store original data for charts
			var originalChartData = null;
			if (chartInstance && chartInstance.data) {
				originalChartData = JSON.parse(JSON.stringify(chartInstance.data));
			}

			var $rows = $heatmap.length ? $heatmap.find('tbody tr') : $();
			var originalRowCount = $rows.length;

			// Row filter
			$wrapper.find('#' + rowFilterId).on('change', function() {
				updateFilterStatus();
				applyFilters();
			});

			// Column filter
			$wrapper.find('#' + colFilterId).on('change', function() {
				updateFilterStatus();
				applyFilters();
			});

			// Value range filters (min and max)
			var rangeMinId = rangeFilterId + '-min';
			var rangeMaxId = rangeFilterId + '-max';
			var rangeMinLabelId = wrapperId + '-range-min-label';
			var rangeMaxLabelId = wrapperId + '-range-max-label';
			
			$wrapper.find('#' + rangeMinId).on('input', function() {
				var minValue = parseFloat($(this).val());
				var maxValue = parseFloat($wrapper.find('#' + rangeMaxId).val());
				// Ensure min doesn't exceed max
				if (minValue > maxValue) {
					minValue = maxValue;
					$(this).val(minValue);
				}
				$wrapper.find('#' + valueDisplayId).text(minValue + ' - ' + maxValue);
				$wrapper.find('#' + rangeMinLabelId).text(Math.round(minValue));
				updateFilterStatus();
				applyFilters();
			});
			
			$wrapper.find('#' + rangeMaxId).on('input', function() {
				var maxValue = parseFloat($(this).val());
				var minValue = parseFloat($wrapper.find('#' + rangeMinId).val());
				// Ensure max doesn't go below min
				if (maxValue < minValue) {
					maxValue = minValue;
					$(this).val(maxValue);
				}
				$wrapper.find('#' + valueDisplayId).text(minValue + ' - ' + maxValue);
				$wrapper.find('#' + rangeMaxLabelId).text(Math.round(maxValue));
				updateFilterStatus();
				applyFilters();
			});

			// Reset filters
			$wrapper.find('#' + resetBtnId).on('click', function() {
				$wrapper.find('#' + rowFilterId).val('').trigger('change');
				$wrapper.find('#' + colFilterId).val('').trigger('change');
				var minVal = parseFloat($wrapper.find('#' + rangeMinId).attr('min')) || 0;
				var maxVal = parseFloat($wrapper.find('#' + rangeMaxId).attr('max'));
				$wrapper.find('#' + rangeMinId).val(minVal).trigger('input');
				$wrapper.find('#' + rangeMaxId).val(maxVal).trigger('input');
				updateFilterStatus();
			});

			function updateFilterStatus() {
				var selectedRows = $wrapper.find('#' + rowFilterId).val() || [];
				var selectedCols = $wrapper.find('#' + colFilterId).val() || [];
				var minValue = parseFloat($wrapper.find('#' + rangeMinId).val()) || 0;
				var maxValue = parseFloat($wrapper.find('#' + rangeMaxId).val());
				var originalMin = parseFloat($wrapper.find('#' + rangeMinId).attr('min')) || 0;
				var originalMax = parseFloat($wrapper.find('#' + rangeMaxId).attr('max'));
				
				var hasFilters = (selectedRows.length > 0 && selectedRows[0] !== '') || 
								  (selectedCols.length > 0 && selectedCols[0] !== '') ||
								  minValue > originalMin || maxValue < originalMax;
				
				var $status = $wrapper.find('#' + statusId);
				if (hasFilters) {
					$status.text('Filters active').css('color', '#059669');
				} else {
					$status.text('').css('color', '');
				}
			}

			function applyFilters() {
				var selectedRows = $wrapper.find('#' + rowFilterId).val() || [];
				var selectedCols = $wrapper.find('#' + colFilterId).val() || [];
				var minValue = parseFloat($wrapper.find('#' + rangeMinId).val()) || 0;
				var maxValue = parseFloat($wrapper.find('#' + rangeMaxId).val());

				// Filter heat map table
				if ($heatmap.length) {
					filterHeatMap($heatmap, $rows, selectedRows, selectedCols, minValue, maxValue);
				}

				// Filter chart
				if (chartInstance && originalChartData) {
					filterChart(chartInstance, originalChartData, selectedRows, selectedCols, minValue, maxValue);
				}
			}

			function filterHeatMap($heatmap, $rows, selectedRows, selectedCols, minValue, maxValue) {
				$rows.each(function() {
					var $row = $(this);
					var rowLabel = $row.find('th.exaig-hm-row').text().trim();
					var showRow = selectedRows.length === 0 || selectedRows.indexOf(rowLabel) !== -1 || selectedRows.indexOf('') !== -1;

					if (!showRow) {
						$row.hide();
						return;
					}

					var showAnyCell = false;
					$row.find('td.exaig-hm-cell').each(function() {
						var $cell = $(this);
						var colIndex = $cell.index();
						var colHeader = $heatmap.find('thead th').eq(colIndex).text().trim();
						var cellValue = parseFloat($cell.text().replace(/[KM]/g, '').replace(/,/g, '')) || 0;
						
						var showCol = selectedCols.length === 0 || selectedCols.indexOf(colHeader) !== -1 || selectedCols.indexOf('') !== -1;
						var showValue = cellValue >= minValue && cellValue <= maxValue;

						if (showCol && showValue) {
							$cell.show();
							showAnyCell = true;
						} else {
							$cell.hide();
						}
					});

					if (showAnyCell) {
						$row.show();
					} else {
						$row.hide();
					}
				});
			}

			function filterChart(chartInstance, originalData, selectedRows, selectedCols, minValue, maxValue) {
				if (!chartInstance || !originalData) return;

				var filteredLabels = [];
				var filteredData = [];
				var filteredBackgroundColors = [];

				for (var i = 0; i < originalData.labels.length; i++) {
					var label = originalData.labels[i];
					var value = originalData.datasets[0].data[i];
					
					// Parse label (format: "row / col")
					var parts = label.split(' / ');
					var row = parts[0] || '';
					var col = parts[1] || '';
					
					var showRow = selectedRows.length === 0 || selectedRows.indexOf(row) !== -1 || selectedRows.indexOf('') !== -1;
					var showCol = selectedCols.length === 0 || selectedCols.indexOf(col) !== -1 || selectedCols.indexOf('') !== -1;
					var showValue = value >= minValue && value <= maxValue;

					if (showRow && showCol && showValue) {
						filteredLabels.push(label);
						filteredData.push(value);
						if (originalData.datasets[0].backgroundColor && originalData.datasets[0].backgroundColor[i]) {
							filteredBackgroundColors.push(originalData.datasets[0].backgroundColor[i]);
						}
					}
				}

				chartInstance.data.labels = filteredLabels;
				chartInstance.data.datasets[0].data = filteredData;
				if (filteredBackgroundColors.length > 0) {
					chartInstance.data.datasets[0].backgroundColor = filteredBackgroundColors;
				}
				chartInstance.update('active');
			}
		});
	}

	// Initialize on document ready
	$(document).ready(function() {
		initSlicers();
	});

	// Reinitialize when content is loaded via AJAX
	$(document).on('exaig-slicers-content-loaded', function() {
		initSlicers();
	});
})(jQuery);

