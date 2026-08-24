/**
 * Editor registration for the `currency-converter/rates` block.
 *
 * Deliberately plain ES5 against the `wp.*` globals rather than JSX. The plugin ships as a
 * zip to sites with no build tooling — see the autoloader and `RatesBlock`'s docblock — so
 * there is nothing here that needs compiling before it can run.
 *
 * The preview is `ServerSideRender`, which asks PHP to render the block with the current
 * attributes. That is the only way the editor can show the real stored rates, and it means
 * the preview and the front end cannot disagree: they are the same `render()`.
 *
 * @package Currency_Converter
 */

( function ( blocks, element, blockEditor, components, i18n, serverSideRender ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var ToggleControl = components.ToggleControl;
	var SelectControl = components.SelectControl;
	var RangeControl = components.RangeControl;
	var ServerSideRender = serverSideRender;

	/**
	 * Currency choices for the base selector.
	 *
	 * Localised from PHP so the list is `Currencies::CODES` and not a second copy of it that
	 * can drift. Falls back to the base alone if the script is somehow enqueued without it.
	 */
	var settings = window.currencyConverterBlockEditor || {};
	var choices = settings.currencies || [ { label: 'USD', value: 'USD' } ];

	blocks.registerBlockType( 'currency-converter/rates', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			var controls = el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Rates', 'currency-converter' ), initialOpen: true },
					el( SelectControl, {
						label: __( 'Base currency', 'currency-converter' ),
						help: __( 'Rates are stored against this currency.', 'currency-converter' ),
						value: attributes.base,
						options: choices,
						onChange: function ( value ) {
							setAttributes( { base: value } );
						}
					} ),
					el( ToggleControl, {
						label: __( 'Show the converter', 'currency-converter' ),
						checked: !! attributes.showConverter,
						onChange: function ( value ) {
							setAttributes( { showConverter: !! value } );
						}
					} ),
					el( ToggleControl, {
						label: __( 'Show the rates table', 'currency-converter' ),
						checked: !! attributes.showTable,
						onChange: function ( value ) {
							setAttributes( { showTable: !! value } );
						}
					} ),
					el( ToggleControl, {
						label: __( 'Show when rates were updated', 'currency-converter' ),
						checked: !! attributes.showUpdated,
						onChange: function ( value ) {
							setAttributes( { showUpdated: !! value } );
						}
					} ),
					el( RangeControl, {
						label: __( 'Rows to show', 'currency-converter' ),
						help: __( 'Zero shows every stored rate.', 'currency-converter' ),
						value: attributes.limit,
						min: 0,
						max: 50,
						onChange: function ( value ) {
							setAttributes( { limit: value ? parseInt( value, 10 ) : 0 } );
						}
					} )
				)
			);

			var preview = el( ServerSideRender, {
				block: 'currency-converter/rates',
				attributes: attributes
			} );

			return el( element.Fragment, null, controls, preview );
		},

		/*
		 * Null on purpose: this is a dynamic block. Saving markup would freeze the rates
		 * into post content on the day it was edited and cut them off from the daily sync.
		 */
		save: function () {
			return null;
		}
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n,
	window.wp.serverSideRender
);
