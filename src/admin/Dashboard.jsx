import { useState, useCallback, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import ApiKeysTab from './components/ApiKeysTab';
import ChatbotSettingsTab from './components/ChatbotSettingsTab';
import DisplaySettingsTab from './components/DisplaySettingsTab';
import InstructionsTab from './components/InstructionsTab';
import KnowledgeBaseTab from './components/KnowledgeBaseTab';
import ChatSessionsTab from './components/ChatSessionsTab';
import ChatPreviewTab from './components/ChatPreviewTab';
import SetupWizard from './components/SetupWizard';

const TABS = [
	{ id: 'api-keys', label: __('API Keys', 'botisst-ai-chat-assistant'), icon: 'dashicons-rest-api', desc: __('Configure your AI providers and select your preferred chatbot models.', 'botisst-ai-chat-assistant'), component: ApiKeysTab },
	{ id: 'chatbot-settings', label: __('Chatbot', 'botisst-ai-chat-assistant'), icon: 'dashicons-admin-settings', desc: __('Customize your chatbot name, greeting message, and behavioral features.', 'botisst-ai-chat-assistant'), component: ChatbotSettingsTab },
	{ id: 'display-settings', label: __('Display Options', 'botisst-ai-chat-assistant'), icon: 'dashicons-align-center', desc: __('Configure where and how your chatbot appears on the frontend.', 'botisst-ai-chat-assistant'), component: DisplaySettingsTab },
	{ id: 'instructions', label: __('Instructions', 'botisst-ai-chat-assistant'), icon: 'dashicons-edit', desc: __('Set how your chatbot talks, what it helps with, and how creative its replies are.', 'botisst-ai-chat-assistant'), component: InstructionsTab },
	{ id: 'knowledge-base', label: __('Knowledge Base', 'botisst-ai-chat-assistant'), icon: 'dashicons-database', desc: __('Provide custom text, links, documents, and index your website content for the chatbot to learn from.', 'botisst-ai-chat-assistant'), component: KnowledgeBaseTab },
	{ id: 'chat-sessions', label: __('Chat Sessions', 'botisst-ai-chat-assistant'), icon: 'dashicons-format-chat', desc: __('View and manage recent conversations with your AI assistant.', 'botisst-ai-chat-assistant'), component: ChatSessionsTab },
	{ id: 'chat-preview', label: __('Chat Preview', 'botisst-ai-chat-assistant'), icon: 'dashicons-welcome-view-site', desc: __('See how your chatbot appears to your website visitors.', 'botisst-ai-chat-assistant'), component: ChatPreviewTab },
];

const NAV_GROUPS = [
	{ label: __( 'Setup', 'botisst-ai-chat-assistant' ), items: [ 'api-keys', 'chatbot-settings', 'display-settings', 'instructions' ] },
	{ label: __( 'Knowledge', 'botisst-ai-chat-assistant' ), items: [ 'knowledge-base' ] },
	{ label: __( 'Monitor', 'botisst-ai-chat-assistant' ), items: [ 'chat-sessions', 'chat-preview' ] },
];

export default function Dashboard({ settings: initialSettings }) {
	const [settings, setSettings] = useState(initialSettings);
	const [notice, setNotice] = useState(null);
	const [showWizard, setShowWizard] = useState(!!window.baca_data?.show_setup_wizard);
	const [wizardSessionKey, setWizardSessionKey] = useState(0);

	const isSaveChatEnabled = !!settings?.chatbot?.save_chat;
	const availableTabs = TABS.filter((tab) => {
		if (tab.id === 'chat-sessions') {
			return isSaveChatEnabled;
		}
		return true;
	});

	const [activeTab, setActiveTab] = useState(() => {
		const hash = window.location.hash.replace('#', '');
		if (hash && availableTabs.some((t) => t.id === hash)) {
			return hash;
		}
		return availableTabs[0].id;
	});

	const showNotice = useCallback((message, type = 'success') => {
		setNotice({ message, type });
		setTimeout(() => setNotice(null), 10000);
	}, []);

	const updateSettingsData = useCallback((newData) => {
		setSettings(prev => ({ ...prev, ...newData }));
	}, []);

	const activeTabData = availableTabs.find(t => t.id === activeTab) || availableTabs[0];
	const ActiveComponent = activeTabData?.component;

	useEffect(() => {
		if (!availableTabs.some((t) => t.id === activeTab)) {
			const fallbackTab = availableTabs[0].id;
			setActiveTab(fallbackTab);
			window.location.hash = fallbackTab;
		}
	}, [activeTab, availableTabs]);

	useEffect(() => {
		// Keep the active tab visible inside the scrollable mobile tab bar.
		const activeButton = document.querySelector('.baca-tabs .baca-tab.active');
		if (activeButton && typeof activeButton.scrollIntoView === 'function') {
			activeButton.scrollIntoView({ block: 'nearest', inline: 'nearest' });
		}
	}, [activeTab]);

	useEffect( () => {
		const syncTabFromHash = () => {
			const hash = window.location.hash.replace( '#', '' );
			if ( hash && availableTabs.some( ( tab ) => tab.id === hash ) ) {
				setActiveTab( hash );
			}
		};
		window.addEventListener( 'hashchange', syncTabFromHash );
		return () => window.removeEventListener( 'hashchange', syncTabFromHash );
	}, [availableTabs] );

	useEffect(() => {
		if (showWizard) {
			const url = new URL(window.location.href);
			if (url.searchParams.has('baca_open_wizard')) {
				url.searchParams.delete('baca_open_wizard');
				window.history.replaceState({}, '', url.toString());
			}
		}
	}, [showWizard]);

	const handleTabClick = (tabId) => () => {
		setActiveTab(tabId);
		window.location.hash = tabId;
	};

	return (
		<div className="baca-dashboard-wrapper">
			<SetupWizard
				open={showWizard}
				settings={settings}
				onSave={updateSettingsData}
				onClose={() => {
					setShowWizard(false);
					setWizardSessionKey(prev => prev + 1);
				}}
				showNotice={showNotice}
			/>
			<header className="baca-dashboard-header">
				<div className="baca-brand">
					<span className="dashicons dashicons-format-chat" aria-hidden="true"></span>
					<span>{__('Botisst', 'botisst-ai-chat-assistant')}</span>
				</div>
				<nav className="baca-tabs" role="tablist" aria-label={__('Settings sections', 'botisst-ai-chat-assistant')}>
					{NAV_GROUPS.map((group) => (
						<div className="baca-nav-group" key={group.label}>
							<span className="baca-nav-group__label">{group.label}</span>
							{group.items.map((tabId) => {
								const tab = availableTabs.find((t) => t.id === tabId);
								if (!tab) {
									return null;
								}
								return (
									<button
										key={tab.id}
										type="button"
										role="tab"
										title={tab.label}
										aria-selected={activeTab === tab.id}
										tabIndex={activeTab === tab.id ? 0 : -1}
										className={`baca-tab ${activeTab === tab.id ? 'active' : ''}`}
										onClick={handleTabClick(tab.id)}
									>
										<span className={`dashicons ${tab.icon}`} aria-hidden="true"></span>
										<span className="baca-tab-label">{tab.label}</span>
									</button>
								);
							})}
						</div>
					))}
				</nav>
			</header>

			<main className="baca-content">
				<header className="baca-content-header">
					<h1 id="baca-tab-title">{activeTabData?.label}</h1>
					<p id="baca-tab-desc">{activeTabData?.desc}</p>
				</header>



				{notice && (
					<div className={`baca-toast baca-toast-${notice.type}`}>
						<span className={`dashicons ${notice.type === 'success' ? 'dashicons-yes-alt' : 'dashicons-warning'}`}></span>
						<span className="baca-toast-message">{notice.message}</span>
						<button type="button" className="baca-toast-close" onClick={() => setNotice(null)} aria-label={__('Close notice', 'botisst-ai-chat-assistant')}>
							<span className="dashicons dashicons-no-alt"></span>
						</button>
					</div>
				)}

				<div className="baca-tab-panel active">
					{ActiveComponent && <ActiveComponent key={`${activeTab}-${wizardSessionKey}`} settings={settings} onSave={updateSettingsData} showNotice={showNotice} />}
				</div>
			</main>
		</div>
	);
}
