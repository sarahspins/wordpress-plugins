import hashlib
import io
import json
import tempfile
import unittest
from unittest.mock import patch
from pathlib import Path

import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from controller import MediaStore, StateStore
from fleet_sync import apply_manifest, heartbeat_url, publish_fleet_status, slideshow_feed_url, sync_wordpress_slideshow


class FleetSyncTests(unittest.TestCase):
    def test_heartbeat_uses_master_private_feed(self):
        self.assertEqual(
            "http://100.112.216.40:8788/api/sync/heartbeat",
            heartbeat_url("http://100.112.216.40:8788/api/sync/manifest"),
        )
    def test_manifest_updates_channels_but_preserves_assignments(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            store = StateStore(root / "state.json")
            media = MediaStore(root / "media")
            store.assign("HDMI-1", "standby", "", "Lobby", "schedule")
            manifest = {
                "schema": 1,
                "revision": 22,
                "channels": store.load()["channels"] | {
                    "schedule": {"label": "Schedule", "kind": "web", "source": "https://example.com/tv/"}
                },
                "media": [],
            }
            changed = apply_manifest("https://example.com/manifest", manifest, store, media)
            state = store.load()
            self.assertTrue(changed)
            self.assertEqual("Lobby", state["assignments"]["HDMI-1"]["label"])
            self.assertEqual("https://example.com/tv/", state["channels"]["schedule"]["source"])
            self.assertEqual(22, state["last_sync"]["revision"])

    def test_controller_manifest_uses_checksum_object_names(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            store = StateStore(root / "state.json")
            media = MediaStore(root / "media")
            payload = b"\x89PNG\r\n\x1a\ncontent"
            item = media.upload(io.BytesIO(payload), "slide.png", len(payload))
            manifest = media.sync_manifest(store.load())
            self.assertEqual(hashlib.sha256(payload).hexdigest() + ".png", manifest["media"][0]["file"])
            self.assertEqual(item["id"], manifest["media"][0]["id"])

    def test_wordpress_slideshow_becomes_local_channel_and_skips_same_revision(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            store = StateStore(root / "state.json")
            media = MediaStore(root / "media")
            payload = b"\x89PNG\r\n\x1a\ncontent"
            item = media.upload(io.BytesIO(payload), "slide.png", len(payload))
            config = {
                "cloud_api_base": "https://shows.example/wp-json/ice-field-display-cloud/v1",
                "cloud_username": "display",
                "cloud_application_password": "secret",
            }
            feed = {
                "schema": 1,
                "revision": "12345",
                "seconds": 7,
                "slides": [{"url": "https://shows.example/slide.png", "starts_at": "2026-11-25T06:00", "expires_at": "2026-12-01T09:15"}],
            }
            with patch("fleet_sync.fetch_authenticated_json", return_value=feed), patch(
                "fleet_sync.import_slideshow_image", return_value=item
            ) as importer:
                self.assertTrue(sync_wordpress_slideshow(config, store, media))
                self.assertFalse(sync_wordpress_slideshow(config, store, media))
            channel = store.load()["channels"]["video_for_screens"]
            settings = json.loads(channel["source"])
            self.assertEqual("slideshow", channel["kind"])
            self.assertEqual(7, settings["seconds"])
            self.assertEqual("2026-11-25T06:00", settings["slides"][0]["start"])
            self.assertEqual("2026-12-01T09:15", settings["slides"][0]["end"])
            self.assertEqual(f"/media/{item['id']}", settings["slides"][0]["source"])
            importer.assert_called_once()

    def test_slideshow_feed_uses_cloud_api_base(self):
        self.assertEqual(
            "https://shows.example/api/slideshow",
            slideshow_feed_url({"cloud_api_base": "https://shows.example/api/"}),
        )

    def test_controller_publishes_compact_fleet_status(self):
        with tempfile.TemporaryDirectory() as directory:
            store = StateStore(Path(directory) / "state.json")
            config = {
                "cloud_api_base": "https://shows.example/api",
                "cloud_username": "display",
                "cloud_application_password": "secret",
                "primary_manifest_url": "http://100.1.2.3:8788/api/sync/manifest",
            }
            master = {"hostname": "ice-and-field-display-01", "role": "master", "last_updated": 1, "screens": []}
            node = {"hostname": "ice-and-field-display-02", "role": "node", "last_updated": 2, "screens": [{"name": "Lobby", "channel": "schedule"}]}
            with patch("fleet_sync.master_fleet_node", return_value=master), patch(
                "fleet_sync.load_fleet_nodes", return_value=[node]
            ), patch("fleet_sync.urlopen") as opener:
                opener.return_value.__enter__.return_value.status = 201
                publish_fleet_status(config, store)
            request = opener.call_args.args[0]
            payload = json.loads(request.data.decode())
            self.assertEqual("https://shows.example/api/fleet", request.full_url)
            self.assertEqual(2, len(payload["nodes"]))
            self.assertEqual("Lobby", payload["nodes"][1]["screens"][0]["name"])


if __name__ == "__main__":
    unittest.main()
