/**
 * Cortex Visitor chats screen: lists stored visitor conversations, opens one with its
 * transcript and contact details, generates the AI summary, forwards it by email, saves
 * the administrator note, marks conversations as read or unread and deletes them. The open conversation is kept in the URL hash
 * (#chat=ID), so the browser's back button returns to the list.
 *
 * Each conversation shows whether the visitor is still in it (active, idle or ended,
 * computed by the server from the last message and the widget's presence pings). The
 * open conversation and the list refresh periodically while the tab is visible, and
 * forwarding warns when the conversation may not be finished or has continued since it
 * was last sent.
 */
( function () {
	'use strict';

	var cfg = window.wpCortexVisitorChats || {};
	var apiFetch = wp.apiFetch;
	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;
	var PATH = '/wp-cortex/v1/visitor-chats';
	var PER_PAGE = 20;
	var FORWARD_KEY = 'wpCortexForwardTo';
	var REFRESH_INTERVAL = 15000;
	var LIST_REFRESH_INTERVAL = 30000;
	var CONTACT_LABELS = {
		first_name: __( 'First name', 'wp-cortex' ),
		last_name: __( 'Last name', 'wp-cortex' ),
		email: __( 'Email', 'wp-cortex' ),
		phone: __( 'Phone', 'wp-cortex' ),
		address: __( 'Address', 'wp-cortex' ),
		company: __( 'Company', 'wp-cortex' ),
		website: __( 'Website URL(s)', 'wp-cortex' ),
		request: __( 'Request', 'wp-cortex' )
	};

	var root = document.getElementById( 'wp-cortex-visitor-chats' );
	if ( ! root ) {
		return;
	}

	function byId( id ) {
		return document.getElementById( id );
	}

	var els = {
		notice: byId( 'wp-cortex-vchats-notice' ),
		listView: byId( 'wp-cortex-vchats-list-view' ),
		filters: byId( 'wp-cortex-vchats-filters' ),
		search: byId( 'wp-cortex-vchats-search' ),
		searchInput: byId( 'wp-cortex-vchats-search-input' ),
		bulk: byId( 'wp-cortex-vchats-bulk' ),
		bulkApply: byId( 'wp-cortex-vchats-bulk-apply' ),
		pages: byId( 'wp-cortex-vchats-pages' ),
		selectAll: byId( 'wp-cortex-vchats-select-all' ),
		list: byId( 'wp-cortex-vchats-list' ),
		detail: byId( 'wp-cortex-vchat-detail' ),
		back: byId( 'wp-cortex-vchat-back' ),
		title: byId( 'wp-cortex-vchat-title' ),
		meta: byId( 'wp-cortex-vchat-meta' ),
		activity: byId( 'wp-cortex-vchat-activity' ),
		transcript: byId( 'wp-cortex-vchat-transcript' ),
		contact: byId( 'wp-cortex-vchat-contact' ),
		noteForm: byId( 'wp-cortex-vchat-note-form' ),
		note: byId( 'wp-cortex-vchat-note' ),
		summarize: byId( 'wp-cortex-vchat-summarize' ),
		summaryStatus: byId( 'wp-cortex-vchat-summary-status' ),
		summaryText: byId( 'wp-cortex-vchat-summary-text' ),
		pagesBox: byId( 'wp-cortex-vchat-pages' ),
		forwardForm: byId( 'wp-cortex-vchat-forward-form' ),
		forwardTo: byId( 'wp-cortex-vchat-forward-to' ),
		forwardMessage: byId( 'wp-cortex-vchat-forward-message' ),
		forwardSummarize: byId( 'wp-cortex-vchat-forward-summarize' ),
		forwardHint: byId( 'wp-cortex-vchat-forward-hint' ),
		forwardWarning: byId( 'wp-cortex-vchat-forward-warning' ),
		forwardSend: byId( 'wp-cortex-vchat-forward-send' ),
		forwarded: byId( 'wp-cortex-vchat-forwarded' ),
		toggleRead: byId( 'wp-cortex-vchat-toggle-read' ),
		del: byId( 'wp-cortex-vchat-delete' )
	};

	// The initial filter can come from the URL (&filter=unread), e.g. from the dashboard widget.
	var initialFilter = new URLSearchParams( window.location.search ).get( 'filter' );
	var state = { filter: -1 !== [ 'unread', 'contact' ].indexOf( initialFilter ) ? initialFilter : '', search: '', page: 1, pages: 1, total: 0, chats: [], counts: null };
	var current = null;
	// Requests that re-render the open conversation themselves; refreshing waits for them.
	var pending = 0;
	var sending = false;
	var listLoadedAt = 0;
	// Blob URLs of the loaded images of the open conversation, by file name.
	var imageUrls = {};

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

	function toTime( value ) {
		var t = value ? new Date( value.replace( ' ', 'T' ) + 'Z' ).getTime() : 0;
		return isNaN( t ) ? 0 : t;
	}

	// "just now", "5 min ago" or, after an hour, the date.
	function fmtAgo( value ) {
		var minutes = Math.floor( ( Date.now() - toTime( value ) ) / 60000 );
		if ( minutes < 1 ) {
			return __( 'just now', 'wp-cortex' );
		}
		if ( minutes < 60 ) {
			return sprintf( _n( '%d minute ago', '%d minutes ago', minutes, 'wp-cortex' ), minutes );
		}
		return fmtDate( value );
	}

	// Latest visitor message, contact change or presence ping.
	function lastActivity( chat ) {
		return toTime( chat.seen_at ) > toTime( chat.updated_at ) ? chat.seen_at : chat.updated_at;
	}

	// The visitor closed the chat window after their last message.
	function closedChat( chat ) {
		return chat.seen_at && ! chat.chat_open && toTime( chat.seen_at ) >= toTime( chat.updated_at );
	}

	function activityBadge( chat ) {
		if ( 'active' === chat.activity ) {
			return el( 'span', 'wp-cortex-badge wp-cortex-badge-ok wp-cortex-vchat-live', __( 'Active', 'wp-cortex' ) );
		}
		if ( 'idle' === chat.activity ) {
			return el( 'span', 'wp-cortex-badge wp-cortex-badge-warn', __( 'Idle', 'wp-cortex' ) );
		}
		return null;
	}

	function activityText( chat ) {
		if ( 'active' === chat.activity ) {
			return chat.chat_open ?
				__( 'The visitor has the chat open right now: the conversation may still be in progress.', 'wp-cortex' ) :
				sprintf( __( 'The visitor wrote %s: the conversation may still be in progress.', 'wp-cortex' ), fmtAgo( chat.updated_at ) );
		}
		if ( 'idle' === chat.activity ) {
			return closedChat( chat ) ?
				sprintf( __( 'The visitor closed the chat %s and may come back.', 'wp-cortex' ), fmtAgo( chat.seen_at ) ) :
				sprintf( __( 'No activity since %s: the visitor may come back.', 'wp-cortex' ), fmtAgo( lastActivity( chat ) ) );
		}
		return sprintf( __( 'Conversation ended: no activity since %s.', 'wp-cortex' ), fmtDate( lastActivity( chat ) ) );
	}

	function isHttpUrl( url ) {
		try {
			var u = new URL( url, window.location.href );
			return 'http:' === u.protocol || 'https:' === u.protocol;
		} catch ( e ) {
			return false;
		}
	}

	function link( text, url, cls ) {
		if ( ! url || ! isHttpUrl( url ) ) {
			return el( 'span', cls, text );
		}
		var a = el( 'a', cls, text );
		a.href = url;
		a.target = '_blank';
		a.rel = 'noopener';
		return a;
	}

	function contactName( contact ) {
		return [ contact.first_name, contact.last_name ].filter( Boolean ).join( ' ' );
	}

	function appendWebsiteLinks( parent, value, cls ) {
		String( value ).split( /\r?\n/ ).filter( Boolean ).forEach( function ( url, i ) {
			if ( i && ! cls ) {
				parent.appendChild( document.createTextNode( ', ' ) );
			}
			parent.appendChild( link( url, url, cls ) );
		} );
	}

	function badge( chat ) {
		return el( 'span', 'wp-cortex-badge ' + ( chat.is_read ? '' : 'wp-cortex-badge-running' ), chat.is_read ? __( 'Read', 'wp-cortex' ) : __( 'Unread', 'wp-cortex' ) );
	}

	function menuLink() {
		return document.querySelector( '#adminmenu a[href$="page=wp-cortex-visitor-chats"]' );
	}

	function menuCount() {
		var count = menuLink() && menuLink().querySelector( '.pending-count' );
		return count ? parseInt( count.textContent.replace( /\D/g, '' ), 10 ) || 0 : 0;
	}

	// Keeps the unread count in the admin menu in sync without a reload.
	function updateMenuCount( unread ) {
		var a = menuLink();
		if ( ! a ) {
			return;
		}
		unread = Math.max( 0, unread );
		var bubble = a.querySelector( '.awaiting-mod' );
		if ( ! unread ) {
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
		bubble.className = 'awaiting-mod count-' + unread;
		bubble.querySelector( '.pending-count' ).textContent = String( unread );
	}

	/* ---------- List ---------- */

	function renderFilters() {
		var counts = state.counts || { all: 0, unread: 0, contact: 0 };
		var filters = [
			[ '', __( 'All', 'wp-cortex' ), counts.all ],
			[ 'unread', __( 'Unread', 'wp-cortex' ), counts.unread ],
			[ 'contact', __( 'With contact details', 'wp-cortex' ), counts.contact ]
		];
		els.filters.innerHTML = '';
		filters.forEach( function ( f, i ) {
			var li = el( 'li' );
			var a = el( 'a', state.filter === f[ 0 ] ? 'current' : '', f[ 1 ] + ' ' );
			a.href = '#';
			a.appendChild( el( 'span', 'count', '(' + f[ 2 ] + ')' ) );
			a.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				state.filter = f[ 0 ];
				state.page = 1;
				load();
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

	function renderContactCell( chat ) {
		var td = el( 'td', 'wp-cortex-col-contact' );
		var c = chat.contact || {};
		if ( ! chat.has_contact ) {
			td.appendChild( el( 'span', 'wp-cortex-muted', c.request ? c.request : '–' ) );
			return td;
		}
		var name = contactName( c );
		if ( name ) {
			td.appendChild( el( 'strong', 'wp-cortex-block', name ) );
		}
		[ c.email, c.phone, c.company ].filter( Boolean ).forEach( function ( v ) {
			td.appendChild( el( 'span', 'wp-cortex-block', v ) );
		} );
		if ( c.website ) {
			appendWebsiteLinks( td, c.website, 'wp-cortex-block' );
		}
		return td;
	}

	function renderRow( chat ) {
		var tr = el( 'tr', chat.is_read ? '' : 'wp-cortex-vchat-unread' );

		var cb = el( 'th', 'check-column' );
		cb.scope = 'row';
		var box = el( 'input' );
		box.type = 'checkbox';
		box.value = chat.id;
		box.setAttribute( 'aria-label', sprintf( __( 'Select conversation %d', 'wp-cortex' ), chat.id ) );
		cb.appendChild( box );
		tr.appendChild( cb );

		var main = el( 'td', 'column-primary' );
		var open = el( 'a', 'row-title', chat.preview || sprintf( __( 'Conversation #%d', 'wp-cortex' ), chat.id ) );
		open.href = '#chat=' + chat.id;
		main.appendChild( open );
		var meta = el( 'p', 'wp-cortex-vchat-row-meta' );
		meta.appendChild( document.createTextNode( sprintf( __( 'Started %s', 'wp-cortex' ), fmtDate( chat.created_at ) ) ) );
		if ( chat.page ) {
			meta.appendChild( document.createTextNode( ' · ' ) );
			meta.appendChild( link( chat.page.title || chat.page.url, chat.page.url ) );
		}
		if ( chat.ip ) {
			meta.appendChild( document.createTextNode( ' · IP ' + chat.ip ) );
		}
		main.appendChild( meta );
		if ( chat.admin_note ) {
			main.appendChild( el( 'p', 'wp-cortex-vchat-row-note', chat.admin_note ) );
		}
		tr.appendChild( main );

		tr.appendChild( renderContactCell( chat ) );
		tr.appendChild( el( 'td', '', String( chat.message_count || 0 ) ) );
		tr.appendChild( el( 'td', '', fmtDate( chat.updated_at ) ) );
		var status = el( 'td', 'wp-cortex-col-status' );
		var live = activityBadge( chat );
		if ( live ) {
			live.title = activityText( chat );
			status.appendChild( live );
		}
		status.appendChild( badge( chat ) );
		if ( chat.forward_stale ) {
			status.appendChild( el( 'span', 'wp-cortex-badge wp-cortex-badge-warn', __( 'Changed since sent', 'wp-cortex' ) ) );
		}
		tr.appendChild( status );

		return tr;
	}

	function renderList() {
		renderFilters();
		renderPages();
		els.selectAll.checked = false;
		els.list.innerHTML = '';
		if ( ! state.chats.length ) {
			var tr = el( 'tr' );
			var td = el( 'td', '', state.search || state.filter ? __( 'No conversations match.', 'wp-cortex' ) : __( 'No visitor conversations yet.', 'wp-cortex' ) );
			td.colSpan = 6;
			tr.appendChild( td );
			els.list.appendChild( tr );
			return;
		}
		state.chats.forEach( function ( chat ) {
			els.list.appendChild( renderRow( chat ) );
		} );
	}

	function load() {
		var query = '?page=' + state.page + '&per_page=' + PER_PAGE;
		if ( state.filter ) {
			query += '&filter=' + encodeURIComponent( state.filter );
		}
		if ( state.search ) {
			query += '&search=' + encodeURIComponent( state.search );
		}
		listLoadedAt = Date.now();
		return apiFetch( { path: PATH + query } ).then( function ( res ) {
			state.chats = res.chats || [];
			state.total = res.total || 0;
			state.pages = res.pages || 1;
			state.counts = res.counts || null;
			if ( state.page > state.pages ) {
				state.page = state.pages;
				return load();
			}
			renderList();
			updateMenuCount( state.counts ? state.counts.unread : 0 );
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
		// eslint-disable-next-line no-alert
		if ( 'delete' === action && ! window.confirm( sprintf( _n( 'Delete %d conversation?', 'Delete %d conversations?', ids.length, 'wp-cortex' ), ids.length ) ) ) {
			return;
		}
		apiFetch( { path: PATH + '/bulk', method: 'POST', data: { action: action, ids: ids } } ).then( function () {
			notify( 'delete' === action ? __( 'Conversations deleted.', 'wp-cortex' ) : __( 'Conversations updated.', 'wp-cortex' ), false );
			els.bulk.value = '';
			return load();
		} ).catch( fail );
	}

	/* ---------- Detail ---------- */

	function addMessage( cls, text, markdown, at ) {
		var m = el( 'div', 'wp-cortex-vchat-msg wp-cortex-vchat-msg-' + cls );
		var body = el( 'div', 'wp-cortex-vchat-text' );
		if ( markdown ) {
			body.innerHTML = window.wpCortexMarkdown.render( text );
		} else {
			body.textContent = text;
		}
		m.appendChild( body );
		if ( at ) {
			m.appendChild( el( 'span', 'wp-cortex-vchat-time', fmtDate( at ) ) );
		}
		els.transcript.appendChild( m );
		return m;
	}

	function clearImages() {
		Object.keys( imageUrls ).forEach( function ( name ) {
			URL.revokeObjectURL( imageUrls[ name ] );
		} );
		imageUrls = {};
	}

	function loadImage( name ) {
		if ( imageUrls[ name ] ) {
			return Promise.resolve( imageUrls[ name ] );
		}
		return apiFetch( { path: PATH + '/' + current.id + '/images/' + encodeURIComponent( name ) } ).then( function ( res ) {
			var bin = window.atob( res.data );
			var bytes = new Uint8Array( bin.length );
			for ( var i = 0; i < bin.length; i++ ) {
				bytes[ i ] = bin.charCodeAt( i );
			}
			imageUrls[ name ] = URL.createObjectURL( new Blob( [ bytes ], { type: res.mime } ) );
			return imageUrls[ name ];
		} );
	}

	// Images are loaded through the REST API (they are not publicly reachable) and shown
	// as blob URLs; a click opens the full image in a new tab.
	function renderImage( name ) {
		var wrap = el( 'a', 'wp-cortex-vchat-image', __( 'Loading image…', 'wp-cortex' ) );
		wrap.target = '_blank';
		wrap.rel = 'noopener';
		loadImage( name ).then( function ( url ) {
			var img = el( 'img' );
			img.src = url;
			img.alt = __( 'Image attached by the visitor', 'wp-cortex' );
			wrap.textContent = '';
			wrap.href = url;
			wrap.title = __( 'Open the full image', 'wp-cortex' );
			wrap.appendChild( img );
		} ).catch( function () {
			wrap.textContent = __( 'The image is no longer available.', 'wp-cortex' );
		} );
		return wrap;
	}

	function renderItem( item ) {
		var m;
		switch ( item.role ) {
			case 'user':
				m = addMessage( 'user', item.text || '', false, item.at );
				if ( item.image ) {
					m.insertBefore( renderImage( item.image ), m.firstChild );
				}
				if ( item.page ) {
					var on = el( 'span', 'wp-cortex-vchat-page', __( 'on', 'wp-cortex' ) + ' ' );
					on.appendChild( link( item.page.title || item.page.url, item.page.url ) );
					m.appendChild( on );
				}
				break;
			case 'assistant':
				addMessage( 'assistant', item.text || '', true, item.at );
				break;
			case 'sources':
				var wrap = el( 'div', 'wp-cortex-vchat-sources' );
				wrap.appendChild( document.createTextNode( __( 'Sources:', 'wp-cortex' ) + ' ' ) );
				( item.sources || [] ).forEach( function ( s, i ) {
					if ( i ) {
						wrap.appendChild( document.createTextNode( ', ' ) );
					}
					wrap.appendChild( link( s.title || s.url, s.url ) );
				} );
				els.transcript.appendChild( wrap );
				break;
			case 'navigate':
				var nav = el( 'div', 'wp-cortex-vchat-event', '→ ' + __( 'Visitor taken to', 'wp-cortex' ) + ' ' );
				nav.appendChild( link( item.title || item.url, item.url ) );
				els.transcript.appendChild( nav );
				break;
			case 'notice':
				els.transcript.appendChild( el( 'div', 'wp-cortex-vchat-event', '✓ ' + ( item.text || '' ) ) );
				break;
			case 'error':
				addMessage( 'error', item.text || '', false, item.at );
				break;
		}
	}

	function renderContact( chat ) {
		var contact = chat.contact;
		els.contact.innerHTML = '';
		var keys = Object.keys( CONTACT_LABELS ).filter( function ( k ) {
			return contact && contact[ k ];
		} );
		var dl = el( 'dl', 'wp-cortex-vchat-contact' );
		if ( ! keys.length ) {
			els.contact.appendChild( el( 'p', 'wp-cortex-muted', __( 'The visitor did not leave contact details.', 'wp-cortex' ) ) );
		}
		keys.forEach( function ( k ) {
			dl.appendChild( el( 'dt', '', CONTACT_LABELS[ k ] ) );
			var dd = el( 'dd' );
			var value = String( contact[ k ] );
			if ( 'email' === k ) {
				var mail = el( 'a', '', value );
				mail.href = 'mailto:' + value;
				dd.appendChild( mail );
			} else if ( 'phone' === k ) {
				var tel = el( 'a', '', value );
				tel.href = 'tel:' + value.replace( /[^0-9+]/g, '' );
				dd.appendChild( tel );
			} else if ( 'website' === k ) {
				appendWebsiteLinks( dd, value );
			} else {
				dd.textContent = value;
			}
			dl.appendChild( dd );
		} );
		ipAddresses( chat ).forEach( function ( row ) {
			dl.appendChild( el( 'dt', '', row[ 0 ] ) );
			dl.appendChild( el( 'dd', '', row[ 1 ] ) );
		} );
		if ( dl.childNodes.length ) {
			els.contact.appendChild( dl );
		}
	}

	// Every IP the visitor wrote from (a conversation can span networks), plus the
	// unverified proxy header addresses.
	function ipAddresses( chat ) {
		var ips = [];
		var forwarded = [];
		function add( list, value ) {
			String( value || '' ).split( /,\s*/ ).forEach( function ( ip ) {
				if ( ip && -1 === list.indexOf( ip ) ) {
					list.push( ip );
				}
			} );
		}
		( chat.transcript || [] ).forEach( function ( item ) {
			if ( 'user' === item.role ) {
				add( ips, item.ip );
				add( forwarded, item.ip_forwarded );
			}
		} );
		add( ips, chat.ip );
		add( forwarded, chat.ip_forwarded );
		var rows = [];
		if ( ips.length ) {
			rows.push( [ _n( 'IP address', 'IP addresses', ips.length, 'wp-cortex' ), ips.join( ', ' ) ] );
		}
		if ( forwarded.length ) {
			rows.push( [ __( 'Proxy headers (unverified)', 'wp-cortex' ), forwarded.join( ', ' ) ] );
		}
		return rows;
	}

	// Pages from the transcript: where the visitor wrote from, where they were taken and
	// what the assistant suggested.
	function renderVisitedPages( chat ) {
		var groups = [
			[ __( 'Visited while chatting', 'wp-cortex' ), [] ],
			[ __( 'Taken to', 'wp-cortex' ), [] ],
			[ __( 'Suggested by the assistant', 'wp-cortex' ), [] ]
		];
		function add( group, title, url ) {
			if ( url && ! groups[ group ][ 1 ].some( function ( p ) {
				return p.url === url;
			} ) ) {
				groups[ group ][ 1 ].push( { title: title || url, url: url } );
			}
		}
		if ( chat.page ) {
			add( 0, chat.page.title, chat.page.url );
		}
		( chat.transcript || [] ).forEach( function ( item ) {
			if ( 'user' === item.role && item.page ) {
				add( 0, item.page.title, item.page.url );
			} else if ( 'navigate' === item.role ) {
				add( 1, item.title, item.url );
			} else if ( 'sources' === item.role ) {
				( item.sources || [] ).forEach( function ( s ) {
					add( 2, s.title, s.url );
				} );
			}
		} );
		els.pagesBox.innerHTML = '';
		groups.forEach( function ( g ) {
			if ( ! g[ 1 ].length ) {
				return;
			}
			var p = el( 'p', 'wp-cortex-vchat-pages', g[ 0 ] + ': ' );
			g[ 1 ].forEach( function ( page, i ) {
				if ( i ) {
					p.appendChild( document.createTextNode( ', ' ) );
				}
				p.appendChild( link( page.title, page.url ) );
			} );
			els.pagesBox.appendChild( p );
		} );
	}

	function renderSummary( chat ) {
		els.summaryText.innerHTML = chat.summary ? window.wpCortexMarkdown.render( chat.summary ) : '';
		els.summarize.textContent = chat.summary ? __( 'Refresh summary', 'wp-cortex' ) : __( 'Summarize', 'wp-cortex' );
		els.summarize.disabled = false;
		els.summaryStatus.className = 'wp-cortex-vchat-summary-status';
		if ( ! chat.summary ) {
			els.summaryStatus.textContent = __( 'Let the AI model summarize what the visitor wanted, asked and looked at.', 'wp-cortex' );
		} else if ( chat.summary_stale ) {
			els.summaryStatus.className += ' is-stale';
			els.summaryStatus.textContent = sprintf( __( 'Generated %s. The conversation has continued since then: refresh the summary.', 'wp-cortex' ), fmtDate( chat.summary_at ) );
		} else {
			els.summaryStatus.textContent = sprintf( __( 'Generated %s.', 'wp-cortex' ), fmtDate( chat.summary_at ) );
		}
	}

	function summarize() {
		var id = current.id;
		els.summarize.disabled = true;
		els.summarize.textContent = __( 'Summarizing…', 'wp-cortex' );
		pending++;
		apiFetch( { path: PATH + '/' + id + '/summary', method: 'POST' } ).then( function ( chat ) {
			if ( current && current.id === id ) {
				current = chat;
				renderDetail();
			}
		} ).catch( function ( err ) {
			fail( err );
			if ( current && current.id === id ) {
				renderSummary( current );
			}
		} ).then( function () {
			pending--;
		} );
	}

	function forward() {
		var id = current.id;
		var to = els.forwardTo.value.trim();
		var withSummary = els.forwardSummarize.checked;
		// eslint-disable-next-line no-alert
		if ( 'active' === current.activity && ! window.confirm( __( 'The visitor is still in the chat, so the conversation may not be finished. Send it anyway? You can send an update later.', 'wp-cortex' ) ) ) {
			return;
		}
		sending = true;
		pending++;
		els.forwardSend.disabled = true;
		els.forwardSend.textContent = withSummary && ( ! current.summary || current.summary_stale ) ? __( 'Summarizing and sending…', 'wp-cortex' ) : __( 'Sending…', 'wp-cortex' );
		apiFetch( { path: PATH + '/' + id + '/forward', method: 'POST', data: { to: to, message: els.forwardMessage.value, summarize: withSummary } } ).then( function ( chat ) {
			try {
				window.localStorage.setItem( FORWARD_KEY, to );
			} catch ( e ) {}
			els.forwardMessage.value = '';
			if ( current && current.id === id ) {
				// The summary may have been generated before sending.
				current = chat;
				renderDetail();
			}
			notify( sprintf( __( 'Conversation sent to %s.', 'wp-cortex' ), to ), false );
		} ).catch( fail ).then( function () {
			sending = false;
			pending--;
			els.forwardSend.disabled = false;
			renderSendLabel();
		} );
	}

	function renderSendLabel() {
		if ( ! sending ) {
			els.forwardSend.textContent = current && current.forward_stale ? __( 'Send update', 'wp-cortex' ) : __( 'Send', 'wp-cortex' );
		}
	}

	function defaultForwardTo() {
		var saved = '';
		try {
			saved = window.localStorage.getItem( FORWARD_KEY ) || '';
		} catch ( e ) {}
		return saved || cfg.forwardTo || '';
	}

	function renderForwarded( chat ) {
		var text = chat.forwarded_at ? sprintf( __( 'Last sent to %1$s on %2$s.', 'wp-cortex' ), chat.forwarded_to, fmtDate( chat.forwarded_at ) ) : '';
		if ( chat.forward_stale ) {
			text += ' ' + ( chat.new_messages ?
				sprintf( _n( 'The conversation has continued since then: %d new visitor message. Send an update so the recipients have the whole conversation.', 'The conversation has continued since then: %d new visitor messages. Send an update so the recipients have the whole conversation.', chat.new_messages, 'wp-cortex' ), chat.new_messages ) :
				__( 'The conversation has changed since then. Send an update so the recipients have the latest version.', 'wp-cortex' ) );
		}
		els.forwarded.textContent = text;
		els.forwarded.className = chat.forward_stale ? 'wp-cortex-vchat-forward-hint is-stale' : 'description';
		renderForwardWarning( chat );
		renderForwardHint();
		renderSendLabel();
	}

	// Warns before forwarding a conversation the visitor may still continue.
	function renderForwardWarning( chat ) {
		var text = '';
		if ( 'active' === chat.activity ) {
			text = __( 'The visitor is still in the chat: the conversation may not be finished yet. If you send it now, send an update when it ends.', 'wp-cortex' );
		} else if ( 'idle' === chat.activity ) {
			text = sprintf( __( 'Last activity %s: the visitor may still come back to the conversation.', 'wp-cortex' ), fmtAgo( lastActivity( chat ) ) );
		}
		els.forwardWarning.textContent = text;
		els.forwardWarning.hidden = ! text;
	}

	function renderActivity( chat ) {
		els.activity.innerHTML = '';
		var live = activityBadge( chat );
		if ( live ) {
			els.activity.appendChild( live );
			els.activity.appendChild( document.createTextNode( ' ' ) );
		}
		els.activity.appendChild( el( 'span', live ? '' : 'wp-cortex-muted', activityText( chat ) ) );
	}

	// What happens to the summary when sending: generated, refreshed or sent as outdated.
	function renderForwardHint() {
		var text = '';
		var stale = false;
		if ( current && els.forwardSummarize.checked ) {
			if ( ! current.summary ) {
				text = __( 'A summary will be generated before sending.', 'wp-cortex' );
			} else if ( current.summary_stale ) {
				text = __( 'The summary is outdated and will be refreshed before sending.', 'wp-cortex' );
			}
		} else if ( current && current.summary && current.summary_stale ) {
			stale = true;
			text = __( 'The summary is outdated: the conversation continued after it was generated. It will be sent marked as outdated.', 'wp-cortex' );
		} else if ( current && ! current.summary ) {
			text = __( 'Sent without a summary.', 'wp-cortex' );
		}
		els.forwardHint.textContent = text;
		els.forwardHint.className = 'wp-cortex-vchat-forward-hint' + ( stale ? ' is-stale' : '' );
		els.forwardHint.hidden = ! text;
	}

	function renderDetail() {
		var chat = current;
		var name = contactName( chat.contact || {} );
		els.title.innerHTML = '';
		els.title.appendChild( document.createTextNode( name ? sprintf( __( 'Conversation with %s', 'wp-cortex' ), name ) : sprintf( __( 'Conversation #%d', 'wp-cortex' ), chat.id ) ) );
		els.title.appendChild( badge( chat ) );

		els.meta.innerHTML = '';
		els.meta.appendChild( document.createTextNode( sprintf( __( 'Started %1$s · last activity %2$s · %3$s', 'wp-cortex' ), fmtDate( chat.created_at ), fmtDate( chat.updated_at ), sprintf( _n( '%d visitor message', '%d visitor messages', chat.message_count, 'wp-cortex' ), chat.message_count ) ) ) );
		if ( chat.page ) {
			els.meta.appendChild( document.createTextNode( ' · ' + __( 'started on', 'wp-cortex' ) + ' ' ) );
			els.meta.appendChild( link( chat.page.title || chat.page.url, chat.page.url ) );
		}
		renderActivity( chat );

		els.transcript.innerHTML = '';
		( chat.transcript || [] ).forEach( renderItem );
		if ( ! ( chat.transcript || [] ).length ) {
			els.transcript.appendChild( el( 'p', 'wp-cortex-muted', __( 'No messages.', 'wp-cortex' ) ) );
		}

		renderContact( chat );
		renderSummary( chat );
		renderVisitedPages( chat );
		renderForwarded( chat );
		if ( ! els.forwardTo.value ) {
			els.forwardTo.value = defaultForwardTo();
		}
		els.toggleRead.textContent = chat.is_read ? __( 'Mark as unread', 'wp-cortex' ) : __( 'Mark as read', 'wp-cortex' );
	}

	function update( data, message ) {
		var wasRead = current.is_read;
		return apiFetch( { path: PATH + '/' + current.id, method: 'PATCH', data: data } ).then( function ( chat ) {
			if ( wasRead !== chat.is_read ) {
				updateMenuCount( menuCount() + ( chat.is_read ? -1 : 1 ) );
			}
			current = chat;
			renderDetail();
			if ( undefined !== data.admin_note ) {
				els.note.value = chat.admin_note || '';
			}
			if ( message ) {
				notify( message, false );
			}
		} ).catch( fail );
	}

	function openChat( id ) {
		clearNotice();
		clearImages();
		els.listView.hidden = true;
		els.detail.hidden = false;
		els.transcript.innerHTML = '';
		els.title.textContent = __( 'Loading…', 'wp-cortex' );
		els.meta.textContent = '';
		els.activity.textContent = '';
		apiFetch( { path: PATH + '/' + id } ).then( function ( chat ) {
			current = chat;
			// An update goes to the same recipients by default.
			els.forwardTo.value = chat.forwarded_to || defaultForwardTo();
			renderDetail();
			// Set only here and after saving, so other updates keep an unsaved note.
			els.note.value = chat.admin_note || '';
			window.scrollTo( 0, 0 );
			// Opening a conversation marks it as read.
			if ( ! chat.is_read ) {
				return update( { is_read: true } );
			}
		} ).catch( function ( err ) {
			fail( err );
			showList();
		} );
	}

	function showList() {
		current = null;
		clearImages();
		els.detail.hidden = true;
		els.listView.hidden = false;
		load();
	}

	function route() {
		var m = /^#chat=(\d+)$/.exec( window.location.hash );
		if ( m ) {
			openChat( parseInt( m[ 1 ], 10 ) );
		} else {
			showList();
		}
	}

	function goToList() {
		if ( window.location.hash ) {
			// Triggers route() through hashchange.
			window.location.hash = '';
		} else {
			showList();
		}
	}

	/* ---------- Refresh ---------- */

	// What a refresh must re-render for; everything else only updates the relative times.
	function signature( chat ) {
		return [ chat.updated_at, chat.seen_at, chat.chat_open, chat.activity, chat.is_read, chat.forwarded_at, chat.summary_at ].join( '|' );
	}

	function refreshDetail() {
		var id = current.id;
		apiFetch( { path: PATH + '/' + id } ).then( function ( chat ) {
			if ( ! current || current.id !== id || pending ) {
				return;
			}
			var changed = signature( chat ) !== signature( current );
			current = chat;
			if ( ! changed ) {
				renderActivity( chat );
				renderForwardWarning( chat );
				return;
			}
			var y = window.scrollY;
			renderDetail();
			window.scrollTo( 0, y );
			// New visitor messages while the conversation is open on screen are read.
			if ( ! chat.is_read ) {
				update( { is_read: true } );
			}
		} ).catch( function () {} );
	}

	// Keeps the open conversation and the list current while the tab is visible. The list
	// is not reloaded while rows are selected, so a bulk action keeps its selection.
	function refresh() {
		if ( 'hidden' === document.visibilityState || pending ) {
			return;
		}
		if ( current && ! els.detail.hidden ) {
			refreshDetail();
		} else if ( ! els.listView.hidden && Date.now() - listLoadedAt >= LIST_REFRESH_INTERVAL && ! selectedIds().length ) {
			load();
		}
	}

	/* ---------- Events ---------- */

	els.search.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		state.search = els.searchInput.value.trim();
		state.page = 1;
		load();
	} );
	els.selectAll.addEventListener( 'change', function () {
		Array.prototype.forEach.call( els.list.querySelectorAll( 'input[type="checkbox"]' ), function ( box ) {
			box.checked = els.selectAll.checked;
		} );
	} );
	els.bulkApply.addEventListener( 'click', applyBulk );
	els.back.addEventListener( 'click', function ( e ) {
		e.preventDefault();
		goToList();
	} );
	els.noteForm.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		update( { admin_note: els.note.value }, __( 'Note saved.', 'wp-cortex' ) );
	} );
	els.summarize.addEventListener( 'click', summarize );
	els.forwardSummarize.addEventListener( 'change', renderForwardHint );
	els.forwardForm.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		forward();
	} );
	els.toggleRead.addEventListener( 'click', function () {
		update( { is_read: ! current.is_read } );
	} );
	els.del.addEventListener( 'click', function () {
		// eslint-disable-next-line no-alert
		if ( ! current || ! window.confirm( __( 'Delete this conversation? This cannot be undone.', 'wp-cortex' ) ) ) {
			return;
		}
		apiFetch( { path: PATH + '/' + current.id, method: 'DELETE' } ).then( function () {
			goToList();
			notify( __( 'Conversation deleted.', 'wp-cortex' ), false );
		} ).catch( fail );
	} );
	window.addEventListener( 'hashchange', route );
	window.setInterval( refresh, REFRESH_INTERVAL );
	document.addEventListener( 'visibilitychange', refresh );

	route();
}() );
