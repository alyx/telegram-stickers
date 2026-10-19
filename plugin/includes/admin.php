<?php

defined( 'ABSPATH' ) || exit;

function tsp_settings(): array {
	$settings = get_option( TSP_SETTINGS, array() );
	return is_array( $settings ) ? wp_parse_args(
		$settings,
		array(
			'mode'        => 'hosted',
			'service_url' => '',
		)
	) : array(
		'mode'        => 'hosted',
		'service_url' => '',
	);
}

function tsp_admin_menu(): void {
	add_media_page( __( 'Sticker Packs', 'telegram-stickers' ), __( 'Sticker Packs', 'telegram-stickers' ), 'upload_files', 'telegram-stickers', 'tsp_admin_page' );
}

function tsp_admin_page(): void {
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_die( esc_html__( 'You cannot import media.', 'telegram-stickers' ) );
	}
	$settings = tsp_settings();
	?>
	<div class="wrap tsp-admin">
		<h1><?php esc_html_e( 'Sticker Packs', 'telegram-stickers' ); ?></h1>
		<p><?php esc_html_e( 'Make a pack from your own images or import one from Telegram. Pick individual stickers in the editor.', 'telegram-stickers' ); ?></p>
		<?php if ( isset( $_GET['settings_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'telegram-stickers' ); ?></p></div>
		<?php endif; ?>
		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<details class="tsp-settings">
				<summary><?php esc_html_e( 'Connection settings', 'telegram-stickers' ); ?></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'tsp_save_settings' ); ?>
					<input type="hidden" name="action" value="tsp_save_settings">
					<p><label for="tsp-mode"><strong><?php esc_html_e( 'Import connection', 'telegram-stickers' ); ?></strong></label><br>
					<select id="tsp-mode" name="mode">
						<option value="hosted" <?php selected( $settings['mode'], 'hosted' ); ?>><?php esc_html_e( 'Hosted service', 'telegram-stickers' ); ?></option>
						<option value="byo" <?php selected( $settings['mode'], 'byo' ); ?>><?php esc_html_e( 'My Telegram bot token', 'telegram-stickers' ); ?></option>
					</select></p>
					<p><label for="tsp-service-url"><?php esc_html_e( 'Hosted service URL', 'telegram-stickers' ); ?></label><br>
					<input class="regular-text" id="tsp-service-url" name="service_url" type="url" value="<?php echo esc_attr( $settings['service_url'] ); ?>" placeholder="https://stickers.example.com"></p>
					<p class="description"><?php esc_html_e( 'HTTPS required, except for localhost development.', 'telegram-stickers' ); ?></p>
					<p><label for="tsp-token"><?php esc_html_e( 'Telegram bot token', 'telegram-stickers' ); ?></label><br>
					<input class="regular-text" id="tsp-token" name="bot_token" type="password" autocomplete="new-password" placeholder="<?php echo get_option( TSP_TOKEN ) ? esc_attr__( 'Token saved; leave blank to keep it', 'telegram-stickers' ) : ''; ?>"></p>
					<p class="description"><?php esc_html_e( 'Only administrators can change connection settings. The token is never sent to the editor.', 'telegram-stickers' ); ?></p>
					<?php submit_button( __( 'Save settings', 'telegram-stickers' ) ); ?>
				</form>
			</details>
		<?php else : ?>
			<p class="description"><?php echo 'byo' === $settings['mode'] ? esc_html__( 'The site uses its own Telegram bot.', 'telegram-stickers' ) : esc_html__( 'The site uses a hosted sticker service.', 'telegram-stickers' ); ?></p>
		<?php endif; ?>
		<div class="tsp-manual-box">
			<h2 id="tsp-manual-heading"><?php esc_html_e( 'Create a pack', 'telegram-stickers' ); ?></h2>
			<p><?php esc_html_e( 'Choose PNG, WebP, or JPEG images from the Media Library, or upload new ones. A Telegram connection is not needed.', 'telegram-stickers' ); ?></p>
			<label for="tsp-manual-title"><strong><?php esc_html_e( 'Pack name', 'telegram-stickers' ); ?></strong></label><br>
			<input id="tsp-manual-title" class="regular-text" type="text" maxlength="200" autocomplete="off">
			<div class="tsp-manual-actions">
				<button id="tsp-manual-add" class="button button-secondary" type="button"><?php esc_html_e( 'Add images', 'telegram-stickers' ); ?></button>
				<button id="tsp-manual-save" class="button button-primary" type="button"><?php esc_html_e( 'Create pack', 'telegram-stickers' ); ?></button>
				<button id="tsp-manual-cancel" class="button" type="button" hidden><?php esc_html_e( 'Cancel editing', 'telegram-stickers' ); ?></button>
			</div>
			<ol id="tsp-manual-images" class="tsp-manual-images" aria-label="<?php esc_attr_e( 'Images in this pack, in order', 'telegram-stickers' ); ?>"></ol>
			<p id="tsp-manual-hint" class="description"><?php esc_html_e( 'Add at least one image. Use the arrow buttons to change the order. Removing an image from a pack does not delete it from the Media Library or existing posts.', 'telegram-stickers' ); ?></p>
			<div id="tsp-manual-status" role="status" aria-live="polite"></div>
		</div>
		<div class="tsp-import-box">
			<h2><?php esc_html_e( 'Import a pack', 'telegram-stickers' ); ?></h2>
			<p><?php esc_html_e( 'Import a public Telegram pack using the connection configured above.', 'telegram-stickers' ); ?></p>
			<label for="tsp-pack-url"><?php esc_html_e( 'Telegram pack link or name', 'telegram-stickers' ); ?></label>
			<div class="tsp-import-row">
				<input id="tsp-pack-url" class="regular-text" type="text" placeholder="https://t.me/addstickers/alyxbutmore" autocomplete="off">
				<button id="tsp-import-button" class="button button-primary" type="button"><?php esc_html_e( 'Import pack', 'telegram-stickers' ); ?></button>
			</div>
			<div id="tsp-import-status" role="status" aria-live="polite"></div>
			<progress id="tsp-import-progress" max="1" value="0" hidden></progress>
		</div>
		<h2><?php esc_html_e( 'Sticker packs', 'telegram-stickers' ); ?></h2>
		<div id="tsp-pack-list"><p><?php esc_html_e( 'Loading packs…', 'telegram-stickers' ); ?></p></div>
	</div>
	<?php
}

function tsp_save_settings(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You cannot change connection settings.', 'telegram-stickers' ), '', 403 );
	}
	check_admin_referer( 'tsp_save_settings' );
	$mode = isset( $_POST['mode'] ) && 'byo' === sanitize_key( wp_unslash( $_POST['mode'] ) ) ? 'byo' : 'hosted';
	$url  = isset( $_POST['service_url'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['service_url'] ) ) ) : '';
	if ( '' !== $url && ! tsp_valid_service_url( $url ) ) {
		wp_die( esc_html__( 'Use an HTTPS service URL, or localhost for development.', 'telegram-stickers' ), '', 400 );
	}
	update_option(
		TSP_SETTINGS,
		array(
			'mode'        => $mode,
			'service_url' => untrailingslashit( esc_url_raw( $url ) ),
		),
		false
	);
	$token = isset( $_POST['bot_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['bot_token'] ) ) ) : '';
	if ( '' !== $token ) {
		if ( ! preg_match( '/^[0-9]+:[A-Za-z0-9_-]+$/', $token ) ) {
			wp_die( esc_html__( 'Invalid bot token format.', 'telegram-stickers' ), '', 400 );
		}
		update_option( TSP_TOKEN, $token, false );
	}
	wp_safe_redirect( admin_url( 'upload.php?page=telegram-stickers&settings_saved=1' ) );
	exit;
}

function tsp_valid_service_url( string $url ): bool {
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || ( isset( $parts['path'] ) && '/' !== $parts['path'] ) ) {
		return false;
	}
	$host = strtolower( $parts['host'] );
	return 'https' === $parts['scheme'] || ( 'http' === $parts['scheme'] && in_array( $host, array( 'localhost', '127.0.0.1', '[::1]' ), true ) );
}

function tsp_admin_assets( string $hook ): void {
	if ( 'media_page_telegram-stickers' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'tsp-admin', plugins_url( 'assets/admin.css', TSP_PLUGIN_FILE ), array(), TSP_VERSION );
	wp_enqueue_media();
	wp_enqueue_script( 'tsp-admin', plugins_url( 'assets/admin.js', TSP_PLUGIN_FILE ), array( 'wp-i18n' ), TSP_VERSION, true );
	wp_set_script_translations( 'tsp-admin', 'telegram-stickers' );
	wp_add_inline_script(
		'tsp-admin',
		'window.tspAdmin = ' . wp_json_encode(
			array(
				'api'   => esc_url_raw( rest_url( 'telegram-stickers/v1/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		) . ';',
		'before'
	);
}
