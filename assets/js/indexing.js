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
		resume: document.getElementById( 'wp-cortex-resume' ),
		cancel: document.getElementById( 'wp-cortex-cancel' ),
		requestError: document.getElementById( 'wp-cortex-request-error' ),
		errorsWrap: document.getElementById( 'wp-cortex-errors-wrap' ),
		errors: document.getElementById( 'wp-cortex-errors' )
	};

	var looping = false;
	var cancelRequested = false;
	var lastRun = null;
	var session = { startedAt: 0, startProcessed: 0 };

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

	function sleep( ms ) {
		return new Promise( function ( resolve ) {
			setTimeout( resolve, ms );
		} );
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
			var end = run.finished_at || Math.floor( Date.now() / 1000 );
			elapsed = fmtDuration( end - run.started_at );
			if ( status === 'running' && looping && session.startedAt ) {
				var done = processed - session.startProcessed;
				var secs = ( Date.now() - session.startedAt ) / 1000;
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
		var running = !! lastRun && lastRun.status === 'running';
		els.sync.disabled = looping || running;
		els.rebuild.disabled = looping || running;
		els.cancel.hidden = ! running;
		els.cancel.disabled = cancelRequested;
		els.resume.hidden = ! ( running && ! looping );
	}

	function applyResponse( res ) {
		renderRun( res.run, res.eligible );
		renderStats( res.stats, res.stats_errors );
	}

	function request( action, data ) {
		var delays = [ 2000, 4000, 8000 ];
		var attempt = 0;

		function run() {
			return post( action, data ).catch( function ( err ) {
				if ( attempt >= delays.length ) {
					throw err;
				}
				var delay = delays[ attempt++ ];
				showRequestError( sprintf( __( 'Request failed (%1$s). Retrying in %2$d s…', 'wp-cortex' ), ( err && err.message ) || '', delay / 1000 ) );
				return sleep( delay ).then( run );
			} );
		}

		return run();
	}

	function loop() {
		if ( looping ) {
			return Promise.resolve();
		}
		looping = true;
		cancelRequested = false;
		session.startedAt = Date.now();
		session.startProcessed = lastRun ? lastRun.processed : 0;
		updateButtons();

		function step() {
			if ( cancelRequested ) {
				return Promise.resolve();
			}
			return request( 'batch' ).then( function ( res ) {
				showRequestError( '' );
				applyResponse( res );
				if ( ! res.run || res.run.status !== 'running' ) {
					return null;
				}
				return ( res.locked ? sleep( 2000 ) : Promise.resolve() ).then( step );
			} );
		}

		return step().catch( function ( err ) {
			showRequestError( sprintf( __( 'Indexing stopped: %s', 'wp-cortex' ), ( err && err.message ) || __( 'Unknown error.', 'wp-cortex' ) ) );
		} ).then( function () {
			looping = false;
			updateButtons();
		} );
	}

	function start( mode ) {
		showRequestError( '' );
		cancelRequested = false;
		lastRun = lastRun || {};
		looping = true; // Lock the buttons while the start request is in flight.
		updateButtons();
		post( 'start', { mode: mode } ).then( function ( res ) {
			looping = false;
			applyResponse( res );
			return loop();
		} ).catch( function ( err ) {
			looping = false;
			showRequestError( ( err && err.message ) || __( 'Could not start indexing.', 'wp-cortex' ) );
			updateButtons();
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

	els.resume.addEventListener( 'click', function () {
		showRequestError( '' );
		loop();
	} );

	els.cancel.addEventListener( 'click', function () {
		cancelRequested = true;
		els.cancel.disabled = true;
		post( 'cancel' ).then( applyResponse ).catch( function ( err ) {
			showRequestError( ( err && err.message ) || __( 'Could not cancel.', 'wp-cortex' ) );
		} ).then( function () {
			cancelRequested = false;
			updateButtons();
		} );
	} );

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( looping ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );

	apiFetch( { path: restPath } ).then( applyResponse ).catch( function ( err ) {
		showRequestError( ( err && err.message ) || __( 'Could not load the indexing status.', 'wp-cortex' ) );
		els.sync.disabled = false;
		els.rebuild.disabled = false;
	} );
}() );
