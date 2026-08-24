/**
 * Front-end behaviour for the `currency-converter/rates` block's converter.
 *
 * The arithmetic is not here, and that is the point. JavaScript has one number type and it
 * is a float; the module stores `DECIMAL(24,12)` and multiplies with `bcmath` precisely
 * because floats lose money. So this file collects three values, asks the server, and
 * prints what comes back — see `Rest\ConvertController` for the long version.
 *
 * Plain ES5, no build step, no framework. Progressive by design: the form is rendered
 * server-side with real values, so a visitor whose browser never runs this still sees a
 * working control; this only removes the page reload.
 *
 * @package Currency_Converter
 */

( function () {
	'use strict';

	var settings = window.currencyConverterBlock || {};
	var endpoint = settings.endpoint;

	if ( ! endpoint || ! window.fetch ) {
		return;
	}

	var strings = settings.strings || {};

	/**
	 * Wire one converter form.
	 *
	 * @param {HTMLFormElement} form The form rendered by RatesBlock::render_converter().
	 */
	function setup( form ) {
		var amount = form.querySelector( '.currency-rates-block__amount' );
		var from = form.querySelector( '.currency-rates-block__from' );
		var to = form.querySelector( '.currency-rates-block__to' );
		var output = form.querySelector( '.currency-rates-block__result' );

		if ( ! amount || ! from || ! to || ! output ) {
			return;
		}

		var timer = null;
		var controller = null;

		/**
		 * Ask the server, and print the answer.
		 */
		function convert() {
			var value = amount.value.trim();

			if ( '' === value ) {
				output.textContent = '';
				return;
			}

			// A newer keystroke supersedes an in-flight request, so answers cannot arrive
			// out of order and leave a stale number on screen.
			if ( controller ) {
				controller.abort();
			}

			controller = 'undefined' !== typeof AbortController ? new AbortController() : null;

			var url =
				endpoint +
				( endpoint.indexOf( '?' ) === -1 ? '?' : '&' ) +
				'amount=' +
				encodeURIComponent( value ) +
				'&from=' +
				encodeURIComponent( from.value ) +
				'&to=' +
				encodeURIComponent( to.value );

			var options = { headers: {}, credentials: 'same-origin' };

			if ( settings.nonce ) {
				options.headers['X-WP-Nonce'] = settings.nonce;
			}

			if ( controller ) {
				options.signal = controller.signal;
			}

			output.textContent = strings.converting || '';

			window
				.fetch( url, options )
				.then( function ( response ) {
					return response.json().then( function ( body ) {
						return { ok: response.ok, body: body };
					} );
				} )
				.then( function ( result ) {
					if ( ! result.ok ) {
						// The endpoint's own message when it has one — it explains which
						// pair is missing, which is what somebody needs to read.
						output.textContent =
							( result.body && result.body.message ) || strings.error || '';
						return;
					}

					// textContent, never innerHTML: the response is already escaped for
					// nothing in particular, and this way it cannot become markup.
					output.textContent = result.body.phrase || '';
				} )
				.catch( function ( error ) {
					// An aborted request is the expected outcome of typing, not a failure.
					if ( error && 'AbortError' === error.name ) {
						return;
					}

					output.textContent = strings.error || '';
				} );
		}

		/**
		 * Wait for typing to settle before asking.
		 */
		function schedule() {
			window.clearTimeout( timer );
			timer = window.setTimeout( convert, 250 );
		}

		amount.addEventListener( 'input', schedule );
		from.addEventListener( 'change', convert );
		to.addEventListener( 'change', convert );

		// The form has no server-side submit target; Enter should convert, not reload.
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			convert();
		} );

		convert();
	}

	/**
	 * Wire every converter on the page.
	 */
	function init() {
		var forms = document.querySelectorAll( '.currency-rates-block__converter' );

		Array.prototype.forEach.call( forms, setup );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
