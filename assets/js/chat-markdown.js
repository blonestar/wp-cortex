/**
 * Safe Markdown subset for the Cortex chat panels: paragraphs, lists, links, bold,
 * italic and inline code. Input is HTML-escaped first; only http(s) and relative links.
 */
( function () {
	'use strict';

	function escapeHtml( s ) {
		return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	}

	function safeUrl( url ) {
		// The input is already HTML-escaped; undo only for URL parsing.
		var raw = url.replace( /&amp;/g, '&' );
		try {
			var u = new URL( raw, window.location.href );
			if ( 'http:' === u.protocol || 'https:' === u.protocol ) {
				// Only plain http(s) or relative URLs (no other schemes) are accepted.
				if ( /^[a-z][a-z0-9+.-]*:/i.test( raw ) && ! /^https?:/i.test( raw ) ) {
					return '';
				}
				return escapeHtml( raw );
			}
		} catch ( e ) {}
		return '';
	}

	function inline( s ) {
		var codes = [];
		s = s.replace( /`([^`]+)`/g, function ( m, c ) {
			codes.push( c );
			return '\u0000' + ( codes.length - 1 ) + '\u0000';
		} );
		s = s.replace( /\[([^\]]+)\]\(([^)\s]+)\)/g, function ( m, label, url ) {
			var safe = safeUrl( url );
			if ( ! safe ) {
				return label;
			}
			return '<a href="' + safe + '" rel="noopener">' + label + '</a>';
		} );
		s = s.replace( /\*\*([^*]+)\*\*/g, '<strong>$1</strong>' );
		s = s.replace( /(^|[^*])\*([^*\s][^*]*)\*/g, '$1<em>$2</em>' );
		s = s.replace( /\u0000(\d+)\u0000/g, function ( m, i ) {
			return '<code>' + codes[ +i ] + '</code>';
		} );
		return s;
	}

	function renderMarkdown( text ) {
		var lines = escapeHtml( text ).split( /\r?\n/ );
		var out = '';
		var para = [];
		var listType = '';

		function flushPara() {
			if ( para.length ) {
				out += '<p>' + inline( para.join( '<br>' ) ) + '</p>';
				para = [];
			}
		}
		function closeList() {
			if ( listType ) {
				out += '</' + listType + '>';
				listType = '';
			}
		}

		lines.forEach( function ( line ) {
			var ul = /^\s*[-*]\s+(.*)$/.exec( line );
			var ol = /^\s*\d+[.)]\s+(.*)$/.exec( line );
			if ( ul || ol ) {
				flushPara();
				var type = ul ? 'ul' : 'ol';
				if ( listType !== type ) {
					closeList();
					out += '<' + type + '>';
					listType = type;
				}
				out += '<li>' + inline( ( ul || ol )[ 1 ] ) + '</li>';
			} else if ( '' === line.trim() ) {
				flushPara();
				closeList();
			} else {
				closeList();
				para.push( line );
			}
		} );
		flushPara();
		closeList();
		return out;
	}

	window.wpCortexMarkdown = { render: renderMarkdown };
}() );
