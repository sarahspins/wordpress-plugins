import json
import tempfile
import unittest
from pathlib import Path

from personalize_state import personalize_state


class PersonalizeStateTests(unittest.TestCase):
    def test_updates_node_and_single_screen_without_removing_synced_content(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "state.json"
            path.write_text(json.dumps({
                "revision": 40,
                "node": {"id": "ice-and-field-display-03", "name": "Office"},
                "channels": {"video_for_screens": {"kind": "slideshow", "source": "saved"}},
                "assignments": {"HDMI-1": {"label": "Office", "channel": "video_for_screens"}},
            }), encoding="utf-8")
            personalize_state(path, "ice-and-field-display-04", "Warm Room 1")
            state = json.loads(path.read_text(encoding="utf-8"))
            self.assertEqual({"id": "ice-and-field-display-04", "name": "Warm Room 1"}, state["node"])
            self.assertEqual("Warm Room 1", state["assignments"]["HDMI-1"]["label"])
            self.assertEqual("saved", state["channels"]["video_for_screens"]["source"])
            self.assertEqual(41, state["revision"])

    def test_does_not_rename_multiple_screen_assignments(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "state.json"
            path.write_text(json.dumps({
                "revision": 1,
                "assignments": {"HDMI-1": {"label": "One"}, "HDMI-2": {"label": "Two"}},
            }), encoding="utf-8")
            personalize_state(path, "ice-and-field-display-04", "Lobby")
            state = json.loads(path.read_text(encoding="utf-8"))
            self.assertEqual("One", state["assignments"]["HDMI-1"]["label"])
            self.assertEqual("Two", state["assignments"]["HDMI-2"]["label"])
