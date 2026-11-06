#!/bin/sh
set -eu

SOURCE_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
BRAND_DIR="$HOME/.local/share/ice-field-display-controller/branding-armbian"
BACKGROUND_DIR="$HOME/.local/share/backgrounds"
WHITE_LOGO="$SOURCE_DIR/assets/ice-and-field-logo-white.svg"
WALLPAPER="$BRAND_DIR/ice-field-wallpaper-1080p.png"
PLYMOUTH_FALLBACK="$BRAND_DIR/bgrt-fallback.png"
PLYMOUTH_WATERMARK="$BRAND_DIR/watermark.png"
THEME_DIR="/usr/share/plymouth/themes/armbian"
THEME_FILE="$THEME_DIR/armbian.plymouth"
NAVY="#043c5e"

if [ ! -s "$WHITE_LOGO" ]; then
    echo "The bundled white Ice & Field SVG is missing." >&2
    exit 1
fi

if [ ! -d "$THEME_DIR" ]; then
    echo "The Armbian Plymouth theme was not found at $THEME_DIR." >&2
    exit 1
fi

echo "Installing the image tools required for local Armbian branding..."
sudo apt-get update
sudo apt-get install -y imagemagick librsvg2-bin plymouth

if command -v magick >/dev/null 2>&1; then
    IMAGE_TOOL=magick
elif command -v convert >/dev/null 2>&1; then
    IMAGE_TOOL=convert
else
    echo "ImageMagick was installed but no image command was found." >&2
    exit 1
fi

mkdir -p "$BRAND_DIR" "$BACKGROUND_DIR"

echo "Generating the 1080p desktop wallpaper..."
rsvg-convert --width 720 --output "$BRAND_DIR/wallpaper-logo.png" "$WHITE_LOGO"
"$IMAGE_TOOL" -size 1920x1080 "xc:$NAVY" \
    "$BRAND_DIR/wallpaper-logo.png" \
    -gravity center -compose over -composite -alpha off -strip "$WALLPAPER"

echo "Generating Plymouth artwork from the official local SVG..."
rsvg-convert --width 230 --output "$BRAND_DIR/plymouth-logo.png" "$WHITE_LOGO"
rsvg-convert --width 720 --output "$BRAND_DIR/plymouth-wordmark.png" "$WHITE_LOGO"
"$IMAGE_TOOL" -size 256x256 xc:none \
    "$BRAND_DIR/plymouth-logo.png" \
    -gravity center -compose over -composite -strip "$PLYMOUTH_FALLBACK"
"$IMAGE_TOOL" "$BRAND_DIR/plymouth-wordmark.png" -strip "$PLYMOUTH_WATERMARK"

if [ "$(identify -format '%wx%h' "$WALLPAPER")" != "1920x1080" ]; then
    echo "Wallpaper validation failed." >&2
    exit 1
fi
if [ "$(identify -format '%wx%h' "$PLYMOUTH_FALLBACK")" != "256x256" ]; then
    echo "Plymouth fallback validation failed." >&2
    exit 1
fi
case "$(identify -format '%wx%h' "$PLYMOUTH_WATERMARK")" in
    720x2[0-9][0-9]) ;;
    *) echo "Plymouth watermark validation failed." >&2; exit 1 ;;
esac

install -m 0644 "$WALLPAPER" "$BACKGROUND_DIR/ice-field-wallpaper.png"
sudo install -m 0644 "$WALLPAPER" /usr/share/backgrounds/ice-field-wallpaper.png

echo "Applying the matching XFCE desktop wallpaper..."
export DISPLAY="${DISPLAY:-:0}"
export XAUTHORITY="${XAUTHORITY:-$HOME/.Xauthority}"
export DBUS_SESSION_BUS_ADDRESS="${DBUS_SESSION_BUS_ADDRESS:-unix:path=/run/user/$(id -u)/bus}"
wallpaper_path="$BACKGROUND_DIR/ice-field-wallpaper.png"
# XFCE image-style 1 is centered. The previous zoomed mode (5) could place
# the artwork unexpectedly while the output changed resolution during boot.
wallpaper_style=1
properties=$(xfconf-query -c xfce4-desktop -l 2>/dev/null | grep '/last-image$' || true)
if [ -n "$properties" ]; then
    printf '%s\n' "$properties" | while IFS= read -r property; do
        [ -n "$property" ] || continue
        xfconf-query -c xfce4-desktop -p "$property" -s "$wallpaper_path"
        style_property=${property%/last-image}/image-style
        xfconf-query -c xfce4-desktop -p "$style_property" -s "$wallpaper_style" 2>/dev/null || true
    done
else
    property="/backdrop/screen0/monitorHDMI-1/workspace0/last-image"
    xfconf-query -c xfce4-desktop -p "$property" -n -t string -s "$wallpaper_path"
    xfconf-query -c xfce4-desktop -p "/backdrop/screen0/monitorHDMI-1/workspace0/image-style" -n -t int -s "$wallpaper_style"
fi
xfdesktop --reload 2>/dev/null || true

echo "Hiding XFCE desktop panels for signage..."
panel_ids=$(xfconf-query -c xfce4-panel -p /panels 2>/dev/null | grep '^[0-9][0-9]*$' || true)
[ -n "$panel_ids" ] || panel_ids=1
printf '%s\n' "$panel_ids" | while IFS= read -r panel_id; do
    [ -n "$panel_id" ] || continue
    property="/panels/panel-$panel_id/autohide-behavior"
    if xfconf-query -c xfce4-panel -p "$property" >/dev/null 2>&1; then
        xfconf-query -c xfce4-panel -p "$property" -s 2
    else
        xfconf-query -c xfce4-panel -p "$property" -n -t int -s 2
    fi
done
xfce4-panel -r 2>/dev/null || true

echo "Backing up and installing the Armbian Plymouth artwork..."
for asset in bgrt-fallback.png watermark.png; do
    if [ -f "$THEME_DIR/$asset" ] && [ ! -f "$THEME_DIR/$asset.ice-field-original" ]; then
        sudo cp "$THEME_DIR/$asset" "$THEME_DIR/$asset.ice-field-original"
    fi
done
if [ -f "$THEME_FILE" ] && [ ! -f "$THEME_FILE.ice-field-original" ]; then
    sudo cp "$THEME_FILE" "$THEME_FILE.ice-field-original"
fi
sudo install -m 0644 "$PLYMOUTH_FALLBACK" "$THEME_DIR/bgrt-fallback.png"
sudo install -m 0644 "$PLYMOUTH_WATERMARK" "$THEME_DIR/watermark.png"
theme_file=$(mktemp)
greeter_file=$(mktemp)
lightdm_file=$(mktemp)
display_script=$(mktemp)
trap 'rm -f "$theme_file" "$greeter_file" "$lightdm_file" "$display_script"' EXIT HUP INT TERM
sed \
    -e 's/^BackgroundStartColor=.*/BackgroundStartColor=0x043c5e/' \
    -e 's/^BackgroundEndColor=.*/BackgroundEndColor=0x043c5e/' \
    "$THEME_FILE" > "$theme_file"
sudo install -m 0644 "$theme_file" "$THEME_FILE"

echo "Applying matching LightDM transition branding..."
printf '%s\n' \
    '[greeter]' \
    'background=/usr/share/backgrounds/ice-field-wallpaper.png' \
    'user-background=false' \
    > "$greeter_file"
printf '%s\n' \
    '[Seat:*]' \
    'display-setup-script=/usr/local/bin/ice-field-display-setup' \
    > "$lightdm_file"
printf '%s\n' \
    '#!/bin/sh' \
    'xsetroot -solid "#043c5e"' \
    > "$display_script"
sudo install -d -m 0755 /etc/lightdm/lightdm-gtk-greeter.conf.d /etc/lightdm/lightdm.conf.d
sudo install -m 0644 "$greeter_file" /etc/lightdm/lightdm-gtk-greeter.conf.d/50-ice-field-branding.conf
sudo install -m 0644 "$lightdm_file" /etc/lightdm/lightdm.conf.d/51-ice-field-branding.conf
sudo install -m 0755 "$display_script" /usr/local/bin/ice-field-display-setup

echo "Rebuilding the early-boot image..."
sudo update-initramfs -u

echo
echo "Ice & Field Armbian boot and desktop branding installed."
echo "Reboot when ready to verify the Plymouth-to-wallpaper transition."
