<?php
/**
 * BACA Vector DB Factory
 *
 * Factory class for creating and managing vector database instances.
 *
 * @package Botisst
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Class BACA_Vector_DB_Factory
 */
class BACA_Vector_DB_Factory
{

	/**
	 * Instances
	 *
	 * @var array
	 */
	private static $instances = [];

	/**
	 * Available Providers
	 *
	 * @return array
	 */
	public static function get_providers()
	{
		return [
			'sqlite' => [
				'name' => 'SQLite (Local)',
				'description' => 'Local vector storage using WordPress options',
				'status' => 'stable',
				'requires' => [],
			],
			'pinecone' => [
				'name' => 'Pinecone',
				'description' => 'Cloud-based vector database (managed service)',
				'status' => 'stable',
				'requires' => ['api_key', 'environment', 'index_name'],
			],
		];
	}

	/**
	 * Create Vector DB Instance
	 *
	 * @param string $provider Provider name.
	 * @param array  $config   Configuration.
	 * @return BACA_Vector_DB_Base|WP_Error
	 */
	public static function create($provider, $config = [])
	{

		$provider = sanitize_text_field(
			$provider
		);

		/*
		 * Create cache key
		 */
		$cache_key = md5(
			$provider .
			wp_json_encode($config)
		);

		if (
			isset(
			self::$instances[
				$cache_key
			]
		)
		) {

			return self::$instances[
				$cache_key
			];
		}

		switch ($provider) {

			case 'sqlite':

				require_once
					BACA_PATH .
					'includes/class-baca-vector-db-sqlite.php';

				$instance =
					new BACA_Vector_DB_SQLite(
						$config
					);

				break;

			case 'pinecone':

				require_once
					BACA_PATH .
					'includes/class-baca-vector-db-pinecone.php';

				$instance =
					new BACA_Vector_DB_Pinecone(
						$config
					);

				break;


			default:

				return new WP_Error(
					'unknown_provider',
					sprintf(
						'Unknown provider: %s',
						$provider
					)
				);
		}

		self::$instances[
			$cache_key
		] = $instance;

		return $instance;
	}

	/**
	 * Test Provider Connection
	 *
	 * @param string $provider Provider name.
	 * @param array  $config   Configuration.
	 * @return bool|WP_Error
	 */
	public static function test_connection($provider, $config = [])
	{
		$instance = self::create($provider, $config);

		if (is_wp_error($instance)) {
			return $instance;
		}

		return $instance->test_connection();
	}

	/**
	 * Get Provider Configuration Requirements
	 *
	 * @param string $provider Provider name.
	 * @return array Configuration requirements
	 */
	public static function get_config_requirements($provider)
	{
		$providers = self::get_providers();

		if (!isset($providers[$provider])) {
			return [];
		}

		return $providers[$provider]['requires'];
	}

	/**
	 * Validate Provider Configuration
	 *
	 * @param string $provider Provider name.
	 * @param array  $config   Configuration.
	 * @return bool|WP_Error
	 */
	public static function validate_config($provider, $config)
	{
		$requirements = self::get_config_requirements($provider);

		foreach ($requirements as $required_field) {
			if (empty($config[$required_field])) {
				return new WP_Error(
					'invalid_config',
					sprintf('Missing required configuration: %s', $required_field)
				);
			}
		}

		return true;
	}
}
