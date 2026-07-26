<?php
/**
 * Botisst Elementor Widget
 *
 * @package Botisst
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BACA_Elementor_Widget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'botisst_ai_widget';
	}

	public function get_title() {
		return __( 'Botisst AI Chatbot', 'botisst-ai-chat-assistant' );
	}

	public function get_icon() {
		return 'eicon-comments';
	}

	public function get_categories() {
		return [ 'general' ];
	}

	protected function render() {
		$plugin = BACA_Chat_Assistant::get_instance();
		$plugin->baca_render_chatbot_ui( true );
	}
}

class BACA_Elementor {

	public function __construct() {
		add_action( 'elementor/widgets/register', [ $this, 'baca_register_widgets' ] );
	}

	public function baca_register_widgets( $widgets_manager ) {
		$widgets_manager->register( new BACA_Elementor_Widget() );
	}
}

new BACA_Elementor();
