( function () {
	'use strict';

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
}() );
