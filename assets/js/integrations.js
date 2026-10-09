/**
 * Settings > Leads > Integrations: the Send test lead button posts the values of the
 * integration's card (saved or not) to /wp-cortex/v1/integrations/<id>/test.
 */
( function () {
	'use strict';

	var apiFetch = wp.apiFetch;
	var __ = wp.i18n.__;

	// Values of a card's inputs, keyed by setting name: ...[integrations][<id>][<key>] (and [] for lists).
	function collect( card ) {
		var settings = {};
		Array.prototype.forEach.call( card.querySelectorAll( 'input, select, textarea' ), function ( input ) {
			var match = /\[integrations\]\[[^\]]+\]\[([^\]]+)\](\[\])?$/.exec( input.name || '' );
			if ( ! match ) {
				return;
			}
			var key = match[ 1 ];
			if ( match[ 2 ] ) {
				settings[ key ] = settings[ key ] || [];
				if ( input.checked ) {
					settings[ key ].push( input.value );
				}
			} else if ( 'checkbox' === input.type ) {
				settings[ key ] = input.checked ? '1' : '';
			} else {
				settings[ key ] = input.value;
			}
		} );
		return settings;
	}

	Array.prototype.forEach.call( document.querySelectorAll( '.wp-cortex-integration' ), function ( card ) {
		var button = card.querySelector( '.wp-cortex-integration-test' );
		var result = card.querySelector( '.wp-cortex-integration-result' );
		if ( ! button || ! result ) {
			return;
		}
		button.addEventListener( 'click', function () {
			button.disabled = true;
			result.className = 'wp-cortex-integration-result';
			result.textContent = __( 'Sending…', 'wp-cortex' );
			apiFetch( {
				path: '/wp-cortex/v1/integrations/' + encodeURIComponent( card.getAttribute( 'data-integration' ) ) + '/test',
				method: 'POST',
				data: { settings: collect( card ) }
			} ).then( function () {
				result.className = 'wp-cortex-integration-result is-ok';
				result.textContent = __( 'Test lead sent.', 'wp-cortex' );
			} ).catch( function ( error ) {
				result.className = 'wp-cortex-integration-result is-error';
				result.textContent = ( error && error.message ) || __( 'The test lead could not be sent.', 'wp-cortex' );
			} ).then( function () {
				button.disabled = false;
			} );
		} );
	} );
}() );
