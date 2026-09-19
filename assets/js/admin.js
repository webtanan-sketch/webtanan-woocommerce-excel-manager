( function () {
	'use strict';

	function updateConditionFields( select ) {
		var value = select.value;
		document.querySelectorAll( '[data-wem-condition]' ).forEach( function ( field ) {
			var visible = field.getAttribute( 'data-wem-condition' ) === value;
			field.hidden = ! visible;
			field.setAttribute( 'aria-hidden', visible ? 'false' : 'true' );
			field.querySelectorAll( 'input, select, textarea' ).forEach( function ( control ) {
				control.disabled = ! visible;
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var errorSummary = document.querySelector( '.wem-alert--error[role="alert"]' );
		if ( errorSummary ) {
			errorSummary.focus();
		}

		document.querySelectorAll( '[data-wem-condition-select]' ).forEach( function ( select ) {
			updateConditionFields( select );
			select.addEventListener( 'change', function () {
				updateConditionFields( select );
			} );
		} );

		document.querySelectorAll( 'form[data-wem-confirm]' ).forEach( function ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				if ( ! window.confirm( form.getAttribute( 'data-wem-confirm' ) ) ) {
					event.preventDefault();
				}
			} );
		} );
	} );
}() );
