( function () {
	'use strict';

	var config = window.wpCortexIndexing || {};
	var apiFetch = wp.apiFetch;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var restPath = config.restPath || '/wp-cortex/v1/index';

	var root = document.getElementById( 'wp-cortex-indexing' );
	if ( ! root ) {
		return;
	}

	var els = {
		status: document.getElementById( 'wp-cortex-status' ),
		progress: document.getElementById( 'wp-cortex-progress' ),
		bar: document.getElementById( 'wp-cortex-progress-bar' ),
		text: document.getElementById( 'wp-cortex-progress-text' ),
		eligible: document.getElementById( 'wp-cortex-eligible' ),
		sync: document.getElementById( 'wp-cortex-sync' ),
		rebuild: document.getElementById( 'wp-cortex-rebuild' ),
		pause: document.getElementById( 'wp-cortex-pause' ),
		resume: document.getElementById( 'wp-cortex-resume' ),
		cancel: document.getElementById( 'wp-cortex-cancel' ),
		requestError: document.getElementById( 'wp-cortex-request-error' ),
		errorsWrap: document.getElementById( 'wp-cortex-errors-wrap' ),
		errors: document.getElementById( 'wp-cortex-errors' )
	};

	// The run is processed on the server; this page only polls its state.
	var POLL_RUNNING = 3000;
	var POLL_IDLE = 15000;

	var busy = false;
	var actionPending = false;
	var lastRun = null;
	var pollTimer = null;
	var polling = false;
	var failures = 0;
	// First progress seen for the current run, for the ETA.
	var session = { runId: '', at: 0, processed: 0 };

	function fmt( n ) {
		return Number( n || 0 ).toLocaleString();
	}

	function fmtSize( bytes ) {
		bytes = Number( bytes || 0 );
		if ( bytes >= 1048576 ) {
			return ( bytes / 1048576 ).toLocaleString( undefined, { maximumFractionDigits: 1 } ) + ' MB';
		}
		return ( bytes / 1024 ).toLocaleString( undefined, { maximumFractionDigits: 1 } ) + ' KB';
	}

	function fmtDuration( seconds ) {
		seconds = Math.max( 0, Math.round( seconds ) );
		var h = Math.floor( seconds / 3600 );
		var m = Math.floor( ( seconds % 3600 ) / 60 );
		var s = seconds % 60;
		var pad = function ( v ) {
			return v < 10 ? '0' + v : String( v );
		};
		return h > 0 ? h + ':' + pad( m ) + ':' + pad( s ) : m + ':' + pad( s );
	}

	function fmtDate( value ) {
		if ( ! value ) {
			return '–';
		}
		var date;
		if ( /^\d+$/.test( String( value ) ) ) {
			date = new Date( Number( value ) * 1000 );
		} else {
			// SQLite-style "YYYY-MM-DD HH:MM:SS" is stored in UTC.
			var str = String( value );
			date = new Date( /[zZ]|[+-]\d\d:?\d\d$/.test( str ) ? str : str.replace( ' ', 'T' ) + 'Z' );
		}
		return isNaN( date.getTime() ) ? String( value ) : date.toLocaleString();
	}

	function post( action, data ) {
		return apiFetch( { path: restPath + '/' + action, method: 'POST', data: data || {} } );
	}

	function setText( selector, value, scope ) {
		var node = ( scope || root ).querySelector( selector );
		if ( node ) {
			node.textContent = value;
		}
	}

	function showRequestError( message ) {
		var p = els.requestError.querySelector( 'p' );
		if ( message ) {
			p.textContent = message;
			els.requestError.hidden = false;
		} else {
			p.textContent = '';
			els.requestError.hidden = true;
		}
	}

	function renderStats( stats, statsErrors ) {
		[ 'public', 'admin' ].forEach( function ( scope ) {
			var card = root.querySelector( '[data-stats-scope="' + scope + '"]' );
			if ( ! card ) {
				return;
			}
			var s = stats ? stats[ scope ] : null;
			var errNode = card.querySelector( '[data-stat-error]' );
			var list = card.querySelector( '[data-stat="by_type"]' );

			if ( ! s ) {
				errNode.textContent = ( statsErrors && statsErrors[ scope ] ) || __( 'Statistics are unavailable.', 'wp-cortex' );
				errNode.hidden = false;
				return;
			}
			errNode.hidden = true;

			var pct = s.chunks > 0 ? Math.round( ( s.embedded / s.chunks ) * 100 ) : 0;
			setText( '[data-stat="documents"]', fmt( s.documents ), card );
			setText( '[data-stat="chunks"]', fmt( s.chunks ), card );
			setText( '[data-stat="embedded"]', fmt( s.embedded ) + ' (' + pct + '%)', card );
			setText( '[data-stat="fields"]', fmt( s.fields ), card );
			setText( '[data-stat="size"]', fmtSize( s.size ), card );
			setText( '[data-stat="updated"]', fmtDate( s.last_update ), card );

			list.textContent = '';
			var byType = s.by_type || {};
			Object.keys( byType ).forEach( function ( type ) {
				var li = document.createElement( 'li' );
				var name = document.createElement( 'span' );
				var count = document.createElement( 'strong' );
				name.textContent = type;
				count.textContent = fmt( byType[ type ] );
				li.appendChild( name );
				li.appendChild( count );
				list.appendChild( li );
			} );
		} );
	}

	function statusLabel( status ) {
		var labels = {
			running: __( 'Running', 'wp-cortex' ),
			paused: __( 'Paused', 'wp-cortex' ),
			completed: __( 'Completed', 'wp-cortex' ),
			cancelled: __( 'Cancelled', 'wp-cortex' ),
			failed: __( 'Failed', 'wp-cortex' )
		};
		return labels[ status ] || __( 'Idle', 'wp-cortex' );
	}

	function renderErrors( run ) {
		var errors = ( run && run.errors ) || [];
		els.errors.textContent = '';
		els.errorsWrap.hidden = errors.length === 0;

		errors.slice( -20 ).forEach( function ( err ) {
			var li = document.createElement( 'li' );
			if ( err.post_id > 0 ) {
				var a = document.createElement( 'a' );
				a.href = ( config.editPostUrl || '' ) + err.post_id;
				a.textContent = sprintf( __( 'Post #%d', 'wp-cortex' ), err.post_id );
				li.appendChild( a );
				li.appendChild( document.createTextNode( ': ' ) );
			}
			li.appendChild( document.createTextNode( err.message || '' ) );
			els.errors.appendChild( li );
		} );
	}

	function renderRun( run, eligible ) {
		lastRun = run;
		var status = run ? run.status : 'idle';
		var total = run ? run.total : 0;
		var processed = run ? run.processed : 0;
		var pct = total > 0 ? Math.min( 100, Math.round( ( processed / total ) * 100 ) ) : ( status === 'completed' ? 100 : 0 );

		els.status.textContent = statusLabel( status );
		els.status.className = 'wp-cortex-badge wp-cortex-badge-' + status;
		els.bar.style.width = pct + '%';
		els.progress.setAttribute( 'aria-valuenow', String( pct ) );
		els.progress.classList.toggle( 'is-complete', status === 'completed' );
		els.progress.classList.toggle( 'is-failed', status === 'failed' );

		els.text.textContent = run ?
			sprintf( __( '%1$s / %2$s posts', 'wp-cortex' ), fmt( processed ), fmt( total ) ) :
			__( 'No indexing run yet.', 'wp-cortex' );

		[ 'indexed', 'skipped', 'removed', 'failed', 'embedded_chunks', 'tokens' ].forEach( function ( key ) {
			setText( '[data-counter="' + key + '"]', run ? fmt( run[ key ] ) : '–' );
		} );

		var elapsed = '–';
		var eta = '–';
		if ( run ) {
			var end = run.finished_at || run.paused_at || Math.floor( Date.now() / 1000 );
			elapsed = fmtDuration( end - run.started_at - ( run.paused_seconds || 0 ) );
			if ( status !== 'running' ) {
				session.runId = ''; // Measure the speed again after a resume.
			} else {
				if ( session.runId !== run.id ) {
					session = { runId: run.id, at: Date.now(), processed: processed };
				}
				var done = processed - session.processed;
				var secs = ( Date.now() - session.at ) / 1000;
				if ( done > 0 && total > processed ) {
					eta = fmtDuration( ( secs / done ) * ( total - processed ) );
				}
			}
		}
		setText( '[data-counter="elapsed"]', elapsed );
		setText( '[data-counter="eta"]', eta );

		if ( typeof eligible === 'number' ) {
			els.eligible.textContent = sprintf( __( 'Eligible posts: %s', 'wp-cortex' ), fmt( eligible ) );
		}

		renderErrors( run );
		updateButtons();
	}

	function updateButtons() {
		var status = lastRun ? lastRun.status : '';
		var active = status === 'running' || status === 'paused';
		els.sync.disabled = busy || active;
		els.rebuild.disabled = busy || active;
		els.pause.hidden = status !== 'running';
		els.resume.hidden = status !== 'paused';
		els.cancel.hidden = ! active;
		els.pause.disabled = actionPending;
		els.resume.disabled = actionPending;
		els.cancel.disabled = actionPending;
	}

	function applyResponse( res ) {
		renderRun( res.run, res.eligible );
		renderStats( res.stats, res.stats_errors );
	}

	function isRunning() {
		return !! lastRun && lastRun.status === 'running';
	}

	function schedulePoll( delay ) {
		clearTimeout( pollTimer );
		pollTimer = null;
		if ( document.hidden ) {
			return; // Polling resumes when the tab becomes visible.
		}
		if ( typeof delay !== 'number' ) {
			delay = isRunning() ? POLL_RUNNING : POLL_IDLE;
		}
		pollTimer = setTimeout( poll, delay );
	}

	function poll() {
		if ( polling ) {
			return;
		}
		polling = true;
		clearTimeout( pollTimer );
		pollTimer = null;

		apiFetch( { path: restPath } ).then( function ( res ) {
			failures = 0;
			showRequestError( '' );
			applyResponse( res );
		} ).catch( function ( err ) {
			failures++;
			showRequestError( ( err && err.message ) || __( 'Could not load the indexing status.', 'wp-cortex' ) );
			if ( ! lastRun ) {
				els.sync.disabled = false;
				els.rebuild.disabled = false;
			}
		} ).then( function () {
			polling = false;
			// Back off while requests fail.
			schedulePoll( failures ? Math.min( 60000, POLL_RUNNING * Math.pow( 2, failures ) ) : undefined );
		} );
	}

	function start( mode ) {
		showRequestError( '' );
		busy = true; // Lock the buttons while the start request is in flight.
		updateButtons();
		post( 'start', { mode: mode } ).then( function ( res ) {
			applyResponse( res );
		} ).catch( function ( err ) {
			showRequestError( ( err && err.message ) || __( 'Could not start indexing.', 'wp-cortex' ) );
		} ).then( function () {
			busy = false;
			updateButtons();
			schedulePoll();
		} );
	}

	els.sync.addEventListener( 'click', function () {
		start( 'sync' );
	} );

	els.rebuild.addEventListener( 'click', function () {
		if ( window.confirm( __( 'This deletes both index databases and indexes all content again. Continue?', 'wp-cortex' ) ) ) {
			start( 'rebuild' );
		}
	} );

	function runAction( action, failMessage ) {
		showRequestError( '' );
		actionPending = true;
		updateButtons();
		post( action ).then( applyResponse ).catch( function ( err ) {
			showRequestError( ( err && err.message ) || failMessage );
		} ).then( function () {
			actionPending = false;
			updateButtons();
			schedulePoll();
		} );
	}

	els.pause.addEventListener( 'click', function () {
		runAction( 'pause', __( 'Could not pause.', 'wp-cortex' ) );
	} );

	els.resume.addEventListener( 'click', function () {
		runAction( 'resume', __( 'Could not resume.', 'wp-cortex' ) );
	} );

	els.cancel.addEventListener( 'click', function () {
		runAction( 'cancel', __( 'Could not cancel.', 'wp-cortex' ) );
	} );

	document.addEventListener( 'visibilitychange', function () {
		if ( document.hidden ) {
			clearTimeout( pollTimer );
			pollTimer = null;
		} else {
			poll();
		}
	} );

	poll();
}() );
