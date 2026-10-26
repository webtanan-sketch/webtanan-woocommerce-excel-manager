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
			if ( ! errorSummary.hasAttribute( 'tabindex' ) ) {
				errorSummary.setAttribute( 'tabindex', '-1' );
			}
			errorSummary.focus();
		}

		var fileInput = document.querySelector( '#wem-file[type="file"]' );
		var fileName = document.getElementById( 'wem-file-name' );
		if ( fileInput && fileName ) {
			fileInput.addEventListener( 'change', function () {
				fileName.textContent = fileInput.files && fileInput.files.length
					? fileInput.files[ 0 ].name
					: 'هنوز فایلی انتخاب نشده است.';
			} );
		}

		document.querySelectorAll( '[data-wem-condition-select]' ).forEach( function ( select ) {
			updateConditionFields( select );
			select.addEventListener( 'change', function () {
				updateConditionFields( select );
			} );
		} );

		document.querySelectorAll( '[data-wem-sale-action]' ).forEach( function ( select ) {
			var form = select.closest( 'form' );
			function updateSaleFields() {
				var sale = select.value === 'create_sale_percent';
				var target = form.querySelector( '[name="price_target"]' );
				if ( sale ) { target.value = 'sale'; }
				target.disabled = sale;
				form.querySelectorAll( '[data-wem-sale-field]' ).forEach( function ( field ) {
					field.hidden = ! sale;
					field.querySelectorAll( 'input' ).forEach( function ( input ) { input.disabled = ! sale; } );
				} );
			}
			updateSaleFields();
			select.addEventListener( 'change', updateSaleFields );
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
