<?php
/**
 * BACA Settings Handler
 *
 * Composition root and REST route registrar. Owns the plugin's settings
 * option (get/persist) and the handful of generic settings endpoints
 * (chatbot config, display config, setup wizard, uploads). Chat, RAG, MCP,
 * and API-key concerns each live in their own collaborator class — see
 * class-baca-chat-controller.php, class-baca-rag-controller.php,
 * class-baca-mcp-controller.php, and class-baca-key-store.php.
 *
 * @package Botisst
 */

if (!defined('ABSPATH')) {
	exit;
}

require_once BACA_PATH . 'includes/trait-baca-rest-helpers.php';
require_once BACA_PATH . 'includes/class-baca-key-store.php';
require_once BACA_PATH . 'includes/class-baca-mcp-controller.php';
require_once BACA_PATH . 'includes/class-baca-rag-controller.php';
require_once BACA_PATH . 'includes/class-baca-chat-controller.php';

/**
 * Class BACA_Settings_Handler
 */
class BACA_Settings_Handler
{
	use BACA_REST_Helpers;

	/**
	 * Key Store
	 *
	 * @var BACA_Key_Store
	 */
	private $key_store;

	/**
	 * RAG Controller
	 *
	 * @var BACA_RAG_Controller
	 */
	private $rag_controller;

	/**
	 * MCP Controller
	 *
	 * @var BACA_MCP_Controller
	 */
	private $mcp_controller;

	/**
	 * Chat Controller
	 *
	 * @var BACA_Chat_Controller
	 */
	private $chat_controller;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->key_store = new BACA_Key_Store();
		$this->rag_controller = new BACA_RAG_Controller();
		$this->mcp_controller = new BACA_MCP_Controller($this->key_store);
		$this->chat_controller = new BACA_Chat_Controller($this->rag_controller, $this->mcp_controller);

		add_action('rest_api_init', [$this, 'baca_register_routes']);
	}

	/**
	 * Register REST Routes
	 *
	 * Registers all core settings and chat capability endpoints under the botisst-ai-chat-assistant namespace.
	 *
	 * @return void
	 */
	public function baca_register_routes()
	{
		register_rest_route(
			'baca/v1',
			'/save-settings',
			[
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => [$this->key_store, 'save_provider_keys'],
				'permission_callback' => [$this, 'baca_permission_only_admins'],
			]
		);

		register_rest_route(
			'baca/v1',
			'/save-bot-settings',
			[
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => [$this, 'baca_save_bot_settings'],
				'permission_callback' => [$this, 'baca_permission_only_admins'],
			]
		);

		register_rest_route(
			'baca/v1',
			'/setup-wizard',
			[
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => [$this, 'baca_update_setup_wizard_status'],
				'permission_callback' => [$this, 'baca_permission_only_admins'],
			]
		);

		register_rest_route(
			'baca/v1',
			'/reset-key',
			[
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => [$this->key_store, 'reset_key'],
				'permission_callback' => [$this, 'baca_permission_only_admins'],
			]
		);

		register_rest_route(
			'baca/v1',
			'/save-display-settings',
			[
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => [$this, 'baca_save_display_settings'],
				'permission_callback' => [$this, 'baca_permission_only_admins'],
			]
		);

		register_rest_route(
			'baca/v1',
			'/upload',
			[
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => [$this, 'baca_upload_file'],
				'permission_callback' => [$this, 'baca_permission_upload'],
			]
		);

		register_rest_route(
			'baca/v1',
			'/chat',
			[
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => [$this->chat_controller, 'handle'],
				'permission_callback' => [$this->chat_controller, 'permission_check'],
			]
		);

		register_rest_route(
			'baca/v1',
			'/delete-session',
			[
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => [$this->chat_controller, 'delete_session'],
				'permission_callback' => [$this, 'baca_permission_only_admins'],
			]
		);

		register_rest_route(
			'baca/v1',
			'/sessions',
			[
				'methods' => \WP_REST_Server::READABLE,
				'callback' => [$this->chat_controller, 'get_sessions'],
				'permission_callback' => [$this, 'baca_permission_only_admins'],
			]
		);

		register_rest_route(
			'baca/v1',
			'/clear-session',
			[
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => [$this->chat_controller, 'clear_session'],
				'permission_callback' => [$this->chat_controller, 'permission_check'],
			]
		);

		register_rest_route(
			'baca/v1',
			'/rag/settings',
			[
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => [$this->rag_controller, 'save_settings'],
				'permission_callback' => [$this, 'baca_permission_only_admins'],
			]
		);

		register_rest_route(
			'baca/v1',
			'/rag/post-types',
			[
				'methods' => \WP_REST_Server::READABLE,
				'callback' => [$this->rag_controller, 'get_post_types'],
				'permission_callback' => [$this, 'baca_permission_only_admins'],
			]
		);

		register_rest_route(
			'baca/v1',
			'/rag/stats',
			[
				'methods' => \WP_REST_Server::READABLE,
				'callback' => [$this->rag_controller, 'get_stats'],
				'permission_callback' => [$this, 'baca_permission_only_admins'],
			]
		);

		register_rest_route(
			'baca/v1',
			'/rag/index',
			[
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => [$this->rag_controller, 'index_content'],
				'permission_callback' => [$this, 'baca_permission_only_admins'],
			]
		);

		register_rest_route(
			'baca/v1',
			'/rag/index/status',
			[
				'methods' => \WP_REST_Server::READABLE,
				'callback' => [$this->rag_controller, 'get_index_status'],
				'permission_callback' => [$this, 'baca_permission_only_admins'],
			]
		);

		register_rest_route(
			'baca/v1',
			'/mcp/servers',
			[
				'methods' => \WP_REST_Server::READABLE,
				'callback' => [$this->mcp_controller, 'get_servers'],
				'permission_callback' => [$this, 'baca_permission_only_admins'],
			]
		);

		register_rest_route(
			'baca/v1',
			'/mcp/servers',
			[
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => [$this->mcp_controller, 'save_server'],
				'permission_callback' => [$this, 'baca_permission_only_admins'],
			]
		);

		register_rest_route(
			'baca/v1',
			'/mcp/servers/(?P<id>[a-zA-Z0-9_-]+)',
			[
				'methods' => \WP_REST_Server::EDITABLE,
				'callback' => [$this->mcp_controller, 'update_server'],
				'permission_callback' => [$this, 'baca_permission_only_admins'],
			]
		);

		register_rest_route(
			'baca/v1',
			'/mcp/servers/(?P<id>[a-zA-Z0-9_-]+)',
			[
				'methods' => \WP_REST_Server::DELETABLE,
				'callback' => [$this->mcp_controller, 'delete_server'],
				'permission_callback' => [$this, 'baca_permission_only_admins'],
			]
		);

		register_rest_route(
			'baca/v1',
			'/all-content',
			[
				'methods' => \WP_REST_Server::READABLE,
				'callback' => [$this, 'get_all_content'],
				'permission_callback' => [$this, 'baca_permission_only_admins'],
			]
		);
	}

	/**
	 * Get all public content (pages, posts, CPTs) for exclusion dropdown.
	 */
	public function get_all_content($request) {
		$post_types = get_post_types(['public' => true, 'exclude_from_search' => false], 'names');
		unset($post_types['attachment']);
		
		$args = [
			'post_type' => array_values($post_types),
			'post_status' => 'any',
			'posts_per_page' => 500,
			'orderby' => 'title',
			'order' => 'ASC',
		];
		
		$query = new \WP_Query($args);
		$items = [];
		foreach ($query->posts as $post) {
			$type_obj = get_post_type_object($post->post_type);
			$items[] = [
				'id' => $post->ID,
				'title' => $post->post_title ? $post->post_title : 'ID ' . $post->ID,
				'type' => $type_obj && isset($type_obj->labels->singular_name) ? $type_obj->labels->singular_name : ucfirst($post->post_type),
			];
		}
		
		return new \WP_REST_Response($items, 200);
	}

	/**
	 * Main Permission Check
	 *
	 * @return bool True if current user is an admin.
	 */
	public function baca_permission_only_admins()
	{
		return current_user_can('manage_options');
	}

	/**
	 * Upload Permission Check
	 *
	 * @return bool True if current user is an admin.
	 */
	public function baca_permission_upload()
	{
		return current_user_can('manage_options');
	}

	/**
	 * Get Unified Settings
	 *
	 * @return array The complete multidimensional array of plugin settings.
	 */
	public static function baca_get_all_settings()
	{
		$defaults = [
			'models' => [
				'openai' => 'gpt-4o-mini',
				'google' => 'gemini-2.5-flash',
			],
			'chatbot' => [
				'bot_name' => 'Botisst',
				'primary_color' => '#6366f1',
				'greeting_msg' => 'Hello! I am your AI assistant. How can I help you today?',
				'bot_avatar' => '',
				'bubble_style' => 'rounded',
				'api_error_msg' => 'There is some error on server, please contact our [support agent]({support_url}).',
				'support_url' => home_url('/support'),
				'pre_question_1' => '',
				'pre_question_2' => '',
				'pre_question_3' => '',
				'pre_question_4' => '',
				'pre_questions_bg_color' => '#ffffff',
				'pre_questions_text_color' => '#475569',
				'pre_questions_border_color' => '#e2e8f0',
				'pre_questions_border_radius' => 'rounded',
				'system_prompt' => 'You are a knowledgeable expert helping people. Rules: 1) NEVER use phrases like "Based on provided content", "According to", "The information shows" - just answer directly like a human. 2) Greetings → "How can I help you today?" 3) If user asks for links/posts → GIVE ALL links immediately. 4) If user mentions topic → GIVE that post\'s link automatically. 5) Answer naturally, full sentences, conversational. 6) Include links in responses when relevant. 7) Be proactive - solve problems, don\'t ask questions. 8) Sound genuinely knowledgeable and helpful, like a real expert talking to you. 9) FOR "HOW TO" OR "HOW DO I" QUESTIONS: Use concise structure - Title, brief description (1-2 sentences), then mention relevant documentation/guides. 10) Keep "what is" explanations concise and natural (2-3 sentences max).',
				'temperature' => 0.7,
				'max_tokens' => 500,
				'save_chat' => false,
				'ask_email' => false,
				'enable_pre_questions' => false,
				'enable_uploads' => false,
				'save_uploads' => false,
				'max_upload_size' => 5,
				'default_provider' => 'openai',
				'knowledge_text' => '',
				'knowledge_urls' => [],
				'training_files' => [],
				'rate_limit_per_minute' => 20,
			],
			'display' => [
				'entire_site' => true,
				'exclude_pages' => '',
				'position' => 'bottom-right',
				'show_on_mobile' => true,
				'trigger_type' => 'click',
				'trigger_delay' => 5,
				'launcher_text' => 'Chat with us',
			],
			'rag' => [
				'enabled' => true,
				'post_types' => ['post', 'page'],
				'chunk_size' => 1000,
				'max_results' => 5,
				'auto_index' => true,
				'require_indexed_data' => false,
				'no_data_message' => 'I don\'t have information about your question in my knowledge base. Please rephrase or ask about topics I have knowledge of.',
				'vector_db' => ['provider' => 'sqlite'],
				'indexing' => [
					'chunk_size' => 1000,
					'chunk_overlap' => 100,
					'auto_update' => true,
				],
				'embeddings' => [
					'provider' => '',
					'model' => '',
				],
				'max_tokens' => '',
			],
			'mcp_servers' => [],
		];

		$settings = get_option('baca_chat_assistant_settings', []);

		foreach ($defaults as $key => $default_value) {
			if (!isset($settings[$key])) {
				$settings[$key] = $default_value;
			} elseif (is_array($default_value) && is_array($settings[$key])) {
				$settings[$key] = wp_parse_args($settings[$key], $default_value);
			}
		}

		return $settings;
	}

	/**
	 * Persist Settings
	 *
	 * Single write path for the plugin's settings option, used in place of
	 * calling update_option() directly. Two things worth knowing about this
	 * option:
	 *
	 * - It's stored with autoload disabled. It's a fairly large array (all
	 *   provider/RAG/chatbot/MCP config) that's only actually needed on this
	 *   plugin's own admin screen and REST requests — update_option()'s
	 *   default of autoloading it would otherwise pull the whole blob into
	 *   the alloptions cache on every single request, including the public
	 *   frontend. (WordPress 6.6+ honors the autoload param here even when
	 *   the option already exists; on older versions this is a no-op and
	 *   the option keeps whatever autoload it was first created with.)
	 * - AI provider API keys (BACA_Key_Store::get_provider_key()) are stored
	 *   in this option in plaintext, the same way most WordPress plugins
	 *   store third-party credentials. They're read on every chat request to
	 *   authenticate outbound API calls, so keeping them in plaintext here
	 *   (rather than encrypted, as done for the MCP server API key via
	 *   BACA_Key_Store::encrypt_secret()) is a deliberate simplicity/
	 *   performance tradeoff, not an oversight — protecting them is a matter
	 *   of standard WP database access control, same as any other stored
	 *   credential.
	 *
	 * @param array $settings Full settings array to persist.
	 * @return void
	 */
	public static function baca_persist_settings($settings)
	{
		update_option('baca_chat_assistant_settings', $settings, false);
	}

	/**
	 * Get specific provider key.
	 *
	 * Thin proxy to BACA_Key_Store, kept here (and public static) because
	 * other classes across the plugin already call it as
	 * BACA_Settings_Handler::baca_get_provider_key().
	 *
	 * @param string $provider Identifier for the AI provider.
	 * @return string The raw API key string if available.
	 */
	public static function baca_get_provider_key($provider)
	{
		return BACA_Key_Store::get_provider_key($provider);
	}

	/**
	 * Get the list of available models for an AI provider.
	 *
	 * Thin proxy to BACA_Key_Store, kept here (and public static) because
	 * other classes across the plugin already call it as
	 * BACA_Settings_Handler::baca_get_models().
	 *
	 * @param string $provider Provider identifier (e.g. 'openai').
	 * @return array Map of model ID => model display name.
	 */
	public static function baca_get_models($provider)
	{
		return BACA_Key_Store::get_models($provider);
	}

	/**
	 * Save Chatbot Config Profile Settings
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response Standard API response.
	 */
	public function baca_save_bot_settings($request)
	{
		$params = $request->get_json_params();

		// Clean knowledge URLs array safely.
		$urls = [];
		if (isset($params['knowledge_urls'])) {
			foreach ((array) $params['knowledge_urls'] as $url) {
				$clean = esc_url_raw($url);
				if (!empty($clean)) {
					$urls[] = $clean;
				}
			}
		}

		// Clean training files safe ID array.
		$files = [];
		if (isset($params['training_files'])) {
			foreach ((array) $params['training_files'] as $file_id) {
				$files[] = absint($file_id);
			}
		}

		$settings = self::baca_get_all_settings();
		$existing_chatbot = isset($settings['chatbot']) ? $settings['chatbot'] : [];

		$chatbot_settings = [
			'bot_name' => isset($params['bot_name']) ? sanitize_text_field($params['bot_name']) : (isset($existing_chatbot['bot_name']) ? $existing_chatbot['bot_name'] : 'Botisst'),
			'primary_color' => isset($params['primary_color']) ? sanitize_hex_color($params['primary_color']) : (isset($existing_chatbot['primary_color']) ? $existing_chatbot['primary_color'] : '#6366f1'),
			'greeting_msg' => isset($params['greeting_msg']) ? sanitize_textarea_field($params['greeting_msg']) : (isset($existing_chatbot['greeting_msg']) ? $existing_chatbot['greeting_msg'] : ''),
			'bot_avatar' => isset($params['bot_avatar']) ? esc_url_raw($params['bot_avatar']) : (isset($existing_chatbot['bot_avatar']) ? $existing_chatbot['bot_avatar'] : ''),
			'bubble_style' => isset($params['bubble_style']) ? sanitize_text_field($params['bubble_style']) : (isset($existing_chatbot['bubble_style']) ? $existing_chatbot['bubble_style'] : 'rounded'),
			'api_error_msg' => isset($params['api_error_msg']) ? sanitize_textarea_field($params['api_error_msg']) : (isset($existing_chatbot['api_error_msg']) ? $existing_chatbot['api_error_msg'] : 'There is some error on server, please contact our [support agent]({support_url}).'),
			'support_url' => isset($params['support_url']) ? esc_url_raw($params['support_url']) : (isset($existing_chatbot['support_url']) ? $existing_chatbot['support_url'] : home_url('/support')),
			'pre_question_1' => isset($params['pre_question_1']) ? sanitize_text_field($params['pre_question_1']) : (isset($existing_chatbot['pre_question_1']) ? $existing_chatbot['pre_question_1'] : ''),
			'pre_question_2' => isset($params['pre_question_2']) ? sanitize_text_field($params['pre_question_2']) : (isset($existing_chatbot['pre_question_2']) ? $existing_chatbot['pre_question_2'] : ''),
			'pre_question_3' => isset($params['pre_question_3']) ? sanitize_text_field($params['pre_question_3']) : (isset($existing_chatbot['pre_question_3']) ? $existing_chatbot['pre_question_3'] : ''),
			'pre_question_4' => isset($params['pre_question_4']) ? sanitize_text_field($params['pre_question_4']) : (isset($existing_chatbot['pre_question_4']) ? $existing_chatbot['pre_question_4'] : ''),
			'pre_questions_bg_color' => isset($params['pre_questions_bg_color']) ? sanitize_hex_color($params['pre_questions_bg_color']) : (isset($existing_chatbot['pre_questions_bg_color']) ? $existing_chatbot['pre_questions_bg_color'] : '#ffffff'),
			'pre_questions_text_color' => isset($params['pre_questions_text_color']) ? sanitize_hex_color($params['pre_questions_text_color']) : (isset($existing_chatbot['pre_questions_text_color']) ? $existing_chatbot['pre_questions_text_color'] : '#475569'),
			'pre_questions_border_color' => isset($params['pre_questions_border_color']) ? sanitize_hex_color($params['pre_questions_border_color']) : (isset($existing_chatbot['pre_questions_border_color']) ? $existing_chatbot['pre_questions_border_color'] : '#e2e8f0'),
			'pre_questions_border_radius' => isset($params['pre_questions_border_radius']) ? sanitize_text_field($params['pre_questions_border_radius']) : (isset($existing_chatbot['pre_questions_border_radius']) ? $existing_chatbot['pre_questions_border_radius'] : 'rounded'),
			'system_prompt' => isset($params['system_prompt']) ? sanitize_textarea_field($params['system_prompt']) : (isset($existing_chatbot['system_prompt']) ? $existing_chatbot['system_prompt'] : ''),
			'temperature' => isset($params['temperature']) ? floatval($params['temperature']) : (isset($existing_chatbot['temperature']) ? floatval($existing_chatbot['temperature']) : 0.7),
			'max_tokens' => isset($params['max_tokens']) ? intval($params['max_tokens']) : (isset($existing_chatbot['max_tokens']) ? intval($existing_chatbot['max_tokens']) : 500),
			'save_chat' => isset($params['save_chat']) ? (bool) $params['save_chat'] : (isset($existing_chatbot['save_chat']) ? (bool) $existing_chatbot['save_chat'] : false),
			'ask_email' => isset($params['ask_email']) ? (bool) $params['ask_email'] : (isset($existing_chatbot['ask_email']) ? (bool) $existing_chatbot['ask_email'] : false),
			'enable_pre_questions' => isset($params['enable_pre_questions']) ? (bool) $params['enable_pre_questions'] : (isset($existing_chatbot['enable_pre_questions']) ? (bool) $existing_chatbot['enable_pre_questions'] : false),
			'enable_uploads' => isset($params['enable_uploads']) ? (bool) $params['enable_uploads'] : (isset($existing_chatbot['enable_uploads']) ? (bool) $existing_chatbot['enable_uploads'] : false),
			'save_uploads' => isset($params['save_uploads']) ? (bool) $params['save_uploads'] : (isset($existing_chatbot['save_uploads']) ? (bool) $existing_chatbot['save_uploads'] : false),
			'max_upload_size' => isset($params['max_upload_size']) ? intval($params['max_upload_size']) : (isset($existing_chatbot['max_upload_size']) ? intval($existing_chatbot['max_upload_size']) : 5),
			'default_provider' => isset($params['default_provider']) ? sanitize_text_field($params['default_provider']) : (isset($existing_chatbot['default_provider']) ? $existing_chatbot['default_provider'] : 'openai'),
			'knowledge_text' => isset($params['knowledge_text']) ? sanitize_textarea_field($params['knowledge_text']) : (isset($existing_chatbot['knowledge_text']) ? $existing_chatbot['knowledge_text'] : ''),
			'knowledge_urls' => isset($params['knowledge_urls']) ? $urls : (isset($existing_chatbot['knowledge_urls']) ? $existing_chatbot['knowledge_urls'] : []),
			'training_files' => isset($params['training_files']) ? $files : (isset($existing_chatbot['training_files']) ? $existing_chatbot['training_files'] : []),
			'rate_limit_per_minute' => isset($params['rate_limit_per_minute']) ? max(1, min(300, intval($params['rate_limit_per_minute']))) : (isset($existing_chatbot['rate_limit_per_minute']) ? intval($existing_chatbot['rate_limit_per_minute']) : 20),
		];

		$settings['chatbot'] = $chatbot_settings;
		self::baca_persist_settings($settings);

		return new \WP_REST_Response(
			[
				'success' => true,
				'message' => esc_html__('Chatbot settings saved successfully!', 'botisst-ai-chat-assistant'),
			],
			200
		);
	}

	/**
	 * Save Display Control Settings
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response Standard API response.
	 */
	public function baca_save_display_settings($request)
	{
		$params = $request->get_json_params();

		$display_settings = [
			'entire_site' => isset($params['entire_site']) ? (bool) $params['entire_site'] : false,
			'exclude_pages' => isset($params['exclude_pages']) ? sanitize_text_field($params['exclude_pages']) : '',
			'position' => isset($params['position']) ? sanitize_text_field($params['position']) : 'bottom-right',
			'show_on_mobile' => isset($params['show_on_mobile']) ? (bool) $params['show_on_mobile'] : true,
			'trigger_type' => isset($params['trigger_type']) ? sanitize_text_field($params['trigger_type']) : 'click',
			'trigger_delay' => isset($params['trigger_delay']) ? intval($params['trigger_delay']) : 5,
			'launcher_text' => isset($params['launcher_text']) ? sanitize_text_field($params['launcher_text']) : 'Chat with us',
		];

		$settings = self::baca_get_all_settings();
		$settings['display'] = $display_settings;
		self::baca_persist_settings($settings);

		return new \WP_REST_Response(
			[
				'success' => true,
				'message' => esc_html__('Display settings saved successfully!', 'botisst-ai-chat-assistant'),
			],
			200
		);
	}

	/**
	 * Mark the first-run setup wizard as completed or skipped, so it stops
	 * auto-opening on future dashboard visits.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response
	 */
	public function baca_update_setup_wizard_status($request)
	{
		$status = sanitize_text_field($request->get_param('status'));

		if (!in_array($status, ['completed', 'skipped', 'pending'], true)) {
			return new \WP_REST_Response(
				['success' => false, 'message' => esc_html__('Invalid status.', 'botisst-ai-chat-assistant')],
				400
			);
		}

		update_option('baca_setup_wizard_status', $status);

		return new \WP_REST_Response(['success' => true], 200);
	}

	/**
	 * Handle a file upload via the WordPress media library.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response Uploaded attachment's ID, URL, and name.
	 */
	public function baca_upload_file($request)
	{
		if (!function_exists('media_handle_upload')) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		// media_handle_upload() sanitizes $_FILES internally; no nonce check
		// here since this endpoint's permission_callback already requires
		// manage_options.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if (empty($_FILES['file'])) {
			return new \WP_REST_Response(
				[
					'success' => false,
					'message' => esc_html__('No file was uploaded.', 'botisst-ai-chat-assistant'),
				],
				400
			);
		}

		$attachment_id = media_handle_upload('file', 0);

		if (is_wp_error($attachment_id)) {
			return new \WP_REST_Response(
				[
					'success' => false,
					'message' => $attachment_id->get_error_message(),
				],
				500
			);
		}

		return new \WP_REST_Response(
			[
				'success' => true,
				'id' => $attachment_id,
				'url' => wp_get_attachment_url($attachment_id),
				'name' => get_the_title($attachment_id),
			],
			200
		);
	}
}
