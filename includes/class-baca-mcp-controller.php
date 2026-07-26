<?php
/**
 * BACA MCP Controller
 *
 * Owns the admin-configured MCP server list: REST CRUD for that list, and
 * building MCP-derived context for chat prompts. Distinct from
 * BACA_MCP_Server (includes/mcp/class-baca-mcp-server.php), which builds
 * site-content context (posts/products) rather than managing this list.
 *
 * @package Botisst
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Class BACA_MCP_Controller
 */
class BACA_MCP_Controller
{
	use BACA_REST_Helpers;

	/**
	 * Key Store
	 *
	 * @var BACA_Key_Store
	 */
	private $key_store;

	/**
	 * Constructor
	 *
	 * @param BACA_Key_Store $key_store Used to encrypt/decrypt server API keys.
	 */
	public function __construct(BACA_Key_Store $key_store)
	{
		$this->key_store = $key_store;
	}

	/**
	 * REST callback: list configured MCP servers.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_servers($request)
	{
		$settings = BACA_Settings_Handler::baca_get_all_settings();
		$servers = isset($settings['mcp_servers']) ? $settings['mcp_servers'] : [];
		$servers = array_map([$this, 'redact_server'], $servers);

		return new \WP_REST_Response(['servers' => $servers], 200);
	}

	/**
	 * REST callback: add an MCP server.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response
	 */
	public function save_server($request)
	{
		$params = $request->get_json_params();

		$name = isset($params['name']) ? sanitize_text_field($params['name']) : '';
		$url = isset($params['url']) ? esc_url_raw($params['url']) : '';
		$type = isset($params['type']) ? sanitize_text_field($params['type']) : 'http';
		$apiKey = isset($params['apiKey']) ? sanitize_text_field($params['apiKey']) : '';
		$enabled = isset($params['enabled']) ? (bool) $params['enabled'] : true;
		$isDefault = isset($params['isDefault']) ? (bool) $params['isDefault'] : false;

		if (empty($name) || empty($url)) {
			return new \WP_REST_Response(
				['error' => esc_html__('Name and URL are required', 'botisst-ai-chat-assistant')],
				400
			);
		}

		$server_id = 'mcp_' . sanitize_title($name) . '_' . time();

		$settings = BACA_Settings_Handler::baca_get_all_settings();
		if (!isset($settings['mcp_servers'])) {
			$settings['mcp_servers'] = [];
		}

		$server = [
			'id' => $server_id,
			'name' => $name,
			'url' => $url,
			'type' => $type,
			'enabled' => $enabled,
			'isDefault' => $isDefault,
			'addedAt' => current_time('mysql'),
		];

		if (!empty($apiKey)) {
			$server['apiKey'] = $this->key_store->encrypt_secret($apiKey);
		}

		$settings['mcp_servers'][] = $server;
		BACA_Settings_Handler::baca_persist_settings($settings);

		return new \WP_REST_Response(
			[
				'success' => true,
				'message' => esc_html__('MCP server added successfully!', 'botisst-ai-chat-assistant'),
				'server' => $this->redact_server($server),
			],
			200
		);
	}

	/**
	 * REST callback: update an MCP server (currently: enabled flag only).
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response
	 */
	public function update_server($request)
	{
		$server_id = sanitize_text_field($request->get_param('id'));
		$params = $request->get_json_params();

		$settings = BACA_Settings_Handler::baca_get_all_settings();
		$servers = isset($settings['mcp_servers']) ? $settings['mcp_servers'] : [];

		$found = false;
		foreach ($servers as &$server) {
			if ($server['id'] === $server_id) {
				if (isset($params['enabled'])) {
					$server['enabled'] = (bool) $params['enabled'];
				}
				$found = true;
				break;
			}
		}

		if (!$found) {
			return new \WP_REST_Response(
				['error' => esc_html__('Server not found', 'botisst-ai-chat-assistant')],
				404
			);
		}

		$settings['mcp_servers'] = $servers;
		BACA_Settings_Handler::baca_persist_settings($settings);

		return new \WP_REST_Response(
			[
				'success' => true,
				'message' => esc_html__('MCP server updated successfully!', 'botisst-ai-chat-assistant'),
			],
			200
		);
	}

	/**
	 * REST callback: delete an MCP server.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response
	 */
	public function delete_server($request)
	{
		$server_id = sanitize_text_field($request->get_param('id'));

		$settings = BACA_Settings_Handler::baca_get_all_settings();
		$servers = isset($settings['mcp_servers']) ? $settings['mcp_servers'] : [];

		$filtered_servers = array_filter($servers, function ($server) use ($server_id) {
			return $server['id'] !== $server_id;
		});

		if (count($filtered_servers) === count($servers)) {
			return new \WP_REST_Response(
				['error' => esc_html__('Server not found', 'botisst-ai-chat-assistant')],
				404
			);
		}

		$settings['mcp_servers'] = array_values($filtered_servers);
		BACA_Settings_Handler::baca_persist_settings($settings);

		return new \WP_REST_Response(
			[
				'success' => true,
				'message' => esc_html__('MCP server deleted successfully!', 'botisst-ai-chat-assistant'),
			],
			200
		);
	}

	/**
	 * Build MCP-derived context for a chat prompt: site content context from
	 * BACA_MCP_Server, combined with a summary of enabled custom MCP
	 * servers. Both are included (not one as a fallback for the other) so a
	 * configured custom server is actually used even when the site already
	 * has content of its own — which is virtually always.
	 *
	 * @param string $prompt   User prompt.
	 * @param array  $settings Plugin settings.
	 * @return string Context to append to the system prompt, or ''.
	 */
	public function get_chat_context($prompt, $settings)
	{
		try {
			if (!class_exists('BACA_MCP_Server')) {
				require_once BACA_PATH . 'includes/mcp/class-baca-mcp-server.php';
			}

			$mcp_server = BACA_MCP_Server::get_instance();
			$site_context = $mcp_server ? $mcp_server->get_site_context() : '';

			$custom_context = $this->get_custom_servers_context($prompt, $settings);

			$context = $site_context;
			if (!empty($custom_context)) {
				$context .= "\n\n## Connected MCP Servers\n" . $custom_context;
			}

			return $context;
		} catch (Exception $e) {
			self::log_debug('Botisst AI MCP Context Retrieval Error: ' . $e->getMessage());
			return '';
		}
	}

	/**
	 * Summarize enabled custom MCP servers for the chat context.
	 *
	 * @param string $query    User query (currently unused, reserved for
	 *                         future per-server querying).
	 * @param array  $settings Plugin settings.
	 * @return string Context from custom MCP servers.
	 */
	private function get_custom_servers_context($query, $settings)
	{
		$mcp_servers = isset($settings['mcp_servers']) ? $settings['mcp_servers'] : [];

		if (empty($mcp_servers)) {
			return '';
		}

		$context = '';
		foreach ($mcp_servers as $server) {
			if (!empty($server['enabled'])) {
				// Custom MCP server integration point
				$context .= "MCP Server: " . sanitize_text_field($server['name']) . "\n";
			}
		}

		return $context;
	}

	/**
	 * Redact MCP Server Secret
	 *
	 * The stored apiKey (even encrypted) should never round-trip back to
	 * the browser — callers only need to know whether one is set.
	 *
	 * @param array $server MCP server record.
	 * @return array Server record safe to return over the REST API.
	 */
	private function redact_server($server)
	{
		$server['hasApiKey'] = !empty($server['apiKey']);
		unset($server['apiKey']);

		return $server;
	}
}
