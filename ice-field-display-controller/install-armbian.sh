#!/bin/sh
set -eu

SOURCE_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
AUTOLOGIN_USER=$(id -un)

if ! command -v apt-get >/dev/null 2>&1; then
    echo "This installer requires an Armbian Debian/Ubuntu system." >&2
    exit 1
fi

echo "Installing the lightweight packages required by the Ice & Field display node..."
sudo apt-get update
sudo apt-get install -y chromium x11-xserver-utils xdotool

if systemctl list-unit-files lightdm.service >/dev/null 2>&1; then
    autologin_file=$(mktemp)
    trap 'rm -f "$autologin_file"' EXIT HUP INT TERM
    printf '[Seat:*]\nautologin-user=%s\nautologin-user-timeout=0\nuser-session=xfce\n' "$AUTOLOGIN_USER" > "$autologin_file"
    sudo install -d -m 0755 /etc/lightdm/lightdm.conf.d
    sudo install -m 0644 "$autologin_file" /etc/lightdm/lightdm.conf.d/50-ice-field-autologin.conf
fi

"$SOURCE_DIR/install.sh"

echo
echo "Armbian/XFCE display support is installed."
echo "Sign out and back in, or reboot, if the kiosk does not appear within a few seconds."
