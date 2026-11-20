import json
import io
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from controller import MediaStore, StateStore, admin_page, detect_outputs, detect_x11_outputs, display_page, load_fleet_nodes, local_fleet_node, master_fleet_node, master_fleet_nodes, page_shell, record_fleet_node, request_node_assignment, request_node_sync, update_fleet_screen_assignment, valid_media_signature


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

    def test_playback_node_default_can_bind_video_channel(self):
        with tempfile.TemporaryDirectory() as directory:
            store = StateStore(Path(directory) / "state.json")
            state = store.assign("HDMI-1", "standby", "", "HDMI-1", "video_for_screens")
            self.assertEqual("video_for_screens", state["assignments"]["HDMI-1"]["channel"])

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


class FleetNodeTests(unittest.TestCase):
    @patch("controller.socket.gethostname", return_value="ice-and-field-display-01")
    def test_master_is_listed_with_current_revision(self, _hostname):
        with tempfile.TemporaryDirectory() as directory:
            store = StateStore(Path(directory) / "state.json")
            state = store.load()
            state["revision"] = 44
            store.save(state)
            node = master_fleet_node(store, "http://100.112.216.40:8788/api/sync/manifest")
            self.assertEqual("ice-and-field-display-01", node["hostname"])
            self.assertEqual("master", node["role"])
            self.assertEqual("100.112.216.40", node["address"])
            self.assertEqual(44, node["revision"])
            self.assertEqual([], node["screens"])

    @patch("controller.socket.gethostname", return_value="ice-and-field-display-02")
    def test_local_playback_node_is_not_presented_as_master(self, _hostname):
        with tempfile.TemporaryDirectory() as directory:
            store = StateStore(Path(directory) / "state.json")
            state = store.load()
            state["assignments"] = {"HDMI-1": {"label": "Lobby - Office Wall"}}
            state["last_sync"] = {"source": "master", "at": 1_795_000_000, "revision": 40}
            store.save(state)
            node = local_fleet_node(store, {
                "mode": "node",
                "primary_manifest_url": "http://100.112.216.40:8788/api/sync/manifest",
            })
            self.assertEqual("ice-and-field-display-02", node["hostname"])
            self.assertEqual("node", node["role"])
            self.assertEqual("This device", node["address"])
            self.assertEqual("100.112.216.40", node["master_address"])
            self.assertEqual(40, node["revision"])
            self.assertEqual(1_795_000_000, node["last_updated"])
            self.assertEqual("Lobby - Office Wall", node["screens"][0]["name"])
            self.assertEqual("standby", node["screens"][0]["kind"])

    @patch("controller.urlopen")
    def test_playback_node_reads_the_masters_full_fleet_list(self, mocked_urlopen):
        response = mocked_urlopen.return_value.__enter__.return_value
        response.read.return_value = json.dumps({"nodes": [
            {"hostname": "ice-and-field-display-01", "role": "master"},
            {"hostname": "ice-and-field-display-02", "role": "node"},
            {"hostname": "ice-and-field-display-03", "role": "node"},
        ]}).encode()
        nodes = master_fleet_nodes({
            "primary_manifest_url": "http://100.112.216.40:8788/api/sync/manifest",
        })
        self.assertEqual(3, len(nodes))
        self.assertEqual("ice-and-field-display-02", nodes[1]["hostname"])
        self.assertEqual("http://100.112.216.40:8788/api/sync/nodes", mocked_urlopen.call_args.args[0].full_url)

    @patch("controller.urlopen")
    def test_master_can_request_immediate_verified_node_sync(self, mocked_urlopen):
        response = mocked_urlopen.return_value.__enter__.return_value
        response.status = 202
        results = request_node_sync([
            {"hostname": "ice-and-field-display-01", "role": "master", "address": "100.112.216.40"},
            {"hostname": "ice-and-field-display-02", "role": "node", "address": "100.73.25.2"},
        ])
        self.assertEqual([{"hostname": "ice-and-field-display-02", "ok": True}], results)
        request = mocked_urlopen.call_args.args[0]
        self.assertEqual("http://100.73.25.2:8788/api/sync/now", request.full_url)
        self.assertEqual("POST", request.method)

    @patch("controller.urlopen")
    def test_master_can_assign_a_channel_to_a_private_node(self, mocked_urlopen):
        mocked_urlopen.return_value.__enter__.return_value.status = 202
        request_node_assignment({"address": "100.73.25.2"}, "HDMI-1", "schedule")
        request = mocked_urlopen.call_args.args[0]
        self.assertEqual("http://100.73.25.2:8788/api/sync/assignment", request.full_url)
        self.assertEqual({"output": "HDMI-1", "channel": "schedule"}, json.loads(request.data))

    def test_master_records_the_new_screen_assignment_without_changing_check_in(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "fleet-nodes.json"
            record_fleet_node({"hostname": "ice-and-field-display-03", "revision": 42, "screens": [{"output": "HDMI-1", "name": "Lobby 3", "channel": "pricing"}]}, "100.65.185.108", path)
            before = load_fleet_nodes(path)[0]["last_updated"]
            update_fleet_screen_assignment("ice-and-field-display-03", "HDMI-1", "schedule", path)
            node = load_fleet_nodes(path)[0]
            self.assertEqual("schedule", node["screens"][0]["channel"])
            self.assertEqual(before, node["last_updated"])

    @patch("controller.time.time", return_value=1_794_995_200)
    def test_node_check_in_is_persisted_and_updated(self, _time):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "fleet-nodes.json"
            record_fleet_node({"hostname": "ice-and-field-display-03", "revision": 41}, "100.65.185.108", path)
            record_fleet_node({"hostname": "ice-and-field-display-03", "revision": 42, "screens": [{"output": "HDMI-1", "name": "Lobby 3"}]}, "100.65.185.108", path)
            nodes = load_fleet_nodes(path)
            self.assertEqual(1, len(nodes))
            self.assertEqual(42, nodes[0]["revision"])
            self.assertEqual(1_794_995_200, nodes[0]["last_updated"])
            self.assertEqual("Lobby 3", nodes[0]["screens"][0]["name"])
            self.assertEqual("standby", nodes[0]["screens"][0]["kind"])

    def test_invalid_node_name_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaisesRegex(ValueError, "hostname"):
                record_fleet_node({"hostname": "bad node!", "revision": 1}, "100.64.0.1", Path(directory) / "nodes.json")


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
    def test_management_page_has_ice_field_favicon(self):
        page = page_shell("Test", "").decode("utf-8")
        self.assertIn('rel="icon"', page)
        self.assertIn('data:image/png;base64,', page)

    def test_master_content_settings_are_expanded(self):
        page = admin_page("controller").decode()
        self.assertIn('id="local-media-settings" class="card wide settings-group" open', page)
        self.assertIn('id="display-content-settings" class="card wide settings-group" open', page)
        self.assertNotIn("Controlled by Display 01", page)

    def test_playback_content_settings_are_collapsed_and_explain_master_control(self):
        page = admin_page("node").decode()
        self.assertIn('id="local-media-settings" class="card wide settings-group"><summary>Local Media Library — Controlled by Display 01', page)
        self.assertIn('id="display-content-settings" class="card wide settings-group"><summary>Display Content — Controlled by Display 01', page)
        self.assertIn("Expand this section only when a local change", page)

    def test_web_display_clips_remote_scrollbar(self):
        page = display_page("HDMI-A-1", {"kind": "web", "source": "https://example.com/schedule", "label": "Lobby"}, 1).decode()
        self.assertIn('class="web-frame"', page)
        self.assertIn("width:calc(100% + 24px)", page)
        self.assertIn("cursor:none!important", page)
        self.assertIn("pointer-events:none", page)

    def test_management_page_has_manual_tv_power_control(self):
        page = admin_page().decode("utf-8")
        self.assertIn('id="tv-power-toggle"', page)
        self.assertIn("/api/tv-power", page)

    def test_management_page_lists_attached_nodes(self):
        page = admin_page().decode("utf-8")
        self.assertIn('id="fleet-nodes"', page)
        self.assertIn("/api/sync/nodes", page)
        self.assertIn("Master controller", page)
        self.assertIn("Last synced", page)
        self.assertIn("Screens:", page)
        self.assertIn("Current node", page)
        self.assertIn("current_hostname", page)
        self.assertIn("Push updates to playback nodes", page)
        self.assertIn("/api/sync/push", page)
        self.assertIn("/api/sync/assignment", page)
        self.assertIn("Showing:", page)

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
        self.assertIn("inset:0", page)
        self.assertIn("translate3d(100%,0,0)", page)
        self.assertIn("translate3d(-100%,0,0)", page)
        self.assertIn("prepareWipe()", page)
        self.assertIn("Math.random()", page)
        self.assertIn("activeSlides()", page)
        self.assertIn("wipe.animate", page)
        self.assertIn("wipeHalf=140", page)
        self.assertEqual(2, page.count("duration:wipeHalf"))
        self.assertIn("await cover.finished;slideImage.src", page)
        self.assertIn("await uncover.finished", page)
        self.assertIn("cubic-bezier(.2,.4,.6,.8)", page)
        self.assertIn("cubic-bezier(.4,.2,.8,.6)", page)
        self.assertNotIn("setTimeout(()=>{slideImage.src", page)
        self.assertNotIn("left:-50vw", page)


if __name__ == "__main__":
    unittest.main()
