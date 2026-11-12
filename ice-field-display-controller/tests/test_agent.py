import sys
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import agent


class Result:
    def __init__(self, returncode=0, stdout=""):
        self.returncode = returncode
        self.stdout = stdout


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

    @patch("agent.subprocess.run")
    @patch("agent.shutil.which", side_effect=lambda name: "/usr/bin/xdotool" if name == "xdotool" else None)
    def test_x11_cursor_is_parked_off_screen(self, _which, run):
        agent.hide_cursor()
        self.assertEqual(
            ["/usr/bin/xdotool", "mousemove", "--sync", "8191", "8191"],
            run.call_args.args[0],
        )


class X11ConfigurationTests(unittest.TestCase):
    @patch.dict("agent.os.environ", {"IFDC_X11_MODE": "1920x1080", "IFDC_X11_RATE": "30"})
    @patch("agent.subprocess.run")
    def test_unsupported_rate_falls_back_to_supported_mode(self, run):
        run.side_effect = [
            Result(),
            Result(stdout="HDMI-1 connected 3840x2160+0+0\n"),
            Result(returncode=1),
            Result(),
        ]
        self.assertTrue(agent.configure_x11_signage())
        self.assertEqual(
            ["xrandr", "--output", "HDMI-1", "--mode", "1920x1080"],
            run.call_args_list[-1].args[0],
        )

    @patch.dict("agent.os.environ", {"IFDC_X11_MODE": "1920x1080", "IFDC_X11_RATE": "30"})
    @patch("agent.subprocess.run")
    def test_mode_failure_does_not_block_kiosk_startup(self, run):
        run.side_effect = [
            Result(),
            Result(stdout="HDMI-1 connected 3840x2160+0+0\n"),
            Result(returncode=1),
            Result(returncode=1),
        ]
        self.assertTrue(agent.configure_x11_signage())


class WaylandConfigurationTests(unittest.TestCase):
    @patch.dict("agent.os.environ", {"IFDC_WAYLAND_MODE": "1920x1080", "IFDC_WAYLAND_RATE": "30"})
    @patch("agent.subprocess.run")
    def test_supported_1080p30_mode_is_applied(self, run):
        run.side_effect = [
            Result(stdout='''HDMI-A-1 "TV"
  Enabled: yes
  Modes:
    3840x2160 px, 30.000000 Hz (preferred, current)
    1920x1080 px, 60.000000 Hz
    1920x1080 px, 30.000000 Hz
'''),
            Result(),
        ]
        self.assertTrue(agent.configure_wayland_signage())
        self.assertEqual(
            ["wlr-randr", "--output", "HDMI-A-1", "--mode", "1920x1080@30.000000Hz", "--pos", "0,0"],
            run.call_args_list[-1].args[0],
        )

    @patch.dict("agent.os.environ", {"IFDC_WAYLAND_MODE": "1920x1080", "IFDC_WAYLAND_RATE": "30"})
    @patch("agent.subprocess.run")
    def test_dual_outputs_are_arranged_side_by_side(self, run):
        run.side_effect = [
            Result(stdout='''HDMI-A-1 "TV 1"\n  Modes:\n    1920x1080 px, 30.000000 Hz\nHDMI-A-2 "TV 2"\n  Modes:\n    1920x1080 px, 30.000000 Hz\n'''),
            Result(),
        ]
        self.assertTrue(agent.configure_wayland_signage())
        self.assertEqual(
            ["wlr-randr", "--output", "HDMI-A-1", "--mode", "1920x1080@30.000000Hz", "--pos", "0,0", "--output", "HDMI-A-2", "--mode", "1920x1080@30.000000Hz", "--pos", "1920,0"],
            run.call_args_list[-1].args[0],
        )


if __name__ == "__main__":
    unittest.main()
