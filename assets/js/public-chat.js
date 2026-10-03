/**
 * Cortex visitor chat: answers from the public index only.
 *
 * Conversations are kept in the browser session (not on the server); the previous text
 * turns are sent with every message. Requests carry no cookies or nonce, so they are
 * always anonymous and work on cached pages.
 */
( function () {
	'use strict';

	var cfg = window.wpCortexPublicChat || {};
	var __ = wp.i18n.__;
	var STORE_KEY = 'wpCortexPublicChat';
	var MAX_STORED = 40;
	var MAX_HISTORY = 12;
	var P = 'wp-cortex-pchat-';
	var SVG_NS = 'http://www.w3.org/2000/svg';
	var ICONS = {
		chat: 'M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 4v-4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z',
		plus: 'M12 5v14M5 12h14',
		close: 'M6 6l12 12M18 6L6 18'
	};
	var root, toggle, panel, list, input, sendBtn, thinkingEl;
	var state = { open: false, items: [] };
	var busy = false;

	function loadState() {
		try {
			var s = JSON.parse( window.sessionStorage.getItem( STORE_KEY ) || '{}' );
			state.open = !! s.open;
			state.items = Array.isArray( s.items ) ? s.items : [];
		} catch ( e ) {}
	}

	function saveState() {
		state.items = state.items.slice( -MAX_STORED );
		try {
			window.sessionStorage.setItem( STORE_KEY, JSON.stringify( state ) );
		} catch ( e ) {}
	}

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

	function icon( name ) {
		var svg = document.createElementNS( SVG_NS, 'svg' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );
		var path = document.createElementNS( SVG_NS, 'path' );
		path.setAttribute( 'd', ICONS[ name ] );
		svg.appendChild( path );
		return svg;
	}

	function iconButton( name, label, onClick ) {
		var b = el( 'button', P + 'iconbtn' );
		b.type = 'button';
		b.setAttribute( 'aria-label', label );
		b.title = label;
		b.appendChild( icon( name ) );
		b.addEventListener( 'click', onClick );
		return b;
	}

	/* ---------- Rendering ---------- */

	function scrollBottom() {
		list.scrollTop = list.scrollHeight;
	}

	function addMessage( cls, text, markdown ) {
		var m = el( 'div', P + 'msg ' + P + 'msg-' + cls );
		if ( markdown ) {
			m.innerHTML = window.wpCortexMarkdown.render( text );
		} else {
			m.textContent = text;
		}
		list.appendChild( m );
		return m;
	}

	function isHttpUrl( url ) {
		try {
			var u = new URL( url, window.location.href );
			return 'http:' === u.protocol || 'https:' === u.protocol;
		} catch ( e ) {
			return false;
		}
	}

	function addSources( sources ) {
		var wrap = el( 'div', P + 'sources' );
		wrap.appendChild( el( 'p', P + 'sources-title', __( 'Sources', 'wp-cortex' ) ) );
		var ul = el( 'ul' );
		( sources || [] ).forEach( function ( s ) {
			if ( ! s || ! s.url || ! isHttpUrl( s.url ) ) {
				return;
			}
			var li = el( 'li' );
			var a = el( 'a', P + 'source-link', s.title || s.url );
			a.href = s.url;
			li.appendChild( a );
			if ( s.snippet ) {
				li.appendChild( el( 'p', P + 'snippet', s.snippet ) );
			}
			ul.appendChild( li );
		} );
		wrap.appendChild( ul );
		list.appendChild( wrap );
	}

	function renderItem( item ) {
		if ( ! item ) {
			return;
		}
		switch ( item.role ) {
			case 'user':
				addMessage( 'user', item.text || '', false );
				break;
			case 'assistant':
				addMessage( 'assistant', item.text || '', true );
				break;
			case 'sources':
				addSources( item.sources );
				break;
			case 'error':
				addMessage( 'error', item.text || '', false );
				break;
		}
	}

	function render() {
		list.innerHTML = '';
		addMessage( 'assistant', cfg.welcome || '', false );
		state.items.forEach( renderItem );
		scrollBottom();
	}

	function setBusy( on ) {
		busy = on;
		input.disabled = on;
		sendBtn.disabled = on;
		if ( on ) {
			thinkingEl = el( 'div', P + 'thinking' );
			thinkingEl.setAttribute( 'aria-label', __( 'Thinking…', 'wp-cortex' ) );
			thinkingEl.appendChild( el( 'span' ) );
			thinkingEl.appendChild( el( 'span' ) );
			thinkingEl.appendChild( el( 'span' ) );
			list.appendChild( thinkingEl );
			scrollBottom();
		} else {
			if ( thinkingEl && thinkingEl.parentNode ) {
				thinkingEl.parentNode.removeChild( thinkingEl );
			}
			thinkingEl = null;
			input.focus();
		}
	}

	/* ---------- Sending ---------- */

	// Previous user and assistant turns, text only.
	function history() {
		return state.items.filter( function ( item ) {
			return item && ( 'user' === item.role || 'assistant' === item.role ) && item.text;
		} ).slice( -MAX_HISTORY ).map( function ( item ) {
			return { role: item.role, text: item.text };
		} );
	}

	function addItem( item ) {
		state.items.push( item );
		renderItem( item );
	}

	function send() {
		var text = input.value.trim();
		if ( busy || ! text ) {
			return;
		}
		var previous = history();
		input.value = '';
		addItem( { role: 'user', text: text } );
		saveState();
		setBusy( true );

		window.fetch( cfg.endpoint, {
			method: 'POST',
			credentials: 'omit',
			headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
			body: JSON.stringify( { message: text, history: previous, post_id: cfg.postId || 0 } )
		} ).then( function ( res ) {
			return res.json().catch( function () {
				return {};
			} ).then( function ( body ) {
				if ( ! res.ok ) {
					// Only the rate limit message is meant for visitors.
					throw new Error( 429 === res.status && body && body.message ? body.message : '' );
				}
				return body;
			} );
		} ).then( function ( body ) {
			setBusy( false );
			( body.items || [] ).forEach( function ( item ) {
				if ( item && 'user' !== item.role ) {
					addItem( item );
				}
			} );
			saveState();
			scrollBottom();
		} ).catch( function ( err ) {
			setBusy( false );
			// Errors are shown but not kept, so a reload starts clean.
			addMessage( 'error', ( err && err.message ) || __( 'Sorry, something went wrong. Please try again.', 'wp-cortex' ), false );
			scrollBottom();
		} );
	}

	/* ---------- Open / close ---------- */

	function openPanel( focus ) {
		state.open = true;
		saveState();
		panel.classList.add( 'is-open' );
		toggle.setAttribute( 'aria-expanded', 'true' );
		scrollBottom();
		if ( focus ) {
			input.focus();
		}
	}

	function closePanel() {
		state.open = false;
		saveState();
		panel.classList.remove( 'is-open' );
		toggle.setAttribute( 'aria-expanded', 'false' );
		toggle.focus();
	}

	function newChat() {
		if ( busy ) {
			return;
		}
		state.items = [];
		saveState();
		render();
		input.focus();
	}

	/* ---------- Build UI ---------- */

	function build() {
		root = document.getElementById( 'wp-cortex-public-chat-root' );
		if ( ! root || ! cfg.endpoint || ! window.fetch ) {
			return;
		}

		toggle = el( 'button', P + 'toggle' );
		toggle.type = 'button';
		toggle.setAttribute( 'aria-label', cfg.title || __( 'Chat', 'wp-cortex' ) );
		toggle.setAttribute( 'aria-expanded', 'false' );
		toggle.appendChild( icon( 'chat' ) );
		toggle.addEventListener( 'click', function () {
			if ( panel.classList.contains( 'is-open' ) ) {
				closePanel();
			} else {
				openPanel( true );
			}
		} );

		panel = el( 'div', P + 'panel' );
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-label', cfg.title || __( 'Chat', 'wp-cortex' ) );

		var header = el( 'div', P + 'header' );
		header.appendChild( el( 'h2', P + 'title', cfg.title || '' ) );
		header.appendChild( iconButton( 'plus', __( 'New conversation', 'wp-cortex' ), newChat ) );
		header.appendChild( iconButton( 'close', __( 'Close', 'wp-cortex' ), closePanel ) );

		list = el( 'div', P + 'messages' );
		list.setAttribute( 'aria-live', 'polite' );

		var form = el( 'form', P + 'form' );
		input = el( 'textarea', P + 'input' );
		input.rows = 1;
		input.maxLength = cfg.maxLength || 2000;
		input.placeholder = __( 'Type your question…', 'wp-cortex' );
		input.setAttribute( 'aria-label', __( 'Message', 'wp-cortex' ) );
		sendBtn = el( 'button', P + 'send', __( 'Send', 'wp-cortex' ) );
		sendBtn.type = 'submit';
		form.appendChild( input );
		form.appendChild( sendBtn );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			send();
		} );
		input.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key && ! e.shiftKey && ! e.isComposing ) {
				e.preventDefault();
				send();
			}
		} );

		panel.appendChild( header );
		panel.appendChild( list );
		panel.appendChild( form );
		panel.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				closePanel();
			}
		} );

		root.appendChild( toggle );
		root.appendChild( panel );

		loadState();
		render();
		if ( state.open ) {
			// Restored after navigation: do not move the focus away from the page.
			openPanel( false );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', build );
	} else {
		build();
	}
}() );
