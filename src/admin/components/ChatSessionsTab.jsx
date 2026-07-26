import { useState, useMemo, useCallback, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { ConfirmDialog } from './ui';

function parseMessages( content ) {
	if ( ! content ) {
		return [];
	}
	try {
		const msgs = typeof content === 'string' ? JSON.parse( content ) : content;
		return Array.isArray( msgs ) ? msgs : [];
	} catch {
		return [];
	}
}

function getDisplayUser( session ) {
	if ( session.email ) {
		return session.email;
	}

	return __( 'Guest User', 'botisst-ai-chat-assistant' );
}

function formatProviderLabel( provider ) {
	if ( ! provider ) {
		return __( 'Unknown', 'botisst-ai-chat-assistant' );
	}
	const labels = {
		openai: 'OpenAI',
		google: 'Google',
	};
	return labels[ provider.toLowerCase() ] || provider.charAt( 0 ).toUpperCase() + provider.slice( 1 );
}

function getSessionMeta( session ) {
	return {
		provider: session?.provider ? formatProviderLabel( session.provider ) : __( 'Unknown', 'botisst-ai-chat-assistant' ),
		model: session?.model || __( 'Unknown', 'botisst-ai-chat-assistant' ),
	};
}

function escapeHtml( value ) {
	return String( value ?? '' ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
}

function getLastMessageSnippet( session ) {
	const msgs = parseMessages( session.content );
	if ( ! msgs.length ) {
		return '';
	}
	const last = msgs[ msgs.length - 1 ];
	const text = ( last?.content || '' ).replace( /\s+/g, ' ' ).trim();
	if ( ! text ) {
		return '';
	}
	const max = 72;
	const clipped = text.length > max ? `${ text.slice( 0, max ) }…` : text;
	return `"${ clipped }"`;
}

function formatMessageTime( value ) {
	if ( ! value ) {
		return '';
	}
	const normalized = String( value ).includes( 'T' ) ? value : String( value ).replace( ' ', 'T' );
	const date = new Date( normalized );
	if ( Number.isNaN( date.getTime() ) ) {
		return value;
	}
	const now = new Date();
	const timeOpts = { hour: 'numeric', minute: '2-digit' };
	const dateOpts = {
		month: 'short',
		day: 'numeric',
		...( date.getFullYear() !== now.getFullYear() ? { year: 'numeric' } : {} ),
	};
	return `${ date.toLocaleDateString( undefined, dateOpts ) } · ${ date.toLocaleTimeString( undefined, timeOpts ) }`;
}

function sessionMatchesSearch( session, query ) {
	if ( ! query ) {
		return true;
	}
	const q = query.toLowerCase();
	const name = getDisplayUser( session );
	const lastMsg = getLastMessageSnippet( session ).toLowerCase();
	return (
		session.session_id?.toLowerCase().includes( q ) ||
		name.toLowerCase().includes( q ) ||
		( session.provider || '' ).toLowerCase().includes( q ) ||
		( session.model || '' ).toLowerCase().includes( q ) ||
		lastMsg.includes( q )
	);
}

function buildPageNumbers( current, total ) {
	if ( total <= 7 ) {
		return Array.from( { length: total }, ( _, i ) => i + 1 );
	}
	const pages = new Set( [ 1, total, current ] );
	if ( current > 2 ) {
		pages.add( current - 1 );
	}
	if ( current < total - 1 ) {
		pages.add( current + 1 );
	}
	const sorted = [ ...pages ].sort( ( a, b ) => a - b );
	const result = [];
	for ( let i = 0; i < sorted.length; i++ ) {
		if ( i > 0 && sorted[ i ] - sorted[ i - 1 ] > 1 ) {
			result.push( '…' );
		}
		result.push( sorted[ i ] );
	}
	return result;
}

export default function ChatSessionsTab( { showNotice } ) {
	const [ sessionsList, setSessionsList ] = useState( [] );
	const [ loadingSessions, setLoadingSessions ] = useState( true );
	const [ searchQuery, setSearchQuery ] = useState( '' );
	const [ providerFilter, setProviderFilter ] = useState( 'all' );
	const [ currentPage, setCurrentPage ] = useState( 1 );
	const [ perPage, setPerPage ] = useState( 10 );
	const [ selectedSession, setSelectedSession ] = useState( null );
	const [ modalOpen, setModalOpen ] = useState( false );
	const [ deletingId, setDeletingId ] = useState( null );
	const [ confirmingSession, setConfirmingSession ] = useState( null );
	const [ filterOpen, setFilterOpen ] = useState( false );
	const filterRef = useRef( null );

	const [ loadLimit, setLoadLimit ] = useState( window.baca_data?.load_limit || '100' );
	const [ localLimit, setLocalLimit ] = useState( loadLimit );

	const [ sortOrder, setSortOrder ] = useState( window.baca_data?.sort_order || 'desc' );

	const [ localPerPage, setLocalPerPage ] = useState( String( perPage ) );

	useEffect( () => {
		setLocalLimit( loadLimit );
	}, [ loadLimit ] );

	useEffect( () => {
		setLocalPerPage( String( perPage ) );
	}, [ perPage ] );

	const handleLimitCommit = ( val ) => {
		const parsed = val.trim();
		if ( parsed === '' ) {
			setLoadLimit( 'all' );
		} else {
			const num = parseInt( parsed, 10 );
			if ( ! isNaN( num ) && num > 0 ) {
				setLoadLimit( String( num ) );
			} else {
				setLocalLimit( loadLimit ); // Reset to valid value.
			}
		}
	};

	const handlePerPageCommit = ( val ) => {
		const parsed = val.trim();
		const num = parseInt( parsed, 10 );
		if ( ! isNaN( num ) && num > 0 ) {
			setPerPage( num );
		} else {
			setLocalPerPage( String( perPage ) ); // Reset to valid value.
		}
	};

	const providers = useMemo( () => {
		const set = new Set();
		sessionsList.forEach( ( s ) => {
			if ( s.provider ) {
				set.add( s.provider );
			}
		} );
		return [ ...set ].sort();
	}, [ sessionsList ] );

	const filteredSessions = useMemo( () => {
		return sessionsList.filter( ( session ) => {
			if ( providerFilter !== 'all' && ( session.provider || '' ) !== providerFilter ) {
				return false;
			}
			return sessionMatchesSearch( session, searchQuery.trim() );
		} );
	}, [ sessionsList, searchQuery, providerFilter ] );

	const totalPages = useMemo(
		() => Math.max( 1, Math.ceil( filteredSessions.length / perPage ) ),
		[ filteredSessions.length, perPage ]
	);

	const paginatedSessions = useMemo( () => {
		const page = Math.min( currentPage, totalPages );
		const start = ( page - 1 ) * perPage;
		return filteredSessions.slice( start, start + perPage );
	}, [ filteredSessions, currentPage, totalPages, perPage ] );

	useEffect( () => {
		let cancelled = false;

		const loadSessions = async () => {
			setLoadingSessions( true );
			try {
				const response = await apiFetch( { path: `/baca/v1/sessions?limit=${ loadLimit }&order=${ sortOrder }` } );
				if ( ! cancelled ) {
					setSessionsList( Array.isArray( response?.sessions ) ? response.sessions : [] );
					if ( response?.load_limit ) {
						setLoadLimit( response.load_limit );
					}
					if ( response?.sort_order ) {
						setSortOrder( response.sort_order );
					}
				}
			} catch {
				if ( ! cancelled ) {
					setSessionsList( [] );
					showNotice?.(
						__( 'Could not load chat sessions. Please refresh the page.', 'botisst-ai-chat-assistant' ),
						'error'
					);
				}
			} finally {
				if ( ! cancelled ) {
					setLoadingSessions( false );
				}
			}
		};

		loadSessions();

		return () => {
			cancelled = true;
		};
	}, [ showNotice, loadLimit, sortOrder ] );

	useEffect( () => {
		setCurrentPage( 1 );
	}, [ searchQuery, providerFilter, perPage, loadLimit, sortOrder ] );

	useEffect( () => {
		if ( ! filterOpen ) {
			return undefined;
		}

		const handlePointerDown = ( event ) => {
			if ( filterRef.current && ! filterRef.current.contains( event.target ) ) {
				setFilterOpen( false );
			}
		};

		const handleKeyDown = ( event ) => {
			if ( event.key === 'Escape' ) {
				setFilterOpen( false );
			}
		};

		document.addEventListener( 'mousedown', handlePointerDown );
		document.addEventListener( 'keydown', handleKeyDown );

		return () => {
			document.removeEventListener( 'mousedown', handlePointerDown );
			document.removeEventListener( 'keydown', handleKeyDown );
		};
	}, [ filterOpen ] );

	const filterOptions = useMemo(
		() => [
			{ value: 'all', label: __( 'All providers', 'botisst-ai-chat-assistant' ) },
			...providers.map( ( provider ) => ( {
				value: provider,
				label: formatProviderLabel( provider ),
			} ) ),
		],
		[ providers ]
	);

	const activeFilterLabel =
		filterOptions.find( ( option ) => option.value === providerFilter )?.label ||
		__( 'All providers', 'botisst-ai-chat-assistant' );

	const selectProvider = useCallback( ( value ) => {
		setProviderFilter( value );
		setFilterOpen( false );
	}, [] );

	useEffect( () => {
		if ( currentPage > totalPages ) {
			setCurrentPage( totalPages );
		}
	}, [ currentPage, totalPages ] );

	useEffect( () => {
		if ( selectedSession ) {
			setModalOpen( true );
			document.body.classList.add( 'baca-modal-open' );
		} else {
			setModalOpen( false );
			document.body.classList.remove( 'baca-modal-open' );
		}
		return () => document.body.classList.remove( 'baca-modal-open' );
	}, [ selectedSession ] );

	const openSession = useCallback( ( session ) => {
		setSelectedSession( session );
	}, [] );

	const closeModal = useCallback( () => {
		setSelectedSession( null );
	}, [] );

	const handleDelete = useCallback(
		async ( session ) => {
			setDeletingId( session.session_id );
			try {
				await apiFetch( {
					path: '/baca/v1/delete-session',
					method: 'POST',
					data: { session_id: session.session_id },
				} );
				setSessionsList( ( prev ) => prev.filter( ( s ) => s.session_id !== session.session_id ) );
				if ( selectedSession?.session_id === session.session_id ) {
					closeModal();
				}
				showNotice?.(
					__( 'Session deleted.', 'botisst-ai-chat-assistant' ),
					'success'
				);
			} catch {
				showNotice?.(
					__( 'Could not delete this session. Please try again.', 'botisst-ai-chat-assistant' ),
					'error'
				);
			} finally {
				setDeletingId( null );
			}
		},
		[ selectedSession, closeModal, showNotice ]
	);

	const handleExportCsv = useCallback( () => {
		if ( ! filteredSessions.length ) {
			showNotice?.(
				__( 'No sessions to export.', 'botisst-ai-chat-assistant' ),
				'error'
			);
			return;
		}

		const escape = ( val ) => `"${ String( val ?? '' ).replace( /"/g, '""' ) }"`;
		const header = [ 'Email', 'Provider', 'Model', 'Last Message', 'Created', 'Updated' ];
		const rows = filteredSessions.map( ( session ) => {
			const name = getDisplayUser( session );
			return [
				name,
				session.provider || '',
				session.model || '',
				getLastMessageSnippet( session ).replace( /^"|"$/g, '' ),
				session.created_at || '',
				session.updated_at || '',
			]
				.map( escape )
				.join( ',' );
		} );

		const csv = [ header.map( escape ).join( ',' ), ...rows ].join( '\n' );
		const blob = new Blob( [ csv ], { type: 'text/csv;charset=utf-8;' } );
		const url = URL.createObjectURL( blob );
		const link = document.createElement( 'a' );
		link.href = url;
		link.download = `botisst-chat-sessions-${ new Date().toISOString().slice( 0, 10 ) }.csv`;
		link.click();
		URL.revokeObjectURL( url );
	}, [ filteredSessions, showNotice ] );

	const handlePrintTranscript = useCallback( () => {
		if ( ! selectedSession ) {
			return;
		}
		const name = getDisplayUser( selectedSession );
		const meta = getSessionMeta( selectedSession );
		const msgs = parseMessages( selectedSession.content );

		const printWindow = window.open( '', '_blank', 'width=720,height=900' );
		if ( ! printWindow ) {
			showNotice?.(
				__( 'Allow pop-ups to download or print the transcript.', 'botisst-ai-chat-assistant' ),
				'error'
			);
			return;
		}

		printWindow.document.write(
			`<!DOCTYPE html><html><head><title>${ __( 'Session Transcript', 'botisst-ai-chat-assistant' ) }</title>
			<style>
				body { font-family: system-ui, sans-serif; padding: 2rem; color: #111827; line-height: 1.5; }
				h1 { font-size: 1.25rem; margin-bottom: 0.25rem; }
				.meta { color: #6b7280; font-size: 0.875rem; margin-bottom: 2rem; }
				.msg { margin-bottom: 1.25rem; }
				.role { font-weight: 600; font-size: 0.8125rem; text-transform: uppercase; letter-spacing: 0.04em; color: #6366f1; }
				.time { color: #9ca3af; font-size: 0.75rem; font-weight: 400; margin-left: 0.5rem; }
				.body { margin-top: 0.35rem; white-space: pre-wrap; }
			</style></head><body>
			<h1>${ __( 'Session Transcript', 'botisst-ai-chat-assistant' ) }</h1>
			<p class="meta">${ escapeHtml( name ) }</p>
			<p class="meta"><strong>${ __( 'Provider', 'botisst-ai-chat-assistant' ) }:</strong> ${ escapeHtml( meta.provider ) } · <strong>${ __( 'Model', 'botisst-ai-chat-assistant' ) }:</strong> ${ escapeHtml( meta.model ) }</p>
			${ msgs
				.map( ( msg ) => {
					const role = msg.role === 'user' ? name : __( 'Assistant', 'botisst-ai-chat-assistant' );
					return `<div class="msg"><div class="role">${ escapeHtml( role ) }<span class="time">${ formatMessageTime( msg.created_at ) }</span></div><div class="body">${ escapeHtml( msg.content ) }</div></div>`;
				} )
				.join( '' ) }
			</body></html>`
		);
		printWindow.document.close();
		printWindow.focus();
		printWindow.print();
	}, [ selectedSession, showNotice ] );

	const pageNumbers = buildPageNumbers( Math.min( currentPage, totalPages ), totalPages );
	const showingFrom = filteredSessions.length ? ( Math.min( currentPage, totalPages ) - 1 ) * perPage + 1 : 0;
	const showingTo = Math.min( Math.min( currentPage, totalPages ) * perPage, filteredSessions.length );

	return (
		<div className="baca-sessions">
			<div className="baca-sessions-toolbar">
				<div className="baca-sessions-toolbar__left">
					<div className="baca-sessions-search">
						<span className="dashicons dashicons-search" aria-hidden="true" />
						<input
							type="search"
							className="baca-sessions-search__input"
							placeholder={ __( 'Search sessions…', 'botisst-ai-chat-assistant' ) }
							value={ searchQuery }
							onChange={ ( e ) => setSearchQuery( e.target.value ) }
							aria-label={ __( 'Search sessions', 'botisst-ai-chat-assistant' ) }
						/>
					</div>
					<div
						ref={ filterRef }
						className={ `baca-sessions-filter ${ filterOpen ? 'is-open' : '' }` }
					>
						<button
							type="button"
							className="baca-sessions-filter__trigger"
							onClick={ () => setFilterOpen( ( open ) => ! open ) }
							aria-expanded={ filterOpen }
							aria-haspopup="listbox"
							aria-label={ __( 'Filter by provider', 'botisst-ai-chat-assistant' ) }
						>
							<span className="dashicons dashicons-filter" aria-hidden="true" />
							<span className="baca-sessions-filter__label">{ activeFilterLabel }</span>
							<span
								className={ `dashicons dashicons-arrow-down-alt2 baca-sessions-filter__chevron ${ filterOpen ? 'is-open' : '' }` }
								aria-hidden="true"
							/>
						</button>
						{ filterOpen && (
							<ul className="baca-sessions-filter__menu" role="listbox" aria-label={ __( 'Providers', 'botisst-ai-chat-assistant' ) }>
								{ filterOptions.map( ( option ) => (
									<li key={ option.value } role="presentation">
										<button
											type="button"
											role="option"
											aria-selected={ providerFilter === option.value }
											className={ `baca-sessions-filter__option ${ providerFilter === option.value ? 'is-selected' : '' }` }
											onClick={ () => selectProvider( option.value ) }
										>
											{ option.label }
										</button>
									</li>
								) ) }
							</ul>
						) }
					</div>

					<div className="baca-sessions-limit">
						<span className="dashicons dashicons-database" aria-hidden="true" />
						<span className="baca-sessions-limit__label">{ __( 'Limit:', 'botisst-ai-chat-assistant' ) }</span>
						<input
							type="text"
							pattern="[0-9]*"
							inputMode="numeric"
							className="baca-sessions-limit__input"
							placeholder={ __( 'All', 'botisst-ai-chat-assistant' ) }
							value={ localLimit === 'all' ? '' : localLimit }
							onChange={ ( e ) => setLocalLimit( e.target.value ) }
							onBlur={ () => handleLimitCommit( localLimit ) }
							onKeyDown={ ( e ) => {
								if ( e.key === 'Enter' ) {
									e.preventDefault();
									handleLimitCommit( localLimit );
									e.target.blur();
								}
							} }
							title={ __( 'Sessions load limit (empty for all)', 'botisst-ai-chat-assistant' ) }
							aria-label={ __( 'Sessions load limit', 'botisst-ai-chat-assistant' ) }
						/>
					</div>

					<div className="baca-sessions-switcher">
						<button
							type="button"
							className={ `baca-sessions-switcher__btn ${ sortOrder === 'desc' ? 'is-active' : '' }` }
							onClick={ () => setSortOrder( 'desc' ) }
							aria-label={ __( 'Sort by newest first', 'botisst-ai-chat-assistant' ) }
						>
							{ __( 'Newest', 'botisst-ai-chat-assistant' ) }
						</button>
						<button
							type="button"
							className={ `baca-sessions-switcher__btn ${ sortOrder === 'asc' ? 'is-active' : '' }` }
							onClick={ () => setSortOrder( 'asc' ) }
							aria-label={ __( 'Sort by oldest first', 'botisst-ai-chat-assistant' ) }
						>
							{ __( 'Oldest', 'botisst-ai-chat-assistant' ) }
						</button>
					</div>
				</div>
				<div className="baca-sessions-toolbar__right">
					<button type="button" className="baca-sessions-export" onClick={ handleExportCsv }>
						{ __( 'Export CSV', 'botisst-ai-chat-assistant' ) }
					</button>
				</div>
			</div>

			{ loadingSessions ? (
				<div className="baca-sessions-empty">
					<span className="baca-spinner baca-spinner--muted" aria-hidden="true" />
					<h3>{ __( 'Loading sessions…', 'botisst-ai-chat-assistant' ) }</h3>
				</div>
			) : sessionsList.length === 0 ? (
				<div className="baca-sessions-empty">
					<span className="dashicons dashicons-format-chat" aria-hidden="true" />
					<h3>{ __( 'No sessions yet', 'botisst-ai-chat-assistant' ) }</h3>
					<p>{ __( 'Conversations will show up here once visitors start chatting with your bot.', 'botisst-ai-chat-assistant' ) }</p>
				</div>
			) : filteredSessions.length === 0 ? (
				<div className="baca-sessions-empty baca-sessions-empty--compact">
					<span className="dashicons dashicons-search" aria-hidden="true" />
					<h3>{ __( 'No matching sessions', 'botisst-ai-chat-assistant' ) }</h3>
					<p>{ __( 'Try a different search term or filter.', 'botisst-ai-chat-assistant' ) }</p>
				</div>
			) : (
				<>
					<div className="baca-sessions-table-wrap">
						<table className="baca-sessions-table">
							<thead>
								<tr>
									<th>{ __( 'Email', 'botisst-ai-chat-assistant' ) }</th>
									<th>{ __( 'Provider', 'botisst-ai-chat-assistant' ) }</th>
									<th>{ __( 'Created', 'botisst-ai-chat-assistant' ) }</th>
									<th>{ __( 'Last message', 'botisst-ai-chat-assistant' ) }</th>
									<th className="baca-sessions-table__actions-col">{ __( 'Actions', 'botisst-ai-chat-assistant' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ paginatedSessions.map( ( session ) => {
									const name = getDisplayUser( session );
									const snippet = getLastMessageSnippet( session );
									const isDeleting = deletingId === session.session_id;

									return (
										<tr key={ session.session_id }>
											<td>
												<span className="baca-sessions-user__name">{ name }</span>
											</td>
											<td className="baca-sessions-provider">
												{ session.provider ? (
													<span className="baca-sessions-provider__badge">
														{ formatProviderLabel( session.provider ) }
													</span>
												) : (
													<span className="baca-sessions-last-msg--empty">
														{ __( 'Unknown', 'botisst-ai-chat-assistant' ) }
													</span>
												) }
											</td>
											<td className="baca-sessions-date">
												{ session.created_at ? (
													<span>{ formatMessageTime( session.created_at ) }</span>
												) : (
													<span className="baca-sessions-last-msg--empty">
														{ __( 'Unknown', 'botisst-ai-chat-assistant' ) }
													</span>
												) }
											</td>
											<td className="baca-sessions-last-msg">
												{ snippet || (
													<span className="baca-sessions-last-msg--empty">
														{ __( 'No messages', 'botisst-ai-chat-assistant' ) }
													</span>
												) }
											</td>
											<td className="baca-sessions-table__actions">
												<button
													type="button"
													className="baca-sessions-icon-btn"
													onClick={ () => openSession( session ) }
													title={ __( 'View session', 'botisst-ai-chat-assistant' ) }
													aria-label={ __( 'View session', 'botisst-ai-chat-assistant' ) }
												>
													<span className="dashicons dashicons-visibility" aria-hidden="true" />
												</button>
												<button
													type="button"
													className="baca-sessions-icon-btn baca-sessions-icon-btn--danger"
													onClick={ ( e ) => {
													e.stopPropagation();
													setConfirmingSession( session );
												} }
													disabled={ isDeleting }
													title={ __( 'Delete session', 'botisst-ai-chat-assistant' ) }
													aria-label={ __( 'Delete session', 'botisst-ai-chat-assistant' ) }
												>
													<span className="dashicons dashicons-trash" aria-hidden="true" />
												</button>
											</td>
										</tr>
									);
								} ) }
							</tbody>
						</table>
					</div>

					<footer className="baca-sessions-footer">
						<p className="baca-sessions-footer__count">
							{ filteredSessions.length
								? sprintf(
										/* translators: 1: start index, 2: end index, 3: total count */
										__( 'Showing %1$d–%2$d of %3$d sessions', 'botisst-ai-chat-assistant' ),
										showingFrom,
										showingTo,
										filteredSessions.length
								  )
								: __( 'No sessions', 'botisst-ai-chat-assistant' ) }
						</p>
						<div className="baca-sessions-footer__right">
							<div className="baca-sessions-per-page-input-wrap">
								<span className="baca-sessions-per-page-input__label">
									{ __( 'Rows per page:', 'botisst-ai-chat-assistant' ) }
								</span>
								<input
									type="text"
									pattern="[0-9]*"
									inputMode="numeric"
									className="baca-sessions-per-page-input__field"
									value={ localPerPage }
									onChange={ ( e ) => setLocalPerPage( e.target.value ) }
									onBlur={ () => handlePerPageCommit( localPerPage ) }
									onKeyDown={ ( e ) => {
										if ( e.key === 'Enter' ) {
											e.preventDefault();
											handlePerPageCommit( localPerPage );
											e.target.blur();
										}
									} }
									aria-label={ __( 'Sessions per page', 'botisst-ai-chat-assistant' ) }
								/>
							</div>
							{ totalPages > 1 && (
								<nav className="baca-sessions-pagination" aria-label={ __( 'Sessions pagination', 'botisst-ai-chat-assistant' ) }>
								<button
									type="button"
									className="baca-sessions-page-btn"
									disabled={ currentPage <= 1 }
									onClick={ () => setCurrentPage( ( p ) => Math.max( 1, p - 1 ) ) }
									aria-label={ __( 'Previous page', 'botisst-ai-chat-assistant' ) }
								>
									<span className="dashicons dashicons-arrow-left-alt2" aria-hidden="true" />
								</button>
								{ pageNumbers.map( ( page, idx ) =>
									page === '…' ? (
										<span key={ `ellipsis-${ idx }` } className="baca-sessions-page-ellipsis">
											…
										</span>
									) : (
										<button
											key={ page }
											type="button"
											className={ `baca-sessions-page-num ${ currentPage === page ? 'is-active' : '' }` }
											onClick={ () => setCurrentPage( page ) }
											aria-current={ currentPage === page ? 'page' : undefined }
										>
											{ page }
										</button>
									)
								) }
								<button
									type="button"
									className="baca-sessions-page-btn"
									disabled={ currentPage >= totalPages }
									onClick={ () => setCurrentPage( ( p ) => Math.min( totalPages, p + 1 ) ) }
									aria-label={ __( 'Next page', 'botisst-ai-chat-assistant' ) }
								>
									<span className="dashicons dashicons-arrow-right-alt2" aria-hidden="true" />
								</button>
								</nav>
							) }
						</div>
					</footer>
				</>
			) }

			{ selectedSession && (
				<div className={ `baca-modal baca-sessions-modal ${ modalOpen ? 'is-visible' : '' }` } role="dialog" aria-modal="true" aria-labelledby="baca-session-modal-title">
					<div className="baca-modal-overlay" onClick={ closeModal } />
					<div className="baca-modal-content">
						<div className="baca-modal-header">
							<div className="baca-modal-title">
								<span className="baca-modal-title-icon" aria-hidden="true">
									<span className="dashicons dashicons-format-chat" />
								</span>
								<h3 id="baca-session-modal-title">{ __( 'Session Transcript', 'botisst-ai-chat-assistant' ) }</h3>
							</div>
							<button type="button" className="baca-modal-close" onClick={ closeModal } aria-label={ __( 'Close', 'botisst-ai-chat-assistant' ) }>
								<span className="dashicons dashicons-no-alt" aria-hidden="true" />
							</button>
						</div>
						{ ( () => {
							const meta = getSessionMeta( selectedSession );
							return (
								<div className="baca-sessions-modal-meta">
									<div className="baca-sessions-modal-meta__item">
										<span className="baca-sessions-modal-meta__label">
											{ __( 'Provider', 'botisst-ai-chat-assistant' ) }
										</span>
										<span className="baca-sessions-modal-meta__value">{ meta.provider }</span>
									</div>
									<div className="baca-sessions-modal-meta__item">
										<span className="baca-sessions-modal-meta__label">
											{ __( 'Model', 'botisst-ai-chat-assistant' ) }
										</span>
										<span className="baca-sessions-modal-meta__value">{ meta.model }</span>
									</div>
								</div>
							);
						} )() }
						<div className="baca-modal-body">
							{ ( () => {
								const msgs = parseMessages( selectedSession.content );
								if ( ! msgs.length ) {
									return <p className="baca-text-muted">{ __( 'No messages in this session.', 'botisst-ai-chat-assistant' ) }</p>;
								}
								return msgs.map( ( msg, i ) => (
									<div
										key={ i }
										className={ `baca-modal-msg ${ msg.role === 'user' ? 'baca-modal-msg-user' : 'baca-modal-msg-assistant' }` }
									>
										<div className="baca-modal-msg-content">{ msg.content }</div>
										{ msg.created_at && (
											<div className="baca-modal-msg-time">{ formatMessageTime( msg.created_at ) }</div>
										) }
									</div>
								) );
							} )() }
						</div>
						<div className="baca-modal-footer">
							<button type="button" className="baca-modal-footer-link" onClick={ handlePrintTranscript }>
								{ __( 'Print Chat Transcript', 'botisst-ai-chat-assistant' ) }
							</button>
							<button type="button" className="baca-btn baca-btn-primary" onClick={ closeModal }>
								{ __( 'Return to Sessions', 'botisst-ai-chat-assistant' ) }
							</button>
						</div>
					</div>
				</div>
			) }

			<ConfirmDialog
				open={ !! confirmingSession }
				title={ __( 'Delete chat session', 'botisst-ai-chat-assistant' ) }
				message={ __( 'Delete this chat session permanently? This cannot be undone.', 'botisst-ai-chat-assistant' ) }
				confirmLabel={ __( 'Delete Session', 'botisst-ai-chat-assistant' ) }
				busy={ !! deletingId && confirmingSession?.session_id === deletingId }
				onCancel={ () => setConfirmingSession( null ) }
				onConfirm={ () => {
					const session = confirmingSession;
					setConfirmingSession( null );
					handleDelete( session );
				} }
			/>
		</div>
	);
}
