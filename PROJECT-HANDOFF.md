# Project Handoff

Last updated: September 17, 2026

## Current published release

GitHub release: `suite-20`

| Component | Published version |
| --- | ---: |
| Dash Connector | 1.7.22 |
| Programming | 1.9.2.8 |
| Productions | 3.7.1 |
| Rink Displays | 2.8.19 |
| Productions Theme | 1.6.8 |

Suite 20 is published from `01273b40bb2110a7f4dc9def2f032e94fd6cd4bf`. All five core ZIPs and `ice-field-updates.json` were verified uploaded. It adds per-class Sport controls, guarded daily publication of new offerings, and the inline participant-count disclaimer. Elementor Slide Scheduler remains outside the suite updater pending compatibility testing.

## Current state

- Sarah confirmed Programming 1.9.2.8 and Rink Displays 2.8.19 stable and approved suite release on September 17. Suite-20 is published with those versions; the other three component versions remain unchanged. PHP syntax, policy tests, whitespace checks, and all five ZIP integrity checks passed. Wiki documentation was updated for both behaviors and current versions.

- Programming 1.9.2.8 includes per-Team/class and standalone-Level Sport selectors. Existing local Sports are preselected, otherwise mapped Dash Sports are used. Individual values beat bulk Sport; a single bulk Sport can resolve unclassified rows only during manual imports. Daily sync preserves existing classifications and leaves unresolved new offerings draft with Activity Log warnings.

- Programming 1.9.2.8 new-offering publication defaults on for Dash Season names containing Learn to Skate and off elsewhere (including Productions); explicit per-Season choices override defaults. Daily sync remains opt-in and registration-open only. Automatic publication requires published parents and never republishes existing drafts/unpublished offerings. Manual publishing and Production companion visibility are unchanged.
- Rink Displays 2.8.19 adds an asterisk to schedule participant counts and an inline italic disclaimer immediately after Last updated, with no space after the asterisk. Counts, FULL indicators, and refresh timing are unchanged. Participant-display development remains tabled.

- Dash Connector 1.7.22 adds administrator-managed Event Assignment Rules, bounded read-only retries, explicit bulk Event Type Correction, string-safe DaySmart event-type IDs and dropdowns, and inclusive Custom Date Range searches up to the existing 92-day safety limit while retaining Whole Month as the default.
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

- Verify Programming 1.9.2.7 after its suite-19 update by re-syncing an affected Season and confirming the Event-derived class range.
- Test Programming 1.9.2.6 Check Changes on the shows website and confirm both the filtered Teams stage and weekly Events stages complete.
- Observe Rink Displays 2.8.13 on the shows website and LG screens, especially slideshow transitions and background registration-total refreshes.
- Keep component README/changelog/roadmap files and the GitHub wiki synchronized with behavior changes.
- Test Elementor Slide Scheduler 1.0.0 against the site's installed Elementor Pro version before adding it to the suite packaging and update system.
- Verify Rink Displays 2.8.18's Refresh Displayed Week control on a future week after its suite-19 update.

## Moving to another computer

1. Allow iCloud Drive to finish downloading this folder completely.
2. Open this exact folder as the Codex workspace.
3. Ask: “Read AGENTS.md and PROJECT-HANDOFF.md, then inspect GitHub and the working tree before continuing.”
4. Run `./project-status.command` or double-click it in Finder for a quick local report.
5. If GitHub authentication is missing, authenticate Git/GitHub before attempting a push or release.
6. Never copy tokens into this folder. Authentication stays in that Mac's Keychain or GitHub CLI configuration.
