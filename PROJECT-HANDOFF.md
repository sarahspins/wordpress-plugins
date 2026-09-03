# Project Handoff

Last updated: September 3, 2026

## Current published release

GitHub release: `suite-17`

| Component | Published version |
| --- | ---: |
| Dash Connector | 1.7.22 |
| Programming | 1.9.2.6 |
| Productions | 3.7.1 |
| Rink Displays | 2.8.13 |
| Productions Theme | 1.6.7 |

Suite 17 contains all five ZIPs and `ice-field-updates.json`. It publishes custom Connector Event Assignment date ranges, bounded Programming Team and Event preparation for host reliability, and Rink Displays image slideshows and background refresh controls. The GitHub wiki update is pending explicit approval.

## Current state

- Dash Connector 1.7.22 adds administrator-managed Event Assignment Rules, bounded read-only retries, explicit bulk Event Type Correction, string-safe DaySmart event-type IDs and dropdowns, and inclusive Custom Date Range searches up to the existing 92-day safety limit while retaining Whole Month as the default.
- Programming 1.9.2.6 includes lightweight Season discovery, protected synchronization, classifications, and staged AJAX preparation. It filters Team requests by Season, reports HTTP status details, and splits scheduled Event preparation into cached seven-day requests to address the reproduced Flywheel/Shows-site timeouts and HTTP 500 failures.
- Productions 3.7.1 and Productions Theme 1.6.7 are stable and published.
- Rink Displays 2.8.13 includes the external schedule loader, adaptive Later pagination, stable pinned regions, locker/registration enrichment, atomic static schedule JSON, responsive timing, and background refreshes. It adds editor-accessible expiring image slideshows for Video for Screens and the schedule banner, configurable image and transition timing, and an admin-side background schedule-data refresh control. Temporary cache-busting parameters are removed from the visible browser URL after use.
- The wiki is public and enabled at `https://github.com/sarahspins/wordpress-plugins/wiki`.

## Hosting and API context

- The main website is hosted on Flywheel and has produced intermittent critical errors and gateway errors during large Programming/Dash requests.
- The staging Apache website and the separate shows website have been more reliable.
- Programming now separates expensive protected imports into AJAX stages to reduce per-request PHP memory and gateway exposure.
- Rink Displays now serves shared static schedule snapshots so TV screens normally bypass WordPress/PHP.
- The Dash API uses `leagues` for the records presented as Programming Levels and `teams` for monthly/session offerings.
- Do not assume hosting logs are available. Error messages and staged operations should identify the failed resource wherever possible.

## Decisions that should remain stable

- GitHub `main` is the code source of truth; iCloud is cross-computer file availability, not branch synchronization.
- Automatic Connector event checks are disabled by default and should be enabled on only one website.
- Public Skating event type 10 uses capacity 250 only when capacity is zero/missing; nonzero capacity is preserved.
- Protected Public Skating Teams and titles longer than 22 characters are never automatically changed.
- Camps import without redundant Levels and display/sort by actual event dates.
- Programming synchronization preserves local presentation unless an explicit replacement option is selected.
- Production data is layered onto Programming records rather than maintained as an independent competing import hierarchy.

## Tabled work

Participant-display development is tabled. Do not include or resume per-rink participant lists, check-in indicators, or participant-specific Now/Up Next work unless Sarah explicitly asks.

## Next likely work

- Test Programming 1.9.2.6 Check Changes on the shows website and confirm both the filtered Teams stage and weekly Events stages complete.
- Observe Rink Displays 2.8.13 on the shows website and LG screens, especially slideshow transitions and background registration-total refreshes.
- Keep component README/changelog/roadmap files and the GitHub wiki synchronized with behavior changes.

## Moving to another computer

1. Allow iCloud Drive to finish downloading this folder completely.
2. Open this exact folder as the Codex workspace.
3. Ask: “Read AGENTS.md and PROJECT-HANDOFF.md, then inspect GitHub and the working tree before continuing.”
4. Run `./project-status.command` or double-click it in Finder for a quick local report.
5. If GitHub authentication is missing, authenticate Git/GitHub before attempting a push or release.
6. Never copy tokens into this folder. Authentication stays in that Mac's Keychain or GitHub CLI configuration.
