# Telegram Stickers for WordPress

**By alyx.** Make sticker packs from your WordPress Media Library, or import
static Telegram and Signal packs. Then choose a sticker in the block editor.
Imported images live on your site, so published posts use local attachments.

- **Make your own:** collect, reorder, and edit packs from images you already have.
- **Import:** paste a Telegram or Signal pack link; imports can be retried.
- **Write:** choose a pack and sticker from a searchable grid in the Sticker block.

Animated and video stickers are skipped in this MVP.

## WordPress plugin

Install the attached GitHub Release ZIP through **Plugins → Add New → Upload
Plugin**, or copy `plugin/` to
`wp-content/plugins/telegram-stickers/`, then activate **Telegram Stickers**.
Requires WordPress 6.7+ and PHP 8.1+.

Open **Media → Sticker Packs** to make or import a pack. Anyone with
`upload_files` can use this screen. In a post, add the **Sticker** block and
choose a pack, an image, its display size, and alternative text.

An administrator opens **Media → Sticker Packs → Connection settings** and chooses:

- **Hosted service:** provide the root URL of a running Worker. The local development
  URL is `http://127.0.0.1:8787`. Production services must use HTTPS.
- **My Telegram bot token:** enter a token obtained from BotFather. The token stays
  in a non-autoloaded WordPress option and is never passed to editor JavaScript.

Create a pack from PNG, WebP, or JPEG images already in the Media Library or
upload new ones.
Images can be reordered or removed. Manual packs are shared for use; the creator
or an administrator can edit them. Removing an image from a pack leaves the Media
Library file and existing post render intact. Manual packs need no Telegram service.

The same page imports public `https://t.me/addstickers/<name>` packs. Imports run
in batches of four and can be retried; existing stickers are reused. The
page also imports `https://signal.art/addstickers/#pack_id=…&pack_key=…` links
without a bot or Worker. Signal's pack key stays in private WordPress post meta
for refreshes and is not returned to the editor. Signal imports decrypt the
manifest and sticker files on the site using PHP's OpenSSL and hash extensions.
Static PNG files are supported too; animated APNG and WebP files are skipped.
`plugin/certs/signal-ca.pem` is Signal's CA certificate from the
[signalstickers-client project](https://github.com/signalstickers/signalstickers-client/blob/master/signalstickers_client/utils/ca/cacert.pem).
The CDN request verifies against it; maintainers should review it before its
2032 expiration.
The **Sticker** block presents a pack selector, a keyboard-accessible sticker
grid, a filter, a live preview, display size, and alternative text.

## License and acknowledgments

Copyright © 2026 alyx. Original plugin and Worker code is licensed under
**GPL-3.0-or-later**; see [LICENSE](LICENSE). The WordPress ZIP includes the
license and [third-party notices](THIRD_PARTY_NOTICES.md).

[TStickers](https://github.com/FHPythonUtils/TStickers) (MIT) inspired the
Telegram import flow. [signalstickers-client](https://github.com/signalstickers/signalstickers-client)
(LGPLv3) documented the Signal sticker protocol and supplied the bundled Signal
CA certificate. Their Python code and dependencies are not shipped. See the
notices for attribution and the included LGPLv3 text. This is an independent
plugin, unaffiliated with Telegram or Signal.

## Local Worker

The Worker source is in `worker/`. Install dependencies with `npm ci`, then create
`worker/.dev.vars` with:

```
TELEGRAM_BOT_TOKEN=your-bot-token
TICKET_SECRET=a-long-random-string
```

Keep this file private (`chmod 600 worker/.dev.vars`). Start with `npm run dev`
from `worker/`. Wrangler serves `http://127.0.0.1:8787`.

The Worker offers `GET /health`, `GET /v1/packs/<name>`, and a signed, temporary
`GET /v1/files/<ticket>` route. It never returns the bot token or a Telegram file
URL to WordPress. This MVP has **no client authentication or quotas**; do not
publish the Worker to the Internet until those controls are added. The WordPress
plugin has a separate connection provider, so the hosted service can be added
later without changing saved packs or blocks.

For a production deployment, set `TELEGRAM_BOT_TOKEN` and `TICKET_SECRET` as
Worker secrets and update the plugin's hosted service URL to the deployed HTTPS
endpoint. The local `.dev.vars` is for development only.

## Development and maintenance

`plugin/telegram-stickers.php` registers the block and WordPress hooks. The
`plugin/includes/` files separate the admin screen, pack REST API, Telegram or
hosted provider, Signal import, and block rendering. The Worker has no WordPress
dependency. No Composer or Node packages are shipped in the plugin ZIP.

Install the development tools, then run the same checks used by CI:

```sh
composer install
npm ci
npm ci --prefix worker
composer lint:php
npm run lint:js
npm run lint:css
npm run test:worker
```

The WordPress smoke tests use fake providers, so they need no Telegram token or
Signal network connection. They check permissions, manual pack creation and
editing, pack validation, import, animated Signal sticker skipping, attachment
reuse on retry, key privacy, and block rendering in a real WordPress instance.
To run it in an isolated Docker
site:

```sh
npm exec --no -- wp-env start
npm run test:wp
npm exec --no -- wp-env stop
```

Run `npm run package` to build `dist/telegram-stickers-<version>.zip`.
The deterministic archive is built from an explicit allowlist and excludes
tests, dependencies, and local secrets. When releasing, update the plugin
header, `TSP_VERSION`, and `plugin/readme.txt` stable tag together; packaging
checks that they agree.

Push a tag matching that version (for example, `v0.3.0`) to publish a GitHub
Release. `.github/workflows/release.yml` runs the full CI workflow, builds the
installable ZIP, verifies it, and attaches the ZIP and `SHA256SUMS` with
generated release notes. A mismatched tag fails before publication. The ZIP
has `telegram-stickers/` as its root directory and can be installed through
**Plugins → Add New → Upload Plugin**. GitHub's automatically generated source
archives are repository snapshots; use the attached plugin ZIP for WordPress.

`.github/workflows/ci.yml` runs PHP coding standards and syntax checks across
supported PHP versions, WordPress JavaScript and CSS linters, Worker tests, the
WordPress smoke test, and the official WordPress Plugin Check action against
the packaged plugin. The source does not contain credentials; `.dev.vars`,
dependencies, and release ZIPs are ignored by Git.
