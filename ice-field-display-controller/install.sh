#!/bin/sh
set -eu

APP_DIR="$HOME/.local/lib/ice-field-display-controller"
UNIT_DIR="$HOME/.config/systemd/user"
SOURCE_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)

command -v python3 >/dev/null 2>&1 || { echo "Python 3 is required." >&2; exit 1; }
command -v chromium >/dev/null 2>&1 || command -v chromium-browser >/dev/null 2>&1 || { echo "Chromium is required." >&2; exit 1; }
command -v wlr-randr >/dev/null 2>&1 || command -v xrandr >/dev/null 2>&1 || { echo "wlr-randr or xrandr is required." >&2; exit 1; }

mkdir -p "$APP_DIR" "$UNIT_DIR" "$HOME/.local/share/ice-field-display-controller"
mkdir -p "$APP_DIR/assets"
install -m 0755 "$SOURCE_DIR/controller.py" "$APP_DIR/controller.py"
install -m 0755 "$SOURCE_DIR/agent.py" "$APP_DIR/agent.py"
install -m 0755 "$SOURCE_DIR/configure-cursor.py" "$APP_DIR/configure-cursor.py"
install -m 0644 "$SOURCE_DIR/startup.html" "$APP_DIR/startup.html"
install -m 0644 "$SOURCE_DIR/VERSION" "$APP_DIR/VERSION"
install -m 0644 "$SOURCE_DIR/assets/ice-and-field-logo-white.png" "$APP_DIR/assets/ice-and-field-logo-white.png"
install -m 0644 "$SOURCE_DIR/assets/ice-and-field-logo-white.svg" "$APP_DIR/assets/ice-and-field-logo-white.svg"
install -m 0644 "$SOURCE_DIR/assets/ice-and-field-logo.svg" "$APP_DIR/assets/ice-and-field-logo.svg"

sed "s|@APP_DIR@|$APP_DIR|g" "$SOURCE_DIR/systemd/ice-field-display-controller.service.in" > "$UNIT_DIR/ice-field-display-controller.service"
sed "s|@APP_DIR@|$APP_DIR|g" "$SOURCE_DIR/systemd/ice-field-display-agent.service.in" > "$UNIT_DIR/ice-field-display-agent.service"

systemctl --user daemon-reload
systemctl --user enable ice-field-display-controller.service
systemctl --user enable ice-field-display-agent.service
systemctl --user restart ice-field-display-controller.service
systemctl --user restart ice-field-display-agent.service

echo
echo "Ice & Field Display Controller installed."
echo "Open http://127.0.0.1:8787/ on the Pi."
if ! command -v wtype >/dev/null 2>&1 && ! command -v xdotool >/dev/null 2>&1; then
    echo "For compositor-level cursor hiding, run: ./install-cursor-support.sh"
fi
