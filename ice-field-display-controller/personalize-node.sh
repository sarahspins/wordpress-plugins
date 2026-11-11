#!/bin/sh
set -eu

PENDING_DIR=/var/lib/ice-field-display-controller
PENDING_FILE=$PENDING_DIR/pending-node-number
COMPLETE_FILE=$PENDING_DIR/node-identity

usage() {
    cat <<'EOF'
Usage:
  sudo ice-field-personalize-node NODE_NUMBER
  sudo ice-field-personalize-node --apply-pending

Examples:
  sudo ice-field-personalize-node 3
  sudo ice-field-personalize-node 03

The numbered form stages a unique node identity and reboots. During the next
boot, the early personalization service applies the hostname, regenerates the
Linux machine ID and SSH host keys, clears the cloned Tailscale identity, and
reboots once more. Run `sudo tailscale up` after the node returns.
EOF
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

    printf '%s\n' "$hostname" > "$COMPLETE_FILE"
    chmod 0600 "$COMPLETE_FILE"
    rm -f "$PENDING_FILE"
    case "$request_file" in
        /boot/node-number|/boot/firmware/node-number) rm -f "$request_file" ;;
    esac
    sync
    echo "Personalized this clone as $hostname; rebooting with its new identity."
    systemctl reboot --force
}

case "${1:-}" in
    --apply-pending)
        apply_request
        ;;
    -h|--help|'')
        usage
        ;;
    *)
        [ "$#" -eq 1 ] || { usage >&2; exit 2; }
        stage_request "$1"
        ;;
esac
