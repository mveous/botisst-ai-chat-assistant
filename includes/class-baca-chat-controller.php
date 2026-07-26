<?php
/**
 * BACA Chat Controller
 *
 * Owns the public chat endpoint: permission/rate-limit checks, building the
 * system prompt (chatbot config + RAG context + MCP context + memory), the
 * actual AI provider call, and conversation/session persistence.
 *
 * @package Botisst
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Class BACA_Chat_Controller
 */
class BACA_Chat_Controller
{
	use BACA_REST_Helpers;

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
	 * Constructor
	 *
	 * @param BACA_RAG_Controller $rag_controller Used to retrieve RAG context for prompts.
	 * @param BACA_MCP_Controller $mcp_controller Used to retrieve MCP context for prompts.
	 */
	public function __construct(BACA_RAG_Controller $rag_controller, BACA_MCP_Controller $mcp_controller)
	{
		$this->rag_controller = $rag_controller;
		$this->mcp_controller = $mcp_controller;
	}

	/**
	 * Public Chat Permission Check
	 *
	 * Allows access if the user is logged in (capability check) OR
	 * if the visitor provides a valid REST API nonce (proving they are using our frontend).
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return bool True if authorized.
	 */
	public function permission_check($request)
	{
		if (current_user_can('read')) {
			return true;
		}
		$nonce = $request->get_header('X-WP-Nonce');
		if ($nonce && wp_verify_nonce($nonce, 'wp_rest')) {
			return true;
		}
		return false;
	}

	/**
	 * REST callback: handle a chat message end-to-end.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response Formatted answer response.
	 */
	public function handle($request)
	{
		if ($this->is_rate_limited()) {
			return $this->error_response(
				esc_html__('Too many requests. Please wait a moment and try again.', 'botisst-ai-chat-assistant'),
				429
			);
		}

		$params = $request->get_json_params();

		$prompt = isset($params['prompt']) ? sanitize_textarea_field($params['prompt']) : '';
		$session_id = isset($params['session_id']) ? sanitize_text_field($params['session_id']) : 'default';
		$email = isset($params['email']) ? sanitize_email($params['email']) : '';

		if (!current_user_can('manage_options')) {
			$cookie_session_id = isset($_COOKIE['baca_session_id']) ? sanitize_text_field(wp_unslash($_COOKIE['baca_session_id'])) : '';
			if (empty($cookie_session_id) || $cookie_session_id !== $session_id) {
				return $this->error_response(
					esc_html__('Unauthorized session access.', 'botisst-ai-chat-assistant'),
					403
				);
			}
		}

		if (empty($prompt)) {
			return $this->error_response(
				esc_html__('Empty prompt provided.', 'botisst-ai-chat-assistant'),
				400
			);
		}

		$settings = BACA_Settings_Handler::baca_get_all_settings();

		if (empty($settings)) {
			return $this->error_response(
				esc_html__('Plugin settings not configured.', 'botisst-ai-chat-assistant'),
				500
			);
		}

		$bot = isset($settings['chatbot']) ? $settings['chatbot'] : [];
		$models = isset($settings['models']) ? $settings['models'] : [];

		if (empty($bot) || empty($models)) {
			return $this->error_response(
				esc_html__('Chatbot settings incomplete.', 'botisst-ai-chat-assistant'),
				500
			);
		}

		try {
			$provider = $this->get_active_provider($bot);
			$model_id = $this->get_model_id($provider, $models);

			if (empty($model_id)) {
				return $this->error_response(
					esc_html__('No AI computational model selected for processing.', 'botisst-ai-chat-assistant'),
					400
				);
			}
		} catch (Exception $e) {
			self::log_debug('Botisst AI Chat Active Provider/Model Error: ' . $e->getMessage());
			$error_message = current_user_can('manage_options') ? $e->getMessage() : esc_html__('An error occurred while processing your request.', 'botisst-ai-chat-assistant');
			return $this->error_response($error_message, 400);
		}
		try {
			$system_message = $this->build_system_prompt($bot, $settings);
		} catch (Exception $e) {
			self::log_debug('Botisst AI Chat System Prompt Error: ' . $e->getMessage());
			$error_message = current_user_can('manage_options') ? $e->getMessage() : esc_html__('An error occurred while processing your request.', 'botisst-ai-chat-assistant');
			return $this->error_response($error_message, 500);
		}
		try {
			$rag_data = $this->rag_controller->get_chat_context($prompt, $session_id, $bot, $settings);

			if ($rag_data['require_data_missing']) {
				return $this->save_and_respond(
					$rag_data['message'],
					$session_id,
					$bot,
					$email,
					$prompt
				);
			}

			if (!empty($rag_data['context'])) {
				$system_message .= "\n\nCRITICAL INSTRUCTION: Answer the user's question concisely based ONLY on the facts provided in the 'Knowledge Base Information' below. Do not hallucinate, over-explain, or add external general knowledge that is not explicitly stated in the context.\n\nKnowledge Base Information:\n" . $rag_data['context'];
			}

			$rag_links = $rag_data['links'];
		} catch (Exception $e) {
			self::log_debug('Botisst AI RAG Link Fetch Error: ' . $e->getMessage());
			$rag_links = [];
		}

		try {
			$mcp_context = $this->mcp_controller->get_chat_context($prompt, $settings);
			if (!empty($mcp_context)) {
				$system_message .= "\n\nCustom Data:\n" . $mcp_context;
			}
		} catch (Exception $e) {
			self::log_debug('Botisst AI MCP Context Fetch Error: ' . $e->getMessage());
		}

		try {
			$memory = $this->get_optimized_memory($session_id, $prompt, $system_message);
			$system_message = $memory['system_message'];
		} catch (Exception $e) {
			self::log_debug('Botisst AI Memory Optimization Error: ' . $e->getMessage());
		}

		try {
			$ai_message = $this->call_ai_api(
				$prompt,
				$system_message,
				$provider,
				$model_id,
				$bot
			);

			if (empty($ai_message)) {
				return $this->error_response(
					esc_html__('AI connection returned an empty response.', 'botisst-ai-chat-assistant'),
					500
				);
			}
		} catch (\Throwable $e) {
			self::log_debug('Botisst AI Chat API/Processing Error: ' . $e->getMessage());
			$error_message = current_user_can('manage_options') ? $e->getMessage() : esc_html__('An error occurred while processing your request.', 'botisst-ai-chat-assistant');
			return $this->error_response($error_message, 500);
		}

		try {
			$this->save_conversation($prompt, $ai_message, $session_id, $provider, $model_id, $bot, $email);
		} catch (Exception $e) {
			self::log_debug('Botisst AI Save Conversation Error: ' . $e->getMessage());
		}

		try {
			$formatted_messages = $this->get_formatted_messages($session_id);
		} catch (Exception $e) {
			self::log_debug('Botisst AI Get Formatted Messages Error: ' . $e->getMessage());
			$formatted_messages = [];
		}

		return new \WP_REST_Response(
			[
				'success' => true,
				'message' => $ai_message,
				'session_id' => $session_id,
				'messages' => $formatted_messages,
				'reference_links' => $rag_links,
			],
			200
		);
	}

	/**
	 * REST callback: return all chat sessions for the admin dashboard.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_sessions($request)
	{
		$user_id = get_current_user_id();

		// Handle user-specific loading limit.
		$limit = $request->get_param('limit');
		if (!is_null($limit)) {
			$limit = sanitize_text_field($limit);
			update_user_meta($user_id, 'baca_sessions_load_limit', $limit);
		} else {
			$limit = get_user_meta($user_id, 'baca_sessions_load_limit', true);
			if (empty($limit)) {
				$limit = '100'; // Default limit.
			}
		}

		// Handle user-specific sorting order.
		$order = $request->get_param('order');
		if (!is_null($order)) {
			$order = sanitize_text_field($order);
			update_user_meta($user_id, 'baca_sessions_sort_order', $order);
		} else {
			$order = get_user_meta($user_id, 'baca_sessions_sort_order', true);
			if (empty($order)) {
				$order = 'desc'; // Default order.
			}
		}

		$sessions = BACA_DB::baca_get_all_sessions($limit, $order);

		return new \WP_REST_Response(
			[
				'sessions' => $sessions,
				'total' => count($sessions),
				'load_limit' => $limit,
				'sort_order' => $order,
			],
			200
		);
	}

	/**
	 * REST callback: delete a chat session from the database.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_session($request)
	{
		$session_id = sanitize_text_field($request->get_param('session_id'));

		if (empty($session_id)) {
			return new \WP_Error(
				'baca_invalid_session',
				__('Session ID is required.', 'botisst-ai-chat-assistant'),
				['status' => 400]
			);
		}

		if (!BACA_DB::baca_delete_session($session_id)) {
			return new \WP_Error(
				'baca_delete_failed',
				__('Session could not be deleted.', 'botisst-ai-chat-assistant'),
				['status' => 404]
			);
		}

		return new \WP_REST_Response(['success' => true], 200);
	}

	/**
	 * REST callback: clear session and reset cookies.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return \WP_REST_Response
	 */
	public function clear_session($request)
	{
		$new_session_id = 'sess_' . wp_generate_password(9, false);

		setcookie('baca_session_id', $new_session_id, time() + 3600, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), false);
		setcookie('baca_clear_allowed', 'true', time() + 1800, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), false);

		return new \WP_REST_Response(
			[
				'success' => true,
				'session_id' => $new_session_id,
			],
			200
		);
	}

	/**
	 * Get Client IP
	 *
	 * Best-effort caller IP for rate limiting. Only REMOTE_ADDR is trusted;
	 * headers like X-Forwarded-For are attacker-controlled unless a proxy
	 * is explicitly configured to set them, so they're not used here.
	 *
	 * @return string
	 */
	private function get_client_ip()
	{
		return isset($_SERVER['REMOTE_ADDR'])
			? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
			: '';
	}

	/**
	 * Is Chat Rate Limited
	 *
	 * permission_check() accepts any valid "wp_rest" nonce, which
	 * WordPress issues identically to every anonymous visitor and is
	 * readable in every page's HTML source — it does not identify a
	 * caller. Throttle by IP instead, so one client can't run up the
	 * site owner's AI provider costs by hammering the endpoint.
	 *
	 * @return bool True if the current caller has exceeded the limit.
	 */
	private function is_rate_limited()
	{
		$ip = $this->get_client_ip();

		if (empty($ip)) {
			return false;
		}

		$settings = BACA_Settings_Handler::baca_get_all_settings();
		$limit = isset($settings['chatbot']['rate_limit_per_minute'])
			? intval($settings['chatbot']['rate_limit_per_minute'])
			: 20;

		if ($limit <= 0) {
			return false;
		}

		$key = 'baca_chat_rl_' . md5($ip);
		$count = (int) get_transient($key);

		if ($count >= $limit) {
			return true;
		}

		set_transient($key, $count + 1, MINUTE_IN_SECONDS);

		return false;
	}

	/**
	 * Get active AI provider
	 */
	private function get_active_provider($bot)
	{
		$available_providers = [];

		foreach (['openai', 'google'] as $provider) {
			if (!empty(BACA_Key_Store::get_provider_key($provider))) {
				$available_providers[] = $provider;
			}
		}

		if (count($available_providers) === 0) {
			throw new Exception(esc_html__('No AI provider API keys configured.', 'botisst-ai-chat-assistant'));
		}

		if (count($available_providers) === 1) {
			return $available_providers[0];
		}

		// Multiple providers available - use default or first available
		$default_provider = !empty($bot['default_provider']) ? $bot['default_provider'] : 'openai';

		return in_array($default_provider, $available_providers, true) ? $default_provider : $available_providers[0];
	}

	/**
	 * Get model ID for provider
	 */
	private function get_model_id($provider, $models)
	{
		$model_id = isset($models[$provider]) ? $models[$provider] : '';

		if (!empty($model_id)) {
			return $model_id;
		}

		// Fallback to default models
		$defaults = [
			'openai' => 'gpt-4o-mini',
			'google' => 'gemini-2.5-flash',
		];

		return isset($defaults[$provider]) ? $defaults[$provider] : '';
	}

	/**
	 * Build comprehensive system prompt
	 */
	private function build_system_prompt($bot, $settings)
	{
		$system_message = '';

		// Admin custom prompt
		if (!empty($bot['system_prompt'])) {
			$system_message .= trim(wp_kses_post($bot['system_prompt']));
		}

		// Manual knowledge base
		if (!empty($bot['knowledge_text'])) {
			$system_message .= "\n\nKNOWLEDGE:\n";
			$system_message .= wp_kses_post($bot['knowledge_text']);
		}

		$system_message .= '

You are a helpful, professional AI assistant.

LANGUAGE
- Always reply in the same language as the user.
- Match the user tone naturally.

CONVERSATION MEMORY
- Use previous messages in the current session.
- Follow-up questions refer to the last discussed topic.
- Questions like:
  "more details"
  "tell me more"
  "continue"
  "explain more"
  "why?"
  "how?"
  "what about that?"
  should automatically continue the previous topic.
- Never ask "What topic do you mean?" if conversation context exists.

KNOWLEDGE BASE
- Use available knowledge base information whenever relevant.
- Never say:
  "According to the knowledge base"
  "Based on the provided content"
  "The information shows"

ANSWER STYLE
- Simple question → short answer.
- Technical question → detailed answer with examples.
- Use headings and bullet points when useful.
- Give practical examples whenever possible.
- Complete every answer fully.

ACCURACY
- Never invent facts, links, products, statistics, or company information.
- If unsure, clearly state uncertainty.
- If information is unavailable, say so honestly.

LINKS
- Include relevant links when available.
- If article links exist, include them naturally.

CLARIFICATION
- Ask a clarifying question only when the request is genuinely ambiguous.
- Do NOT ask clarification questions for:
  more details
  tell me more
  continue
  elaborate
  explain more

IMPORTANT
If conversation history exists, use it before asking questions.
Always expand on the previous answer when the user asks for more information.

';

		return trim($system_message);
	}

	/**
	 * Get optimized memory and conversation history
	 */
	private function get_optimized_memory($session_id, $prompt, $system_message)
	{
		try {

			$rag_context = '';

			if (!class_exists('BACA_Memory_Optimizer')) {
				require_once BACA_PATH . 'includes/class-baca-memory-optimizer.php';
			}

			$memory_optimizer = BACA_Memory_Optimizer::get_instance();

			$optimized_memory = $memory_optimizer->build_optimized_context(
				$session_id,
				$prompt,
				$rag_context
			);

			$memory_text = $memory_optimizer->format_for_prompt(
				$optimized_memory
			);

			if (!empty($memory_text)) {
				$system_message .= "\n\nMEMORY CONTEXT:\n";
				$system_message .= $memory_text;
			}

			/*
			 * Add recent conversation
			 */
			$conversation_history = $this->get_recent_conversation(
				$session_id,
				10
			);

			if (!empty($conversation_history)) {

				$system_message .= "\n\nCONVERSATION HISTORY:\n";
				$system_message .= $conversation_history;

				$system_message .= "\n\nFOLLOW-UP RULES:
- If user asks 'why', 'how', 'who', 'when', 'where', 'which', 'what about', 'tell me more', 'continue', 'can you explain', assume they are referring to the previous topic.
- Resolve pronouns such as 'it', 'that', 'this', 'they' using the conversation history.
- Never ignore previous messages in the same session.
";
			}

		} catch (Exception $e) {
			self::log_debug('Botisst AI Memory Build Context Error: ' . $e->getMessage());
		}

		return [
			'system_message' => $system_message,
		];
	}

	/**
	 * Call AI API using WordPress AI Client
	 */
	private function call_ai_api($prompt, $system_message, $provider, $model_id, $bot)
	{
		$registry = \WordPress\AiClient\AiClient::defaultRegistry();

		$key = BACA_Key_Store::get_provider_key($provider);

		if (empty($key)) {
			throw new Exception(
				sprintf(
					/* translators: %s: Provider name. */
					esc_html__(
						'API Key missing for provider: %s',
						'botisst-ai-chat-assistant'
					),
					esc_html( $provider )
				)
			);
		}

		/*
		 * Authentication
		 */
		$auth = new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication(
			$key
		);

		$registry->setProviderRequestAuthentication(
			$provider,
			$auth
		);

		/*
		 * Model
		 */
		$model = null;

		try {

			$model = $registry->getProviderModel(
				$provider,
				$model_id
			);

		} catch (\Throwable $e) {
			self::log_debug('Botisst AI Model Retrieval Error: ' . $e->getMessage());
		}

		/*
		 * Settings
		 */
		$temperature = isset($bot['temperature'])
			? (float) $bot['temperature']
			: 0.5;

		$max_tokens = isset($bot['max_tokens'])
			? (int) $bot['max_tokens']
			: 1500;

		/*
		 * Enhanced prompt
		 */
		$full_prompt = trim($prompt);

		/*
		 * Build request
		 */
		$builder = \WordPress\AiClient\AiClient::prompt(
			$full_prompt
		);

		$builder->usingRequestOptions(
			\WordPress\AiClient\Providers\Http\DTO\RequestOptions::fromArray(
				[
					\WordPress\AiClient\Providers\Http\DTO\RequestOptions::KEY_TIMEOUT => 90,
				]
			)
		);

		if ($model) {

			$builder->usingModel(
				$model
			);

		} else {

			$builder->usingProvider(
				$provider
			);
		}

		try {

			$result = $builder
				->usingSystemInstruction(
					$system_message
				)
				->usingTemperature(
					$temperature
				)
				->usingMaxTokens(
					$max_tokens
				)
				->generateTextResult();

			$response = trim(
				$result->toText()
			);

			if (empty($response)) {

				throw new Exception(
					'Empty response received from AI provider.'
				);
			}

			return $response;

		} catch (\Throwable $e) {
			throw new Exception( esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * Save conversation to database
	 */
	private function save_conversation($prompt, $ai_message, $session_id, $provider, $model_id, $bot, $email)
	{
		// Save to database if enabled
		if (isset($bot['save_chat']) && (bool) $bot['save_chat']) {
			if (class_exists('BACA_DB')) {
				$db = new BACA_DB();
				$db->baca_save_message($prompt, $ai_message, $session_id, $provider, $model_id, $email);
			}
		}

	}

	/**
	 * Get formatted messages from database
	 */
	private function get_formatted_messages($session_id)
	{
		$formatted_messages = [];

		if (!class_exists('BACA_DB')) {
			return $formatted_messages;
		}

		try {
			global $wpdb;

			// Use prepared statement properly
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe table prefix, direct query required.
			$existing_messages = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT content FROM {$wpdb->prefix}baca_sessions WHERE session_id = %s",
					$session_id
				)
			);

			if ($existing_messages) {
				$complete_messages = json_decode($existing_messages, true);

				if (is_array($complete_messages)) {
					foreach ($complete_messages as $msg) {
						if (isset($msg['role']) && isset($msg['content'])) {
							$formatted_messages[] = [
								'role' => ($msg['role'] === 'assistant') ? 'bot' : 'user',
								'content' => $msg['content'],
							];
						}
					}
				}
			}
		} catch (Exception $e) {
			self::log_debug('Botisst AI DB Messages Retrieval Error: ' . $e->getMessage());
		}

		return $formatted_messages;
	}

	/**
	 * Save response and return
	 */
	private function save_and_respond($message, $session_id, $bot, $email, $prompt)
	{
		if (isset($bot['save_chat']) && (bool) $bot['save_chat']) {
			if (class_exists('BACA_DB')) {
				$db = new BACA_DB();
				$db->baca_save_message($prompt, $message, $session_id, 'knowledge-base', 'no-data', $email);
			}
		}

		return new \WP_REST_Response(
			[
				'success' => true,
				'message' => $message,
				'session_id' => $session_id,
				'from_kb' => false,
			],
			200
		);
	}

	/**
	 * Get recent conversation history.
	 *
	 * @param string $session_id Session ID.
	 * @param int    $limit Number of messages.
	 * @return string
	 */
	private function get_recent_conversation($session_id, $limit = 10)
	{
		global $wpdb;

		if (empty($session_id)) {
			return '';
		}

		$table = esc_sql( $wpdb->prefix . 'baca_sessions' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database query on custom table.
		$messages_json = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT content FROM {$table} WHERE session_id = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is dynamic but safe.
				$session_id
			)
		);

		if (empty($messages_json)) {
			return '';
		}

		$messages = json_decode($messages_json, true);

		if (!is_array($messages)) {
			return '';
		}

		$messages = array_slice(
			$messages,
			-$limit
		);

		$history = '';

		foreach ($messages as $message) {

			if (empty($message['content'])) {
				continue;
			}

			$role = (
				isset($message['role']) &&
				$message['role'] === 'assistant'
			)
				? 'Assistant'
				: 'User';

			$history .= sprintf(
				"%s: %s\n",
				$role,
				trim($message['content'])
			);
		}

		return trim($history);
	}
}
