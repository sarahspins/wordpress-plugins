import json
import io
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from controller import MediaStore, StateStore, detect_outputs, detect_x11_outputs, display_page, valid_media_signature


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

    def test_local_slideshow_is_normalized(self):
        with tempfile.TemporaryDirectory() as directory:
            store = StateStore(Path(directory) / "state.json")
            slides = [f"/media/{'a' * 32}", f"/media/{'b' * 32}"]
            state = store.save_channel("video_for_screens", "slideshow", json.dumps({"slides": slides, "seconds": 9}))
            saved = json.loads(state["channels"]["video_for_screens"]["source"])
            self.assertEqual(slides, [item["source"] for item in saved["slides"]])
            self.assertEqual(9, saved["seconds"])
            self.assertEqual("brand-wipe", saved["transition"])

    def test_slideshow_accepts_optional_local_schedule(self):
        with tempfile.TemporaryDirectory() as directory:
            store = StateStore(Path(directory) / "state.json")
            slides = [
                {"source": f"/media/{'a' * 32}", "start": "2026-11-05T08:00", "end": "2026-11-30T22:00"},
                {"source": f"/media/{'b' * 32}", "start": "", "end": ""},
            ]
            state = store.save_channel("video_for_screens", "slideshow", json.dumps({"slides": slides, "seconds": 8}))
            self.assertEqual(slides, json.loads(state["channels"]["video_for_screens"]["source"])["slides"])


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

    def test_image_schedule_is_stored_with_media(self):
        with tempfile.TemporaryDirectory() as directory:
            store = MediaStore(Path(directory) / "media")
            payload = b"\x89PNG\r\n\x1a\n" + b"test payload"
            item = store.upload(io.BytesIO(payload), "scheduled.png", len(payload))
            updated = store.update_schedule(item["id"], "2026-11-05T09:00", "2026-11-30T21:00")
            self.assertEqual("2026-11-05T09:00", updated["start"])
            self.assertEqual("2026-11-30T21:00", store.list()[0]["end"])


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

    @patch("controller.subprocess.run")
    def test_x11_current_geometry_is_parsed(self, run):
        run.return_value.returncode = 0
        run.return_value.stdout = """Screen 0: minimum 320 x 200, current 2560 x 1440
HDMI-1 connected primary 2560x1440+0+0 (normal left inverted right x axis y axis)
   2560x1440     59.95*+  74.96
"""
        outputs = detect_x11_outputs()
        self.assertEqual(["HDMI-1"], [item["name"] for item in outputs])
        self.assertEqual((2560, 1440, 0, 0), tuple(outputs[0][key] for key in ("width", "height", "x", "y")))


class DisplayPageTests(unittest.TestCase):
    def test_web_display_clips_remote_scrollbar(self):
        page = display_page("HDMI-A-1", {"kind": "web", "source": "https://example.com/schedule", "label": "Lobby"}, 1).decode()
        self.assertIn('class="web-frame"', page)
        self.assertIn("width:calc(100% + 24px)", page)
        self.assertIn("cursor:none!important", page)
        self.assertIn("pointer-events:none", page)

    def test_display_has_quick_channel_menu(self):
        page = display_page("HDMI-A-1", {"kind": "standby", "source": "", "label": "Lobby", "channel": "schedule"}, 2).decode()
        self.assertIn('class="quick-menu"', page)
        self.assertIn('data-channel="schedule" disabled', page)
        self.assertIn('data-channel="pricing"', page)
        self.assertIn("Open full controller", page)
        self.assertIn("event.key==='ContextMenu'", page)
        self.assertIn("event.key==='ArrowDown'", page)

    def test_slideshow_uses_confirmed_brand_wipe(self):
        slides = [f"/media/{'a' * 32}", f"/media/{'b' * 32}"]
        page = display_page("HDMI-A-1", {"kind": "slideshow", "source": json.dumps({"slides": slides, "seconds": 7}), "label": "Lobby"}, 3).decode()
        self.assertIn('class="brand-wipe"', page)
        self.assertIn("#003b5c", page)
        self.assertIn("#33b6ff", page)
        self.assertIn("#ff8033", page)
        self.assertIn("#ffc600", page)
        self.assertIn("setInterval(nextSlide,7000)", page)
        self.assertIn("left:-50vw;right:-50vw", page)
        self.assertIn("translate3d(75%,0,0)", page)
        self.assertIn("translate3d(-75%,0,0)", page)
        self.assertIn("prepareWipe()", page)
        self.assertIn("Math.random()", page)
        self.assertIn("activeSlides()", page)
        self.assertIn("wipe.animate", page)
        self.assertIn("wipeDuration=280", page)
        self.assertIn("duration:wipeDuration", page)
        self.assertIn("motion.currentTime", page)
        self.assertIn("requestAnimationFrame(swapUnderCover)", page)
        self.assertNotIn("setTimeout(()=>{slideImage.src", page)


if __name__ == "__main__":
    unittest.main()
