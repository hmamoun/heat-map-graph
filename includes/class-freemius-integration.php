<?php
/**
 * Freemius Integration
 *
 * Initializes Freemius SDK for plugin monetization.
 *
 * @package Heat_Map_Graph
 */

if (!defined('ABSPATH')) {
	exit;
}

// Create a helper function to safely get Freemius instance
if (!function_exists('exaig_heatmap_fs')) {
	/**
	 * Get Freemius instance.
	 *
	 * @return Freemius|false Returns Freemius instance or false if not available.
	 */
	function exaig_heatmap_fs() {
		global $exaig_heatmap_fs;

		// Return cached instance if already initialized
		if (isset($exaig_heatmap_fs)) {
			return $exaig_heatmap_fs;
		}

		// Check if Freemius SDK is available
		if (!function_exists('fs_dynamic_init')) {
			// Try to load Freemius SDK if it exists
			$freemius_paths = [
				dirname(__FILE__) . '/../freemius/start.php',
				dirname(__FILE__) . '/../../freemius/start.php',
				dirname(__FILE__) . '/../../../freemius/start.php',
			];

			$freemius_loaded = false;
			foreach ($freemius_paths as $path) {
				if (file_exists($path)) {
					require_once $path;
					$freemius_loaded = true;
					break;
				}
			}

			// If still not available, return false
			if (!$freemius_loaded && !function_exists('fs_dynamic_init')) {
				$exaig_heatmap_fs = false;
				return false;
			}
		}

		// Initialize Freemius only if function exists
		if (function_exists('fs_dynamic_init')) {
			$exaig_heatmap_fs = fs_dynamic_init([
				'id' => 'YOUR_FREEMIUS_ID', // Replace with your actual Freemius ID
				'slug' => 'heat-map-graph',
				'type' => 'plugin',
				'public_key' => 'YOUR_PUBLIC_KEY', // Replace with your actual public key
				'is_premium' => false,
				'has_addons' => false,
				'has_paid_plans' => true,
				'menu' => [
					'slug' => 'exaig_heat_map_graph',
					'first-path' => 'admin.php?page=exaig_heat_map_graph',
					'support' => false,
				],
				'is_live' => false, // Set to true when ready for production
			]);

			// Signal that SDK was initiated
			do_action('exaig_heatmap_fs_loaded');

			return $exaig_heatmap_fs;
		}

		$exaig_heatmap_fs = false;
		return false;
	}

	// Only try to init Freemius if SDK is available
	// Don't call it here - let it be called when needed
}

