<?php
/**
 * BACA Vector DB - SQLite Adapter
 *
 * Local SQLite/MySQL implementation for storing and searching embeddings.
 *
 * @package Botisst
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once BACA_PATH . 'includes/class-baca-vector-db-base.php';

/**
 * Class BACA_Vector_DB_SQLite
 */
class BACA_Vector_DB_SQLite extends BACA_Vector_DB_Base {

	/**
	 * Store Embedding
	 *
	 * @param string $chunk_id   Chunk ID.
	 * @param array  $embedding  Embedding vector.
	 * @param string $document_id Document ID.
	 * @return bool|WP_Error Success status
	 */
	public function store_embedding( $chunk_id, $embedding, $document_id = '' ) {
		global $wpdb;

		$embeddings_table = esc_sql( $wpdb->prefix . 'baca_embeddings' );
		$chunks_table     = esc_sql( $wpdb->prefix . 'baca_rag_chunks' );

		$embedding_json = wp_json_encode( $embedding );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database write to custom table.
		$result = $wpdb->replace(
			$embeddings_table,
			[
				'chunk_id'  => $chunk_id,
				'embedding' => $embedding_json,
				'model'     => '',
				'provider'  => 'sqlite',
			],
			[ '%d', '%s', '%s', '%s' ]
		);

		if ( false === $result ) {
			return new WP_Error( 'sqlite_store_failed', 'Failed to save embedding in database.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database write to custom table.
		$wpdb->update(
			$chunks_table,
			[
				'vector_id'        => $chunk_id,
				'embedding_status' => 'completed',
			],
			[ 'id' => $chunk_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		return true;
	}

	/**
	 * Bulk Store Embeddings
	 *
	 * @param array $embeddings Array of embeddings.
	 * @return bool|WP_Error Success status
	 */
	public function bulk_store_embeddings( $embeddings ) {
		global $wpdb;

		$embeddings_table = esc_sql( $wpdb->prefix . 'baca_embeddings' );
		$chunks_table     = esc_sql( $wpdb->prefix . 'baca_rag_chunks' );

		foreach ( $embeddings as $chunk_id => $embedding_data ) {
			$embedding      = $embedding_data['vector'];
			$embedding_json = wp_json_encode( $embedding );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database write to custom table.
			$result = $wpdb->replace(
				$embeddings_table,
				[
					'chunk_id'  => $chunk_id,
					'embedding' => $embedding_json,
					'model'     => $embedding_data['model'] ?? '',
					'provider'  => 'sqlite',
				],
				[ '%d', '%s', '%s', '%s' ]
			);

			if ( false === $result ) {
				return new WP_Error( 'sqlite_bulk_store_failed', 'Failed to bulk save embeddings in database.' );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database write to custom table.
			$wpdb->update(
				$chunks_table,
				[
					'vector_id'        => $chunk_id,
					'embedding_status' => 'completed',
				],
				[ 'id' => $chunk_id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
		}

		return true;
	}

	/**
	 * Search Embeddings
	 *
	 * @param array $query_embedding Query embedding vector.
	 * @param int   $limit           Maximum results.
	 * @return array Search results
	 */
	public function search( $query_embedding, $limit = 5 ) {
		global $wpdb;

		if ( ! is_array( $query_embedding ) || empty( $query_embedding ) ) {
			return [];
		}

		$embeddings_table = esc_sql( $wpdb->prefix . 'baca_embeddings' );
		$chunks_table     = esc_sql( $wpdb->prefix . 'baca_rag_chunks' );

		// Fetch completed chunks and their embeddings using a JOIN
		// limit to 500 to keep performance reasonable for local PHP cosine calculations.
		// Ordered by most recently indexed first so which 500 chunks get
		// searched (on sites with more than 500) is deterministic instead
		// of an arbitrary/undefined subset.
		$query = "SELECT e.chunk_id, e.embedding FROM {$embeddings_table} e JOIN {$chunks_table} c ON e.chunk_id = c.id WHERE c.embedding_status = 'completed' ORDER BY c.created_at DESC, c.id DESC LIMIT 500"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are dynamic but safe.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Direct database read from custom table, query is safe.
		$results_db = $wpdb->get_results( $query, ARRAY_A );

		if ( empty( $results_db ) ) {
			return [];
		}

		$results = [];

		foreach ( $results_db as $row ) {
			$embedding = json_decode( $row['embedding'], true );

			if ( ! is_array( $embedding ) ) {
				continue;
			}

			$similarity = $this->cosine_similarity( $query_embedding, $embedding );

			$results[] = [
				'chunk_id'   => (int) $row['chunk_id'],
				'similarity' => $similarity,
			];
		}

		// Sort by similarity descending
		usort(
			$results,
			function ( $a, $b ) {
				return $b['similarity'] <=> $a['similarity'];
			}
		);

		return array_slice( $results, 0, $limit );
	}

	/**
	 * Cosine Similarity
	 *
	 * Calculates cosine similarity between two vectors.
	 *
	 * @param array $vector1 First vector.
	 * @param array $vector2 Second vector.
	 * @return float Similarity score (0-1)
	 */
	private function cosine_similarity( $vector1, $vector2 ) {
		if ( count( $vector1 ) !== count( $vector2 ) ) {
			return 0;
		}

		$dot_product = 0;
		$magnitude1  = 0;
		$magnitude2  = 0;

		for ( $i = 0; $i < count( $vector1 ); $i++ ) {
			$dot_product += $vector1[ $i ] * $vector2[ $i ];
			$magnitude1  += $vector1[ $i ] * $vector1[ $i ];
			$magnitude2  += $vector2[ $i ] * $vector2[ $i ];
		}

		$magnitude1 = sqrt( $magnitude1 );
		$magnitude2 = sqrt( $magnitude2 );

		if ( $magnitude1 === 0 || $magnitude2 === 0 ) {
			return 0;
		}

		return $dot_product / ( $magnitude1 * $magnitude2 );
	}

	/**
	 * Delete Embedding
	 *
	 * @param string $chunk_id Chunk ID.
	 * @return bool Success status
	 */
	public function delete_embedding( $chunk_id ) {
		global $wpdb;

		$embeddings_table = esc_sql( $wpdb->prefix . 'baca_embeddings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database write to custom table.
		$wpdb->delete(
			$embeddings_table,
			[ 'chunk_id' => $chunk_id ],
			[ '%d' ]
		);

		$chunks_table = esc_sql( $wpdb->prefix . 'baca_rag_chunks' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database write to custom table.
		$wpdb->update(
			$chunks_table,
			[
				'embedding_status' => 'pending',
				'vector_id'        => null,
			],
			[ 'id' => $chunk_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		return true;
	}

	/**
	 * Get Embedding
	 *
	 * @param string $chunk_id Chunk ID.
	 * @return array|null Embedding or null
	 */
	public function get_embedding( $chunk_id ) {
		global $wpdb;

		$embeddings_table = esc_sql( $wpdb->prefix . 'baca_embeddings' );
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database read from custom table.
		$embedding_json = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT embedding FROM {$embeddings_table} WHERE chunk_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is dynamic but safe.
				$chunk_id
			)
		);

		if ( ! $embedding_json ) {
			return null;
		}

		return json_decode( $embedding_json, true );
	}

	/**
	 * Bulk Delete
	 *
	 * @param array $chunk_ids Array of chunk IDs.
	 * @return bool Success status
	 */
	public function bulk_delete( $chunk_ids ) {
		global $wpdb;

		if ( empty( $chunk_ids ) ) {
			return true;
		}

		$embeddings_table = esc_sql( $wpdb->prefix . 'baca_embeddings' );
		$chunks_table     = esc_sql( $wpdb->prefix . 'baca_rag_chunks' );

		$placeholders = implode( ',', array_fill( 0, count( $chunk_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Direct database write to custom table.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$embeddings_table} WHERE chunk_id IN ($placeholders)", ...$chunk_ids ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Direct database write to custom table.
		$wpdb->query( $wpdb->prepare( "UPDATE {$chunks_table} SET embedding_status = 'pending', vector_id = NULL WHERE id IN ($placeholders)", ...$chunk_ids ) );

		return true;
	}

	/**
	 * Test Connection
	 *
	 * @return bool|WP_Error
	 */
	public function test_connection() {
		global $wpdb;
		$table_name = esc_sql( $wpdb->prefix . 'baca_embeddings' );

		// Safe check to verify table existence across any SQL driver engine
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct query on custom table, table name is dynamic but safe.
		$wpdb->query( "SELECT 1 FROM {$table_name} LIMIT 1" );

		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'sqlite_table_missing', 'Local vector table does not exist. Please reactivate the plugin.' );
		}

		return true;
	}

	/**
	 * Get Statistics
	 *
	 * @return array Statistics
	 */
	public function get_statistics() {
		global $wpdb;

		$chunks_table = esc_sql( $wpdb->prefix . 'baca_rag_chunks' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct query on custom table, table name is dynamic but safe.
		$total    = $wpdb->get_var( "SELECT COUNT(*) FROM {$chunks_table}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct query on custom table, table name is dynamic but safe.
		$embedded = $wpdb->get_var( "SELECT COUNT(*) FROM {$chunks_table} WHERE embedding_status = 'completed'" );

		return [
			'total_vectors' => intval( $total ),
			'embedded'      => intval( $embedded ),
			'pending'       => intval( $total - $embedded ),
		];
	}
}
