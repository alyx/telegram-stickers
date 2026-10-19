<?php

defined( 'ABSPATH' ) || exit;

function tsp_rest_import_start( WP_REST_Request $request ) {
	$name = tsp_pack_name( (string) $request->get_param( 'pack' ) );
	if ( is_wp_error( $name ) ) {
		return $name;
	}
	$manifest = tsp_manifest( $name );
	if ( is_wp_error( $manifest ) ) {
		return $manifest;
	}
	if ( ! isset( $manifest['stickers'], $manifest['title'] ) || ! is_array( $manifest['stickers'] ) || count( $manifest['stickers'] ) > 200 ) {
		return new WP_Error( 'tsp_manifest', __( 'The pack data is invalid.', 'telegram-stickers' ), array( 'status' => 502 ) );
	}
	$static = array();
	foreach ( $manifest['stickers'] as $sticker ) {
		if ( empty( $sticker['supported'] ) ) {
			continue;
		}
		if ( ! isset( $sticker['id'], $sticker['download'] ) || ! is_string( $sticker['id'] ) || ! is_string( $sticker['download'] ) ) {
			return new WP_Error( 'tsp_manifest', __( 'The pack data is invalid.', 'telegram-stickers' ), array( 'status' => 502 ) );
		}
		$static[] = array(
			'id'       => sanitize_text_field( $sticker['id'] ),
			'emoji'    => sanitize_text_field( $sticker['emoji'] ?? '' ),
			'download' => $sticker['download'],
		);
	}
	if ( ! $static ) {
		return new WP_Error( 'tsp_no_static', __( 'This pack has no static stickers to import.', 'telegram-stickers' ), array( 'status' => 400 ) );
	}
	$existing = tsp_find_pack( $name );
	$pack_id  = $existing ? $existing : wp_insert_post(
		array(
			'post_type'   => 'tsp_pack',
			'post_status' => 'publish',
			'post_title'  => sanitize_text_field( $manifest['title'] ),
		),
		true
	);
	if ( is_wp_error( $pack_id ) ) {
		return new WP_Error( 'tsp_save', __( 'Could not save the pack.', 'telegram-stickers' ), array( 'status' => 500 ) );
	}
	wp_update_post(
		array(
			'ID'         => $pack_id,
			'post_title' => sanitize_text_field( $manifest['title'] ),
		)
	);
	update_post_meta( $pack_id, '_tsp_name', $name );
	update_post_meta( $pack_id, '_tsp_source', 'telegram' );
	update_post_meta( $pack_id, '_tsp_manifest', $static );
	update_post_meta( $pack_id, '_tsp_cursor', 0 );
	update_post_meta( $pack_id, '_tsp_status', 'importing' );
	return array(
		'packId'  => $pack_id,
		'total'   => count( $static ),
		'skipped' => count( $manifest['stickers'] ) - count( $static ),
		'done'    => 0,
	);
}

function tsp_download_sticker( array $sticker ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$settings = tsp_settings();
	if ( 'byo' === $settings['mode'] ) {
		$file = tsp_telegram_api( 'getFile', array( 'file_id' => $sticker['download'] ) );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$path = (string) ( $file['file_path'] ?? '' );
		if ( ! preg_match( '#^[A-Za-z0-9_./-]+\.(webp|png)$#i', $path ) || str_contains( $path, '..' ) ) {
			return new WP_Error( 'tsp_file', __( 'Telegram returned an unsupported file.', 'telegram-stickers' ), array( 'status' => 502 ) );
		}
		$url = 'https://api.telegram.org/file/bot' . get_option( TSP_TOKEN ) . '/' . $path;
	} else {
		$path = (string) $sticker['download'];
		if ( ! preg_match( '#^/v1/files/[A-Za-z0-9_.-]+$#', $path ) ) {
			return new WP_Error( 'tsp_file', __( 'The sticker service returned an invalid file link.', 'telegram-stickers' ), array( 'status' => 502 ) );
		}
		$url = untrailingslashit( $settings['service_url'] ) . $path;
	}
	$temp = wp_tempnam( 'tsp-sticker' );
	if ( ! $temp ) {
		return new WP_Error( 'tsp_temp', __( 'Could not create a temporary file.', 'telegram-stickers' ), array( 'status' => 500 ) );
	}
	$response = wp_remote_get(
		$url,
		array(
			'timeout'             => 20,
			'redirection'         => 0,
			'stream'              => true,
			'filename'            => $temp,
			'limit_response_size' => 2000000,
		)
	);
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		wp_delete_file( $temp );
		return new WP_Error( 'tsp_download', __( 'Could not download this sticker. Retry the import.', 'telegram-stickers' ), array( 'status' => 502 ) );
	}
	$info = @getimagesize( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	$mime = is_array( $info ) ? ( $info['mime'] ?? '' ) : '';
	$size = filesize( $temp );
	if ( ! in_array( $mime, array( 'image/webp', 'image/png' ), true ) || ! $size || $size > 2000000 || $info[0] > 1024 || $info[1] > 1024 ) {
		wp_delete_file( $temp );
		return new WP_Error( 'tsp_image', __( 'The downloaded sticker is not a supported image.', 'telegram-stickers' ), array( 'status' => 502 ) );
	}
	return array(
		'path'      => $temp,
		'extension' => 'image/png' === $mime ? 'png' : 'webp',
	);
}

/**
 * Sideload with an explicit MIME map. This also keeps other upload filters
 * compatible with WordPress passing null for the default MIME map.
 */
function tsp_attach_image( array $file, int $pack_id, array $sticker ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$name       = sanitize_file_name( get_post_meta( $pack_id, '_tsp_name', true ) . '-' . $sticker['id'] . '.' . $file['extension'] );
	$file_array = array(
		'name'     => $name,
		'tmp_name' => $file['path'],
	);
	$handled    = wp_handle_sideload(
		$file_array,
		array(
			'test_form' => false,
			'mimes'     => array(
				'webp' => 'image/webp',
				'png'  => 'image/png',
			),
		)
	);
	if ( isset( $handled['error'] ) ) {
		return new WP_Error( 'tsp_media', __( 'WordPress could not save this sticker.', 'telegram-stickers' ), array( 'status' => 500 ) );
	}
	$id = wp_insert_attachment(
		array(
			'post_mime_type' => $handled['type'],
			'guid'           => $handled['url'],
			'post_parent'    => $pack_id,
			'post_title'     => get_the_title( $pack_id ) . ( $sticker['emoji'] ? ' ' . $sticker['emoji'] : '' ),
		),
		$handled['file'],
		$pack_id,
		true
	);
	if ( is_wp_error( $id ) ) {
		wp_delete_file( $handled['file'] );
		return new WP_Error( 'tsp_media', __( 'WordPress could not save this sticker.', 'telegram-stickers' ), array( 'status' => 500 ) );
	}
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $handled['file'] ) );
	update_post_meta( $id, '_tsp_unique_id', $sticker['id'] );
	return $id;
}

function tsp_rest_import_step( WP_REST_Request $request ) {
	$pack_id = (int) $request->get_param( 'packId' );
	if ( 'tsp_pack' !== get_post_type( $pack_id ) ) {
		return new WP_Error( 'tsp_pack', __( 'Pack not found.', 'telegram-stickers' ), array( 'status' => 404 ) );
	}
	if ( 'signal' === get_post_meta( $pack_id, '_tsp_source', true ) ) {
		return tsp_signal_import_step( $pack_id );
	}
	if ( 'manual' === get_post_meta( $pack_id, '_tsp_source', true ) ) {
		return new WP_Error( 'tsp_pack', __( 'No import is pending for this pack.', 'telegram-stickers' ), array( 'status' => 400 ) );
	}
	$manifest = get_post_meta( $pack_id, '_tsp_manifest', true );
	if ( ! is_array( $manifest ) ) {
		return new WP_Error( 'tsp_pack', __( 'No import is pending for this pack.', 'telegram-stickers' ), array( 'status' => 400 ) );
	}
	$cursor   = (int) get_post_meta( $pack_id, '_tsp_cursor', true );
	$items    = get_post_meta( $pack_id, '_tsp_items', true );
	$items    = is_array( $items ) ? $items : array();
	$existing = array();
	foreach ( $items as $item ) {
		if ( isset( $item['id'], $item['attachment_id'] ) && 'attachment' === get_post_type( (int) $item['attachment_id'] ) ) {
			$existing[ $item['id'] ] = (int) $item['attachment_id'];
		}
	}
	$limit = min( $cursor + 4, count( $manifest ) );
	for ( $index = $cursor; $index < $limit; $index++ ) {
		$sticker = $manifest[ $index ];
		$id      = $existing[ $sticker['id'] ] ?? 0;
		if ( ! $id ) {
			$file = tsp_download_sticker( $sticker );
			if ( is_wp_error( $file ) ) {
				update_post_meta( $pack_id, '_tsp_status', 'error' );
				return $file;
			}
			$id = tsp_attach_image( $file, $pack_id, $sticker );
			if ( file_exists( $file['path'] ) ) {
				wp_delete_file( $file['path'] );
			}
			if ( is_wp_error( $id ) ) {
				update_post_meta( $pack_id, '_tsp_status', 'error' );
				return new WP_Error( 'tsp_media', __( 'WordPress could not save this sticker.', 'telegram-stickers' ), array( 'status' => 500 ) );
			}
		}
		$items   = array_values( array_filter( $items, fn( $item ) => ( $item['id'] ?? '' ) !== $sticker['id'] ) );
		$items[] = array(
			'id'            => $sticker['id'],
			'emoji'         => $sticker['emoji'],
			'attachment_id' => $id,
		);
		update_post_meta( $pack_id, '_tsp_items', $items );
		update_post_meta( $pack_id, '_tsp_cursor', $index + 1 );
	}
	$done     = (int) get_post_meta( $pack_id, '_tsp_cursor', true );
	$complete = $done >= count( $manifest );
	if ( $complete ) {
		$order = array_flip( array_column( $manifest, 'id' ) );
		usort( $items, fn( $a, $b ) => ( $order[ $a['id'] ] ?? PHP_INT_MAX ) <=> ( $order[ $b['id'] ] ?? PHP_INT_MAX ) );
		update_post_meta( $pack_id, '_tsp_items', $items );
		update_post_meta( $pack_id, '_tsp_status', 'ready' );
		delete_post_meta( $pack_id, '_tsp_manifest' );
		delete_post_meta( $pack_id, '_tsp_cursor' );
	}
	return array(
		'packId'   => $pack_id,
		'done'     => $done,
		'total'    => count( $manifest ),
		'complete' => $complete,
		'pack'     => $complete ? tsp_pack_payload( get_post( $pack_id ) ) : null,
	);
}
