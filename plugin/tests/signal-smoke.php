<?php
/** Run with wp eval-file tests/signal-smoke.php; all Signal responses are local mocks. */

defined( 'ABSPATH' ) || exit;

function tsp_signal_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI test failure.
	}
}

function tsp_signal_test_field( int $number, int|string $value ): string {
	if ( is_int( $value ) ) {
		return chr( $number << 3 ) . chr( $value );
	}
	return chr( ( $number << 3 ) | 2 ) . chr( strlen( $value ) ) . $value;
}

function tsp_signal_test_encrypt( string $plain, string $key ): string {
	$derived = hash_hkdf( 'sha256', hex2bin( $key ), 64, 'Sticker Pack' );
	$iv      = str_repeat( "\x11", 16 );
	$data    = $iv . openssl_encrypt( $plain, 'aes-256-cbc', substr( $derived, 0, 32 ), OPENSSL_RAW_DATA, $iv );
	return $data . hash_hmac( 'sha256', $data, substr( $derived, 32, 32 ), true );
}

$original_user = get_current_user_id();
$admins        = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
$signal_hex    = bin2hex( random_bytes( 16 ) );
$key           = bin2hex( random_bytes( 32 ) );
$pack_id       = 0;
$attachments   = array();
$requests      = array();
$entry         = tsp_signal_test_field( 1, 1 ) . tsp_signal_test_field( 2, '🙂' );
$png_entry     = tsp_signal_test_field( 1, 2 ) . tsp_signal_test_field( 2, '⭐' );
$animated_png  = tsp_signal_test_field( 1, 3 );
$animated_webp = tsp_signal_test_field( 1, 4 );
$manifest      = tsp_signal_test_field( 1, 'Signal smoke' ) . tsp_signal_test_field( 2, 'Test author' ) . tsp_signal_test_field( 4, $entry ) . tsp_signal_test_field( 4, $png_entry ) . tsp_signal_test_field( 4, $animated_png ) . tsp_signal_test_field( 4, $animated_webp );
$webp          = base64_decode( 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoIAAgAAUAmJaQAA3AA/v02aAA=', true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- A tiny WebP fixture.
$png           = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9hFpV24AAAAASUVORK5CYII=', true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- A tiny PNG fixture.
$animation     = 'RIFF' . pack( 'V', 12 ) . 'WEBPANIM' . pack( 'V', 4 ) . "\0\0\0\0";
$apng          = "\x89PNG\r\n\x1a\n" . pack( 'N', 4 ) . 'acTL' . "\0\0\0\0\0\0\0\0";
$mock          = static function ( $preempt, $args, $url ) use ( $signal_hex, $key, $manifest, $webp, $png, $apng, $animation, &$requests ) {
	$base = 'https://cdn.signal.org/stickers/' . $signal_hex . '/';
	if ( ! str_starts_with( $url, $base ) ) {
		return $preempt;
	}
	$requests[] = $url;
	$path       = substr( $url, strlen( $base ) );
	$plain      = match ( $path ) {
		'manifest.proto' => $manifest,
		'full/1'       => $webp,
		'full/2'       => $png,
		'full/3'       => $apng,
		'full/4'       => $animation,
		default        => '',
	};
	return array(
		'headers'  => array( 'content-type' => 'application/octet-stream' ),
		'body'     => tsp_signal_test_encrypt( $plain, $key ),
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
	);
};
add_filter( 'pre_http_request', $mock, 10, 3 );

try {
	tsp_signal_test_assert( ! empty( $admins ), 'No administrator found.' );
	wp_set_current_user( 0 );
	$guest = new WP_REST_Request( 'POST', '/telegram-stickers/v1/signal/start' );
	$guest->set_param( 'pack', 'https://signal.art/addstickers/#pack_id=' . $signal_hex . '&pack_key=' . $key );
	tsp_signal_test_assert( rest_do_request( $guest )->is_error(), 'Guest could import a Signal pack.' );
	wp_set_current_user( (int) $admins[0] );
	tsp_signal_test_assert( is_wp_error( tsp_signal_link( 'https://evil.example/addstickers/#pack_id=' . $signal_hex . '&pack_key=' . $key ) ), 'Foreign Signal host accepted.' );
	tsp_signal_test_assert( is_wp_error( tsp_signal_link( 'https://signal.art/addstickers/#pack_id=' . $signal_hex ) ), 'Missing key accepted.' );
	tsp_signal_test_assert( is_wp_error( tsp_signal_decrypt( tsp_signal_test_encrypt( $manifest, $key ), str_repeat( '0', 64 ) ) ), 'Wrong key accepted.' );
	$pack_link = 'https://signal.art/addstickers/#pack_id=' . $signal_hex . '&pack_key=' . $key;
	for ( $attempt = 0; $attempt < 2; $attempt++ ) {
		$start = new WP_REST_Request( 'POST', $attempt ? '/telegram-stickers/v1/signal/refresh' : '/telegram-stickers/v1/signal/start' );
		$start->set_param( $attempt ? 'packId' : 'pack', $attempt ? $pack_id : $pack_link );
		$response = rest_do_request( $start );
		tsp_signal_test_assert( ! $response->is_error(), 'Signal import start failed.' );
		$job     = $response->get_data();
		$pack_id = (int) $job['packId'];
		do {
			$step = new WP_REST_Request( 'POST', '/telegram-stickers/v1/import/step' );
			$step->set_param( 'packId', $pack_id );
			$response = rest_do_request( $step );
			tsp_signal_test_assert( ! $response->is_error(), 'Signal import step failed.' );
			$job = $response->get_data();
		} while ( ! $job['complete'] );
		$payload = $job['pack'];
		tsp_signal_test_assert( 'signal' === $payload['source'] && 'ready' === $payload['status'] && 2 === count( $payload['stickers'] ) && 2 === $job['skipped'], 'Signal import result is wrong.' );
		tsp_signal_test_assert( ! str_contains( wp_json_encode( $payload ), $key ), 'Signal key leaked to editor payload.' );
		$attachment_ids = array_column( $payload['stickers'], 'attachmentId' );
		if ( $attempt ) {
			tsp_signal_test_assert( $attachments === $attachment_ids, 'Refresh duplicated attachments.' );
		} else {
			$attachments = $attachment_ids;
			tsp_signal_test_assert( 'image/webp' === get_post_mime_type( $attachments[0] ) && 'image/png' === get_post_mime_type( $attachments[1] ), 'Static sticker formats were not saved correctly.' );
		}
	}
	tsp_signal_test_assert( 1 === count( array_filter( $requests, static fn( $url ) => str_ends_with( $url, '/full/1' ) ) ), 'Refresh downloaded an existing sticker.' );
	tsp_signal_test_assert( 1 === count( array_filter( $requests, static fn( $url ) => str_ends_with( $url, '/full/2' ) ) ), 'Refresh downloaded an existing PNG sticker.' );
	tsp_signal_test_assert(
		str_contains(
			tsp_render_sticker(
				array(
					'packId'       => $pack_id,
					'attachmentId' => $attachments[0],
				)
			),
			'<img'
		),
		'Signal block did not render.'
	);
	echo "Signal sticker smoke test passed.\n";
} finally {
	remove_filter( 'pre_http_request', $mock, 10 );
	foreach ( $attachments as $attachment_id ) {
		wp_delete_attachment( $attachment_id, true );
	}
	if ( $pack_id ) {
		wp_delete_post( $pack_id, true );
	}
	wp_set_current_user( $original_user );
}
