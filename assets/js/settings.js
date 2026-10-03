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

	function initModelPicker() {
		var picker = document.getElementById( 'wp-cortex-model-picker' );
		var provider = document.querySelector( 'select[name$="[chat_provider]"]' );

		if ( ! picker || ! provider || ! window.wp || ! wp.apiFetch ) {
			return;
		}

		var __ = wp.i18n.__;
		var sprintf = wp.i18n.sprintf;
		var select = document.getElementById( 'wp-cortex-chat-model' );
		var filterInput = document.getElementById( 'wp-cortex-model-filter' );
		var refresh = document.getElementById( 'wp-cortex-model-refresh' );
		var status = document.getElementById( 'wp-cortex-model-status' );
		var reasoning = document.getElementById( 'wp-cortex-reasoning' );
		var reasoningSelect = document.getElementById( 'wp-cortex-chat-reasoning' );
		var chosen = picker.getAttribute( 'data-saved' ) || '';
		var models = [];
		var requestId = 0;

		filterInput.hidden = false;
		refresh.hidden = false;

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

				if ( m.tools ) {
					toolIds.push( m.id );
				}

				if ( query !== '' && label.toLowerCase().indexOf( query ) === -1 ) {
					return;
				}

				if ( m.tools ) {
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

			available = available && provider.value !== '' && chosen !== '';
			reasoning.hidden = ! available;
			reasoningSelect.disabled = ! available;
		}

		function setBusy( busy ) {
			refresh.disabled = busy || provider.value === '';
			refresh.classList.toggle( 'is-loading', busy );
		}

		function sync() {
			var none = provider.value === '';

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

	initEmbeddings();
	initModelPicker();
}() );
