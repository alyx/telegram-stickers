( function( wp ) {
	'use strict';
	const { __, _n, sprintf } = wp.i18n;
	const config = window.tspAdmin;
	const input = document.getElementById( 'tsp-pack-url' );
	const button = document.getElementById( 'tsp-import-button' );
	const status = document.getElementById( 'tsp-import-status' );
	const progress = document.getElementById( 'tsp-import-progress' );
	const list = document.getElementById( 'tsp-pack-list' );
	if ( ! config || ! input || ! button || ! status || ! progress || ! list ) {
		return;
	}

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
			empty.textContent = __( 'No packs imported yet.', 'telegram-stickers' );
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
			grid.append( card );
		} );
		list.append( grid );
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
	input.addEventListener( 'keydown', function( event ) {
		if ( event.key === 'Enter' ) {
			event.preventDefault();
			button.click();
		}
	} );
	loadPacks();
}( window.wp ) );
