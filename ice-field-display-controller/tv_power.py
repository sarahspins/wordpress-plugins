#!/usr/bin/env python3
"""Schedule HDMI-CEC TV power from the public weekly rink calendar cache."""

from __future__ import annotations

import json
import os
import re
import subprocess
import time
from datetime import datetime, time as clock_time, timedelta
from pathlib import Path
from urllib.request import Request, urlopen
from zoneinfo import ZoneInfo

from controller import state_directory


DEFAULTS = {
    "enabled": True,
    "schedule_base_url": "https://iceandfield.com/wp-content/uploads/ice-field-schedule-cache",
    "timezone": "America/Chicago",
    "wake_minutes_before": 15,
    "standby_minutes_after": 15,
    "fallback_on": "05:30",
    "fallback_off": "23:00",
    "cec_devices": "auto",
    "cec_device": "/dev/cec0",
    "poll_seconds": 60,
}


def load_config() -> dict:
    config = dict(DEFAULTS)
    try:
        saved = json.loads((state_directory() / "sync.json").read_text(encoding="utf-8"))
        if isinstance(saved.get("tv_power"), dict):
            config.update(saved["tv_power"])
    except (OSError, json.JSONDecodeError, AttributeError):
        pass
    return config


def week_start(day: datetime) -> str:
    return (day.date() - timedelta(days=day.weekday())).isoformat()


def schedule_url(config: dict, now: datetime) -> str:
    return f"{str(config['schedule_base_url']).rstrip('/')}/week-{week_start(now)}.json"


def fetch_schedule(config: dict, now: datetime, cache_path: Path) -> dict:
    request = Request(schedule_url(config, now), headers={
        "Accept": "application/json",
        "Cache-Control": "no-cache",
        "User-Agent": "IceFieldDisplay/0.1",
    })
    try:
        with urlopen(request, timeout=15) as response:
            payload = json.loads(response.read().decode("utf-8"))
        if not isinstance(payload.get("days"), list):
            raise ValueError("Weekly schedule has no daily data.")
        temporary = cache_path.with_suffix(".tmp")
        temporary.write_text(json.dumps(payload, separators=(",", ":")), encoding="utf-8")
        temporary.replace(cache_path)
        return payload
    except Exception:
        try:
            payload = json.loads(cache_path.read_text(encoding="utf-8"))
            if payload.get("weekStart") == week_start(now) and isinstance(payload.get("days"), list):
                return payload
        except (OSError, json.JSONDecodeError, AttributeError):
            pass
        raise


def parse_local(value: str, timezone: ZoneInfo) -> datetime:
    parsed = datetime.fromisoformat(value)
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone)
    return parsed.astimezone(timezone)


def event_window(payload: dict, now: datetime, config: dict) -> tuple[datetime, datetime] | None:
    day_key = now.date().isoformat()
    day = next((item for item in payload.get("days", []) if item.get("date") == day_key), None)
    if day is None:
        raise ValueError(f"Weekly schedule does not contain {day_key}.")
    timezone = ZoneInfo(str(config["timezone"]))
    events = [
        event
        for rink in ("gold", "silver")
        for event in day.get(rink, [])
        if isinstance(event, dict) and event.get("start") and event.get("end")
        # The public calendar can include a synthetic class card at midnight
        # whose title contains its real daytime meeting time. It is not a
        # midnight facility event and must not wake the TVs the night before.
        and not (str(event.get("eventTypeId", "")) == "c" and int(event.get("startMinutes", -1)) == 0)
    ]
    if not events:
        return None
    starts = [parse_local(str(event["start"]), timezone) for event in events]
    ends = [parse_local(str(event["end"]), timezone) for event in events]
    return (
        min(starts) - timedelta(minutes=int(config["wake_minutes_before"])),
        max(ends) + timedelta(minutes=int(config["standby_minutes_after"])),
    )


def fallback_window(now: datetime, config: dict) -> tuple[datetime, datetime]:
    def parse_clock(value: str) -> clock_time:
        return datetime.strptime(value, "%H:%M").time()

    return (
        datetime.combine(now.date(), parse_clock(str(config["fallback_on"])), tzinfo=now.tzinfo),
        datetime.combine(now.date(), parse_clock(str(config["fallback_off"])), tzinfo=now.tzinfo),
    )


def desired_state(now: datetime, window: tuple[datetime, datetime] | None) -> str:
    return "on" if window is not None and window[0] <= now < window[1] else "standby"


def cec_devices(config: dict) -> list[str]:
    configured = config.get("cec_devices")
    if isinstance(configured, list):
        return [str(device) for device in configured if str(device)]
    if configured and str(configured).lower() != "auto":
        return [device.strip() for device in str(configured).split(",") if device.strip()]
    if "cec_devices" not in config and config.get("cec_device"):
        return [str(config["cec_device"])]
    return sorted(str(device) for device in Path("/dev").glob("cec[0-9]*"))


def cec_power_status(device: str) -> str:
    query = subprocess.run(
        ["cec-ctl", "-d", device, "--to", "0", "--give-device-power-status", "-v"],
        check=True,
        timeout=10,
        capture_output=True,
        text=True,
    )
    match = re.search(r"pwr-state:\s*([a-z-]+)", query.stdout, re.IGNORECASE)
    return match.group(1).lower() if match else "unknown"


def send_cec_device(state: str, device: str) -> str:
    subprocess.run(
        ["cec-ctl", "-d", device, "--playback", "--osd-name", "Ice & Field Display"],
        check=True,
        timeout=10,
        stdout=subprocess.DEVNULL,
    )
    command = "--image-view-on" if state == "on" else "--standby"
    subprocess.run(
        ["cec-ctl", "-d", device, "--to", "0", command],
        check=True,
        timeout=10,
        stdout=subprocess.DEVNULL,
    )
    if state == "on":
        # Waking a television and selecting this HDMI source are distinct CEC
        # operations. Give slower TV input controllers a moment to initialize,
        # then announce the Pi's detected connector address as active.
        time.sleep(2)
        query = subprocess.run(
            ["cec-ctl", "-d", device, "--physical-address"],
            check=True,
            timeout=10,
            capture_output=True,
            text=True,
        )
        matches = re.findall(r"(?im)^\s*([0-9a-f]\.[0-9a-f]\.[0-9a-f]\.[0-9a-f])\s*$", query.stdout)
        if not matches:
            print("HDMI-CEC warning: physical address could not be detected; TV woke without input selection.", flush=True)
        else:
            subprocess.run(
                ["cec-ctl", "-d", device, "--active-source", f"phys-addr={matches[-1]}"],
                check=True,
                timeout=10,
                stdout=subprocess.DEVNULL,
            )
    return cec_power_status(device)


def send_cec(state: str, config: dict) -> dict[str, str]:
    devices = cec_devices(config)
    if not devices:
        raise OSError("No HDMI-CEC adapters were found.")
    expected = {"on", "to-on"} if state == "on" else {"standby", "to-standby"}
    attempts = 3 if state == "on" else 2
    results = {}
    for device in devices:
        for attempt in range(attempts):
            try:
                status = send_cec_device(state, device)
                results[device] = status
                if status in expected:
                    break
            except (OSError, subprocess.SubprocessError) as error:
                results[device] = f"error: {error}"
            if attempt + 1 < attempts:
                time.sleep(5)
    return results


def write_status(
    directory: Path,
    state: str,
    mode: str,
    message: str,
    next_transition: datetime | None,
    cec_results: dict[str, str] | None = None,
) -> None:
    path = directory / "tv-power-status.json"
    temporary = path.with_suffix(".tmp")
    payload = {
        "available": True,
        "state": state,
        "mode": mode,
        "message": message,
        "next_transition": next_transition.isoformat() if next_transition else "",
        "cec_results": cec_results or {},
        "updated_at": int(time.time()),
    }
    temporary.write_text(json.dumps(payload, separators=(",", ":")), encoding="utf-8")
    os.replace(temporary, path)


def next_transition(now: datetime, window: tuple[datetime, datetime] | None) -> datetime | None:
    if window is None:
        return None
    if now < window[0]:
        return window[0]
    if now < window[1]:
        return window[1]
    return None


def read_manual_command(directory: Path) -> str:
    path = directory / "tv-power-command.json"
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
        action = str(payload.get("action", ""))
        return action if action in {"on", "standby"} else ""
    except (OSError, json.JSONDecodeError, AttributeError):
        return ""
    finally:
        path.unlink(missing_ok=True)


def main() -> None:
    directory = state_directory()
    cache_path = directory / "weekly-schedule.json"
    last_scheduled_state = ""
    next_fetch = 0.0
    payload = None
    while True:
        config = load_config()
        if not config.get("enabled", True):
            time.sleep(60)
            continue
        timezone = ZoneInfo(str(config["timezone"]))
        now = datetime.now(timezone)
        using_fallback = False
        if payload is None or time.monotonic() >= next_fetch:
            try:
                payload = fetch_schedule(config, now, cache_path)
            except Exception as error:
                payload = None
                using_fallback = True
                print(f"TV schedule warning: {error}; using fixed fallback hours.", flush=True)
            next_fetch = time.monotonic() + 300
        try:
            window = event_window(payload, now, config) if payload is not None else fallback_window(now, config)
        except Exception as error:
            using_fallback = True
            window = fallback_window(now, config)
            print(f"TV schedule warning: {error}; using fixed fallback hours.", flush=True)
        state = desired_state(now, window)
        transition = next_transition(now, window)
        if state != last_scheduled_state:
            try:
                results = send_cec(state, config)
                detail = "no rink events" if window is None else f"{window[0].isoformat()} to {window[1].isoformat()}"
                source = "fallback" if using_fallback else "rink schedule"
                print(f"TV {state} requested from {source}: {detail}; CEC results: {results}", flush=True)
                write_status(directory, state, "schedule", f"Following {source}.", transition, results)
                last_scheduled_state = state
            except (OSError, subprocess.SubprocessError) as error:
                print(f"HDMI-CEC warning: {error}", flush=True)
        manual = read_manual_command(directory)
        if manual:
            try:
                results = send_cec(manual, config)
                print(f"TV {manual} requested manually from the management page; CEC results: {results}", flush=True)
                write_status(directory, manual, "manual", "Manual override active until the next scheduled transition.", transition, results)
            except (OSError, subprocess.SubprocessError) as error:
                print(f"HDMI-CEC manual-control warning: {error}", flush=True)
        # This only checks a tiny local command file. Network schedule refreshes
        # remain independently limited to five-minute intervals above.
        time.sleep(1)


if __name__ == "__main__":
    main()
