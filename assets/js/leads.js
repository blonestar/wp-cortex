/**
 * Cortex Leads screen: key figures compared with the previous period, conversations and
 * leads over time, leads by channel, lead quality, top sources, campaigns and landing
 * pages, and the leads list with status changes, AI rating and CSV export. Channels,
 * sources, campaigns, landing pages and days to lead are left out while attribution is off.
 *
 * Charts are plain SVG and HTML: one hue per series, a legend for more than one series,
 * a tooltip on hover and the figures in a table next to (or under) every chart.
 */
( function () {
	'use strict';

	var cfg = window.wpCortexLeads || {};
	var ATTRIBUTION = !! cfg.attribution;
	var apiFetch = wp.apiFetch;
	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;
	var PATH = '/wp-cortex/v1/leads';
	var PER_PAGE = 20;
	var SVG_NS = 'http://www.w3.org/2000/svg';
	var COLORS = {
		conversations: '#2a78d6',
		leads: '#eb6834',
		hot: '#e34948',
		warm: '#eda100',
		cold: '#2a78d6',
		unrated: '#c3c2b7',
		bar: '#2a78d6',
		grid: '#e1e0d9',
		axis: '#c3c2b7'
	};
	var CHANNELS = [ 'paid_search', 'paid_social', 'display', 'organic_search', 'organic_social', 'ai', 'email', 'affiliate', 'referral', 'other', 'direct', 'unknown' ];

	var root = document.getElementById( 'wp-cortex-leads' );
	if ( ! root ) {
		return;
	}

	function byId( id ) {
		return document.getElementById( id );
	}

	var els = {
		notice: byId( 'wp-cortex-leads-notice' ),
		period: byId( 'wp-cortex-leads-period' ),
		channel: byId( 'wp-cortex-leads-channel' ),
		rateAll: byId( 'wp-cortex-leads-rate-all' ),
		exportBtn: byId( 'wp-cortex-leads-export' ),
		kpis: byId( 'wp-cortex-leads-kpis' ),
		trend: byId( 'wp-cortex-leads-trend' ),
		channels: byId( 'wp-cortex-leads-channels' ),
		quality: byId( 'wp-cortex-leads-quality' ),
		campaigns: byId( 'wp-cortex-leads-campaigns' ),
		landing: byId( 'wp-cortex-leads-landing' ),
		statuses: byId( 'wp-cortex-leads-statuses' ),
		search: byId( 'wp-cortex-leads-search' ),
		searchInput: byId( 'wp-cortex-leads-search-input' ),
		rating: byId( 'wp-cortex-leads-rating' ),
		pages: byId( 'wp-cortex-leads-pages' ),
		head: byId( 'wp-cortex-leads-head' ),
		rows: byId( 'wp-cortex-leads-rows' )
	};

	var state = { days: 30, channel: '', status: '', rating: '', search: '', orderby: 'lead_at', order: 'desc', page: 1, pages: 1, total: 0, leads: [], counts: null };
	var report = null;
	var tooltip = null;
	var number = new Intl.NumberFormat();

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

	function svg( tag, attrs ) {
		var n = document.createElementNS( SVG_NS, tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			n.setAttribute( key, attrs[ key ] );
		} );
		return n;
	}

	function notify( message, isError ) {
		els.notice.className = 'notice inline ' + ( isError ? 'notice-error' : 'notice-success' );
		els.notice.querySelector( 'p' ).textContent = message;
		els.notice.hidden = false;
	}

	function fail( err ) {
		notify( ( err && err.message ) || __( 'Something went wrong.', 'wp-cortex' ), true );
	}

	function toDate( value ) {
		return value ? new Date( value.replace( ' ', 'T' ) + ( 10 === value.length ? 'T00:00:00' : 'Z' ) ) : null;
	}

	function fmtDate( value, withTime ) {
		var d = toDate( value );
		if ( ! d || isNaN( d.getTime() ) ) {
			return '–';
		}
		return withTime ? d.toLocaleString( undefined, { dateStyle: 'medium', timeStyle: 'short' } ) : d.toLocaleDateString( undefined, { day: 'numeric', month: 'short' } );
	}

	function fmtPercent( value ) {
		return null === value || undefined === value ? '–' : number.format( value ) + '%';
	}

	function channelLabel( channel ) {
		var labels = cfg.channels || {};
		return labels[ 'unknown' === channel ? '' : channel ] || channel || labels[ '' ] || '';
	}

	function chatLink( id, text, cls ) {
		var a = el( 'a', cls, text );
		a.href = cfg.chatUrl + '#chat=' + id;
		return a;
	}

	function query( extra ) {
		var params = { days: state.days, channel: state.channel, status: state.status, rating: state.rating, search: state.search, orderby: state.orderby, order: state.order };
		Object.keys( extra || {} ).forEach( function ( key ) {
			params[ key ] = extra[ key ];
		} );
		return '?' + Object.keys( params ).filter( function ( key ) {
			return '' !== params[ key ];
		} ).map( function ( key ) {
			return key + '=' + encodeURIComponent( params[ key ] );
		} ).join( '&' );
	}

	/* ---------- Tooltip ---------- */

	function showTip( event, lines ) {
		if ( ! tooltip ) {
			tooltip = el( 'div', 'wp-cortex-chart-tip' );
			tooltip.setAttribute( 'role', 'status' );
			document.body.appendChild( tooltip );
		}
		tooltip.innerHTML = '';
		lines.forEach( function ( line, i ) {
			var row = el( 'div', i ? '' : 'wp-cortex-chart-tip-title' );
			if ( line.color ) {
				var swatch = el( 'span', 'wp-cortex-swatch' );
				swatch.style.background = line.color;
				row.appendChild( swatch );
			}
			row.appendChild( document.createTextNode( line.text ) );
			tooltip.appendChild( row );
		} );
		tooltip.hidden = false;
		var x = event.clientX + 14;
		var y = event.clientY + 14;
		var rect = tooltip.getBoundingClientRect();
		if ( x + rect.width > window.innerWidth - 8 ) {
			x = event.clientX - rect.width - 14;
		}
		if ( y + rect.height > window.innerHeight - 8 ) {
			y = event.clientY - rect.height - 14;
		}
		tooltip.style.left = x + 'px';
		tooltip.style.top = y + 'px';
	}

	function hideTip() {
		if ( tooltip ) {
			tooltip.hidden = true;
		}
	}

	function withTip( node, lines ) {
		node.addEventListener( 'mousemove', function ( e ) {
			showTip( e, lines );
		} );
		node.addEventListener( 'mouseleave', hideTip );
		return node;
	}

	/* ---------- Key figures ---------- */

	// Change against the previous period: relative for counts, in points for rates.
	function delta( kpi, kind, lowerIsBetter ) {
		if ( null === kpi.previous || undefined === kpi.previous || null === kpi.value ) {
			return null;
		}
		var diff = kpi.value - kpi.previous;
		var text;
		if ( 'rate' === kind ) {
			text = ( diff > 0 ? '+' : '' ) + number.format( Math.round( diff * 10 ) / 10 ) + ' ' + __( 'pts', 'wp-cortex' );
		} else if ( ! kpi.previous ) {
			text = diff ? __( 'new', 'wp-cortex' ) : '0%';
		} else {
			text = ( diff > 0 ? '+' : '' ) + number.format( Math.round( 100 * diff / kpi.previous ) ) + '%';
		}
		var good = lowerIsBetter ? diff < 0 : diff > 0;
		return { text: text, trend: diff ? ( good ? 'up' : 'down' ) : 'flat', arrow: diff > 0 ? '▲' : ( diff < 0 ? '▼' : '■' ) };
	}

	function renderKpis() {
		var k = report.kpis;
		var tiles = [
			[ __( 'Leads', 'wp-cortex' ), number.format( k.leads.value ), delta( k.leads ), __( 'Visitors who left a way to reach them', 'wp-cortex' ) ],
			[ __( 'Conversations', 'wp-cortex' ), number.format( k.conversations.value ), delta( k.conversations ), __( 'Visitor chats started', 'wp-cortex' ) ],
			[ __( 'Chat to lead rate', 'wp-cortex' ), fmtPercent( k.rate.value ), delta( k.rate, 'rate' ), __( 'Leads per conversation', 'wp-cortex' ) ],
			[ __( 'Hot leads', 'wp-cortex' ), number.format( k.hot.value ), delta( k.hot ), __( 'Rated hot by AI', 'wp-cortex' ) ],
			[ __( 'Days to lead', 'wp-cortex' ), null === k.days_to_lead.value ? '–' : number.format( k.days_to_lead.value ), delta( k.days_to_lead, 'count', true ), __( 'Average from first visit to contact', 'wp-cortex' ) ]
		];
		if ( ! ATTRIBUTION ) {
			tiles.pop();
		}
		var previous = state.days ? sprintf( __( 'vs previous %d days', 'wp-cortex' ), state.days ) : '';
		els.kpis.innerHTML = '';
		tiles.forEach( function ( t ) {
			var tile = el( 'div', 'wp-cortex-kpi' );
			tile.appendChild( el( 'div', 'wp-cortex-kpi-label', t[ 0 ] ) );
			tile.appendChild( el( 'div', 'wp-cortex-kpi-value', t[ 1 ] ) );
			var foot = el( 'div', 'wp-cortex-kpi-foot' );
			if ( t[ 2 ] ) {
				var d = el( 'span', 'wp-cortex-kpi-delta is-' + t[ 2 ].trend );
				d.appendChild( el( 'span', '', t[ 2 ].arrow + ' ' ) );
				d.appendChild( document.createTextNode( t[ 2 ].text ) );
				foot.appendChild( d );
				foot.appendChild( document.createTextNode( ' ' + previous ) );
			} else {
				foot.textContent = t[ 3 ];
			}
			tile.title = t[ 3 ];
			tile.appendChild( foot );
			els.kpis.appendChild( tile );
		} );
	}

	/* ---------- Trend chart ---------- */

	function niceMax( value ) {
		if ( value <= 4 ) {
			return 4;
		}
		var step = Math.pow( 10, Math.floor( Math.log10( value ) ) );
		var nice = [ 1, 2, 2.5, 5, 10 ].map( function ( m ) {
			return m * step;
		} ).filter( function ( m ) {
			return m * 4 >= value;
		} )[ 0 ];
		return nice * 4;
	}

	function bucketLabel( date, unit ) {
		var d = toDate( date );
		if ( 'month' === unit ) {
			return d.toLocaleDateString( undefined, { month: 'short', year: 'numeric' } );
		}
		return d.toLocaleDateString( undefined, { day: 'numeric', month: 'short' } );
	}

	function legend( items ) {
		var wrap = el( 'div', 'wp-cortex-legend' );
		items.forEach( function ( item ) {
			var entry = el( 'span', 'wp-cortex-legend-item' );
			var swatch = el( 'span', 'wp-cortex-swatch' + ( item.line ? ' is-line' : '' ) );
			swatch.style.background = item.color;
			entry.appendChild( swatch );
			entry.appendChild( document.createTextNode( item.label ) );
			wrap.appendChild( entry );
		} );
		return wrap;
	}

	function renderTrend() {
		var trend = report.trend;
		var points = trend.points;
		els.trend.innerHTML = '';
		var series = [
			{ key: 'conversations', label: __( 'Conversations', 'wp-cortex' ), color: COLORS.conversations },
			{ key: 'leads', label: __( 'Leads', 'wp-cortex' ), color: COLORS.leads }
		];
		els.trend.appendChild( legend( series.map( function ( s ) {
			return { label: s.label, color: s.color, line: true };
		} ) ) );

		var width = Math.max( 320, els.trend.clientWidth || 800 );
		var height = 240;
		var pad = { top: 16, right: 40, bottom: 28, left: 40 };
		var w = width - pad.left - pad.right;
		var h = height - pad.top - pad.bottom;
		var max = niceMax( Math.max.apply( null, points.map( function ( p ) {
			return Math.max( p.conversations, p.leads );
		} ).concat( [ 0 ] ) ) );
		var x = function ( i ) {
			return pad.left + ( points.length > 1 ? i * w / ( points.length - 1 ) : w / 2 );
		};
		var y = function ( v ) {
			return pad.top + h - v / max * h;
		};

		var chart = svg( 'svg', { viewBox: '0 0 ' + width + ' ' + height, width: width, height: height, role: 'img', 'aria-label': __( 'Conversations and leads over time', 'wp-cortex' ), class: 'wp-cortex-trend-svg' } );

		for ( var t = 0; t <= 4; t++ ) {
			var value = max * t / 4;
			chart.appendChild( svg( 'line', { x1: pad.left, x2: pad.left + w, y1: y( value ), y2: y( value ), stroke: 0 === t ? COLORS.axis : COLORS.grid, 'stroke-width': 1 } ) );
			var tick = svg( 'text', { x: pad.left - 8, y: y( value ) + 4, 'text-anchor': 'end', class: 'wp-cortex-axis-text' } );
			tick.textContent = number.format( value );
			chart.appendChild( tick );
		}

		var labelEvery = Math.max( 1, Math.ceil( points.length / Math.max( 2, Math.floor( w / 70 ) ) ) );
		points.forEach( function ( p, i ) {
			if ( 0 === i % labelEvery ) {
				var label = svg( 'text', { x: x( i ), y: height - 8, 'text-anchor': 'middle', class: 'wp-cortex-axis-text' } );
				label.textContent = bucketLabel( p.date, trend.unit );
				chart.appendChild( label );
			}
		} );

		// The leads area: a light wash under the series the screen is about.
		if ( points.length > 1 ) {
			var area = 'M' + x( 0 ) + ',' + y( 0 ) + points.map( function ( p, i ) {
				return 'L' + x( i ) + ',' + y( p.leads );
			} ).join( '' ) + 'L' + x( points.length - 1 ) + ',' + y( 0 ) + 'Z';
			chart.appendChild( svg( 'path', { d: area, fill: COLORS.leads, 'fill-opacity': 0.1 } ) );
		}

		series.forEach( function ( s ) {
			var d = points.map( function ( p, i ) {
				return ( i ? 'L' : 'M' ) + x( i ) + ',' + y( p[ s.key ] );
			} ).join( '' );
			chart.appendChild( svg( 'path', { d: d, fill: 'none', stroke: s.color, 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' } ) );
			var last = points.length - 1;
			if ( last >= 0 ) {
				chart.appendChild( svg( 'circle', { cx: x( last ), cy: y( points[ last ][ s.key ] ), r: 4, fill: s.color, stroke: '#fff', 'stroke-width': 2 } ) );
				var end = svg( 'text', { x: x( last ) + 8, y: y( points[ last ][ s.key ] ) + 4, class: 'wp-cortex-axis-text is-strong' } );
				end.textContent = number.format( points[ last ][ s.key ] );
				chart.appendChild( end );
			}
		} );

		// Crosshair and tooltip for the nearest period.
		var cross = svg( 'line', { y1: pad.top, y2: pad.top + h, stroke: COLORS.axis, 'stroke-width': 1, visibility: 'hidden' } );
		var dots = series.map( function ( s ) {
			var dot = svg( 'circle', { r: 4, fill: s.color, stroke: '#fff', 'stroke-width': 2, visibility: 'hidden' } );
			return dot;
		} );
		chart.appendChild( cross );
		dots.forEach( function ( dot ) {
			chart.appendChild( dot );
		} );
		var hit = svg( 'rect', { x: pad.left - 10, y: 0, width: w + 20, height: height, fill: 'transparent' } );
		hit.addEventListener( 'mousemove', function ( e ) {
			var box = chart.getBoundingClientRect();
			var px = ( e.clientX - box.left ) * width / box.width;
			var i = points.length > 1 ? Math.round( ( px - pad.left ) / w * ( points.length - 1 ) ) : 0;
			i = Math.max( 0, Math.min( points.length - 1, i ) );
			var p = points[ i ];
			if ( ! p ) {
				return;
			}
			cross.setAttribute( 'x1', x( i ) );
			cross.setAttribute( 'x2', x( i ) );
			cross.setAttribute( 'visibility', 'visible' );
			series.forEach( function ( s, n ) {
				dots[ n ].setAttribute( 'cx', x( i ) );
				dots[ n ].setAttribute( 'cy', y( p[ s.key ] ) );
				dots[ n ].setAttribute( 'visibility', 'visible' );
			} );
			var title = 'day' === trend.unit ? fmtDate( p.date ) : ( 'week' === trend.unit ? sprintf( __( 'Week of %s', 'wp-cortex' ), fmtDate( p.date ) ) : bucketLabel( p.date, 'month' ) );
			showTip( e, [
				{ text: title },
				{ text: sprintf( __( 'Conversations: %s', 'wp-cortex' ), number.format( p.conversations ) ), color: COLORS.conversations },
				{ text: sprintf( __( 'Leads: %s', 'wp-cortex' ), number.format( p.leads ) ), color: COLORS.leads },
				{ text: sprintf( __( 'Chat to lead rate: %s', 'wp-cortex' ), p.conversations ? fmtPercent( Math.round( 1000 * Math.min( p.leads, p.conversations ) / p.conversations ) / 10 ) : '–' ) }
			] );
		} );
		hit.addEventListener( 'mouseleave', function () {
			cross.setAttribute( 'visibility', 'hidden' );
			dots.forEach( function ( dot ) {
				dot.setAttribute( 'visibility', 'hidden' );
			} );
			hideTip();
		} );
		chart.appendChild( hit );
		els.trend.appendChild( chart );

		// The same figures as a table, for screen readers and exact numbers.
		var details = el( 'details', 'wp-cortex-chart-table' );
		details.appendChild( el( 'summary', '', __( 'Show as table', 'wp-cortex' ) ) );
		details.appendChild( table(
			[ __( 'Period', 'wp-cortex' ), __( 'Conversations', 'wp-cortex' ), __( 'Leads', 'wp-cortex' ) ],
			points.slice().reverse().map( function ( p ) {
				return [ bucketLabel( p.date, trend.unit ), number.format( p.conversations ), number.format( p.leads ) ];
			} )
		) );
		els.trend.appendChild( details );
	}

	/* ---------- Bars and tables ---------- */

	// Horizontal bars of one series: a label, the bar and the figures beside it.
	function bars( rows, options ) {
		var max = Math.max.apply( null, rows.map( function ( r ) {
			return r.value;
		} ).concat( [ 1 ] ) );
		var list = el( 'ul', 'wp-cortex-bars' );
		rows.forEach( function ( r ) {
			var li = el( 'li', 'wp-cortex-bar-row' );
			li.appendChild( el( 'span', 'wp-cortex-bar-label', r.label ) );
			var track = el( 'span', 'wp-cortex-bar-track' );
			var bar = el( 'span', 'wp-cortex-bar' );
			bar.style.width = ( r.value ? Math.max( 1, 100 * r.value / max ) : 0 ) + '%';
			bar.style.background = r.color || options.color || COLORS.bar;
			track.appendChild( bar );
			li.appendChild( track );
			li.appendChild( el( 'span', 'wp-cortex-bar-value', r.text ) );
			withTip( li, r.tip );
			list.appendChild( li );
		} );
		return list;
	}

	function table( headers, rows, numeric ) {
		var t = el( 'table', 'widefat striped wp-cortex-mini-table' );
		var tr = el( 'tr' );
		headers.forEach( function ( h, i ) {
			var th = el( 'th', i && false !== numeric ? 'num' : '', h );
			th.scope = 'col';
			tr.appendChild( th );
		} );
		var thead = el( 'thead' );
		thead.appendChild( tr );
		t.appendChild( thead );
		var tbody = el( 'tbody' );
		rows.forEach( function ( row ) {
			var r = el( 'tr' );
			row.forEach( function ( cell, i ) {
				var td = el( 'td', i && false !== numeric ? 'num' : '' );
				if ( cell instanceof Node ) {
					td.appendChild( cell );
				} else {
					td.textContent = cell;
				}
				r.appendChild( td );
			} );
			tbody.appendChild( r );
		} );
		t.appendChild( tbody );
		return t;
	}

	function empty( text ) {
		return el( 'p', 'wp-cortex-muted wp-cortex-chart-empty', text || __( 'No data for this period yet.', 'wp-cortex' ) );
	}

	function renderChannels() {
		var rows = report.channels;
		els.channels.innerHTML = '';
		if ( ! rows.length ) {
			els.channels.appendChild( empty() );
			return;
		}
		els.channels.appendChild( bars( rows.map( function ( r ) {
			return {
				label: channelLabel( r.channel ),
				value: r.leads,
				text: sprintf( _n( '%s lead', '%s leads', r.leads, 'wp-cortex' ), number.format( r.leads ) ) + ' · ' + fmtPercent( r.rate ),
				tip: [
					{ text: channelLabel( r.channel ) },
					{ text: sprintf( __( 'Leads: %s', 'wp-cortex' ), number.format( r.leads ) ) },
					{ text: sprintf( __( 'Conversations: %s', 'wp-cortex' ), number.format( r.conversations ) ) },
					{ text: sprintf( __( 'Chat to lead rate: %s', 'wp-cortex' ), fmtPercent( r.rate ) ) }
				]
			};
		} ), {} ) );
		els.channels.appendChild( el( 'p', 'description', __( 'Leads per channel, with the share of conversations that became leads.', 'wp-cortex' ) ) );
	}

	function renderQuality() {
		var ratings = report.ratings;
		var total = Object.keys( ratings ).reduce( function ( sum, key ) {
			return sum + ratings[ key ];
		}, 0 );
		els.quality.innerHTML = '';
		if ( ! total ) {
			els.quality.appendChild( empty() );
			return;
		}
		var parts = [
			{ key: 'hot', label: __( 'Hot', 'wp-cortex' ) },
			{ key: 'warm', label: __( 'Warm', 'wp-cortex' ) },
			{ key: 'cold', label: __( 'Cold', 'wp-cortex' ) },
			{ key: '', color: 'unrated', label: __( 'Not rated', 'wp-cortex' ) }
		].filter( function ( p ) {
			return ratings[ p.key ];
		} );
		els.quality.appendChild( legend( parts.map( function ( p ) {
			return { label: p.label + ' ' + number.format( ratings[ p.key ] ), color: COLORS[ p.color || p.key ] };
		} ) ) );
		var stack = el( 'div', 'wp-cortex-stack' );
		parts.forEach( function ( p ) {
			var seg = el( 'span', 'wp-cortex-stack-seg' );
			seg.style.flexGrow = ratings[ p.key ];
			seg.style.background = COLORS[ p.color || p.key ];
			withTip( seg, [ { text: p.label }, { text: sprintf( __( '%1$s of %2$s leads (%3$s)', 'wp-cortex' ), number.format( ratings[ p.key ] ), number.format( total ), fmtPercent( Math.round( 1000 * ratings[ p.key ] / total ) / 10 ) ) } ] );
			stack.appendChild( seg );
		} );
		els.quality.appendChild( stack );

		var intents = Object.keys( report.intents ).filter( function ( key ) {
			return key && report.intents[ key ];
		} ).sort( function ( a, b ) {
			return report.intents[ b ] - report.intents[ a ];
		} );
		if ( intents.length ) {
			els.quality.appendChild( el( 'h3', '', __( 'What leads want', 'wp-cortex' ) ) );
			els.quality.appendChild( bars( intents.map( function ( key ) {
				var count = report.intents[ key ];
				return {
					label: ( cfg.intents || {} )[ key ] || key,
					value: count,
					text: number.format( count ),
					tip: [ { text: ( cfg.intents || {} )[ key ] || key }, { text: sprintf( _n( '%s lead', '%s leads', count, 'wp-cortex' ), number.format( count ) ) } ]
				};
			} ), {} ) );
		}
		if ( ratings[ '' ] ) {
			var hint = el( 'p', 'description', sprintf( _n( '%s lead is not rated yet.', '%s leads are not rated yet.', ratings[ '' ], 'wp-cortex' ), number.format( ratings[ '' ] ) ) + ' ' );
			var rate = el( 'a', '', __( 'Rate them with AI', 'wp-cortex' ) );
			rate.href = '#';
			rate.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				rateAll();
			} );
			hint.appendChild( rate );
			els.quality.appendChild( hint );
		}
	}

	// A count with a thin bar behind it, scaled to the largest value of the column.
	function inlineBar( value, max ) {
		var wrap = el( 'span', 'wp-cortex-inline-bar' );
		var bar = el( 'span', 'wp-cortex-inline-bar-fill' );
		bar.style.width = ( max ? 100 * value / max : 0 ) + '%';
		wrap.appendChild( bar );
		wrap.appendChild( el( 'span', 'wp-cortex-inline-bar-text', number.format( value ) ) );
		return wrap;
	}

	function renderCampaigns() {
		var rows = report.campaigns;
		els.campaigns.innerHTML = '';
		if ( ! rows.length ) {
			els.campaigns.appendChild( empty() );
			return;
		}
		var max = Math.max.apply( null, rows.map( function ( r ) {
			return r.leads;
		} ) );
		els.campaigns.appendChild( table(
			[ __( 'Source / medium · campaign', 'wp-cortex' ), __( 'Leads', 'wp-cortex' ), __( 'Chats', 'wp-cortex' ), __( 'Rate', 'wp-cortex' ) ],
			rows.map( function ( r ) {
				var label = el( 'span' );
				label.appendChild( el( 'strong', '', r.source + ' / ' + r.medium ) );
				if ( r.campaign ) {
					label.appendChild( el( 'span', 'wp-cortex-block wp-cortex-muted', r.campaign ) );
				}
				return [ label, inlineBar( r.leads, max ), number.format( r.conversations ), fmtPercent( r.rate ) ];
			} )
		) );
	}

	function renderLanding() {
		var rows = report.landing;
		els.landing.innerHTML = '';
		if ( ! rows.length ) {
			els.landing.appendChild( empty() );
			return;
		}
		var max = Math.max.apply( null, rows.map( function ( r ) {
			return r.leads;
		} ) );
		els.landing.appendChild( table(
			[ __( 'Landing page', 'wp-cortex' ), __( 'Leads', 'wp-cortex' ), __( 'Chats', 'wp-cortex' ), __( 'Rate', 'wp-cortex' ) ],
			rows.map( function ( r ) {
				var a = el( 'a', 'wp-cortex-path', r.path );
				a.href = ( cfg.homeUrl || '' ).replace( /\/$/, '' ) + r.path;
				a.target = '_blank';
				a.rel = 'noopener';
				return [ a, inlineBar( r.leads, max ), number.format( r.conversations ), fmtPercent( r.rate ) ];
			} )
		) );
	}

	function loadReport() {
		els.kpis.classList.add( 'is-loading' );
		return apiFetch( { path: PATH + '/report?days=' + state.days + ( state.channel ? '&channel=' + encodeURIComponent( state.channel ) : '' ) } ).then( function ( res ) {
			report = res;
			els.kpis.classList.remove( 'is-loading' );
			renderReport();
		} ).catch( fail );
	}

	function renderReport() {
		if ( ! report ) {
			return;
		}
		renderKpis();
		renderTrend();
		renderQuality();
		if ( ATTRIBUTION ) {
			renderChannels();
			renderCampaigns();
			renderLanding();
		}
	}

	/* ---------- Leads list ---------- */

	var COLUMNS = [
		{ key: 'lead', label: __( 'Lead', 'wp-cortex' ), cls: 'column-primary' },
		{ key: 'lead_at', label: __( 'Received', 'wp-cortex' ), sort: 'lead_at', cls: 'wp-cortex-col-date' },
		{ key: 'source', label: __( 'Source', 'wp-cortex' ), sort: 'channel' },
		{ key: 'landing', label: __( 'Landing page', 'wp-cortex' ) },
		{ key: 'interest', label: __( 'Interest', 'wp-cortex' ) },
		{ key: 'rating', label: __( 'Rating', 'wp-cortex' ), sort: 'score', cls: 'wp-cortex-col-rating' },
		{ key: 'status', label: __( 'Status', 'wp-cortex' ), sort: 'status', cls: 'wp-cortex-col-lead-status' }
	].filter( function ( c ) {
		return ATTRIBUTION || ( 'source' !== c.key && 'landing' !== c.key );
	} );

	function renderHead() {
		els.head.innerHTML = '';
		COLUMNS.forEach( function ( c ) {
			var th = el( 'th', ( c.cls || '' ) + ( c.sort ? ' sortable ' + ( state.orderby === c.sort ? 'sorted ' + state.order : 'desc' ) : '' ) );
			th.scope = 'col';
			if ( ! c.sort ) {
				th.textContent = c.label;
			} else {
				var a = el( 'a' );
				a.href = '#';
				a.appendChild( el( 'span', '', c.label ) );
				a.appendChild( el( 'span', 'sorting-indicators' ) );
				a.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					state.order = state.orderby === c.sort && 'desc' === state.order ? 'asc' : 'desc';
					state.orderby = c.sort;
					state.page = 1;
					loadLeads();
				} );
				th.appendChild( a );
			}
			els.head.appendChild( th );
		} );
	}

	function renderStatuses() {
		var counts = state.counts || {};
		var total = Object.keys( counts ).reduce( function ( sum, key ) {
			return sum + counts[ key ];
		}, 0 );
		var filters = [ [ '', __( 'All', 'wp-cortex' ), total ] ].concat( Object.keys( cfg.statuses || {} ).map( function ( key ) {
			return [ key, cfg.statuses[ key ], counts[ key ] || 0 ];
		} ) );
		els.statuses.innerHTML = '';
		filters.forEach( function ( f, i ) {
			var li = el( 'li' );
			var a = el( 'a', state.status === f[ 0 ] ? 'current' : '', f[ 1 ] + ' ' );
			a.href = '#';
			a.appendChild( el( 'span', 'count', '(' + number.format( f[ 2 ] ) + ')' ) );
			a.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				state.status = f[ 0 ];
				state.page = 1;
				loadLeads();
			} );
			li.appendChild( a );
			if ( i < filters.length - 1 ) {
				li.appendChild( document.createTextNode( ' | ' ) );
			}
			els.statuses.appendChild( li );
		} );
	}

	function renderPages() {
		els.pages.innerHTML = '';
		els.pages.appendChild( el( 'span', 'displaying-num', sprintf( _n( '%s lead', '%s leads', state.total, 'wp-cortex' ), number.format( state.total ) ) ) );
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
				loadLeads();
			} );
			wrap.appendChild( b );
		}
		pageButton( __( 'Previous page', 'wp-cortex' ), '‹', state.page - 1, state.page <= 1 );
		wrap.appendChild( el( 'span', 'paging-input', ' ' + sprintf( __( '%1$d of %2$d', 'wp-cortex' ), state.page, state.pages ) + ' ' ) );
		pageButton( __( 'Next page', 'wp-cortex' ), '›', state.page + 1, state.page >= state.pages );
		els.pages.appendChild( wrap );
	}

	function ratingBadge( lead ) {
		var labels = { hot: __( 'Hot', 'wp-cortex' ), warm: __( 'Warm', 'wp-cortex' ), cold: __( 'Cold', 'wp-cortex' ) };
		var badge = el( 'span', 'wp-cortex-rating is-' + lead.lead_rating );
		badge.appendChild( el( 'span', 'wp-cortex-rating-dot' ) );
		badge.appendChild( document.createTextNode( labels[ lead.lead_rating ] + ' · ' + lead.lead_score ) );
		var q = lead.qualification || {};
		if ( q.reason ) {
			badge.title = q.reason;
		}
		return badge;
	}

	function rateButton( lead, td ) {
		var b = el( 'button', 'button button-small', lead.lead_rating ? __( 'Rate again', 'wp-cortex' ) : __( 'Rate with AI', 'wp-cortex' ) );
		b.type = 'button';
		b.addEventListener( 'click', function () {
			b.disabled = true;
			b.textContent = __( 'Rating…', 'wp-cortex' );
			apiFetch( { path: PATH + '/' + lead.id + '/rate', method: 'POST' } ).then( function () {
				return Promise.all( [ loadLeads(), loadReport() ] );
			} ).catch( function ( err ) {
				fail( err );
				b.disabled = false;
				b.textContent = __( 'Rate with AI', 'wp-cortex' );
			} );
		} );
		td.appendChild( b );
	}

	function statusSelect( lead ) {
		var select = el( 'select', 'wp-cortex-lead-status is-' + lead.lead_status );
		select.setAttribute( 'aria-label', __( 'Lead status', 'wp-cortex' ) );
		Object.keys( cfg.statuses || {} ).forEach( function ( key ) {
			var o = el( 'option', '', cfg.statuses[ key ] );
			o.value = key;
			o.selected = key === lead.lead_status;
			select.appendChild( o );
		} );
		select.addEventListener( 'change', function () {
			var value = select.value;
			select.disabled = true;
			apiFetch( { path: '/wp-cortex/v1/visitor-chats/' + lead.id, method: 'PATCH', data: { lead_status: value } } ).then( function () {
				state.counts[ lead.lead_status ] = Math.max( 0, ( state.counts[ lead.lead_status ] || 0 ) - 1 );
				state.counts[ value ] = ( state.counts[ value ] || 0 ) + 1;
				lead.lead_status = value;
				select.className = 'wp-cortex-lead-status is-' + value;
				select.disabled = false;
				renderStatuses();
				updateMenuCount( state.counts.new || 0 );
			} ).catch( function ( err ) {
				fail( err );
				select.value = lead.lead_status;
				select.disabled = false;
			} );
		} );
		return select;
	}

	function updateMenuCount( count ) {
		var a = document.querySelector( '#adminmenu .wp-submenu a[href$="page=wp-cortex-leads"]' );
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
		bubble.querySelector( '.pending-count' ).textContent = number.format( count );
	}

	function contactName( c ) {
		return [ c.first_name, c.last_name ].filter( Boolean ).join( ' ' );
	}

	function sourceCell( lead ) {
		var source = el( 'td' );
		source.appendChild( el( 'span', 'wp-cortex-badge wp-cortex-channel', channelLabel( lead.channel || 'unknown' ) ) );
		if ( lead.source ) {
			source.appendChild( el( 'span', 'wp-cortex-block wp-cortex-muted', lead.source + ' / ' + lead.medium ) );
		}
		if ( lead.campaign ) {
			source.appendChild( el( 'span', 'wp-cortex-block', lead.campaign ) );
		}
		if ( lead.first_channel && lead.first_channel !== lead.channel ) {
			source.appendChild( el( 'span', 'wp-cortex-block wp-cortex-muted', sprintf( __( 'First visit: %s', 'wp-cortex' ), channelLabel( lead.first_channel ) ) ) );
		}
		return source;
	}

	function landingCell( lead ) {
		var landing = el( 'td' );
		landing.appendChild( el( 'span', 'wp-cortex-path', lead.landing_path || '–' ) );
		if ( lead.visits > 1 ) {
			landing.appendChild( el( 'span', 'wp-cortex-block wp-cortex-muted', sprintf( _n( '%d visit', '%d visits', lead.visits, 'wp-cortex' ), lead.visits ) ) );
		}
		return landing;
	}

	function renderRow( lead ) {
		var tr = el( 'tr' );
		var c = lead.contact || {};
		var q = lead.qualification || {};

		var main = el( 'td', 'column-primary' );
		main.appendChild( chatLink( lead.id, contactName( c ) || c.email || c.phone || sprintf( __( 'Lead #%d', 'wp-cortex' ), lead.id ), 'row-title' ) );
		[ c.email, c.phone, [ q.role, c.company || q.company ].filter( Boolean ).join( ', ' ) ].filter( Boolean ).forEach( function ( v ) {
			main.appendChild( el( 'span', 'wp-cortex-block wp-cortex-muted', v ) );
		} );
		tr.appendChild( main );

		var date = el( 'td', 'wp-cortex-col-date', fmtDate( lead.lead_at, true ) );
		tr.appendChild( date );

		if ( ATTRIBUTION ) {
			tr.appendChild( sourceCell( lead ) );
			tr.appendChild( landingCell( lead ) );
		}

		var interest = el( 'td' );
		if ( lead.lead_intent ) {
			interest.appendChild( el( 'strong', 'wp-cortex-block', ( cfg.intents || {} )[ lead.lead_intent ] || lead.lead_intent ) );
		}
		var want = q.interest || c.request;
		interest.appendChild( el( 'span', 'wp-cortex-block' + ( want ? '' : ' wp-cortex-muted' ), want || '–' ) );
		if ( q.budget || q.timeline ) {
			interest.appendChild( el( 'span', 'wp-cortex-block wp-cortex-muted', [ q.budget, q.timeline ].filter( Boolean ).join( ' · ' ) ) );
		}
		tr.appendChild( interest );

		var rating = el( 'td', 'wp-cortex-col-rating' );
		if ( lead.lead_rating ) {
			rating.appendChild( ratingBadge( lead ) );
			if ( lead.qualification_stale ) {
				rating.appendChild( el( 'span', 'wp-cortex-block wp-cortex-muted', __( 'Conversation continued', 'wp-cortex' ) ) );
				rateButton( lead, rating );
			}
		} else {
			rateButton( lead, rating );
		}
		tr.appendChild( rating );

		var status = el( 'td', 'wp-cortex-col-lead-status' );
		status.appendChild( statusSelect( lead ) );
		tr.appendChild( status );

		return tr;
	}

	function renderLeads() {
		renderHead();
		renderStatuses();
		renderPages();
		els.rows.innerHTML = '';
		if ( ! state.leads.length ) {
			var tr = el( 'tr' );
			var td = el( 'td', '', state.search || state.status || state.rating || state.channel ? __( 'No leads match.', 'wp-cortex' ) : __( 'No leads in this period yet. A visitor becomes a lead when they leave an email address, phone number, postal address or website in the visitor chat.', 'wp-cortex' ) );
			td.colSpan = COLUMNS.length;
			tr.appendChild( td );
			els.rows.appendChild( tr );
			return;
		}
		state.leads.forEach( function ( lead ) {
			els.rows.appendChild( renderRow( lead ) );
		} );
	}

	function loadLeads() {
		return apiFetch( { path: PATH + query( { page: state.page, per_page: PER_PAGE } ) } ).then( function ( res ) {
			state.leads = res.leads || [];
			state.total = res.total || 0;
			state.pages = res.pages || 1;
			state.counts = res.counts || {};
			if ( state.page > state.pages ) {
				state.page = state.pages;
				return loadLeads();
			}
			renderLeads();
		} ).catch( fail );
	}

	/* ---------- AI rating of every unrated lead ---------- */

	var rating = false;

	function rateAll() {
		if ( rating ) {
			return;
		}
		rating = true;
		var done = 0;
		els.rateAll.disabled = true;
		function next() {
			els.rateAll.textContent = sprintf( __( 'Rating leads… %d done', 'wp-cortex' ), done );
			return apiFetch( { path: PATH + '/rate-next', method: 'POST' } ).then( function ( res ) {
				if ( res.rated ) {
					done++;
				}
				if ( res.rated && res.remaining ) {
					return next();
				}
			} );
		}
		next().then( function () {
			notify( done ? sprintf( _n( '%d lead rated.', '%d leads rated.', done, 'wp-cortex' ), done ) : __( 'Every lead is already rated.', 'wp-cortex' ), false );
		} ).catch( fail ).then( function () {
			rating = false;
			els.rateAll.disabled = false;
			els.rateAll.textContent = __( 'Rate unrated leads with AI', 'wp-cortex' );
			loadLeads();
			loadReport();
		} );
	}

	/* ---------- CSV export ---------- */

	// Values starting with = + - @ are prefixed so spreadsheets do not run them as formulas.
	function csvCell( value ) {
		var text = null === value || undefined === value ? '' : String( value );
		if ( /^[=+\-@\t\r]/.test( text ) ) {
			text = '\'' + text;
		}
		return /[",\r\n]/.test( text ) ? '"' + text.replace( /"/g, '""' ) + '"' : text;
	}

	function exportCsv() {
		els.exportBtn.disabled = true;
		apiFetch( { path: PATH + '/export' + query() } ).then( function ( res ) {
			var leads = res.leads || [];
			var utm = cfg.utmParams || [];
			var clicks = cfg.clickIds || [];
			var extra = [];
			leads.forEach( function ( l ) {
				var a = l.attribution || {};
				[ a.last, a.first ].forEach( function ( t ) {
					Object.keys( ( t && t.extra ) || {} ).forEach( function ( key ) {
						if ( -1 === extra.indexOf( key ) ) {
							extra.push( key );
						}
					} );
				} );
			} );
			extra.sort();
			var params = utm.concat( clicks, extra );

			// The latest visit (that led to the chat) and the first one: channel, source,
			// landing page, referrer and every URL parameter as the visitor's link had it.
			var touchHeaders = function ( prefix ) {
				return [ prefix + 'Channel', prefix + 'Source', prefix + 'Medium', prefix + 'Landing page', prefix + 'Referrer', prefix + 'Visit (UTC)' ].concat( params.map( function ( key ) {
					return prefix + key;
				} ) );
			};
			var touchCells = function ( t ) {
				if ( ! t ) {
					return touchHeaders( '' ).map( function () {
						return '';
					} );
				}
				return [ channelLabel( t.channel || 'unknown' ), t.source, t.medium, t.landing, t.referrer, t.at ].concat( params.map( function ( key ) {
					return ( t.utm || {} )[ key ] || ( t.click_ids || {} )[ key ] || ( t.extra || {} )[ key ] || '';
				} ) );
			};

			var headers = [ 'Lead ID', 'Received (UTC)', 'First name', 'Last name', 'Email', 'Phone', 'Address', 'Company', 'Website', 'Request', 'Status', 'Rating', 'Score', 'Intent', 'Interest', 'Role', 'Budget', 'Timeline', 'Next step' ]
				.concat( touchHeaders( '' ), touchHeaders( 'First visit ' ), [ 'Visits', 'Consent', 'Conversation started (UTC)', 'Messages', 'Start page', 'Note', 'Conversation URL' ] );
			var lines = [ headers.map( csvCell ).join( ',' ) ];
			leads.forEach( function ( l ) {
				var c = l.contact || {};
				var r = l.rating || {};
				var a = l.attribution || {};
				lines.push( [
					l.id, l.lead_at, c.first_name, c.last_name, c.email, c.phone, ( c.address || '' ).replace( /\n/g, ' ' ), c.company || r.company, ( c.website || '' ).replace( /\n/g, ' ' ), c.request,
					( cfg.statuses || {} )[ l.status ] || l.status, r.rating, l.rating ? r.score : '', ( cfg.intents || {} )[ r.intent ] || r.intent,
					r.interest, r.role, r.budget, r.timeline, r.next_step
				].concat( touchCells( a.last ), touchCells( a.first ), [
					a.visits || '', a.consent, l.started_at, l.message_count, l.start_page, l.note, l.conversation_url
				] ).map( csvCell ).join( ',' ) );
			} );
			var blob = new Blob( [ '﻿' + lines.join( '\r\n' ) + '\r\n' ], { type: 'text/csv;charset=utf-8' } );
			var a = el( 'a' );
			a.href = URL.createObjectURL( blob );
			a.download = ( cfg.siteName || 'site' ) + '-leads-' + new Date().toISOString().slice( 0, 10 ) + '.csv';
			document.body.appendChild( a );
			a.click();
			document.body.removeChild( a );
			window.setTimeout( function () {
				URL.revokeObjectURL( a.href );
			}, 1000 );
			if ( res.truncated ) {
				notify( sprintf( __( 'Only the first %1$s of %2$s leads were exported. Narrow the filters to export the rest.', 'wp-cortex' ), number.format( ( res.leads || [] ).length ), number.format( res.total ) ), true );
			}
		} ).catch( fail ).then( function () {
			els.exportBtn.disabled = false;
		} );
	}

	/* ---------- Filters ---------- */

	function renderChannelSelect() {
		els.channel.innerHTML = '';
		var all = el( 'option', '', __( 'All channels', 'wp-cortex' ) );
		all.value = '';
		els.channel.appendChild( all );
		CHANNELS.forEach( function ( key ) {
			var o = el( 'option', '', channelLabel( key ) );
			o.value = key;
			els.channel.appendChild( o );
		} );
		els.channel.value = state.channel;
	}

	function reload() {
		state.page = 1;
		loadReport();
		loadLeads();
	}

	Array.prototype.forEach.call( els.period.querySelectorAll( 'button' ), function ( b ) {
		b.addEventListener( 'click', function () {
			state.days = parseInt( b.getAttribute( 'data-days' ), 10 ) || 0;
			Array.prototype.forEach.call( els.period.querySelectorAll( 'button' ), function ( other ) {
				other.setAttribute( 'aria-pressed', other === b ? 'true' : 'false' );
			} );
			reload();
		} );
	} );
	if ( els.channel ) {
		els.channel.addEventListener( 'change', function () {
			state.channel = els.channel.value;
			reload();
		} );
	}
	els.rating.addEventListener( 'change', function () {
		state.rating = els.rating.value;
		state.page = 1;
		loadLeads();
	} );
	els.search.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		state.search = els.searchInput.value.trim();
		state.page = 1;
		loadLeads();
	} );
	els.rateAll.addEventListener( 'click', rateAll );
	els.exportBtn.addEventListener( 'click', exportCsv );

	var resizeTimer = null;
	window.addEventListener( 'resize', function () {
		window.clearTimeout( resizeTimer );
		resizeTimer = window.setTimeout( function () {
			if ( report ) {
				renderTrend();
			}
		}, 150 );
	} );

	if ( els.channel ) {
		renderChannelSelect();
	}
	reload();
}() );
