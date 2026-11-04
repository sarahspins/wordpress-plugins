#!/usr/bin/env python3
"""Supervise one Chromium kiosk window per connected display output."""

from __future__ import annotations

import os
import shutil
import signal
import subprocess
import sys
import time
from pathlib import Path
from urllib.parse import quote

from controller import detect_outputs

CONTROLLER = os.environ.get("IFDC_CONTROLLER_URL", "http://127.0.0.1:8787")
POLL_SECONDS = 5
processes: dict[str, tuple[subprocess.Popen, tuple]] = {}


def chromium_binary() -> str:
    for candidate in ("chromium", "chromium-browser"):
        found = shutil.which(candidate)
        if found:
            return found
    raise RuntimeError("Chromium is not installed.")


def launch(output: dict) -> subprocess.Popen:
    profile = Path.home() / ".local/share/ice-field-display-controller/chromium" / output["name"]
    profile.mkdir(parents=True, exist_ok=True)
    url = f"{CONTROLLER}/display/{quote(output['name'])}"
    command = [
        chromium_binary(),
        f"--user-data-dir={profile}",
        "--no-first-run",
        "--password-store=basic",
        "--disable-session-crashed-bubble",
        "--disable-infobars",
        "--autoplay-policy=no-user-gesture-required",
        "--check-for-update-interval=31536000",
        "--kiosk",
        f"--window-position={output['x']},{output['y']}",
        f"--window-size={output['width']},{output['height']}",
        url,
    ]
    print(f"Starting {output['name']} at {output['x']},{output['y']} ({output['width']}x{output['height']})", flush=True)
    return subprocess.Popen(command, stdout=subprocess.DEVNULL, stderr=subprocess.STDOUT)


def stop_all(*_args: object) -> None:
    for process, _signature in processes.values():
        if process.poll() is None:
            process.terminate()
    deadline = time.time() + 4
    for process, _signature in processes.values():
        remaining = max(0, deadline - time.time())
        try:
            process.wait(timeout=remaining)
        except subprocess.TimeoutExpired:
            process.kill()
    sys.exit(0)


def main() -> None:
    signal.signal(signal.SIGTERM, stop_all)
    signal.signal(signal.SIGINT, stop_all)
    while True:
        outputs = detect_outputs()
        active_names = {output["name"] for output in outputs}
        for name in list(processes):
            process, _signature = processes[name]
            if name not in active_names:
                if process.poll() is None:
                    process.terminate()
                del processes[name]
        for output in outputs:
            signature = (output["x"], output["y"], output["width"], output["height"])
            existing = processes.get(output["name"])
            if existing and existing[0].poll() is None and existing[1] == signature:
                continue
            if existing and existing[0].poll() is None:
                existing[0].terminate()
            try:
                processes[output["name"]] = (launch(output), signature)
            except RuntimeError as error:
                print(error, file=sys.stderr, flush=True)
        time.sleep(POLL_SECONDS)


if __name__ == "__main__":
    main()
