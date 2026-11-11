# Ice & Field Display Controller

Self-contained Raspberry Pi display management for Ice & Field. One physical Pi is a **node** and may drive up to two independently assigned HDMI **screens**. The first Pi can also host the controller.

## Phase 1 capabilities

- Detects connected Wayland/HDMI outputs with `wlr-randr`.
- Assigns a web page, image, video, or standby screen to each output.
- Uses the locally bundled official vector Ice & Field logos, preferring the white SVG on the branded standby screen with a PNG fallback and no website dependency.
- Stores assignments locally and restores them after a reboot.
- Runs one isolated Chromium kiosk instance per connected output.
- Waits for the local Controller, paints a fully local branded startup page, and only then transitions to the assigned display so Chromium never needs to expose a prolonged white loading screen.
- Uses Chromium's local basic password store for kiosk profiles so desktop keyring prompts cannot block unattended startup.
- Provides a local, beginner-friendly control panel.
- Provides a local media library for MP4, WebM, PNG, JPEG, WebP, and SVG files, including upload progress, previews, storage reporting, content validation, and safe removal.
- Accepts multiple media files in one selection and uploads them sequentially with combined progress, avoiding a burst of simultaneous transfers on lower-memory nodes.
- Builds locally stored image playlists for named channels with a configurable hold time and a continuous, approximately 0.3-second right-to-left Ice & Field four-color wipe between slides. The image swaps at the exact midpoint without pausing the bands. Band widths and the navy, blue, orange, and gold order vary with every transition.
- Each image's Media Library card contains its optional local start date/time and stop date/time. Blank starts display immediately; blank stops do not expire. Saving a media schedule refreshes every playlist using that image, and screens recalculate eligible slides during playback without requiring a manual refresh.
- Assigns saved media to an individual screen or every connected screen without depending on the WordPress host.
- Uses editable location-based screen names such as **Lobby 1** and **Warm Room 1**, while retaining the HDMI hardware port only as a diagnostic reference.
- Defines reusable **Schedule**, **Pricing**, **Public Skating Rules**, and **Video for Screens** content channels. Each channel may use a web page or locally stored media, and updating it automatically refreshes every screen assigned to that channel.
- Clips browser scrollbars outside the visible edge of full-screen web displays so embedded schedule pages remain clean on TVs.
- Hides the mouse cursor on signage pages by default and treats embedded websites as non-interactive display content.
- Provides a discreet top-right quick-switch menu on every display for Schedule, Pricing, Public Skating Rules, Video for Screens, and the full Controller.
- Opens and navigates the quick-switch menu from common remote-control Menu/Context Menu keys, with Up/Down/Enter/Escape keyboard support and a right-click fallback.
- Keeps controller and node concepts separate so fleet synchronization can be added without changing screen assignments.

Remote multi-user access and additional-node enrollment are intentionally deferred until local kiosk behavior has been verified on the Raspberry Pi.

## Install on Raspberry Pi OS Desktop

Copy this directory to the Pi, open a terminal inside it, and run:

```bash
./install.sh
```

The installer uses only software already present in a standard Raspberry Pi OS Desktop installation. It installs two user services:

- `ice-field-display-controller.service` — local control panel and display pages
- `ice-field-display-agent.service` — HDMI discovery and Chromium kiosk supervision

The control panel is available locally at `http://127.0.0.1:8787/`. It is intentionally not exposed to the network until authenticated remote access is implemented.

## Install on an Orange Pi Zero 3 with Armbian XFCE

Use the Ubuntu XFCE image built specifically for **Orange Pi Zero 3**. From this directory run:

```bash
./install-armbian.sh
```

The Armbian installer adds Chromium, X11 display discovery, and the small `xdotool` cursor helper before running the normal user-service installer. It also configures LightDM to sign the current display user into XFCE automatically after reboot; SSH and administrator commands still require the account password. Both services receive the local X11 authorization path so screen discovery and kiosk startup do not depend on manually exporting the desktop environment. The same controller and agent support Armbian/XFCE's `HDMI-1` naming while retaining the Raspberry Pi OS Wayland path. Chromium uses reduced background services suitable for a 1 GB single-screen test node; active content still determines the final memory requirement.

Armbian nodes use a balanced Chromium profile that retains GPU compositing and hardware video decoding but disables Vulkan. The installer protects 1 GB nodes from 4K-TV framebuffer allocation failures by adding a 1920×1080 kernel boot override and an Xorg 1080p preferred mode; the original `armbianEnv.txt` is retained once with an `.ice-field-backup` suffix. The Armbian service waits 15 seconds after the user service starts so X11 and the graphics driver can settle before Chromium launches. Connected X11 outputs are then set to the hardware-tested 1920×1080 at 30 Hz profile. If a TV rejects exactly 30 Hz, the agent retries 1080p at a supported refresh rate; a mode-setting failure is logged but no longer prevents Chromium from launching. Screen blanking and DPMS are disabled, and the installer's user-level autostart override prevents Light Locker from covering unattended signage. Browser output is retained under `~/.local/state/ice-field-display-controller/chromium-HDMI-1.log` (with the actual output name substituted). For recovery work, `./install-armbian.sh --safe` installs every update while leaving the display agent disabled; start it once for testing with `systemctl --user start ice-field-display-agent.service`, and enable it at boot only after the hardware proves stable.

Uploaded media is streamed directly to local storage rather than held in memory. Completed uploads are committed atomically, playback supports browser range requests, and an interrupted or invalid upload cannot replace the content currently shown on a screen. The controller preserves at least 1 GB of free storage and refuses to remove a file while a screen is using it.

## Reliable cursor hiding on Raspberry Pi OS Trixie

CSS cursor hiding is retained, but Chromium can cause Labwc/Wayland to redraw the hardware pointer after a channel change. Install the compositor-level support once:

```bash
./install-cursor-support.sh
```

This separate installer uses `sudo` only to install Debian's `wtype` package when needed. It backs up the user's Labwc configuration, adds a dedicated hide-and-park shortcut, discovers and reloads the active Labwc session even when installation runs through a remote shell, immediately hides the pointer, and restarts the display agent. The agent invokes the shortcut after kiosk startup and every controller revision. Moving a physical mouse can still reveal the pointer temporarily; the next display change hides it again.

## Optional boot and desktop branding

After the normal controller installation is working, run:

```bash
./install-branding.sh
```

This intentionally separate installer makes system-level changes with `sudo`. It installs Raspberry Pi's supported early-splash package and ImageMagick, generates a compliant 1920×1080 uncompressed TGA from the bundled white SVG, configures it as the early boot image, and applies a matching 2560×1440 local desktop background. The consistent navy background reduces the visual transition between firmware startup, desktop login, and the Chromium kiosk.

The installer uses the dedicated librsvg renderer to preserve the official SVG viewBox and transparency before compositing. It validates the rendered logo proportions, validates both finished canvas dimensions, and confirms that logo pixels differ from the navy background before applying either image. The finished wallpaper is centred rather than scaled by the desktop manager. These checks prevent white rectangles and distorted logos from replacing the desktop.

The generated files remain in `~/.local/share/ice-field-display-controller/branding/`. Re-running the installer safely regenerates and reapplies them after a logo or design change.

### Armbian branding

On an Armbian/XFCE display node, run this separate installer instead:

```bash
./install-armbian-branding.sh
```

It uses the same bundled white SVG to create a navy 1920×1080 XFCE wallpaper and a large, proportionate 720-pixel-wide logo for Armbian's native-size Plymouth watermark. The Plymouth background, LightDM root background, LightDM greeter, and XFCE desktop all use matching Ice & Field navy branding to minimize visible transitions. XFCE is explicitly set to center the finished wallpaper on every configured monitor and workspace, and every configured panel is set to always hide for a clean recovery desktop. Wallpaper changes occur before the initramfs rebuild so a boot-image problem cannot block desktop branding. Original Plymouth image and theme assets are retained once with an `.ice-field-original` suffix. It changes the visible Armbian Plymouth stage; an earlier board-firmware logo, if present on another model, remains a separate bootloader concern.

## Service controls

```bash
systemctl --user status ice-field-display-controller.service
systemctl --user status ice-field-display-agent.service
systemctl --user restart ice-field-display-agent.service
journalctl --user -u ice-field-display-agent.service -f
```

## Development

```bash
python3 -m unittest discover -s tests
```

