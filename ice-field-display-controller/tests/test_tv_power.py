import datetime as dt
import unittest
from unittest.mock import patch
from zoneinfo import ZoneInfo

from tv_power import desired_state, event_window, fallback_window, next_transition, schedule_url, send_cec, week_start


ZONE = ZoneInfo("America/Chicago")
CONFIG = {
    "schedule_base_url": "https://example.test/cache",
    "timezone": "America/Chicago",
    "wake_minutes_before": 15,
    "standby_minutes_after": 15,
    "fallback_on": "05:30",
    "fallback_off": "23:00",
}


class TvPowerTests(unittest.TestCase):
    @patch("tv_power.time.sleep")
    @patch("tv_power.subprocess.run")
    def test_wake_announces_detected_hdmi_as_active_source(self, run, sleep):
        run.return_value.stdout = "Physical Address: 1.0.0.0\n1.0.0.0\n"
        send_cec("on", {"cec_device": "/dev/cec0"})
        self.assertEqual(4, run.call_count)
        self.assertEqual(
            ["cec-ctl", "-d", "/dev/cec0", "--active-source", "phys-addr=1.0.0.0"],
            run.call_args_list[-1].args[0],
        )
        sleep.assert_called_once_with(2)

    @patch("tv_power.subprocess.run")
    def test_standby_does_not_change_input(self, run):
        send_cec("standby", {"cec_device": "/dev/cec0"})
        self.assertEqual(2, run.call_count)

    def test_week_uses_monday(self):
        now = dt.datetime(2026, 11, 12, 12, tzinfo=ZONE)
        self.assertEqual(week_start(now), "2026-11-09")
        self.assertEqual(schedule_url(CONFIG, now), "https://example.test/cache/week-2026-11-09.json")

    def test_combines_both_rinks_and_applies_margins(self):
        now = dt.datetime(2026, 11, 12, 12, tzinfo=ZONE)
        payload = {"days": [{
            "date": "2026-11-12",
            "gold": [{"start": "2026-11-12T06:00:00-06:00", "end": "2026-11-12T22:00:00-06:00"}],
            "silver": [{"start": "2026-11-12T05:30:00-06:00", "end": "2026-11-12T23:15:00-06:00"}],
        }]}
        start, end = event_window(payload, now, CONFIG)
        self.assertEqual(start, dt.datetime(2026, 11, 12, 5, 15, tzinfo=ZONE))
        self.assertEqual(end, dt.datetime(2026, 11, 12, 23, 30, tzinfo=ZONE))

    def test_valid_empty_day_stays_off(self):
        now = dt.datetime(2026, 11, 12, 12, tzinfo=ZONE)
        self.assertIsNone(event_window({"days": [{"date": "2026-11-12", "gold": [], "silver": []}]}, now, CONFIG))
        self.assertEqual(desired_state(now, None), "standby")

    def test_midnight_synthetic_class_card_is_not_an_event(self):
        now = dt.datetime(2026, 11, 12, 12, tzinfo=ZONE)
        payload = {"days": [{
            "date": "2026-11-12",
            "gold": [{"start": "2026-11-12T06:00:00-06:00", "end": "2026-11-12T07:00:00-06:00", "startMinutes": 360}],
            "silver": [{
                "start": "2026-11-12T00:00:00-06:00", "end": "2026-11-12T00:30:00-06:00",
                "startMinutes": 0, "eventTypeId": "c",
            }],
        }]}
        start, _end = event_window(payload, now, CONFIG)
        self.assertEqual(start, dt.datetime(2026, 11, 12, 5, 45, tzinfo=ZONE))

    def test_window_edges(self):
        window = (
            dt.datetime(2026, 11, 12, 5, 45, tzinfo=ZONE),
            dt.datetime(2026, 11, 12, 23, 30, tzinfo=ZONE),
        )
        self.assertEqual(desired_state(window[0], window), "on")
        self.assertEqual(desired_state(window[1], window), "standby")
        self.assertEqual(next_transition(window[0], window), window[1])
        self.assertIsNone(next_transition(window[1], window))

    def test_fallback_hours(self):
        now = dt.datetime(2026, 11, 12, 12, tzinfo=ZONE)
        self.assertEqual(fallback_window(now, CONFIG), (
            dt.datetime(2026, 11, 12, 5, 30, tzinfo=ZONE),
            dt.datetime(2026, 11, 12, 23, 0, tzinfo=ZONE),
        ))


if __name__ == "__main__":
    unittest.main()
