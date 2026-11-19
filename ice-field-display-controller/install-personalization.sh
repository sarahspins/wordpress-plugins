#!/bin/sh
set -eu

SOURCE_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)

echo "Installing Ice & Field golden-image first-boot support..."
sudo install -m 0755 "$SOURCE_DIR/personalize-node.sh" /usr/local/sbin/ice-field-personalize-node
sudo install -d -m 0755 /usr/local/lib/ice-field-display-controller
sudo install -m 0755 "$SOURCE_DIR/personalize_state.py" /usr/local/lib/ice-field-display-controller/personalize_state.py
sudo install -m 0644 "$SOURCE_DIR/systemd/ice-field-display-personalize.service" /etc/systemd/system/ice-field-display-personalize.service
sudo systemctl daemon-reload
sudo systemctl enable ice-field-display-personalize.service

echo "Golden-image first-boot support is installed."
echo "When ready to image this card, run:"
echo "  sudo ice-field-personalize-node --prepare-image"
