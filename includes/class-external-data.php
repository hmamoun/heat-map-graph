<?php
/**
 * External Data Handler
 *
 * Handles connections to external data sources (APIs, CSV URLs, etc.).
 *
 * @package Heat_Map_Graph
 */

if (!defined('ABSPATH')) {
	exit;
}

class EXAIG_Heat_Map_Graph_External_Data {

	/**
	 * Fetch data from external source.
	 *
	 * @param string $source_type Source type (api, csv_url).
	 * @param array  $config Configuration.
	 * @return array|WP_Error Data array or error.
	 */
	public static function fetch_data($source_type, $config) {
		if (!EXAIG_Heat_Map_Graph_Premium_Features::has_feature('external_data')) {
			return new WP_Error('premium_required', 'External data sources require Premium.');
		}

		switch ($source_type) {
			case 'api':
				return self::fetch_from_api($config);
			case 'csv_url':
				return self::fetch_from_csv_url($config);
			default:
				return new WP_Error('invalid_source', 'Invalid data source type.');
		}
	}

	/**
	 * Fetch data from REST API.
	 *
	 * @param array $config Configuration.
	 * @return array|WP_Error
	 */
	private static function fetch_from_api($config) {
		$url = isset($config['url']) ? $config['url'] : '';
		if (empty($url)) {
			return new WP_Error('missing_url', 'API URL is required.');
		}

		$args = [
			'timeout' => 30,
			'headers' => [],
		];

		if (isset($config['auth_type']) && $config['auth_type'] === 'bearer') {
			$args['headers']['Authorization'] = 'Bearer ' . $config['auth_token'];
		}

		$response = wp_remote_get($url, $args);

		if (is_wp_error($response)) {
			return $response;
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			return new WP_Error('invalid_json', 'Invalid JSON response from API.');
		}

		// Handle CKAN API format (Canada Open Data, etc.)
		if (isset($data['result']['records']) && is_array($data['result']['records'])) {
			$data = $data['result']['records'];
		}
		// Handle CKAN success wrapper
		elseif (isset($data['result']) && is_array($data['result']) && isset($data['success']) && $data['success'] === true) {
			$data = $data['result'];
			// If result is an array of records
			if (isset($data[0]) && is_array($data[0])) {
				// Already an array of records
			} elseif (isset($data['records'])) {
				$data = $data['records'];
			}
		}

		// Transform to row/column/value format
		return self::transform_api_data($data, $config);
	}

	/**
	 * Fetch data from CSV URL.
	 *
	 * @param array $config Configuration.
	 * @return array|WP_Error
	 */
	private static function fetch_from_csv_url($config) {
		$url = isset($config['url']) ? $config['url'] : '';
		if (empty($url)) {
			return new WP_Error('missing_url', 'CSV URL is required.');
		}

		$response = wp_remote_get($url, ['timeout' => 30]);

		if (is_wp_error($response)) {
			return $response;
		}

		$body = wp_remote_retrieve_body($response);
		$lines = explode("\n", $body);
		
		if (empty($lines)) {
			return new WP_Error('empty_csv', 'CSV file is empty.');
		}

		// Parse CSV
		$data = [];
		$headers = str_getcsv(array_shift($lines));
		
		foreach ($lines as $line) {
			if (empty(trim($line))) {
				continue;
			}
			$row = str_getcsv($line);
			if (count($row) === count($headers)) {
				$data[] = array_combine($headers, $row);
			}
		}

		return self::transform_csv_data($data, $config);
	}

	/**
	 * Transform API data to row/column/value format.
	 *
	 * @param array $data Raw API data.
	 * @param array $config Configuration.
	 * @return array Transformed data.
	 */
	private static function transform_api_data($data, $config) {
		$row_field = $config['row_field'] ?? 'row_label';
		$col_field = $config['col_field'] ?? 'col_label';
		$value_field = $config['value_field'] ?? 'cell_value';

		// Assume data is array of objects
		$transformed = [];
		foreach ($data as $item) {
			if (is_array($item) && isset($item[$row_field]) && isset($item[$col_field]) && isset($item[$value_field])) {
				// Convert value to numeric if it's a string that looks like a number
				$value = $item[$value_field];
				if (is_string($value)) {
					// Remove commas and spaces, then check if numeric
					$value_clean = str_replace([',', ' '], '', $value);
					if (is_numeric($value_clean)) {
						$value = (float)$value_clean;
					}
				}
				
				$transformed[] = [
					$row_field => $item[$row_field],
					$col_field => $item[$col_field],
					$value_field => $value,
				];
			}
		}

		return $transformed;
	}

	/**
	 * Transform CSV data to row/column/value format.
	 *
	 * @param array $data CSV data.
	 * @param array $config Configuration.
	 * @return array Transformed data.
	 */
	private static function transform_csv_data($data, $config) {
		$row_field = $config['row_field'] ?? 'row_label';
		$col_field = $config['col_field'] ?? 'col_label';
		$value_field = $config['value_field'] ?? 'cell_value';

		// CSV should already have these columns
		return $data;
	}
}

