#!/usr/bin/env python3
"""Install the Ice & Field cursor-hide key binding into Labwc."""

from __future__ import annotations

import shutil
from pathlib import Path

MARKER = "Ice & Field display cursor support"
BINDING = f"""    <!-- {MARKER} -->
    <keybind key="A-W-h">
      <action name="HideCursor" />
      <action name="WarpCursor" x="-1" y="-1" />
    </keybind>
"""


def main() -> None:
    target = Path.home() / ".config/labwc/rc.xml"
    if not target.exists():
        system = Path("/etc/xdg/labwc/rc.xml")
        if not system.exists():
            raise SystemExit("Labwc configuration was not found. Cursor support was not changed.")
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(system, target)
    text = target.read_text(encoding="utf-8")
    if MARKER in text:
        print("Labwc cursor support is already configured.")
        return
    if "</keyboard>" not in text:
        raise SystemExit("The Labwc keyboard section was not found. Cursor support was not changed.")
    backup = target.with_suffix(".xml.ice-field-backup")
    if not backup.exists():
        shutil.copy2(target, backup)
    target.write_text(text.replace("</keyboard>", BINDING + "  </keyboard>", 1), encoding="utf-8")
    print(f"Configured Labwc cursor support in {target}")


if __name__ == "__main__":
    main()
