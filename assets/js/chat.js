/**
 * Cortex chat panel for administrators (admin screens and, optionally, the front end).
 */
( function () {
	'use strict';

	var cfg = window.wpCortexChat || {};
	var __ = wp.i18n.__;
	var STORE_KEY = 'wpCortexChat';
	var TAB_KEY = 'wpCortexChatTab';
	var SIZE_KEY = 'wpCortexChatSize';
	var MAX_TABS = 50;
	var DRAG_THRESHOLD = 4;
	var PANEL_GAP = 12;
	var MIN_WIDTH = 320;
	var MIN_HEIGHT = 320;
	var MOBILE_WIDTH = 600;
	var P = 'wp-cortex-chat-';
	var root, toggle, panel, resizeHandle, list, select, input, sendBtn, thinkingEl;
	var state = { open: false, conversationId: 0, position: null };
	var busy = false;
	var listLoaded = false;
	var dragState = null;
	var resizeState = null;
	var panelSize = null;
	var ignoreNextToggle = false;

	function loadState() {
		try {
			var s = JSON.parse( window.sessionStorage.getItem( STORE_KEY ) || '{}' );
			state.open = !! s.open;
			state.conversationId = parseInt( s.conversationId, 10 ) || 0;
			state.position = normalizePosition( s.position );
		} catch ( e ) {}
	}

	function saveState() {
		try {
			window.sessionStorage.setItem( STORE_KEY, JSON.stringify( state ) );
		} catch ( e ) {}
	}

	// The panel size is kept across sessions, unlike the open state and the position.
	function loadSize() {
		try {
			var s = JSON.parse( window.localStorage.getItem( SIZE_KEY ) || 'null' );
			var width = s ? parseFloat( s.width ) : NaN;
			var height = s ? parseFloat( s.height ) : NaN;
			panelSize = isFinite( width ) && isFinite( height ) ? { width: width, height: height } : null;
		} catch ( e ) {}
	}

	function saveSize() {
		try {
			if ( panelSize ) {
				window.localStorage.setItem( SIZE_KEY, JSON.stringify( panelSize ) );
			} else {
				window.localStorage.removeItem( SIZE_KEY );
			}
		} catch ( e ) {}
	}

	function normalizePosition( position ) {
		if ( ! position ) {
			return null;
		}

		var left = parseFloat( position.left );
		var top = parseFloat( position.top );
		if ( ! isFinite( left ) || ! isFinite( top ) ) {
			return null;
		}

		return { left: left, top: top };
	}

	function viewportSize() {
		return {
			width: window.innerWidth || document.documentElement.clientWidth || 0,
			height: window.innerHeight || document.documentElement.clientHeight || 0,
		};
	}

	function toggleSize() {
		return {
			width: toggle ? toggle.offsetWidth : 52,
			height: toggle ? toggle.offsetHeight : 52,
		};
	}

	function clampPosition( position ) {
		var viewport = viewportSize();
		var size = toggleSize();
		var maxLeft = Math.max( 0, viewport.width - size.width );
		var maxTop = Math.max( 0, viewport.height - size.height );

		return {
			left: Math.round( Math.max( 0, Math.min( maxLeft, position.left ) ) ),
			top: Math.round( Math.max( 0, Math.min( maxTop, position.top ) ) ),
		};
	}

	function defaultPosition() {
		var viewport = viewportSize();
		var size = toggleSize();
		var margin = viewport.width <= 600 ? 12 : 20;

		return clampPosition( {
			left: viewport.width - size.width - margin,
			top: viewport.height - size.height - margin,
		} );
	}

	function setTogglePosition( position ) {
		toggle.style.left = position.left + 'px';
		toggle.style.top = position.top + 'px';
		toggle.style.right = 'auto';
		toggle.style.bottom = 'auto';
	}

	function currentTogglePosition() {
		var rect = toggle.getBoundingClientRect();

		return clampPosition( { left: rect.left, top: rect.top } );
	}

	function isMobile() {
		return viewportSize().width <= MOBILE_WIDTH;
	}

	// Keep a size between the minimum and what fits in the viewport above or below the toggle.
	function clampSize( size ) {
		var viewport = viewportSize();
		var toggleRect = toggle.getBoundingClientRect();
		var room = Math.max( toggleRect.top, viewport.height - toggleRect.bottom ) - PANEL_GAP - 10;
		var maxWidth = Math.max( MIN_WIDTH, viewport.width - 20 );
		var maxHeight = Math.max( MIN_HEIGHT, room );

		return {
			width: Math.round( Math.max( MIN_WIDTH, Math.min( maxWidth, size.width ) ) ),
			height: Math.round( Math.max( MIN_HEIGHT, Math.min( maxHeight, size.height ) ) ),
		};
	}

	// Apply the chosen size; on small screens the stylesheet layout applies instead.
	function applySize() {
		if ( ! panel ) {
			return;
		}

		if ( ! panelSize || isMobile() ) {
			panel.style.width = '';
			panel.style.height = '';
			panel.style.maxWidth = '';
			panel.style.maxHeight = '';
			return;
		}

		var size = clampSize( panelSize );
		panel.style.width = size.width + 'px';
		panel.style.height = size.height + 'px';
		panel.style.maxWidth = 'none';
		panel.style.maxHeight = 'none';
	}

	// Put the resize handle in the panel corner away from the toggle.
	function placeResizeHandle( panelLeft, panelTop, panelRect, toggleRect ) {
		var left = panelLeft + panelRect.width / 2 < toggleRect.left + toggleRect.width / 2;
		var top = panelTop + panelRect.height / 2 < toggleRect.top + toggleRect.height / 2;

		panel.classList.toggle( P + 'handle-left', left );
		panel.classList.toggle( P + 'handle-top', top );
	}

	// Align the panel to the same horizontal side as the toggle and keep it visible.
	function positionPanel() {
		if ( ! panel || ! panel.classList.contains( 'is-open' ) ) {
			return;
		}

		var viewport = viewportSize();
		var toggleRect = toggle.getBoundingClientRect();
		var panelRect = panel.getBoundingClientRect();
		var margin = 10;
		var left = toggleRect.left;
		var top = toggleRect.top - panelRect.height - PANEL_GAP;
		var maxLeft = Math.max( margin, viewport.width - panelRect.width - margin );
		var maxTop = Math.max( margin, viewport.height - panelRect.height - margin );

		if ( left + panelRect.width > viewport.width - margin ) {
			left = toggleRect.right - panelRect.width;
		}
		left = Math.max( margin, Math.min( maxLeft, left ) );

		if ( top < margin ) {
			top = toggleRect.bottom + PANEL_GAP;
		}
		top = Math.max( margin, Math.min( maxTop, top ) );

		panel.style.left = Math.round( left ) + 'px';
		panel.style.top = Math.round( top ) + 'px';
		panel.style.right = 'auto';
		panel.style.bottom = 'auto';
		if ( ! resizeState ) {
			placeResizeHandle( left, top, panelRect, toggleRect );
		}
	}

	function applyPosition() {
		if ( ! toggle ) {
			return;
		}

		applySize();
		setTogglePosition( state.position ? clampPosition( state.position ) : defaultPosition() );
		positionPanel();
	}

	function bindToggleDrag() {
		toggle.addEventListener( 'pointerdown', function ( event ) {
			if ( false === event.isPrimary || ( 0 !== event.button && -1 !== event.button ) ) {
				return;
			}

			var rect = toggle.getBoundingClientRect();
			dragState = {
				pointerId: event.pointerId,
				startX: event.clientX,
				startY: event.clientY,
				left: rect.left,
				top: rect.top,
				moved: false,
			};
			toggle.classList.add( P + 'is-dragging' );
			if ( toggle.setPointerCapture ) {
				try {
					toggle.setPointerCapture( event.pointerId );
				} catch ( e ) {}
			}
		} );

		toggle.addEventListener( 'pointermove', function ( event ) {
			if ( ! dragState || event.pointerId !== dragState.pointerId ) {
				return;
			}

			var deltaX = event.clientX - dragState.startX;
			var deltaY = event.clientY - dragState.startY;
			if ( ! dragState.moved && Math.abs( deltaX ) < DRAG_THRESHOLD && Math.abs( deltaY ) < DRAG_THRESHOLD ) {
				return;
			}

			dragState.moved = true;
			setTogglePosition( clampPosition( {
				left: dragState.left + deltaX,
				top: dragState.top + deltaY,
			} ) );
			applySize();
			positionPanel();
			event.preventDefault();
		} );

		function endDrag( event ) {
			if ( ! dragState || event.pointerId !== dragState.pointerId ) {
				return;
			}

			var moved = dragState.moved;
			dragState = null;
			toggle.classList.remove( P + 'is-dragging' );

			if ( moved ) {
				state.position = currentTogglePosition();
				saveState();
				ignoreNextToggle = true;
				window.setTimeout( function () {
					ignoreNextToggle = false;
				}, 0 );
				event.preventDefault();
			}
		}

		toggle.addEventListener( 'pointerup', endDrag );
		toggle.addEventListener( 'pointercancel', endDrag );
	}

	function bindPanelResize() {
		resizeHandle.addEventListener( 'pointerdown', function ( event ) {
			if ( false === event.isPrimary || ( 0 !== event.button && -1 !== event.button ) ) {
				return;
			}

			var rect = panel.getBoundingClientRect();
			resizeState = {
				pointerId: event.pointerId,
				startX: event.clientX,
				startY: event.clientY,
				width: rect.width,
				height: rect.height,
				left: panel.classList.contains( P + 'handle-left' ),
				top: panel.classList.contains( P + 'handle-top' ),
			};
			panel.classList.add( P + 'is-resizing' );
			if ( resizeHandle.setPointerCapture ) {
				try {
					resizeHandle.setPointerCapture( event.pointerId );
				} catch ( e ) {}
			}
			event.preventDefault();
		} );

		resizeHandle.addEventListener( 'pointermove', function ( event ) {
			if ( ! resizeState || event.pointerId !== resizeState.pointerId ) {
				return;
			}

			var deltaX = event.clientX - resizeState.startX;
			var deltaY = event.clientY - resizeState.startY;
			panelSize = clampSize( {
				width: resizeState.width + ( resizeState.left ? -deltaX : deltaX ),
				height: resizeState.height + ( resizeState.top ? -deltaY : deltaY ),
			} );
			applySize();
			positionPanel();
			event.preventDefault();
		} );

		function endResize( event ) {
			if ( ! resizeState || event.pointerId !== resizeState.pointerId ) {
				return;
			}

			resizeState = null;
			panel.classList.remove( P + 'is-resizing' );
			saveSize();
			positionPanel();
		}

		resizeHandle.addEventListener( 'pointerup', endResize );
		resizeHandle.addEventListener( 'pointercancel', endResize );

		// Double click restores the default size.
		resizeHandle.addEventListener( 'dblclick', function () {
			panelSize = null;
			saveSize();
			applySize();
			positionPanel();
		} );
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

	function iconButton( icon, label, onClick ) {
		var b = el( 'button', P + 'iconbtn' );
		b.type = 'button';
		b.setAttribute( 'aria-label', label );
		b.title = label;
		var i = el( 'span', 'dashicons dashicons-' + icon );
		i.setAttribute( 'aria-hidden', 'true' );
		b.appendChild( i );
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

	function addResults( results ) {
		var wrap = el( 'div', P + 'results' );
		( results || [] ).forEach( function ( r ) {
			var card = el( 'div', P + 'card' );
			card.appendChild( el( 'p', P + 'card-title', r.title || __( '(no title)', 'wp-cortex' ) ) );
			var badges = el( 'div', P + 'badges' );
			if ( r.post_type ) {
				badges.appendChild( el( 'span', P + 'badge', r.post_type ) );
			}
			if ( r.status ) {
				badges.appendChild( el( 'span', P + 'badge ' + P + 'badge-' + String( r.status ).replace( /[^a-z0-9_-]/gi, '' ), r.status ) );
			}
			if ( r.author ) {
				badges.appendChild( el( 'span', P + 'author', r.author ) );
			}
			card.appendChild( badges );
			if ( r.snippet ) {
				card.appendChild( el( 'p', P + 'snippet', r.snippet ) );
			}
			var actions = el( 'div', P + 'card-actions' );
			if ( r.edit_url ) {
				var e = el( 'a', 'button button-small', __( 'Edit', 'wp-cortex' ) );
				e.href = r.edit_url;
				actions.appendChild( e );
			}
			if ( r.url && 'publish' === r.status ) {
				var v = el( 'a', 'button button-small', __( 'View', 'wp-cortex' ) );
				v.href = r.url;
				actions.appendChild( v );
			}
			card.appendChild( actions );
			wrap.appendChild( card );
		} );
		list.appendChild( wrap );
	}

	function findSkillProposalCard( proposalId ) {
		if ( ! proposalId ) {
			return null;
		}
		var cards = list.querySelectorAll( '.' + P + 'skill' );
		for ( var i = 0; i < cards.length; i++ ) {
			if ( cards[ i ].getAttribute( 'data-proposal-id' ) === proposalId ) {
				return cards[ i ];
			}
		}
		return null;
	}

	function placeSkillProposalCard( card ) {
		var existing = findSkillProposalCard( card.getAttribute( 'data-proposal-id' ) );
		if ( existing && existing.parentNode ) {
			existing.parentNode.removeChild( existing );
		}
		list.appendChild( card );
	}

	function resolveSkillProposal( proposalId, status, skillId ) {
		if ( ! proposalId || ! state.conversationId ) {
			return Promise.resolve();
		}
		return wp.apiFetch( {
			path: '/wp-cortex/v1/chat/conversations/' + state.conversationId + '/skill-proposals/' + encodeURIComponent( proposalId ),
			method: 'POST',
			data: { status: status, skill_id: skillId || 0 }
		} );
	}

	function addResolvedSkillProposal( skill ) {
		var status = String( skill.status || '' );
		var dismissed = 'dismissed' === status;
		var card = el( 'div', P + 'card ' + P + 'skill ' + P + 'skill-resolved' );
		var proposalId = String( skill.proposal_id || '' );
		if ( proposalId ) {
			card.setAttribute( 'data-proposal-id', proposalId );
		}
		card.appendChild( el( 'p', P + 'card-title', dismissed ? __( 'Skill proposal dismissed.', 'wp-cortex' ) : ( skill.legacy ? __( 'Skill already saved.', 'wp-cortex' ) : __( 'Skill saved.', 'wp-cortex' ) ) ) );

		if ( cfg.skillsUrl && ! dismissed ) {
			var link = el( 'a', '', __( 'Manage skills', 'wp-cortex' ) );
			link.href = cfg.skillsUrl;
			card.appendChild( link );
		}

		placeSkillProposalCard( card );
	}

	function addSkillProposal( skill ) {
		if ( ! cfg.skills || ! skill || ! skill.name ) {
			return;
		}
		if ( 'saved' === skill.status || 'dismissed' === skill.status ) {
			addResolvedSkillProposal( skill );
			return;
		}

		var existingId = parseInt( skill.existing_id, 10 ) || 0;
		var proposalId = String( skill.proposal_id || '' );
		var card = el( 'form', P + 'card ' + P + 'skill' );
		if ( proposalId ) {
			card.setAttribute( 'data-proposal-id', proposalId );
		}
		card.appendChild( el( 'p', P + 'card-title', existingId ? __( 'Update this skill?', 'wp-cortex' ) : __( 'Save as a skill?', 'wp-cortex' ) ) );

		function field( label, control ) {
			var l = el( 'label', P + 'skill-field' );
			l.appendChild( el( 'span', P + 'skill-label', label ) );
			l.appendChild( control );
			card.appendChild( l );
			return control;
		}

		var name = field( __( 'Name', 'wp-cortex' ), el( 'input', P + 'skill-input' ) );
		name.type = 'text';
		name.value = skill.name;
		var desc = field( __( 'When to use', 'wp-cortex' ), el( 'input', P + 'skill-input' ) );
		desc.type = 'text';
		desc.value = skill.description || '';
		var steps = field( __( 'Instructions', 'wp-cortex' ), el( 'textarea', P + 'skill-input' ) );
		steps.rows = 5;
		steps.value = skill.instructions || '';

		var statusEl = el( 'p', P + 'skill-status' );
		var actions = el( 'div', P + 'card-actions' );
		var save = el( 'button', 'button button-small button-primary', existingId ? __( 'Update skill', 'wp-cortex' ) : __( 'Save skill', 'wp-cortex' ) );
		save.type = 'submit';
		var dismiss = el( 'button', 'button button-small', __( 'Dismiss', 'wp-cortex' ) );
		dismiss.type = 'button';
		actions.appendChild( save );
		actions.appendChild( dismiss );
		card.appendChild( actions );
		card.appendChild( statusEl );

		function done( text ) {
			[ name, desc, steps ].forEach( function ( c ) {
				c.disabled = true;
			} );
			actions.hidden = true;
			statusEl.textContent = text;
			if ( cfg.skillsUrl ) {
				var link = el( 'a', '', __( 'Manage skills', 'wp-cortex' ) );
				link.href = cfg.skillsUrl;
				statusEl.appendChild( document.createTextNode( ' ' ) );
				statusEl.appendChild( link );
			}
		}

		dismiss.addEventListener( 'click', function () {
			dismiss.disabled = true;
			statusEl.textContent = '';
			resolveSkillProposal( proposalId, 'dismissed', 0 ).then( function () {
				skill.status = 'dismissed';
				done( __( 'Not saved.', 'wp-cortex' ) );
			} ).catch( function ( err ) {
				dismiss.disabled = false;
				statusEl.textContent = ( err && err.message ) || __( 'Could not remember this decision.', 'wp-cortex' );
			} );
		} );
		card.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			save.disabled = true;
			statusEl.textContent = '';
			var data = { name: name.value, description: desc.value, instructions: steps.value };
			if ( ! existingId ) {
				data.source = 'agent';
			}
			wp.apiFetch( {
				path: '/wp-cortex/v1/skills' + ( existingId ? '/' + existingId : '' ),
				method: existingId ? 'PUT' : 'POST',
				data: data
			} ).then( function ( savedSkill ) {
				var savedId = parseInt( savedSkill && savedSkill.id, 10 ) || existingId;
				return resolveSkillProposal( proposalId, 'saved', savedId ).then( function () {
					skill.status = 'saved';
					skill.skill_id = savedId;
					done( __( 'Skill saved.', 'wp-cortex' ) );
				} ).catch( function () {
					// The skill is saved even if an old conversation cannot be updated.
					skill.status = 'saved';
					skill.skill_id = savedId;
					done( __( 'Skill saved. This card will be finalized when the conversation is reloaded.', 'wp-cortex' ) );
				} );
			} ).catch( function ( err ) {
				save.disabled = false;
				statusEl.textContent = ( err && err.message ) || __( 'Something went wrong.', 'wp-cortex' );
			} );
		} );

		placeSkillProposalCard( card );
	}

	function screenContext() {
		return { screen: cfg.screen || '', post_id: cfg.postId || 0, admin_pages: cfg.adminPages || [], tabs: collectTabs() };
	}

	function actionStatusText( action ) {
		switch ( action.status ) {
			case 'done':
				return __( 'Done.', 'wp-cortex' );
			case 'cancelled':
				return __( 'Cancelled.', 'wp-cortex' );
			case 'running':
				return __( 'Running…', 'wp-cortex' );
			case 'failed':
				/* translators: %s: error message */
				return __( 'Failed: %s', 'wp-cortex' ).replace( '%s', function () {
					return action.error || __( 'unknown error', 'wp-cortex' );
				} );
		}
		return '';
	}

	function addAbilityAction( action ) {
		if ( ! action || ! action.id ) {
			return;
		}
		var pending = 'pending' === action.status;
		var card = el( 'div', P + 'card ' + P + 'action' + ( action.destructive ? ' ' + P + 'action-destructive' : '' ) );
		card.setAttribute( 'data-action-id', String( action.id ) );
		card.appendChild( el( 'p', P + 'card-title', pending ? __( 'Run this action?', 'wp-cortex' ) : ( action.label || action.ability ) ) );

		var badges = el( 'div', P + 'badges' );
		if ( pending ) {
			badges.appendChild( el( 'span', P + 'badge', action.label || action.ability ) );
		}
		badges.appendChild( el( 'code', P + 'author', action.ability || '' ) );
		if ( action.destructive ) {
			badges.appendChild( el( 'span', P + 'badge ' + P + 'badge-destructive', __( 'Destructive', 'wp-cortex' ) ) );
		}
		card.appendChild( badges );

		if ( pending && action.description ) {
			card.appendChild( el( 'p', P + 'snippet', action.description ) );
		}
		var input = action.input;
		if ( input && 'object' === typeof input && Object.keys( input ).length ) {
			card.appendChild( el( 'pre', P + 'action-input', JSON.stringify( input, null, 2 ) ) );
		}

		var statusEl = el( 'p', P + 'skill-status', pending ? '' : actionStatusText( action ) );

		if ( pending ) {
			var buttons = el( 'div', P + 'card-actions' );
			var run = el( 'button', 'button button-small button-primary', __( 'Run', 'wp-cortex' ) );
			run.type = 'button';
			var cancel = el( 'button', 'button button-small', __( 'Cancel', 'wp-cortex' ) );
			cancel.type = 'button';
			buttons.appendChild( run );
			buttons.appendChild( cancel );
			card.appendChild( buttons );

			var decide = function ( decision ) {
				if ( busy ) {
					return;
				}
				run.disabled = true;
				cancel.disabled = true;
				statusEl.textContent = 'run' === decision ? __( 'Running…', 'wp-cortex' ) : '';
				setBusy( true );
				wp.apiFetch( {
					path: '/wp-cortex/v1/chat/conversations/' + state.conversationId + '/actions/' + encodeURIComponent( action.id ),
					method: 'POST',
					data: { decision: decision, context: screenContext() }
				} ).then( function ( res ) {
					setBusy( false );
					( res.items || [] ).forEach( renderItem );
					scrollBottom();
					handleActions( res.actions );
				} ).catch( function ( err ) {
					setBusy( false );
					run.disabled = false;
					cancel.disabled = false;
					statusEl.textContent = ( err && err.message ) || __( 'Something went wrong.', 'wp-cortex' );
				} );
			};
			run.addEventListener( 'click', function () {
				decide( 'run' );
			} );
			cancel.addEventListener( 'click', function () {
				decide( 'cancel' );
			} );
		}
		card.appendChild( statusEl );

		var existing = list.querySelector( '[data-action-id="' + String( action.id ).replace( /[^a-fA-F0-9-]/g, '' ) + '"]' );
		if ( existing && existing.parentNode ) {
			existing.parentNode.replaceChild( card, existing );
		} else {
			list.appendChild( card );
		}
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
			case 'results':
				addResults( item.results );
				break;
			case 'skill_proposal':
				addSkillProposal( item.skill );
				break;
			case 'ability_action':
				addAbilityAction( item.action );
				break;
			case 'error':
				addMessage( 'error', item.text || '', false );
				break;
		}
	}

	function renderEmpty() {
		list.innerHTML = '';
		list.appendChild( el( 'div', P + 'empty', __( 'Ask me about your site content, for example: find posts about a topic without a meta description.', 'wp-cortex' ) ) );
	}

	function renderTranscript( items ) {
		list.innerHTML = '';
		if ( ! items || ! items.length ) {
			renderEmpty();
			return;
		}
		items.forEach( renderItem );
		scrollBottom();
	}

	function setBusy( on ) {
		busy = on;
		input.disabled = on;
		sendBtn.disabled = on;
		if ( on ) {
			thinkingEl = el( 'div', P + 'thinking', __( 'Thinking…', 'wp-cortex' ) );
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

	/* ---------- Conversations ---------- */

	function loadList() {
		return wp.apiFetch( { path: '/wp-cortex/v1/chat/conversations' } ).then( function ( res ) {
			listLoaded = true;
			select.innerHTML = '';
			var blank = el( 'option', '', __( 'New conversation', 'wp-cortex' ) );
			blank.value = '0';
			select.appendChild( blank );
			( res.conversations || [] ).forEach( function ( c ) {
				var o = el( 'option', '', c.title || __( 'Untitled', 'wp-cortex' ) );
				o.value = String( c.id );
				select.appendChild( o );
			} );
			select.value = String( state.conversationId );
			if ( select.value !== String( state.conversationId ) ) {
				// The stored conversation no longer exists (deleted, or another user); start fresh.
				select.value = '0';
				if ( state.conversationId && ! busy ) {
					state.conversationId = 0;
					saveState();
					renderEmpty();
				}
			}
		} ).catch( function () {} );
	}

	function loadConversation( id ) {
		state.conversationId = id;
		saveState();
		if ( ! id ) {
			renderEmpty();
			return Promise.resolve();
		}
		return wp.apiFetch( { path: '/wp-cortex/v1/chat/conversations/' + id } ).then( function ( res ) {
			renderTranscript( res.transcript );
		} ).catch( function ( err ) {
			state.conversationId = 0;
			saveState();
			list.innerHTML = '';
			addMessage( 'error', ( err && err.message ) || __( 'Could not load the conversation.', 'wp-cortex' ), false );
		} );
	}

	function newChat() {
		if ( busy ) {
			return;
		}
		state.conversationId = 0;
		saveState();
		if ( select ) {
			select.value = '0';
		}
		renderEmpty();
		input.focus();
	}

	function deleteCurrent() {
		if ( busy || ! state.conversationId ) {
			return;
		}
		if ( ! window.confirm( __( 'Delete this conversation?', 'wp-cortex' ) ) ) {
			return;
		}
		wp.apiFetch( { path: '/wp-cortex/v1/chat/conversations/' + state.conversationId, method: 'DELETE' } ).then( function () {
			newChat();
			return loadList();
		} ).catch( function ( err ) {
			addMessage( 'error', ( err && err.message ) || __( 'Could not delete the conversation.', 'wp-cortex' ), false );
			scrollBottom();
		} );
	}

	/* ---------- Tabs ---------- */

	function normalizeLabel( text ) {
		return String( text || '' ).replace( /\s+/g, ' ' ).trim();
	}

	// Visible tab elements of the current screen (ACF, core nav tabs and ARIA tabs) with their labels.
	function tabElements() {
		var found = [];
		var seen = {};
		if ( cfg.frontend ) {
			// Tabs can only be switched on admin screens.
			return found;
		}
		var nodes = document.querySelectorAll( '.acf-tab-wrap .acf-tab-button, .nav-tab-wrapper .nav-tab, [role="tab"]' );
		Array.prototype.forEach.call( nodes, function ( node ) {
			if ( root && root.contains( node ) ) {
				return;
			}
			if ( ! ( node.offsetWidth || node.offsetHeight || node.getClientRects().length ) ) {
				return;
			}
			var label = normalizeLabel( node.textContent );
			if ( ! label || seen[ label ] || found.length >= MAX_TABS ) {
				return;
			}
			seen[ label ] = true;
			found.push( { label: label, node: node } );
		} );
		return found;
	}

	function collectTabs() {
		return tabElements().map( function ( t ) {
			return t.label;
		} );
	}

	function findTab( label ) {
		var wanted = normalizeLabel( label ).toLowerCase();
		var tabs = tabElements();
		for ( var i = 0; i < tabs.length; i++ ) {
			if ( tabs[ i ].label.toLowerCase() === wanted ) {
				return tabs[ i ];
			}
		}
		return null;
	}

	function clickTab( label ) {
		var tab = findTab( label );
		if ( ! tab ) {
			return false;
		}
		if ( tab.node.scrollIntoView ) {
			tab.node.scrollIntoView( { block: 'center' } );
		}
		tab.node.click();
		return true;
	}

	function reportTab( label, found ) {
		var text = found
			/* translators: %s: tab name */
			? __( 'Switched to the “%s” tab.', 'wp-cortex' )
			/* translators: %s: tab name */
			: __( 'The “%s” tab was not found on this page.', 'wp-cortex' );
		addMessage( 'assistant', text.replace( '%s', function () {
			return label;
		} ), false );
		scrollBottom();
	}

	function setPendingTab( label ) {
		try {
			window.sessionStorage.setItem( TAB_KEY, label );
		} catch ( e ) {}
	}

	// Reads and clears the tab to open after navigation, so it can never loop.
	function takePendingTab() {
		var label = '';
		try {
			label = window.sessionStorage.getItem( TAB_KEY ) || '';
			window.sessionStorage.removeItem( TAB_KEY );
		} catch ( e ) {}
		return normalizeLabel( label );
	}

	// Opens a tab path such as "Visitor chat › Appearance" level by level: a nested tab
	// only becomes visible after its parent is selected. ACF initializes its tabs on
	// ready, so each level is retried for a few seconds.
	function applyPendingTab() {
		var path = takePendingTab();
		if ( ! path ) {
			return;
		}
		var parts = path.split( /\s*[›>]\s*/ ).filter( Boolean );
		var level = 0;
		var tries = 0;
		( function attempt() {
			if ( clickTab( parts[ level ] ) ) {
				if ( ++level >= parts.length ) {
					reportTab( parts.join( ' › ' ), true );
					return;
				}
				tries = 0;
				window.setTimeout( attempt, 100 );
			} else if ( ++tries < 12 ) {
				window.setTimeout( attempt, 250 );
			} else {
				reportTab( parts[ level ], false );
			}
		}() );
	}

	/* ---------- Sending ---------- */

	function handleActions( actions ) {
		actions = actions || [];
		// Only one page can be opened: the last navigation wins, as on the server.
		var nav = actions.filter( function ( a ) {
			return a && 'navigate' === a.type && a.url;
		} ).pop();
		if ( ! nav ) {
			// Without navigation, switch tabs on the current screen.
			actions.forEach( function ( a ) {
				if ( a && 'select_tab' === a.type && a.label ) {
					reportTab( a.label, clickTab( a.label ) );
				}
			} );
			return;
		}
		var target;
		try {
			target = new URL( nav.url, window.location.href );
		} catch ( e ) {
			return;
		}
		if ( target.origin !== window.location.origin ) {
			return;
		}
		var title = nav.title || target.pathname;
		/* translators: %s: post title or admin screen name */
		addMessage( 'assistant', __( 'Opening “%s”…', 'wp-cortex' ).replace( '%s', function () {
			return title;
		} ), false );
		scrollBottom();
		state.open = true;
		saveState();
		if ( nav.tab ) {
			setPendingTab( String( nav.tab ) );
		}
		window.setTimeout( function () {
			window.location.href = target.href;
		}, 600 );
	}

	function send() {
		var text = input.value.trim();
		if ( busy || ! text ) {
			return;
		}
		input.value = '';
		var empty = list.querySelector( '.' + P + 'empty' );
		if ( empty ) {
			list.removeChild( empty );
		}
		renderItem( { role: 'user', text: text } );
		setBusy( true );
		post( text, true );
	}

	function post( text, canRetry ) {
		wp.apiFetch( {
			path: '/wp-cortex/v1/chat/message',
			method: 'POST',
			data: {
				conversation_id: state.conversationId || 0,
				message: text,
				context: screenContext()
			}
		} ).then( function ( res ) {
			var isNew = res.conversation_id && res.conversation_id !== state.conversationId;
			state.conversationId = parseInt( res.conversation_id, 10 ) || 0;
			saveState();
			setBusy( false );
			// The user item was already shown locally; skip the first returned user item.
			var skipped = false;
			( res.items || [] ).forEach( function ( item ) {
				if ( ! skipped && 'user' === item.role ) {
					skipped = true;
					return;
				}
				renderItem( item );
			} );
			scrollBottom();
			if ( isNew || listLoaded ) {
				loadList();
			}
			handleActions( res.actions );
		} ).catch( function ( err ) {
			// The conversation was deleted meanwhile: continue as a new conversation.
			if ( canRetry && err && 'wp_cortex_not_found' === err.code && state.conversationId ) {
				state.conversationId = 0;
				saveState();
				if ( select ) {
					select.value = '0';
				}
				post( text, false );
				return;
			}
			setBusy( false );
			renderItem( { role: 'error', text: ( err && err.message ) || __( 'Something went wrong.', 'wp-cortex' ) } );
			scrollBottom();
		} );
	}

	/* ---------- Open / close ---------- */

	function openPanel() {
		state.open = true;
		saveState();
		panel.classList.add( 'is-open' );
		toggle.setAttribute( 'aria-expanded', 'true' );
		positionPanel();
		if ( ! listLoaded ) {
			loadList();
		}
		input.focus();
	}

	function closePanel() {
		state.open = false;
		saveState();
		panel.classList.remove( 'is-open' );
		toggle.setAttribute( 'aria-expanded', 'false' );
		toggle.focus();
	}

	/* ---------- Build UI ---------- */

	function build() {
		root = document.getElementById( 'wp-cortex-chat-root' );
		if ( ! root ) {
			return;
		}

		toggle = el( 'button', P + 'toggle' );
		toggle.type = 'button';
		toggle.setAttribute( 'aria-label', __( 'Toggle Cortex chat', 'wp-cortex' ) );
		toggle.setAttribute( 'title', __( 'Drag to move the chat', 'wp-cortex' ) );
		toggle.setAttribute( 'aria-expanded', 'false' );
		var ti = el( 'span', 'dashicons dashicons-format-chat' );
		ti.setAttribute( 'aria-hidden', 'true' );
		toggle.appendChild( ti );
		toggle.addEventListener( 'click', function () {
			if ( ignoreNextToggle ) {
				ignoreNextToggle = false;
				return;
			}
			if ( panel.classList.contains( 'is-open' ) ) {
				closePanel();
			} else {
				openPanel();
			}
		} );

		panel = el( 'div', P + 'panel' );
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-label', __( 'Cortex chat', 'wp-cortex' ) );

		var header = el( 'div', P + 'header' );
		header.appendChild( el( 'h2', P + 'title', __( 'Cortex', 'wp-cortex' ) ) );
		select = el( 'select', P + 'select' );
		select.setAttribute( 'aria-label', __( 'Conversation', 'wp-cortex' ) );
		var first = el( 'option', '', __( 'New conversation', 'wp-cortex' ) );
		first.value = '0';
		select.appendChild( first );
		select.addEventListener( 'change', function () {
			if ( busy ) {
				select.value = String( state.conversationId );
				return;
			}
			loadConversation( parseInt( select.value, 10 ) || 0 );
		} );
		header.appendChild( select );
		header.appendChild( iconButton( 'plus-alt2', __( 'New chat', 'wp-cortex' ), newChat ) );
		header.appendChild( iconButton( 'trash', __( 'Delete conversation', 'wp-cortex' ), deleteCurrent ) );
		header.appendChild( iconButton( 'no-alt', __( 'Close', 'wp-cortex' ), closePanel ) );

		list = el( 'div', P + 'messages' );
		list.setAttribute( 'aria-live', 'polite' );

		var form = el( 'form', P + 'form' );
		input = el( 'textarea', P + 'input' );
		input.rows = 2;
		input.placeholder = __( 'Ask about your content…', 'wp-cortex' );
		input.setAttribute( 'aria-label', __( 'Message', 'wp-cortex' ) );
		sendBtn = el( 'button', 'button button-primary', __( 'Send', 'wp-cortex' ) );
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

		resizeHandle = el( 'div', P + 'resize-handle' );
		resizeHandle.setAttribute( 'aria-hidden', 'true' );
		resizeHandle.title = __( 'Drag to resize, double-click to reset', 'wp-cortex' );

		panel.appendChild( header );
		panel.appendChild( list );
		panel.appendChild( form );
		panel.appendChild( resizeHandle );

		// Keep editor shortcuts from firing while typing in the panel.
		panel.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				closePanel();
			}
			e.stopPropagation();
		} );
		panel.addEventListener( 'keyup', function ( e ) {
			e.stopPropagation();
		} );
		panel.addEventListener( 'keypress', function ( e ) {
			e.stopPropagation();
		} );

		root.appendChild( toggle );
		root.appendChild( panel );

		renderEmpty();
		loadState();
		loadSize();
		applyPosition();
		bindToggleDrag();
		bindPanelResize();
		window.addEventListener( 'resize', applyPosition );
		if ( state.open ) {
			openPanel();
			if ( state.conversationId ) {
				loadConversation( state.conversationId ).then( function () {
					if ( listLoaded ) {
						select.value = String( state.conversationId );
					}
					scrollBottom();
					applyPendingTab();
				} );
				return;
			}
		}
		applyPendingTab();
	}

	wp.domReady( build );
}() );
