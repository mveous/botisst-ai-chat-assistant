<?php
/**
 * BACA Indexer
 *
 * Handles document indexing and chunking for RAG system.
 *
 * @package Botisst
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Class BACA_Indexer
 */
class BACA_Indexer
{

	/**
	 * Chunk Size (in characters)
	 *
	 * @var int
	 */
	private $chunk_size = 1000;

	/**
	 * Chunk Overlap (in characters)
	 *
	 * @var int
	 */
	private $chunk_overlap = 100;

	/**
	 * Embedding Provider
	 *
	 * @var mixed
	 */
	private $embedding_provider;

	/**
	 * Vector DB Adapter
	 *
	 * @var mixed
	 */
	private $vector_db;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		$settings = $this->get_settings();

		if (!empty($settings['indexing']['chunk_size'])) {
			$this->chunk_size = intval(
				$settings['indexing']['chunk_size']
			);
		}

		if (!empty($settings['indexing']['chunk_overlap'])) {
			$this->chunk_overlap = intval(
				$settings['indexing']['chunk_overlap']
			);
		}

		require_once BACA_PATH .
			'includes/class-baca-embedding-manager.php';

		require_once BACA_PATH .
			'includes/class-baca-vector-db-factory.php';

		$this->embedding_provider =
			new BACA_Embedding_Manager();

		$provider =
			$settings['vector_db']['provider']
			?? 'sqlite';

		$config =
			$settings['vector_db']
			?? [];

		$this->vector_db =
			BACA_Vector_DB_Factory::create(
				$provider,
				$config
			);
	}

	/**
	 * Get Settings
	 *
	 * @return array
	 */
	private function get_settings()
	{
		$settings = BACA_Settings_Handler::baca_get_all_settings();
		return isset($settings['rag']) ? $settings['rag'] : [];
	}

	/**
	 * Index All Documents
	 *
	 * Indexes all configured post types.
	 *
	 * @return array Status of indexing operation
	 */
	public function index_all_documents()
	{
		$settings = $this->get_settings();
		$sources = !empty($settings['post_types']) ? $settings['post_types'] : ['post', 'page'];

		if (empty($sources)) {
			return ['success' => false, 'message' => 'No indexing sources configured'];
		}

		return $this->index_documents_by_type($sources);
	}

	/**
	 * Index Documents by Specific Post Types
	 *
	 * @param array $post_types Array of post types to index.
	 * @return array Status of indexing operation
	 */
	public function index_documents_by_type($post_types = [])
	{
		global $wpdb;

		if (empty($post_types)) {
			return [
				'success' => false,
				'message' => 'No post types specified',
			];
		}

		$indexed = 0;
		$failed = 0;
		$errors = [];

		$this->update_metadata(
			'index_status',
			'running'
		);

		$this->update_metadata(
			'last_index_start',
			current_time('mysql')
		);

		$registered_types = get_post_types();
		$valid_post_types = array_filter(
			array_intersect($post_types, $registered_types),
			'post_type_exists'
		);
		$docs_table = esc_sql($wpdb->prefix . 'baca_rag_documents');

		/*
		 * Clean up post types that are not selected or no longer registered
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct query with safe dynamic table name.
		$db_post_types = $wpdb->get_col("SELECT DISTINCT post_type FROM {$docs_table}");

		if (!empty($db_post_types)) {
			foreach ($db_post_types as $db_post_type) {
				if (!in_array($db_post_type, $valid_post_types, true)) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database query on custom table.
					$doc_ids = $wpdb->get_col(
						$wpdb->prepare(
							"SELECT document_id FROM {$docs_table} WHERE post_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is dynamic but safe.
							$db_post_type
						)
					);
					if (!empty($doc_ids)) {
						foreach ($doc_ids as $doc_id) {
							$this->remove_document($doc_id);
						}
					}
				}
			}
		}

		foreach ($post_types as $post_type) {

			if (!in_array($post_type, $registered_types, true) || !post_type_exists($post_type)) {
				continue;
			}

			try {

				$posts = get_posts(
					[
						'post_type' => sanitize_text_field(
							$post_type
						),
						'post_status' => 'publish',
						'posts_per_page' => -1,
						'orderby' => 'ID',
						'order' => 'ASC',
						'suppress_filters' => false,
					]
				);

				/*
				 * Clean up stale or deleted/unpublished posts of this post type
				 */
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database read.
				$db_post_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT post_id FROM {$docs_table} WHERE post_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is dynamic but safe.
						$post_type
					)
				);
				$db_post_ids = array_map('intval', $db_post_ids);

				$active_post_ids = [];
				if (!empty($posts)) {
					foreach ($posts as $post) {
						if (!empty($post->ID)) {
							$active_post_ids[] = intval($post->ID);
						}
					}
				}

				$posts_to_remove = array_diff($db_post_ids, $active_post_ids);
				foreach ($posts_to_remove as $remove_post_id) {
					$this->remove_document('post_' . $remove_post_id);
				}

				if (empty($posts)) {
					continue;
				}

				foreach ($posts as $post) {

					try {

						if (
							empty($post->ID)
						) {
							continue;
						}

						$result = $this->index_post(
							$post
						);

						if ($result) {

							$indexed++;

						} else {

							$failed++;

							$errors[] =
								'Failed Post ID: ' .
								$post->ID;
						}

					} catch (Exception $e) {

						$failed++;

						$errors[] =
							'Post ' .
							$post->ID .
							': ' .
							$e->getMessage();
					}
				}

			} catch (Exception $e) {

				$failed++;

				$errors[] =
					'Post Type ' .
					$post_type .
					': ' .
					$e->getMessage();

			}
		}

		$this->update_metadata(
			'last_index_time',
			current_time('mysql')
		);

		$this->update_metadata(
			'last_indexed_count',
			$indexed
		);

		$this->update_metadata(
			'last_failed_count',
			$failed
		);

		$this->update_metadata(
			'index_status',
			'completed'
		);

		$message = sprintf(
			'Indexed %d documents. Failed %d documents.',
			$indexed,
			$failed
		);

		if (!empty($errors)) {

			$message .=
				' First Errors: ' .
				implode(
					' | ',
					array_slice(
						$errors,
						0,
						3
					)
				);
		}

		return [
			'success' => ($indexed > 0),
			'indexed' => $indexed,
			'failed' => $failed,
			'errors' => $errors,
			'message' => $message,
		];
	}

	/**
	 * Index Single Post
	 *
	 * @param WP_Post $post Post object.
	 * @return bool Success status
	 */
	public function index_post($post)
	{

		if (!$post instanceof WP_Post) {
			return false;
		}

		if ('publish' !== $post->post_status) {
			return false;
		}

		global $wpdb;
		$chunks_table = esc_sql($wpdb->prefix . 'baca_rag_chunks');

		try {

			$document_id = 'post_' . $post->ID;

			$current_hash = md5(
				$post->post_title .
				$post->post_content
			);

			$existing = $this->get_document(
				$document_id
			);

			if (
				$existing &&
				!empty($existing['hash']) &&
				$existing['hash'] === $current_hash
			) {
				return true;
			}

			/*
			 * Store document
			 */
			$this->store_document(
				[
					'document_id' => $document_id,
					'post_id' => $post->ID,
					'post_type' => $post->post_type,
					'title' => $post->post_title,
					'content' => $post->post_content,
					'excerpt' => $post->post_excerpt,
					'url' => get_permalink($post),
					'hash' => $current_hash,
					'indexed_at' => current_time('mysql'),
				]
			);

			/*
			 * Create chunks
			 */
			$chunks = $this->chunk_content(
				$post->post_content,
				$post->post_title
			);

			if (empty($chunks)) {
				return false;
			}

			/*
			 * Get embedding provider
			 */
			$embedding_provider =
				$this->embedding_provider;

			if (!$embedding_provider) {

				throw new Exception(
					'Embedding provider missing.'
				);
			}

			/*
			 * Get vector database
			 */
			$vector_db =
				$this->vector_db;

			if (!$vector_db || is_wp_error($vector_db)) {

				throw new Exception(
					'Vector DB missing.'
				);
			}

			foreach ($chunks as $index => $chunk_data) {

				/*
				 * Save chunk
				 */
				$chunk_id = $this->store_chunk(
					$document_id,
					$index,
					$chunk_data
				);

				if (empty($chunk_id)) {
					continue;
				}

				$content = is_array($chunk_data)
					? ($chunk_data['content'] ?? '')
					: $chunk_data;

				if (empty($content)) {
					continue;
				}

				/*
				 * Generate embedding
				 */
				$embedding =
					$embedding_provider
						->embed(
							$content
						);

				if (
					empty($embedding) ||
					!is_array($embedding)
				) {

					throw new Exception(
						'Embedding generation failed for chunk: ' .
						$chunk_id
					);
				}

				/*
				 * Store vector
				 */
				$result =
					$vector_db->store_embedding(
						(string) $chunk_id,
						$embedding,
						$document_id
					);

				if (is_wp_error($result)) {

					throw new Exception(
						'Vector storage failed: ' .
						$result->get_error_message()
					);
				}

				/*
				 * Mark completed so this chunk isn't re-embedded by the
				 * pending-embeddings batch.
				 */
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct update to custom table status.
				$wpdb->update(
					$chunks_table,
					[
						'embedding_status' => 'completed',
						'vector_id' => (string) $chunk_id,
					],
					[
						'id' => $chunk_id,
					]
				);
			}

			return true;

		} catch (Exception $e) {
			throw $e;
		}
	}

	/**
	 * Chunk Content
	 *
	 * Splits content into overlapping chunks.
	 *
	 * @param string $content Content to chunk.
	 * @param string $title   Document title (prepended to content).
	 * @return array Array of chunks
	 */
	private function chunk_content($content, $title = '')
	{
		// Combine title and content
		$full_content = $title . "\n\n" . $content;
		$full_content = wp_strip_all_tags($full_content);
		$full_content = preg_replace('/\s+/', ' ', $full_content); // Normalize whitespace

		$chunks = [];
		$length = mb_strlen($full_content);

		if ($length <= $this->chunk_size) {
			return [
				[
					'content' => $full_content,
					'chunk_hash' => md5($full_content),
					'tokens_count' => $this->estimate_tokens($full_content),
				]
			];
		}

		$start = 0;
		$index = 0;

		// Guard against a misconfigured chunk_overlap >= chunk_size, which
		// would make $start never advance and loop forever.
		$effective_overlap = min($this->chunk_overlap, max(0, $this->chunk_size - 1));

		while ($start < $length) {
			$end = min($start + $this->chunk_size, $length);
			$chunk = mb_substr($full_content, $start, $this->chunk_size);
			$chunks[] = [
				'content' => trim($chunk),
				'chunk_index' => $index,
				'chunk_hash' => md5($chunk),
				'tokens_count' => $this->estimate_tokens($chunk),
			];

			// Move start position with overlap
			$start += ($this->chunk_size - $effective_overlap);
			$index++;
		}

		return $chunks;
	}

	/**
	 * Estimate Tokens
	 *
	 * Rough estimate: ~4 characters = 1 token
	 *
	 * @param string $text Text to estimate.
	 * @return int Estimated token count
	 */
	private function estimate_tokens($text)
	{
		return ceil(strlen($text) / 4);
	}

	/**
	 * Store Document
	 *
	 * Stores document metadata.
	 *
	 * @param array $data Document data.
	 * @return bool|int Document ID on success
	 */
	private function store_document($data)
	{
		global $wpdb;

		try {
			$table = esc_sql($wpdb->prefix . 'baca_rag_documents');

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database query on custom table.
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE document_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is dynamic but safe.
					$data['document_id']
				)
			);

			// (Continue directly to insert_data, update/insert handles $existing check below)

			$insert_data = [
				'document_id' => $data['document_id'],
				'post_id' => $data['post_id'],
				'post_type' => $data['post_type'],
				'title' => $data['title'],
				'content' => $data['content'],
				'excerpt' => $data['excerpt'],
				'url' => $data['url'],
				'hash' => $data['hash'],
				'indexed_at' => $data['indexed_at'],
			];

			if ($existing) {
				// Update
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct update to custom table.
				$wpdb->update(
					$table,
					$insert_data,
					['document_id' => $data['document_id']],
					['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
				);
				return $existing->id;
			} else {
				// Insert
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct insert to custom table.
				$result = $wpdb->insert(
					$table,
					$insert_data,
					['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
				);
				if (false === $result) {
					return false;
				}
				return $wpdb->insert_id;
			}
		} catch (Exception $e) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('Botisst AI Indexer Store Document Error: ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Allowed under WP_DEBUG constraint.
			}
			return false;
		}
	}

	/**
	 * Store Chunk
	 *
	 * Stores a document chunk.
	 *
	 * @param string $document_id Document ID.
	 * @param int    $index       Chunk index.
	 * @param array  $chunk_data  Chunk data.
	 * @return bool|int Chunk ID on success
	 */
	private function store_chunk($document_id, $index, $chunk_data)
	{

		global $wpdb;

		try {

			$table = esc_sql(
				$wpdb->prefix . 'baca_rag_chunks'
			);

			/*
			 * Check existing chunk
			 */
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database query on custom table.
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE document_id = %s AND chunk_index = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is dynamic but safe.
					$document_id,
					$index
				)
			);

			$insert_data = [
				'document_id' => $document_id,
				'chunk_index' => $index,
				'content' => $chunk_data['content'] ?? '',
				'chunk_hash' => $chunk_data['chunk_hash']
					?? md5($chunk_data['content'] ?? ''),
				'tokens_count' => $chunk_data['tokens_count']
					?? $this->estimate_tokens(
						$chunk_data['content'] ?? ''
					),
				'embedding_status' => 'pending',
			];

			/*
			 * Update existing chunk
			 */
			if ($existing) {

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct update to custom table.
				$result = $wpdb->update(
					$table,
					$insert_data,
					[
						'id' => $existing,
					],
					[
						'%s',
						'%d',
						'%s',
						'%s',
						'%d',
						'%s',
					],
					[
						'%d',
					]
				);

				if (false === $result) {
					return false;
				}

				return (int) $existing;
			}

			/*
			 * Insert new chunk
			 */
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct insert to custom table.
			$result = $wpdb->insert(
				$table,
				$insert_data,
				[
					'%s',
					'%d',
					'%s',
					'%s',
					'%d',
					'%s',
				]
			);

			if (false === $result) {
				return false;
			}

			return (int) $wpdb->insert_id;

		} catch (Exception $e) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('Botisst AI Indexer Store Chunk Error: ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Allowed under WP_DEBUG constraint.
			}
			return false;
		}
	}

	/**
	 * Get Document
	 *
	 * @param string $document_id Document ID.
	 * @return array|null Document data or null
	 */
	private function get_document($document_id)
	{
		global $wpdb;

		$table = esc_sql($wpdb->prefix . 'baca_rag_documents');

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database query on custom table.
		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE document_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is dynamic but safe.
				$document_id
			),
			ARRAY_A
		);

		return $result;
	}

	/**
	 * Remove Document
	 *
	 * Removes a document and its chunks.
	 *
	 * @param string $document_id Document ID.
	 * @return bool Success status
	 */
	public function remove_document($document_id)
	{
		global $wpdb;

		$docs_table = esc_sql($wpdb->prefix . 'baca_rag_documents');
		$chunks_table = esc_sql($wpdb->prefix . 'baca_rag_chunks');

		// Get all chunk IDs first to delete from vector DB
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct read query from custom table.
		$chunk_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$chunks_table} WHERE document_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is dynamic but safe.
				$document_id
			)
		);

		if (!empty($chunk_ids) && $this->vector_db && !is_wp_error($this->vector_db)) {
			$this->vector_db->bulk_delete(array_map('intval', $chunk_ids));
		}

		// Remove chunks
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct deletion from custom table.
		$wpdb->delete(
			$chunks_table,
			['document_id' => $document_id],
			['%s']
		);

		// Remove document
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct deletion from custom table.
		$wpdb->delete(
			$docs_table,
			['document_id' => $document_id],
			['%s']
		);

		return true;
	}

	/**
	 * Generate Embeddings for Pending Chunks
	 *
	 * Processes at most $batch_size chunks per call so a full backlog is
	 * drained over several calls (chained via wp_schedule_single_event)
	 * instead of one long-running request.
	 *
	 * @param int $batch_size Maximum chunks to embed this call.
	 * @return array Status with count of processed chunks and how many remain.
	 */
	public function generate_pending_embeddings($batch_size = 50)
	{

		global $wpdb;

		require_once BACA_PATH .
			'includes/class-baca-embedding-manager.php';

		require_once BACA_PATH .
			'includes/class-baca-vector-db-factory.php';

		$chunks_table = esc_sql(
			$wpdb->prefix . 'baca_rag_chunks'
		);

		$settings = $this->get_settings();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database query on custom table.
		$pending_chunks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$chunks_table} WHERE embedding_status = 'pending' LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is dynamic but safe.
				max(1, (int) $batch_size)
			),
			ARRAY_A
		);

		if (empty($pending_chunks)) {

			$this->update_metadata('index_status', 'completed');

			return [
				'processed' => 0,
				'failed' => 0,
				'remaining' => 0,
				'message' => 'No pending chunks found',
			];
		}

		$embedding_manager =
			new BACA_Embedding_Manager();

		$provider =
			$settings['vector_db']['provider']
			?? 'sqlite';

		$config =
			$settings['vector_db']
			?? [];

		$vector_db =
			BACA_Vector_DB_Factory::create(
				$provider,
				$config
			);

		if (!$vector_db || is_wp_error($vector_db)) {

			return [
				'processed' => 0,
				'failed' => count($pending_chunks),
				'message' => 'Failed to initialize vector database',
			];
		}

		$processed = 0;
		$failed = 0;

		foreach ($pending_chunks as $chunk) {

			try {

				if (empty($chunk['content'])) {

					$failed++;

					continue;
				}

				/*
				 * Generate embedding
				 */
				$embedding =
					$embedding_manager->embed(
						$chunk['content']
					);

				if (
					empty($embedding) ||
					!is_array($embedding)
				) {

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct update to custom table status.
					$wpdb->update(
						$chunks_table,
						[
							'embedding_status' => 'failed',
						],
						[
							'id' => $chunk['id'],
						]
					);

					$failed++;

					continue;
				}

				/*
				 * Store vector
				 */
				$result =
					$vector_db->store_embedding(
						(string) $chunk['id'],
						$embedding,
						$chunk['document_id'] ?? ''
					);

				if (is_wp_error($result)) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct update to custom table status.
					$wpdb->update(
						$chunks_table,
						[
							'embedding_status' => 'failed',
						],
						[
							'id' => $chunk['id'],
						]
					);

					$failed++;

					continue;
				}

				/*
				 * Mark completed
				 */
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct update to custom table status.
				$wpdb->update(
					$chunks_table,
					[
						'embedding_status' => 'completed',
						'vector_id' => $chunk['id'],
					],
					[
						'id' => $chunk['id'],
					]
				);

				$processed++;

			} catch (Exception $e) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct update to custom table status.
				$wpdb->update(
					$chunks_table,
					[
						'embedding_status' => 'failed',
					],
					[
						'id' => $chunk['id'],
					]
				);

				$failed++;
			}
		}

		// Lock only the counter read-modify-write, not the embedding calls
		// above — those can safely run concurrently across ticks since each
		// chunk row is updated independently by its own ID.
		if ($this->acquire_index_lock()) {
			try {
				$this->update_metadata('embed_processed', (int) $this->get_metadata('embed_processed', 0) + $processed);
				$this->update_metadata('embed_failed', (int) $this->get_metadata('embed_failed', 0) + $failed);
			} finally {
				$this->release_index_lock();
			}
		}

		$remaining = $this->count_pending_embeddings();

		if (0 === $remaining) {
			$this->update_metadata('index_status', 'completed');
			$this->update_metadata('last_index_time', current_time('mysql'));
		}

		return [
			'processed' => $processed,
			'failed' => $failed,
			'remaining' => $remaining,
			'message' => sprintf(
				'Processed %d embeddings, failed %d',
				$processed,
				$failed
			),
		];
	}

	/**
	 * Update Metadata
	 *
	 * @param string $key   Metadata key.
	 * @param string $value Metadata value.
	 * @return void
	 */
	private function update_metadata($key, $value)
	{
		global $wpdb;

		$table = esc_sql($wpdb->prefix . 'baca_rag_metadata');

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database query on custom table.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (meta_key, meta_value) VALUES (%s, %s) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is dynamic but safe.
				$key,
				$value
			)
		);
	}

	/**
	 * Get Metadata
	 *
	 * @param string $key     Metadata key.
	 * @param mixed  $default Default value if the key has no stored value.
	 * @return mixed
	 */
	public function get_metadata($key, $default = null)
	{
		global $wpdb;

		$table = esc_sql($wpdb->prefix . 'baca_rag_metadata');

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database query on custom table.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$table} WHERE meta_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is dynamic but safe.
				$key
			)
		);

		return null === $value ? $default : $value;
	}

	/**
	 * Count Pending Embeddings
	 *
	 * @return int
	 */
	private function count_pending_embeddings()
	{
		global $wpdb;

		$chunks_table = esc_sql($wpdb->prefix . 'baca_rag_chunks');

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct count on custom table.
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$chunks_table} WHERE embedding_status = 'pending'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is dynamic but safe.
		);
	}

	/**
	 * Queue Index Job
	 *
	 * Cleans up stale documents and queues the post IDs that need
	 * (re)indexing. Does not touch the embedding API itself — actual
	 * indexing happens in small batches via process_index_batch(), so a
	 * full-site index never runs inside a single request.
	 *
	 * @param array $post_types Post types to index.
	 * @return array Queue summary, e.g. ['total' => 42].
	 */
	public function queue_index_job($post_types = [])
	{
		global $wpdb;

		if (empty($post_types)) {
			return ['total' => 0];
		}

		$registered_types = get_post_types();
		$valid_post_types = array_intersect($post_types, $registered_types);
		$docs_table = esc_sql($wpdb->prefix . 'baca_rag_documents');

		/*
		 * Clean up post types that are not selected or no longer registered
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct query with safe dynamic table name.
		$db_post_types = $wpdb->get_col("SELECT DISTINCT post_type FROM {$docs_table}");

		if (!empty($db_post_types)) {
			foreach ($db_post_types as $db_post_type) {
				if (!in_array($db_post_type, $valid_post_types, true)) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database query on custom table.
					$doc_ids = $wpdb->get_col(
						$wpdb->prepare(
							"SELECT document_id FROM {$docs_table} WHERE post_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is dynamic but safe.
							$db_post_type
						)
					);
					foreach ($doc_ids as $doc_id) {
						$this->remove_document($doc_id);
					}
				}
			}
		}

		$post_ids = [];

		foreach ($valid_post_types as $post_type) {

			$active_ids = get_posts(
				[
					'post_type' => sanitize_text_field($post_type),
					'post_status' => 'publish',
					'posts_per_page' => -1,
					'orderby' => 'ID',
					'order' => 'ASC',
					'fields' => 'ids',
					'suppress_filters' => false,
				]
			);

			/*
			 * Clean up stale or deleted/unpublished posts of this post type
			 */
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database read.
			$db_post_ids = array_map(
				'intval',
				$wpdb->get_col(
					$wpdb->prepare(
						"SELECT post_id FROM {$docs_table} WHERE post_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is dynamic but safe.
						$post_type
					)
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach (array_diff($db_post_ids, $active_ids) as $stale_id) {
				$this->remove_document('post_' . $stale_id);
			}

			$post_ids = array_merge($post_ids, $active_ids);
		}

		$post_ids = array_values(array_unique(array_map('intval', $post_ids)));

		/*
		 * Only queue posts that are actually new or whose content changed
		 * since the last index (same hash comparison index_post() uses).
		 * Already-indexed, unchanged posts are skipped entirely instead of
		 * being counted toward the progress total, so re-indexing after
		 * adding one more post type reports only the genuinely new items.
		 */
		$queue_ids = $this->filter_new_or_changed($post_ids);

		$this->update_metadata('index_queue', wp_json_encode($queue_ids));
		$this->update_metadata('index_status', empty($queue_ids) ? 'embedding' : 'indexing');
		$this->update_metadata('index_total', count($queue_ids));
		$this->update_metadata('index_processed', 0);
		$this->update_metadata('index_indexed', 0);
		$this->update_metadata('index_failed', 0);
		$this->update_metadata('last_index_start', current_time('mysql'));

		if (empty($queue_ids)) {
			$this->update_metadata('embed_total', $this->count_pending_embeddings());
			$this->update_metadata('embed_processed', 0);
			$this->update_metadata('embed_failed', 0);
		}

		return ['total' => count($queue_ids)];
	}

	/**
	 * Filter Post IDs Down to New or Changed
	 *
	 * Compares each post's current title+content hash against the hash
	 * already stored for it (if any) and keeps only posts that are new or
	 * have changed, so unchanged posts are neither re-embedded nor counted
	 * in the indexing progress total.
	 *
	 * @param array $post_ids Candidate post IDs (already filtered to active/published).
	 * @return array Post IDs that are new or whose content changed.
	 */
	private function filter_new_or_changed(array $post_ids)
	{
		global $wpdb;

		if (empty($post_ids)) {
			return [];
		}

		$id_placeholders = implode(',', array_fill(0, count($post_ids), '%d'));

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct read to compute content hashes for diffing; wp_posts is core.
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE ID IN ({$id_placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are prepared; table is $wpdb->posts.
				$post_ids
			),
			ARRAY_A
		);

		if (empty($posts)) {
			return [];
		}

		$current_hashes = [];
		foreach ($posts as $post_row) {
			$current_hashes['post_' . $post_row['ID']] = md5($post_row['post_title'] . $post_row['post_content']);
		}

		$document_ids = array_keys($current_hashes);
		$doc_placeholders = implode(',', array_fill(0, count($document_ids), '%s'));
		$docs_table = esc_sql($wpdb->prefix . 'baca_rag_documents');

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct read on custom table.
		$existing_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT document_id, hash FROM {$docs_table} WHERE document_id IN ({$doc_placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name is dynamic but safe; placeholders are prepared.
				$document_ids
			),
			ARRAY_A
		);

		$existing_hashes = [];
		foreach ($existing_rows as $row) {
			$existing_hashes[$row['document_id']] = $row['hash'];
		}

		$new_or_changed = [];
		foreach ($posts as $post_row) {
			$document_id = 'post_' . $post_row['ID'];
			if (
				!isset($existing_hashes[$document_id]) ||
				$existing_hashes[$document_id] !== $current_hashes[$document_id]
			) {
				$new_or_changed[] = (int) $post_row['ID'];
			}
		}

		return $new_or_changed;
	}

	/**
	 * Acquire the Indexing Lock
	 *
	 * Guards the index_queue read-modify-write cycle in process_index_batch()
	 * and the progress counters in generate_pending_embeddings() against two
	 * overlapping WP-Cron ticks (WordPress's pseudo-cron can be triggered by
	 * two concurrent page loads) clobbering each other's write. Uses the
	 * same transient-as-mutex pattern WordPress core itself uses in
	 * wp-cron.php to prevent double-spawned cron runs.
	 *
	 * @return bool True if the lock was acquired.
	 */
	private function acquire_index_lock()
	{
		if (false !== get_transient('baca_indexing_lock')) {
			return false;
		}

		set_transient('baca_indexing_lock', time(), 30);

		return true;
	}

	/**
	 * Release the Indexing Lock
	 *
	 * @return void
	 */
	private function release_index_lock()
	{
		delete_transient('baca_indexing_lock');
	}

	/**
	 * Process Index Batch
	 *
	 * Indexes (and embeds, via index_post()) a small batch of queued
	 * posts. Meant to be called repeatedly by a chained
	 * wp_schedule_single_event tick rather than in a loop over the full
	 * queue, so each PHP request only makes a handful of embedding API
	 * calls.
	 *
	 * @param int $batch_size Number of posts to process this call.
	 * @return array ['remaining' => int, 'indexed' => int, 'failed' => int]
	 */
	public function process_index_batch($batch_size = 5)
	{
		if (!$this->acquire_index_lock()) {
			// Another tick is actively writing the queue right now — skip
			// this run untouched; the caller's existing "remaining > 0"
			// reschedule logic will simply retry a few seconds later.
			return [
				'remaining' => count((array) json_decode((string) $this->get_metadata('index_queue', '[]'), true)),
				'indexed' => 0,
				'failed' => 0,
			];
		}

		try {
			$queue = json_decode((string) $this->get_metadata('index_queue', '[]'), true);

			if (!is_array($queue)) {
				$queue = [];
			}

			$batch = array_splice($queue, 0, max(1, (int) $batch_size));

			$indexed = 0;
			$failed = 0;

			foreach ($batch as $post_id) {

				$post = get_post((int) $post_id);

				if (!$post) {
					continue;
				}

				try {
					if ($this->index_post($post)) {
						$indexed++;
					} else {
						$failed++;
					}
				} catch (Exception $e) {
					$failed++;
				}
			}

			$this->update_metadata('index_queue', wp_json_encode($queue));
			$this->update_metadata('index_processed', (int) $this->get_metadata('index_processed', 0) + count($batch));
			$this->update_metadata('index_indexed', (int) $this->get_metadata('index_indexed', 0) + $indexed);
			$this->update_metadata('index_failed', (int) $this->get_metadata('index_failed', 0) + $failed);

			$remaining = count($queue);

			if (0 === $remaining) {
				$this->update_metadata('index_status', 'embedding');
				$this->update_metadata('embed_total', $this->count_pending_embeddings());
				$this->update_metadata('embed_processed', 0);
				$this->update_metadata('embed_failed', 0);
				$this->update_metadata('last_index_time', current_time('mysql'));
			}

			return [
				'remaining' => $remaining,
				'indexed' => $indexed,
				'failed' => $failed,
			];
		} finally {
			$this->release_index_lock();
		}
	}

	/**
	 * Get Index Progress
	 *
	 * Snapshot of the current (or most recent) background indexing job,
	 * used by the dashboard to poll and render progress.
	 *
	 * @return array
	 */
	public function get_index_progress()
	{
		return [
			'status' => (string) $this->get_metadata('index_status', 'idle'),
			'docs_total' => (int) $this->get_metadata('index_total', 0),
			'docs_processed' => (int) $this->get_metadata('index_processed', 0),
			'docs_indexed' => (int) $this->get_metadata('index_indexed', 0),
			'docs_failed' => (int) $this->get_metadata('index_failed', 0),
			'embed_total' => (int) $this->get_metadata('embed_total', 0),
			'embed_processed' => (int) $this->get_metadata('embed_processed', 0),
			'embed_failed' => (int) $this->get_metadata('embed_failed', 0),
			'last_index_start' => (string) $this->get_metadata('last_index_start', ''),
			'last_index_time' => (string) $this->get_metadata('last_index_time', ''),
		];
	}
}
