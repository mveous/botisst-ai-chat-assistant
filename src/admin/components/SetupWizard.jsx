import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const PROVIDERS = {
	openai: {
		name: __('OpenAI', 'botisst-ai-chat-assistant'),
		link: 'https://platform.openai.com/settings/organization/api-keys',
	},
	google: {
		name: __('Google Gemini', 'botisst-ai-chat-assistant'),
		link: 'https://aistudio.google.com/api-keys',
	},
};

const VECTOR_DB_OPTIONS = [
	{
		value: 'sqlite',
		label: __('SQLite (Local)', 'botisst-ai-chat-assistant'),
		desc: __('Local vector storage - no setup needed', 'botisst-ai-chat-assistant'),
	},
	{
		value: 'pinecone',
		label: __('Pinecone (Cloud)', 'botisst-ai-chat-assistant'),
		desc: __('Managed cloud service - requires API key', 'botisst-ai-chat-assistant'),
	},
];

async function updateWizardStatus(status) {
	try {
		await apiFetch({ path: '/baca/v1/setup-wizard', method: 'POST', data: { status } });
	} catch (e) {
		// Non-fatal — worst case the wizard offers to run again next visit.
	}
}

export default function SetupWizard({ open, settings, onSave, onClose, showNotice }) {
	const [step, setStep] = useState(1);
	const [busy, setBusy] = useState(false);

	const getInitialProvider = () => {
		if (settings?.chatbot?.default_provider) {
			return settings.chatbot.default_provider;
		}
		if (settings?.api_keys?.openai) return 'openai';
		if (settings?.api_keys?.google) return 'google';
		return 'openai';
	};

	const [selectedProvider, setSelectedProvider] = useState(getInitialProvider);
	const hasSavedApiKey = !!settings?.api_keys?.[selectedProvider];
	const [apiKey, setApiKey] = useState(() => settings?.api_keys?.[getInitialProvider()] || '');

	const getPreferredEmbeddingProvider = (currentSettings = settings) => {
		const apiKeys = currentSettings?.api_keys || {};
		const savedEmbeddingProvider = currentSettings?.rag?.embeddings?.provider;

		if (savedEmbeddingProvider) {
			return savedEmbeddingProvider;
		}

		if (currentSettings?.chatbot?.default_provider) {
			return currentSettings.chatbot.default_provider;
		}

		if (apiKeys.google && !apiKeys.openai) {
			return 'google';
		}

		if (apiKeys.openai && !apiKeys.google) {
			return 'openai';
		}

		return 'openai';
	};

	const [vectorDb, setVectorDb] = useState(() => settings?.rag?.vector_db?.provider || 'sqlite');
	const [embeddingProvider, setEmbeddingProvider] = useState(() => getPreferredEmbeddingProvider(settings));
	const [pineconeApiKey, setPineconeApiKey] = useState(() => settings?.rag?.vector_db?.api_key || '');
	const [pineconeHost, setPineconeHost] = useState(() => settings?.rag?.vector_db?.host || '');
	const [pineconeIndexName, setPineconeIndexName] = useState(() => settings?.rag?.vector_db?.index_name || '');

	const [knowledgeText, setKnowledgeText] = useState('');

	const [availablePostTypes, setAvailablePostTypes] = useState([]);
	const [selectedPostTypes, setSelectedPostTypes] = useState(() => settings?.rag?.post_types || ['post', 'page']);

	useEffect(() => {
		if (!open) {
			return undefined;
		}
		document.body.classList.add('baca-modal-open');

		const initialProvider = getInitialProvider();
		setSelectedProvider(initialProvider);
		setApiKey(settings?.api_keys?.[initialProvider] || '');

		setVectorDb(settings?.rag?.vector_db?.provider || 'sqlite');
		setEmbeddingProvider(getPreferredEmbeddingProvider(settings));
		setPineconeApiKey(settings?.rag?.vector_db?.api_key || '');
		setPineconeHost(settings?.rag?.vector_db?.host || '');
		setPineconeIndexName(settings?.rag?.vector_db?.index_name || '');

		setSelectedPostTypes(settings?.rag?.post_types || ['post', 'page']);
		apiFetch({ path: '/baca/v1/rag/post-types' })
			.then((res) => setAvailablePostTypes(res.types || []))
			.catch(() => { });

		return () => document.body.classList.remove('baca-modal-open');
		// Intentionally only [open]: this should initialize the wizard's
		// fields once when it opens, not keep re-syncing (and clobbering
		// in-progress edits) every time settings changes while it's open —
		// each step already saves its own local state via onSave().
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [open]);

	if (!open) {
		return null;
	}

	const changeStep = (next) => {
		setStep(next);
	};

	const handleClose = async (status) => {
		setBusy(true);
		await updateWizardStatus(status);
		setBusy(false);
		onClose();
	};

	const handleProviderNext = async () => {
		if (!apiKey.trim()) {
			showNotice(__('An API key is required to continue.', 'botisst-ai-chat-assistant'), 'error');
			return;
		}

		setEmbeddingProvider(selectedProvider);

		if (settings?.api_keys?.[selectedProvider] && apiKey === settings.api_keys[selectedProvider]) {
			if (settings?.chatbot?.default_provider === selectedProvider) {
				changeStep(2);
				return;
			}
		}

		setBusy(true);
		try {
			const data = {
				default_provider: selectedProvider,
			};
			if (apiKey !== settings?.api_keys?.[selectedProvider]) {
				data[`${selectedProvider}_key`] = apiKey;
			}

			const response = await apiFetch({
				path: '/baca/v1/save-settings',
				method: 'POST',
				data,
			});

			onSave({
				api_keys: response.api_keys || {},
				chatbot: response.chatbot || {},
			});
			changeStep(2);
		} catch (error) {
			const message = error?.errors?.[selectedProvider] || error?.message
				|| __('Could not validate this API key. Please try again.', 'botisst-ai-chat-assistant');
			showNotice(message, 'error');
		} finally {
			setBusy(false);
		}
	};

	const handleVectorDbNext = async () => {
		setBusy(true);
		try {
			const vectorDbConfig = vectorDb === 'pinecone'
				? { provider: 'pinecone', api_key: pineconeApiKey, host: pineconeHost, index_name: pineconeIndexName }
				: { provider: 'sqlite' };

			const embeddingsConfig = { provider: embeddingProvider };

			await apiFetch({
				path: '/baca/v1/rag/settings',
				method: 'POST',
				data: {
					vector_db: vectorDbConfig,
					embeddings: embeddingsConfig
				},
			});

			onSave({
				rag: {
					...settings?.rag,
					vector_db: vectorDbConfig,
					embeddings: {
						...settings?.rag?.embeddings,
						...embeddingsConfig
					}
				}
			});
			changeStep(vectorDb === 'pinecone' ? 4 : 3);
		} catch (error) {
			showNotice(
				error?.message || __('Could not connect to this vector database. Please check your details.', 'botisst-ai-chat-assistant'),
				'error'
			);
		} finally {
			setBusy(false);
		}
	};

	const handlePostTypesNext = async () => {
		setBusy(true);
		try {
			await apiFetch({
				path: '/baca/v1/rag/settings',
				method: 'POST',
				data: {
					post_types: selectedPostTypes
				},
			});

			onSave({
				rag: {
					...settings?.rag,
					post_types: selectedPostTypes
				}
			});

			// Trigger RAG indexing for website content of selected post types immediately
			const indexResponse = await apiFetch({
				path: '/baca/v1/rag/index',
				method: 'POST',
				data: {
					post_types: selectedPostTypes,
					index_sources: {
						knowledge_text: false,
						urls: false,
						files: false,
						website: selectedPostTypes.length > 0,
					},
				},
			});

			if (indexResponse && indexResponse.success === false) {
				throw new Error(indexResponse.message || __('Embedding generation failed.', 'botisst-ai-chat-assistant'));
			}

			changeStep(vectorDb === 'pinecone' ? 5 : 4);
		} catch (error) {
			showNotice(error.message || __('Could not save post types.', 'botisst-ai-chat-assistant'), 'error');
		} finally {
			setBusy(false);
		}
	};

	const handleFinish = async () => {
		setBusy(true);
		try {
			await apiFetch({
				path: '/baca/v1/save-bot-settings',
				method: 'POST',
				data: { knowledge_text: knowledgeText },
			});

			onSave({ chatbot: { ...settings?.chatbot, knowledge_text: knowledgeText } });

			// Trigger RAG indexing for manual knowledge text only if provided
			if (knowledgeText.trim()) {
				const indexResponse = await apiFetch({
					path: '/baca/v1/rag/index',
					method: 'POST',
					data: {
						post_types: selectedPostTypes,
						index_sources: {
							knowledge_text: true,
							urls: false,
							files: false,
							website: false,
						},
					},
				});

				if (indexResponse && indexResponse.success === false) {
					throw new Error(indexResponse.message || __('Embedding generation failed.', 'botisst-ai-chat-assistant'));
				}
			}

			showNotice(__('Embedding generation and setup completed successfully!', 'botisst-ai-chat-assistant'));
			await updateWizardStatus('completed');
			changeStep('done');
		} catch (error) {
			showNotice(
				error?.message || __('Could not complete the setup process. Please try again.', 'botisst-ai-chat-assistant'),
				'error'
			);
		} finally {
			setBusy(false);
		}
	};

	const stepsCount = vectorDb === 'pinecone' ? 5 : 4;
	const stepLabels = vectorDb === 'pinecone' ? [
		__('Connect an AI provider', 'botisst-ai-chat-assistant'),
		__('Choose vector database', 'botisst-ai-chat-assistant'),
		__('Pinecone settings', 'botisst-ai-chat-assistant'),
		__('Content for Your Chatbot', 'botisst-ai-chat-assistant'),
		__('Add chatbot knowledge', 'botisst-ai-chat-assistant'),
	] : [
		__('Connect an AI provider', 'botisst-ai-chat-assistant'),
		__('Choose vector database', 'botisst-ai-chat-assistant'),
		__('Content for Your Chatbot', 'botisst-ai-chat-assistant'),
		__('Add chatbot knowledge', 'botisst-ai-chat-assistant'),
	];

	const renderProgress = () => {
		if (step === 'done') {
			return null;
		}
		return (
			<div className="baca-wizard-progress">
				{Array.from({ length: stepsCount }, (_, i) => i + 1).map((n) => (
					<button
						key={n}
						type="button"
						className={`baca-wizard-progress__dot ${n === step ? 'is-active' : ''} ${n < step ? 'is-done' : ''}`}
						onClick={() => n < step && changeStep(n)}
						disabled={n >= step || busy}
						aria-label={__('Go back to step', 'botisst-ai-chat-assistant') + ': ' + stepLabels[n - 1]}
						aria-current={n === step ? 'step' : undefined}
					/>
				))}
			</div>
		);
	};

	const renderStepOne = () => (
		<>
			<h2 className="baca-wizard-step-title">
				{__('Connect an AI provider', 'botisst-ai-chat-assistant')}
			</h2>
			<p className="baca-wizard-step-desc">
				{__('Choose an AI provider and enter your API key to continue.', 'botisst-ai-chat-assistant')}
			</p>

			<div className="baca-bot-field">
				<label htmlFor="wizard_provider">
					{__('AI Provider', 'botisst-ai-chat-assistant')}
				</label>
				<select
					id="wizard_provider"
					className="baca-bot-select"
					value={selectedProvider}
					onChange={(e) => {
						const newProvider = e.target.value;
						setSelectedProvider(newProvider);
						setApiKey(settings?.api_keys?.[newProvider] || '');
						setEmbeddingProvider(newProvider);
					}}
				>
					{Object.entries(PROVIDERS).map(([id, provider]) => (
						<option key={id} value={id}>{provider.name}</option>
					))}
				</select>
			</div>

			<div className="baca-bot-field">
				<label htmlFor="wizard_api_key">
					{__('API Key', 'botisst-ai-chat-assistant')}
				</label>
				<input
					type="text"
					id="wizard_api_key"
					className="baca-bot-input"
					value={apiKey}
					onChange={(e) => setApiKey(e.target.value)}
					placeholder={__('Paste your API key here', 'botisst-ai-chat-assistant')}
					disabled={hasSavedApiKey}
				/>
				<a
					href={PROVIDERS[selectedProvider].link}
					className="baca-api-help-link"
					target="_blank"
					rel="noopener noreferrer"
				>
					{__('Generate your API key here', 'botisst-ai-chat-assistant')}
					<span className="dashicons dashicons-external" aria-hidden="true" />
				</a>
			</div>
		</>
	);

	const renderStepTwo = () => {
		const isKeyConfigured = !!settings?.api_keys?.[embeddingProvider];

		return (
			<>
				<h2 className="baca-wizard-step-title">
					{__('Choose your database', 'botisst-ai-chat-assistant')}
				</h2>
				<p className="baca-wizard-step-desc">
					{__('Your knowledge base is stored here so the AI can quickly search and use it when answering questions.', 'botisst-ai-chat-assistant')}
				</p>

				<div className="baca-kb-db-options">
					{VECTOR_DB_OPTIONS.map((option) => (
						<label key={option.value} className="baca-radio-card" style={{ margin: 0 }}>
							<input
								type="radio"
								name="wizard_vector_db"
								value={option.value}
								checked={vectorDb === option.value}
								onChange={() => setVectorDb(option.value)}
							/>
							<span className="baca-radio-card__label">
								<strong>{option.label}</strong>
								<br />
								<small>{option.desc}</small>
							</span>
						</label>
					))}
				</div>

				<div className="baca-bot-field" style={{ marginTop: '1.5rem' }}>
					<div style={{ display: 'flex', alignItems: 'center', marginBottom: '0.375rem' }}>
						<label htmlFor="wizard_embedding_provider" style={{ marginBottom: 0 }}>
							{__('Embedding Provider', 'botisst-ai-chat-assistant')}
						</label>
						{vectorDb === 'pinecone' && (
							<div className="baca-info-tooltip-wrapper">
								<button
									type="button"
									className="baca-info-btn"
									aria-label={__('Dimensions info', 'botisst-ai-chat-assistant')}
								>
									i
								</button>
								<div className="baca-info-tooltip">
									{sprintf(
										__('Because you selected %1$s, your Pinecone index must be created with exactly %2$d dimensions.', 'botisst-ai-chat-assistant'),
										embeddingProvider === 'google' ? __('Google Gemini (gemini-embedding-001)', 'botisst-ai-chat-assistant') : __('OpenAI (text-embedding-3-small)', 'botisst-ai-chat-assistant'),
										embeddingProvider === 'google' ? 768 : 1536
									)}
								</div>
							</div>
						)}
					</div>
					<select
						id="wizard_embedding_provider"
						className="baca-bot-select"
						value={embeddingProvider}
						onChange={(e) => setEmbeddingProvider(e.target.value)}
					>
						<option value="openai">{__('OpenAI (text-embedding-3-small)', 'botisst-ai-chat-assistant')}</option>
						<option value="google">{__('Google Gemini (gemini-embedding-001)', 'botisst-ai-chat-assistant')}</option>
					</select>
					<p className="baca-bot-hint" style={{ marginTop: '0.375rem' }}>
						{__('Select the AI provider to generate vector embeddings.', 'botisst-ai-chat-assistant')}
					</p>

					{!isKeyConfigured && (
						<div className="baca-bot-warning" style={{ marginTop: '0.75rem', padding: '0.75rem 1rem', background: '#fef2f2', border: '1px solid #fee2e2', borderRadius: '8px', fontSize: '0.8125rem', color: '#b91c1c', display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>
							<span>{__('You need to add an API key to generate embeddings.', 'botisst-ai-chat-assistant')}</span>
							<button
								type="button"
								className="baca-bot-link"
								style={{ display: 'inline-flex', padding: 0, border: 'none', background: 'none', color: '#2563eb', textDecoration: 'underline', cursor: 'pointer', fontSize: 'inherit', fontWeight: '600' }}
								onClick={() => changeStep(1)}
							>
								{__('Go back to Step 1 to add the API key', 'botisst-ai-chat-assistant')}
							</button>
						</div>
					)}
				</div>
			</>
		);
	};

	const renderPineconeStep = () => {
		const currentDimensions = embeddingProvider === 'google' ? 768 : 1536;
		const currentProviderName = embeddingProvider === 'google' ? __('Google Gemini', 'botisst-ai-chat-assistant') : __('OpenAI', 'botisst-ai-chat-assistant');

		return (
			<>
				<h2 className="baca-wizard-step-title">
					{__('Pinecone Settings', 'botisst-ai-chat-assistant')}
				</h2>
				<p className="baca-wizard-step-desc">
					{__('Configure your Pinecone connection details below.', 'botisst-ai-chat-assistant')}
				</p>

				<div className="baca-kb-info-block" style={{ marginBottom: '1.25rem' }}>
					<p style={{ margin: 0 }}>
						<strong>{__('Important Pinecone Index Requirement:', 'botisst-ai-chat-assistant')}</strong>{' '}
						{sprintf(
							__('Because you are using %1$s, you must create your Pinecone index with exactly %2$d dimensions.', 'botisst-ai-chat-assistant'),
							currentProviderName,
							currentDimensions
						)}
					</p>
				</div>

				<div className="baca-bot-field">
					<label htmlFor="wizard_pinecone_key">
						{__('Pinecone API Key', 'botisst-ai-chat-assistant')}
					</label>
					<input
						type="password"
						id="wizard_pinecone_key"
						className="baca-bot-input"
						value={pineconeApiKey}
						onChange={(e) => setPineconeApiKey(e.target.value)}
						placeholder="pcsk_..."
					/>
				</div>
				<div className="baca-bot-field">
					<label htmlFor="wizard_pinecone_host">
						{__('Pinecone Host', 'botisst-ai-chat-assistant')}
					</label>
					<input
						type="text"
						id="wizard_pinecone_host"
						className="baca-bot-input"
						value={pineconeHost}
						onChange={(e) => setPineconeHost(e.target.value)}
						placeholder="https://index-xxxxx.svc.aped-4627-b74a.pinecone.io"
					/>
					<a
						href="https://app.pinecone.io/"
						className="baca-api-help-link"
						target="_blank"
						rel="noopener noreferrer"
					>
						{__('Find your Pinecone API details and host URL here', 'botisst-ai-chat-assistant')}
						<span className="dashicons dashicons-external" aria-hidden="true" />
					</a>
				</div>
				<div className="baca-bot-field">
					<label htmlFor="wizard_pinecone_index">
						{__('Index Name', 'botisst-ai-chat-assistant')}
					</label>
					<input
						type="text"
						id="wizard_pinecone_index"
						className="baca-bot-input"
						value={pineconeIndexName}
						onChange={(e) => setPineconeIndexName(e.target.value)}
						placeholder={__('e.g. botisst-index', 'botisst-ai-chat-assistant')}
					/>
				</div>
			</>
		);
	};

	const renderStepThree = () => (
		<>
			<h2 className="baca-wizard-step-title">
				{__('Add what your bot should know', 'botisst-ai-chat-assistant')}
			</h2>
			<p className="baca-wizard-step-desc">
				{__('Add facts, FAQs, or company information for your chatbot. You can add more content later in the Knowledge Base.', 'botisst-ai-chat-assistant')}
			</p>

			<div className="baca-bot-field">
				<label htmlFor="wizard_knowledge_text">
					{__('Knowledge Base Text', 'botisst-ai-chat-assistant')}
				</label>
				<textarea
					id="wizard_knowledge_text"
					className="baca-bot-input baca-bot-textarea"
					rows="6"
					value={knowledgeText}
					onChange={(e) => setKnowledgeText(e.target.value)}
					placeholder={__('e.g. We are open Monday-Friday, 9am-5pm. Our return policy is...', 'botisst-ai-chat-assistant')}
				/>
			</div>
		</>
	);

	const renderPostTypesStep = () => (
		<>
			<h2 className="baca-wizard-step-title">
				{__('Content for Your Chatbot', 'botisst-ai-chat-assistant')}
			</h2>
			<p className="baca-wizard-step-desc">
				{__('Choose which WordPress content the chatbot can use to answer questions.', 'botisst-ai-chat-assistant')}
			</p>

			<div className="baca-kb-post-types" style={{ marginTop: '1.5rem', display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
				{availablePostTypes.length > 0 ? (
					availablePostTypes.map((pt) => (
						<label key={pt.value} className="baca-checkbox" style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', fontSize: '0.875rem', color: '#374151', cursor: 'pointer' }}>
							<input
								type="checkbox"
								checked={selectedPostTypes.includes(pt.value)}
								onChange={() => {
									setSelectedPostTypes((prev) =>
										prev.includes(pt.value) ? prev.filter((v) => v !== pt.value) : [...prev, pt.value]
									);
								}}
							/>
							<span className="baca-checkbox__label">
								{pt.label} ({pt.count})
							</span>
						</label>
					))
				) : (
					<p className="baca-hint" style={{ fontSize: '0.8125rem', color: '#6b7280' }}>
						{__('Fetching available content types...', 'botisst-ai-chat-assistant')}
					</p>
				)}
			</div>
		</>
	);

	const renderDone = () => (
		<div className="baca-wizard-done">
			<span className="baca-wizard-done__icon dashicons dashicons-yes-alt" aria-hidden="true" />
			<h2 className="baca-wizard-step-title">
				{__("You're all set!", 'botisst-ai-chat-assistant')}
			</h2>
			<p className="baca-wizard-step-desc">
				{__('Your chatbot is ready to go. You can fine-tune everything else from the dashboard at any time.', 'botisst-ai-chat-assistant')}
			</p>
		</div>
	);

	const isLastStep = vectorDb === 'pinecone' ? step === 5 : step === 4;

	const handleSkip = () => {
		const total = vectorDb === 'pinecone' ? 5 : 4;
		if (step === total) {
			handleClose('skipped');
		} else {
			changeStep(step + 1);
		}
	};

	const handleNextClick = () => {
		if (step === 1) {
			handleProviderNext();
		} else if (step === 2) {
			if (vectorDb === 'pinecone') {
				changeStep(3);
			} else {
				handleVectorDbNext();
			}
		} else if (step === 3) {
			if (vectorDb === 'pinecone') {
				handleVectorDbNext();
			} else {
				handlePostTypesNext();
			}
		} else if (step === 4) {
			if (vectorDb === 'pinecone') {
				handlePostTypesNext();
			} else {
				handleFinish();
			}
		} else if (step === 5) {
			handleFinish();
		}
	};

	return (
		<div className="baca-modal baca-wizard-modal is-visible" role="dialog" aria-modal="true" aria-labelledby="baca-wizard-title">
			<div className="baca-modal-overlay" />
			<div className="baca-modal-content baca-wizard-modal__content">
				<div className="baca-modal-header">
					<div className="baca-modal-title">
						<span className="baca-modal-title-icon" aria-hidden="true">
							<span className="dashicons dashicons-format-chat" />
						</span>
						<h3 id="baca-wizard-title">
							{__('Welcome to Botisst — quick setup', 'botisst-ai-chat-assistant')}
						</h3>
					</div>
					<button
						type="button"
						className="baca-modal-close"
						onClick={() => handleClose('skipped')}
						aria-label={__('Close', 'botisst-ai-chat-assistant')}
						disabled={busy}
					>
						<span className="dashicons dashicons-no-alt" aria-hidden="true" />
					</button>
				</div>

				{renderProgress()}

				<div className="baca-modal-body baca-wizard-modal__body">
					{step === 1 && renderStepOne()}
					{step === 2 && renderStepTwo()}
					{vectorDb === 'pinecone' ? (
						<>
							{step === 3 && renderPineconeStep()}
							{step === 4 && renderPostTypesStep()}
							{step === 5 && renderStepThree()}
						</>
					) : (
						<>
							{step === 3 && renderPostTypesStep()}
							{step === 4 && renderStepThree()}
						</>
					)}
					{step === 'done' && renderDone()}
				</div>

				<div className="baca-modal-footer baca-wizard-modal__footer">
					{step === 'done' ? (
						<>
							<span />
							<button
								type="button"
								className="baca-btn baca-btn-primary"
								onClick={() => {
									if (window.location.href.includes('page=baca')) {
										onClose();
									} else {
										window.location.href = 'admin.php?page=baca';
									}
								}}
							>
								{__('Go to Settings', 'botisst-ai-chat-assistant')}
							</button>
						</>
					) : (
						<>
							{step === 1 ? (
								<span />
							) : (
								<button
									type="button"
									className="baca-bot-link baca-wizard-skip"
									onClick={handleSkip}
									disabled={busy}
								>
									{__('Skip for now', 'botisst-ai-chat-assistant')}
								</button>
							)}
							<button
								type="button"
								className="baca-btn baca-btn-primary"
								onClick={handleNextClick}
								disabled={
									busy ||
									(step === 1 && !apiKey.trim()) ||
									(step === 2 && !settings?.api_keys?.[embeddingProvider]) ||
									(vectorDb === 'pinecone' && step === 3 && (!pineconeApiKey.trim() || !pineconeHost.trim() || !pineconeIndexName.trim()))
								}
							>
								{busy
									? <span className="baca-spinner" aria-hidden="true" />
									: (isLastStep
										? __('Finish', 'botisst-ai-chat-assistant')
										: __('Next', 'botisst-ai-chat-assistant'))}
							</button>
						</>
					)}
				</div>
			</div>
		</div>
	);
}
