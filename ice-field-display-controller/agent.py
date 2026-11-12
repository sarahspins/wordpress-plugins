#!/usr/bin/env python3
"""Supervise one Chromium kiosk window per connected display output."""

from __future__ import annotations

import os
import re
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


def configure_wayland_signage() -> bool:
    mode = os.environ.get("IFDC_WAYLAND_MODE", "").strip()
    rate = os.environ.get("IFDC_WAYLAND_RATE", "").strip()
    if not mode:
        return True
    try:
        query = subprocess.run(
            ["wlr-randr"], capture_output=True, text=True, timeout=5, check=True,
        )
        outputs: list[tuple[str, list[str]]] = []
        current_name = ""
        available: list[str] = []
        for line in query.stdout.splitlines():
            if line and not line[0].isspace():
                if current_name:
                    outputs.append((current_name, available))
                current_name = line.split(" ", 1)[0]
                available = []
                continue
            match = re.match(r"^\s+(\d+x\d+) px, ([0-9.]+) Hz", line)
            if match and match.group(1) == mode:
                available.append(match.group(2))
        if current_name:
            outputs.append((current_name, available))
        if not outputs or any(not rates for _name, rates in outputs):
            return False
        width = int(mode.split("x", 1)[0])
        command = ["wlr-randr"]
        x = 0
        for output, rates in outputs:
            chosen = rates[0]
            if rate:
                chosen = min(rates, key=lambda item: abs(float(item) - float(rate)))
            command.extend(["--output", output, "--mode", f"{mode}@{chosen}Hz", "--pos", f"{x},0"])
            x += width
        requested = subprocess.run(command, timeout=12, check=False)
        if requested.returncode != 0:
            print(f"Could not apply the requested Wayland signage layout; retaining the active layout.", file=sys.stderr, flush=True)
        print(f"Wayland signage configuration completed for {len(outputs)} output(s).", flush=True)
        return True
    except (OSError, ValueError, subprocess.SubprocessError):
        return False


def configure_x11_signage() -> bool:
    mode = os.environ.get("IFDC_X11_MODE", "").strip()
    rate = os.environ.get("IFDC_X11_RATE", "").strip()
    if not mode:
        return True
    try:
        subprocess.run(
            ["xset", "s", "off", "-dpms"],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            timeout=3,
            check=False,
        )
        query = subprocess.run(
            ["xrandr", "--query"],
            capture_output=True,
            text=True,
            timeout=5,
            check=True,
        )
        outputs = [
            line.split()[0]
            for line in query.stdout.splitlines()
            if " connected" in line and not line.startswith(" ")
        ]
        if not outputs:
            return False
        for output in outputs:
            command = ["xrandr", "--output", output, "--mode", mode]
            if rate:
                command.extend(["--rate", rate])
            requested = subprocess.run(command, timeout=8, check=False)
            if requested.returncode == 0:
                continue
            if rate:
                fallback = subprocess.run(
                    ["xrandr", "--output", output, "--mode", mode],
                    timeout=8,
                    check=False,
                )
                if fallback.returncode == 0:
                    print(
                        f"{output} does not accept {mode}@{rate}; using its supported {mode} refresh rate.",
                        flush=True,
                    )
                    continue
            print(
                f"Could not apply {mode} to {output}; retaining the active X11 mode and continuing.",
                file=sys.stderr,
                flush=True,
            )
        print(f"X11 signage configuration completed for {len(outputs)} output(s).", flush=True)
        return True
    except (OSError, subprocess.SubprocessError):
        return False


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


def launch(output: dict, force_x11: bool = False) -> subprocess.Popen:
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
    if force_x11:
        command.append("--ozone-platform=x11")
    if os.environ.get("IFDC_CONSERVATIVE_CHROMIUM") == "1":
        command.append("--disable-features=Vulkan")
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
    display_configured = False
    signal.signal(signal.SIGTERM, stop_all)
    signal.signal(signal.SIGINT, stop_all)
    while True:
        revision = controller_revision()
        if revision is None:
            time.sleep(POLL_SECONDS)
            continue
        if not display_configured:
            if os.environ.get("WAYLAND_DISPLAY") and shutil.which("wlr-randr"):
                display_configured = configure_wayland_signage()
            else:
                display_configured = configure_x11_signage()
            if not display_configured:
                time.sleep(POLL_SECONDS)
                continue
        if revision != last_revision:
            last_revision = revision
            hide_cursor_at = time.monotonic() + 8
        outputs = detect_outputs()
        desired_mode = os.environ.get("IFDC_WAYLAND_MODE", "").strip()
        if os.environ.get("WAYLAND_DISPLAY") and desired_mode and outputs:
            desired_width, desired_height = (int(value) for value in desired_mode.split("x", 1))
            expected_x = 0
            layout_wrong = False
            for output in outputs:
                if (output["width"], output["height"], output["x"], output["y"]) != (desired_width, desired_height, expected_x, 0):
                    layout_wrong = True
                expected_x += desired_width
            if layout_wrong and configure_wayland_signage():
                outputs = detect_outputs()
        force_x11 = bool(os.environ.get("WAYLAND_DISPLAY") and os.environ.get("DISPLAY") and len(outputs) > 1)
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
                processes[output["name"]] = (launch(output, force_x11=force_x11), signature)
                hide_cursor_at = max(hide_cursor_at, time.monotonic() + 8)
            except RuntimeError as error:
                print(error, file=sys.stderr, flush=True)
        if hide_cursor_at and time.monotonic() >= hide_cursor_at:
            hide_cursor()
            hide_cursor_at = 0
        time.sleep(POLL_SECONDS)


if __name__ == "__main__":
    main()
