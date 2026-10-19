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

$original_user  = get_current_user_id();
$old_settings   = get_option( TSP_SETTINGS, null );
$name           = 'TspSmoke' . wp_generate_password( 8, false, false );
$pack_id        = 0;
$manual_pack_id = 0;
$author_ids     = array();
$manual_ids     = array();
$invalid_id     = 0;
$image          = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9hFpV24AAAAASUVORK5CYII=', true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- A 1x1 PNG test fixture.
$mock           = static function ( $preempt, $arguments, $url ) use ( $name, $image ) {
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

	foreach ( array( 'owner', 'other' ) as $label ) {
		$user_id = wp_create_user( 'tsp-smoke-' . $label . '-' . strtolower( $name ), wp_generate_password( 24 ), 'tsp-smoke-' . $label . '-' . strtolower( $name ) . '@example.invalid' );
		tsp_smoke_assert( ! is_wp_error( $user_id ), 'Could not create smoke test author.' );
		( new WP_User( $user_id ) )->set_role( 'author' );
		$author_ids[] = $user_id;
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';
	foreach ( array( 'one', 'two' ) as $label ) {
		$upload = wp_upload_bits( 'tsp-smoke-' . $label . '.png', null, $image );
		tsp_smoke_assert( empty( $upload['error'] ), 'Could not upload smoke test image.' );
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'TSP smoke ' . $label,
				'post_status'    => 'inherit',
				'post_author'    => $author_ids[0],
			),
			$upload['file'],
			0,
			true
		);
		tsp_smoke_assert( ! is_wp_error( $attachment_id ), 'Could not create smoke test attachment.' );
		$manual_ids[] = $attachment_id;
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
	}
	wp_set_current_user( $author_ids[0] );
	$invalid_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'text/plain',
			'post_title'     => 'Not a sticker image',
			'post_status'    => 'inherit',
			'post_author'    => $author_ids[0],
		),
		false,
		0,
		true
	);
	tsp_smoke_assert( ! is_wp_error( $invalid_id ), 'Could not create invalid media fixture.' );
	$bad_media = new WP_REST_Request( 'POST', '/telegram-stickers/v1/manual' );
	$bad_media->set_param( 'title', 'Bad media' );
	$bad_media->set_param( 'attachmentIds', array( $invalid_id ) );
	tsp_smoke_assert( rest_do_request( $bad_media )->is_error(), 'Non-image media was accepted.' );
	$bad_media->set_param( 'attachmentIds', array() );
	tsp_smoke_assert( rest_do_request( $bad_media )->is_error(), 'Empty manual pack was accepted.' );
	$create = new WP_REST_Request( 'POST', '/telegram-stickers/v1/manual' );
	$create->set_param( 'title', 'My test stickers' );
	$create->set_param( 'attachmentIds', $manual_ids );
	$created = rest_do_request( $create );
	tsp_smoke_assert( ! $created->is_error(), 'Author could not create a manual pack.' );
	$manual_pack_id = (int) $created->get_data()['id'];
	tsp_smoke_assert( 'manual' === $created->get_data()['source'] && 2 === count( $created->get_data()['stickers'] ) && $created->get_data()['canEdit'], 'Manual pack payload is wrong.' );

	$duplicate = new WP_REST_Request( 'POST', '/telegram-stickers/v1/manual/' . $manual_pack_id );
	$duplicate->set_param( 'title', 'My test stickers' );
	$duplicate->set_param( 'attachmentIds', array( $manual_ids[0], $manual_ids[0] ) );
	tsp_smoke_assert( rest_do_request( $duplicate )->is_error(), 'Duplicate image IDs were accepted.' );

	wp_set_current_user( $author_ids[1] );
	$shared      = rest_do_request( new WP_REST_Request( 'GET', '/telegram-stickers/v1/packs' ) );
	$shared_pack = array_values( array_filter( $shared->get_data(), static fn( $item ) => $manual_pack_id === $item['id'] ) );
	tsp_smoke_assert( 1 === count( $shared_pack ) && ! $shared_pack[0]['canEdit'], 'Another author could not use the shared pack safely.' );
	$forbidden = new WP_REST_Request( 'POST', '/telegram-stickers/v1/manual/' . $manual_pack_id );
	$forbidden->set_param( 'title', 'Hijacked' );
	$forbidden->set_param( 'attachmentIds', $manual_ids );
	$denied = rest_do_request( $forbidden );
	tsp_smoke_assert( $denied->is_error() && 403 === $denied->get_status(), 'Another author could edit a manual pack.' );
	$foreign = new WP_REST_Request( 'POST', '/telegram-stickers/v1/manual' );
	$foreign->set_param( 'title', 'Foreign images' );
	$foreign->set_param( 'attachmentIds', $manual_ids );
	$denied = rest_do_request( $foreign );
	tsp_smoke_assert( $denied->is_error() && 403 === $denied->get_status(), 'Another author could add someone else’s media.' );

	wp_set_current_user( (int) $admins[0] );
	$update = new WP_REST_Request( 'POST', '/telegram-stickers/v1/manual/' . $manual_pack_id );
	$update->set_param( 'title', 'Reordered test stickers' );
	$update->set_param( 'attachmentIds', array_reverse( $manual_ids ) );
	$updated = rest_do_request( $update );
	tsp_smoke_assert( ! $updated->is_error() && $manual_ids[1] === $updated->get_data()['stickers'][0]['attachmentId'], 'Administrator could not reorder the manual pack.' );
	$update->set_param( 'attachmentIds', array( $manual_ids[1] ) );
	$updated = rest_do_request( $update );
	tsp_smoke_assert( ! $updated->is_error() && 1 === count( $updated->get_data()['stickers'] ) && 1 === count( $updated->get_data()['archivedStickers'] ), 'Removing a sticker did not archive its reference.' );
	$kept_html = tsp_render_sticker(
		array(
			'packId'       => $manual_pack_id,
			'attachmentId' => $manual_ids[0],
			'alt'          => 'Still here',
		)
	);
	tsp_smoke_assert( str_contains( $kept_html, 'Still here' ), 'Removing an image from a pack broke an existing post.' );
	tsp_smoke_assert( 'attachment' === get_post_type( $manual_ids[0] ), 'Removing an image deleted Media Library content.' );
	$imported_edit = new WP_REST_Request( 'POST', '/telegram-stickers/v1/manual/' . $pack_id );
	$imported_edit->set_param( 'title', 'Wrong source' );
	$imported_edit->set_param( 'attachmentIds', $manual_ids );
	tsp_smoke_assert( rest_do_request( $imported_edit )->is_error(), 'Manual endpoint edited a Telegram pack.' );
	echo "Telegram Stickers WordPress smoke test passed.\n";
} finally {
	remove_filter( 'pre_http_request', $mock, 10 );
	if ( $manual_pack_id ) {
		wp_delete_post( $manual_pack_id, true );
	}
	foreach ( $manual_ids as $attachment_id ) {
		wp_delete_attachment( $attachment_id, true );
	}
	if ( $invalid_id ) {
		wp_delete_attachment( $invalid_id, true );
	}
	foreach ( $author_ids as $author_id ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $author_id );
	}
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
