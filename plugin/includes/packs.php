<?php

defined( 'ABSPATH' ) || exit;

function tsp_rest_routes(): void {
	register_rest_route(
		'telegram-stickers/v1',
		'/packs',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'tsp_rest_packs',
			'permission_callback' => 'tsp_can_use_packs',
		)
	);
	register_rest_route(
		'telegram-stickers/v1',
		'/import/start',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tsp_rest_import_start',
			'permission_callback' => 'tsp_can_use_packs',
			'args'                => array(
				'pack' => array(
					'required' => true,
					'type'     => 'string',
				),
			),
		)
	);
	register_rest_route(
		'telegram-stickers/v1',
		'/import/step',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tsp_rest_import_step',
			'permission_callback' => 'tsp_can_use_packs',
			'args'                => array(
				'packId' => array(
					'required' => true,
					'type'     => 'integer',
				),
			),
		)
	);
	register_rest_route(
		'telegram-stickers/v1',
		'/signal/start',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tsp_rest_signal_start',
			'permission_callback' => 'tsp_can_use_packs',
			'args'                => array(
				'pack' => array(
					'required' => true,
					'type'     => 'string',
				),
			),
		)
	);
	register_rest_route(
		'telegram-stickers/v1',
		'/signal/refresh',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tsp_rest_signal_refresh',
			'permission_callback' => 'tsp_can_use_packs',
			'args'                => array(
				'packId' => array(
					'required' => true,
					'type'     => 'integer',
				),
			),
		)
	);
	register_rest_route(
		'telegram-stickers/v1',
		'/manual',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tsp_rest_manual_save',
			'permission_callback' => 'tsp_can_use_packs',
		)
	);
	register_rest_route(
		'telegram-stickers/v1',
		'/manual/(?P<id>\d+)',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'tsp_rest_manual_save',
			'permission_callback' => 'tsp_can_use_packs',
		)
	);
}

function tsp_can_use_packs(): bool {
	return current_user_can( 'upload_files' );
}

function tsp_rest_packs(): array {
	$posts = get_posts(
		array(
			'post_type'      => 'tsp_pack',
			'post_status'    => 'any',
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);
	return array_map( 'tsp_pack_payload', $posts );
}

function tsp_pack_payload( WP_Post $pack ): array {
	$items    = get_post_meta( $pack->ID, '_tsp_items', true );
	$items    = is_array( $items ) ? $items : array();
	$stickers = array();
	foreach ( $items as $item ) {
		$sticker = tsp_sticker_payload( $item );
		if ( $sticker ) {
			$stickers[] = $sticker;
		}
	}
	$retired  = get_post_meta( $pack->ID, '_tsp_retired_items', true );
	$retired  = is_array( $retired ) ? $retired : array();
	$archived = array();
	foreach ( $retired as $item ) {
		$sticker = tsp_sticker_payload( $item );
		if ( $sticker ) {
			$archived[] = $sticker;
		}
	}
	$source = get_post_meta( $pack->ID, '_tsp_source', true );
	$source = in_array( $source, array( 'manual', 'signal' ), true ) ? $source : 'telegram';
	return array(
		'id'               => $pack->ID,
		'name'             => (string) get_post_meta( $pack->ID, '_tsp_name', true ),
		'title'            => get_the_title( $pack ),
		'source'           => $source,
		'canEdit'          => 'manual' === $source && tsp_can_edit_manual_pack( $pack ),
		'status'           => (string) get_post_meta( $pack->ID, '_tsp_status', true ),
		'stickers'         => $stickers,
		'archivedStickers' => $archived,
	);
}

function tsp_sticker_payload( array $item ): ?array {
	$id = isset( $item['attachment_id'] ) ? (int) $item['attachment_id'] : 0;
	if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
		return null;
	}
	$picker_url = wp_get_attachment_image_url( $id, 'tsp_picker' );
	return array(
		'id'           => (string) ( $item['id'] ?? $id ),
		'attachmentId' => $id,
		'emoji'        => (string) ( $item['emoji'] ?? '' ),
		'alt'          => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		'url'          => $picker_url ? $picker_url : wp_get_attachment_url( $id ),
	);
}

/** Manual packs are shared for use, but only their creator or an administrator can change them. */
function tsp_can_edit_manual_pack( WP_Post $pack ): bool {
	return current_user_can( 'upload_files' ) && ( current_user_can( 'manage_options' ) || get_current_user_id() === (int) $pack->post_author );
}

function tsp_pack_name( string $input ) {
	$input = trim( $input );
	if ( preg_match( '/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $input ) ) {
		return $input;
	}
	$parts = wp_parse_url( $input );
	if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || ! in_array( strtolower( $parts['host'] ?? '' ), array( 't.me', 'telegram.me' ), true ) || isset( $parts['user'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
		return new WP_Error( 'tsp_bad_pack', __( 'Enter a Telegram sticker pack link or pack name.', 'telegram-stickers' ), array( 'status' => 400 ) );
	}
	$path = trim( $parts['path'] ?? '', '/' );
	if ( ! preg_match( '#^addstickers/([A-Za-z][A-Za-z0-9_]{0,63})$#', $path, $matches ) ) {
		return new WP_Error( 'tsp_bad_pack', __( 'Enter a Telegram sticker pack link or pack name.', 'telegram-stickers' ), array( 'status' => 400 ) );
	}
	return $matches[1];
}

/** Find an imported pack without an unindexed post-meta SQL query. */
function tsp_find_pack( string $name ): int {
	$page = 1;
	do {
		$ids = get_posts(
			array(
				'post_type'      => 'tsp_pack',
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'paged'          => $page,
				'fields'         => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			if ( get_post_meta( $id, '_tsp_name', true ) === $name ) {
				return (int) $id;
			}
		}
		$count = count( $ids );
		++$page;
	} while ( 100 === $count );
	return 0;
}
