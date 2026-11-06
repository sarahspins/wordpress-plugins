#!/bin/sh
set -eu

SOURCE_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
AUTOLOGIN_USER=$(id -un)
SAFE_INSTALL=0

if [ "${1:-}" = "--safe" ]; then
    SAFE_INSTALL=1
elif [ "$#" -gt 0 ]; then
    echo "Usage: $0 [--safe]" >&2
    exit 2
fi

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

override_dir="$HOME/.config/systemd/user/ice-field-display-agent.service.d"
mkdir -p "$override_dir"
cat > "$override_dir/20-armbian-conservative.conf" <<'EOF'
[Service]
Environment=IFDC_CONSERVATIVE_CHROMIUM=1
EOF

if [ "$SAFE_INSTALL" -eq 1 ]; then
    IFDC_ENABLE_AGENT=0 "$SOURCE_DIR/install.sh"
else
    "$SOURCE_DIR/install.sh"
fi

echo
echo "Armbian/XFCE display support is installed."
if [ "$SAFE_INSTALL" -eq 1 ]; then
    echo "The display agent remains disabled for safe recovery testing."
    echo "Start it with: systemctl --user enable --now ice-field-display-agent.service"
else
    echo "Sign out and back in, or reboot, if the kiosk does not appear within a few seconds."
fi
