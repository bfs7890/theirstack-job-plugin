/**
 * Editor script for the Healthcare Jobs Board block. Plain JavaScript
 * (no JSX/build step) using the WordPress global scripts already loaded
 * in the block editor.
 */
( function ( blocks, element, blockEditor, components, i18n, serverSideRender ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var ServerSideRender = serverSideRender;

	blocks.registerBlockType( 'healthcare-jobs/job-board', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			return el(
				element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Job Board Settings', 'healthcare-jobs' ) },
						el( TextControl, {
							label: __( 'Category (optional)', 'healthcare-jobs' ),
							value: attributes.category,
							onChange: function ( value ) {
								setAttributes( { category: value } );
							}
						} ),
						el( TextControl, {
							label: __( 'Location (optional)', 'healthcare-jobs' ),
							value: attributes.location,
							onChange: function ( value ) {
								setAttributes( { location: value } );
							}
						} ),
						el( TextControl, {
							label: __( 'Results limit (0 = use default)', 'healthcare-jobs' ),
							type: 'number',
							value: attributes.limit,
							onChange: function ( value ) {
								setAttributes( { limit: parseInt( value, 10 ) || 0 } );
							}
						} )
					)
				),
				el( 'div', { className: 'healthcare-jobs-block-preview' },
					el( ServerSideRender, {
						block: 'healthcare-jobs/job-board',
						attributes: attributes
					} )
				)
			);
		},
		save: function () {
			// Server-side rendered via render.php; nothing to save.
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
