<?php
/**
 * BACA Retriever
 *
 * Handles document retrieval and similarity search for RAG system.
 *
 * @package Botisst
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BACA_Retriever
 */
class BACA_Retriever {

	/**
	 * Get Settings
	 *
	 * @return array
	 */
	private function get_settings() {
		$settings = BACA_Settings_Handler::baca_get_all_settings();
		return isset( $settings['rag'] ) ? $settings['rag'] : [];
	}

	/**
	 * Search for Relevant Documents
	 *
	 * @param array $query_embedding Query embedding vector.
	 * @param int   $limit           Maximum documents to return.
	 * @return array Array of relevant documents with content
	 */
	public function search( $query_embedding, $limit = 5 ) {
		if ( empty( $query_embedding ) || ! is_array( $query_embedding ) ) {
			return [];
		}

		$settings = $this->get_settings();

		// Get vector DB provider
		$vector_db_provider = ! empty( $settings['vector_db']['provider'] ) ? $settings['vector_db']['provider'] : 'sqlite';

		try {
			$vector_db = $this->get_vector_db( $vector_db_provider );
			if ( ! $vector_db ) {
				return [];
			}

			$results = $vector_db->search( $query_embedding, $limit );

			return $this->format_results( $results );
		} catch ( Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Botisst AI Retriever Search Error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Allowed under WP_DEBUG constraint.
			}
			return [];
		}
	}

	/**
	 * Get Vector DB Instance
	 *
	 * @param string $provider Vector DB provider name.
	 * @return object|null Vector DB instance
	 */
	private function get_vector_db( $provider ) {
		require_once BACA_PATH . 'includes/class-baca-vector-db-factory.php';

		$settings = $this->get_settings();
		$config   = ! empty( $settings['vector_db'] ) ? $settings['vector_db'] : [];

		return BACA_Vector_DB_Factory::create( $provider, $config );
	}

	/**
	 * Format Search Results
	 *
	 * @param array $results Raw search results.
	 * @return array Formatted results with content
	 */
	private function format_results( $results ) {
		if ( empty( $results ) ) {
			return [];
		}

		$formatted = [];
		global $wpdb;

		$chunks_table    = esc_sql( $wpdb->prefix . 'baca_rag_chunks' );
		$documents_table = esc_sql( $wpdb->prefix . 'baca_rag_documents' );

		$settings         = $this->get_settings();
		$configured_types = ! empty( $settings['post_types'] ) ? $settings['post_types'] : [ 'post', 'page' ];
		$registered_types = get_post_types();

		foreach ( $results as $result ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database query on custom table.
			$chunk = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$chunks_table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is dynamic but safe.
					$result['chunk_id']
				),
				ARRAY_A
			);

			if ( $chunk ) {
				// Get document info for URL, title and post_type
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database query on custom table.
				$document = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT title, url, post_type FROM {$documents_table} WHERE document_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is dynamic but safe.
						$chunk['document_id']
					),
					ARRAY_A
				);

				if ( ! $document ) {
					continue;
				}

				$post_type = $document['post_type'] ?? '';

				// Skip if post type is not configured or not currently registered (e.g. plugin deactivated)
				if ( ! in_array( $post_type, $configured_types, true ) || ! in_array( $post_type, $registered_types, true ) ) {
					continue;
				}

				$formatted[] = [
					'chunk_id'   => $chunk['id'],
					'content'    => $chunk['content'],
					'similarity' => $result['similarity'] ?? 0,
					'title'      => $document['title'] ?? 'Unknown',
					'url'        => $document['url'] ?? '',
				];
			}
		}

		return $formatted;
	}

	/**
	 * Get Statistics
	 *
	 * @return array Statistics about indexed data
	 */
	public function get_statistics() {
		global $wpdb;

		$chunks_table    = esc_sql( $wpdb->prefix . 'baca_rag_chunks' );
		$documents_table = esc_sql( $wpdb->prefix . 'baca_rag_documents' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table names, direct queries.
		$total_chunks = $wpdb->get_var( "SELECT COUNT(*) FROM {$chunks_table}" );
		$total_documents = $wpdb->get_var( "SELECT COUNT(*) FROM {$documents_table}" );
		$pending_chunks = $wpdb->get_var( "SELECT COUNT(*) FROM {$chunks_table} WHERE embedding_status = 'pending'" );
		$completed_chunks = $wpdb->get_var( "SELECT COUNT(*) FROM {$chunks_table} WHERE embedding_status = 'completed'" );
		$total_tokens = $wpdb->get_var( "SELECT SUM(tokens_count) FROM {$chunks_table}" );
		$indexed_post_types = $wpdb->get_col( "SELECT DISTINCT post_type FROM {$documents_table} WHERE post_type != ''" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return [
			'total_documents'   => $total_documents ?? 0,
			'total_chunks'      => $total_chunks ?? 0,
			'pending_embeddings' => $pending_chunks ?? 0,
			'completed_embeddings' => $completed_chunks ?? 0,
			'total_tokens'      => $total_tokens ?? 0,
			'indexed_post_types' => $indexed_post_types ?? [],
		];
	}
}
