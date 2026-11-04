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
    labwc -r >/dev/null 2>&1 || true
fi

systemctl --user restart ice-field-display-agent.service

echo
echo "Ice & Field compositor-level cursor hiding is installed."
echo "The pointer will hide after display startup and channel changes."
