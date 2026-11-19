#!/bin/sh
set -eu

PENDING_DIR=/var/lib/ice-field-display-controller
PENDING_FILE=$PENDING_DIR/pending-node-number
SCREEN_NAME_FILE=$PENDING_DIR/pending-screen-name
COMPLETE_FILE=$PENDING_DIR/node-identity
GOLDEN_MARKER=$PENDING_DIR/golden-image
SOURCE_HARDWARE_FILE=$PENDING_DIR/golden-source-hardware
STATE_HELPER=/usr/local/lib/ice-field-display-controller/personalize_state.py

usage() {
    cat <<'EOF'
Usage:
  sudo ice-field-personalize-node NODE_NUMBER
  sudo ice-field-personalize-node --apply-pending
  sudo ice-field-personalize-node --prepare-image

Examples:
  sudo ice-field-personalize-node 3
  sudo ice-field-personalize-node 03

The numbered form stages a unique node identity and reboots. During the next
boot, the early personalization service applies the hostname, regenerates the
Linux machine ID and SSH host keys, clears the cloned Tailscale identity, and
reboots once more. Run `sudo tailscale up` after the node returns.

The prepare-image form records this Orange Pi's hardware identity, arms the SD
card as a reusable golden image, and powers it off. The original board continues
to boot normally. A copied card booted in a different Orange Pi asks locally for
its display number and friendly screen/location name before networking starts.
EOF
}

hardware_identity() {
    serial=$(tr -d '\000\r\n' < /proc/device-tree/serial-number 2>/dev/null || true)
    if [ -n "$serial" ]; then
        printf 'serial:%s\n' "$serial"
        return 0
    fi
    for interface in /sys/class/net/*; do
        [ -f "$interface/address" ] || continue
        name=${interface##*/}
        case "$name" in lo|tailscale*|docker*|br-*|veth*) continue ;; esac
        address=$(cat "$interface/address" 2>/dev/null || true)
        [ -n "$address" ] && [ "$address" != "00:00:00:00:00:00" ] && printf '%s:%s\n' "$name" "$address"
    done | sort
}

normalize_node_number() {
    raw=$1
    case "$raw" in
        ''|*[!0-9]*) return 1 ;;
    esac
    number=$(printf '%s' "$raw" | sed 's/^0*//')
    [ -n "$number" ] || number=0
    [ "$number" -ge 1 ] 2>/dev/null || return 1
    [ "$number" -le 999 ] 2>/dev/null || return 1
    printf '%02d\n' "$number"
}

find_boot_request() {
    for request in /boot/node-number /boot/firmware/node-number; do
        if [ -f "$request" ]; then
            printf '%s\n' "$request"
            return 0
        fi
    done
    return 1
}

stage_request() {
    [ "$(id -u)" -eq 0 ] || { echo "Run this command with sudo." >&2; exit 1; }
    node_number=$(normalize_node_number "$1") || {
        echo "Node number must be a whole number from 1 through 999." >&2
        exit 2
    }
    install -d -m 0700 "$PENDING_DIR"
    printf '%s\n' "$node_number" > "$PENDING_FILE"
    chmod 0600 "$PENDING_FILE"
    echo "Node $node_number is staged as ice-and-field-display-$node_number."
    echo "Rebooting now; one additional automatic reboot is expected."
    systemctl reboot
}

prepare_image() {
    [ "$(id -u)" -eq 0 ] || { echo "Run this command with sudo." >&2; exit 1; }
    identity=$(hardware_identity)
    [ -n "$identity" ] || { echo "Could not determine a stable hardware identity for this Orange Pi." >&2; exit 1; }
    install -d -m 0700 "$PENDING_DIR"
    printf '%s\n' "$identity" > "$SOURCE_HARDWARE_FILE"
    chmod 0600 "$SOURCE_HARDWARE_FILE"
    : > "$GOLDEN_MARKER"
    chmod 0600 "$GOLDEN_MARKER"
    sync
    echo
    echo "This SD card is armed as an Ice & Field golden image."
    echo "The original Orange Pi will recognize itself and continue to boot normally."
    echo "Copies booted on other Orange Pis will launch the first-boot setup."
    echo "Powering off now so the card can be imaged safely."
    systemctl poweroff
}

prompt_for_clone_identity() {
    [ -f "$GOLDEN_MARKER" ] || return 1
    source_identity=$(cat "$SOURCE_HARDWARE_FILE" 2>/dev/null || true)
    current_identity=$(hardware_identity)
    if [ -n "$source_identity" ] && [ "$current_identity" = "$source_identity" ]; then
        return 1
    fi

    printf '\033c'
    echo "============================================================"
    echo "          ICE & FIELD DISPLAY — FIRST-BOOT SETUP"
    echo "============================================================"
    echo
    echo "This copied SD card needs a unique display identity."
    while :; do
        printf 'Display number (1-999, for example 04): '
        IFS= read -r requested || requested=
        node_number=$(normalize_node_number "$requested" || true)
        [ -n "$node_number" ] && break
        echo "Please enter a whole number from 1 through 999."
    done
    default_name="Display $node_number"
    printf 'Friendly screen/location name [%s]: ' "$default_name"
    IFS= read -r screen_name || screen_name=
    [ -n "$screen_name" ] || screen_name=$default_name
    screen_name=$(printf '%s' "$screen_name" | cut -c1-80)

    install -d -m 0700 "$PENDING_DIR"
    printf '%s\n' "$node_number" > "$PENDING_FILE"
    printf '%s\n' "$screen_name" > "$SCREEN_NAME_FILE"
    chmod 0600 "$PENDING_FILE" "$SCREEN_NAME_FILE"
    echo
    echo "Configuring ice-and-field-display-$node_number as $screen_name..."
    return 0
}

apply_request() {
    [ "$(id -u)" -eq 0 ] || exit 1
    request_file=
    if [ -f "$PENDING_FILE" ]; then
        request_file=$PENDING_FILE
    else
        request_file=$(find_boot_request || true)
    fi
    [ -n "$request_file" ] || exit 0

    requested=$(sed -n '1{s/[[:space:]]//g;p;q;}' "$request_file")
    node_number=$(normalize_node_number "$requested") || {
        echo "Ignoring invalid node number in $request_file" >&2
        exit 1
    }
    hostname="ice-and-field-display-$node_number"
    screen_name=$(sed -n '1p' "$SCREEN_NAME_FILE" 2>/dev/null || true)
    [ -n "$screen_name" ] || screen_name="Display $node_number"

    install -d -m 0700 "$PENDING_DIR"
    printf '%s\n' "$hostname" > /etc/hostname
    if grep -q '^127\.0\.1\.1[[:space:]]' /etc/hosts 2>/dev/null; then
        sed "s/^127\.0\.1\.1[[:space:]].*/127.0.1.1\t$hostname/" /etc/hosts > "$PENDING_DIR/hosts.new"
        install -m 0644 "$PENDING_DIR/hosts.new" /etc/hosts
        rm -f "$PENDING_DIR/hosts.new"
    else
        printf '127.0.1.1\t%s\n' "$hostname" >> /etc/hosts
    fi
    hostname "$hostname" 2>/dev/null || true

    # A cloned system must not retain machine, SSH, or Tailscale identities.
    : > /etc/machine-id
    rm -f /var/lib/dbus/machine-id
    systemd-machine-id-setup
    rm -f /etc/ssh/ssh_host_*
    ssh-keygen -A
    systemctl stop tailscaled.service 2>/dev/null || true
    rm -f /var/lib/tailscale/tailscaled.state

    # Remove browser process locks copied from a running source system.
    for chromium_dir in /home/*/.config/chromium /home/*/.config/chromium-*; do
        [ -d "$chromium_dir" ] || continue
        rm -f "$chromium_dir"/SingletonCookie "$chromium_dir"/SingletonLock "$chromium_dir"/SingletonSocket
    done

    # Update the controller's friendly local identity while retaining synced media.
    if [ -x "$STATE_HELPER" ]; then
        for state_file in /home/*/.local/share/ice-field-display-controller/state.json; do
            [ -f "$state_file" ] || continue
            owner=$(stat -c '%U:%G' "$state_file")
            "$STATE_HELPER" "$state_file" "$hostname" "$screen_name"
            chown "$owner" "$state_file"
        done
    fi

    printf '%s\n' "$hostname" > "$COMPLETE_FILE"
    chmod 0600 "$COMPLETE_FILE"
    rm -f "$PENDING_FILE" "$SCREEN_NAME_FILE" "$GOLDEN_MARKER" "$SOURCE_HARDWARE_FILE"
    case "$request_file" in
        /boot/node-number|/boot/firmware/node-number) rm -f "$request_file" ;;
    esac
    sync
    echo "Personalized this clone as $hostname; rebooting with its new identity."
    systemctl reboot --force
}

first_boot() {
    if [ -f "$PENDING_FILE" ] || find_boot_request >/dev/null 2>&1; then
        apply_request
        return
    fi
    if prompt_for_clone_identity; then
        apply_request
    fi
}

case "${1:-}" in
    --apply-pending)
        apply_request
        ;;
    --first-boot)
        first_boot
        ;;
    --prepare-image)
        prepare_image
        ;;
    -h|--help|'')
        usage
        ;;
    *)
        [ "$#" -eq 1 ] || { usage >&2; exit 2; }
        stage_request "$1"
        ;;
esac
