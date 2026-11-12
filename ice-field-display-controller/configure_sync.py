#!/usr/bin/env python3
"""Interactive fleet/cloud configuration for the master display controller."""

from __future__ import annotations

import getpass
import json
import os
import tempfile
from pathlib import Path

from controller import state_directory


def main() -> None:
    path = state_directory() / "sync.json"
    try:
        config = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        config = {}
    print("Ice & Field display cloud setup")
    print("The application password stays on this controller and is not shown while typing.")
    current_user = str(config.get("cloud_username", ""))
    username = input(f"WordPress username [{current_user}]: ").strip() or current_user
    password = getpass.getpass("WordPress application password (leave blank to keep saved): ").strip()
    if not username:
        raise SystemExit("A WordPress username is required.")
    if not password and not config.get("cloud_application_password"):
        raise SystemExit("An application password is required for the first setup.")
    config.update({
        "mode": "controller",
        "interval_seconds": 300,
        "primary_manifest_url": config.get("primary_manifest_url", "http://100.112.216.40:8788/api/sync/manifest"),
        "cloud_manifest_url": "https://shows.iceandfield.com/wp-json/ice-field-display-cloud/v1/manifest",
        "cloud_api_base": "https://shows.iceandfield.com/wp-json/ice-field-display-cloud/v1",
        "cloud_username": username,
        "cloud_application_password": password or config.get("cloud_application_password", ""),
    })
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix="sync-config-", suffix=".json", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(config, handle, indent=2, sort_keys=True)
            handle.write("\n")
        os.chmod(temporary, 0o600)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)
    (state_directory() / "sync-now").touch()
    print("Saved. The first WordPress cloud backup has been requested.")


if __name__ == "__main__":
    main()
