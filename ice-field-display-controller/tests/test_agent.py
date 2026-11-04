import sys
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import agent


class CursorSupportTests(unittest.TestCase):
    @patch("agent.subprocess.run")
    @patch("agent.shutil.which", return_value="/usr/bin/wtype")
    def test_hide_cursor_invokes_labwc_shortcut(self, _which, run):
        agent.hide_cursor()
        command = run.call_args.args[0]
        self.assertEqual("/usr/bin/wtype", command[0])
        self.assertEqual(["-M", "alt", "-M", "logo", "h", "-m", "logo", "-m", "alt"], command[1:])

    @patch("agent.subprocess.run")
    @patch("agent.shutil.which", return_value=None)
    def test_missing_helper_is_safe(self, _which, run):
        agent.hide_cursor()
        run.assert_not_called()


if __name__ == "__main__":
    unittest.main()
