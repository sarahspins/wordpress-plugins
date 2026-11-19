#!/usr/bin/env python3
"""Apply clone-specific friendly identity without disturbing synchronized content."""

from __future__ import annotations

import json
import os
import sys
import tempfile
from pathlib import Path


def personalize_state(path: Path, hostname: str, screen_name: str) -> None:
    state = json.loads(path.read_text(encoding="utf-8"))
    state["node"] = {"id": hostname, "name": screen_name}
    assignments = state.get("assignments", {})
    if isinstance(assignments, dict) and len(assignments) == 1:
        assignment = next(iter(assignments.values()))
        if isinstance(assignment, dict):
            assignment["label"] = screen_name
    state["revision"] = int(state.get("revision", 0)) + 1

    fd, temporary = tempfile.mkstemp(prefix="state-personalized-", suffix=".json", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(state, handle, indent=2, sort_keys=True)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temporary, path.stat().st_mode & 0o777)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def main() -> None:
    if len(sys.argv) != 4:
        raise SystemExit("Usage: personalize_state.py STATE_FILE HOSTNAME SCREEN_NAME")
    personalize_state(Path(sys.argv[1]), sys.argv[2], sys.argv[3])


if __name__ == "__main__":
    main()
