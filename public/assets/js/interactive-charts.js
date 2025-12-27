/**
 * Interactive Charts JavaScript
 *
 * Adds enhanced interactivity to Chart.js charts: tooltips, click interactions, zoom/pan.
 */

(function($) {
	'use strict';

	// Wait for Chart.js to load
	function initInteractiveCharts() {
		if (typeof Chart === 'undefined') {
			setTimeout(initInteractiveCharts, 100);
			return;
		}

		// Enhance all charts after they're created
		$(document).ready(function() {
			// Use MutationObserver to detect when charts are added dynamically
			var observer = new MutationObserver(function(mutations) {
				mutations.forEach(function(mutation) {
					mutation.addedNodes.forEach(function(node) {
						if (node.nodeType === 1) { // Element node
							var $node = $(node);
							if ($node.hasClass('exaig-chart-wrapper') || $node.find('.exaig-chart-wrapper').length) {
								enhanceCharts();
							}
					});
				});
			});
		});

		// Initial enhancement
		enhanceCharts();
	}

	function enhanceCharts() {
		$('.exaig-chart-wrapper canvas').each(function() {
			var canvas = this;
			var chartId = canvas.id;
			
			if (!chartId) return;
			
			// Wait a bit for chart to be initialized
			setTimeout(function() {
				var chartElement = document.getElementById(chartId);
				if (!chartElement || !chartElement.chart) return;
				
				var chart = chartElement.chart;
				
				// Skip if already enhanced
				if (chart.exaigEnhanced) return;
				chart.exaigEnhanced = true;

				// Add click interaction
				canvas.addEventListener('click', function(evt) {
					var points = chart.getElementsAtEventForMode(evt, 'nearest', { intersect: true }, true);
					if (points.length) {
						var point = points[0];
						var datasetIndex = point.datasetIndex;
						var index = point.index;
						var value = chart.data.datasets[datasetIndex].data[index];
						var label = chart.data.labels[index];
						
						// Trigger custom event
						var event = new CustomEvent('exaigChartClick', {
							detail: {
								chart: chart,
								label: label,
								value: value,
								datasetIndex: datasetIndex,
								index: index
							}
						});
						canvas.dispatchEvent(event);
						
						// Visual feedback
						chart.setActiveElements([{
							datasetIndex: datasetIndex,
							index: index
						}]);
						chart.update('active');
						
						// Reset after animation
						setTimeout(function() {
							chart.setActiveElements([]);
							chart.update('active');
						}, 1000);
					}
				});

				// Enhanced hover effects
				canvas.addEventListener('mousemove', function(evt) {
					var points = chart.getElementsAtEventForMode(evt, 'nearest', { intersect: true }, true);
					if (points.length) {
						canvas.style.cursor = 'pointer';
					} else {
						canvas.style.cursor = 'default';
					}
				});

				// Add zoom/pan plugin if available (Chart.js zoom plugin)
				if (typeof ChartZoom !== 'undefined') {
					chart.options.plugins = chart.options.plugins || {};
					chart.options.plugins.zoom = chart.options.plugins.zoom || {};
					chart.options.plugins.zoom.zoom = {
						wheel: {
							enabled: true
						},
						pinch: {
							enabled: true
						},
						mode: 'xy'
					};
					chart.options.plugins.zoom.pan = {
						enabled: true,
						mode: 'xy'
					};
				}
			}, 500);
		});
	}

	// Initialize when DOM is ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initInteractiveCharts);
	} else {
		initInteractiveCharts();
	}

	// Also initialize after Chart.js loads
	$(window).on('load', function() {
		setTimeout(enhanceCharts, 1000);
	});

})(jQuery);

