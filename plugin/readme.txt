=== Telegram Stickers ===
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import static Telegram sticker packs into the Media Library and select stickers in the block editor.

== Description ==

Telegram Stickers imports static WebP and PNG stickers from public Telegram packs. The images are saved on your WordPress site. Authors and other users with the upload_files capability can import packs from Media > Sticker Packs and insert the Telegram Sticker block into posts.

An administrator can connect either a hosted sticker service or the site's own Telegram bot token. Animated and video stickers are skipped.

== Installation ==

1. Install and activate the plugin.
2. As an administrator, open Media > Sticker Packs and set a hosted service URL or a Telegram bot token.
3. Import a public t.me/addstickers pack. Add the Telegram Sticker block in a post to select a sticker.

== Changelog ==

= 0.1.2 =
* Add coding standards and import regression checks.
* Organize the import, provider, admin, and block code into small modules.

= 0.1.1 =
* Fix a missing WordPress file include during REST imports.

= 0.1.0 =
* Initial static sticker import and block editor picker.
