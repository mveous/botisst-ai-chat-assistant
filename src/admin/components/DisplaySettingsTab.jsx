import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

export default function DisplaySettingsTab( { settings, onSave, showNotice } ) {
	const [ saving, setSaving ] = useState( false );
	const [ availableItems, setAvailableItems ] = useState( [] );
	const [ loadingItems, setLoadingItems ] = useState( false );
	const [ searchQuery, setSearchQuery ] = useState( '' );
	const [ isDropdownOpen, setIsDropdownOpen ] = useState( false );
	const dropdownRef = useRef( null );

	const display = settings?.display || {};

	const getInitialFormData = () => ( {
		entire_site: display.entire_site ?? false,
		show_on_mobile: display.show_on_mobile ?? true,
		exclude_pages: display.exclude_pages || '',
		position: display.position || 'bottom-right',
		launcher_text: display.launcher_text || '',
		trigger_type: display.trigger_type || 'click',
		trigger_delay: display.trigger_delay ?? 5,
	} );

	const [ formData, setFormData ] = useState( getInitialFormData );
	const [ baseline, setBaseline ] = useState( getInitialFormData );
	const isDirty = JSON.stringify( formData ) !== JSON.stringify( baseline );

	useEffect( () => {
		let isMounted = true;
		setLoadingItems( true );

		apiFetch( { path: '/baca/v1/all-content' } )
			.then( ( items ) => {
				if ( ! isMounted ) return;

				if ( Array.isArray( items ) ) {
					setAvailableItems( items );
				}
			} )
			.finally( () => {
				if ( isMounted ) {
					setLoadingItems( false );
				}
			} );

		return () => {
			isMounted = false;
		};
	}, [] );

	// Close dropdown when clicking outside
	useEffect( () => {
		const handleClickOutside = ( event ) => {
			if ( dropdownRef.current && ! dropdownRef.current.contains( event.target ) ) {
				setIsDropdownOpen( false );
			}
		};
		document.addEventListener( 'mousedown', handleClickOutside );
		return () => {
			document.removeEventListener( 'mousedown', handleClickOutside );
		};
	}, [] );

	const handleChange = ( name, value ) => {
		setFormData( ( prev ) => ( { ...prev, [ name ]: value } ) );
	};

	const getExcludedIdArray = () => {
		if ( ! formData.exclude_pages ) return [];
		return formData.exclude_pages
			.split( ',' )
			.map( ( s ) => s.trim() )
			.filter( Boolean );
	};

	const toggleItemExclusion = ( itemIdStr ) => {
		const currentIds = getExcludedIdArray();
		let updated;
		if ( currentIds.includes( itemIdStr ) ) {
			updated = currentIds.filter( ( id ) => id !== itemIdStr );
		} else {
			updated = [ ...currentIds, itemIdStr ];
		}
		handleChange( 'exclude_pages', updated.join( ', ' ) );
	};

	const removeItemExclusion = ( itemIdStr ) => {
		const updated = getExcludedIdArray().filter( ( id ) => id !== itemIdStr );
		handleChange( 'exclude_pages', updated.join( ', ' ) );
	};

	const handleAddCustomId = ( customVal ) => {
		const trimmed = ( customVal || searchQuery ).trim();
		if ( ! trimmed ) return;
		const currentIds = getExcludedIdArray();
		if ( ! currentIds.includes( trimmed ) ) {
			handleChange( 'exclude_pages', [ ...currentIds, trimmed ].join( ', ' ) );
		}
		setSearchQuery( '' );
		setIsDropdownOpen( false );
	};

	const handleSubmit = async ( e ) => {
		e.preventDefault();
		setSaving( true );
		try {
			await apiFetch( {
				path: '/baca/v1/save-display-settings',
				method: 'POST',
				data: formData,
			} );
			onSave( {
				display: { ...display, ...formData },
			} );
			setBaseline( formData );
			showNotice( __( 'Display settings saved successfully!', 'botisst-ai-chat-assistant' ) );
		} catch ( error ) {
			showNotice( error.message || __( 'Failed to save settings', 'botisst-ai-chat-assistant' ), 'error' );
		} finally {
			setSaving( false );
		}
	};

	const renderToggleCard = ( id, title, description, checked, onChange, icon = null ) => (
		<div className="baca-bot-setting-card">
			<div className="baca-bot-setting-card__main">
				{ icon }
				<div className="baca-bot-setting-card__text">
					<strong>{ title }</strong>
					<span>{ description }</span>
				</div>
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

	const excludedIds = getExcludedIdArray();

	const filteredItems = availableItems.filter( ( item ) => {
		if ( ! searchQuery.trim() ) return true;
		const q = searchQuery.toLowerCase();
		return (
			item.title.toLowerCase().includes( q ) ||
			String( item.id ).includes( q ) ||
			item.type.toLowerCase().includes( q )
		);
	} );

	return (
		<div className="baca-display-settings">
			<form onSubmit={ handleSubmit }>
				{ renderToggleCard(
					'entire_site',
					__( 'Enable Site-wide Chatbot', 'botisst-ai-chat-assistant' ),
					__( 'Activate the chatbot across all pages of your website', 'botisst-ai-chat-assistant' ),
					formData.entire_site,
					( v ) => handleChange( 'entire_site', v )
				) }

				<div className="baca-display-grid">
					<div className="baca-bot-field">
						<label htmlFor="launcher_text">
							{ __( 'Chat Button Text', 'botisst-ai-chat-assistant' ) }
						</label>
						<input
							type="text"
							id="launcher_text"
							className="baca-bot-input"
							value={ formData.launcher_text }
							onChange={ ( e ) => handleChange( 'launcher_text', e.target.value ) }
							placeholder={ __( 'How can we help?', 'botisst-ai-chat-assistant' ) }
						/>
					</div>

					<div className="baca-bot-field">
						<label htmlFor="exclude_search_input">
							{ __( 'Exclude Content (Pages, Posts, Custom Types)', 'botisst-ai-chat-assistant' ) }
						</label>

						<div
							ref={ dropdownRef }
							className="baca-combobox-wrapper"
							style={ { position: 'relative', width: '100%' } }
						>
							<div className="baca-combobox-input-wrap" style={ { position: 'relative' } }>
								<input
									type="text"
									id="exclude_search_input"
									className="baca-bot-input"
									value={ searchQuery }
									onChange={ ( e ) => {
										setSearchQuery( e.target.value );
										setIsDropdownOpen( true );
									} }
									onFocus={ () => setIsDropdownOpen( true ) }
									onKeyDown={ ( e ) => {
										if ( e.key === 'Enter' ) {
											e.preventDefault();
											handleAddCustomId();
										}
									} }
									placeholder={
										loadingItems
											? __( 'Loading content…', 'botisst-ai-chat-assistant' )
											: __( 'Type title or ID to search…', 'botisst-ai-chat-assistant' )
									}
									style={ { paddingRight: '36px' } }
								/>
								<span
									style={ {
										position: 'absolute',
										right: '12px',
										top: '50%',
										transform: 'translateY(-50%)',
										pointerEvents: 'none',
										color: '#94a3b8',
										fontSize: '12px',
									} }
								>
									▼
								</span>
							</div>

							{ isDropdownOpen && (
								<div
									className="baca-combobox-dropdown"
									style={ {
										position: 'absolute',
										top: 'calc(100% + 4px)',
										left: 0,
										right: 0,
										maxHeight: '220px',
										overflowY: 'auto',
										background: '#ffffff',
										border: '1px solid #cbd5e1',
										borderRadius: '8px',
										boxShadow: '0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.05)',
										zIndex: 999,
										padding: '4px 0',
									} }
								>
									{ filteredItems.length === 0 ? (
										<div
											style={ {
												padding: '10px 14px',
												fontSize: '13px',
												color: '#64748b',
												textAlign: 'center',
											} }
										>
											{ searchQuery.trim() ? (
												<div>
													<div>{ __( 'No matching page/post found.', 'botisst-ai-chat-assistant' ) }</div>
													<button
														type="button"
														onClick={ () => handleAddCustomId() }
														style={ {
															marginTop: '6px',
															background: '#4f46e5',
															color: '#fff',
															border: 'none',
															borderRadius: '6px',
															padding: '4px 10px',
															fontSize: '12px',
															cursor: 'pointer',
														} }
													>
														{ sprintf( __( 'Add "%s" as custom ID', 'botisst-ai-chat-assistant' ), searchQuery.trim() ) }
													</button>
												</div>
											) : (
												__( 'No pages or posts available.', 'botisst-ai-chat-assistant' )
											) }
										</div>
									) : (
										filteredItems.map( ( item ) => {
											const itemIdStr = String( item.id );
											const isExcluded = excludedIds.includes( itemIdStr );
											return (
												<div
													key={ item.id }
													onClick={ () => toggleItemExclusion( itemIdStr ) }
													style={ {
														display: 'flex',
														alignItems: 'center',
														justify: 'space-between',
														padding: '8px 14px',
														fontSize: '13px',
														cursor: 'pointer',
														background: isExcluded ? '#f0f9ff' : 'transparent',
														borderBottom: '1px solid #f1f5f9',
														transition: 'background 0.15s ease',
													} }
													onMouseEnter={ ( e ) => {
														if ( ! isExcluded ) e.currentTarget.style.background = '#f8fafc';
													} }
													onMouseLeave={ ( e ) => {
														if ( ! isExcluded ) e.currentTarget.style.background = 'transparent';
													} }
												>
													<div style={ { display: 'flex', alignItems: 'center', gap: '8px', flex: 1 } }>
														<span
															style={ {
																fontSize: '11px',
																fontWeight: '600',
																textTransform: 'uppercase',
																padding: '2px 6px',
																borderRadius: '4px',
																background: item.type === 'Page' ? '#e0f2fe' : '#fef3c7',
																color: item.type === 'Page' ? '#0369a1' : '#b45309',
															} }
														>
															{ item.type }
														</span>
														<span style={ { fontWeight: 500, color: '#1e293b' } }>
															{ item.title }
														</span>
														<span style={ { fontSize: '11px', color: '#94a3b8' } }>
															(ID: { item.id })
														</span>
													</div>
													{ isExcluded && (
														<span style={ { color: '#0284c7', fontWeight: 'bold', fontSize: '14px' } }>
															✓
														</span>
													) }
												</div>
											);
										} )
									) }
								</div>
							)}

							<div
								className="baca-selected-tokens"
								style={ {
									display: 'flex',
									flexWrap: 'wrap',
									gap: '6px',
									marginTop: '8px',
									minHeight: '24px',
								} }
							>
								{ excludedIds.length === 0 ? (
									<span style={ { fontSize: '12px', color: '#94a3b8', fontStyle: 'italic' } }>
										{ __( 'No content currently excluded.', 'botisst-ai-chat-assistant' ) }
									</span>
								) : (
									excludedIds.map( ( idStr ) => {
										const found = availableItems.find( ( item ) => String( item.id ) === idStr );
										const label = found
											? `[${ found.type }] ${ found.title }`
											: sprintf( __( 'ID %s', 'botisst-ai-chat-assistant' ), idStr );
										return (
											<span
												key={ idStr }
												className="baca-badge"
												style={ {
													display: 'inline-flex',
													alignItems: 'center',
													gap: '6px',
													padding: '4px 10px',
													borderRadius: '6px',
													background: '#eff6ff',
													border: '1px solid #bfdbfe',
													color: '#1d4ed8',
													fontSize: '12px',
													fontWeight: '500',
												} }
											>
												{ label }
												<button
													type="button"
													onClick={ () => removeItemExclusion( idStr ) }
													style={ {
														border: 'none',
														background: 'transparent',
														cursor: 'pointer',
														padding: 0,
														lineHeight: 1,
														color: '#3b82f6',
														fontWeight: 'bold',
														fontSize: '12px',
													} }
													aria-label={ __( 'Remove', 'botisst-ai-chat-assistant' ) }
												>
													✕
												</button>
											</span>
										);
									} )
								) }
							</div>
						</div>
					</div>

					<div className="baca-bot-field">
						<label htmlFor="trigger_type">
							{ __( 'Auto-Open Trigger', 'botisst-ai-chat-assistant' ) }
						</label>
						<select
							id="trigger_type"
							className="baca-bot-select"
							value={ formData.trigger_type }
							onChange={ ( e ) => handleChange( 'trigger_type', e.target.value ) }
						>
							<option value="delay">
								{ __( 'On Page Load', 'botisst-ai-chat-assistant' ) }
							</option>
							<option value="click">
								{ __( 'Wait for Click', 'botisst-ai-chat-assistant' ) }
							</option>
						</select>
					</div>

					<div className="baca-bot-field">
						<label htmlFor="position">
							{ __( 'Widget Position on Screen', 'botisst-ai-chat-assistant' ) }
						</label>
						<select
							id="position"
							className="baca-bot-select"
							value={ formData.position }
							onChange={ ( e ) => handleChange( 'position', e.target.value ) }
						>
							<option value="bottom-right">
								{ __( 'Bottom Right', 'botisst-ai-chat-assistant' ) }
							</option>
							<option value="bottom-left">
								{ __( 'Bottom Left', 'botisst-ai-chat-assistant' ) }
							</option>
						</select>
					</div>
				</div>

				{ formData.trigger_type === 'delay' && (
					<div className="baca-bot-field baca-display-delay-field">
						<label htmlFor="trigger_delay">
							{ __( 'Delay (seconds)', 'botisst-ai-chat-assistant' ) }
						</label>
						<input
							type="number"
							id="trigger_delay"
							className="baca-bot-input"
							min="1"
							max="60"
							value={ formData.trigger_delay }
							onChange={ ( e ) => handleChange( 'trigger_delay', e.target.value ) }
						/>
					</div>
				) }

				{ renderToggleCard(
					'show_on_mobile',
					__( 'Show on Mobile', 'botisst-ai-chat-assistant' ),
					__( 'Optimize interface for mobile devices', 'botisst-ai-chat-assistant' ),
					formData.show_on_mobile,
					( v ) => handleChange( 'show_on_mobile', v ),
					<span className="baca-display-mobile-icon" aria-hidden="true">
						<span className="dashicons dashicons-smartphone" />
					</span>
				) }

				<footer className="baca-display-footer">
					<button type="submit" className="baca-btn baca-btn-primary" disabled={ saving || ! isDirty }>
						{ saving
							? __( 'Saving…', 'botisst-ai-chat-assistant' )
							: __( 'Save', 'botisst-ai-chat-assistant' ) }
					</button>
				</footer>
			</form>
		</div>
	);
}
