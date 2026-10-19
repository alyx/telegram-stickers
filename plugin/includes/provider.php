<?php

defined( 'ABSPATH' ) || exit;

function tsp_request_json( string $url, array $args = array() ) {
	$args     = wp_parse_args(
		$args,
		array(
			'timeout'             => 15,
			'redirection'         => 0,
			'limit_response_size' => 1000000,
		)
	);
	$response = wp_remote_request( $url, $args );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return new WP_Error( 'tsp_provider', __( 'The sticker service could not load this pack.', 'telegram-stickers' ), array( 'status' => 502 ) );
	}
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	return is_array( $data ) ? $data : new WP_Error( 'tsp_provider', __( 'The sticker service returned invalid data.', 'telegram-stickers' ), array( 'status' => 502 ) );
}

function tsp_telegram_api( string $method, array $body ) {
	$token = (string) get_option( TSP_TOKEN, '' );
	if ( '' === $token ) {
		return new WP_Error( 'tsp_config', __( 'An administrator must configure a Telegram bot token.', 'telegram-stickers' ), array( 'status' => 503 ) );
	}
	$data = tsp_request_json(
		'https://api.telegram.org/bot' . $token . '/' . $method,
		array(
			'method' => 'POST',
			'body'   => $body,
		)
	);
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	return ! empty( $data['ok'] ) && isset( $data['result'] ) ? $data['result'] : new WP_Error( 'tsp_telegram', __( 'Telegram could not load this pack.', 'telegram-stickers' ), array( 'status' => 502 ) );
}

function tsp_manifest( string $name ) {
	$settings = tsp_settings();
	if ( 'byo' === $settings['mode'] ) {
		$pack = tsp_telegram_api( 'getStickerSet', array( 'name' => $name ) );
		if ( is_wp_error( $pack ) ) {
			return $pack;
		}
		$stickers = array();
		foreach ( $pack['stickers'] ?? array() as $index => $sticker ) {
			$static     = empty( $sticker['is_animated'] ) && empty( $sticker['is_video'] );
			$stickers[] = array(
				'id'        => (string) ( $sticker['file_unique_id'] ?? $pack['name'] . ':' . $index ),
				'emoji'     => (string) ( $sticker['emoji'] ?? '' ),
				'index'     => $index,
				'supported' => $static,
				'download'  => $static ? (string) ( $sticker['file_id'] ?? '' ) : null,
			);
		}
		return array(
			'name'     => $pack['name'] ?? $name,
			'title'    => $pack['title'] ?? $name,
			'stickers' => $stickers,
		);
	}
	$url = (string) $settings['service_url'];
	if ( ! tsp_valid_service_url( $url ) ) {
		return new WP_Error( 'tsp_config', __( 'An administrator must configure the hosted service URL.', 'telegram-stickers' ), array( 'status' => 503 ) );
	}
	return tsp_request_json( untrailingslashit( $url ) . '/v1/packs/' . rawurlencode( $name ) );
}
