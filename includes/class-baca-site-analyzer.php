<?php
/**
 * Site Analyzer: Detects site type (ecommerce, blog, service, general)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BACA_Site_Analyzer {
	private static $instance = null;
	private $transient_key = 'baca_site_type_detection';
	private $transient_duration = 3600; // 1 hour

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get detected or configured site type
	 */
	public function get_site_type() {
		$settings = get_option( 'baca_chat_assistant_settings', array() );

		// If admin manually set site type, respect it
		if ( ! empty( $settings['site_detection']['site_type_override'] ) ) {
			return $settings['site_detection']['site_type_override'];
		}

		// Otherwise, detect automatically
		return $this->detect_site_type();
	}

	/**
	 * Auto-detect site type based on installed plugins and post types
	 */
	public function detect_site_type() {
		// Check transient cache (1 hour)
		$cached = get_transient( $this->transient_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$site_type = 'general';

		// Check for WooCommerce (highest priority for ecommerce)
		if ( $this->is_woocommerce_active() ) {
			$site_type = 'ecommerce';
		}
		// Check for Easy Digital Downloads
		elseif ( $this->is_edd_active() ) {
			$site_type = 'ecommerce';
		}
		// Check for product post type (custom ecommerce)
		elseif ( post_type_exists( 'product' ) ) {
			$site_type = 'ecommerce';
		}
		// Check if it's primarily a blog (many posts, few pages)
		elseif ( $this->is_blog_type() ) {
			$site_type = 'blog';
		}
		// Check for service-focused setup
		elseif ( $this->is_service_type() ) {
			$site_type = 'service';
		}

		// Cache detection result
		set_transient( $this->transient_key, $site_type, $this->transient_duration );

		return $site_type;
	}

	/**
	 * Check if WooCommerce is active
	 */
	private function is_woocommerce_active() {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
	}

	/**
	 * Check if Easy Digital Downloads is active
	 */
	private function is_edd_active() {
		return class_exists( 'EDD_Session' ) || function_exists( 'edd_get_products' );
	}

	/**
	 * Detect if site is primarily a blog
	 */
	private function is_blog_type() {
		// Count posts vs pages
		$post_count = wp_count_posts( 'post' )->publish;
		$page_count = wp_count_posts( 'page' )->publish;

		// If more than 10 posts and posts > pages, it's likely a blog
		return $post_count > 10 && $post_count > $page_count;
	}

	/**
	 * Detect if site is service-focused
	 */
	private function is_service_type() {
		// Check for service-related custom post types or taxonomies
		$post_types = get_post_types( array( '_builtin' => false ), 'names' );

		foreach ( $post_types as $post_type ) {
			if ( stripos( $post_type, 'service' ) !== false ) {
				return true;
			}
		}

		return false;
	}

}
