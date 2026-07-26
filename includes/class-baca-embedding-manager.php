<?php
/**
 * BACA Embedding Manager
 *
 * Manages embedding generation from multiple providers.
 *
 * @package Botisst
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BACA_Embedding_Manager
 */
class BACA_Embedding_Manager {

	/**
	 * Get Settings
	 *
	 * @return array
	 */
	private function get_settings() {
		$settings = BACA_Settings_Handler::baca_get_all_settings();
		$rag_settings = isset( $settings['rag'] ) ? $settings['rag'] : [];

		if ( empty( $rag_settings['embeddings'] ) ) {
			$rag_settings['embeddings'] = [];
		}

		if ( empty( $rag_settings['embeddings']['provider'] ) ) {
			$rag_settings['embeddings']['provider'] = 'openai';
		}

		$provider = $rag_settings['embeddings']['provider'];

		if ( $provider === 'google' ) {
			if ( empty( $rag_settings['embeddings']['model'] ) ) {
				$rag_settings['embeddings']['model'] = 'models/gemini-embedding-001';
			}
			$rag_settings['embeddings']['api_key_type'] = 'google';
		} elseif ( $provider === 'local' ) {
			if ( empty( $rag_settings['embeddings']['model'] ) ) {
				$rag_settings['embeddings']['model'] = 'local-embedding';
			}
			$rag_settings['embeddings']['api_key_type'] = 'local';
		} else {
			// default to openai
			$rag_settings['embeddings']['provider'] = 'openai';
			if ( empty( $rag_settings['embeddings']['model'] ) ) {
				$rag_settings['embeddings']['model'] = 'text-embedding-3-small';
			}
			$rag_settings['embeddings']['api_key_type'] = 'openai';
		}

		return $rag_settings;
	}

	/**
	 * Generate Embedding
	 *
	 * @param string $text Text to embed.
	 * @return array|null Embedding vector or null on failure
	 */
	public function embed( $text ) {
		if ( empty( $text ) ) {
			return null;
		}

		$settings = $this->get_settings();
		$provider = ! empty( $settings['embeddings']['provider'] ) ? $settings['embeddings']['provider'] : 'google';

		try {
			$embedding = null;

			switch ( $provider ) {
				case 'google':
					$embedding = $this->embed_google( $text );
					break;
				case 'openai':
					$embedding = $this->embed_openai( $text );
					break;
				case 'local':
					$embedding = $this->embed_local( $text );
					break;
				default:
					$embedding = $this->embed_google( $text );
			}

			return $embedding;
		} catch ( Exception $e ) {
			throw $e;
		}
	}

	/**
	 * Embed Using OpenAI
	 *
	 * @param string $text Text to embed.
	 * @return array|null Embedding vector
	 */
	private function embed_openai( $text ) {
		$api_key = BACA_Settings_Handler::baca_get_provider_key( 'openai' );

		if ( empty( $api_key ) ) {
			return null;
		}

		$settings = $this->get_settings();
		$model = ! empty( $settings['embeddings']['model'] ) ? $settings['embeddings']['model'] : 'text-embedding-3-small';

		$response = wp_remote_post(
			'https://api.openai.com/v1/embeddings',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'input' => $text,
						'model' => $model,
					]
				),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'OpenAI Embedding Network Error: ' . esc_html( $response->get_error_message() ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $data['error'] ) ) {
			$error_msg = isset($data['error']['message']) ? $data['error']['message'] : wp_json_encode($data['error']);
			throw new Exception( 'OpenAI API Error: ' . esc_html( $error_msg ) );
		}

		if ( ! empty( $data['data'][0]['embedding'] ) ) {
			return $data['data'][0]['embedding'];
		}

		return null;
	}

	/**
	 * Embed Using Google
	 *
	 * @param string $text Text to embed.
	 * @return array|null Embedding vector
	 */
	private function embed_google( $text ) {
		$api_key = BACA_Settings_Handler::baca_get_provider_key( 'google' );

		if ( empty( $api_key ) ) {
			return null;
		}

		$settings = $this->get_settings();
		$model = ! empty( $settings['embeddings']['model'] ) ? $settings['embeddings']['model'] : 'gemini-embedding-001';
		
		// Map legacy/discontinued models to the active gemini-embedding-001
		if ( in_array( $model, [ 'embedding-001', 'models/embedding-001', 'text-embedding-004', 'models/text-embedding-004' ], true ) ) {
			$model = 'gemini-embedding-001';
		}
		
		$model_path = strpos( $model, 'models/' ) === 0 ? $model : 'models/' . $model;

		$response = wp_remote_post(
			'https://generativelanguage.googleapis.com/v1beta/' . $model_path . ':embedContent?key=' . $api_key,
			[
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'model'   => $model_path,
						'content' => [
							'parts' => [
								[
									'text' => $text,
								],
							],
						],
						'outputDimensionality' => 768,
					]
				),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Google Embedding Network Error: ' . esc_html( $response->get_error_message() ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! empty( $data['error'] ) ) {
			$error_msg = isset($data['error']['message']) ? $data['error']['message'] : wp_json_encode($data['error']);
			throw new Exception( 'Google API Error: ' . esc_html( $error_msg ) );
		}

		if ( ! empty( $data['embedding']['values'] ) ) {
			return $data['embedding']['values'];
		}

		return null;
	}

	/**
	 * Embed Using Local Model
	 *
	 * For now, returns a dummy implementation.
	 * In production, integrate with sentence-transformers or similar.
	 *
	 * @param string $text Text to embed.
	 * @return array|null Embedding vector
	 */
	private function embed_local( $text ) {
		// Simple hash-based mock embedding (would use actual ML model in production)
		$settings = BACA_Settings_Handler::baca_get_all_settings();
		$dimensions = ! empty( $settings['rag']['embeddings']['dimensions'] ) ? (int) $settings['rag']['embeddings']['dimensions'] : 768;

		$hash = md5( $text );
		$values = [];
		for ( $i = 0; $i < $dimensions; $i++ ) {
			$chunk = substr( $hash, $i % strlen( $hash ), 2 );
			$values[] = ( hexdec( $chunk ) - 128 ) / 256; // Normalize to -0.5 to 0.5
		}
		return $values;
	}

	/**
	 * Get Embedding Model Info
	 *
	 * @return array Model information
	 */
	public function get_model_info() {
		$settings = $this->get_settings();
		$provider = ! empty( $settings['embeddings']['provider'] ) ? $settings['embeddings']['provider'] : 'openai';
		$model    = ! empty( $settings['embeddings']['model'] ) ? $settings['embeddings']['model'] : '';

		$info = [
			'provider' => $provider,
			'model'    => $model,
		];

		// Add dimension info based on model
		switch ( $provider ) {
			case 'openai':
				if ( 'text-embedding-3-large' === $model ) {
					$info['dimensions'] = 3072;
				} else {
					$info['dimensions'] = 1536;
				}
				break;
			case 'google':
				$info['dimensions'] = 768;
				break;
			case 'local':
				$info['dimensions'] = 1024;
				break;
		}

		return $info;
	}
}
