#!/bin/sh
set -eu

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this installer with sudo: sudo ./install-recovery.sh" >&2
    exit 1
fi

SOURCE_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
APP_DIR=/usr/local/lib/ice-field-display-controller
SYSTEMD_DIR=/etc/systemd/system

install -d -m 0755 "$APP_DIR"
install -m 0755 "$SOURCE_DIR/connectivity_watchdog.py" "$APP_DIR/connectivity_watchdog.py"
install -m 0644 "$SOURCE_DIR/systemd/ice-field-display-connectivity-watchdog.service" \
    "$SYSTEMD_DIR/ice-field-display-connectivity-watchdog.service"
install -m 0644 "$SOURCE_DIR/systemd/ice-field-display-connectivity-watchdog.timer" \
    "$SYSTEMD_DIR/ice-field-display-connectivity-watchdog.timer"

# Preserve enough history to diagnose a prior crash without filling the SD card.
install -d -m 0755 /var/log/journal /etc/systemd/journald.conf.d
cat > /etc/systemd/journald.conf.d/ice-field-display.conf <<'EOF'
[Journal]
Storage=persistent
SystemMaxUse=200M
RuntimeMaxUse=50M
MaxRetentionSec=14day
EOF

# Let systemd recover a completely frozen board. Network failures are handled
# separately and never trigger an unattended reboot loop.
if [ -e /dev/watchdog ] || [ -e /dev/watchdog0 ]; then
    install -d -m 0755 /etc/systemd/system.conf.d
    cat > /etc/systemd/system.conf.d/ice-field-display-watchdog.conf <<'EOF'
[Manager]
RuntimeWatchdogSec=60s
RebootWatchdogSec=10min
EOF
else
    echo "Hardware watchdog not detected; freeze recovery was skipped."
fi

systemctl enable NetworkManager.service tailscaled.service
if systemctl list-unit-files ssh.service >/dev/null 2>&1; then
    systemctl enable ssh.service
elif systemctl list-unit-files sshd.service >/dev/null 2>&1; then
    systemctl enable sshd.service
fi
systemctl daemon-reload
systemctl enable --now ice-field-display-connectivity-watchdog.timer
systemctl restart systemd-journald.service
systemctl daemon-reexec

echo
echo "Ice & Field connectivity recovery installed."
echo "The network check runs after three minutes and every two minutes thereafter."
