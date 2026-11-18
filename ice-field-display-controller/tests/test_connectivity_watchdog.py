import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import connectivity_watchdog as watchdog


class ConnectivityWatchdogTests(unittest.TestCase):
    def test_state_round_trip(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "state.json"
            watchdog.save_state({"network_failures": 2, "tailscale_failures": 3}, path)
            self.assertEqual(
                {"network_failures": 2, "tailscale_failures": 3},
                watchdog.load_state(path),
            )

    def test_tailscale_requires_running_and_online(self):
        with patch("connectivity_watchdog.run") as run:
            run.return_value.returncode = 0
            run.return_value.stdout = json.dumps({"BackendState": "Running", "Self": {"Online": True}})
            self.assertTrue(watchdog.tailscale_online())
            run.return_value.stdout = json.dumps({"BackendState": "Running", "Self": {"Online": False}})
            self.assertFalse(watchdog.tailscale_online())

    def test_network_recovery_waits_for_two_failures(self):
        with patch("connectivity_watchdog.run") as run:
            watchdog.recover_network(1)
            run.assert_not_called()
            watchdog.recover_network(2)
            self.assertEqual(2, run.call_count)
            self.assertEqual(
                ["systemctl", "restart", "NetworkManager.service"],
                run.call_args_list[0].args[0],
            )

    def test_tailscale_recovery_restarts_service(self):
        with patch("connectivity_watchdog.run") as run:
            watchdog.recover_tailscale(2)
            run.assert_called_once_with(["systemctl", "restart", "tailscaled.service"])


if __name__ == "__main__":
    unittest.main()
