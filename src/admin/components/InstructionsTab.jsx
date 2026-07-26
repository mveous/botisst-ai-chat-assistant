import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const SYSTEM_PROMPT_HELP = __(
	'Explain how your chatbot should sound and behave—its tone, what it helps with, and any rules it should follow.',
	'botisst-ai-chat-assistant'
);

export default function InstructionsTab( { settings, onSave, showNotice } ) {
	const [ saving, setSaving ] = useState( false );
	const botSettings = settings?.chatbot || {};

	const getInitialFormData = () => ( {
		system_prompt: botSettings.system_prompt || '',
		temperature: botSettings.temperature ?? 0.7,
		max_tokens: botSettings.max_tokens ?? 500,
	} );

	const [ formData, setFormData ] = useState( getInitialFormData );
	// Snapshot of the last-saved form state — the Save button stays
	// disabled until something differs from this baseline.
	const [ baseline, setBaseline ] = useState( getInitialFormData );
	const isDirty = JSON.stringify( formData ) !== JSON.stringify( baseline );

	const handleChange = ( name, value ) => {
		setFormData( ( prev ) => ( { ...prev, [ name ]: value } ) );
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
			showNotice( __( 'Instructions saved successfully!', 'botisst-ai-chat-assistant' ) );
		} catch ( error ) {
			showNotice( error.message || __( 'Failed to save instructions', 'botisst-ai-chat-assistant' ), 'error' );
		} finally {
			setSaving( false );
		}
	};

	const temperatureValue = Number( formData.temperature );

	return (
		<div className="baca-instructions-settings">
			<form onSubmit={ handleSubmit }>
				<article className="baca-instructions-card">
					<header className="baca-instructions-card__header">
						<span className="baca-instructions-card__icon" aria-hidden="true">
							<span className="dashicons dashicons-edit" />
						</span>
						<h3 className="baca-instructions-card__title">
							{ __( 'System Instructions', 'botisst-ai-chat-assistant' ) }
						</h3>
					</header>
					<div className="baca-instructions-card__body">
						<label htmlFor="system_prompt" className="baca-instructions-label">
							{ __( 'Custom ChatBot Prompt', 'botisst-ai-chat-assistant' ) }
						</label>
						<textarea
							id="system_prompt"
							className="baca-instructions-textarea"
							rows="6"
							value={ formData.system_prompt }
							onChange={ ( e ) => handleChange( 'system_prompt', e.target.value ) }
							placeholder={ __(
								"For example: You're a friendly assistant on our site. Answer clearly, stay helpful, and keep replies short.",
								'botisst-ai-chat-assistant'
							) }
						/>
						<p className="baca-bot-hint">{ SYSTEM_PROMPT_HELP }</p>
					</div>
				</article>

				<article className="baca-instructions-card">
					<header className="baca-instructions-card__header">
						<span className="baca-instructions-card__icon" aria-hidden="true">
							<span className="dashicons dashicons-admin-settings" />
						</span>
						<h3 className="baca-instructions-card__title">
							{ __( 'Model Parameters', 'botisst-ai-chat-assistant' ) }
						</h3>
					</header>
					<div className="baca-instructions-card__body">
						<div className="baca-instructions-params-grid">
							<div className="baca-instructions-param">
								<div className="baca-instructions-param__head">
									<label htmlFor="temperature">
										{ __( 'Chat Accuracy', 'botisst-ai-chat-assistant' ) }
									</label>
									<span className="baca-instructions-value-badge">
										{ temperatureValue.toFixed( 1 ) }
									</span>
								</div>
								<input
									type="range"
									id="temperature"
									className="baca-instructions-range"
									min="0"
									max="2"
									step="0.1"
									value={ formData.temperature }
									onChange={ ( e ) => handleChange( 'temperature', e.target.value ) }
								/>
								<div className="baca-instructions-range-labels">
									<span>{ __( 'Focused/Deterministic', 'botisst-ai-chat-assistant' ) }</span>
									<span>{ __( 'Creative', 'botisst-ai-chat-assistant' ) }</span>
								</div>
								<p className="baca-bot-hint">
									{ __( 'Turn it up for more creative replies; turn it down for shorter, more predictable answers. Values above 1.0 make replies noticeably more random and are rarely needed.', 'botisst-ai-chat-assistant' ) }
								</p>
							</div>

							<div className="baca-instructions-param">
								<label htmlFor="max_tokens" className="baca-instructions-label">
									{ __( 'Max Tokens', 'botisst-ai-chat-assistant' ) }
								</label>
								<input
									type="number"
									id="max_tokens"
									className="baca-bot-input"
									value={ formData.max_tokens }
									onChange={ ( e ) => handleChange( 'max_tokens', e.target.value ) }
									min="100"
									max="8192"
								/>
								<p className="baca-bot-hint">
									{ __( 'Sets the longest reply the chatbot can send in one message.', 'botisst-ai-chat-assistant' ) }
								</p>
							</div>
						</div>
					</div>
				</article>

				<footer className="baca-instructions-footer">
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
