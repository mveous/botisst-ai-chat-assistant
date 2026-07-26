import './baca-dashboard.css';
import { render, useState, useEffect } from '@wordpress/element';
import Dashboard from './Dashboard';
import SetupWizard from './components/SetupWizard';

function StandaloneWizard() {
	const [ open, setOpen ] = useState( false );
	const [ settings, setSettings ] = useState( () => window.baca_data?.settings );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		const handleTrigger = ( e ) => {
			if ( e.target.classList.contains( 'baca-run-wizard-trigger' ) || e.target.closest('.baca-run-wizard-trigger') ) {
				e.preventDefault();
				setOpen( true );
			}
		};
		document.addEventListener( 'click', handleTrigger );
		return () => document.removeEventListener( 'click', handleTrigger );
	}, [] );

	const showNotice = ( message, type = 'success' ) => {
		setNotice( { message, type } );
		setTimeout( () => setNotice( null ), 10000 );
	};

	if ( ! open ) {
		return null;
	}

	return (
		<>
			<SetupWizard
				open={ open }
				settings={ settings }
				onSave={ ( updatedSettings ) => {
					if ( window.baca_data ) {
						window.baca_data.settings = { ...window.baca_data.settings, ...updatedSettings };
					}
					setSettings( ( prev ) => ( { ...prev, ...updatedSettings } ) );
				} }
				onClose={ () => {
					setOpen( false );
					const noticeEl = document.querySelector( '.baca-setup-notice' );
					if ( noticeEl ) {
						noticeEl.style.display = 'none';
					}
				} }
				showNotice={ showNotice }
			/>
			{ notice && (
				<div className={ `baca-toast baca-toast-${ notice.type }` } style={ { zIndex: 9999999 } }>
					<span className={ `dashicons ${ notice.type === 'success' ? 'dashicons-yes-alt' : 'dashicons-warning' }` }></span>
					<span className="baca-toast-message">{ notice.message }</span>
					<button type="button" className="baca-toast-close" onClick={ () => setNotice( null ) } aria-label="Close notice">
						<span className="dashicons dashicons-no-alt"></span>
					</button>
				</div>
			) }
		</>
	);
}

document.addEventListener( 'DOMContentLoaded', () => {
	const root = document.getElementById( 'baca-admin-root' );
	if ( root ) {
		render( <Dashboard settings={window.baca_data.settings} />, root );
	}

	const standaloneRoot = document.getElementById( 'baca-wizard-standalone-root' );
	if ( standaloneRoot ) {
		render( <StandaloneWizard />, standaloneRoot );
	}
} );
