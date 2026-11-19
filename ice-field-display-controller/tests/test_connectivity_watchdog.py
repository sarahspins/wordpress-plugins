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
            status = unittest.mock.Mock(
                returncode=0,
                stdout=json.dumps({"BackendState": "Running", "Self": {"Online": True}}),
            )
            failed_ping = unittest.mock.Mock(returncode=1, stdout="")
            successful_ping = unittest.mock.Mock(returncode=0, stdout="")
            run.side_effect = [status, failed_ping, successful_ping]
            self.assertTrue(watchdog.tailscale_online("ice-and-field-display-03"))

    def test_tailscale_rejects_stale_online_self_status(self):
        with patch("connectivity_watchdog.run") as run:
            status = unittest.mock.Mock(
                returncode=0,
                stdout=json.dumps({"BackendState": "Running", "Self": {"Online": True}}),
            )
            failed_ping = unittest.mock.Mock(returncode=1, stdout="")
            run.side_effect = [status, failed_ping, failed_ping]
            self.assertFalse(watchdog.tailscale_online("ice-and-field-display-03"))

    def test_tailscale_rejects_offline_self_status_without_peer_probe(self):
        with patch("connectivity_watchdog.run") as run:
            run.return_value = unittest.mock.Mock(
                returncode=0,
                stdout=json.dumps({"BackendState": "Running", "Self": {"Online": False}}),
            )
            self.assertFalse(watchdog.tailscale_online("ice-and-field-display-03"))
            self.assertEqual(1, run.call_count)

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
