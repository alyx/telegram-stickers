import assert from 'node:assert/strict';
import test from 'node:test';
import { handleRequest } from '../src/index.js';

const env = { TELEGRAM_BOT_TOKEN: 'test-token', TICKET_SECRET: 'test-signing-secret' };
const pack = {
	name: 'alyxbutmore', title: 'alyx but more stickers', stickers: [
		{ file_id: 'sticker-file', file_unique_id: 'stable-id', emoji: '🙂', is_animated: false, is_video: false },
		{ file_id: 'animation-file', file_unique_id: 'animated-id', is_animated: true, is_video: false },
	],
};

function mockFetch( url ) {
	if ( url.endsWith( '/getStickerSet' ) ) {
		return Promise.resolve( Response.json( { ok: true, result: pack } ) );
	}
	if ( url.endsWith( '/getFile' ) ) {
		return Promise.resolve( Response.json( { ok: true, result: { file_path: 'stickers/sample.webp' } } ) );
	}
	if ( url.includes( '/file/bot' ) ) {
		return Promise.resolve( new Response( 'RIFFxxxxWEBP', { headers: { 'Content-Length': '12' } } ) );
	}
	throw new Error( 'Unexpected request' );
}

test( 'manifest excludes the bot token and skips animations', async () => {
	const response = await handleRequest( new Request( 'http://localhost/v1/packs/alyxbutmore' ), env, mockFetch );
	assert.equal( response.status, 200 );
	const body = await response.json();
	assert.equal( body.stickers.length, 2 );
	assert.equal( body.stickers[ 0 ].supported, true );
	assert.equal( body.stickers[ 1 ].supported, false );
	assert.equal( body.stickers[ 1 ].download, null );
	assert.equal( JSON.stringify( body ).includes( 'test-token' ), false );
	const file = await handleRequest( new Request( `http://localhost${ body.stickers[ 0 ].download }` ), env, mockFetch );
	assert.equal( file.status, 200 );
	assert.equal( file.headers.get( 'Content-Type' ), 'image/webp' );
} );

test( 'tampered file ticket is rejected', async () => {
	const response = await handleRequest( new Request( 'http://localhost/v1/packs/alyxbutmore' ), env, mockFetch );
	const body = await response.json();
	const ticket = body.stickers[ 0 ].download;
	const tampered = `${ ticket.slice( 0, -1 ) }${ ticket.endsWith( 'A' ) ? 'B' : 'A' }`;
	const result = await handleRequest( new Request( `http://localhost${ tampered }` ), env, mockFetch );
	assert.equal( result.status, 403 );
} );
