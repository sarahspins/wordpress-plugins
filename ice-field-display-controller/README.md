# Ice & Field Display Controller

Self-contained Raspberry Pi display management for Ice & Field. One physical Pi is a **node** and may drive up to two independently assigned HDMI **screens**. The first Pi can also host the controller.

## Phase 1 capabilities

- Detects connected Wayland/HDMI outputs with `wlr-randr`.
- Assigns a web page, image, video, or standby screen to each output.
- Uses the locally bundled white Ice & Field logo on the branded standby screen, with no website dependency.
- Stores assignments locally and restores them after a reboot.
- Runs one isolated Chromium kiosk instance per connected output.
- Uses Chromium's local basic password store for kiosk profiles so desktop keyring prompts cannot block unattended startup.
- Provides a local, beginner-friendly control panel.
- Keeps controller and node concepts separate so fleet synchronization can be added without changing screen assignments.

Remote multi-user access and additional-node enrollment are intentionally deferred until local kiosk behavior has been verified on the Raspberry Pi.

## Install on Raspberry Pi OS Desktop

Copy this directory to the Pi, open a terminal inside it, and run:

```bash
./install.sh
```

The installer uses only software already present in a standard Raspberry Pi OS Desktop installation. It installs two user services:

- `ice-field-display-controller.service` — local control panel and display pages
- `ice-field-display-agent.service` — HDMI discovery and Chromium kiosk supervision

The control panel is available locally at `http://127.0.0.1:8787/`. It is intentionally not exposed to the network until authenticated remote access is implemented.

## Service controls

```bash
systemctl --user status ice-field-display-controller.service
systemctl --user status ice-field-display-agent.service
systemctl --user restart ice-field-display-agent.service
journalctl --user -u ice-field-display-agent.service -f
```

## Development

```bash
python3 -m unittest discover -s tests
```

