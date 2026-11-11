#!/bin/sh
set -eu

SOURCE_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
AUTOLOGIN_USER=$(id -un)
SAFE_INSTALL=0
TEMP_DIR=$(mktemp -d)
trap 'rm -rf "$TEMP_DIR"' EXIT HUP INT TERM

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

echo "Protecting 1 GB nodes from 4K framebuffer allocation failures..."
kernel_connector=HDMI-A-1
for connector_path in /sys/class/drm/card*-HDMI-A-*; do
    [ -e "$connector_path" ] || continue
    connector_name=${connector_path##*/}
    kernel_connector=${connector_name#*-}
    [ "$(cat "$connector_path/status" 2>/dev/null || true)" = "connected" ] && break
done
boot_env=/boot/armbianEnv.txt
if [ -f "$boot_env" ]; then
    boot_video="video=$kernel_connector:1920x1080@60"
    awk -v boot_video="$boot_video" '
        BEGIN { found = 0 }
        /^extraargs=/ {
            found = 1
            if ($0 !~ /(^| )video=HDMI-A-[^ ]+:/) {
                print $0 " " boot_video
            } else {
                print
            }
            next
        }
        { print }
        END { if (!found) print "extraargs=" boot_video }
    ' "$boot_env" > "$TEMP_DIR/armbianEnv.txt"
    if [ ! -f "$boot_env.ice-field-backup" ]; then
        sudo cp "$boot_env" "$boot_env.ice-field-backup"
    fi
    sudo install -m 0644 "$TEMP_DIR/armbianEnv.txt" "$boot_env"
fi

cat > "$TEMP_DIR/20-ice-field-display.conf" <<'EOF'
Section "Monitor"
    Identifier "HDMI-1"
    Option "PreferredMode" "1920x1080"
EndSection
EOF
sudo install -d -m 0755 /etc/X11/xorg.conf.d
sudo install -m 0644 "$TEMP_DIR/20-ice-field-display.conf" /etc/X11/xorg.conf.d/20-ice-field-display.conf

if systemctl list-unit-files lightdm.service >/dev/null 2>&1; then
    autologin_file="$TEMP_DIR/50-ice-field-autologin.conf"
    printf '[Seat:*]\nautologin-user=%s\nautologin-user-timeout=0\nuser-session=xfce\n' "$AUTOLOGIN_USER" > "$autologin_file"
    sudo install -d -m 0755 /etc/lightdm/lightdm.conf.d
    sudo install -m 0644 "$autologin_file" /etc/lightdm/lightdm.conf.d/50-ice-field-autologin.conf
fi

autostart_dir="$HOME/.config/autostart"
mkdir -p "$autostart_dir"
printf '%s\n' \
    '[Desktop Entry]' \
    'Type=Application' \
    'Name=Light Locker' \
    'Hidden=true' \
    > "$autostart_dir/light-locker.desktop"
pkill light-locker 2>/dev/null || true

override_dir="$HOME/.config/systemd/user/ice-field-display-agent.service.d"
mkdir -p "$override_dir"
cat > "$override_dir/20-armbian-conservative.conf" <<'EOF'
[Service]
Environment=IFDC_CONSERVATIVE_CHROMIUM=1
Environment=IFDC_X11_MODE=1920x1080
Environment=IFDC_X11_RATE=30
ExecStartPre=/bin/sleep 15
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
    echo "Reboot to apply the protected 1080p boot and Xorg display modes."
fi
