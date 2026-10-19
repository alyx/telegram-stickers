<?php
/**
 * Plugin Name: Telegram Stickers
 * Description: Create sticker packs from your media or import them from Telegram and Signal for use in the block editor.
 * Version: 0.3.0
 * Requires at least: 6.7
 * Requires PHP: 8.1
 * Text Domain: telegram-stickers
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

const TSP_VERSION     = '0.3.0';
const TSP_SETTINGS    = 'tsp_settings';
const TSP_TOKEN       = 'tsp_bot_token';
const TSP_PLUGIN_FILE = __FILE__;

require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/packs.php';
require_once __DIR__ . '/includes/manual.php';
require_once __DIR__ . '/includes/provider.php';
require_once __DIR__ . '/includes/import.php';
require_once __DIR__ . '/includes/signal.php';
require_once __DIR__ . '/includes/block.php';

add_action( 'init', 'tsp_init' );
add_action( 'admin_menu', 'tsp_admin_menu' );
add_action( 'admin_post_tsp_save_settings', 'tsp_save_settings' );
add_action( 'admin_enqueue_scripts', 'tsp_admin_assets' );
add_action( 'rest_api_init', 'tsp_rest_routes' );

function tsp_init(): void {
	register_post_type(
		'tsp_pack',
		array(
			'label'        => __( 'Sticker Packs', 'telegram-stickers' ),
			'public'       => false,
			'show_ui'      => false,
			'supports'     => array( 'title' ),
			'map_meta_cap' => true,
		)
	);
	add_image_size( 'tsp_picker', 96, 96, false );
	wp_register_script(
		'tsp-editor',
		plugins_url( 'assets/editor.js', __FILE__ ),
		array( 'wp-api-fetch', 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
		TSP_VERSION,
		true
	);
	wp_set_script_translations( 'tsp-editor', 'telegram-stickers' );
	wp_add_inline_script(
		'tsp-editor',
		'window.tspEditor = ' . wp_json_encode(
			array(
				'importUrl' => admin_url( 'upload.php?page=telegram-stickers' ),
			)
		) . ';',
		'before'
	);
	register_block_type(
		__DIR__,
		array(
			'editor_script'   => 'tsp-editor',
			'render_callback' => 'tsp_render_sticker',
		)
	);
}
