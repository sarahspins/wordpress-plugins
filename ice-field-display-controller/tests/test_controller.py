import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from controller import StateStore, detect_outputs


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


if __name__ == "__main__":
    unittest.main()
