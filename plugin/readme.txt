=== Telegram Stickers ===
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create packs from Media Library images or import static Telegram and Signal sticker packs, then select stickers in the block editor.

== Description ==

Authors and other users with the upload_files capability can create packs from PNG, WebP, and JPEG images in the Media Library or import static stickers from Telegram and Signal packs. Imported images are saved on your WordPress site. Insert the Sticker block into posts to choose an individual image.

Manual packs are shared for use. Their creator and administrators can edit them. Removing an image from a pack keeps it in the Media Library and preserves existing posts that use it.

Telegram imports require an administrator to connect either a hosted sticker service or the site's own Telegram bot token. Signal imports use Signal's CDN directly and need only a pack link containing its decryption key. The key is saved in private WordPress post meta for pack refreshes and is not sent to the editor. Animated and video stickers are skipped.

== Installation ==

1. Install and activate the plugin.
2. Open Media > Sticker Packs to make a pack from images, import a Telegram t.me/addstickers pack, or import a Signal signal.art/addstickers link. Telegram imports require an administrator to set a hosted service URL or bot token under Connection settings.
3. Add the Sticker block in a post to select a sticker.

== Changelog ==

= 0.3.0 =
* Import static Signal PNG and WebP sticker packs directly from Signal's CDN without a bot or Worker.
* Refresh Signal packs using the privately saved decryption key.

= 0.2.0 =
* Create and edit packs from Media Library images without a Telegram connection.
* Keep existing post images when a sticker is removed from a manual pack.

= 0.1.2 =
* Add coding standards and import regression checks.
* Organize the import, provider, admin, and block code into small modules.

= 0.1.1 =
* Fix a missing WordPress file include during REST imports.

= 0.1.0 =
* Initial static sticker import and block editor picker.
