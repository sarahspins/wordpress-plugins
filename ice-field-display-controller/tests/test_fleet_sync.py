import hashlib
import io
import json
import tempfile
import unittest
from pathlib import Path

import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from controller import MediaStore, StateStore
from fleet_sync import apply_manifest


class FleetSyncTests(unittest.TestCase):
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


if __name__ == "__main__":
    unittest.main()
