/**
 * Cortex visitor chat: answers from the public index only.
 *
 * Conversations are kept in the browser session; the previous text turns are sent with
 * every message. When the site allows images, visitors can paste a screenshot, drop an
 * image on the chat or pick one; it is scaled down in the browser, sent with the next
 * message only and kept in the session as a small preview. A random session token
 * identifies the conversation in the server log (when the site keeps one). While the
 * window is open and the tab visible, the widget pings the presence endpoint every minute
 * (and once when the window is closed), so the site can tell whether the visitor is still
 * in the conversation. Requests carry no cookies or nonce, so they are always anonymous
 * and work on cached pages.
 */
( function () {
	'use strict';

	var cfg = window.wpCortexPublicChat || {};
	var __ = wp.i18n.__;
	var STORE_KEY = 'wpCortexPublicChat';
	var MAX_STORED = 40;
	var MAX_HISTORY = 12;
	var NAVIGATE_DELAY = 1200;
	var PRESENCE_INTERVAL = 60000;
	var THUMB_SIDE = 320;
	var MAX_FILE = 25 * 1024 * 1024;
	var IMAGE_TYPES = /^image\/(png|jpeg|webp|gif)$/;
	var VISITOR_ERRORS = [ 'wp_cortex_rate_limited', 'wp_cortex_invalid_image', 'wp_cortex_images_disabled' ];
	var P = 'wp-cortex-pchat-';
	var SVG_NS = 'http://www.w3.org/2000/svg';
	var ICONS = {
		chat: 'M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 4v-4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z',
		plus: 'M12 5v14M5 12h14',
		close: 'M6 6l12 12M18 6L6 18',
		attach: 'M21.4 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48'
	};
	var root, toggle, panel, list, input, sendBtn, attachBtn, fileInput, attachBox, thinkingEl;
	var state = { open: false, items: [], session: '' };
	var busy = false;
	// Image waiting to be sent: { data: full data URL, thumb: preview data URL }.
	var pending = null;
	var presenceTimer = null;

	function loadState() {
		try {
			var s = JSON.parse( window.sessionStorage.getItem( STORE_KEY ) || '{}' );
			state.open = !! s.open;
			state.items = Array.isArray( s.items ) ? s.items : [];
			state.session = 'string' === typeof s.session && /^[a-f0-9]{32}$/.test( s.session ) ? s.session : '';
		} catch ( e ) {}
		if ( ! state.session ) {
			state.session = newSession();
		}
	}

	// 128 random bits as 32 hex characters.
	function newSession() {
		var bytes = new Uint8Array( 16 );
		if ( window.crypto && window.crypto.getRandomValues ) {
			window.crypto.getRandomValues( bytes );
		} else {
			for ( var i = 0; i < bytes.length; i++ ) {
				bytes[ i ] = Math.floor( Math.random() * 256 );
			}
		}
		return Array.prototype.map.call( bytes, function ( b ) {
			return ( b < 16 ? '0' : '' ) + b.toString( 16 );
		} ).join( '' );
	}

	function saveState() {
		state.items = state.items.slice( -MAX_STORED );
		try {
			window.sessionStorage.setItem( STORE_KEY, JSON.stringify( state ) );
		} catch ( e ) {
			// Over the storage quota: drop the image previews, keep the conversation.
			state.items.forEach( function ( item ) {
				if ( item && item.image ) {
					item.image = true;
				}
			} );
			try {
				window.sessionStorage.setItem( STORE_KEY, JSON.stringify( state ) );
			} catch ( e2 ) {}
		}
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

	function isPreview( value ) {
		return 'string' === typeof value && /^data:image\/(jpeg|png);base64,[A-Za-z0-9+\/=]+$/.test( value );
	}

	// Visitor message: the attached image (or a placeholder when its preview was dropped) and the text.
	function addUserMessage( item ) {
		var m = el( 'div', P + 'msg ' + P + 'msg-user' );
		if ( isPreview( item.image ) ) {
			var img = el( 'img', P + 'msg-image' );
			img.src = item.image;
			img.alt = __( 'Attached image', 'wp-cortex' );
			m.appendChild( img );
		} else if ( item.image ) {
			m.appendChild( el( 'span', P + 'msg-image-missing', __( 'Image', 'wp-cortex' ) ) );
		}
		if ( item.text ) {
			m.appendChild( el( 'span', P + 'msg-text', item.text ) );
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

	function addNote( text, linkText, url ) {
		var n = el( 'div', P + 'note', text );
		if ( url && isHttpUrl( url ) ) {
			n.appendChild( document.createTextNode( ' ' ) );
			var a = el( 'a', '', linkText );
			a.href = url;
			n.appendChild( a );
		}
		list.appendChild( n );
	}

	function renderItem( item ) {
		if ( ! item ) {
			return;
		}
		switch ( item.role ) {
			case 'navigate':
				addNote( '→', item.title || item.url, item.url );
				break;

			case 'user':
				addUserMessage( item );
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
		if ( attachBtn ) {
			attachBtn.disabled = on;
		}
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

	/* ---------- Images ---------- */

	function showError( text ) {
		addMessage( 'error', text, false );
		scrollBottom();
	}

	// Draws the image scaled to fit the given side; JPEG gets a white background.
	function encode( img, side, png, quality ) {
		var scale = Math.min( 1, side / Math.max( img.naturalWidth, img.naturalHeight ) );
		var canvas = document.createElement( 'canvas' );
		canvas.width = Math.max( 1, Math.round( img.naturalWidth * scale ) );
		canvas.height = Math.max( 1, Math.round( img.naturalHeight * scale ) );
		var ctx = canvas.getContext( '2d' );
		if ( ! png ) {
			ctx.fillStyle = '#fff';
			ctx.fillRect( 0, 0, canvas.width, canvas.height );
		}
		ctx.drawImage( img, 0, 0, canvas.width, canvas.height );
		return canvas.toDataURL( png ? 'image/png' : 'image/jpeg', quality );
	}

	// Scales the image down in the browser: PNG (sharp screenshots) unless it is too big, else JPEG.
	function attachImage( file ) {
		if ( busy || ! file ) {
			return;
		}
		if ( ! IMAGE_TYPES.test( file.type ) ) {
			showError( __( 'Please use a PNG, JPEG, WebP or GIF image.', 'wp-cortex' ) );
			return;
		}
		if ( file.size > MAX_FILE ) {
			showError( __( 'The image is too large. Please send a smaller image.', 'wp-cortex' ) );
			return;
		}
		var maxLength = Math.floor( ( cfg.imageMax || 4194304 ) * 4 / 3 );
		var url = URL.createObjectURL( file );
		var img = new Image();
		img.onload = function () {
			URL.revokeObjectURL( url );
			var side = cfg.imageSide || 1600;
			var png = 'image/png' === file.type;
			var data = encode( img, side, png, 0.85 );
			if ( png && data.length > maxLength / 2 ) {
				data = encode( img, side, false, 0.85 );
			}
			if ( data.length > maxLength ) {
				showError( __( 'The image is too large. Please send a smaller image.', 'wp-cortex' ) );
				return;
			}
			setPending( { data: data, thumb: encode( img, THUMB_SIDE, false, 0.7 ) } );
			input.focus();
		};
		img.onerror = function () {
			URL.revokeObjectURL( url );
			showError( __( 'The image could not be read. Please use a PNG, JPEG, WebP or GIF image.', 'wp-cortex' ) );
		};
		img.src = url;
	}

	function setPending( image ) {
		pending = image;
		if ( ! attachBox ) {
			return;
		}
		attachBox.innerHTML = '';
		attachBox.hidden = ! image;
		if ( image ) {
			var img = el( 'img' );
			img.src = image.thumb;
			img.alt = __( 'Image to send', 'wp-cortex' );
			attachBox.appendChild( img );
			var remove = el( 'button', P + 'attachment-remove' );
			remove.type = 'button';
			remove.setAttribute( 'aria-label', __( 'Remove image', 'wp-cortex' ) );
			remove.title = __( 'Remove image', 'wp-cortex' );
			remove.appendChild( icon( 'close' ) );
			remove.addEventListener( 'click', function () {
				setPending( null );
				input.focus();
			} );
			attachBox.appendChild( remove );
		}
	}

	function onPaste( e ) {
		var data = e.clipboardData;
		var items = ( data && data.items ) || [];
		for ( var i = 0; i < items.length; i++ ) {
			if ( 'file' === items[ i ].kind && 0 === items[ i ].type.indexOf( 'image/' ) ) {
				var file = items[ i ].getAsFile();
				if ( file ) {
					// Text copied together with an image (for example from a document) is still pasted.
					if ( ! data.getData( 'text/plain' ) ) {
						e.preventDefault();
					}
					attachImage( file );
					return;
				}
			}
		}
	}

	function hasFiles( e ) {
		return !! ( e.dataTransfer && -1 !== Array.prototype.indexOf.call( e.dataTransfer.types || [], 'Files' ) );
	}

	function enableImages( form ) {
		attachBox = el( 'div', P + 'attachment' );
		attachBox.hidden = true;
		panel.insertBefore( attachBox, form );

		fileInput = el( 'input' );
		fileInput.type = 'file';
		fileInput.accept = 'image/png,image/jpeg,image/webp,image/gif';
		fileInput.hidden = true;
		fileInput.addEventListener( 'change', function () {
			attachImage( fileInput.files && fileInput.files[ 0 ] );
			fileInput.value = '';
		} );

		attachBtn = el( 'button', P + 'attach' );
		attachBtn.type = 'button';
		attachBtn.setAttribute( 'aria-label', __( 'Attach an image', 'wp-cortex' ) );
		attachBtn.title = __( 'Attach an image (you can also paste a screenshot)', 'wp-cortex' );
		attachBtn.appendChild( icon( 'attach' ) );
		attachBtn.addEventListener( 'click', function () {
			fileInput.click();
		} );
		form.insertBefore( attachBtn, form.firstChild );
		form.appendChild( fileInput );

		input.placeholder = __( 'Ask or paste a screenshot…', 'wp-cortex' );
		input.addEventListener( 'paste', onPaste );

		panel.addEventListener( 'dragover', function ( e ) {
			if ( hasFiles( e ) ) {
				e.preventDefault();
				panel.classList.add( 'is-dragging' );
			}
		} );
		panel.addEventListener( 'dragleave', function ( e ) {
			if ( ! panel.contains( e.relatedTarget ) ) {
				panel.classList.remove( 'is-dragging' );
			}
		} );
		panel.addEventListener( 'drop', function ( e ) {
			if ( hasFiles( e ) ) {
				e.preventDefault();
				panel.classList.remove( 'is-dragging' );
				attachImage( e.dataTransfer.files[ 0 ] );
			}
		} );
	}

	/* ---------- Sending ---------- */

	// Previous user and assistant turns: text, and whether a visitor message had an image.
	function history() {
		return state.items.filter( function ( item ) {
			return item && ( 'user' === item.role || 'assistant' === item.role ) && ( item.text || ( 'user' === item.role && item.image ) );
		} ).slice( -MAX_HISTORY ).map( function ( item ) {
			var turn = { role: item.role, text: item.text || '' };
			if ( 'user' === item.role && item.image ) {
				turn.image = true;
			}
			return turn;
		} );
	}

	function addItem( item ) {
		state.items.push( item );
		renderItem( item );
	}

	function send() {
		var text = input.value.trim();
		var image = pending;
		if ( busy || ( ! text && ! image ) ) {
			return;
		}
		var previous = history();
		var item = { role: 'user', text: text };
		if ( image ) {
			item.image = image.thumb;
		}
		input.value = '';
		setPending( null );
		addItem( item );
		saveState();
		setBusy( true );

		window.fetch( cfg.endpoint, {
			method: 'POST',
			credentials: 'omit',
			headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
			body: JSON.stringify( { message: text, image: image ? image.data : '', history: previous, post_id: cfg.postId || 0, session: state.session, page_url: window.location.href.split( '#' )[ 0 ] } )
		} ).then( function ( res ) {
			return res.json().catch( function () {
				return {};
			} ).then( function ( body ) {
				if ( ! res.ok ) {
					// Only the rate limit and image messages are meant for visitors.
					throw new Error( body && body.message && -1 !== VISITOR_ERRORS.indexOf( body.code ) ? body.message : '' );
				}
				return body;
			} );
		} ).then( function ( body ) {
			setBusy( false );
			var target = null;
			( body.items || [] ).forEach( function ( item ) {
				if ( item && 'user' !== item.role ) {
					addItem( item );
					if ( 'navigate' === item.role && item.url && isHttpUrl( item.url ) ) {
						target = item.url;
					}
				}
			} );
			saveState();
			scrollBottom();
			if ( target ) {
				navigate( target );
			}
		} ).catch( function ( err ) {
			setBusy( false );
			// Errors are shown but not kept, so a reload starts clean.
			addMessage( 'error', ( err && err.message ) || __( 'Sorry, something went wrong. Please try again.', 'wp-cortex' ), false );
			scrollBottom();
		} );
	}

	// Opens a page the visitor asked for; the chat state (open, history) survives the load.
	function navigate( url ) {
		var to = new URL( url, window.location.href );
		var here = window.location;
		if ( to.origin === here.origin && to.pathname === here.pathname && to.search === here.search ) {
			return;
		}
		busy = true;
		input.disabled = true;
		sendBtn.disabled = true;
		if ( attachBtn ) {
			attachBtn.disabled = true;
		}
		window.setTimeout( function () {
			window.location.assign( to.href );
		}, NAVIGATE_DELAY );
	}

	/* ---------- Presence ---------- */

	// Tells the server whether the chat window is open. Only conversations with a visitor
	// message are stored, so there is nothing to report before the first one.
	function ping( open, session ) {
		if ( ! cfg.presence || ! state.items.some( function ( item ) {
			return item && 'user' === item.role;
		} ) ) {
			return;
		}
		window.fetch( cfg.presence, {
			method: 'POST',
			credentials: 'omit',
			keepalive: true,
			headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
			body: JSON.stringify( { session: session || state.session, open: open } )
		} ).catch( function () {} );
	}

	// Pings while the window is open and the tab is visible; a hidden tab simply stops.
	function updatePresence() {
		var active = state.open && 'hidden' !== document.visibilityState;
		if ( active && ! presenceTimer ) {
			ping( true );
			presenceTimer = window.setInterval( function () {
				ping( true );
			}, PRESENCE_INTERVAL );
		} else if ( ! active && presenceTimer ) {
			window.clearInterval( presenceTimer );
			presenceTimer = null;
		}
	}

	/* ---------- Open / close ---------- */

	function openPanel( focus ) {
		state.open = true;
		saveState();
		panel.classList.add( 'is-open' );
		toggle.setAttribute( 'aria-expanded', 'true' );
		updatePresence();
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
		updatePresence();
		ping( false );
		toggle.focus();
	}

	function newChat() {
		if ( busy ) {
			return;
		}
		// The previous conversation is over: report its window as closed.
		ping( false );
		state.items = [];
		state.session = newSession();
		setPending( null );
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
		if ( cfg.label ) {
			toggle.classList.add( 'has-label' );
			toggle.appendChild( el( 'span', P + 'toggle-label', cfg.label ) );
		}
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
		if ( cfg.images && window.URL && URL.createObjectURL ) {
			enableImages( form );
		}
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
		document.addEventListener( 'visibilitychange', updatePresence );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', build );
	} else {
		build();
	}
}() );
