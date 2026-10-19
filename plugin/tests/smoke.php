<?php
/**
 * Run with: wp eval-file tests/smoke.php from the plugin directory.
 * Uses a fake hosted service, so no Telegram token or network request is needed.
 */

defined( 'ABSPATH' ) || exit;

function tsp_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This is a CLI test failure, not HTML output.
	}
}

$original_user = get_current_user_id();
$old_settings  = get_option( TSP_SETTINGS, null );
$name          = 'TspSmoke' . wp_generate_password( 8, false, false );
$pack_id       = 0;
$image         = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9hFpV24AAAAASUVORK5CYII=', true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- A 1x1 PNG test fixture.
$mock          = static function ( $preempt, $arguments, $url ) use ( $name, $image ) {
	if ( 'https://stickers.example.invalid/v1/packs/' . $name === $url ) {
		$body = wp_json_encode(
			array(
				'name'     => $name,
				'title'    => 'Smoke test pack',
				'stickers' => array(
					array(
						'id'        => 'first',
						'emoji'     => '🙂',
						'supported' => true,
						'download'  => '/v1/files/first',
					),
					array(
						'id'        => 'second',
						'emoji'     => '⭐',
						'supported' => true,
						'download'  => '/v1/files/second',
					),
					array(
						'id'        => 'animation',
						'supported' => false,
						'download'  => null,
					),
				),
			)
		);
	} elseif ( str_starts_with( $url, 'https://stickers.example.invalid/v1/files/' ) ) {
		file_put_contents( $arguments['filename'], $image ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$body = '';
	} else {
		return $preempt;
	}
	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => $body,
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
	);
};

add_filter( 'pre_http_request', $mock, 10, 3 );

try {
	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	tsp_smoke_assert( ! empty( $admins ), 'No administrator found.' );
	wp_set_current_user( 0 );
	$guest = rest_do_request( new WP_REST_Request( 'GET', '/telegram-stickers/v1/packs' ) );
	tsp_smoke_assert( $guest->is_error(), 'Guest could list imported packs.' );
	wp_set_current_user( (int) $admins[0] );
	update_option(
		TSP_SETTINGS,
		array(
			'mode'        => 'hosted',
			'service_url' => 'https://stickers.example.invalid',
		),
		false
	);
	tsp_smoke_assert( WP_Block_Type_Registry::get_instance()->is_registered( 'telegram-stickers/sticker' ), 'Block is not registered.' );

	$bad = new WP_REST_Request( 'POST', '/telegram-stickers/v1/import/start' );
	$bad->set_param( 'pack', 'https://example.com/not-a-pack' );
	tsp_smoke_assert( rest_do_request( $bad )->is_error(), 'Invalid pack URL was accepted.' );

	for ( $attempt = 0; $attempt < 2; $attempt++ ) {
		$start = new WP_REST_Request( 'POST', '/telegram-stickers/v1/import/start' );
		$start->set_param( 'pack', $name );
		$response = rest_do_request( $start );
		tsp_smoke_assert( ! $response->is_error(), 'Import start failed.' );
		$job     = $response->get_data();
		$pack_id = (int) $job['packId'];
		tsp_smoke_assert( 2 === $job['total'] && 1 === $job['skipped'], 'Static/animated classification failed.' );

		$step = new WP_REST_Request( 'POST', '/telegram-stickers/v1/import/step' );
		$step->set_param( 'packId', $pack_id );
		$result = rest_do_request( $step );
		tsp_smoke_assert( ! $result->is_error() && $result->get_data()['complete'], 'Import step failed.' );
		$payload = tsp_pack_payload( get_post( $pack_id ) );
		tsp_smoke_assert( 'ready' === $payload['status'] && 2 === count( $payload['stickers'] ), 'Pack is not ready.' );
		$attachment_ids = array_column( $payload['stickers'], 'attachmentId' );
		if ( 0 === $attempt ) {
			$first_attachment_ids = $attachment_ids;
		} else {
			tsp_smoke_assert( $first_attachment_ids === $attachment_ids, 'Retry duplicated attachments.' );
		}
	}

	$html = tsp_render_sticker(
		array(
			'packId'       => $pack_id,
			'attachmentId' => $attachment_ids[0],
			'size'         => 40,
			'alt'          => 'A & B',
		)
	);
	tsp_smoke_assert( str_contains( $html, '<img' ) && str_contains( $html, 'A &amp; B' ), 'Block render or alt escaping failed.' );
	tsp_smoke_assert(
		'' === tsp_render_sticker(
			array(
				'packId'       => $pack_id,
				'attachmentId' => 1,
			)
		),
		'Block rendered unrelated media.'
	);
	echo "Telegram Stickers WordPress smoke test passed.\n";
} finally {
	remove_filter( 'pre_http_request', $mock, 10 );
	if ( $pack_id ) {
		foreach ( (array) get_post_meta( $pack_id, '_tsp_items', true ) as $item ) {
			wp_delete_attachment( (int) ( $item['attachment_id'] ?? 0 ), true );
		}
		wp_delete_post( $pack_id, true );
	}
	if ( null === $old_settings ) {
		delete_option( TSP_SETTINGS );
	} else {
		update_option( TSP_SETTINGS, $old_settings, false );
	}
	wp_set_current_user( $original_user );
}
