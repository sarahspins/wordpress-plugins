#!/usr/bin/env python3
"""Controller-to-node synchronization with a WordPress recovery source."""

from __future__ import annotations

import base64
import hashlib
import http.client
import json
import os
import socket
import tempfile
import time
import uuid
from pathlib import Path
from urllib.parse import urljoin, urlparse
from urllib.request import Request, urlopen

from controller import CHANNELS, MediaStore, StateStore, state_directory

DEFAULTS = {
    "mode": "node",
    "primary_manifest_url": "http://100.112.216.40:8788/api/sync/manifest",
    "cloud_manifest_url": "https://shows.iceandfield.com/wp-json/ice-field-display-cloud/v1/manifest",
    "cloud_api_base": "https://shows.iceandfield.com/wp-json/ice-field-display-cloud/v1",
    "cloud_username": "",
    "cloud_application_password": "",
    "interval_seconds": 300,
}


def load_config() -> dict:
    config = dict(DEFAULTS)
    try:
        saved = json.loads((state_directory() / "sync.json").read_text(encoding="utf-8"))
        if isinstance(saved, dict):
            config.update(saved)
    except (OSError, json.JSONDecodeError):
        pass
    return config


def fetch_json(url: str, timeout: int = 15) -> dict:
    request = Request(url, headers={"Accept": "application/json", "User-Agent": "IceFieldDisplay/0.1"})
    with urlopen(request, timeout=timeout) as response:
        return json.loads(response.read().decode("utf-8"))


def fetch_authenticated_json(url: str, config: dict, timeout: int = 30) -> dict:
    request = Request(url, headers={
        "Accept": "application/json",
        "Authorization": authorization(config),
        "User-Agent": "IceFieldDisplay/0.1",
    })
    with urlopen(request, timeout=timeout) as response:
        return json.loads(response.read().decode("utf-8"))


def heartbeat_url(manifest_url: str) -> str:
    parsed = urlparse(manifest_url)
    return f"{parsed.scheme}://{parsed.netloc}/api/sync/heartbeat"


def report_heartbeat(manifest_url: str, revision: int, screens: list[dict] | None = None) -> None:
    payload = json.dumps({
        "hostname": socket.gethostname(),
        "revision": int(revision),
        "screens": screens or [],
    }, separators=(",", ":")).encode()
    request = Request(heartbeat_url(manifest_url), data=payload, method="POST", headers={
        "Content-Type": "application/json", "User-Agent": "IceFieldDisplay/0.1",
    })
    with urlopen(request, timeout=15) as response:
        if response.status not in (200, 201):
            raise RuntimeError(f"Master check-in failed ({response.status}).")


def file_hash(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        while chunk := handle.read(1024 * 1024):
            digest.update(chunk)
    return digest.hexdigest()


def validate_manifest(manifest: dict) -> None:
    if not isinstance(manifest, dict) or manifest.get("schema") != 1:
        raise ValueError("Unsupported display manifest.")
    if not isinstance(manifest.get("channels"), dict) or not isinstance(manifest.get("media"), list):
        raise ValueError("Incomplete display manifest.")
    for item in manifest["media"]:
        digest = str(item.get("sha256", "")) if isinstance(item, dict) else ""
        if len(str(item.get("id", ""))) != 32 or len(digest) != 64 or any(ch not in "0123456789abcdef" for ch in digest):
            raise ValueError("Invalid media entry in display manifest.")


def object_url(manifest_url: str, manifest: dict, item: dict) -> str:
    if manifest.get("object_base_url"):
        return urljoin(str(manifest["object_base_url"]), item["file"])
    parsed = urlparse(manifest_url)
    return f"{parsed.scheme}://{parsed.netloc}/api/sync/objects/{item['sha256']}"


def download_verified(url: str, destination: Path, digest: str, expected_size: int) -> None:
    fd, temporary = tempfile.mkstemp(prefix="sync-", suffix=".partial", dir=destination.parent)
    actual = hashlib.sha256()
    size = 0
    try:
        request = Request(url, headers={"User-Agent": "IceFieldDisplay/0.1"})
        with os.fdopen(fd, "wb") as handle, urlopen(request, timeout=120) as response:
            while chunk := response.read(1024 * 1024):
                handle.write(chunk)
                actual.update(chunk)
                size += len(chunk)
            handle.flush()
            os.fsync(handle.fileno())
        if actual.hexdigest() != digest or (expected_size and size != expected_size):
            raise ValueError("Downloaded media failed checksum or size verification.")
        os.replace(temporary, destination)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def apply_manifest(manifest_url: str, manifest: dict, store: StateStore, media: MediaStore) -> bool:
    validate_manifest(manifest)
    state = store.load()
    index = media._load()
    items = index.setdefault("items", {})
    remote_ids = {str(item["id"]) for item in manifest["media"]}
    for remote in manifest["media"]:
        media_id = str(remote["id"])
        suffix = Path(str(remote["file"])).suffix.lower()
        stored_name = f"{media_id}{suffix}"
        destination = media.directory / stored_name
        local = items.get(media_id, {})
        verified_local = (
            destination.is_file()
            and destination.stat().st_size == int(remote.get("size", 0))
            and local.get("sha256") == remote["sha256"]
            and local.get("stored_name") == stored_name
        )
        if not verified_local:
            download_verified(object_url(manifest_url, manifest, remote), destination, remote["sha256"], int(remote.get("size", 0)))
        items[media_id] = {key: remote.get(key, "") for key in (
            "id", "name", "kind", "content_type", "size", "sha256", "uploaded_at", "start", "end",
        )} | {"stored_name": stored_name}
    protected_ids = set()
    for assignment in state.get("assignments", {}).values():
        if assignment.get("channel"):
            continue
        source = str(assignment.get("source", ""))
        if source.startswith("/media/"):
            protected_ids.add(source.removeprefix("/media/"))
        if assignment.get("kind") == "slideshow":
            try:
                slides = json.loads(source).get("slides", [])
                protected_ids.update(str(item if isinstance(item, str) else item.get("source", "")).removeprefix("/media/") for item in slides)
            except (json.JSONDecodeError, AttributeError):
                pass
    for media_id in set(items) - remote_ids - protected_ids:
        stale = items.pop(media_id)
        stale_path = media.directory / stale.get("stored_name", "missing")
        if stale_path.is_file():
            retired = media.directory.parent / "sync-retired"
            retired.mkdir(parents=True, exist_ok=True)
            destination = retired / stale_path.name
            if not destination.exists():
                os.replace(stale_path, destination)
    media._save(index)
    channels = {key: manifest["channels"].get(key, state.get("channels", {}).get(key, {})) for key in CHANNELS}
    changed = channels != state.get("channels", {})
    state["last_sync"] = {"source": manifest_url, "at": int(time.time()), "revision": int(manifest.get("revision", 0))}
    if changed:
        state["channels"] = channels
        state["revision"] = int(state.get("revision", 0)) + 1
    store.save(state)
    return changed


def sync_node(config: dict, store: StateStore, media: MediaStore) -> str:
    errors = []
    for url in (config.get("primary_manifest_url", ""), config.get("cloud_manifest_url", "")):
        if not url:
            continue
        try:
            manifest = fetch_json(url)
            apply_manifest(url, manifest, store, media)
            if url == config.get("primary_manifest_url"):
                try:
                    state = store.load()
                    screens = [
                        {"output": str(output), "name": str(assignment.get("label", output)),
                         "channel": str(assignment.get("channel", "")),
                         "kind": str(assignment.get("kind", "standby"))}
                        for output, assignment in state.get("assignments", {}).items()
                        if isinstance(assignment, dict)
                    ]
                    report_heartbeat(url, int(manifest.get("revision", 0)), screens)
                except Exception as error:
                    print(f"Master node check-in warning: {error}", flush=True)
            return url
        except Exception as error:
            errors.append(f"{url}: {error}")
    raise RuntimeError("; ".join(errors) or "No synchronization source is configured.")


def authorization(config: dict) -> str:
    credentials = f"{config.get('cloud_username', '')}:{config.get('cloud_application_password', '')}".encode()
    return "Basic " + base64.b64encode(credentials).decode()


def slideshow_feed_url(config: dict) -> str:
    return f"{str(config.get('cloud_api_base', '')).rstrip('/')}/slideshow"


def import_slideshow_image(url: str, media: MediaStore) -> dict:
    parsed = urlparse(url)
    original_name = Path(parsed.path).name or "wordpress-slide.jpg"
    suffix = Path(original_name).suffix.lower()
    if suffix not in {".png", ".jpg", ".jpeg", ".webp"}:
        raise ValueError(f"Unsupported slideshow image: {original_name}")

    fd, temporary = tempfile.mkstemp(prefix="wordpress-slide-", suffix=suffix)
    digest = hashlib.sha256()
    size = 0
    try:
        request = Request(url, headers={"User-Agent": "IceFieldDisplay/0.1"})
        with os.fdopen(fd, "wb") as handle, urlopen(request, timeout=120) as response:
            while chunk := response.read(1024 * 1024):
                size += len(chunk)
                if size > 100 * 1024 * 1024:
                    raise ValueError("A WordPress slideshow image exceeds the 100 MB safety limit.")
                handle.write(chunk)
                digest.update(chunk)
        existing = media.get_by_hash(digest.hexdigest())
        if existing:
            item = dict(existing[0])
            item.setdefault("id", existing[0].get("id", ""))
            return item
        with open(temporary, "rb") as source:
            return media.upload(source, original_name, size)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def sync_wordpress_slideshow(config: dict, store: StateStore, media: MediaStore) -> bool:
    if not config.get("cloud_username") or not config.get("cloud_application_password"):
        raise RuntimeError("WordPress slideshow credentials are incomplete.")
    feed = fetch_authenticated_json(slideshow_feed_url(config), config)
    if not isinstance(feed, dict) or int(feed.get("schema", 0)) != 1 or not isinstance(feed.get("slides"), list):
        raise ValueError("The WordPress slideshow feed is invalid.")
    revision = str(feed.get("revision", "")).strip()
    if not revision:
        raise ValueError("The WordPress slideshow revision is missing.")
    state = store.load()
    previous = state.get("wordpress_slideshow", {}) if isinstance(state.get("wordpress_slideshow"), dict) else {}
    if str(previous.get("revision", "")) == revision:
        return False

    slides = []
    for row in feed["slides"][:100]:
        if not isinstance(row, dict) or not str(row.get("url", "")).startswith(("http://", "https://")):
            continue
        item = import_slideshow_image(str(row["url"]), media)
        media_id = str(item.get("id", ""))
        if not media_id:
            raise ValueError("An imported slideshow image has no local media ID.")
        slides.append({
            "source": f"/media/{media_id}",
            "start": str(row.get("starts_at", "")),
            "end": str(row.get("expires_at", "")),
        })

    if slides:
        source = json.dumps({
            "slides": slides,
            "seconds": max(2, min(120, int(feed.get("seconds", 10)))),
            "transition": "brand-wipe",
        }, separators=(",", ":"))
        store.save_channel("video_for_screens", "slideshow", source)
    else:
        store.save_channel("video_for_screens", "standby", "")
    state = store.load()
    state["wordpress_slideshow"] = {
        "revision": revision,
        "at": int(time.time()),
        "source": slideshow_feed_url(config),
    }
    store.save(state)
    return True


def upload_object(api_base: str, config: dict, item: dict, path: Path) -> None:
    boundary = "----IceField" + uuid.uuid4().hex
    prefix = (f"--{boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{item['file']}\"\r\n"
              f"Content-Type: {item['content_type']}\r\n\r\n").encode()
    suffix = f"\r\n--{boundary}--\r\n".encode()
    parsed = urlparse(f"{api_base.rstrip('/')}/objects/{item['sha256']}")
    connection = http.client.HTTPSConnection(parsed.hostname, parsed.port or 443, timeout=180)
    connection.putrequest("POST", parsed.path)
    connection.putheader("Authorization", authorization(config))
    connection.putheader("Content-Type", f"multipart/form-data; boundary={boundary}")
    connection.putheader("Content-Length", str(len(prefix) + path.stat().st_size + len(suffix)))
    connection.endheaders()
    connection.send(prefix)
    with path.open("rb") as handle:
        while chunk := handle.read(1024 * 1024):
            connection.send(chunk)
    connection.send(suffix)
    response = connection.getresponse()
    body = response.read()
    if response.status not in (200, 201):
        raise RuntimeError(f"Cloud object upload failed ({response.status}): {body[:300].decode(errors='replace')}")


def publish_cloud(config: dict, store: StateStore, media: MediaStore) -> int:
    api_base = str(config.get("cloud_api_base", "")).rstrip("/")
    if not api_base or not config.get("cloud_username") or not config.get("cloud_application_password"):
        raise RuntimeError("Cloud publishing credentials are incomplete.")
    manifest = media.sync_manifest(store.load())
    existing_hashes = set()
    try:
        existing = fetch_json(f"{api_base}/manifest")
        existing_hashes = {item.get("sha256") for item in existing.get("media", [])}
        if int(existing.get("revision", 0)) == manifest["revision"]:
            return manifest["revision"]
    except Exception:
        pass
    for item in manifest["media"]:
        if item["sha256"] in existing_hashes:
            continue
        found = media.get(str(item["id"]))
        if not found:
            raise RuntimeError(f"Local media {item['id']} is missing.")
        upload_object(api_base, config, item, found[1])
    payload = json.dumps(manifest, separators=(",", ":")).encode()
    request = Request(f"{api_base}/manifest", data=payload, method="POST", headers={
        "Authorization": authorization(config), "Content-Type": "application/json", "User-Agent": "IceFieldDisplay/0.1",
    })
    with urlopen(request, timeout=90) as response:
        if response.status not in (200, 201):
            raise RuntimeError(f"Cloud manifest publish failed ({response.status}).")
    return manifest["revision"]


def main() -> None:
    directory = state_directory()
    store = StateStore(directory / "state.json")
    media = MediaStore(directory / "media")
    next_run = 0.0
    marker = directory / "sync-now"
    while True:
        config = load_config()
        if time.monotonic() >= next_run or marker.exists():
            marker.unlink(missing_ok=True)
            try:
                if config.get("mode") == "node":
                    print(f"Display content checked from {sync_node(config, store, media)}", flush=True)
                elif config.get("mode") == "controller" and config.get("cloud_username"):
                    try:
                        changed = sync_wordpress_slideshow(config, store, media)
                        if changed:
                            print("Video for Screens updated from WordPress", flush=True)
                    except Exception as error:
                        print(f"WordPress slideshow warning: {error}", flush=True)
                    print(f"Cloud backup is current at revision {publish_cloud(config, store, media)}", flush=True)
            except Exception as error:
                print(f"Fleet synchronization warning: {error}", flush=True)
            next_run = time.monotonic() + max(60, int(config.get("interval_seconds", 300)))
        time.sleep(5)


if __name__ == "__main__":
    main()
