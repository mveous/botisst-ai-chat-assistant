<?php
/**
 * BACA REST Helpers
 *
 * Small helpers shared by the plugin's REST controllers (error responses,
 * conditional debug logging), so each controller doesn't reimplement them.
 *
 * @package Botisst
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Trait BACA_REST_Helpers
 */
trait BACA_REST_Helpers
{

	/**
	 * Log a debugging message when WP_DEBUG is enabled.
	 *
	 * Static so it can be called from both instance and static methods
	 * via self:: or $this->.
	 *
	 * @param string $message The message to log.
	 * @return void
	 */
	private static function log_debug($message)
	{
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log($message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Allowed under WP_DEBUG constraint.
		}
	}

	/**
	 * Build a standard {success: false, message} error response.
	 *
	 * @param string $message Human-readable error message.
	 * @param int    $status  HTTP status code.
	 * @return \WP_REST_Response
	 */
	private function error_response($message, $status = 500)
	{
		return new \WP_REST_Response(
			[
				'success' => false,
				'message' => $message,
			],
			$status
		);
	}
}
