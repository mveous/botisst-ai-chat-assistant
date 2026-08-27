import { __ } from '@wordpress/i18n';

const PREVIEW_FEATURES = [
	__( 'Header & User Message colors', 'botisst-ai-chat-assistant' ),
	__( 'Assistant Name & Greeting', 'botisst-ai-chat-assistant' ),
	__( 'Bot Avatar updates', 'botisst-ai-chat-assistant' ),
	__( 'Message bubble styling', 'botisst-ai-chat-assistant' ),
	__( 'Chat Launcher visibility', 'botisst-ai-chat-assistant' ),
];

const SAMPLE_USER_MSG = __( 'How do I customize this chat window?', 'botisst-ai-chat-assistant' );
const SAMPLE_BOT_REPLY = __(
	"It's easy! Just use the settings in the dashboard to change the name, colors, and more.",
	'botisst-ai-chat-assistant'
);

export default function ChatPreviewTab( { settings } ) {
	const botSettings = settings?.chatbot || {};
	const displaySettings = settings?.display || {};
	const primaryColor = botSettings.primary_color || '#6366f1';
	const botName = botSettings.bot_name || __( 'Botisst', 'botisst-ai-chat-assistant' );
	const greetingMsg =
		botSettings.greeting_msg ||
		__( 'Hello! I am your AI assistant. How can I help you today?', 'botisst-ai-chat-assistant' );
	const placeholderText = displaySettings.placeholder_text || __( 'Type a message…', 'botisst-ai-chat-assistant' );
	const bubbleClass = `baca-preview-widget--${ botSettings.bubble_style || 'rounded' }`;
	const avatarSrc = botSettings.bot_avatar || '';
	const avatarInitial = botName.trim().charAt( 0 ).toUpperCase() || 'B';

	return (
		<div className="baca-preview">
			<div className="baca-preview__stage">
				<div
					className={ `baca-preview-widget ${ bubbleClass }` }
					style={ { '--baca-preview-primary': primaryColor } }
				>
					<header className="baca-preview-widget__header">
						<div className="baca-preview-widget__header-main">
							<div className="baca-preview-widget__avatar" aria-hidden="true">
								{ avatarSrc ? (
									<img src={ avatarSrc } alt="" />
								) : (
									<span>{ avatarInitial }</span>
								) }
							</div>
							<div className="baca-preview-widget__info">
								<strong>{ botName }</strong>
								<span className="baca-preview-widget__status">
									<span className="baca-preview-widget__status-dot" aria-hidden="true" />
									{ __( 'Online', 'botisst-ai-chat-assistant' ) }
								</span>
							</div>
						</div>
						<button
							type="button"
							className="baca-preview-widget__close"
							aria-label={ __( 'Close chat', 'botisst-ai-chat-assistant' ) }
							tabIndex={ -1 }
						>
							<span className="dashicons dashicons-no-alt" aria-hidden="true" />
						</button>
					</header>

					<div className="baca-preview-widget__messages">
						<div className="baca-preview-widget__row baca-preview-widget__row--bot">
							<div className="baca-preview-widget__bot-avatar" aria-hidden="true">
								{ avatarSrc ? (
									<img src={ avatarSrc } alt="" />
								) : (
									<span className="dashicons dashicons-admin-users" aria-hidden="true" />
								) }
							</div>
							<div className="baca-preview-widget__bubble baca-preview-widget__bubble--bot">
								<p>{ greetingMsg }</p>
							</div>
						</div>

						<div className="baca-preview-widget__row baca-preview-widget__row--user">
							<div className="baca-preview-widget__bubble baca-preview-widget__bubble--user">
								<p>{ SAMPLE_USER_MSG }</p>
							</div>
						</div>

						<div className="baca-preview-widget__row baca-preview-widget__row--bot">
							<div className="baca-preview-widget__bot-avatar" aria-hidden="true">
								{ avatarSrc ? (
									<img src={ avatarSrc } alt="" />
								) : (
									<span className="dashicons dashicons-admin-users" aria-hidden="true" />
								) }
							</div>
							<div className="baca-preview-widget__bubble baca-preview-widget__bubble--bot">
								<p>{ SAMPLE_BOT_REPLY }</p>
							</div>
						</div>
					</div>

					<footer className="baca-preview-widget__footer">
						<input
							type="text"
							className="baca-preview-widget__input"
							placeholder={ placeholderText }
							disabled
							aria-label={ __( 'Message input', 'botisst-ai-chat-assistant' ) }
						/>
						<button
							type="button"
							className="baca-preview-widget__send"
							disabled
							aria-label={ __( 'Send', 'botisst-ai-chat-assistant' ) }
							tabIndex={ -1 }
						>
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
								<path
									d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"
									stroke="currentColor"
									strokeWidth="2"
									strokeLinecap="round"
									strokeLinejoin="round"
								/>
							</svg>
						</button>
					</footer>
				</div>

				<div
					className="baca-preview__launcher"
					style={ { background: primaryColor } }
					aria-hidden="true"
				>
					<span className="dashicons dashicons-format-chat" />
				</div>
			</div>

			<aside className="baca-preview__panel">
				<div className="baca-preview__panel-icon" aria-hidden="true">
					<span className="dashicons dashicons-visibility" />
				</div>
				<h3 className="baca-preview__panel-title">{ __( 'Live Preview', 'botisst-ai-chat-assistant' ) }</h3>
				<p className="baca-preview__panel-desc">
					{ __(
						'Any changes you make in the Chatbot Settings will immediately reflect here. Test your user experience before going live.',
						'botisst-ai-chat-assistant'
					) }
				</p>
				<ul className="baca-preview__features">
					{ PREVIEW_FEATURES.map( ( feature ) => (
						<li key={ feature }>
							<span className="baca-preview__check" aria-hidden="true">
								<span className="dashicons dashicons-yes" />
							</span>
							{ feature }
						</li>
					) ) }
				</ul>
			</aside>
		</div>
	);
}
