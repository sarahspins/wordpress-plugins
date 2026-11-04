#!/bin/sh
set -eu

SOURCE_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)

if ! command -v wtype >/dev/null 2>&1; then
    echo "Installing the Wayland cursor helper..."
    sudo apt-get update
    sudo apt-get install -y wtype
fi

python3 "$SOURCE_DIR/configure-cursor.py"

if command -v labwc >/dev/null 2>&1; then
    LABWC_PID_VALUE=$(pgrep -u "$(id -u)" -x labwc | head -n 1 || true)
    if [ -n "$LABWC_PID_VALUE" ]; then
        LABWC_PID="$LABWC_PID_VALUE" \
        XDG_RUNTIME_DIR="/run/user/$(id -u)" \
        WAYLAND_DISPLAY="${WAYLAND_DISPLAY:-wayland-0}" \
        labwc -r >/dev/null 2>&1 || true
    fi
fi

systemctl --user restart ice-field-display-agent.service

XDG_RUNTIME_DIR="/run/user/$(id -u)" \
WAYLAND_DISPLAY="${WAYLAND_DISPLAY:-wayland-0}" \
wtype -M alt -M logo h -m logo -m alt >/dev/null 2>&1 || true

echo
echo "Ice & Field compositor-level cursor hiding is installed."
echo "The pointer will hide after display startup and channel changes."
