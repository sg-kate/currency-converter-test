/**
 * The admin conversion widget.
 *
 * No arithmetic happens here. Rates are DECIMAL(24,12) and JavaScript has one number type,
 * an IEEE-754 double, so a conversion done in the browser would disagree with the module's
 * own `convert()` in the last decimal places — and disagreeing with the thing it is meant to
 * demonstrate is worse than being slower. Every answer on this page came from PHP.
 *
 * No jQuery either: `fetch` and `FormData` are in every browser WordPress 6.0 supports, and
 * a dependency on `jquery` is a dependency to keep working.
 *
 * @package Currency_Converter
 */

( function () {
	'use strict';

	var settings = window.currencyConverterAdmin;

	if ( ! settings || ! settings.ajaxUrl ) {
		return;
	}

	var form = document.querySelector( '.cc-widget-form' );

	if ( ! form ) {
		return;
	}

	var result = form.querySelector( '.cc-widget-result' );
	var button = form.querySelector( 'button[type="submit"]' );

	/**
	 * Show a message in the live region.
	 *
	 * `textContent` and never `innerHTML`. Server messages name the currency code the user
	 * typed — `UnknownCurrencyException` quotes it back — so a message can contain whatever
	 * was in the form field. Assigning it as text makes that a string on the page; assigning
	 * it as HTML would make the field an injection point into an admin screen.
	 *
	 * @param {string} message The text to show.
	 * @param {string} state   'ok', 'error' or 'working'.
	 */
	function show( message, state ) {
		result.textContent = message;
		result.className = 'cc-widget-result cc-' + state;
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();

		var body = new FormData();

		body.append( 'action', settings.action );
		body.append( 'nonce', settings.nonce );
		body.append( 'amount', form.querySelector( '#cc-amount' ).value );
		body.append( 'from', form.querySelector( '#cc-from' ).value );
		body.append( 'to', form.querySelector( '#cc-to' ).value );

		button.disabled = true;
		show( settings.i18n.working, 'working' );

		fetch( settings.ajaxUrl, {
			method: 'POST',
			body: body,
			// admin-ajax.php identifies the user by cookie; without this the request is
			// logged out and check_ajax_referer fails on a nonce that was correct.
			credentials: 'same-origin',
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( payload && payload.success && payload.data ) {
					show(
						payload.data.amount +
							' ' +
							payload.data.from +
							' = ' +
							payload.data.formatted +
							'  (1 ' +
							payload.data.from +
							' = ' +
							payload.data.rate +
							' ' +
							payload.data.to +
							')',
						'ok'
					);

					return;
				}

				// The server's own words where it gave any: they say which currency is
				// unknown or which pair has no stored rate, which the generic string cannot.
				show(
					payload && payload.data && payload.data.message
						? payload.data.message
						: settings.i18n.failed,
					'error'
				);
			} )
			.catch( function () {
				// A transport failure — offline, a proxy, admin-ajax returning HTML. There is
				// nothing useful to say beyond that it did not happen.
				show( settings.i18n.failed, 'error' );
			} )
			.then( function () {
				button.disabled = false;
			} );
	} );
} )();
