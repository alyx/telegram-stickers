#!/usr/bin/env python3
"""Build the installable plugin ZIP from an explicit source allowlist."""

import argparse
from pathlib import Path
import re
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "plugin"
HEADER = (PLUGIN / "telegram-stickers.php").read_text()
match = re.search(r"^ \* Version: (\d+\.\d+\.\d+)$", HEADER, re.MULTILINE)
if not match:
    raise SystemExit("Plugin version header is missing")

version = match.group(1)
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("--release-tag", help="Require this tag to match the plugin version")
args = parser.parse_args()
if args.release_tag and args.release_tag != f"v{version}":
    raise SystemExit(f"Release tag {args.release_tag!r} does not match plugin version v{version}")
if not re.search(rf"^const TSP_VERSION\s*=\s*'{re.escape(version)}';$", HEADER, re.MULTILINE):
    raise SystemExit("Plugin asset version does not match its header")
if f"Stable tag: {version}" not in (PLUGIN / "readme.txt").read_text():
    raise SystemExit("Plugin readme stable tag does not match its header")
files = [
    ROOT / "LICENSE",
    ROOT / "THIRD_PARTY_NOTICES.md",
    ROOT / "licenses" / "signalstickers-client-LGPL-3.0.txt",
    PLUGIN / "telegram-stickers.php",
    PLUGIN / "block.json",
    PLUGIN / "readme.txt",
    PLUGIN / "certs" / "signal-ca.pem",
    *sorted((PLUGIN / "includes").glob("*.php")),
    *sorted((PLUGIN / "assets").glob("*.js")),
    *sorted((PLUGIN / "assets").glob("*.css")),
]
if not all(path.is_file() for path in files):
    raise SystemExit("A plugin source file is missing")

output = ROOT / "dist" / f"telegram-stickers-{version}.zip"
output.parent.mkdir(exist_ok=True)
with ZipFile(output, "w") as archive:
    for path in files:
        relative = path.relative_to(PLUGIN) if path.is_relative_to(PLUGIN) else path.relative_to(ROOT)
        entry = ZipInfo(str(Path("telegram-stickers") / relative))
        entry.date_time = (1980, 1, 1, 0, 0, 0)
        entry.external_attr = 0o644 << 16
        entry.compress_type = ZIP_DEFLATED
        archive.writestr(entry, path.read_bytes())
with ZipFile(output) as archive:
    if archive.testzip() is not None or len(archive.namelist()) != len(files):
        raise SystemExit("Plugin ZIP failed validation")
print(output)
