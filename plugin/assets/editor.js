( function( wp ) {
	'use strict';
	const h = wp.element.createElement;
	const Fragment = wp.element.Fragment;
	const useEffect = wp.element.useEffect;
	const useState = wp.element.useState;
	const { registerBlockType } = wp.blocks;
	const { __, _n, sprintf } = wp.i18n;
	const { useBlockProps, InspectorControls, BlockControls } = wp.blockEditor;
	const { PanelBody, SelectControl, TextControl, RangeControl, Button, Modal, Placeholder, Spinner, ToolbarGroup, ToolbarButton } = wp.components;

	function Edit( props ) {
		const { attributes, setAttributes } = props;
		const [ packs, setPacks ] = useState( [] );
		const [ loading, setLoading ] = useState( true );
		const [ error, setError ] = useState( '' );
		const [ open, setOpen ] = useState( false );
		const [ filter, setFilter ] = useState( '' );
		const [ pickerPackId, setPickerPackId ] = useState( attributes.packId || 0 );

		useEffect( function() {
			let active = true;
			wp.apiFetch( { path: '/telegram-stickers/v1/packs' } ).then( function( result ) {
				if ( ! active ) {
					return;
				}
				setPacks( result );
				if ( ! pickerPackId && result.length ) {
					setPickerPackId( result[ 0 ].id );
				}
				setLoading( false );
			} ).catch( function( failure ) {
				if ( ! active ) {
					return;
				}
				setError( failure.message || __( 'Could not load sticker packs.', 'telegram-stickers' ) );
				setLoading( false );
			} );
			return function() {
				active = false;
			};
		}, [] );

		const selectedPack = packs.find( function( pack ) {
			return pack.id === attributes.packId;
		} );
		const sticker = selectedPack && selectedPack.stickers.find( function( item ) {
			return item.attachmentId === attributes.attachmentId;
		} );
		const pickerPack = packs.find( function( pack ) {
			return pack.id === pickerPackId;
		} ) || packs[ 0 ];
		const blockProps = useBlockProps( { className: 'tsp-editor-block' } );

		function choose( pack, item ) {
			setAttributes( { packId: pack.id, attachmentId: item.attachmentId, alt: item.emoji || '' } );
			setOpen( false );
			setFilter( '' );
		}

		function chooser() {
			if ( loading ) {
				return h( 'div', { className: 'tsp-loading' }, h( Spinner ), ' ', __( 'Loading sticker packs…', 'telegram-stickers' ) );
			}
			if ( error ) {
				return h( 'p', { className: 'tsp-error' }, error );
			}
			if ( ! packs.length ) {
				return h( 'div', { className: 'tsp-empty' },
					h( 'p', null, __( 'There are no imported sticker packs yet.', 'telegram-stickers' ) ),
					h( 'a', { href: window.tspEditor.importUrl }, __( 'Import a sticker pack', 'telegram-stickers' ) ) );
			}
			const visible = pickerPack ? pickerPack.stickers.filter( function( item, index ) {
				return ! filter || ( item.emoji + ' ' + ( index + 1 ) ).toLowerCase().includes( filter.toLowerCase() );
			} ) : [];
			return h( 'div', { className: 'tsp-chooser' },
				h( 'div', { className: 'tsp-chooser-controls' },
					h( SelectControl, {
						label: __( 'Sticker pack', 'telegram-stickers' ),
						value: String( pickerPack ? pickerPack.id : '' ),
						options: packs.map( function( pack ) {
							const count = sprintf( _n( '%d sticker', '%d stickers', pack.stickers.length, 'telegram-stickers' ), pack.stickers.length );
							return { label: pack.title + ' (' + count + ')', value: String( pack.id ) };
						} ),
						onChange( value ) {
							setPickerPackId( Number( value ) );
							setFilter( '' );
						},
					} ),
					h( TextControl, { label: __( 'Filter by emoji or number', 'telegram-stickers' ), value: filter, onChange: setFilter, placeholder: __( 'Search this pack', 'telegram-stickers' ) } ),
				),
				visible.length ? h( 'div', { className: 'tsp-sticker-grid', role: 'group', 'aria-label': sprintf( __( 'Stickers in %s', 'telegram-stickers' ), pickerPack.title ) },
					visible.map( function( item ) {
						const number = pickerPack.stickers.indexOf( item ) + 1;
						return h( 'button', {
							type: 'button',
							key: item.id,
							className: 'tsp-sticker-choice' + ( item.attachmentId === attributes.attachmentId ? ' is-selected' : '' ),
							'aria-label': sprintf( __( 'Choose sticker %d', 'telegram-stickers' ), number ) + ( item.emoji ? ', ' + item.emoji : '' ),
							onClick() {
								choose( pickerPack, item );
							},
						}, h( 'img', { src: item.url, alt: '', loading: 'lazy' } ), h( 'span', { 'aria-hidden': 'true' }, item.emoji || '#' + number ) );
					} ),
				) : h( 'p', null, __( 'No stickers match that filter.', 'telegram-stickers' ) ),
			);
		}

		const inspector = h( InspectorControls, null,
			h( PanelBody, { title: __( 'Sticker', 'telegram-stickers' ), initialOpen: true },
				h( RangeControl, { label: __( 'Display size (px)', 'telegram-stickers' ), value: attributes.size, min: 24, max: 256, step: 8, onChange( size ) {
					setAttributes( { size } );
				} } ),
				h( TextControl, { label: __( 'Alternative text', 'telegram-stickers' ), help: __( 'Describe the sticker if it adds meaning, or leave blank if decorative.', 'telegram-stickers' ), value: attributes.alt, onChange( alt ) {
					setAttributes( { alt } );
				} } ),
				sticker && h( Button, { variant: 'secondary', onClick() {
					setPickerPackId( attributes.packId );
					setOpen( true );
				} }, __( 'Change sticker', 'telegram-stickers' ) ),
			),
		);

		return h( Fragment, null,
			inspector,
			sticker && h( BlockControls, null, h( ToolbarGroup, null,
				h( ToolbarButton, { icon: 'update', label: __( 'Change sticker', 'telegram-stickers' ), onClick() {
					setPickerPackId( attributes.packId );
					setOpen( true );
				} }, __( 'Change sticker', 'telegram-stickers' ) ) ) ),
			h( 'div', blockProps,
				sticker ? h( 'div', { className: 'tsp-editor-preview' },
					h( 'img', { src: sticker.url, alt: attributes.alt || '', style: { width: attributes.size + 'px', height: attributes.size + 'px', objectFit: 'contain' } } ),
					h( 'div', { className: 'tsp-editor-caption' }, selectedPack.title, ' · ', sticker.emoji || __( 'Sticker', 'telegram-stickers' ) ),
					h( Button, { variant: 'tertiary', onClick() {
						setPickerPackId( attributes.packId );
						setOpen( true );
					} }, __( 'Choose a different sticker', 'telegram-stickers' ) ),
				) : h( Placeholder, { icon: 'format-image', label: __( 'Telegram Sticker', 'telegram-stickers' ), instructions: __( 'Choose a pack, then select a sticker.', 'telegram-stickers' ) }, chooser() ),
			),
			open && h( Modal, { title: __( 'Choose a Telegram sticker', 'telegram-stickers' ), className: 'tsp-picker-modal', onRequestClose() {
				setOpen( false );
			} }, chooser() ),
		);
	}

	registerBlockType( 'telegram-stickers/sticker', { edit: Edit, save() {
		return null;
	} } );
}( window.wp ) );
