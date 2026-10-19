<?php

defined( 'ABSPATH' ) || exit;

function tsp_render_sticker( array $attributes ): string {
	$pack_id = (int) ( $attributes['packId'] ?? 0 );
	$id      = (int) ( $attributes['attachmentId'] ?? 0 );
	if ( ! $pack_id || ! $id || 'tsp_pack' !== get_post_type( $pack_id ) ) {
		return '';
	}
	$items   = get_post_meta( $pack_id, '_tsp_items', true );
	$items   = is_array( $items ) ? $items : array();
	$retired = get_post_meta( $pack_id, '_tsp_retired_items', true );
	$retired = is_array( $retired ) ? $retired : array();
	if ( ! in_array( $id, array_map( fn( $item ) => (int) ( $item['attachment_id'] ?? 0 ), array_merge( $items, $retired ) ), true ) ) {
		return '';
	}
	$size  = max( 24, min( 256, (int) ( $attributes['size'] ?? 64 ) ) );
	$alt   = isset( $attributes['alt'] ) ? (string) $attributes['alt'] : '';
	$image = wp_get_attachment_image(
		$id,
		'full',
		false,
		array(
			'alt'   => $alt,
			'class' => 'tsp-sticker-image',
			'style' => 'width:' . $size . 'px;height:' . $size . 'px;object-fit:contain',
		)
	);
	return $image ? '<span class="wp-block-telegram-stickers-sticker">' . $image . '</span>' : '';
}
