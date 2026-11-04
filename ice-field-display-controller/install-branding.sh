#!/bin/sh
set -eu

SOURCE_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
BRAND_DIR="$HOME/.local/share/ice-field-display-controller/branding"
WHITE_LOGO="$SOURCE_DIR/assets/ice-and-field-logo-white.svg"
WALLPAPER="$BRAND_DIR/ice-field-wallpaper.png"
SPLASH="$BRAND_DIR/ice-field-boot-splash.tga"
WALLPAPER_LOGO="$BRAND_DIR/ice-field-logo-wallpaper.png"
SPLASH_LOGO="$BRAND_DIR/ice-field-logo-splash.png"
NAVY="#043c5e"

if [ ! -s "$WHITE_LOGO" ]; then
    echo "The bundled white Ice & Field SVG is missing." >&2
    exit 1
fi

echo "Installing the Raspberry Pi splash support and image tools…"
sudo apt-get update
sudo apt-get install -y imagemagick librsvg2-bin rpi-splash-screen-support

if command -v magick >/dev/null 2>&1; then
    IMAGE_TOOL=magick
elif command -v convert >/dev/null 2>&1; then
    IMAGE_TOOL=convert
else
    echo "ImageMagick was installed but no image command was found." >&2
    exit 1
fi

mkdir -p "$BRAND_DIR"

echo "Rendering the official SVG without changing its proportions…"
rsvg-convert --width 820 --output "$WALLPAPER_LOGO" "$WHITE_LOGO"
rsvg-convert --width 720 --output "$SPLASH_LOGO" "$WHITE_LOGO"

WALLPAPER_LOGO_SIZE=$(identify -format '%wx%h' "$WALLPAPER_LOGO")
SPLASH_LOGO_SIZE=$(identify -format '%wx%h' "$SPLASH_LOGO")
case "$WALLPAPER_LOGO_SIZE" in
    820x2[0-9][0-9]) ;;
    *) echo "Wallpaper logo validation failed: received $WALLPAPER_LOGO_SIZE." >&2; exit 1 ;;
esac
case "$SPLASH_LOGO_SIZE" in
    720x2[0-9][0-9]) ;;
    *) echo "Splash logo validation failed: received $SPLASH_LOGO_SIZE." >&2; exit 1 ;;
esac

echo "Generating the local desktop background…"
"$IMAGE_TOOL" -size 2560x1440 "xc:$NAVY" \
    "$WALLPAPER_LOGO" \
    -gravity center -compose over -composite -alpha off -strip "$WALLPAPER"

echo "Generating the early-boot image…"
"$IMAGE_TOOL" -size 1920x1080 "xc:$NAVY" \
    "$SPLASH_LOGO" \
    -gravity center -compose over -composite -colors 224 -depth 8 -type TrueColor \
    -alpha off -compress none -define tga:bits-per-sample=8 "$SPLASH"

WALLPAPER_SIZE=$(identify -format '%wx%h' "$WALLPAPER")
SPLASH_SIZE=$(identify -format '%wx%h' "$SPLASH")
if [ "$WALLPAPER_SIZE" != "2560x1440" ]; then
    echo "Wallpaper validation failed: expected 2560x1440, received $WALLPAPER_SIZE." >&2
    exit 1
fi
if [ "$SPLASH_SIZE" != "1920x1080" ]; then
    echo "Boot splash validation failed: expected 1920x1080, received $SPLASH_SIZE." >&2
    exit 1
fi

CORNER=$(identify -format '%[pixel:p{0,0}]' "$WALLPAPER")
CENTRE=$(identify -format '%[pixel:p{1280,720}]' "$WALLPAPER")
if [ "$CORNER" = "$CENTRE" ]; then
    echo "Branding validation failed: the centred logo did not render." >&2
    exit 1
fi

echo "Configuring the early-boot splash…"
sudo configure-splash "$SPLASH"

if command -v pcmanfm >/dev/null 2>&1; then
    echo "Applying the matching desktop background…"
    XDG_RUNTIME_DIR="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}" \
    WAYLAND_DISPLAY="${WAYLAND_DISPLAY:-wayland-0}" \
    DISPLAY="${DISPLAY:-:0}" \
    pcmanfm --wallpaper-mode=center --set-wallpaper="$WALLPAPER" || \
        echo "The boot splash is installed, but the running desktop did not accept the wallpaper command."
fi

echo
echo "Ice & Field boot and desktop branding installed."
echo "Reboot when ready to verify the complete startup sequence."
