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
from urllib.request import urlopen

from controller import detect_outputs

CONTROLLER = os.environ.get("IFDC_CONTROLLER_URL", "http://127.0.0.1:8787")
POLL_SECONDS = 5
processes: dict[str, tuple[subprocess.Popen, tuple]] = {}
last_revision: int | None = None
hide_cursor_at = 0.0


def chromium_binary() -> str:
    for candidate in ("chromium", "chromium-browser"):
        found = shutil.which(candidate)
        if found:
            return found
    raise RuntimeError("Chromium is not installed.")


def controller_revision() -> int | None:
    try:
        with urlopen(f"{CONTROLLER}/api/revision", timeout=1) as response:
            if response.status != 200:
                return None
            import json
            return int(json.loads(response.read().decode("utf-8")).get("revision", 0))
    except (OSError, ValueError):
        return None


def hide_cursor() -> None:
    helper = shutil.which("wtype")
    command = None
    if helper:
        command = [helper, "-M", "alt", "-M", "logo", "h", "-m", "logo", "-m", "alt"]
    else:
        helper = shutil.which("xdotool")
        if helper:
            command = [helper, "mousemove", "--sync", "8191", "8191"]
    if command:
        try:
            subprocess.run(
                command, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                timeout=3, check=False,
            )
        except (OSError, subprocess.SubprocessError):
            pass


def launch(output: dict) -> subprocess.Popen:
    profile = Path.home() / ".local/share/ice-field-display-controller/chromium" / output["name"]
    profile.mkdir(parents=True, exist_ok=True)
    target = f"{CONTROLLER}/display/{quote(output['name'])}"
    startup = Path(__file__).resolve().parent / "startup.html"
    url = f"{startup.as_uri()}?target={quote(target, safe='')}"
    command = [
        chromium_binary(),
        f"--user-data-dir={profile}",
        "--no-first-run",
        "--password-store=basic",
        "--default-background-color=043c5eff",
        "--disable-session-crashed-bubble",
        "--disable-infobars",
        "--disable-background-networking",
        "--disable-component-update",
        "--disable-dev-shm-usage",
        "--disable-sync",
        "--metrics-recording-only",
        "--no-default-browser-check",
        "--autoplay-policy=no-user-gesture-required",
        "--check-for-update-interval=31536000",
        "--kiosk",
        f"--window-position={output['x']},{output['y']}",
        f"--window-size={output['width']},{output['height']}",
    ]
    if os.environ.get("IFDC_CONSERVATIVE_CHROMIUM") == "1":
        command.extend([
            "--disable-gpu",
            "--disable-gpu-compositing",
            "--disable-accelerated-2d-canvas",
            "--disable-accelerated-video-decode",
            "--disable-features=UseSkiaRenderer,Vulkan",
        ])
    command.append(url)
    state_dir = Path.home() / ".local/state/ice-field-display-controller"
    state_dir.mkdir(parents=True, exist_ok=True)
    safe_name = "".join(character if character.isalnum() or character in "-_" else "_" for character in output["name"])
    log_path = state_dir / f"chromium-{safe_name}.log"
    print(f"Starting {output['name']} at {output['x']},{output['y']} ({output['width']}x{output['height']})", flush=True)
    print(f"Chromium log: {log_path}", flush=True)
    with log_path.open("a", encoding="utf-8") as log_file:
        log_file.write(f"\n--- Chromium launch {time.strftime('%Y-%m-%d %H:%M:%S')} ---\n")
        log_file.flush()
        return subprocess.Popen(command, stdout=log_file, stderr=subprocess.STDOUT)


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
    global last_revision, hide_cursor_at
    signal.signal(signal.SIGTERM, stop_all)
    signal.signal(signal.SIGINT, stop_all)
    while True:
        revision = controller_revision()
        if revision is None:
            time.sleep(POLL_SECONDS)
            continue
        if revision != last_revision:
            last_revision = revision
            hide_cursor_at = time.monotonic() + 8
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
            if existing and existing[0].poll() is not None:
                print(
                    f"Chromium for {output['name']} exited with status {existing[0].returncode}; restarting.",
                    file=sys.stderr,
                    flush=True,
                )
            if existing and existing[0].poll() is None:
                existing[0].terminate()
            try:
                processes[output["name"]] = (launch(output), signature)
                hide_cursor_at = max(hide_cursor_at, time.monotonic() + 8)
            except RuntimeError as error:
                print(error, file=sys.stderr, flush=True)
        if hide_cursor_at and time.monotonic() >= hide_cursor_at:
            hide_cursor()
            hide_cursor_at = 0
        time.sleep(POLL_SECONDS)


if __name__ == "__main__":
    main()
