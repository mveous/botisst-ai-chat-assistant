import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const PROVIDER_LABELS = {
	openai: __( 'OpenAI', 'botisst-ai-chat-assistant' ),
	google: __( 'Google Gemini', 'botisst-ai-chat-assistant' ),
};

const SUB_TABS = [
	{ id: 'general', label: __( 'General', 'botisst-ai-chat-assistant' ) },
	{ id: 'advanced', label: __( 'Advance', 'botisst-ai-chat-assistant' ) },
	{ id: 'style', label: __( 'Style', 'botisst-ai-chat-assistant' ) },
	
];

export default function ChatbotSettingsTab( { settings, onSave, showNotice } ) {
	const botSettings = settings?.chatbot || {};
	const [ activeSubTab, setActiveSubTab ] = useState( 'general' );
	const [ saving, setSaving ] = useState( false );

	const getInitialFormData = () => ( {
		bot_name: botSettings.bot_name || '',
		primary_color: botSettings.primary_color || '#6366f1',
		greeting_msg: botSettings.greeting_msg || '',
		api_error_msg: botSettings.api_error_msg || '',
		support_url: botSettings.support_url || '',
		pre_question_1: botSettings.pre_question_1 || '',
		pre_question_2: botSettings.pre_question_2 || '',
		pre_question_3: botSettings.pre_question_3 || '',
		pre_question_4: botSettings.pre_question_4 || '',
		pre_questions_bg_color: botSettings.pre_questions_bg_color || '#ffffff',
		pre_questions_text_color: botSettings.pre_questions_text_color || '#475569',
		pre_questions_border_color: botSettings.pre_questions_border_color || '#e2e8f0',
		pre_questions_border_radius: botSettings.pre_questions_border_radius || 'rounded',
		bot_avatar: botSettings.bot_avatar || '',
		bubble_style: botSettings.bubble_style || 'rounded',

		save_chat: botSettings.save_chat ?? false,
		ask_email: botSettings.ask_email ?? false,
		enable_pre_questions: botSettings.enable_pre_questions ?? false,
		rate_limit_per_minute: botSettings.rate_limit_per_minute ?? 20,
	} );

	const [ formData, setFormData ] = useState( getInitialFormData );
	// Snapshot of the last-saved form state — the Save button stays
	// disabled until something differs from this baseline.
	const [ baseline, setBaseline ] = useState( getInitialFormData );
	const isDirty = JSON.stringify( formData ) !== JSON.stringify( baseline );

	const handleChange = ( name, value ) => {
		setFormData( ( prev ) => ( { ...prev, [ name ]: value } ) );
	};

	const handleUploadImage = () => {
		if ( ! window.wp?.media ) {
			showNotice( __( 'WordPress media modal is not available.', 'botisst-ai-chat-assistant' ), 'error' );
			return;
		}
		const frame = window.wp.media( {
			title: __( 'Select Bot Avatar', 'botisst-ai-chat-assistant' ),
			button: { text: __( 'Use as Avatar', 'botisst-ai-chat-assistant' ) },
			multiple: false,
		} );

		frame.on( 'select', () => {
			const attachment = frame.state().get( 'selection' ).first().toJSON();
			handleChange( 'bot_avatar', attachment.url );
		} );

		frame.open();
	};

	const handleSubmit = async ( e ) => {
		e.preventDefault();
		setSaving( true );
		try {
			await apiFetch( {
				path: '/baca/v1/save-bot-settings',
				method: 'POST',
				data: formData,
			} );
			onSave( { chatbot: { ...botSettings, ...formData } } );
			setBaseline( formData );
			showNotice( __( 'Bot settings saved successfully!', 'botisst-ai-chat-assistant' ) );
		} catch ( error ) {
			showNotice( error.message || __( 'Failed to save settings', 'botisst-ai-chat-assistant' ), 'error' );
		} finally {
			setSaving( false );
		}
	};



	const avatarInitial = ( formData.bot_name || 'B' ).charAt( 0 ).toUpperCase();

	const renderToggleCard = ( id, title, description, checked, onChange ) => (
		<div className="baca-bot-setting-card">
			<div className="baca-bot-setting-card__text">
				<strong>{ title }</strong>
				<span>{ description }</span>
			</div>
			<label className="baca-toggle" htmlFor={ id }>
				<input
					id={ id }
					type="checkbox"
					checked={ checked }
					onChange={ ( e ) => onChange( e.target.checked ) }
				/>
				<span className="baca-toggle-slider" aria-hidden="true" />
			</label>
		</div>
	);

	return (
		<div className="baca-bot-settings">
			<nav className="baca-kb-subnav" role="tablist" aria-label={ __( 'Chatbot settings sections', 'botisst-ai-chat-assistant' ) }>
				{ SUB_TABS.map( ( tab ) => (
					<button
						key={ tab.id }
						type="button"
						role="tab"
						aria-selected={ activeSubTab === tab.id }
						className={ `baca-kb-subtab ${ activeSubTab === tab.id ? 'active' : '' }` }
						onClick={ () => setActiveSubTab( tab.id ) }
					>
						{ tab.label }
					</button>
				) ) }
			</nav>
			<form onSubmit={ handleSubmit }>
				<div className={ `baca-bot-panel ${ activeSubTab === 'general' ? '' : 'baca-kb-panel--hidden' }` }>
					<section className="baca-bot-section">
					<h2 className="baca-bot-section__title">
						{ __( 'Bot Identity', 'botisst-ai-chat-assistant' ) }
					</h2>
					<div className="baca-bot-col--stack">
						<div className="baca-bot-field">
							<label htmlFor="bot_name">
									{ __( 'AI Assistant Name', 'botisst-ai-chat-assistant' ) }
								</label>
								<input
									type="text"
									id="bot_name"
									className="baca-bot-input"
									value={ formData.bot_name }
									onChange={ ( e ) => handleChange( 'bot_name', e.target.value ) }
									placeholder={ __( 'Botisst', 'botisst-ai-chat-assistant' ) }
								/>
								<p className="baca-bot-hint">
									{ __( 'This name will appear in the chat window header for your users.', 'botisst-ai-chat-assistant' ) }
								</p>
							</div>

							<div className="baca-bot-field">
								<label htmlFor="greeting_msg">
									{ __( 'Greeting Message', 'botisst-ai-chat-assistant' ) }
								</label>
								<textarea
									id="greeting_msg"
									className="baca-bot-input baca-bot-textarea"
									rows="4"
									value={ formData.greeting_msg }
									onChange={ ( e ) => handleChange( 'greeting_msg', e.target.value ) }
									placeholder={ __( 'Hello! I am your AI assistant. How can I help you today?', 'botisst-ai-chat-assistant' ) }
								/>
								<p className="baca-bot-hint">
									{ __( 'The first message your chatbot sends to initiate a conversation.', 'botisst-ai-chat-assistant' ) }
								</p>
							</div>

							<div className="baca-bot-field">
								<label htmlFor="support_url">
									{ __( 'Support System URL', 'botisst-ai-chat-assistant' ) }
								</label>
								<input
									type="text"
									id="support_url"
									className="baca-bot-input"
									value={ formData.support_url }
									onChange={ ( e ) => handleChange( 'support_url', e.target.value ) }
									placeholder={ __( 'https://example.com/support', 'botisst-ai-chat-assistant' ) }
								/>
								<p className="baca-bot-hint">
									{ __( 'The link users will be redirected to when they click "support agent" in case of errors.', 'botisst-ai-chat-assistant' ) }
								</p>
							</div>

							<div className="baca-bot-field">
								<label htmlFor="api_error_msg">
									{ __( 'API/Server Error Message', 'botisst-ai-chat-assistant' ) }
								</label>
								<textarea
									id="api_error_msg"
									className="baca-bot-input baca-bot-textarea"
									rows="3"
									value={ formData.api_error_msg }
									onChange={ ( e ) => handleChange( 'api_error_msg', e.target.value ) }
									placeholder={ __( 'There is some error on server, please contact our [support agent]({support_url}).', 'botisst-ai-chat-assistant' ) }
								/>
								<p className="baca-bot-hint">
									{ __( 'The message shown when a connection or API error occurs. You can use the {support_url} placeholder to insert the Support URL link.', 'botisst-ai-chat-assistant' ) }
								</p>
							</div>
						</div>
					</section>

				</div>

				<div className={ `baca-bot-panel ${ activeSubTab === 'advanced' ? '' : 'baca-kb-panel--hidden' }` }>
					<section className="baca-bot-section">
						<h2 className="baca-bot-section__title">
							{ __( 'Security', 'botisst-ai-chat-assistant' ) }
						</h2>
						<div className="baca-bot-field">
							<label htmlFor="rate_limit_per_minute">
								{ __( 'Chat Rate Limit (requests per minute per visitor)', 'botisst-ai-chat-assistant' ) }
							</label>
							<input
								type="number"
								id="rate_limit_per_minute"
								className="baca-bot-input"
								min="1"
								max="300"
								value={ formData.rate_limit_per_minute }
								onChange={ ( e ) => handleChange( 'rate_limit_per_minute', parseInt( e.target.value, 10 ) || 1 ) }
							/>
							<p className="baca-bot-hint">
								{ __( 'The public chat endpoint is throttled per visitor IP to stop it being used to run up your AI provider bill. Lower this if you\'re seeing abuse, or raise it if legitimate users are being blocked.', 'botisst-ai-chat-assistant' ) }
							</p>
						</div>
					</section>

					<section className="baca-bot-section">
					<h2 className="baca-bot-section__title">
						{ __( 'Conversation Features', 'botisst-ai-chat-assistant' ) }
					</h2>
					<div className="baca-bot-features">
						{ renderToggleCard(
							'save_chat',
							__( 'Save Chat History', 'botisst-ai-chat-assistant' ),
							__( 'Enable user session continuity', 'botisst-ai-chat-assistant' ),
							formData.save_chat,
							( v ) => handleChange( 'save_chat', v )
						) }
						{ formData.save_chat && renderToggleCard(
							'ask_email',
							__( 'Ask User Email', 'botisst-ai-chat-assistant' ),
							__( 'Prompt user to enter their email to save chat continuity', 'botisst-ai-chat-assistant' ),
							formData.ask_email ?? false,
							( v ) => handleChange( 'ask_email', v )
						) }
						{ renderToggleCard(
							'enable_pre_questions',
							__( 'Enable Suggested Questions', 'botisst-ai-chat-assistant' ),
							__( 'Provide quick suggestion questions to start a conversation', 'botisst-ai-chat-assistant' ),
							formData.enable_pre_questions,
							( v ) => handleChange( 'enable_pre_questions', v )
						) }
					</div>
				</section>

				{ formData.enable_pre_questions && (
					<>
						<section className="baca-bot-section">
							<h2 className="baca-bot-section__title">
								{ __( 'Suggested Questions', 'botisst-ai-chat-assistant' ) }
							</h2>
							<p className="baca-bot-hint" style={ { marginBottom: '1.25rem' } }>
								{ __( 'Provide up to 4 suggested questions that users can click to instantly start a conversation.', 'botisst-ai-chat-assistant' ) }
							</p>
							<div className="baca-bot-grid">
								<div className="baca-bot-col">
									<div className="baca-bot-field">
										<label htmlFor="pre_question_1">{ __( 'Suggested Question 1', 'botisst-ai-chat-assistant' ) }</label>
										<input
											type="text"
											id="pre_question_1"
											className="baca-bot-input"
											value={ formData.pre_question_1 }
											onChange={ ( e ) => handleChange( 'pre_question_1', e.target.value ) }
											placeholder={ __( 'e.g. What services do you offer?', 'botisst-ai-chat-assistant' ) }
										/>
									</div>
									<div className="baca-bot-field">
										<label htmlFor="pre_question_2">{ __( 'Suggested Question 2', 'botisst-ai-chat-assistant' ) }</label>
										<input
											type="text"
											id="pre_question_2"
											className="baca-bot-input"
											value={ formData.pre_question_2 }
											onChange={ ( e ) => handleChange( 'pre_question_2', e.target.value ) }
											placeholder={ __( 'e.g. How can I contact support?', 'botisst-ai-chat-assistant' ) }
										/>
									</div>
								</div>
								<div className="baca-bot-col">
									<div className="baca-bot-field">
										<label htmlFor="pre_question_3">{ __( 'Suggested Question 3', 'botisst-ai-chat-assistant' ) }</label>
										<input
											type="text"
											id="pre_question_3"
											className="baca-bot-input"
											value={ formData.pre_question_3 }
											onChange={ ( e ) => handleChange( 'pre_question_3', e.target.value ) }
											placeholder={ __( 'e.g. What are your pricing plans?', 'botisst-ai-chat-assistant' ) }
										/>
									</div>
									<div className="baca-bot-field">
										<label htmlFor="pre_question_4">{ __( 'Suggested Question 4', 'botisst-ai-chat-assistant' ) }</label>
										<input
											type="text"
											id="pre_question_4"
											className="baca-bot-input"
											value={ formData.pre_question_4 }
											onChange={ ( e ) => handleChange( 'pre_question_4', e.target.value ) }
											placeholder={ __( 'e.g. Tell me about your company.', 'botisst-ai-chat-assistant' ) }
										/>
									</div>
								</div>
							</div>
						</section>

						<section className="baca-bot-section">
							<h2 className="baca-bot-section__title">
								{ __( 'Suggested Questions Styling', 'botisst-ai-chat-assistant' ) }
							</h2>
							<div className="baca-bot-grid">
								<div className="baca-bot-col">
									<div className="baca-bot-field">
										<label htmlFor="pre_questions_bg_color">
											{ __( 'Background Color', 'botisst-ai-chat-assistant' ) }
										</label>
										<div className="baca-bot-color-field">
											<input
												type="color"
												id="pre_questions_bg_color"
												className="baca-bot-color-picker"
												value={ formData.pre_questions_bg_color }
												onChange={ ( e ) => handleChange( 'pre_questions_bg_color', e.target.value ) }
											/>
											<span className="baca-bot-color-value">{ formData.pre_questions_bg_color }</span>
										</div>
									</div>

									<div className="baca-bot-field">
										<label htmlFor="pre_questions_text_color">
											{ __( 'Text Color', 'botisst-ai-chat-assistant' ) }
										</label>
										<div className="baca-bot-color-field">
											<input
												type="color"
												id="pre_questions_text_color"
												className="baca-bot-color-picker"
												value={ formData.pre_questions_text_color }
												onChange={ ( e ) => handleChange( 'pre_questions_text_color', e.target.value ) }
											/>
											<span className="baca-bot-color-value">{ formData.pre_questions_text_color }</span>
										</div>
									</div>
								</div>

								<div className="baca-bot-col">
									<div className="baca-bot-field">
										<label htmlFor="pre_questions_border_color">
											{ __( 'Border Color', 'botisst-ai-chat-assistant' ) }
										</label>
										<div className="baca-bot-color-field">
											<input
												type="color"
												id="pre_questions_border_color"
												className="baca-bot-color-picker"
												value={ formData.pre_questions_border_color }
												onChange={ ( e ) => handleChange( 'pre_questions_border_color', e.target.value ) }
											/>
											<span className="baca-bot-color-value">{ formData.pre_questions_border_color }</span>
										</div>
									</div>

									<div className="baca-bot-field">
										<label htmlFor="pre_questions_border_radius">
											{ __( 'Border Radius Style', 'botisst-ai-chat-assistant' ) }
										</label>
										<select
											id="pre_questions_border_radius"
											className="baca-bot-select"
											value={ formData.pre_questions_border_radius }
											onChange={ ( e ) => handleChange( 'pre_questions_border_radius', e.target.value ) }
										>
											<option value="rounded">
												{ __( 'Rounded (Default)', 'botisst-ai-chat-assistant' ) }
											</option>
											<option value="square">{ __( 'Square', 'botisst-ai-chat-assistant' ) }</option>
											<option value="pill">{ __( 'Pill', 'botisst-ai-chat-assistant' ) }</option>
										</select>
									</div>
								</div>
							</div>
						</section>
					</>
				) }
				</div>

				<div className={ `baca-bot-panel ${ activeSubTab === 'style' ? '' : 'baca-kb-panel--hidden' }` }>
					<div className="baca-bot-grid">
						<section className="baca-bot-section">
							<h2 className="baca-bot-section__title">
								{ __( 'Bot Avatar', 'botisst-ai-chat-assistant' ) }
							</h2>
							<div className="baca-bot-avatar-card" style={ { margin: '0 auto' } }>
								<div className="baca-bot-avatar-preview">
									{ formData.bot_avatar ? (
										<img src={ formData.bot_avatar } alt="" />
									) : (
										<span>{ avatarInitial }</span>
									) }
								</div>
								<button
									type="button"
									className="baca-btn baca-btn-primary baca-bot-upload-btn"
									onClick={ handleUploadImage }
								>
									<span className="dashicons dashicons-upload" aria-hidden="true" />
									{ __( 'Upload New', 'botisst-ai-chat-assistant' ) }
								</button>
								{ !! formData.bot_avatar && (
									<button
										type="button"
										className="baca-bot-remove-avatar"
										onClick={ () => handleChange( 'bot_avatar', '' ) }
									>
										{ __( 'Remove', 'botisst-ai-chat-assistant' ) }
									</button>
								) }
								<p className="baca-bot-hint baca-bot-hint--center">
									{ __( 'JPG, PNG or SVG. Max size 2MB.', 'botisst-ai-chat-assistant' ) }
								</p>
							</div>
						</section>

						<section className="baca-bot-section">
							<h2 className="baca-bot-section__title">
								{ __( 'Branding & Styles', 'botisst-ai-chat-assistant' ) }
							</h2>
							<div className="baca-bot-col--stack">
								<div className="baca-bot-field">
									<label htmlFor="primary_color">
										{ __( 'Primary Color', 'botisst-ai-chat-assistant' ) }
									</label>
									<div className="baca-bot-color-field">
										<input
											type="color"
											id="primary_color"
											className="baca-bot-color-picker"
											value={ formData.primary_color }
											onChange={ ( e ) => handleChange( 'primary_color', e.target.value ) }
											aria-label={ __( 'Primary color', 'botisst-ai-chat-assistant' ) }
										/>
										<span className="baca-bot-color-value">{ formData.primary_color }</span>
									</div>
									<p className="baca-bot-hint">
										{ __( 'Used for the chat header, launcher button, and your messages.', 'botisst-ai-chat-assistant' ) }
									</p>
								</div>

								<div className="baca-bot-field">
									<label htmlFor="bubble_style">
										{ __( 'Chat Bubble Style', 'botisst-ai-chat-assistant' ) }
									</label>
									<select
										id="bubble_style"
										className="baca-bot-select"
										value={ formData.bubble_style }
										onChange={ ( e ) => handleChange( 'bubble_style', e.target.value ) }
									>
										<option value="rounded">
											{ __( 'Rounded (Default)', 'botisst-ai-chat-assistant' ) }
										</option>
										<option value="square">{ __( 'Square', 'botisst-ai-chat-assistant' ) }</option>
										<option value="pill">{ __( 'Pill', 'botisst-ai-chat-assistant' ) }</option>
									</select>
									<p className="baca-bot-hint">
										{ __( 'Controls the corner rounding of chat message bubbles.', 'botisst-ai-chat-assistant' ) }
									</p>
								</div>
							</div>
						</section>
					</div>
				</div>
				<footer className="baca-bot-footer">
					<button type="submit" className="baca-btn baca-btn-primary" disabled={ saving || ! isDirty }>
						{ saving
							? <><span className="baca-spinner" aria-hidden="true" /> { __( 'Saving…', 'botisst-ai-chat-assistant' ) }</>
							: __( 'Save', 'botisst-ai-chat-assistant' ) }
					</button>
				</footer>
			</form>
		</div>
	);
}
