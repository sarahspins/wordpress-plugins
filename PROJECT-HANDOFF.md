# Project Handoff

Last updated: August 31, 2026

## Current published release

GitHub release: `suite-13`

| Component | Published version |
| --- | ---: |
| Dash Connector | 1.7.17 |
| Programming | 1.9.2.4 |
| Productions | 3.7.1 |
| Rink Displays | 2.7.13 |
| Productions Theme | 1.6.7 |

Suite 13 is published from commit `1addfb7b99b7114981a69022f9e6c2a3eeea9cd8`. The release contains all five ZIPs and `ice-field-updates.json`. The GitHub wiki was updated with the suite.

## Current state

- Dash Connector 1.7.17 includes guarded event reassignment, manual and automatic history/Undo, event-type repair, bounded seven-day event searches, Public Skating protections, preserved nonzero Public Skating capacities, automatic-update email summaries, completed-month visibility cleanup, schedule-gap reports, and GitHub updating.
- Programming 1.9.2.4 includes lightweight Season discovery, per-Season Check Changes, protected manual and daily synchronization, classifications, Learn to Play recurring drop-in Team handling, public lifecycle/order fixes, and staged AJAX preparation for Preview, Check Changes, and Import.
- Productions 3.7.1 and Productions Theme 1.6.7 are stable and published.
- Rink Displays 2.7.13 includes scheduled media, TV schedule logic, atomic static schedule JSON, bounded Dash queries, slower screen polling, clock-based Now/Up Next recalculation, and expired-row cleanup.
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

- Observe Programming 1.9.2.4 on the Flywheel site and capture which AJAX stage fails if another gateway error occurs.
- Continue ordinary plugin enhancements as requested; there is no currently approved unpublished release.
- Keep component README/changelog/roadmap files and the GitHub wiki synchronized with behavior changes.

## Moving to another computer

1. Allow iCloud Drive to finish downloading this folder completely.
2. Open this exact folder as the Codex workspace.
3. Ask: “Read AGENTS.md and PROJECT-HANDOFF.md, then inspect GitHub and the working tree before continuing.”
4. Run `./project-status.command` or double-click it in Finder for a quick local report.
5. If GitHub authentication is missing, authenticate Git/GitHub before attempting a push or release.
6. Never copy tokens into this folder. Authentication stays in that Mac's Keychain or GitHub CLI configuration.
