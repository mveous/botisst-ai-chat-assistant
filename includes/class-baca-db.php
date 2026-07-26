<?php
/**
 * BACA Database Handler
 *
 * @package Botisst
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BACA_DB
 */
class BACA_DB {

	/**
	 * Create Tables
	 *
	 * Uses dbDelta to create or update the custom tables required.
	 *
	 * @return void
	 */
	public static function baca_create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$sessions_table  = $wpdb->prefix . 'baca_sessions';

		$sql_sessions = "CREATE TABLE `$sessions_table` (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id varchar(100) NOT NULL,
			email varchar(100) DEFAULT '' NOT NULL,
			model varchar(100) NOT NULL,
			provider varchar(100) NOT NULL,
			content longtext NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			status varchar(20) DEFAULT 'active' NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY session_id (session_id),
			KEY status (status)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_sessions );

		// RAG Tables
		$rag_documents_table = $wpdb->prefix . 'baca_rag_documents';
		$sql_rag_documents   = "CREATE TABLE `$rag_documents_table` (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			document_id varchar(255) NOT NULL,
			post_id bigint(20) unsigned NOT NULL,
			post_type varchar(100) NOT NULL,
			title text NOT NULL,
			content longtext NOT NULL,
			excerpt text,
			url varchar(2083),
			hash varchar(32),
			indexed_at datetime,
			PRIMARY KEY (id),
			UNIQUE KEY document_id (document_id),
			KEY post_id (post_id),
			KEY post_type (post_type)
		) $charset_collate;";

		dbDelta( $sql_rag_documents );

		$rag_chunks_table = $wpdb->prefix . 'baca_rag_chunks';
		$sql_rag_chunks   = "CREATE TABLE `$rag_chunks_table` (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			document_id varchar(255) NOT NULL,
			chunk_index int(11) NOT NULL,
			content longtext NOT NULL,
			chunk_hash varchar(32),
			tokens_count int(11),
			vector_id varchar(255),
			embedding_status varchar(20) DEFAULT 'pending',
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY document_id (document_id),
			KEY embedding_status (embedding_status),
			KEY vector_id (vector_id)
		) $charset_collate;";

		dbDelta( $sql_rag_chunks );

		$rag_metadata_table = $wpdb->prefix . 'baca_rag_metadata';
		$sql_rag_metadata   = "CREATE TABLE `$rag_metadata_table` (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			meta_key varchar(255) NOT NULL,
			meta_value longtext,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY meta_key (meta_key)
		) $charset_collate;";

		dbDelta( $sql_rag_metadata );

		$embeddings_table = $wpdb->prefix . 'baca_embeddings';
		$sql_embeddings   = "CREATE TABLE `$embeddings_table` (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			chunk_id bigint(20) unsigned NOT NULL,
			embedding longblob NOT NULL,
			model varchar(100),
			provider varchar(100),
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY chunk_id (chunk_id)
		) $charset_collate;";

		dbDelta( $sql_embeddings );

		// Add vector_id column if it doesn't exist (migration)
		$chunks_table = esc_sql( $wpdb->prefix . 'baca_rag_chunks' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for custom schema column check, caching is not applicable.
		$column_exists = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = %s AND COLUMN_NAME = %s AND TABLE_SCHEMA = DATABASE()",
			$chunks_table,
			'vector_id'
		) );

		if ( empty( $column_exists ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe schema alteration, no user input, caching not applicable.
			$wpdb->query( "ALTER TABLE `$chunks_table` ADD COLUMN `vector_id` varchar(255) AFTER `tokens_count`" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe schema alteration, no user input, caching not applicable.
			$wpdb->query( "ALTER TABLE `$chunks_table` ADD KEY `vector_id` (`vector_id`)" );
		}

		// Ensure RAG settings are initialized
		require_once BACA_PATH . 'includes/class-baca-settings-handler.php';
		$current_settings = get_option( 'baca_chat_assistant_settings', [] );
		if ( empty( $current_settings ) || empty( $current_settings['rag'] ) ) {
			$all_settings = BACA_Settings_Handler::baca_get_all_settings();
			update_option( 'baca_chat_assistant_settings', $all_settings );
		}
	}

	/**
	 * Save Message
	 *
	 * @param string $prompt     The user prompt.
	 * @param string $response   The AI response.
	 * @param string $session_id The session identifier.
	 * @param string $provider   The AI provider used.
	 * @param string $model      The AI model used.
	 * @param string $email      The user email.
	 * @return string The final session_id used.
	 */
	public function baca_save_message( $prompt, $response, $session_id = 'default', $provider = '', $model = '', $email = '' ) {
		$prompt     = sanitize_textarea_field( $prompt );
		$response   = wp_kses_post( $response );
		$session_id = sanitize_text_field( $session_id );
		$provider   = sanitize_text_field( $provider );
		$model      = sanitize_text_field( $model );
		$email      = sanitize_email( $email );

		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'baca_sessions' );
		$time  = current_time( 'mysql' );

		$cache_key   = 'baca_session_' . md5( $session_id );
		$cache_group = 'botisst_ai';

		$existing_messages = wp_cache_get( $cache_key, $cache_group );

		if ( false === $existing_messages ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for custom table query.
			$existing_messages = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT content FROM {$table} WHERE session_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
					$session_id
				)
			);
		}

		$new_messages = [
			[
				'role'       => 'user',
				'content'    => $prompt,
				'created_at' => $time,
			],
			[
				'role'       => 'assistant',
				'content'    => $response,
				'created_at' => $time,
			],
		];

		if ( $existing_messages ) {
			$messages = json_decode( $existing_messages, true );
			$messages = is_array( $messages ) ? $messages : [];

			$messages = array_merge( $messages, $new_messages );
			$messages = array_slice( $messages, -50 );

			$messages_json = wp_json_encode( $messages );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Updating custom table.
			$wpdb->update(
				$table,
				[
					'content'    => $messages_json,
					'provider'   => $provider,
					'model'      => $model,
					'email'      => $email,
					'updated_at' => $time,
				],
				[
					'session_id' => $session_id,
				],
				[ '%s', '%s', '%s', '%s', '%s' ],
				[ '%s' ]
			);

			wp_cache_set( $cache_key, $messages_json, $cache_group, HOUR_IN_SECONDS );

		} else {
			$messages_json = wp_json_encode( $new_messages );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Inserting into custom table.
			$wpdb->insert(
				$table,
				[
					'session_id' => $session_id,
					'email'      => $email,
					'model'      => $model,
					'provider'   => $provider,
					'content'    => $messages_json,
					'created_at' => $time,
					'updated_at' => $time,
				],
				[
					'%s',
					'%s',
					'%s',
					'%s',
					'%s',
					'%s',
					'%s',
				]
			);

			wp_cache_set( $cache_key, $messages_json, $cache_group, HOUR_IN_SECONDS );
		}

		return $session_id;
	}

	/**
	 * Get all chat sessions ordered by chronological activity.
	 *
	 * @param string|int $limit The limit of sessions to fetch.
	 * @param string     $order The sorting order ('ASC' or 'DESC').
	 * @return array<int, array<string, mixed>>
	 */
	public static function baca_get_all_sessions( $limit = '100', $order = 'desc' ) {
		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'baca_sessions' );

		// Normalize and validate order.
		$order_clean = esc_sql( strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC' );

		// Normalize and build limit SQL segment. A limit of exactly 0 (or a
		// negative value) should mean "zero rows", not "no LIMIT clause at
		// all" — so the LIMIT is always applied whenever $limit isn't 'all'.
		$limit_sql = esc_sql( '' );
		if ( $limit !== 'all' ) {
			$limit_val = max( 0, intval( $limit ) );
			$limit_sql = esc_sql( " LIMIT {$limit_val}" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table read.
		$sessions = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at {$order_clean}{$limit_sql}", ARRAY_A );

		return is_array( $sessions ) ? $sessions : [];
	}

	/**
	 * Delete a session by session_id.
	 *
	 * @param string $session_id The session identifier.
	 * @return bool True when a row was deleted.
	 */
	public static function baca_delete_session( $session_id ) {
		global $wpdb;

		$session_id = sanitize_text_field( $session_id );
		if ( empty( $session_id ) ) {
			return false;
		}

		$table = $wpdb->prefix . 'baca_sessions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table delete.
		$deleted = $wpdb->delete(
			$table,
			[ 'session_id' => $session_id ],
			[ '%s' ]
		);

		wp_cache_delete( 'baca_session_' . md5( $session_id ), 'botisst_ai' );

		return (bool) $deleted;
	}
}
