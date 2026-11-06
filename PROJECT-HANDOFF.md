# Project Handoff

Last updated: October 23, 2026

## Active security incident — main website

- September 24: anonymous `iceandfield.com` homepage HTML contains a malicious encoded JavaScript loader targeting Windows visitors. Sarah supplied a matching fake Cloudflare/PowerShell verification screenshot; execution on a visitor PC is uncertain.
- The installed must-use file `/www/wp-content/mu-plugins/wp-fixplugin.php` is strongly implicated. Database option `wp_fixplugin_payload` matches the homepage loader exactly by SHA-256. The public REST index advertises `wp-fixplugin/v1` update/remove/status routes, with payload/contract/encryption-key parameters on the update route. No control route was invoked.
- No matching injection indicators were found in the inspected checked-in suite or searched Git history. This does not rule out vulnerabilities or changes to deployed files. Wordfence and Heartbeat Control main files showed no visible injected loader; neither has been established as the intrusion route.
- PHP contents of the must-use file and initial entry remain unverified. Wordfence's viewer looks under `/wordpress`, while this file is under `/www`; Sarah does not have Flywheel access. Hosting/design team must preserve and inspect the file and logs, quarantine confirmed malware, and purge caches. Investigation notes and inert text evidence are under `output/security/2026-09-24/` locally.
- A fresh Wordfence scan was started; no repair, deletion, deactivation, or database change was performed. Do not blindly repair managed-host core changes: the inspected `wp-settings.php` difference adds a Flywheel configuration include.
- Dash Connector 1.7.23 was recovered from the production-tested ZIP in `dist/`, compared file-for-file with 1.7.22, incorporated into the repository, and published in Release 1.0. It adds available DaySmart creator and last-editor details to automatic-update email event lines, while retaining the event date/time when audit metadata is unavailable. Sarah confirmed the production build has been working reliably.

## Current published release

GitHub release: `release-1.2`

| Component | Published version |
| --- | ---: |
| Dash Connector | 1.7.25 |
| Programming | 1.9.2.8 |
| Productions | 3.7.1 |
| Rink Displays | 2.8.23 |
| Productions Theme | 1.6.8 |

Release 1.2 publishes the tested Rink Displays 2.8.23 public-calendar cache fix alongside the unchanged Dash Connector 1.7.25, Programming 1.9.2.8, Productions 3.7.1, and Productions Theme 1.6.8 packages. Elementor Slide Scheduler remains outside the combined updater pending compatibility testing.

## Current state

- Raspberry Pi display-controller development has started as a standalone project under `ice-field-display-controller/`. Phase 1 treats each physical Pi as a Node with up to two independently assigned HDMI Screens. The first Pi can act as both Controller and Node 1. The initial build provides local-only management, Wayland HDMI discovery, persistent web/image/video/standby assignments, isolated Chromium kiosk profiles per output, and user-service recovery after reboot. Remote multi-user access and additional-node enrollment remain the next phase and must use authenticated private access rather than exposing the local controller directly to the public internet.
- Display Controller Phase 2 local-media work adds streamed local uploads with progress, previews, signature validation, storage reporting, safe removal, and HTTP range playback for MP4/WebM/PNG/JPEG/WebP/SVG. Saved media can be selected for one screen or applied to all connected screens. User-facing location names such as Lobby 1 or Warm Room 1 are primary; immutable HDMI port names remain visible only as diagnostic references.
- Display Controller named content channels mirror the established WordPress display concepts: Schedule, Pricing, Public Skating Rules, and Video for Screens. Channels can point to a web page or local media; physical screens bind to a channel so one content change propagates to every assigned screen.
- Full-screen web channels are rendered in a clipped oversize frame so a remote page's right-side browser scrollbar stays beyond the visible TV edge.
- Signage pages hide the cursor by default. Embedded website frames are intentionally non-interactive so cross-origin pages cannot reintroduce a visible pointer.
- Raspberry Pi OS Trixie/Labwc may redraw its compositor-owned pointer after a Chromium channel reload despite CSS suppression. `install-cursor-support.sh` installs `wtype`, safely adds a backed-up Labwc HideCursor/WarpCursor shortcut, and lets the agent hide and park the pointer after startup and every controller revision.
- Cursor support was hardware-tested successfully on Node 1 after reloading Labwc with its required `LABWC_PID` and Wayland environment. The installer now discovers that active session automatically, including when invoked from Raspberry Pi Connect/Tailscale remote shells.
- The discreet top-right display control expands into a quick-switch menu for all four named content channels plus the full Controller. The cursor remains hidden during normal signage and becomes usable over the control.
- The quick-switch menu supports common remote Menu/Context Menu events plus Up/Down/Enter/Escape navigation; right-click also toggles it. Some remotes may emit a vendor-specific key code that requires one follow-up mapping after hardware testing.
- Display Controller local static-image playlists are in testing. The media picker accepts multiple files and uploads them sequentially with combined progress. Named channels can play 2–100 locally saved images in naturally sorted filename order with a configurable 2–120 second hold. Each image's Media Library card owns its optional local start and stop date/times; blank starts are immediate, blank stops do not expire, and saving a media schedule refreshes all playlists using it. Eligibility is recalculated during playback. The continuous approximately 0.3-second Canva-style wipe moves right-to-left and randomizes both band widths and the order of the confirmed Ice & Field palette—navy `#003b5c`, blue `#33b6ff`, orange `#ff8033`, and gold `#ffc600`—for every slide change. The image swaps at the exact midpoint of one uninterrupted animation, avoiding a visible pause while remaining lightweight enough for 1 GB nodes.
- The first test device is a Raspberry Pi 5 with 4 GB RAM, Raspberry Pi OS Desktop (Debian Trixie), Python 3.13, Chromium 156, and Raspberry Pi Connect. Its current test monitor is detected as `HDMI-A-1` at 2560×1440. The hardware naming decision is Node 1 / Screen 1 and Node 1 / Screen 2; friendly location names will be used in everyday controls.
- The first Orange Pi Zero 3 test node is the 1 GB model, running Armbian Ubuntu XFCE on X11. It is enrolled in the Ice & Field Tailscale network as `ice-and-field-display-02`; its local X11 output is `HDMI-1` at 2560×1440. Baseline memory after desktop startup is approximately 552 MiB used with 417 MiB available and 484 MiB swap. Commit `c475e9a` adds an Armbian bootstrap, X11/xrandr output detection, X11 cursor parking, and reduced-background Chromium flags without changing the Raspberry Pi Wayland path. Hardware installation testing is next.
- The 1 GB Orange Pi node later reproduced a white XFCE screen because Chromium exited, followed by a full node lockup when Chromium was launched manually with its normal GPU path. Memory readings did not show an OOM condition, and Light Locker plus discarded browser output complicated the initial diagnosis. Armbian installs preserve per-output Chromium logs under `~/.local/state/ice-field-display-controller/` and support `./install-armbian.sh --safe` so recovery updates leave the kiosk disabled until explicitly tested. Fully disabling GPU compositing and Skia was rejected because Chromium 156 stayed alive without mapping a visible window; disabling the GPU did render reliably but made both CSS wipes and baked-in video transitions visibly uneven. Chromium logs then identified contiguous graphics-memory allocation failures at the monitor's native 2560×1440 mode. The confirmed profile restores GPU compositing and hardware video decoding, keeps Vulkan disabled, waits 15 seconds for the X11/graphics session to settle before launch (five seconds remained insufficient on reboot), sets connected Armbian X11 outputs to 1920×1080 at 30 Hz before kiosk launch, disables X11 blanking/DPMS, and suppresses Light Locker through a per-user autostart override. Both video and slideshow transitions improved under this profile. Raspberry Pi launch behavior is unchanged.
- Raspberry Pi branding is maintained locally through `ice-field-display-controller/install-branding.sh`. It uses the official bundled white SVG to generate Raspberry Pi OS Trixie's supported 1920×1080 early-boot TGA plus a matching 2560×1440 desktop background, reducing visible Raspberry Pi/desktop transitions before kiosk startup. The branding installer is separate because it deliberately installs system packages and invokes the supported `configure-splash` tool with administrator privileges.
- Armbian branding is maintained separately through `ice-field-display-controller/install-armbian-branding.sh`. The two-step Plymouth theme renders its watermark at native size, so the installer now supplies a proportionate 720-pixel-wide Ice & Field logo rather than the initially tested 333×50 canvas. It also aligns the Plymouth background, LightDM root/greeter backgrounds, and XFCE wallpaper to Ice & Field navy, explicitly centers the wallpaper on every configured XFCE monitor/workspace, sets all configured XFCE panels to always hide, preserves the original Plymouth images and theme once, and rebuilds initramfs without relying on Debian's absent `plymouth-set-default-theme` helper. Wallpaper and desktop-setting changes occur before the boot-image rebuild so a Plymouth failure cannot block desktop branding. This targets the only branded boot stage observed on the Orange Pi test node; earlier board-firmware artwork remains outside its scope.

- Dash Connector 1.7.25 is published in Release 1.1. It includes the 1.7.24 no-op automatic-update fix and adds optional day-of-week plus exact start-time filters to manual Event Assignment searches. This supports precise date-range repairs such as Friday 6:00 AM Private Hockey Coaches Ice without changing unrelated sessions.
- Rink Displays 2.8.22 is published in Release 1.1. Public calendar cache timestamps include weekday, date, and time. Administrators can mirror Dash blackout dates under Calendar Closure Dates using `YYYY-MM-DD|Label`; Day and Week views render those dates as full-day closure blocks instead of blank calendars. Matching Gold/Silver events with identical date, time, and title are combined into one facility-wide closure notice when the title contains `closing`, `closed`, or `late`. Dash exposes blackout management through its legacy browser-session admin screen, but no supported blackout resource was found in the documented JSON API used by WordPress.
- Rink Displays 2.8.23 is published in Release 1.2 after production testing. It fixes a logged-in/public cache split in the weekly calendar: static JSON reads omit WordPress cookies and add a unique request version so browser or host-level anonymous static-file caches cannot retain an older file after an administrator refresh. The read-only anonymous AJAX fallback also remains usable when a cached page contains an expired WordPress nonce.
- Rink Displays 2.8.25 is in local testing. It includes the 2.8.24 separate `[field_booking_calendar]` monthly availability view, defaulting to Dash resource 3. Public payloads deliberately replace every scheduled event with only its occupied time and “Unavailable”; event names, IDs, customers, teams, and booking relationships are never exposed. Bookings with less than one hour between them merge into one unavailable block, while gaps of exactly one hour or longer remain visible. The shortcode supports `resource_id`, `title`, `subtitle`, and a clamped 60–100 `scale`, plus month navigation, cached/stale fallback data, and an Editor/Administrator Refresh Month control. Version 2.8.25 also makes the Schedule Display's 180px lower banner image or slideshow span the entire screen width using cover sizing inside a subtly rounded, translucent bordered frame. The test ZIP is `dist/ice-field-rink-displays-2.8.25.zip`.
- Sarah confirmed and approved Rink Displays 2.8.20 after testing its scheduled full-screen Schedule Display takeovers. Release 1.0 was published September 30 with Dash Connector 1.7.23, Programming 1.9.2.8, Productions 3.7.1, Rink Displays 2.8.20, and Productions Theme 1.6.8. PHP syntax, timing tests, workflow validation, manifest validation, and all five ZIP integrity checks passed.

- Programming 1.9.2.8 includes per-Team/class and standalone-Level Sport selectors. Existing local Sports are preselected, otherwise mapped Dash Sports are used. Individual values beat bulk Sport; a single bulk Sport can resolve unclassified rows only during manual imports. Daily sync preserves existing classifications and leaves unresolved new offerings draft with Activity Log warnings.

- Programming 1.9.2.8 new-offering publication defaults on for Dash Season names containing Learn to Skate and off elsewhere (including Productions); explicit per-Season choices override defaults. Daily sync remains opt-in and registration-open only. Automatic publication requires published parents and never republishes existing drafts/unpublished offerings. Manual publishing and Production companion visibility are unchanged.
- Rink Displays 2.8.19 adds an asterisk to schedule participant counts and an inline italic disclaimer immediately after Last updated, with no space after the asterisk. Counts, FULL indicators, and refresh timing are unchanged. Participant-display development remains tabled.
- Rink Displays 2.8.20 is published in Release 1.0. It adds scheduled full-screen Schedule Display takeovers with explicit start/end times in the display timezone. A takeover can reuse the current Video for Screens content or use a separate Media Library video; open schedule TVs detect both start and end transitions through the existing minute refresh check and return to the schedule automatically. Editors and administrators can manage these windows from Schedule Display. Overlaps resolve to the active window with the latest start time.
- The maintained Editor PDF guide under `output/pdf/` was rebuilt September 30 for Release 1.0. Its source now includes beginner instructions for scheduled full-screen Schedule Display takeovers, current component versions, and the corrected participant-count guidance. The contents prioritize Video for Screens, Schedule Display media/takeovers, and schedule refresh immediately after sign-in/workspace basics. The US Letter PDF retains the verified 3/4-inch text margins and 1/4-inch colored-area printer clearance.

- Dash Connector 1.7.22 adds administrator-managed Event Assignment Rules, bounded read-only retries, explicit bulk Event Type Correction, string-safe DaySmart event-type IDs and dropdowns, and inclusive Custom Date Range searches up to the existing 92-day safety limit while retaining Whole Month as the default.
- Dash Connector 1.7.23 is published in Release 1.0. It enriches automatic-update email event lines with available DaySmart creator and last-editor names/timestamps, with a safe fallback to the event date/time when DaySmart omits audit metadata.
- Programming 1.9.2.7 includes lightweight Season discovery, protected synchronization, classifications, staged AJAX preparation, Season-filtered Team requests, and cached seven-day Event preparation. Scheduled Events now determine each imported class's displayed first and last dates when available; Team dates remain the fallback and mismatches are shown in the protected preview.
- Productions 3.7.1 is stable and published. Productions Theme 1.6.8 is published; its enqueued CSS and JavaScript use the active theme version for reliable browser/CDN cache busting.
- Rink Displays 2.8.18 is published. It includes the external schedule loader, adaptive Later pagination, stable pinned regions, locker/registration enrichment, atomic static schedule JSON, responsive timing, editor-accessible expiring image slideshows, cache-resistant background schedule reads, hourly 48-hour media-list cleanup with optional attachment trashing, consolidated non-linking schedule-banner controls, and a logged-in Editor/Administrator control to refresh the exact calendar week being viewed.
- The wiki is public and enabled at `https://github.com/sarahspins/wordpress-plugins/wiki`.
- Ice & Field Elementor Slide Scheduler 1.0.0 is an unpublished standalone test plugin in the repository. It adds optional per-slide start and end display times to Elementor Pro's Slides widget and preserves hidden slides in the editor.

## Hosting and API context

- The main website is hosted on Flywheel and has produced intermittent critical errors and gateway errors during large Programming/Dash requests.
- The staging Apache website and the separate shows website have been more reliable.
- Programming now separates expensive protected imports into AJAX stages to reduce per-request PHP memory and gateway exposure.
- Rink Displays now serves shared static schedule snapshots so TV screens normally bypass WordPress/PHP.
- The Dash API uses `leagues` for the records presented as Programming Levels and `teams` for monthly/session offerings.
- Dash blackout dates currently require a local Displays mirror because the legacy Dash admin endpoint is session-bound and no supported equivalent is documented for API-key access. Do not store or scrape a staff Dash browser session in WordPress.
- Do not assume hosting logs are available. Error messages and staged operations should identify the failed resource wherever possible.

## Decisions that should remain stable

- GitHub `main` is the code source of truth; iCloud is cross-computer file availability, not branch synchronization.
- After implementing a plugin or theme change, create and validate a test ZIP by default unless Sarah explicitly says not to package it.
- New combined releases use human-readable decimal versions beginning with `release-1.0`, followed by `release-1.1`, `release-1.2`, and so on. Public titles use “Ice & Field WordPress Release” rather than “Suite.” Component plugins and the theme retain their independent versions. Historical integer tags such as `suite-20` remain unchanged.
- Automatic Connector event checks are disabled by default and should be enabled on only one website.
- Calendar closure dates are maintained in Displays as `YYYY-MM-DD|Label` lines and should mirror Dash Admin → Blackout Dates until DaySmart exposes a supported API-key endpoint.
- Public Skating event type 10 uses capacity 250 only when capacity is zero/missing; nonzero capacity is preserved.
- Protected Public Skating Teams and titles longer than 22 characters are never automatically changed.
- Camps import without redundant Levels and display/sort by actual event dates.
- Programming synchronization preserves local presentation unless an explicit replacement option is selected.
- Production data is layered onto Programming records rather than maintained as an independent competing import hierarchy.
- The schedule participant-count disclaimer is a completed public-facing presentation choice, not evidence of a Dash API delay or an open software defect. Known count discrepancies arise from internal registration-processing workflows and should not be listed as a pending API issue.

## Tabled work

Participant-display development is tabled. Do not include or resume per-rink participant lists, check-in indicators, or participant-specific Now/Up Next work unless Sarah explicitly asks.

## Next likely work

- Sarah ordered 1 GB and 4 GB Orange Pi Zero 3 boards as lower-cost single-screen node candidates. The 1 GB board is the active compatibility test; compare it with the 4 GB board against the same one-screen schedule/local-media workload while measuring memory, swap, temperature, Chromium stability, Tailscale access, and video playback. Do not treat either as the master Controller until the Linux image, HDMI discovery, hardware decoding, remote recovery, and unattended reboot behavior are verified.

- Install and verify the Phase 1 Raspberry Pi controller on Node 1: confirm automatic startup, local control-panel access, standby rendering, assignment changes, and recovery after reboot before enabling remote access or enrolling more nodes.
- Design authenticated remote access for Sarah and Kristin, then add Controller-to-Node synchronization, heartbeats, cached media distribution, status reporting, and rollback without making continued playback dependent on the Controller or internet connection.

- Verify Programming 1.9.2.7 after its suite-19 update by re-syncing an affected Season and confirming the Event-derived class range.
- Test Programming 1.9.2.6 Check Changes on the shows website and confirm both the filtered Teams stage and weekly Events stages complete.
- Observe Rink Displays 2.8.13 on the shows website and LG screens, especially slideshow transitions and background registration-total refreshes.
- Keep component README/changelog/roadmap files and the GitHub wiki synchronized with behavior changes.
- Test Elementor Slide Scheduler 1.0.0 against the site's installed Elementor Pro version before adding it to the suite packaging and update system.
- Verify Rink Displays 2.8.18's Refresh Displayed Week control on a future week after its suite-19 update.
- Confirm the WordPress updater offers Release 1.2 on each intended website and spot-check the public weekly calendar anonymously after Displays 2.8.23 installs.
- Test the Rink Displays 2.8.25 field calendar on a private page with `[field_booking_calendar]`; confirm resource 3 is the field, occupied times match Dash, adjacent/overlapping bookings merge sensibly, and no private booking titles appear in the page or browser response. Also confirm the Schedule Display's 180px lower banner reaches both screen edges without unwanted cropping of essential artwork.

## Moving to another computer

1. Allow iCloud Drive to finish downloading this folder completely.
2. Open this exact folder as the Codex workspace.
3. Ask: “Read AGENTS.md and PROJECT-HANDOFF.md, then inspect GitHub and the working tree before continuing.”
4. Run `./project-status.command` or double-click it in Finder for a quick local report.
5. If GitHub authentication is missing, authenticate Git/GitHub before attempting a push or release.
6. Never copy tokens into this folder. Authentication stays in that Mac's Keychain or GitHub CLI configuration.
7. The established Raspberry Pi source checkout is `~/ice-field-display-project`; update it with `cd ~/ice-field-display-project`, `git pull`, `cd ice-field-display-controller`, and `./install.sh`.
