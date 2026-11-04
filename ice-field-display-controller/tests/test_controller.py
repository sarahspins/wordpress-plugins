import json
import io
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from controller import MediaStore, StateStore, detect_outputs, display_page, valid_media_signature


class StateStoreTests(unittest.TestCase):
    def test_assignment_is_persisted_and_revision_increments(self):
        with tempfile.TemporaryDirectory() as directory:
            store = StateStore(Path(directory) / "state.json")
            before = store.load()["revision"]
            after = store.assign("HDMI-A-1", "web", "https://example.com/schedule", "Lobby Schedule")
            self.assertEqual(before + 1, after["revision"])
            self.assertEqual("Lobby Schedule", store.load()["assignments"]["HDMI-A-1"]["label"])

    def test_non_http_source_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            store = StateStore(Path(directory) / "state.json")
            with self.assertRaises(ValueError):
                store.assign("HDMI-A-1", "video", "file:///etc/passwd", "Bad")

    def test_local_media_source_is_allowed(self):
        with tempfile.TemporaryDirectory() as directory:
            store = StateStore(Path(directory) / "state.json")
            media_id = "a" * 32
            state = store.assign("HDMI-A-1", "image", f"/media/{media_id}", "Lobby 1")
            self.assertEqual("Lobby 1", state["assignments"]["HDMI-A-1"]["label"])

    def test_named_channel_is_resolved_for_assigned_screen(self):
        with tempfile.TemporaryDirectory() as directory:
            store = StateStore(Path(directory) / "state.json")
            store.save_channel("schedule", "web", "https://example.com/schedule")
            state = store.assign("HDMI-A-1", "standby", "", "Lobby 1", "schedule")
            resolved = store.resolve_assignment(state, state["assignments"]["HDMI-A-1"])
            self.assertEqual("web", resolved["kind"])
            self.assertEqual("https://example.com/schedule", resolved["source"])

    def test_updating_channel_increments_revision(self):
        with tempfile.TemporaryDirectory() as directory:
            store = StateStore(Path(directory) / "state.json")
            before = store.load()["revision"]
            after = store.save_channel("pricing", "video", "https://example.com/pricing.mp4")
            self.assertEqual(before + 1, after["revision"])


class MediaStoreTests(unittest.TestCase):
    def test_valid_png_is_streamed_and_indexed(self):
        with tempfile.TemporaryDirectory() as directory:
            store = MediaStore(Path(directory) / "media")
            payload = b"\x89PNG\r\n\x1a\n" + b"test payload"
            item = store.upload(io.BytesIO(payload), "lobby.png", len(payload))
            self.assertEqual("image", item["kind"])
            self.assertEqual("lobby.png", store.list()[0]["name"])
            self.assertTrue(store.get(item["id"])[1].is_file())

    def test_mismatched_file_contents_are_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            store = MediaStore(Path(directory) / "media")
            with self.assertRaisesRegex(ValueError, "do not match"):
                store.upload(io.BytesIO(b"not a png"), "fake.png", 9)
            self.assertEqual([], store.list())

    def test_signature_checks_cover_supported_types(self):
        self.assertTrue(valid_media_signature(".mp4", b"\x00\x00\x00\x18ftypisom"))
        self.assertTrue(valid_media_signature(".webm", b"\x1a\x45\xdf\xa3"))
        self.assertTrue(valid_media_signature(".svg", b"<?xml version='1.0'?><svg></svg>"))


class OutputDetectionTests(unittest.TestCase):
    @patch("controller.subprocess.run")
    def test_current_mode_and_position_are_parsed(self, run):
        run.return_value.returncode = 0
        run.return_value.stdout = '''HDMI-A-1 "LG"
  Enabled: yes
  Modes:
    2560x1440 px, 59.951000 Hz (preferred, current)
  Position: 0,0
HDMI-A-2 "TV"
  Enabled: no
'''
        outputs = detect_outputs()
        self.assertEqual(1, len(outputs))
        self.assertEqual((2560, 1440), (outputs[0]["width"], outputs[0]["height"]))


class DisplayPageTests(unittest.TestCase):
    def test_web_display_clips_remote_scrollbar(self):
        page = display_page("HDMI-A-1", {"kind": "web", "source": "https://example.com/schedule", "label": "Lobby"}, 1).decode()
        self.assertIn('class="web-frame"', page)
        self.assertIn("width:calc(100% + 24px)", page)
        self.assertIn("cursor:none", page)
        self.assertIn("pointer-events:none", page)

    def test_display_has_quick_channel_menu(self):
        page = display_page("HDMI-A-1", {"kind": "standby", "source": "", "label": "Lobby", "channel": "schedule"}, 2).decode()
        self.assertIn('class="quick-menu"', page)
        self.assertIn('data-channel="schedule" disabled', page)
        self.assertIn('data-channel="pricing"', page)
        self.assertIn("Open full controller", page)


if __name__ == "__main__":
    unittest.main()
