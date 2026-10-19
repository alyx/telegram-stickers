<?php

defined( 'ABSPATH' ) || exit;

/** Create or update a pack made from existing WordPress media attachments. */
function tsp_rest_manual_save( WP_REST_Request $request ) {
	$id   = (int) $request->get_param( 'id' );
	$pack = $id ? get_post( $id ) : null;
	if ( $id && ( ! $pack || 'tsp_pack' !== $pack->post_type || 'manual' !== get_post_meta( $id, '_tsp_source', true ) ) ) {
		return new WP_Error( 'tsp_pack', __( 'Manual pack not found.', 'telegram-stickers' ), array( 'status' => 404 ) );
	}
	if ( $pack && ! tsp_can_edit_manual_pack( $pack ) ) {
		return new WP_Error( 'tsp_forbidden', __( 'You cannot edit this pack.', 'telegram-stickers' ), array( 'status' => 403 ) );
	}

	$raw_title = $request->get_param( 'title' );
	$raw_ids   = $request->get_param( 'attachmentIds' );
	$title     = is_string( $raw_title ) ? trim( sanitize_text_field( $raw_title ) ) : '';
	if ( '' === $title || ! is_array( $raw_ids ) || ! array_is_list( $raw_ids ) || count( $raw_ids ) < 1 || count( $raw_ids ) > 200 ) {
		return new WP_Error( 'tsp_manual_input', __( 'Enter a pack name and choose 1 to 200 images.', 'telegram-stickers' ), array( 'status' => 400 ) );
	}

	$previous = $pack ? get_post_meta( $id, '_tsp_items', true ) : array();
	$previous = is_array( $previous ) ? $previous : array();
	$retired  = $pack ? get_post_meta( $id, '_tsp_retired_items', true ) : array();
	$retired  = is_array( $retired ) ? $retired : array();
	$known    = array_map( static fn( $item ) => (int) ( $item['attachment_id'] ?? 0 ), array_merge( $previous, $retired ) );
	$items    = array();
	$seen     = array();
	foreach ( $raw_ids as $raw_id ) {
		if ( ! is_int( $raw_id ) || $raw_id < 1 || isset( $seen[ $raw_id ] ) || 'attachment' !== get_post_type( $raw_id ) || ! in_array( get_post_mime_type( $raw_id ), array( 'image/png', 'image/webp', 'image/jpeg' ), true ) || ! wp_get_attachment_url( $raw_id ) ) {
			return new WP_Error( 'tsp_manual_image', __( 'Choose distinct PNG, WebP, or JPEG images from the Media Library.', 'telegram-stickers' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $raw_id, $known, true ) && ! current_user_can( 'edit_post', $raw_id ) ) {
			return new WP_Error( 'tsp_manual_image_forbidden', __( 'You cannot add one of those images.', 'telegram-stickers' ), array( 'status' => 403 ) );
		}
		$seen[ $raw_id ] = true;
		$items[]         = array(
			'id'            => 'media-' . $raw_id,
			'emoji'         => '',
			'attachment_id' => $raw_id,
		);
	}

	$post = array(
		'post_title' => $title,
	);
	if ( $pack ) {
		$post['ID'] = $id;
	} else {
		$post['post_type']   = 'tsp_pack';
		$post['post_status'] = 'publish';
		$post['post_author'] = get_current_user_id();
	}
	$saved = $pack ? wp_update_post( $post, true ) : wp_insert_post( $post, true );
	if ( is_wp_error( $saved ) || ! $saved ) {
		return new WP_Error( 'tsp_save', __( 'Could not save the pack.', 'telegram-stickers' ), array( 'status' => 500 ) );
	}

	$active_ids = array_keys( $seen );
	foreach ( $previous as $item ) {
		if ( ! in_array( (int) ( $item['attachment_id'] ?? 0 ), $active_ids, true ) ) {
			$retired[] = $item;
		}
	}
	$retired = array_values(
		array_reduce(
			$retired,
			static function ( array $unique, array $item ): array {
				$unique[ (int) ( $item['attachment_id'] ?? 0 ) ] = $item;
				return $unique;
			},
			array()
		)
	);
	update_post_meta( $saved, '_tsp_source', 'manual' );
	update_post_meta( $saved, '_tsp_status', 'ready' );
	update_post_meta( $saved, '_tsp_items', $items );
	update_post_meta( $saved, '_tsp_retired_items', $retired );
	return tsp_pack_payload( get_post( $saved ) );
}
