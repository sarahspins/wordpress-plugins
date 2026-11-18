#!/usr/bin/env python3
"""Recover display-node networking without making playback depend on it."""

from __future__ import annotations

import json
import re
import subprocess
from pathlib import Path


STATE_PATH = Path("/run/ice-field-display-connectivity-watchdog.json")


def run(command: list[str]) -> subprocess.CompletedProcess:
    return subprocess.run(command, capture_output=True, text=True, timeout=20, check=False)


def default_gateway() -> str:
    result = run(["ip", "route", "show", "default"])
    match = re.search(r"\bvia\s+(\S+)", result.stdout)
    return match.group(1) if match else ""


def gateway_online(gateway: str) -> bool:
    return bool(gateway) and run(["ping", "-c", "1", "-W", "3", gateway]).returncode == 0


def tailscale_online() -> bool:
    result = run(["tailscale", "status", "--json"])
    if result.returncode != 0:
        return False
    try:
        payload = json.loads(result.stdout)
    except json.JSONDecodeError:
        return False
    return payload.get("BackendState") == "Running" and bool(payload.get("Self", {}).get("Online"))


def load_state(path: Path = STATE_PATH) -> dict[str, int]:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
        return {
            "network_failures": int(payload.get("network_failures", 0)),
            "tailscale_failures": int(payload.get("tailscale_failures", 0)),
        }
    except (OSError, ValueError, TypeError, json.JSONDecodeError):
        return {"network_failures": 0, "tailscale_failures": 0}


def save_state(state: dict[str, int], path: Path = STATE_PATH) -> None:
    temporary = path.with_suffix(".tmp")
    temporary.write_text(json.dumps(state, separators=(",", ":")), encoding="utf-8")
    temporary.replace(path)


def recover_network(failures: int) -> None:
    if failures == 2 or failures % 5 == 0:
        print(f"Gateway unavailable for {failures} checks; restarting NetworkManager.", flush=True)
        run(["systemctl", "restart", "NetworkManager.service"])
        run(["nmcli", "device", "connect", "wlan0"])


def recover_tailscale(failures: int) -> None:
    if failures == 2 or failures % 5 == 0:
        print(f"Tailscale unavailable for {failures} checks; restarting tailscaled.", flush=True)
        run(["systemctl", "restart", "tailscaled.service"])


def main() -> None:
    state = load_state()
    gateway = default_gateway()
    if not gateway_online(gateway):
        state["network_failures"] += 1
        state["tailscale_failures"] = 0
        recover_network(state["network_failures"])
        save_state(state)
        return

    state["network_failures"] = 0
    if tailscale_online():
        state["tailscale_failures"] = 0
    else:
        state["tailscale_failures"] += 1
        recover_tailscale(state["tailscale_failures"])
    save_state(state)


if __name__ == "__main__":
    main()
