<?php
/**
 * BACA Vector DB - Pinecone Adapter
 *
 * Pinecone cloud vector database integration.
 *
 * @package Botisst
 */

if (!defined('ABSPATH')) {
	exit;
}

require_once BACA_PATH . 'includes/class-baca-vector-db-base.php';

/**
 * Class BACA_Vector_DB_Pinecone
 */
class BACA_Vector_DB_Pinecone extends BACA_Vector_DB_Base
{

	/**
	 * API Key
	 *
	 * @var string
	 */
	private $api_key = '';

	/**
	 * Environment
	 *
	 * @var string
	 */
	private $environment = '';

	/**
	 * Index Name
	 *
	 * @var string
	 */
	private $index_name = '';

	/**
	 * Base URL
	 *
	 * @var string
	 */
	private $base_url = '';

	/**
	 * Constructor
	 *
	 * @param array $config Configuration.
	 */
	public function __construct($config = [])
	{
		parent::__construct($config);

		$this->api_key = !empty($config['api_key'])
			? trim($config['api_key'])
			: '';

		$this->environment = !empty($config['environment'])
			? trim($config['environment'])
			: '';

		$this->index_name = !empty($config['index_name'])
			? trim($config['index_name'])
			: '';

		/*
		 * IMPORTANT:
		 * Use actual Pinecone host.
		 *
		 * Example:
		 * https://botisst-index-xxxxx.svc.aped-4627-b74a.pinecone.io
		 */
		$this->base_url = !empty($config['host'])
			? untrailingslashit($config['host'])
			: '';

	}

	/**
	 * Validate Configuration
	 *
	 * @return bool|WP_Error
	 */
	protected function validate_config()
	{
		if (empty($this->api_key)) {
			return new WP_Error('pinecone_missing_key', 'Pinecone API key is required');
		}
		if (empty($this->base_url)) {
			return new WP_Error('pinecone_missing_host', 'Pinecone host URL is required');
		}
		if (empty($this->index_name)) {
			return new WP_Error('pinecone_missing_index', 'Pinecone index name is required');
		}
		return true;
	}
	/**
	 * Store Embedding
	 *
	 * @param string $chunk_id     Chunk ID.
	 * @param array  $embedding    Embedding vector.
	 * @param string $document_id  Document ID.
	 * @return bool|WP_Error
	 */
	public function store_embedding($chunk_id, $embedding, $document_id = '')
	{
		global $wpdb;

		$vectors = [
			[
				'id' => $chunk_id,
				'values' => $embedding,
				'metadata' => [
					'document_id' => $document_id,
					'chunk_id' => $chunk_id,
					'timestamp' => current_time('mysql'),
				],
			],
		];

		$response = $this->upsert_vectors($vectors);

		if (is_wp_error($response)) {
			return $response;
		}

		// Update chunk status in database
		$chunks_table = esc_sql($wpdb->prefix . 'baca_rag_chunks');
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct update to custom table.
		$wpdb->update(
			$chunks_table,
			[
				'vector_id' => $chunk_id,
				'embedding_status' => 'completed',
			],
			['id' => (int) $chunk_id],
			['%s', '%s'],
			['%d']
		);

		return true;
	}

	/**
	 * Bulk Store Embeddings
	 *
	 * @param array $embeddings Array of embeddings.
	 * @return bool|WP_Error
	 */
	public function bulk_store_embeddings($embeddings)
	{
		$vectors = [];

		foreach ($embeddings as $chunk_id => $embedding_data) {
			$vectors[] = [
				'id' => $chunk_id,
				'values' => $embedding_data['vector'],
				'metadata' => [
					'document_id' => $embedding_data['document_id'] ?? '',
					'chunk_id' => $chunk_id,
					'timestamp' => current_time('mysql'),
				],
			];
		}

		$response = $this->upsert_vectors($vectors);

		if (is_wp_error($response)) {
			return $response;
		}

		return true;
	}

	/**
	 * Upsert Vectors to Pinecone
	 *
	 * @param array $vectors Vectors to upsert.
	 * @return bool|WP_Error
	 */
	private function upsert_vectors($vectors)
	{
		$url = $this->base_url . '/vectors/upsert';
		$response = wp_safe_remote_post($url, ['headers' => ['Api-Key' => $this->api_key, 'Content-Type' => 'application/json', 'X-Pinecone-API-Version' => '2025-10',], 'body' => wp_json_encode(['vectors' => $vectors, 'namespace' => 'default',]), 'timeout' => 60,]);
		if (is_wp_error($response)) {
			return $response;
		}
		$status_code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		if ($status_code >= 200 && $status_code < 300) {
			return true;
		}
		return new WP_Error('pinecone_error', $body);
	}

	public function search($query_embedding, $limit = 5)
	{

		$url = $this->base_url . '/query';

		$response = wp_safe_remote_post(
			$url,
			[
				'headers' => [
					'Api-Key' => $this->api_key,
					'Content-Type' => 'application/json',
					'X-Pinecone-API-Version' => '2025-10',
				],
				'body' => wp_json_encode(
					[
						'vector' => $query_embedding,
						'topK' => (int) $limit,
						'includeMetadata' => true,
						'namespace' => 'default',
					]
				),
				'timeout' => 30,
			]
		);

		if (is_wp_error($response)) {
			return [];
		}

		$status_code =
			wp_remote_retrieve_response_code(
				$response
			);

		if ($status_code < 200 || $status_code >= 300) {
			return [];
		}

		$data = json_decode(
			wp_remote_retrieve_body(
				$response
			),
			true
		);

		if (empty($data['matches'])) {
			return [];
		}

		$results = [];

		foreach ($data['matches'] as $match) {

			$results[] = [
				'chunk_id' => $match['id'],
				'similarity' => $match['score'],
				'metadata' => $match['metadata'] ?? [],
			];
		}

		return $results;
	}

	/**
	 * Get Embedding
	 *
	 * @param string $chunk_id Chunk ID.
	 * @return array|null
	 */
	public function get_embedding($chunk_id)
	{
		$url = $this->base_url . '/vectors/fetch?ids=' . urlencode($chunk_id);

		$response = wp_safe_remote_get(
			$url,
			[
				'headers' => [
					'Api-Key' => $this->api_key,
				],
				'timeout' => 30,
			]
		);

		if (is_wp_error($response)) {
			return null;
		}

		$data = json_decode(wp_remote_retrieve_body($response), true);

		if (!empty($data['vectors'][$chunk_id])) {
			return $data['vectors'][$chunk_id]['values'];
		}

		return null;
	}

	/**
	 * Delete Embedding
	 *
	 * @param string $chunk_id Chunk ID.
	 * @return bool|WP_Error
	 */
	public function delete_embedding($chunk_id)
	{
		global $wpdb;

		$url = $this->base_url . '/vectors/delete';

		$response = wp_safe_remote_post(
			$url,
			[
				'headers' => [
					'Api-Key' => $this->api_key,
					'Content-Type' => 'application/json',
				],
				'body' => wp_json_encode(
					[
						'ids' => [$chunk_id],
					]
				),
				'timeout' => 30,
			]
		);

		if (is_wp_error($response)) {
			return $response;
		}

		// Update chunk status in database
		$chunks_table = esc_sql($wpdb->prefix . 'baca_rag_chunks');
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct update to custom table.
		$wpdb->update(
			$chunks_table,
			[
				'embedding_status' => 'pending',
				'vector_id' => null,
			],
			['id' => $chunk_id],
			['%s', '%s'],
			['%d']
		);

		return true;
	}

	/**
	 * Bulk Delete
	 *
	 * @param array $chunk_ids Chunk IDs.
	 * @return bool|WP_Error
	 */
	public function bulk_delete($chunk_ids)
	{
		$url = $this->base_url . '/vectors/delete';

		$response = wp_safe_remote_post(
			$url,
			[
				'headers' => [
					'Api-Key' => $this->api_key,
					'Content-Type' => 'application/json',
				],
				'body' => wp_json_encode(
					[
						'ids' => $chunk_ids,
					]
				),
				'timeout' => 30,
			]
		);

		if (is_wp_error($response)) {
			return $response;
		}

		return true;
	}

	/**
	 * Test Connection
	 *
	 * @return bool|WP_Error
	 */
	public function test_connection($expected_dimensions = null)
	{

		$url =
			$this->base_url .
			'/describe_index_stats';

		$response = wp_safe_remote_post(
			$url,
			[
				'headers' => [
					'Api-Key' => $this->api_key,
					'Content-Type' => 'application/json',
					'X-Pinecone-API-Version' => '2025-10',
				],
				'body' => wp_json_encode(
					[
						'namespace' => 'default',
					]
				),
				'timeout' => 15,
			]
		);

		if (is_wp_error($response)) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code(
			$response
		);

		$body = wp_remote_retrieve_body($response);

		if ($status >= 200 && $status < 300) {
			$data = json_decode($body, true);
			
			if (isset($data['dimension'])) {
				require_once BACA_PATH . 'includes/class-baca-embedding-manager.php';
				$embedding_manager = new BACA_Embedding_Manager();
				$model_info = $embedding_manager->get_model_info();
				
				$dimensions = $expected_dimensions !== null ? (int) $expected_dimensions : (isset($model_info['dimensions']) ? (int) $model_info['dimensions'] : null);
				
				if ($dimensions && (int) $data['dimension'] !== $dimensions) {
					$provider_name = $expected_dimensions !== null 
						? ($expected_dimensions === 1536 ? 'openai' : ($expected_dimensions === 768 ? 'google' : 'selected provider'))
						: (isset($model_info['provider']) ? $model_info['provider'] : 'selected provider');

					return new WP_Error(
						'pinecone_dimension_mismatch',
						sprintf(
							/* translators: %1$d: Pinecone index dimensions, %2$s: Provider name, %3$d: Expected dimensions. */
							__('Pinecone index dimension mismatch! Your Pinecone index has %1$d dimensions, but your selected AI provider (%2$s) requires %3$d dimensions. Please recreate your Pinecone index with exactly %3$d dimensions.', 'botisst-ai-chat-assistant'),
							(int) $data['dimension'],
							$provider_name,
							$dimensions
						)
					);
				}
			}

			return true;
		}

		if ($status === 401) {
			return new WP_Error(
				'pinecone_unauthorized', 
				__('Invalid Pinecone API Key. Please check your credentials and try again.', 'botisst-ai-chat-assistant')
			);
		}
		
		if ($status === 403) {
			return new WP_Error(
				'pinecone_forbidden', 
				__('Access to Pinecone index forbidden. Check if your API key has correct permissions.', 'botisst-ai-chat-assistant')
			);
		}
		
		if ($status === 404) {
			return new WP_Error(
				'pinecone_not_found', 
				__('Pinecone host or index not found. Please verify your Host URL.', 'botisst-ai-chat-assistant')
			);
		}

		return new WP_Error(
			'pinecone_connection_failed',
			!empty($body) ? $body : __('Pinecone connection failed with status code: ', 'botisst-ai-chat-assistant') . $status
		);
	}

	/**
	 * Get Statistics
	 *
	 * @return array
	 */
	public function get_statistics()
	{
		$url = $this->base_url . '/describe_index_stats';

		$response = wp_safe_remote_post(
			$url,
			[
				'headers' => [
					'Api-Key' => $this->api_key,
					'Content-Type' => 'application/json',
				],
				'body' => '{}',
				'timeout' => 10,
			]
		);

		if (is_wp_error($response)) {
			return ['error' => $response->get_error_message()];
		}

		$data = json_decode(wp_remote_retrieve_body($response), true);

		return [
			'total_vectors' => $data['totalVectorCount'] ?? 0,
			'index_name' => $this->index_name,
			'environment' => $this->environment,
		];
	}
}
