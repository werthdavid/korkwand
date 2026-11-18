( function ( wp ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;

	wp.blocks.registerBlockType( 'korkwand/latest', {
		edit: function ( props ) {
			var attrs = props.attributes;
			var boards = Array.isArray( window.korkwandBoards ) ? window.korkwandBoards : [];
			var options = [ { label: __( 'All boards', 'korkwand' ), value: '' } ].concat(
				boards.map( function ( b ) {
					return { label: b.name, value: b.slug };
				} )
			);
			// Keep a slug that is no longer in the list selectable, so it is not silently dropped.
			if ( attrs.board && ! boards.some( function ( b ) { return b.slug === attrs.board; } ) ) {
				options.push( { label: attrs.board, value: attrs.board } );
			}

			return el(
				wp.element.Fragment,
				null,
				el(
					wp.blockEditor.InspectorControls,
					null,
					el(
						wp.components.PanelBody,
						{ title: __( 'Latest notes', 'korkwand' ) },
						el( wp.components.SelectControl, {
							label: __( 'Board', 'korkwand' ),
							value: attrs.board || '',
							options: options,
							onChange: function ( value ) {
								props.setAttributes( { board: value } );
							},
						} ),
						el( wp.components.RangeControl, {
							label: __( 'Number of notes', 'korkwand' ),
							value: attrs.count,
							min: 1,
							max: 20,
							onChange: function ( value ) {
								props.setAttributes( { count: value || 5 } );
							},
						} ),
						el( wp.components.ToggleControl, {
							label: __( 'Show date', 'korkwand' ),
							checked: !! attrs.showDate,
							onChange: function ( value ) {
								props.setAttributes( { showDate: !! value } );
							},
						} )
					)
				),
				el( 'div', wp.blockEditor.useBlockProps(),
					el( wp.serverSideRender, { block: 'korkwand/latest', attributes: attrs } )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
