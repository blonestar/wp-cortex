( function () {
	'use strict';

	var apiFetch = wp.apiFetch;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var restPath = '/wp-cortex/v1/index';

	function fmtDate( timestamp ) {
		var date = new Date( Number( timestamp ) * 1000 );
		return isNaN( date.getTime() ) ? String( timestamp ) : date.toLocaleString();
	}

	function setText( selector, value, scope ) {
		var node = scope.querySelector( selector );
		if ( node ) {
			node.textContent = value;
		}
	}

	var dirToggle = document.getElementById( 'wp-cortex-data-dir-toggle' );
	if ( dirToggle ) {
		dirToggle.addEventListener( 'click', function () {
			var path = document.getElementById( 'wp-cortex-data-dir-path' );
			var mask = document.querySelector( '.wp-cortex-data-dir-mask' );
			var show = path.hidden;
			var label = show ? __( 'Hide data directory', 'wp-cortex' ) : __( 'Show data directory', 'wp-cortex' );

			path.hidden = ! show;
			if ( mask ) {
				mask.hidden = show;
			}
			dirToggle.setAttribute( 'aria-expanded', show ? 'true' : 'false' );
			dirToggle.setAttribute( 'aria-label', label );
			dirToggle.title = label;
			dirToggle.firstElementChild.className = 'dashicons ' + ( show ? 'dashicons-hidden' : 'dashicons-visibility' );
		} );
	}

	var storageCheck = document.getElementById( 'wp-cortex-storage-check' );
	var recheck = document.getElementById( 'wp-cortex-storage-recheck' );

	function checkTargetLabel( target ) {
		if ( target === 'directory' ) {
			return __( 'Data directory', 'wp-cortex' );
		}
		return target + '.sqlite';
	}

	function checkStatusLabel( check ) {
		var labels = {
			directory: {
				protected: __( 'Blocked, files are not listed', 'wp-cortex' ),
				reachable: __( 'Reachable, but files are not listed', 'wp-cortex' ),
				exposed: __( 'Publicly reachable, the file list is visible!', 'wp-cortex' ),
				unknown: __( 'Could not be tested', 'wp-cortex' )
			},
			file: {
				protected: __( 'Not downloadable', 'wp-cortex' ),
				exposed: __( 'Publicly downloadable!', 'wp-cortex' ),
				missing: __( 'Not created yet', 'wp-cortex' ),
				unknown: __( 'Could not be tested', 'wp-cortex' )
			}
		};
		var label = labels[ check.target === 'directory' ? 'directory' : 'file' ][ check.status ] || check.status;

		if ( check.code ) {
			label += ' (HTTP ' + check.code + ')';
		} else if ( check.error ) {
			label += ': ' + check.error;
		}
		return label;
	}

	function renderStorageCheck( report ) {
		var summaries = {
			ok: __( 'Not publicly accessible', 'wp-cortex' ),
			outside: __( 'Outside the web root, not publicly accessible', 'wp-cortex' ),
			exposed: __( 'Publicly accessible!', 'wp-cortex' ),
			unknown: __( 'Public access could not be fully tested', 'wp-cortex' )
		};
		var icons = { ok: 'yes-alt', outside: 'yes-alt', exposed: 'warning', unknown: 'editor-help' };
		var list = storageCheck.querySelector( '[data-check-list]' );

		storageCheck.className = 'wp-cortex-storage-check is-' + report.status;
		storageCheck.querySelector( '.dashicons' ).className = 'dashicons dashicons-' + ( icons[ report.status ] || 'editor-help' );
		storageCheck.querySelector( '[data-check-summary]' ).textContent = summaries[ report.status ] || report.status;
		storageCheck.querySelector( '[data-check-help]' ).hidden = report.status !== 'exposed';

		list.textContent = '';
		( report.checks || [] ).forEach( function ( check ) {
			var li = document.createElement( 'li' );
			var name = document.createElement( 'span' );
			var value = document.createElement( 'strong' );
			li.className = 'is-' + check.status;
			name.textContent = checkTargetLabel( check.target ) + ': ';
			value.textContent = checkStatusLabel( check );
			li.appendChild( name );
			li.appendChild( value );
			list.appendChild( li );
		} );

		setText( '[data-check-time]', report.checked_at ? sprintf( __( 'Checked %s.', 'wp-cortex' ), fmtDate( report.checked_at ) ) : '', storageCheck );
	}

	function runStorageCheck( force ) {
		recheck.disabled = true;
		storageCheck.className = 'wp-cortex-storage-check is-checking';
		storageCheck.querySelector( '.dashicons' ).className = 'dashicons dashicons-update';
		setText( '[data-check-summary]', __( 'Checking public access…', 'wp-cortex' ), storageCheck );

		apiFetch( { path: restPath + '/storage-check', method: force ? 'POST' : 'GET' } ).then( renderStorageCheck ).catch( function ( err ) {
			renderStorageCheck( { status: 'unknown', checks: [] } );
			setText( '[data-check-time]', ( err && err.message ) || __( 'The check failed.', 'wp-cortex' ), storageCheck );
		} ).then( function () {
			recheck.disabled = false;
		} );
	}

	if ( storageCheck && recheck ) {
		recheck.addEventListener( 'click', function () {
			runStorageCheck( true );
		} );
		runStorageCheck( false );
	}
} )();
