#!/bin/sh
set -eu

SOURCE_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
BRAND_DIR="$HOME/.local/share/ice-field-display-controller/branding"
WHITE_LOGO="$SOURCE_DIR/assets/ice-and-field-logo-white.svg"
WALLPAPER="$BRAND_DIR/ice-field-wallpaper.png"
SPLASH="$BRAND_DIR/ice-field-boot-splash.tga"
NAVY="#043c5e"

if [ ! -s "$WHITE_LOGO" ]; then
    echo "The bundled white Ice & Field SVG is missing." >&2
    exit 1
fi

echo "Installing the Raspberry Pi splash support and image tools…"
sudo apt-get update
sudo apt-get install -y imagemagick rpi-splash-screen-support

if command -v magick >/dev/null 2>&1; then
    IMAGE_TOOL=magick
elif command -v convert >/dev/null 2>&1; then
    IMAGE_TOOL=convert
else
    echo "ImageMagick was installed but no image command was found." >&2
    exit 1
fi

mkdir -p "$BRAND_DIR"

echo "Generating the local desktop background…"
"$IMAGE_TOOL" -size 2560x1440 "xc:$NAVY" \
    \( "$WHITE_LOGO" -resize 820x \) \
    -gravity center -composite -strip "$WALLPAPER"

echo "Generating the early-boot image…"
"$IMAGE_TOOL" -size 1920x1080 "xc:$NAVY" \
    \( "$WHITE_LOGO" -resize 720x \) \
    -gravity center -composite -colors 224 -depth 8 -type TrueColor \
    -alpha off -compress none -define tga:bits-per-sample=8 "$SPLASH"

echo "Configuring the early-boot splash…"
sudo configure-splash "$SPLASH"

if command -v pcmanfm >/dev/null 2>&1; then
    echo "Applying the matching desktop background…"
    XDG_RUNTIME_DIR="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}" \
    WAYLAND_DISPLAY="${WAYLAND_DISPLAY:-wayland-0}" \
    DISPLAY="${DISPLAY:-:0}" \
    pcmanfm --wallpaper-mode=fit --set-wallpaper="$WALLPAPER" || \
        echo "The boot splash is installed, but the running desktop did not accept the wallpaper command."
fi

echo
echo "Ice & Field boot and desktop branding installed."
echo "Reboot when ready to verify the complete startup sequence."
