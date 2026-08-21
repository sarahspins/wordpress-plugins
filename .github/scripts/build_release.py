#!/usr/bin/env python3
import json
import re
import shutil
import zipfile
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DIST = ROOT / "dist"
COMPONENTS = {
    "ice-field-dash-connector": ("plugin", "ice-field-dash-connector.php"),
    "ice-field-programming": ("plugin", "ice-field-programming.php"),
    "ice-field-productions": ("plugin", "ice-field-productions.php"),
    "ice-field-rink-displays": ("plugin", "ice-field-rink-displays.php"),
    "ice-field-productions-theme": ("theme", "style.css"),
}


def header(source, name, default=""):
    match = re.search(rf"^\s*(?:\*\s*)?{re.escape(name)}:\s*(.+?)\s*$", source, re.MULTILINE | re.IGNORECASE)
    return match.group(1).strip() if match else default


def archive_component(slug, version):
    destination = DIST / f"{slug}-{version}.zip"
    base = ROOT / slug
    with zipfile.ZipFile(destination, "w", zipfile.ZIP_DEFLATED) as archive:
        for path in sorted(base.rglob("*")):
            if path.is_file() and path.name != ".DS_Store":
                archive.write(path, Path(slug) / path.relative_to(base))
    return destination.name


def main():
    if DIST.exists():
        shutil.rmtree(DIST)
    DIST.mkdir()
    manifest = {
        "repository": "sarahspins/wordpress-plugins",
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "components": {},
    }
    for slug, (kind, entrypoint) in COMPONENTS.items():
        source = (ROOT / slug / entrypoint).read_text(encoding="utf-8")
        version = header(source, "Version")
        if not version:
            raise RuntimeError(f"No Version header found for {slug}")
        manifest["components"][slug] = {
            "type": kind,
            "version": version,
            "asset": archive_component(slug, version),
            "requires": header(source, "Requires at least"),
            "requires_php": header(source, "Requires PHP"),
        }
    (DIST / "ice-field-updates.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")


if __name__ == "__main__":
    main()
