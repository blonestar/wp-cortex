/**
 * Settings > Skills tab: lists, adds, edits, toggles and deletes chat skills.
 */
( function () {
	'use strict';

	var apiFetch = wp.apiFetch;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var PATH = '/wp-cortex/v1/skills';

	var root = document.getElementById( 'wp-cortex-skills' );
	if ( ! root || ! document.getElementById( 'wp-cortex-skill-form' ) ) {
		return;
	}

	var els = {
		add: document.getElementById( 'wp-cortex-skill-add' ),
		notice: document.getElementById( 'wp-cortex-skills-notice' ),
		form: document.getElementById( 'wp-cortex-skill-form' ),
		editor: document.getElementById( 'wp-cortex-skill-editor' ),
		formTitle: document.getElementById( 'wp-cortex-skill-form-title' ),
		cancel: document.getElementById( 'wp-cortex-skill-cancel' ),
		list: document.getElementById( 'wp-cortex-skills-list' )
	};

	var skills = [];

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

	/* ---------- List ---------- */

	function rowAction( label, onClick, cls ) {
		var a = el( 'button', 'button-link' + ( cls ? ' ' + cls : '' ), label );
		a.type = 'button';
		a.addEventListener( 'click', onClick );
		return a;
	}

	function renderRow( skill ) {
		var tr = el( 'tr', skill.active ? '' : 'wp-cortex-skill-inactive' );

		var main = el( 'td', 'column-primary' );
		main.appendChild( el( 'strong', 'wp-cortex-skill-name', skill.name ) );
		main.appendChild( el( 'p', 'wp-cortex-skill-description', skill.description ) );
		var details = el( 'details', 'wp-cortex-skill-details' );
		details.appendChild( el( 'summary', '', __( 'Instructions', 'wp-cortex' ) ) );
		details.appendChild( el( 'pre', 'wp-cortex-skill-instructions', skill.instructions ) );
		main.appendChild( details );

		var actions = el( 'div', 'row-actions visible' );
		actions.appendChild( rowAction( __( 'Edit', 'wp-cortex' ), function () {
			openForm( skill );
		} ) );
		actions.appendChild( document.createTextNode( ' | ' ) );
		actions.appendChild( rowAction( skill.active ? __( 'Deactivate', 'wp-cortex' ) : __( 'Activate', 'wp-cortex' ), function () {
			save( skill.id, { active: ! skill.active } );
		} ) );
		actions.appendChild( document.createTextNode( ' | ' ) );
		actions.appendChild( rowAction( __( 'Delete', 'wp-cortex' ), function () {
			remove( skill );
		}, 'wp-cortex-delete' ) );
		main.appendChild( actions );
		tr.appendChild( main );

		tr.appendChild( el( 'td', '', 'agent' === skill.source ? __( 'Assistant', 'wp-cortex' ) : __( 'User', 'wp-cortex' ) ) );
		tr.appendChild( el( 'td', '', String( skill.use_count || 0 ) ) );
		tr.appendChild( el( 'td', '', fmtDate( skill.last_used_at ) ) );

		var status = el( 'td' );
		status.appendChild( el( 'span', 'wp-cortex-badge ' + ( skill.active ? 'wp-cortex-badge-ok' : '' ), skill.active ? __( 'Active', 'wp-cortex' ) : __( 'Inactive', 'wp-cortex' ) ) );
		tr.appendChild( status );

		return tr;
	}

	function render() {
		els.list.innerHTML = '';
		if ( ! skills.length ) {
			var tr = el( 'tr' );
			var td = el( 'td', '', __( 'No skills yet. Add one, or ask the assistant in the chat to remember how it did something.', 'wp-cortex' ) );
			td.colSpan = 5;
			tr.appendChild( td );
			els.list.appendChild( tr );
			return;
		}
		skills.forEach( function ( skill ) {
			els.list.appendChild( renderRow( skill ) );
		} );
	}

	function load() {
		return apiFetch( { path: PATH } ).then( function ( res ) {
			skills = res.skills || [];
			render();
		} ).catch( fail );
	}

	/* ---------- Form ---------- */

	function openForm( skill ) {
		var f = els.form.elements;
		f.id.value = skill ? skill.id : 0;
		f.name.value = skill ? skill.name : '';
		f.description.value = skill ? skill.description : '';
		f.instructions.value = skill ? skill.instructions : '';
		f.active.checked = skill ? !! skill.active : true;
		els.formTitle.textContent = skill ? sprintf( __( 'Edit skill “%s”', 'wp-cortex' ), skill.name ) : __( 'Add skill', 'wp-cortex' );
		els.editor.hidden = false;
		f.name.focus();
	}

	function closeForm() {
		els.editor.hidden = true;
		els.form.reset();
	}

	function save( id, data ) {
		return apiFetch( {
			path: id ? PATH + '/' + id : PATH,
			method: id ? 'PUT' : 'POST',
			data: data
		} ).then( function () {
			notify( __( 'Skill saved.', 'wp-cortex' ), false );
			return load();
		} ).catch( function ( err ) {
			fail( err );
			throw err;
		} );
	}

	function remove( skill ) {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( sprintf( __( 'Delete the skill “%s”?', 'wp-cortex' ), skill.name ) ) ) {
			return;
		}
		apiFetch( { path: PATH + '/' + skill.id, method: 'DELETE' } ).then( function () {
			notify( __( 'Skill deleted.', 'wp-cortex' ), false );
			return load();
		} ).catch( fail );
	}

	els.add.addEventListener( 'click', function () {
		openForm( null );
	} );
	els.cancel.addEventListener( 'click', closeForm );
	els.form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var f = els.form.elements;
		save( parseInt( f.id.value, 10 ) || 0, {
			name: f.name.value,
			description: f.description.value,
			instructions: f.instructions.value,
			active: f.active.checked
		} ).then( closeForm, function () {} );
	} );

	load();
}() );
