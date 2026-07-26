<?php
/**
 * BACA MCP Server
 *
 * Smart pre-processing layer that reads site data and optimizes context
 * for AI queries, reducing tokens by ~80% and improving quality.
 *
 * @package Botisst
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BACA_MCP_Server
 */
class BACA_MCP_Server {

	/**
	 * Instance
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Cache group
	 *
	 * @var string
	 */
	private $cache_group = 'baca_mcp';

	/**
	 * Get Instance (Singleton)
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		// Cache hooks are registered globally in the main plugin class.
	}

	/**
	 * Get site context for injection into prompts
	 *
	 * @return string Formatted site data for context.
	 */
	public function get_site_context() {
		$cache_key = 'baca_site_context';
		$cached    = wp_cache_get( $cache_key, $this->cache_group );

		if ( false !== $cached ) {
			return $cached;
		}

		$site_analyzer = BACA_Site_Analyzer::get_instance();
		$site_type     = $site_analyzer->get_site_type();

		$site_data = $this->fetch_site_data( $site_type );

		if ( empty( $site_data ) || empty( $site_data['items'] ) ) {
			return '';
		}

		$context = "\n\n## Site Information\n";
		$context .= $site_data['summary'] . "\n";

		// Add items (products, posts, pages)
		if ( ! empty( $site_data['items'] ) ) {
			$context .= "\n### Available " . ( 'ecommerce' === $site_type ? 'Products' : 'Content' ) . ":\n";

			$count = 0;
			foreach ( $site_data['items'] as $item ) {
				if ( $count >= 5 ) {
					break; // Limit to 5 items to keep context compact
				}

				if ( 'ecommerce' === $site_type && ! empty( $item['price'] ) ) {
					$currency_symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';
					$context .= "- **" . sanitize_text_field( $item['name'] ) . "** (" . $currency_symbol . floatval( $item['price'] ) . "): " . sanitize_text_field( $item['description'] ) . "\n";
				} else {
					$context .= "- **" . sanitize_text_field( $item['title'] ?? $item['name'] ) . "**: " . sanitize_text_field( $item['description'] ?? $item['excerpt'] ?? '' ) . "\n";
				}

				$count++;
			}
		}

		// Add categories if available
		if ( ! empty( $site_data['categories'] ) ) {
			$context .= "\n### " . ( 'ecommerce' === $site_type ? 'Product Categories' : 'Content Categories' ) . ":\n";

			$count = 0;
			foreach ( $site_data['categories'] as $category ) {
				if ( $count >= 10 ) {
					break;
				}

				$context .= "- " . sanitize_text_field( $category['name'] ) . " (" . intval( $category['count'] ) . " items)\n";
				$count++;
			}
		}

		$context = trim( $context );

		// Cache for 1 day
		wp_cache_set( $cache_key, $context, $this->cache_group, 24 * HOUR_IN_SECONDS );

		return $context;
	}

	/**
	 * Fetch site data based on site type
	 *
	 * @param string $site_type Type of site.
	 * @return array Site data with summary, items, and categories.
	 */
	private function fetch_site_data( $site_type ) {
		$data = [
			'summary'    => '',
			'items'      => [],
			'categories' => [],
		];

		// Build summary
		$site_title       = get_bloginfo( 'name' );
		$site_description = get_bloginfo( 'description' );
		$data['summary']  = $site_title;
		if ( ! empty( $site_description ) ) {
			$data['summary'] .= ': ' . $site_description;
		}

		// Fetch items based on site type
		if ( 'ecommerce' === $site_type ) {
			$data['items']      = $this->fetch_products();
			$data['categories'] = $this->fetch_product_categories();
		} else {
			$data['items']      = $this->fetch_posts();
			$data['categories'] = $this->fetch_post_categories();
		}

		return $data;
	}

	/**
	 * Fetch products for ecommerce sites
	 *
	 * @return array Product data.
	 */
	private function fetch_products() {
		$products = get_posts(
			[
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 10,
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		// Check for WooCommerce products
		if ( class_exists( 'WooCommerce' ) ) {
			$result = [];
			foreach ( $products as $product ) {
				$product_obj = wc_get_product( $product->ID );
				if ( $product_obj ) {
					$result[] = [
						'name'        => $product->post_title,
						'description' => wp_strip_all_tags( $product->post_excerpt ),
						'price'       => $product_obj->get_price(),
						'url'         => get_permalink( $product->ID ),
					];
				}
			}
			return $result;
		}

		// Fallback to generic products
		$result = [];
		foreach ( $products as $product ) {
			$result[] = [
				'name'        => $product->post_title,
				'description' => wp_strip_all_tags( $product->post_excerpt ),
				'url'         => get_permalink( $product->ID ),
			];
		}

		return $result;
	}

	/**
	 * Fetch posts for blog/content sites
	 *
	 * @return array Post data.
	 */
	private function fetch_posts() {
		$posts = get_posts(
			[
				'post_type'      => [ 'post', 'page' ],
				'post_status'    => 'publish',
				'posts_per_page' => 10,
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		$result = [];
		foreach ( $posts as $post ) {
			$excerpt = ! empty( $post->post_excerpt ) ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( $post->post_content ), 20 );
			$result[] = [
				'title'       => $post->post_title,
				'description' => $excerpt,
				'excerpt'     => $excerpt,
				'url'         => get_permalink( $post->ID ),
			];
		}

		return $result;
	}

	/**
	 * Fetch product categories
	 *
	 * @return array Category data.
	 */
	private function fetch_product_categories() {
		$categories = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 10,
			]
		);

		if ( is_wp_error( $categories ) ) {
			return [];
		}

		$result = [];
		foreach ( $categories as $category ) {
			$result[] = [
				'name'  => $category->name,
				'count' => $category->count,
				'url'   => get_term_link( $category ),
			];
		}

		return $result;
	}

	/**
	 * Fetch post categories
	 *
	 * @return array Category data.
	 */
	private function fetch_post_categories() {
		$categories = get_terms(
			[
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'number'     => 10,
			]
		);

		if ( is_wp_error( $categories ) ) {
			return [];
		}

		$result = [];
		foreach ( $categories as $category ) {
			$result[] = [
				'name'  => $category->name,
				'count' => $category->count,
				'url'   => get_term_link( $category ),
			];
		}

		return $result;
	}

	/**
	 * Clean up legacy Site Data Cache entries from database
	 *
	 * @return void
	 */
	public static function cleanup_legacy_cache() {
		global $wpdb;

		// Remove old site data cache options
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Legacy cache cleanup, caching not applicable.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				'%baca_site_data_cache%'
			)
		);

		// Remove old transients
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Legacy transients cleanup, caching not applicable.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				'_transient_%baca_site_data%'
			)
		);

	}
}
