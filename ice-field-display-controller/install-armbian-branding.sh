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
rsvg-convert --height 44 --output "$BRAND_DIR/plymouth-wordmark.png" "$WHITE_LOGO"
"$IMAGE_TOOL" -size 256x256 xc:none \
    "$BRAND_DIR/plymouth-logo.png" \
    -gravity center -compose over -composite -strip "$PLYMOUTH_FALLBACK"
"$IMAGE_TOOL" -size 333x50 xc:none \
    "$BRAND_DIR/plymouth-wordmark.png" \
    -gravity center -compose over -composite -strip "$PLYMOUTH_WATERMARK"

if [ "$(identify -format '%wx%h' "$WALLPAPER")" != "1920x1080" ]; then
    echo "Wallpaper validation failed." >&2
    exit 1
fi
if [ "$(identify -format '%wx%h' "$PLYMOUTH_FALLBACK")" != "256x256" ]; then
    echo "Plymouth fallback validation failed." >&2
    exit 1
fi
if [ "$(identify -format '%wx%h' "$PLYMOUTH_WATERMARK")" != "333x50" ]; then
    echo "Plymouth watermark validation failed." >&2
    exit 1
fi

echo "Backing up and installing the Armbian Plymouth artwork..."
for asset in bgrt-fallback.png watermark.png; do
    if [ -f "$THEME_DIR/$asset" ] && [ ! -f "$THEME_DIR/$asset.ice-field-original" ]; then
        sudo cp "$THEME_DIR/$asset" "$THEME_DIR/$asset.ice-field-original"
    fi
done
sudo install -m 0644 "$PLYMOUTH_FALLBACK" "$THEME_DIR/bgrt-fallback.png"
sudo install -m 0644 "$PLYMOUTH_WATERMARK" "$THEME_DIR/watermark.png"

echo "Rebuilding the early-boot image..."
sudo plymouth-set-default-theme armbian
sudo update-initramfs -u

install -m 0644 "$WALLPAPER" "$BACKGROUND_DIR/ice-field-wallpaper.png"

echo "Applying the matching XFCE desktop wallpaper..."
export DISPLAY="${DISPLAY:-:0}"
export XAUTHORITY="${XAUTHORITY:-$HOME/.Xauthority}"
export DBUS_SESSION_BUS_ADDRESS="${DBUS_SESSION_BUS_ADDRESS:-unix:path=/run/user/$(id -u)/bus}"
wallpaper_path="$BACKGROUND_DIR/ice-field-wallpaper.png"
properties=$(xfconf-query -c xfce4-desktop -l 2>/dev/null | grep '/last-image$' || true)
if [ -n "$properties" ]; then
    printf '%s\n' "$properties" | while IFS= read -r property; do
        [ -n "$property" ] || continue
        xfconf-query -c xfce4-desktop -p "$property" -s "$wallpaper_path"
        style_property=${property%/last-image}/image-style
        xfconf-query -c xfce4-desktop -p "$style_property" -s 5 2>/dev/null || true
    done
else
    property="/backdrop/screen0/monitorHDMI-1/workspace0/last-image"
    xfconf-query -c xfce4-desktop -p "$property" -n -t string -s "$wallpaper_path"
    xfconf-query -c xfce4-desktop -p "/backdrop/screen0/monitorHDMI-1/workspace0/image-style" -n -t int -s 5
fi
xfdesktop --reload 2>/dev/null || true

echo
echo "Ice & Field Armbian boot and desktop branding installed."
echo "Reboot when ready to verify the Plymouth-to-wallpaper transition."
