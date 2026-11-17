( function ( wp ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;

	wp.blocks.registerBlockType( 'nz/pinnwand', {
		edit: function ( props ) {
			var board = props.attributes.board;
			return el(
				wp.element.Fragment,
				null,
				el(
					wp.blockEditor.InspectorControls,
					null,
					el(
						wp.components.PanelBody,
						{ title: __( 'Pinnwand', 'nz-pinnwand' ) },
						el( wp.components.TextControl, {
							label: __( 'Pinnwand (Slug)', 'nz-pinnwand' ),
							value: board || '',
							onChange: function ( value ) {
								props.setAttributes( { board: value } );
							},
						} )
					)
				),
				el( 'div', wp.blockEditor.useBlockProps(),
					board
						? el( wp.serverSideRender, { block: 'nz/pinnwand', attributes: props.attributes } )
						: el( 'p', null, __( 'Bitte rechts den Slug der Pinnwand eintragen.', 'nz-pinnwand' ) )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
