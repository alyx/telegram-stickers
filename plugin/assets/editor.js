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

		function loadPacks( isActive ) {
			setLoading( true );
			setError( '' );
			wp.apiFetch( { path: '/telegram-stickers/v1/packs' } ).then( function( result ) {
				if ( isActive && ! isActive() ) {
					return;
				}
				setPacks( result );
				setPickerPackId( function( current ) {
					if ( current && result.some( function( pack ) {
						return pack.id === current;
					} ) ) {
						return current;
					}
					return result.length ? result[ 0 ].id : 0;
				} );
				setLoading( false );
			} ).catch( function( failure ) {
				if ( isActive && ! isActive() ) {
					return;
				}
				setError( failure.message || __( 'Could not load sticker packs.', 'telegram-stickers' ) );
				setLoading( false );
			} );
		}

		useEffect( function() {
			let active = true;
			loadPacks( function() {
				return active;
			} );
			return function() {
				active = false;
			};
		}, [] );

		const selectedPack = packs.find( function( pack ) {
			return pack.id === attributes.packId;
		} );
		const activeSticker = selectedPack && selectedPack.stickers.find( function( item ) {
			return item.attachmentId === attributes.attachmentId;
		} );
		const sticker = activeSticker || ( selectedPack && selectedPack.archivedStickers.find( function( item ) {
			return item.attachmentId === attributes.attachmentId;
		} ) );
		const pickerPack = packs.find( function( pack ) {
			return pack.id === pickerPackId;
		} ) || packs[ 0 ];
		const blockProps = useBlockProps( { className: 'tsp-editor-block' } );

		function choose( pack, item ) {
			setAttributes( { packId: pack.id, attachmentId: item.attachmentId, alt: item.emoji || item.alt || '' } );
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
					h( 'p', null, __( 'There are no sticker packs yet.', 'telegram-stickers' ) ),
					h( 'a', { href: window.tspEditor.importUrl, target: '_blank', rel: 'noopener noreferrer' }, __( 'Create or import a pack', 'telegram-stickers' ) ),
					h( Button, { variant: 'secondary', onClick() {
						loadPacks();
					} }, __( 'Refresh packs', 'telegram-stickers' ) ) );
			}
			const visible = pickerPack ? pickerPack.stickers.filter( function( item, index ) {
				return ! filter || ( item.emoji + ' ' + item.alt + ' ' + ( index + 1 ) ).toLowerCase().includes( filter.toLowerCase() );
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
					h( TextControl, { label: __( 'Filter by name, emoji, or number', 'telegram-stickers' ), value: filter, onChange: setFilter, placeholder: __( 'Search this pack', 'telegram-stickers' ) } ),
					h( 'div', { className: 'tsp-chooser-links' },
						h( Button, { variant: 'secondary', onClick() {
							loadPacks();
						} }, __( 'Refresh packs', 'telegram-stickers' ) ),
						h( 'a', { href: window.tspEditor.importUrl, target: '_blank', rel: 'noopener noreferrer' }, __( 'Manage packs', 'telegram-stickers' ) ) ),
				),
				visible.length ? h( 'div', { className: 'tsp-sticker-grid', role: 'group', 'aria-label': sprintf( __( 'Stickers in %s', 'telegram-stickers' ), pickerPack.title ) },
					visible.map( function( item ) {
						const number = pickerPack.stickers.indexOf( item ) + 1;
						return h( 'button', {
							type: 'button',
							key: item.id,
							className: 'tsp-sticker-choice' + ( item.attachmentId === attributes.attachmentId ? ' is-selected' : '' ),
							'aria-label': sprintf( __( 'Choose sticker %d', 'telegram-stickers' ), number ) + ( item.emoji || item.alt ? ', ' + ( item.emoji || item.alt ) : '' ),
							onClick() {
								choose( pickerPack, item );
							},
						}, h( 'img', { src: item.url, alt: '', loading: 'lazy' } ), h( 'span', { 'aria-hidden': 'true' }, item.emoji || item.alt || '#' + number ) );
					} ),
				) : h( 'p', null, pickerPack.stickers.length ? __( 'No stickers match that filter.', 'telegram-stickers' ) : __( 'This pack has no stickers. Add images on the Sticker Packs page, then refresh.', 'telegram-stickers' ) ),
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
					h( 'div', { className: 'tsp-editor-caption' }, selectedPack.title, ' · ', activeSticker ? ( sticker.emoji || sticker.alt || __( 'Sticker', 'telegram-stickers' ) ) : __( 'Removed from pack; still shown in this post', 'telegram-stickers' ) ),
					h( Button, { variant: 'tertiary', onClick() {
						setPickerPackId( attributes.packId );
						setOpen( true );
					} }, __( 'Choose a different sticker', 'telegram-stickers' ) ),
				) : h( Placeholder, { icon: 'format-image', label: __( 'Sticker', 'telegram-stickers' ), instructions: __( 'Choose a pack, then select a sticker.', 'telegram-stickers' ) }, chooser() ),
			),
			open && h( Modal, { title: __( 'Choose a sticker', 'telegram-stickers' ), className: 'tsp-picker-modal', onRequestClose() {
				setOpen( false );
			} }, chooser() ),
		);
	}

	registerBlockType( 'telegram-stickers/sticker', { edit: Edit, save() {
		return null;
	} } );
}( window.wp ) );
