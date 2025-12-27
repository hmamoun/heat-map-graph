<?php
/**
 * Premium Features Manager
 *
 * Handles feature gating and premium feature checks.
 *
 * @package Heat_Map_Graph
 */

if (!defined('ABSPATH')) {
	exit;
}

class EXAIG_Heat_Map_Graph_Premium_Features {

	/**
	 * Check if premium features are available.
	 *
	 * @return bool
	 */
	public static function is_premium() {
		// Check if Freemius is available and active
		if (function_exists('fs_is_plan_active')) {
			return fs_is_plan_active('premium');
		}

		// Check if Freemius instance exists and is premium
		if (function_exists('exaig_heatmap_fs')) {
			$fs = exaig_heatmap_fs();
			if ($fs && method_exists($fs, 'is_premium') && $fs->is_premium()) {
				return true;
			}
		}
		
		// Fallback: Check for license key in options (for custom licensing)
		$license_key = get_option('exaig_heatmap_license_key', '');
		return !empty($license_key);
	}

	/**
	 * Check if a specific premium feature is available.
	 *
	 * @param string $feature Feature name (e.g., 'charts', 'export', 'slicers').
	 * @return bool
	 */
	public static function has_feature($feature) {
		if (!self::is_premium()) {
			return false;
		}

		// Feature-specific checks can be added here
		$premium_features = [
			'charts' => true,
			'export' => true,
			'slicers' => true,
			'interactive' => true,
			'linked_charts' => true,
			'external_data' => true,
		];

		return isset($premium_features[$feature]) && $premium_features[$feature];
	}

	/**
	 * Get upgrade URL.
	 *
	 * @return string
	 */
	public static function get_upgrade_url() {
		if (function_exists('fs_get_upgrade_url')) {
			return fs_get_upgrade_url();
		}

		// Try to get from Freemius instance
		if (function_exists('exaig_heatmap_fs')) {
			$fs = exaig_heatmap_fs();
			if ($fs && method_exists($fs, 'get_upgrade_url')) {
				return $fs->get_upgrade_url();
			}
		}

		// Fallback URL (can be customized)
		return 'https://hayan.mamouns.xyz/heat-map-graph-plugin/upgrade/';
	}

	/**
	 * Render premium upgrade notice.
	 *
	 * @param string $feature_name Feature name to display.
	 * @param string $description Feature description.
	 * @return string HTML for upgrade notice.
	 */
	public static function render_upgrade_notice($feature_name, $description = '') {
		if (self::is_premium()) {
			return '';
		}

		$upgrade_url = self::get_upgrade_url();
		ob_start();
		?>
		<div class="exaig-premium-notice" style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:16px;margin:16px 0;">
			<h3 style="margin:0 0 8px 0;color:#856404;">
				<span class="dashicons dashicons-lock" style="vertical-align:middle;"></span>
				Premium Feature: <?php echo esc_html($feature_name); ?>
			</h3>
			<?php if ($description) : ?>
				<p style="margin:0 0 12px 0;color:#856404;"><?php echo esc_html($description); ?></p>
			<?php endif; ?>
			<a href="<?php echo esc_url($upgrade_url); ?>" class="button button-primary" target="_blank">
				Upgrade to Premium
			</a>
		</div>
		<?php
		return ob_get_clean();
	}
}

