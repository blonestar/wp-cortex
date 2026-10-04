( function () {
	'use strict';

	function initEmbeddings() {
		var model = document.getElementById( 'wp-cortex-embedding-model' );
		var dims = document.getElementById( 'wp-cortex-embedding-dimensions' );

		if ( ! model || ! dims ) {
			return;
		}

		function filter() {
			var options = Array.prototype.slice.call( dims.options );
			var largest = null;
			var currentValid = false;

			options.forEach( function ( option ) {
				var models = ( option.getAttribute( 'data-models' ) || '' ).split( ' ' );
				var allowed = models.indexOf( model.value ) !== -1;

				option.hidden = ! allowed;
				option.disabled = ! allowed;

				if ( allowed ) {
					if ( ! largest || parseInt( option.value, 10 ) > parseInt( largest.value, 10 ) ) {
						largest = option;
					}
					if ( option.value === dims.value ) {
						currentValid = true;
					}
				}
			} );

			if ( ! currentValid && largest ) {
				dims.value = largest.value;
			}
		}

		model.addEventListener( 'change', filter );
		filter();
	}

	function initModelPickers() {
		document.querySelectorAll( '.wp-cortex-model-picker' ).forEach( initModelPicker );
	}

	// Wires one model picker (admin chat, visitor chat or summary) to its provider select.
	function initModelPicker( picker ) {
		var provider = document.getElementById( picker.getAttribute( 'data-provider' ) || '' );

		if ( ! provider || ! window.wp || ! wp.apiFetch ) {
			return;
		}

		var __ = wp.i18n.__;
		var sprintf = wp.i18n.sprintf;
		var select = picker.querySelector( '.wp-cortex-model-select' );
		var filterInput = picker.querySelector( '.wp-cortex-model-filter' );
		var refresh = picker.querySelector( '.wp-cortex-model-refresh' );
		var status = picker.querySelector( '.wp-cortex-model-status' );
		var reasoning = picker.querySelector( '.wp-cortex-reasoning' );
		var reasoningSelect = picker.querySelector( '.wp-cortex-reasoning-select' );
		var chosen = picker.getAttribute( 'data-saved' ) || '';
		// The summary makes no tool calls, so any model can be chosen.
		var anyModel = picker.hasAttribute( 'data-any-model' );
		var models = [];
		var requestId = 0;

		filterInput.hidden = false;
		refresh.hidden = false;

		// Whether the selected provider option has no model choice ("Automatic", "Same as the admin chat").
		function noProvider() {
			var current = provider.options[ provider.selectedIndex ];

			return ! current || current.hasAttribute( 'data-no-model' );
		}

		function option( value, label, disabled ) {
			var el = document.createElement( 'option' );
			el.value = value;
			el.textContent = label;
			el.disabled = !! disabled;
			return el;
		}

		function render() {
			var query = filterInput.value.trim().toLowerCase();
			var withTools = document.createDocumentFragment();
			var withoutTools = document.createElement( 'optgroup' );
			var noToolsCount = 0;
			var toolIds = [];

			select.innerHTML = '';
			select.appendChild( option( '', __( 'Provider default', 'wp-cortex' ) ) );
			withoutTools.label = __( 'Without tool calling', 'wp-cortex' );

			models.forEach( function ( m ) {
				var label = m.name + ' (' + m.id + ')';
				var usable = anyModel || m.tools;

				if ( usable ) {
					toolIds.push( m.id );
				}

				if ( query !== '' && label.toLowerCase().indexOf( query ) === -1 ) {
					return;
				}

				if ( usable ) {
					withTools.appendChild( option( m.id, label ) );
				} else {
					withoutTools.appendChild( option( m.id, label, true ) );
					noToolsCount++;
				}
			} );

			if ( chosen !== '' && toolIds.indexOf( chosen ) === -1 ) {
				// Keep the saved value selectable even when it is not in the list.
				select.appendChild( option( chosen, sprintf( /* translators: %s: model ID. */ __( '%s (saved)', 'wp-cortex' ), chosen ) ) );
			}

			select.appendChild( withTools );

			if ( noToolsCount ) {
				select.appendChild( withoutTools );
			}

			select.value = chosen;

			if ( select.value !== chosen ) {
				// Filtered out of view: keep it as a hidden-from-list but submitted value.
				select.insertBefore( option( chosen, chosen ), select.options[ 1 ] || null );
				select.value = chosen;
			}

			syncReasoning();
		}

		// Shows the reasoning levels of the selected provider, only once a model is chosen.
		function syncReasoning() {
			var available = false;
			var currentValid;

			if ( ! reasoning || ! reasoningSelect ) {
				return;
			}

			currentValid = reasoningSelect.value === '';

			Array.prototype.forEach.call( reasoningSelect.options, function ( el ) {
				var providers = el.getAttribute( 'data-providers' );
				var allowed;

				if ( providers === null ) {
					return;
				}

				allowed = providers.split( ' ' ).indexOf( provider.value ) !== -1;
				el.hidden = ! allowed;
				el.disabled = ! allowed;
				available = available || allowed;

				if ( allowed && el.value === reasoningSelect.value ) {
					currentValid = true;
				}
			} );

			if ( ! currentValid ) {
				reasoningSelect.value = '';
			}

			available = available && ! noProvider() && chosen !== '';
			reasoning.hidden = ! available;
			reasoningSelect.disabled = ! available;
		}

		function setBusy( busy ) {
			refresh.disabled = busy || noProvider();
			refresh.classList.toggle( 'is-loading', busy );
		}

		function sync() {
			var none = noProvider();

			select.disabled = none;
			filterInput.disabled = none;
			setBusy( false );
			picker.classList.toggle( 'is-disabled', none );

			if ( none ) {
				status.textContent = __( 'Select a provider to choose a model', 'wp-cortex' );
			}

			return ! none;
		}

		function load( force ) {
			var id = ++requestId;

			models = [];

			if ( ! sync() ) {
				render();
				return;
			}

			setBusy( true );
			status.textContent = __( 'Loading models…', 'wp-cortex' );
			status.classList.remove( 'is-error' );

			wp.apiFetch( {
				path: '/wp-cortex/v1/chat/models?provider=' + encodeURIComponent( provider.value ) + '&refresh=' + ( force ? '1' : '0' )
			} ).then( function ( data ) {
				if ( id !== requestId ) {
					return;
				}
				models = data.models || [];
				render();
				status.textContent = sprintf(
					/* translators: 1: number of models, 2: cached or fresh. */
					__( '%1$d models · %2$s', 'wp-cortex' ),
					models.length,
					data.cached ? __( 'cached', 'wp-cortex' ) : __( 'fresh', 'wp-cortex' )
				);
			} ).catch( function ( error ) {
				if ( id !== requestId ) {
					return;
				}
				render();
				status.textContent = ( error && error.message ) || __( 'Could not load the model list.', 'wp-cortex' );
				status.classList.add( 'is-error' );
			} ).then( function () {
				if ( id === requestId ) {
					setBusy( false );
				}
			} );
		}

		provider.addEventListener( 'change', function () {
			chosen = '';
			load( false );
		} );
		select.addEventListener( 'change', function () {
			chosen = select.value;
			syncReasoning();
		} );
		refresh.addEventListener( 'click', function () {
			load( true );
		} );
		filterInput.addEventListener( 'input', render );
		filterInput.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' ) {
				event.preventDefault();
			}
		} );

		load( false );
	}

	// Wires a list of tab links to their panels. Returns a function that selects a tab.
	function tablist( items, activeClass, vertical, onSelect ) {
		function select( item, focus ) {
			items.forEach( function ( other ) {
				var selected = other === item;
				var panel = document.getElementById( other.getAttribute( 'aria-controls' ) );

				other.classList.toggle( activeClass, selected );
				other.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
				other.setAttribute( 'tabindex', selected ? '0' : '-1' );
				if ( panel ) {
					panel.hidden = ! selected;
				}
			} );

			if ( focus ) {
				item.focus();
			}
			onSelect( item );
		}

		items.forEach( function ( item, index ) {
			item.setAttribute( 'tabindex', item.classList.contains( activeClass ) ? '0' : '-1' );

			item.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				select( item, false );
			} );

			item.addEventListener( 'keydown', function ( event ) {
				var next = null;

				if ( event.key === ( vertical ? 'ArrowDown' : 'ArrowRight' ) ) {
					next = items[ ( index + 1 ) % items.length ];
				} else if ( event.key === ( vertical ? 'ArrowUp' : 'ArrowLeft' ) ) {
					next = items[ ( index - 1 + items.length ) % items.length ];
				} else if ( event.key === 'Home' ) {
					next = items[ 0 ];
				} else if ( event.key === 'End' ) {
					next = items[ items.length - 1 ];
				}

				if ( next ) {
					event.preventDefault();
					select( next, true );
				}
			} );
		} );

		return select;
	}

	// Text color readable on the accent: white or near black, whichever contrasts more.
	// Mirrors ChatAppearance::text_on().
	function textOn( hex ) {
		var m = /^#?([0-9a-f]{6})$/i.exec( hex || '' );
		var luminance = 0;

		if ( ! m ) {
			return '#fff';
		}
		[ 0.2126, 0.7152, 0.0722 ].forEach( function ( weight, i ) {
			var c = parseInt( m[ 1 ].substr( i * 2, 2 ), 16 ) / 255;
			luminance += weight * ( c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 ) );
		} );

		return 1.05 / ( luminance + 0.05 ) >= ( luminance + 0.05 ) / 0.066 ? '#fff' : '#1d2327';
	}

	// Keeps the visitor chat preview (Visitor chat > Appearance) in step with the unsaved fields.
	function initChatPreview() {
		var preview = document.getElementById( 'wp-cortex-pchat-preview' );
		var form = preview && preview.closest( 'form' );
		var sizes = {
			public_chat_radius: '--wp-cortex-radius',
			public_chat_width: '--wp-cortex-width',
			public_chat_height: '--wp-cortex-height',
			public_chat_launcher_size: '--wp-cortex-launcher',
			public_chat_offset: '--wp-cortex-offset',
			public_chat_font_size: '--wp-cortex-font-size'
		};

		if ( ! form ) {
			return;
		}

		function field( key ) {
			return form.elements[ 'wp_cortex_settings[' + key + ']' ];
		}

		function value( key ) {
			var f = field( key );
			return f ? f.value : '';
		}

		function update() {
			var accent = value( 'public_chat_accent' );
			var label = value( 'public_chat_launcher_label' ).trim();
			var title = preview.querySelector( '.wp-cortex-pchat-title' );
			var toggle = preview.querySelector( '.wp-cortex-pchat-toggle' );
			var scheme = value( 'public_chat_scheme' );

			Object.keys( sizes ).forEach( function ( key ) {
				var f = field( key );
				var n = f ? parseInt( f.value, 10 ) : NaN;

				if ( ! isNaN( n ) ) {
					n = Math.min( Math.max( n, parseInt( f.min, 10 ) || 0 ), parseInt( f.max, 10 ) || n );
					preview.style.setProperty( sizes[ key ], n + 'px' );
				}
			} );

			if ( /^#[0-9a-f]{6}$/i.test( accent ) ) {
				preview.style.setProperty( '--wp-cortex-accent', accent );
				preview.style.setProperty( '--wp-cortex-accent-text', textOn( accent ) );
			}

			[ 'light', 'dark', 'auto' ].forEach( function ( name ) {
				preview.classList.toggle( 'wp-cortex-pchat-scheme-' + name, name === scheme );
			} );
			preview.classList.toggle( 'wp-cortex-pchat-left', 'left' === value( 'public_chat_position' ) );
			preview.classList.toggle( 'wp-cortex-pchat-font-theme', 'theme' === value( 'public_chat_font' ) );

			toggle.classList.toggle( 'has-label', '' !== label );
			toggle.querySelector( '.wp-cortex-pchat-toggle-label' ).textContent = label;
			title.textContent = value( 'public_chat_title' ).trim() || title.getAttribute( 'data-placeholder' );
		}

		form.addEventListener( 'input', update );
		form.addEventListener( 'change', update );
	}

	// Switches tabs and their vertical section tabs without reloading. All panels share
	// one form, so every value is saved; the URL and the post-save redirect keep the view.
	function initTabs() {
		var tabs = Array.prototype.slice.call( document.querySelectorAll( '.wp-cortex-tabs .nav-tab' ) );
		var referer = document.querySelector( '.wp-cortex-settings-form input[name="_wp_http_referer"]' );
		var selectSection = {};

		if ( ! tabs.length ) {
			return;
		}

		function activeIn( root, selector, activeClass ) {
			return Array.prototype.slice.call( root.querySelectorAll( selector ) ).filter( function ( item ) {
				return item.classList.contains( activeClass );
			} )[ 0 ];
		}

		function syncUrl() {
			var tab = activeIn( document, '.wp-cortex-tabs .nav-tab', 'nav-tab-active' );
			var panel = tab && document.getElementById( tab.getAttribute( 'aria-controls' ) );
			var section = panel && activeIn( panel, '.wp-cortex-subtab', 'is-active' );
			var target = section || tab;

			if ( ! target ) {
				return;
			}
			if ( window.history && window.history.replaceState ) {
				window.history.replaceState( null, '', target.href );
			}
			if ( referer ) {
				var url = new URL( referer.value, window.location.origin );
				url.searchParams.set( 'tab', tab.getAttribute( 'data-tab' ) );
				if ( section ) {
					url.searchParams.set( 'section', section.getAttribute( 'data-section' ) );
				} else {
					url.searchParams.delete( 'section' );
				}
				url.searchParams.delete( 'settings-updated' );
				referer.value = url.pathname + url.search;
			}
		}

		var selectTab = tablist( tabs, 'nav-tab-active', false, syncUrl );

		tabs.forEach( function ( tab ) {
			var panel = document.getElementById( tab.getAttribute( 'aria-controls' ) );
			var sections = panel ? Array.prototype.slice.call( panel.querySelectorAll( '.wp-cortex-subtab' ) ) : [];

			if ( sections.length ) {
				selectSection[ tab.id ] = tablist( sections, 'is-active', true, syncUrl );
			}
		} );

		// A field the browser rejects on submit may sit on a hidden tab or section: show it first.
		document.querySelectorAll( '.wp-cortex-settings-form input, .wp-cortex-settings-form select, .wp-cortex-settings-form textarea' ).forEach( function ( field ) {
			field.addEventListener( 'invalid', function () {
				var panel = field.closest( '.wp-cortex-tab-panel' );
				var tab = panel && document.getElementById( panel.getAttribute( 'aria-labelledby' ) );
				var section = field.closest( '.wp-cortex-subtab-panel' );
				var sectionTab = section && document.getElementById( section.getAttribute( 'aria-labelledby' ) );

				if ( tab && panel.hidden ) {
					selectTab( tab, false );
				}
				if ( sectionTab && section.hidden && selectSection[ tab.id ] ) {
					selectSection[ tab.id ]( sectionTab, false );
				}
			} );
		} );

		syncUrl();
	}

	initTabs();
	initEmbeddings();
	initModelPickers();
	initChatPreview();
}() );
