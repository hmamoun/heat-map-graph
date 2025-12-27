<?php
/**
 * Slicer Handler
 *
 * Handles filter/slicer functionality for heat maps.
 *
 * @package Heat_Map_Graph
 */

if (!defined('ABSPATH')) {
	exit;
}

class EXAIG_Heat_Map_Graph_Slicer_Handler {

	/**
	 * Render slicer UI.
	 *
	 * @param array $data Heat map data.
	 * @param array $config Configuration.
	 * @return string HTML.
	 */
	public static function render($data, $config = []) {
		if (!EXAIG_Heat_Map_Graph_Premium_Features::has_feature('slicers')) {
			return '';
		}

		// Extract unique values for filters
		$rows = [];
		$cols = [];
		$values = [];

		foreach ($data as $row => $cols_data) {
			$rows[] = $row;
			foreach ($cols_data as $col => $value) {
				$cols[] = $col;
				$values[] = $value;
			}
		}

		$rows = array_unique($rows);
		$cols = array_unique($cols);
		sort($rows);
		sort($cols);

		$min_value = !empty($values) ? min($values) : 0;
		$max_value = !empty($values) ? max($values) : 100;

		$slicer_id = 'exaig-slicers-' . uniqid();
		ob_start();
		?>
		<div class="exaig-slicers-wrapper" data-heatmap-id="<?php echo esc_attr($config['id'] ?? ''); ?>" id="<?php echo esc_attr($slicer_id); ?>">
			<div class="exaig-slicers-header">
				<h4>
					<span class="dashicons dashicons-filter" style="vertical-align:middle;"></span>
					Filters
					<span class="exaig-filter-count" style="margin-left:8px;font-size:12px;color:#6b7280;">(<?php echo count($rows); ?> rows, <?php echo count($cols); ?> columns)</span>
				</h4>
				<button type="button" class="exaig-slicers-toggle" id="<?php echo esc_attr($slicer_id); ?>-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr($slicer_id); ?>-filters">
					<span class="exaig-slicers-toggle-text">Show Filters</span>
					<span class="dashicons dashicons-arrow-down-alt2"></span>
				</button>
			</div>
			<div class="exaig-slicers-filters exaig-slicers-collapsed-init" id="<?php echo esc_attr($slicer_id); ?>-filters" style="display:none;">
				<div class="exaig-slicer-group">
					<label for="<?php echo esc_attr($slicer_id); ?>-filter-rows">
						<span class="dashicons dashicons-list-view" style="font-size:16px;vertical-align:middle;"></span>
						Filter Rows:
					</label>
					<select id="<?php echo esc_attr($slicer_id); ?>-filter-rows" class="exaig-slicer-select" multiple size="5">
						<option value="">All</option>
						<?php foreach ($rows as $row) : ?>
							<option value="<?php echo esc_attr($row); ?>"><?php echo esc_html($row); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="exaig-slicer-help">Hold Ctrl/Cmd to select multiple rows</p>
				</div>
				<div class="exaig-slicer-group">
					<label for="<?php echo esc_attr($slicer_id); ?>-filter-cols">
						<span class="dashicons dashicons-grid-view" style="font-size:16px;vertical-align:middle;"></span>
						Filter Columns:
					</label>
					<select id="<?php echo esc_attr($slicer_id); ?>-filter-cols" class="exaig-slicer-select" multiple size="5">
						<option value="">All</option>
						<?php foreach ($cols as $col) : ?>
							<option value="<?php echo esc_attr($col); ?>"><?php echo esc_html($col); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="exaig-slicer-help">Hold Ctrl/Cmd to select multiple columns</p>
				</div>
				<div class="exaig-slicer-group">
					<label for="<?php echo esc_attr($slicer_id); ?>-filter-value-range">
						<span class="dashicons dashicons-chart-line" style="font-size:16px;vertical-align:middle;"></span>
						Value Range: <span id="<?php echo esc_attr($slicer_id); ?>-value-display" class="exaig-value-display"><?php echo esc_html($min_value); ?> - <?php echo esc_html($max_value); ?></span>
					</label>
					<div class="exaig-range-container">
						<input type="range" id="<?php echo esc_attr($slicer_id); ?>-filter-value-range-min" class="exaig-slicer-range" 
							min="<?php echo esc_attr($min_value); ?>" 
							max="<?php echo esc_attr($max_value); ?>" 
							value="<?php echo esc_attr($min_value); ?>" 
							step="<?php echo ($max_value - $min_value) > 100 ? '1' : '0.1'; ?>" />
						<input type="range" id="<?php echo esc_attr($slicer_id); ?>-filter-value-range-max" class="exaig-slicer-range" 
							min="<?php echo esc_attr($min_value); ?>" 
							max="<?php echo esc_attr($max_value); ?>" 
							value="<?php echo esc_attr($max_value); ?>" 
							step="<?php echo ($max_value - $min_value) > 100 ? '1' : '0.1'; ?>" />
					</div>
					<div class="exaig-range-labels">
						<span id="<?php echo esc_attr($slicer_id); ?>-range-min-label"><?php echo esc_html($min_value); ?></span>
						<span id="<?php echo esc_attr($slicer_id); ?>-range-max-label"><?php echo esc_html($max_value); ?></span>
					</div>
				</div>
				<div class="exaig-slicer-actions">
					<button type="button" class="button button-small" id="<?php echo esc_attr($slicer_id); ?>-reset-filters">
						<span class="dashicons dashicons-update" style="font-size:16px;vertical-align:middle;"></span>
						Reset Filters
					</button>
					<span class="exaig-filter-status" id="<?php echo esc_attr($slicer_id); ?>-status"></span>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}

