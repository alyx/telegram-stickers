const MAX_STICKERS = 200;
const TICKET_LIFETIME_SECONDS = 24 * 60 * 60;

function json( data, status = 200 ) {
	return Response.json( data, {
		status,
		headers: { 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff' },
	} );
}

function base64url( bytes ) {
	return btoa( String.fromCharCode( ...bytes ) ).replace( /\+/g, '-' ).replace( /\//g, '_' ).replace( /=+$/g, '' );
}

function fromBase64url( value ) {
	if ( ! /^[A-Za-z0-9_-]+$/.test( value ) ) {
		throw new Error( 'Invalid ticket' );
	}
	const binary = atob( value.replace( /-/g, '+' ).replace( /_/g, '/' ) + '='.repeat( ( 4 - ( value.length % 4 ) ) % 4 ) );
	const bytes = Uint8Array.from( binary, ( char ) => char.charCodeAt( 0 ) );
	if ( base64url( bytes ) !== value ) {
		throw new Error( 'Invalid ticket' );
	}
	return bytes;
}

async function hmac( secret, value ) {
	const key = await crypto.subtle.importKey( 'raw', new TextEncoder().encode( secret ),
		{ name: 'HMAC', hash: 'SHA-256' }, false, [ 'sign' ] );
	return new Uint8Array( await crypto.subtle.sign( 'HMAC', key, new TextEncoder().encode( value ) ) );
}

async function verifyHmac( secret, value, signature ) {
	const key = await crypto.subtle.importKey( 'raw', new TextEncoder().encode( secret ),
		{ name: 'HMAC', hash: 'SHA-256' }, false, [ 'verify' ] );
	return crypto.subtle.verify( 'HMAC', key, signature, new TextEncoder().encode( value ) );
}

async function issueTicket( secret, fileId, pack ) {
	const payload = base64url( new TextEncoder().encode( JSON.stringify( {
		f: fileId,
		p: pack,
		e: Math.floor( Date.now() / 1000 ) + TICKET_LIFETIME_SECONDS,
	} ) ) );
	return `${ payload }.${ base64url( await hmac( secret, payload ) ) }`;
}

async function readTicket( secret, ticket ) {
	const parts = ticket.split( '.' );
	if ( parts.length !== 2 || parts[ 0 ].length > 1024 ) {
		throw new Error( 'Invalid ticket' );
	}
	if ( ! await verifyHmac( secret, parts[ 0 ], fromBase64url( parts[ 1 ] ) ) ) {
		throw new Error( 'Invalid ticket' );
	}
	const data = JSON.parse( new TextDecoder().decode( fromBase64url( parts[ 0 ] ) ) );
	if ( typeof data.f !== 'string' || ! data.f || typeof data.p !== 'string' ||
    ! /^[A-Za-z][A-Za-z0-9_]{0,63}$/.test( data.p ) ||
    ! Number.isInteger( data.e ) || data.e < Math.floor( Date.now() / 1000 ) ) {
		throw new Error( 'Expired ticket' );
	}
	return data;
}

async function telegram( env, method, params, fetcher ) {
	const url = `https://api.telegram.org/bot${ env.TELEGRAM_BOT_TOKEN }/${ method }`;
	const response = await fetcher( url, {
		method: 'POST',
		body: new URLSearchParams( params ),
		redirect: 'manual',
	} );
	if ( ! response.ok ) {
		throw new Error( 'Telegram unavailable' );
	}
	const data = await response.json();
	if ( ! data.ok || ! data.result ) {
		throw new Error( 'Telegram rejected request' );
	}
	return data.result;
}

export async function handleRequest( request, env, fetcher = fetch ) {
	const url = new URL( request.url );
	if ( request.method !== 'GET' ) {
		return json( { error: 'Method not allowed' }, 405 );
	}
	if ( url.pathname === '/health' ) {
		return json( { ok: true } );
	}
	if ( ! env.TELEGRAM_BOT_TOKEN || ! env.TICKET_SECRET ) {
		return json( { error: 'Service not configured' }, 503 );
	}

	const packMatch = /^\/v1\/packs\/([A-Za-z][A-Za-z0-9_]{0,63})$/.exec( url.pathname );
	if ( packMatch ) {
		try {
			const pack = await telegram( env, 'getStickerSet', { name: packMatch[ 1 ] }, fetcher );
			if ( ! Array.isArray( pack.stickers ) || pack.stickers.length > MAX_STICKERS ) {
				return json( { error: 'Invalid sticker set' }, 502 );
			}
			const stickers = await Promise.all( pack.stickers.map( async ( sticker, index ) => {
				const supported = ! sticker.is_animated && ! sticker.is_video && typeof sticker.file_id === 'string';
				return {
					id: String( sticker.file_unique_id || `${ pack.name }:${ index }` ),
					emoji: String( sticker.emoji || '' ),
					index,
					supported,
					download: supported ? `/v1/files/${ await issueTicket( env.TICKET_SECRET, sticker.file_id, pack.name ) }` : null,
				};
			} ) );
			return json( { name: pack.name, title: pack.title, stickers } );
		} catch ( error ) {
			// Log only the error type; upstream URLs contain the bot token.
			// eslint-disable-next-line no-console
			console.error( 'Sticker pack request failed:', error?.name || 'Error' );
			return json( { error: 'Could not load sticker set' }, 502 );
		}
	}

	const fileMatch = /^\/v1\/files\/([A-Za-z0-9_.-]+)$/.exec( url.pathname );
	if ( fileMatch ) {
		let ticket;
		try {
			ticket = await readTicket( env.TICKET_SECRET, fileMatch[ 1 ] );
		} catch {
			return json( { error: 'Invalid or expired file ticket' }, 403 );
		}
		try {
			const file = await telegram( env, 'getFile', { file_id: ticket.f }, fetcher );
			const path = String( file.file_path || '' );
			if ( ! /^[A-Za-z0-9_./-]+\.(webp|png)$/i.test( path ) || path.includes( '..' ) ) {
				return json( { error: 'Unsupported file' }, 415 );
			}
			const response = await fetcher( `https://api.telegram.org/file/bot${ env.TELEGRAM_BOT_TOKEN }/${ path }`, { redirect: 'manual' } );
			if ( ! response.ok ) {
				return json( { error: 'Could not download sticker' }, 502 );
			}
			const size = Number( response.headers.get( 'Content-Length' ) || 0 );
			if ( size > 2_000_000 ) {
				return json( { error: 'Sticker too large' }, 413 );
			}
			return new Response( response.body, {
				headers: {
					'Content-Type': path.toLowerCase().endsWith( '.png' ) ? 'image/png' : 'image/webp',
					'Cache-Control': 'no-store',
					'X-Content-Type-Options': 'nosniff',
				},
			} );
		} catch {
			return json( { error: 'Could not download sticker' }, 502 );
		}
	}
	return json( { error: 'Not found' }, 404 );
}

export default { fetch( request, env ) {
	return handleRequest( request, env );
} };
