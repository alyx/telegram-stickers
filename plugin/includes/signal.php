<?php
/** Signal's public CDN serves encrypted sticker packs; the key stays on this site. */

defined( 'ABSPATH' ) || exit;

function tsp_signal_link( string $input ) {
	$parts = wp_parse_url( trim( $input ) );
	if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || 'signal.art' !== strtolower( $parts['host'] ?? '' ) || ! in_array( $parts['path'] ?? '', array( '/addstickers', '/addstickers/' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) ) {
		return new WP_Error( 'tsp_signal_link', __( 'Enter a Signal sticker pack link.', 'telegram-stickers' ), array( 'status' => 400 ) );
	}
	$fragment = (string) ( $parts['fragment'] ?? '' );
	if ( ! preg_match( '/^pack_id=([a-fA-F0-9]{32})&pack_key=([a-fA-F0-9]{64})$/', $fragment, $matches ) ) {
		return new WP_Error( 'tsp_signal_link', __( 'The Signal link needs a pack ID and key.', 'telegram-stickers' ), array( 'status' => 400 ) );
	}
	return array( strtolower( $matches[1] ), strtolower( $matches[2] ) );
}

function tsp_signal_fetch( string $pack_id, string $path, int $limit ) {
	$url      = 'https://cdn.signal.org/stickers/' . $pack_id . '/' . $path;
	$response = wp_remote_get(
		$url,
		array(
			'timeout'             => 20,
			'redirection'         => 0,
			'limit_response_size' => $limit + 1,
			'sslcertificates'     => dirname( __DIR__ ) . '/certs/signal-ca.pem',
		)
	);
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'tsp_signal_fetch', __( 'Could not reach Signal. Retry the import.', 'telegram-stickers' ), array( 'status' => 502 ) );
	}
	if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return new WP_Error( 'tsp_signal_fetch', __( 'Signal could not find this pack or sticker.', 'telegram-stickers' ), array( 'status' => 502 ) );
	}
	$body = wp_remote_retrieve_body( $response );
	if ( ! is_string( $body ) || strlen( $body ) < 64 || strlen( $body ) > $limit ) {
		return new WP_Error( 'tsp_signal_fetch', __( 'Signal returned an invalid or oversized file.', 'telegram-stickers' ), array( 'status' => 502 ) );
	}
	return $body;
}

function tsp_signal_decrypt( string $encrypted, string $pack_key ) {
	if ( ! preg_match( '/^[a-f0-9]{64}$/', $pack_key ) || strlen( $encrypted ) < 64 ) {
		return new WP_Error( 'tsp_signal_crypto', __( 'Invalid Signal pack key or encrypted file.', 'telegram-stickers' ), array( 'status' => 400 ) );
	}
	$derived = hash_hkdf( 'sha256', hex2bin( $pack_key ), 64, 'Sticker Pack' );
	$iv      = substr( $encrypted, 0, 16 );
	$payload = substr( $encrypted, 0, -32 );
	$mac     = substr( $encrypted, -32 );
	if ( ! hash_equals( hash_hmac( 'sha256', $payload, substr( $derived, 32, 32 ), true ), $mac ) ) {
		return new WP_Error( 'tsp_signal_crypto', __( 'The Signal pack key is incorrect or the file is damaged.', 'telegram-stickers' ), array( 'status' => 400 ) );
	}
	$plain = openssl_decrypt( substr( $payload, 16 ), 'aes-256-cbc', substr( $derived, 0, 32 ), OPENSSL_RAW_DATA, $iv );
	return false === $plain ? new WP_Error( 'tsp_signal_crypto', __( 'Could not decrypt the Signal sticker file.', 'telegram-stickers' ), array( 'status' => 502 ) ) : $plain;
}

/** Read only the fields in Signal's small Sticker Pack protobuf schema. */
function tsp_signal_fields( string $bytes ) {
	$fields = array();
	$length = strlen( $bytes );
	$offset = 0;
	while ( $offset < $length ) {
		$tag = tsp_signal_varint( $bytes, $offset );
		if ( null === $tag || 0 === $tag ) {
			return null;
		}
		$field = $tag >> 3;
		$wire  = $tag & 7;
		if ( 0 === $field ) {
			return null;
		}
		if ( 0 === $wire ) {
			$value = tsp_signal_varint( $bytes, $offset );
		} elseif ( 2 === $wire ) {
			$size = tsp_signal_varint( $bytes, $offset );
			if ( null === $size || $size > $length - $offset ) {
				return null;
			}
			$value   = substr( $bytes, $offset, $size );
			$offset += $size;
		} elseif ( 1 === $wire || 5 === $wire ) {
			$size = 1 === $wire ? 8 : 4;
			if ( $size > $length - $offset ) {
				return null;
			}
			$offset += $size;
			continue;
		} else {
			return null;
		}
		if ( null === $value ) {
			return null;
		}
		$fields[ $field ][] = $value;
	}
	return $fields;
}

function tsp_signal_varint( string $bytes, int &$offset ): ?int {
	$value  = 0;
	$length = strlen( $bytes );
	for ( $shift = 0; $shift <= 56 && $offset < $length; $shift += 7 ) {
		$byte   = ord( $bytes[ $offset++ ] );
		$value |= ( $byte & 127 ) << $shift;
		if ( 0 === ( $byte & 128 ) ) {
			return $value;
		}
	}
	return null;
}

function tsp_signal_manifest( string $bytes ) {
	$fields = tsp_signal_fields( $bytes );
	if ( ! is_array( $fields ) || ! isset( $fields[1][0], $fields[4] ) || ! is_string( $fields[1][0] ) || ! count( $fields[4] ) || count( $fields[4] ) > 200 ) {
		return new WP_Error( 'tsp_signal_manifest', __( 'The Signal pack manifest is invalid.', 'telegram-stickers' ), array( 'status' => 502 ) );
	}
	$title = sanitize_text_field( $fields[1][0] );
	if ( '' === $title ) {
		return new WP_Error( 'tsp_signal_manifest', __( 'The Signal pack has no title.', 'telegram-stickers' ), array( 'status' => 502 ) );
	}
	$stickers = array();
	$seen     = array();
	foreach ( $fields[4] as $entry ) {
		$sticker = is_string( $entry ) ? tsp_signal_fields( $entry ) : null;
		$id      = $sticker[1][0] ?? null;
		if ( ! is_int( $id ) || $id < 0 || $id > 4294967295 || isset( $seen[ $id ] ) ) {
			return new WP_Error( 'tsp_signal_manifest', __( 'The Signal pack manifest has an invalid sticker.', 'telegram-stickers' ), array( 'status' => 502 ) );
		}
		$seen[ $id ] = true;
		$stickers[]  = array(
			'id'    => (string) $id,
			'emoji' => sanitize_text_field( is_string( $sticker[2][0] ?? null ) ? $sticker[2][0] : '' ),
		);
	}
	return array(
		'title'    => $title,
		'author'   => sanitize_text_field( is_string( $fields[2][0] ?? null ) ? $fields[2][0] : '' ),
		'stickers' => $stickers,
	);
}

function tsp_signal_start( string $pack_id, string $pack_key ) {
	$encrypted = tsp_signal_fetch( $pack_id, 'manifest.proto', 262144 );
	if ( is_wp_error( $encrypted ) ) {
		return $encrypted;
	}
	$plain = tsp_signal_decrypt( $encrypted, $pack_key );
	if ( is_wp_error( $plain ) ) {
		return $plain;
	}
	$manifest = tsp_signal_manifest( $plain );
	if ( is_wp_error( $manifest ) ) {
		return $manifest;
	}
	$name     = 'signal:' . $pack_id;
	$existing = tsp_find_pack( $name );
	$saved    = $existing ? $existing : wp_insert_post(
		array(
			'post_type'   => 'tsp_pack',
			'post_status' => 'publish',
			'post_title'  => $manifest['title'],
		),
		true
	);
	if ( is_wp_error( $saved ) ) {
		return new WP_Error( 'tsp_save', __( 'Could not save the Signal pack.', 'telegram-stickers' ), array( 'status' => 500 ) );
	}
	wp_update_post(
		array(
			'ID'         => $saved,
			'post_title' => $manifest['title'],
		)
	);
	update_post_meta( $saved, '_tsp_name', $name );
	update_post_meta( $saved, '_tsp_source', 'signal' );
	update_post_meta( $saved, '_tsp_signal_key', $pack_key );
	update_post_meta( $saved, '_tsp_manifest', $manifest['stickers'] );
	update_post_meta( $saved, '_tsp_cursor', 0 );
	update_post_meta( $saved, '_tsp_skipped', 0 );
	update_post_meta( $saved, '_tsp_status', 'importing' );
	return array(
		'packId'  => (int) $saved,
		'total'   => count( $manifest['stickers'] ),
		'skipped' => 0,
		'done'    => 0,
	);
}

function tsp_rest_signal_start( WP_REST_Request $request ) {
	$link = tsp_signal_link( (string) $request->get_param( 'pack' ) );
	return is_wp_error( $link ) ? $link : tsp_signal_start( $link[0], $link[1] );
}

function tsp_rest_signal_refresh( WP_REST_Request $request ) {
	$pack_id = (int) $request->get_param( 'packId' );
	if ( 'tsp_pack' !== get_post_type( $pack_id ) || 'signal' !== get_post_meta( $pack_id, '_tsp_source', true ) ) {
		return new WP_Error( 'tsp_pack', __( 'Signal pack not found.', 'telegram-stickers' ), array( 'status' => 404 ) );
	}
	$name = (string) get_post_meta( $pack_id, '_tsp_name', true );
	$key  = (string) get_post_meta( $pack_id, '_tsp_signal_key', true );
	if ( ! preg_match( '/^signal:([a-f0-9]{32})$/', $name, $matches ) || ! preg_match( '/^[a-f0-9]{64}$/', $key ) ) {
		return new WP_Error( 'tsp_signal_key', __( 'This pack has no saved Signal key. Import its link again.', 'telegram-stickers' ), array( 'status' => 400 ) );
	}
	return tsp_signal_start( $matches[1], $key );
}

/** Signal animated WebP contains an ANIM chunk; skip it for the static MVP. */
function tsp_signal_animated_webp( string $bytes ): bool {
	if ( strlen( $bytes ) < 12 || 'RIFF' !== substr( $bytes, 0, 4 ) || 'WEBP' !== substr( $bytes, 8, 4 ) ) {
		return false;
	}
	$offset = 12;
	$length = strlen( $bytes );
	while ( $offset + 8 <= $length ) {
		$type = substr( $bytes, $offset, 4 );
		$size = unpack( 'V', substr( $bytes, $offset + 4, 4 ) )[1];
		if ( 'ANIM' === $type || 'ANMF' === $type ) {
			return true;
		}
		if ( $size > $length - $offset - 8 ) {
			break;
		}
		if ( 'VP8X' === $type && $size >= 1 && 0 !== ( ord( $bytes[ $offset + 8 ] ) & 2 ) ) {
			return true;
		}
		$offset += 8 + $size + ( $size & 1 );
	}
	return false;
}

/** An APNG has an animation-control chunk; ordinary PNGs remain importable. */
function tsp_signal_animated_png( string $bytes ): bool {
	if ( ! str_starts_with( $bytes, "\x89PNG\r\n\x1a\n" ) ) {
		return false;
	}
	$offset = 8;
	$length = strlen( $bytes );
	while ( $offset + 12 <= $length ) {
		$size = unpack( 'N', substr( $bytes, $offset, 4 ) )[1];
		$type = substr( $bytes, $offset + 4, 4 );
		if ( 'acTL' === $type ) {
			return true;
		}
		if ( $size > $length - $offset - 12 ) {
			break;
		}
		$offset += 12 + $size;
	}
	return false;
}

function tsp_signal_import_step( int $pack_id ) {
	$manifest = get_post_meta( $pack_id, '_tsp_manifest', true );
	if ( ! is_array( $manifest ) ) {
		return new WP_Error( 'tsp_pack', __( 'No import is pending for this pack.', 'telegram-stickers' ), array( 'status' => 400 ) );
	}
	$name = (string) get_post_meta( $pack_id, '_tsp_name', true );
	$key  = (string) get_post_meta( $pack_id, '_tsp_signal_key', true );
	if ( ! preg_match( '/^signal:([a-f0-9]{32})$/', $name, $matches ) || ! preg_match( '/^[a-f0-9]{64}$/', $key ) ) {
		return new WP_Error( 'tsp_signal_key', __( 'The Signal pack key is missing.', 'telegram-stickers' ), array( 'status' => 400 ) );
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
	$skipped = (int) get_post_meta( $pack_id, '_tsp_skipped', true );
	$limit   = min( $cursor + 4, count( $manifest ) );
	for ( $index = $cursor; $index < $limit; $index++ ) {
		$sticker = $manifest[ $index ];
		if ( ! isset( $sticker['id'] ) || ! preg_match( '/^\d{1,10}$/', (string) $sticker['id'] ) ) {
			update_post_meta( $pack_id, '_tsp_status', 'error' );
			return new WP_Error( 'tsp_signal_manifest', __( 'The Signal pack manifest is invalid.', 'telegram-stickers' ), array( 'status' => 502 ) );
		}
		$id = $existing[ $sticker['id'] ] ?? 0;
		if ( ! $id ) {
			$encrypted = tsp_signal_fetch( $matches[1], 'full/' . $sticker['id'], 2000064 );
			$bytes     = is_wp_error( $encrypted ) ? $encrypted : tsp_signal_decrypt( $encrypted, $key );
			if ( is_wp_error( $bytes ) ) {
				update_post_meta( $pack_id, '_tsp_status', 'error' );
				return $bytes;
			}
			if ( tsp_signal_animated_webp( $bytes ) || tsp_signal_animated_png( $bytes ) ) {
				++$skipped;
				update_post_meta( $pack_id, '_tsp_skipped', $skipped );
				update_post_meta( $pack_id, '_tsp_cursor', $index + 1 );
				continue;
			}
			$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Reject invalid remote images.
			$mime = is_array( $info ) ? ( $info['mime'] ?? '' ) : '';
			if ( ! in_array( $mime, array( 'image/webp', 'image/png' ), true ) || strlen( $bytes ) > 2000000 || $info[0] > 1024 || $info[1] > 1024 ) {
				update_post_meta( $pack_id, '_tsp_status', 'error' );
				return new WP_Error( 'tsp_signal_image', __( 'Signal returned an unsupported sticker image.', 'telegram-stickers' ), array( 'status' => 502 ) );
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$temp = wp_tempnam( 'tsp-signal' );
			if ( ! $temp || false === file_put_contents( $temp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporary sideload file.
				if ( $temp ) {
					wp_delete_file( $temp );
				}
				update_post_meta( $pack_id, '_tsp_status', 'error' );
				return new WP_Error( 'tsp_temp', __( 'Could not create a temporary sticker file.', 'telegram-stickers' ), array( 'status' => 500 ) );
			}
			$id = tsp_attach_image(
				array(
					'path'      => $temp,
					'extension' => 'image/png' === $mime ? 'png' : 'webp',
				),
				$pack_id,
				$sticker
			);
			if ( file_exists( $temp ) ) {
				wp_delete_file( $temp );
			}
			if ( is_wp_error( $id ) ) {
				update_post_meta( $pack_id, '_tsp_status', 'error' );
				return $id;
			}
		}
		$items   = array_values( array_filter( $items, static fn( $item ) => ( $item['id'] ?? '' ) !== $sticker['id'] ) );
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
		$order   = array_flip( array_column( $manifest, 'id' ) );
		$retired = get_post_meta( $pack_id, '_tsp_retired_items', true );
		$retired = is_array( $retired ) ? $retired : array();
		$active  = array();
		foreach ( $items as $item ) {
			if ( isset( $order[ $item['id'] ] ) ) {
				$active[] = $item;
			} else {
				$retired[] = $item;
			}
		}
		if ( ! $active ) {
			update_post_meta( $pack_id, '_tsp_status', 'error' );
			return new WP_Error( 'tsp_no_static', __( 'This Signal pack has no static stickers to import.', 'telegram-stickers' ), array( 'status' => 400 ) );
		}
		$items = $active;
		usort( $items, static fn( $a, $b ) => ( $order[ $a['id'] ] ?? PHP_INT_MAX ) <=> ( $order[ $b['id'] ] ?? PHP_INT_MAX ) );
		update_post_meta( $pack_id, '_tsp_items', $items );
		update_post_meta( $pack_id, '_tsp_retired_items', $retired );
		update_post_meta( $pack_id, '_tsp_status', 'ready' );
		delete_post_meta( $pack_id, '_tsp_manifest' );
		delete_post_meta( $pack_id, '_tsp_cursor' );
	}
	return array(
		'packId'   => $pack_id,
		'done'     => $done,
		'total'    => count( $manifest ),
		'skipped'  => $skipped,
		'complete' => $complete,
		'pack'     => $complete ? tsp_pack_payload( get_post( $pack_id ) ) : null,
	);
}
