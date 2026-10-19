( function( wp ) {
	'use strict';
	const { __, _n, sprintf } = wp.i18n;
	const config = window.tspAdmin;
	const input = document.getElementById( 'tsp-pack-url' );
	const button = document.getElementById( 'tsp-import-button' );
	const status = document.getElementById( 'tsp-import-status' );
	const progress = document.getElementById( 'tsp-import-progress' );
	const list = document.getElementById( 'tsp-pack-list' );
	const manualHeading = document.getElementById( 'tsp-manual-heading' );
	const manualTitle = document.getElementById( 'tsp-manual-title' );
	const manualAdd = document.getElementById( 'tsp-manual-add' );
	const manualSave = document.getElementById( 'tsp-manual-save' );
	const manualCancel = document.getElementById( 'tsp-manual-cancel' );
	const manualImages = document.getElementById( 'tsp-manual-images' );
	const manualStatus = document.getElementById( 'tsp-manual-status' );
	if ( ! config || ! input || ! button || ! status || ! progress || ! list || ! manualTitle || ! manualAdd || ! manualSave || ! manualCancel || ! manualImages || ! manualStatus ) {
		return;
	}
	let manualPackId = 0;
	let manualItems = [];

	async function request( path, body ) {
		const response = await fetch( config.api + path, {
			method: body ? 'POST' : 'GET',
			credentials: 'same-origin',
			headers: Object.assign( { 'X-WP-Nonce': config.nonce }, body ? { 'Content-Type': 'application/json' } : {} ),
			body: body ? JSON.stringify( body ) : undefined,
		} );
		let data;
		if ( ( response.headers.get( 'Content-Type' ) || '' ).includes( 'application/json' ) ) {
			try {
				data = await response.json();
			} catch { /* Report invalid JSON below. */ }
		}
		if ( ! response.ok ) {
			throw new Error( ( data && data.message ) || sprintf( __( 'The server returned HTTP %d.', 'telegram-stickers' ), response.status ) );
		}
		if ( ! data ) {
			throw new Error( __( 'The server returned an invalid response.', 'telegram-stickers' ) );
		}
		return data;
	}

	function showPacks( packs ) {
		list.replaceChildren();
		if ( ! packs.length ) {
			const empty = document.createElement( 'p' );
			empty.textContent = __( 'No sticker packs yet. Create one above or import from Telegram.', 'telegram-stickers' );
			list.append( empty );
			return;
		}
		const grid = document.createElement( 'div' );
		grid.className = 'tsp-pack-cards';
		packs.forEach( function( pack ) {
			const card = document.createElement( 'div' );
			card.className = 'tsp-pack-card';
			const heading = document.createElement( 'h3' );
			heading.textContent = pack.title;
			card.append( heading );
			const detail = document.createElement( 'p' );
			const count = sprintf( _n( '%d sticker', '%d stickers', pack.stickers.length, 'telegram-stickers' ), pack.stickers.length );
			const state = {
				ready: __( 'Ready', 'telegram-stickers' ),
				importing: __( 'Importing', 'telegram-stickers' ),
				error: __( 'Needs retry', 'telegram-stickers' ),
			};
			detail.textContent = count + ' · ' + ( state[ pack.status ] || state.importing );
			card.append( detail );
			const previews = document.createElement( 'div' );
			previews.className = 'tsp-pack-previews';
			pack.stickers.slice( 0, 5 ).forEach( function( sticker ) {
				const img = document.createElement( 'img' );
				img.src = sticker.url;
				img.alt = sticker.emoji || '';
				img.width = 44;
				img.height = 44;
				previews.append( img );
			} );
			card.append( previews );
			if ( pack.source === 'manual' && pack.canEdit ) {
				const edit = document.createElement( 'button' );
				edit.type = 'button';
				edit.className = 'button button-secondary';
				edit.textContent = __( 'Edit pack', 'telegram-stickers' );
				edit.addEventListener( 'click', function() {
					manualPackId = pack.id;
					manualItems = pack.stickers.map( function( sticker ) {
						return { id: sticker.attachmentId, url: sticker.url, alt: sticker.alt };
					} );
					manualTitle.value = pack.title;
					manualHeading.textContent = __( 'Edit pack', 'telegram-stickers' );
					manualSave.textContent = __( 'Save changes', 'telegram-stickers' );
					manualCancel.hidden = false;
					manualStatus.textContent = '';
					renderManualImages();
					manualTitle.scrollIntoView( { block: 'center' } );
					manualTitle.focus();
				} );
				card.append( edit );
			} else if ( pack.source === 'telegram' ) {
				const refresh = document.createElement( 'button' );
				refresh.type = 'button';
				refresh.className = 'button button-secondary';
				refresh.textContent = __( 'Refresh pack', 'telegram-stickers' );
				refresh.addEventListener( 'click', function() {
					input.value = pack.name;
					input.focus();
					button.click();
				} );
				card.append( refresh );
			}
			grid.append( card );
		} );
		list.append( grid );
	}

	function renderManualImages() {
		manualImages.replaceChildren();
		manualItems.forEach( function( item, index ) {
			const row = document.createElement( 'li' );
			row.className = 'tsp-manual-image';
			const image = document.createElement( 'img' );
			image.src = item.url;
			image.alt = '';
			image.width = 56;
			image.height = 56;
			row.append( image );
			const label = document.createElement( 'span' );
			label.textContent = item.alt || sprintf( __( 'Image %d', 'telegram-stickers' ), index + 1 );
			row.append( label );
			[
				{ label: __( 'Move up', 'telegram-stickers' ), offset: -1 },
				{ label: __( 'Move down', 'telegram-stickers' ), offset: 1 },
			].forEach( function( action ) {
				const move = document.createElement( 'button' );
				move.type = 'button';
				move.className = 'button button-small';
				move.textContent = action.offset < 0 ? '↑' : '↓';
				move.setAttribute( 'aria-label', sprintf( __( '%1$s image %2$d', 'telegram-stickers' ), action.label, index + 1 ) );
				move.disabled = index + action.offset < 0 || index + action.offset >= manualItems.length;
				move.addEventListener( 'click', function() {
					const next = index + action.offset;
					[ manualItems[ index ], manualItems[ next ] ] = [ manualItems[ next ], manualItems[ index ] ];
					renderManualImages();
				} );
				row.append( move );
			} );
			const remove = document.createElement( 'button' );
			remove.type = 'button';
			remove.className = 'button button-small';
			remove.textContent = __( 'Remove', 'telegram-stickers' );
			remove.setAttribute( 'aria-label', sprintf( __( 'Remove image %d from pack', 'telegram-stickers' ), index + 1 ) );
			remove.addEventListener( 'click', function() {
				manualItems.splice( index, 1 );
				renderManualImages();
			} );
			row.append( remove );
			manualImages.append( row );
		} );
	}

	function resetManual() {
		manualPackId = 0;
		manualItems = [];
		manualTitle.value = '';
		manualHeading.textContent = __( 'Create a pack', 'telegram-stickers' );
		manualSave.textContent = __( 'Create pack', 'telegram-stickers' );
		manualCancel.hidden = true;
		manualStatus.textContent = '';
		renderManualImages();
	}

	function addManualImages() {
		const frame = wp.media( {
			title: __( 'Choose images for the sticker pack', 'telegram-stickers' ),
			button: { text: __( 'Add to pack', 'telegram-stickers' ) },
			library: { type: 'image' },
			multiple: true,
		} );
		frame.on( 'select', function() {
			let unsupported = 0;
			frame.state().get( 'selection' ).toJSON().forEach( function( image ) {
				if ( ! [ 'image/png', 'image/webp', 'image/jpeg' ].includes( image.mime ) ) {
					unsupported++;
					return;
				}
				if ( ! manualItems.some( function( item ) {
					return item.id === image.id;
				} ) ) {
					manualItems.push( {
						id: image.id,
						url: image.sizes && image.sizes.thumbnail ? image.sizes.thumbnail.url : image.url,
						alt: image.alt || '',
					} );
				}
			} );
			renderManualImages();
			manualStatus.className = unsupported ? 'tsp-error' : '';
			manualStatus.textContent = unsupported ? __( 'Only PNG, WebP, and JPEG images can be added.', 'telegram-stickers' ) : '';
		} );
		frame.open();
	}

	async function saveManual() {
		const title = manualTitle.value.trim();
		if ( ! title || ! manualItems.length ) {
			manualStatus.className = 'tsp-error';
			manualStatus.textContent = __( 'Enter a pack name and add at least one image.', 'telegram-stickers' );
			( title ? manualAdd : manualTitle ).focus();
			return;
		}
		manualSave.disabled = true;
		manualStatus.className = '';
		manualStatus.textContent = __( 'Saving pack…', 'telegram-stickers' );
		try {
			await request( manualPackId ? 'manual/' + manualPackId : 'manual', { title, attachmentIds: manualItems.map( function( item ) {
				return item.id;
			} ) } );
			const changed = !! manualPackId;
			resetManual();
			manualStatus.className = 'tsp-success';
			manualStatus.textContent = changed ? __( 'Pack updated. It is ready in the editor.', 'telegram-stickers' ) : __( 'Pack created. It is ready in the editor.', 'telegram-stickers' );
			await loadPacks();
		} catch ( error ) {
			manualStatus.className = 'tsp-error';
			manualStatus.textContent = error.message;
		} finally {
			manualSave.disabled = false;
		}
	}

	async function loadPacks() {
		try {
			showPacks( await request( 'packs' ) );
		} catch ( error ) {
			list.textContent = error.message;
		}
	}

	async function importPack() {
		const pack = input.value.trim();
		if ( ! pack ) {
			input.focus();
			return;
		}
		button.disabled = true;
		progress.hidden = true;
		status.className = '';
		status.textContent = __( 'Loading pack details…', 'telegram-stickers' );
		try {
			const job = await request( 'import/start', { pack } );
			progress.max = job.total;
			progress.value = 0;
			progress.hidden = false;
			do {
				status.textContent = sprintf( __( 'Importing sticker %1$d of %2$d…', 'telegram-stickers' ), job.done + 1, job.total );
				const step = await request( 'import/step', { packId: job.packId } );
				job.done = step.done;
				progress.value = step.done;
				if ( step.complete ) {
					break;
				}
			} while ( job.done < job.total );
			status.className = 'tsp-success';
			const imported = sprintf( _n( 'Imported %d static sticker', 'Imported %d static stickers', job.total, 'telegram-stickers' ), job.total );
			const skipped = job.skipped ? sprintf( _n( '; skipped %d animated or video sticker', '; skipped %d animated or video stickers', job.skipped, 'telegram-stickers' ), job.skipped ) : '';
			status.textContent = imported + skipped + __( '. Ready to use in the editor.', 'telegram-stickers' );
			await loadPacks();
		} catch ( error ) {
			status.className = 'tsp-error';
			status.textContent = error.message + ' ' + __( 'You can retry the import; saved stickers will be reused.', 'telegram-stickers' );
		} finally {
			button.disabled = false;
		}
	}

	button.addEventListener( 'click', importPack );
	manualAdd.addEventListener( 'click', addManualImages );
	manualSave.addEventListener( 'click', saveManual );
	manualCancel.addEventListener( 'click', resetManual );
	input.addEventListener( 'keydown', function( event ) {
		if ( event.key === 'Enter' ) {
			event.preventDefault();
			button.click();
		}
	} );
	loadPacks();
}( window.wp ) );
