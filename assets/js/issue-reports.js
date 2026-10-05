/**
 * Cortex Issue reports screen: lists the problems visitors reported in the visitor chat,
 * marks them as resolved, dismissed or open, saves a note and deletes them. A single
 * report can be opened from the URL hash (#report=ID), for example from a conversation.
 */
( function () {
	'use strict';

	var cfg = window.wpCortexIssueReports || {};
	var apiFetch = wp.apiFetch;
	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;
	var PATH = '/wp-cortex/v1/issue-reports';
	var PER_PAGE = 20;
	var STATUSES = cfg.statuses || {};

	var root = document.getElementById( 'wp-cortex-issue-reports' );
	if ( ! root ) {
		return;
	}

	function byId( id ) {
		return document.getElementById( id );
	}

	var els = {
		notice: byId( 'wp-cortex-reports-notice' ),
		filters: byId( 'wp-cortex-reports-filters' ),
		search: byId( 'wp-cortex-reports-search' ),
		searchInput: byId( 'wp-cortex-reports-search-input' ),
		bulk: byId( 'wp-cortex-reports-bulk' ),
		bulkApply: byId( 'wp-cortex-reports-bulk-apply' ),
		pages: byId( 'wp-cortex-reports-pages' ),
		single: byId( 'wp-cortex-reports-single' ),
		showAll: byId( 'wp-cortex-reports-show-all' ),
		selectAll: byId( 'wp-cortex-reports-select-all' ),
		list: byId( 'wp-cortex-reports-list' )
	};

	// The initial filter can come from the URL (&status=resolved); open reports by default.
	var initialStatus = new URLSearchParams( window.location.search ).get( 'status' );
	var state = { status: null !== initialStatus && ( '' === initialStatus || STATUSES[ initialStatus ] ) ? initialStatus : 'open', search: '', page: 1, pages: 1, total: 0, reports: [], counts: null, single: 0 };

	/* ---------- Helpers ---------- */

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) {
			n.className = cls;
		}
		if ( undefined !== text ) {
			n.textContent = text;
		}
		return n;
	}

	function notify( message, isError ) {
		els.notice.className = 'notice inline ' + ( isError ? 'notice-error' : 'notice-success' );
		els.notice.querySelector( 'p' ).textContent = message;
		els.notice.hidden = false;
	}

	function clearNotice() {
		els.notice.hidden = true;
	}

	function fail( err ) {
		notify( ( err && err.message ) || __( 'Something went wrong.', 'wp-cortex' ), true );
	}

	function fmtDate( value ) {
		if ( ! value ) {
			return '–';
		}
		var d = new Date( value.replace( ' ', 'T' ) + 'Z' );
		return isNaN( d.getTime() ) ? value : d.toLocaleString();
	}

	function isHttpUrl( url ) {
		try {
			var u = new URL( url, window.location.href );
			return 'http:' === u.protocol || 'https:' === u.protocol;
		} catch ( e ) {
			return false;
		}
	}

	function link( text, url, newTab ) {
		if ( ! url || ! isHttpUrl( url ) ) {
			return el( 'span', '', text );
		}
		var a = el( 'a', '', text );
		a.href = url;
		if ( newTab ) {
			a.target = '_blank';
			a.rel = 'noopener';
		}
		return a;
	}

	function badge( report ) {
		var cls = { open: 'wp-cortex-badge-running', resolved: 'wp-cortex-badge-ok', dismissed: '' }[ report.status ] || '';
		return el( 'span', 'wp-cortex-badge ' + cls, STATUSES[ report.status ] || report.status );
	}

	// Keeps the open count in the admin menu in sync without a reload.
	function updateMenuCount( count ) {
		var a = document.querySelector( '#adminmenu a[href$="page=wp-cortex-issue-reports"]' );
		if ( ! a ) {
			return;
		}
		var bubble = a.querySelector( '.awaiting-mod' );
		if ( ! count ) {
			if ( bubble ) {
				bubble.parentNode.removeChild( bubble );
			}
			return;
		}
		if ( ! bubble ) {
			bubble = el( 'span', 'awaiting-mod' );
			bubble.appendChild( el( 'span', 'pending-count' ) );
			a.appendChild( document.createTextNode( ' ' ) );
			a.appendChild( bubble );
		}
		bubble.className = 'awaiting-mod count-' + count;
		bubble.querySelector( '.pending-count' ).textContent = String( count );
	}

	/* ---------- List ---------- */

	function renderFilters() {
		var counts = state.counts || { all: 0, open: 0, resolved: 0, dismissed: 0 };
		var filters = [
			[ 'open', STATUSES.open || 'open', counts.open ],
			[ 'resolved', STATUSES.resolved || 'resolved', counts.resolved ],
			[ 'dismissed', STATUSES.dismissed || 'dismissed', counts.dismissed ],
			[ '', __( 'All', 'wp-cortex' ), counts.all ]
		];
		els.filters.innerHTML = '';
		filters.forEach( function ( f, i ) {
			var li = el( 'li' );
			var a = el( 'a', ! state.single && state.status === f[ 0 ] ? 'current' : '', f[ 1 ] + ' ' );
			a.href = '#';
			a.appendChild( el( 'span', 'count', '(' + f[ 2 ] + ')' ) );
			a.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				state.status = f[ 0 ];
				state.page = 1;
				showAll();
			} );
			li.appendChild( a );
			if ( i < filters.length - 1 ) {
				li.appendChild( document.createTextNode( ' | ' ) );
			}
			els.filters.appendChild( li );
		} );
	}

	function renderPages() {
		els.pages.innerHTML = '';
		els.pages.appendChild( el( 'span', 'displaying-num', sprintf( _n( '%s item', '%s items', state.total, 'wp-cortex' ), state.total ) ) );
		if ( state.pages < 2 ) {
			return;
		}
		var wrap = el( 'span', 'pagination-links' );
		function pageButton( label, text, page, disabled ) {
			var b = el( 'button', 'button', text );
			b.type = 'button';
			b.setAttribute( 'aria-label', label );
			b.disabled = disabled;
			b.addEventListener( 'click', function () {
				state.page = page;
				load();
			} );
			wrap.appendChild( b );
		}
		pageButton( __( 'Previous page', 'wp-cortex' ), '‹', state.page - 1, state.page <= 1 );
		wrap.appendChild( el( 'span', 'paging-input', ' ' + sprintf( __( '%1$d of %2$d', 'wp-cortex' ), state.page, state.pages ) + ' ' ) );
		pageButton( __( 'Next page', 'wp-cortex' ), '›', state.page + 1, state.page >= state.pages );
		els.pages.appendChild( wrap );
	}

	function rowAction( actions, text, onClick, cls ) {
		if ( actions.childNodes.length ) {
			actions.appendChild( document.createTextNode( ' | ' ) );
		}
		var span = el( 'span', cls || '' );
		var b = el( 'button', 'button-link', text );
		b.type = 'button';
		b.addEventListener( 'click', onClick );
		span.appendChild( b );
		actions.appendChild( span );
	}

	function renderNoteEditor( report, cell ) {
		if ( cell.querySelector( '.wp-cortex-report-note-form' ) ) {
			return;
		}
		var form = el( 'form', 'wp-cortex-report-note-form' );
		var label = el( 'label', 'screen-reader-text', __( 'Note', 'wp-cortex' ) );
		var area = el( 'textarea', 'large-text' );
		area.id = 'wp-cortex-report-note-' + report.id;
		label.htmlFor = area.id;
		area.rows = 3;
		area.maxLength = 4000;
		area.value = report.admin_note || '';
		area.placeholder = __( 'For example: fixed in the page text.', 'wp-cortex' );
		var save = el( 'button', 'button button-primary', __( 'Save note', 'wp-cortex' ) );
		save.type = 'submit';
		var cancel = el( 'button', 'button', __( 'Cancel', 'wp-cortex' ) );
		cancel.type = 'button';
		cancel.addEventListener( 'click', function () {
			form.parentNode.removeChild( form );
		} );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			save.disabled = true;
			update( report.id, { admin_note: area.value }, __( 'Note saved.', 'wp-cortex' ) );
		} );
		form.appendChild( label );
		form.appendChild( area );
		var buttons = el( 'p', 'wp-cortex-actions' );
		buttons.appendChild( save );
		buttons.appendChild( cancel );
		form.appendChild( buttons );
		cell.appendChild( form );
		area.focus();
	}

	function renderRow( report ) {
		var tr = el( 'tr', 'open' === report.status ? 'wp-cortex-report-open' : '' );

		var cb = el( 'th', 'check-column' );
		cb.scope = 'row';
		var box = el( 'input' );
		box.type = 'checkbox';
		box.value = report.id;
		box.setAttribute( 'aria-label', sprintf( __( 'Select report %d', 'wp-cortex' ), report.id ) );
		cb.appendChild( box );
		tr.appendChild( cb );

		var main = el( 'td', 'column-primary' );
		var title = el( 'strong', 'wp-cortex-report-category', report.category_label );
		main.appendChild( title );
		main.appendChild( el( 'span', 'wp-cortex-muted', ' #' + report.id ) );
		main.appendChild( el( 'p', 'wp-cortex-report-description', report.description ) );
		if ( report.excerpt ) {
			main.appendChild( el( 'blockquote', 'wp-cortex-report-excerpt', report.excerpt ) );
		}
		if ( report.admin_note ) {
			main.appendChild( el( 'p', 'wp-cortex-vchat-row-note', report.admin_note ) );
		}

		var actions = el( 'div', 'row-actions visible' );
		if ( 'resolved' !== report.status ) {
			rowAction( actions, __( 'Mark as resolved', 'wp-cortex' ), function () {
				update( report.id, { status: 'resolved' }, __( 'Report marked as resolved.', 'wp-cortex' ) );
			} );
		}
		if ( 'dismissed' !== report.status ) {
			rowAction( actions, __( 'Dismiss', 'wp-cortex' ), function () {
				update( report.id, { status: 'dismissed' }, __( 'Report dismissed.', 'wp-cortex' ) );
			} );
		}
		if ( 'open' !== report.status ) {
			rowAction( actions, __( 'Reopen', 'wp-cortex' ), function () {
				update( report.id, { status: 'open' }, __( 'Report reopened.', 'wp-cortex' ) );
			} );
		}
		rowAction( actions, report.admin_note ? __( 'Edit note', 'wp-cortex' ) : __( 'Add note', 'wp-cortex' ), function () {
			renderNoteEditor( report, main );
		} );
		if ( report.chat_id && cfg.chatUrl ) {
			if ( actions.childNodes.length ) {
				actions.appendChild( document.createTextNode( ' | ' ) );
			}
			actions.appendChild( link( __( 'View conversation', 'wp-cortex' ), cfg.chatUrl + '#chat=' + report.chat_id, false ) );
		}
		rowAction( actions, __( 'Delete', 'wp-cortex' ), function () {
			remove( report.id );
		}, 'trash' );
		main.appendChild( actions );
		tr.appendChild( main );

		var page = el( 'td', 'wp-cortex-col-page' );
		if ( report.page ) {
			page.appendChild( link( report.page.title || report.page.url, report.page_url || report.page.url, true ) );
			if ( report.page.edit_url ) {
				page.appendChild( document.createTextNode( ' · ' ) );
				page.appendChild( link( __( 'Edit', 'wp-cortex' ), report.page.edit_url, false ) );
			}
		} else if ( report.page_url ) {
			page.appendChild( link( report.page_url, report.page_url, true ) );
		} else {
			page.appendChild( el( 'span', 'wp-cortex-muted', '–' ) );
		}
		tr.appendChild( page );

		tr.appendChild( el( 'td', '', fmtDate( report.created_at ) ) );

		var status = el( 'td' );
		status.appendChild( badge( report ) );
		if ( 'open' !== report.status && report.resolved_at ) {
			status.appendChild( el( 'span', 'wp-cortex-block wp-cortex-muted', [ fmtDate( report.resolved_at ), report.resolved_by ].filter( Boolean ).join( ' · ' ) ) );
		}
		tr.appendChild( status );

		return tr;
	}

	function renderList() {
		renderFilters();
		els.single.hidden = ! state.single;
		els.pages.hidden = !! state.single;
		if ( ! state.single ) {
			renderPages();
		}
		els.selectAll.checked = false;
		els.list.innerHTML = '';
		if ( ! state.reports.length ) {
			var tr = el( 'tr' );
			var empty = state.search || '' === state.status ? __( 'No reports match.', 'wp-cortex' ) : __( 'No reports here.', 'wp-cortex' );
			var td = el( 'td', '', state.counts && ! state.counts.all ? __( 'No issue reports yet.', 'wp-cortex' ) : empty );
			td.colSpan = 5;
			tr.appendChild( td );
			els.list.appendChild( tr );
			return;
		}
		state.reports.forEach( function ( report ) {
			els.list.appendChild( renderRow( report ) );
		} );
	}

	function applyCounts( counts ) {
		if ( counts ) {
			state.counts = counts;
			updateMenuCount( counts.open || 0 );
		}
	}

	function load() {
		if ( state.single ) {
			return apiFetch( { path: PATH + '/' + state.single } ).then( function ( report ) {
				state.reports = [ report ];
				state.total = 1;
				renderList();
			} ).catch( function ( err ) {
				state.single = 0;
				fail( err );
				return load();
			} );
		}
		var query = '?page=' + state.page + '&per_page=' + PER_PAGE;
		if ( state.status ) {
			query += '&status=' + encodeURIComponent( state.status );
		}
		if ( state.search ) {
			query += '&search=' + encodeURIComponent( state.search );
		}
		return apiFetch( { path: PATH + query } ).then( function ( res ) {
			state.reports = res.reports || [];
			state.total = res.total || 0;
			state.pages = res.pages || 1;
			applyCounts( res.counts );
			if ( state.page > state.pages ) {
				state.page = state.pages;
				return load();
			}
			renderList();
		} ).catch( fail );
	}

	// Counts for the filters and menu while a single report is shown.
	function loadCounts() {
		return apiFetch( { path: PATH + '?per_page=1' } ).then( function ( res ) {
			applyCounts( res.counts );
			renderFilters();
		} ).catch( function () {} );
	}

	/* ---------- Actions ---------- */

	function update( id, data, message ) {
		clearNotice();
		return apiFetch( { path: PATH + '/' + id, method: 'PATCH', data: data } ).then( function () {
			notify( message, false );
			return state.single ? load().then( loadCounts ) : load();
		} ).catch( fail );
	}

	function remove( id ) {
		if ( ! window.confirm( __( 'Delete this report? This cannot be undone.', 'wp-cortex' ) ) ) {
			return;
		}
		clearNotice();
		apiFetch( { path: PATH + '/' + id, method: 'DELETE' } ).then( function () {
			notify( __( 'Report deleted.', 'wp-cortex' ), false );
			if ( state.single ) {
				showAll();
			} else {
				load();
			}
		} ).catch( fail );
	}

	function selectedIds() {
		return Array.prototype.map.call( els.list.querySelectorAll( 'input[type="checkbox"]:checked' ), function ( box ) {
			return parseInt( box.value, 10 );
		} );
	}

	function applyBulk() {
		var action = els.bulk.value;
		var ids = selectedIds();
		if ( ! action || ! ids.length ) {
			return;
		}
		if ( 'delete' === action && ! window.confirm( sprintf( _n( 'Delete %d report? This cannot be undone.', 'Delete %d reports? This cannot be undone.', ids.length, 'wp-cortex' ), ids.length ) ) ) {
			return;
		}
		clearNotice();
		apiFetch( { path: PATH + '/bulk', method: 'POST', data: { action: action, ids: ids } } ).then( function ( res ) {
			els.bulk.value = '';
			notify( sprintf( _n( '%d report updated.', '%d reports updated.', res.updated || 0, 'wp-cortex' ), res.updated || 0 ), false );
			return state.single ? load().then( loadCounts ) : load();
		} ).catch( fail );
	}

	/* ---------- Routing ---------- */

	function showAll() {
		if ( state.single ) {
			state.single = 0;
			window.history.pushState( null, '', window.location.pathname + window.location.search );
		}
		load();
	}

	function route() {
		var m = /^#report=(\d+)$/.exec( window.location.hash );
		state.single = m ? parseInt( m[ 1 ], 10 ) : 0;
		if ( state.single ) {
			load().then( loadCounts );
		} else {
			load();
		}
	}

	els.search.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		state.search = els.searchInput.value.trim();
		state.page = 1;
		showAll();
	} );
	els.selectAll.addEventListener( 'change', function () {
		Array.prototype.forEach.call( els.list.querySelectorAll( 'input[type="checkbox"]' ), function ( box ) {
			box.checked = els.selectAll.checked;
		} );
	} );
	els.bulkApply.addEventListener( 'click', applyBulk );
	els.showAll.addEventListener( 'click', function ( e ) {
		e.preventDefault();
		showAll();
	} );
	window.addEventListener( 'hashchange', route );

	route();
}() );
