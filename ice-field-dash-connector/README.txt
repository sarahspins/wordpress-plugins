Ice & Field Dash Connector
Version 1.6.10

Shared Dash/DaySmart connection for Ice & Field WordPress plugins.

1.6.10 fixes:
- Completed Team cleanup now turns off Dash Online Registration (`online_signup`) as well as making the Team inactive
- Post-update verification requires both settings, preventing inactive Teams from remaining visible as empty Customer Portal cards
- Previously half-cleaned Teams with `inactive` on but Online Registration still enabled are detected and repaired

1.6.9 adds:
- Optional automatic-update summary emails to one or more administrator-selected recipients
- Email delivery only when a scheduled run actually changes one or more events
- A WordPress admin Automatic Update History retaining the newest 500 verified event changes
- Before-and-after Team, capacity, and event-name details for every recorded automatic change
- Guarded per-event Undo that refuses to overwrite later changes and verifies the restored Dash state
- Undo automatically pauses future automation for that event, with a Resume automation control

1.6.8 adds:
- A Completed Monthly Visibility preview for Open Freestyle, Stick & Puck, Private Hockey Coaches Ice, and Public Skating
- An optional cutoff mode that includes the selected completed month and every matching earlier monthly Team
- The standard full-screen progress and completion overlay for bulk Team visibility updates
- Team-only cleanup: completed monthly Teams are made inactive while their Levels and events remain unchanged
- Automatic cleanup of the immediately previous month whenever event-assignment automation is enabled
- Exact unique-destination matching, fresh-state checks, and post-update verification before a Team is considered inactive
- Existing protected and themed Public Skating safeguards also prevent unsafe monthly Team cleanup

1.6.7 adds:
- Public Skating to hourly and Current + Next Month automatic assignment checks
- Strict destination discovery using Level {year} Public Skating and Team {month} {year}
- Exactly one active Level/Team match is required before any Public Skating update can run
- Public Skating assignment, capacity 250, and the standard event name Public Skating are verified while protected-team and 23+ character themed-title exclusions remain in force
- Season names are informational only, so renaming a Public Sessions Season does not affect destination matching
- Event-name standardization remains manual for the other recurring session types

1.6.6 adds:
- Automatic permanent protection for Public Skating event type 10 titles longer than 22 characters
- Themed Public Skating sessions are omitted from monthly previews and single-event searches without requiring future team IDs to be hard-coded
- The same title-length rule is enforced again at update time, alongside the existing protected-team list

1.6.5 adds:
- Public Skating event type 10 capacity standardization at 250
- Public Skating name-only, capacity-only, or combined corrections in the single-event workflow
- Complete removal of Teams #117, #118, #218–221, #328, and #329 from both Public Skating preview paths
- The existing server-side permanent block remains in place as a final safeguard for those special-event teams

1.6.4 adds:
- An optional bulk event-name control for each Standard Monthly Update group
- Previewed old-to-new name changes and a final confirmation summary
- Public Skating defaults to the consistent event name “Public Skating”
- Fresh-name validation and post-update verification alongside the existing assignment and capacity safeguards

1.6.3 adds:
- Manual Public Skating reassignment to the Standard Monthly Update preview for Dash event type 10
- Year-specific destination safeguards for 2026 Level #207 / Teams #564–571 and 2027 Level #208 / Teams #572–577
- Permanent exclusion of Public Skating events assigned to Teams #117, #118, #218–221, #328, and #329
- Capacity preservation for Public Skating and an unchecked-by-default manual confirmation step
- Explicit exclusion of Public Skating from hourly and Current + Next Month automation

1.6.2 adds:
- Hourly automatic assignment and capacity repair from 5:45 AM through 6:45 PM for the current and following month
- A per-site enable switch that is disabled by default, preventing duplicate automation across multiple WordPress websites
- Scheduling based on the rink timezone stored by Rink Displays, with the WordPress timezone as a fallback
- The proven Standard Monthly Update rules for Open Freestyle, Stick & Puck, and Private Hockey Coaches Ice
- Strict unique-destination checks and post-update verification before an automated month is considered successful
- Last-run status and a manual Current + Next Month run control on Event Assignment

1.6.1 adds:
- A dedicated Dash Connector > Check GitHub Releases menu item
- Immediate redirect to WordPress Updates after checking the private release feed
- Clear success or configuration guidance on the Updates screen

1.6.0 adds:
- Private GitHub Release updates for all Ice & Field plugins and the Productions Theme
- One fine-grained, read-only GitHub token stored in Dash Connector
- Authenticated manifest checks and protected downloads through the normal WordPress updater
- A manual Check GitHub releases now control for administrators

1.5.1 adds:
- Administrator-only permanent team deletion from an inspected Object Explorer team record
- Exact typed team-ID confirmation plus a final browser confirmation
- A fresh team lookup plus mandatory empty-roster and schedule-event checks immediately before deletion
- A purpose-built teams-only DELETE client method rather than generic write access
- Post-delete verification and a local audit record of the last 50 successful deletions
- Guidance to enable Registration Delete permission only while it is needed

1.5.0 adds:
- A Standard Monthly Update for Open Freestyle, Stick & Puck, and Private Hockey Coaches Ice
- Automatic monthly destination discovery for all three session types
- Combined assignment, reassignment, and capacity preview counts
- Group-level selection with one confirmation and shared progress overlay
- The existing single-event and capacity-repair workflow remains available for exceptions

1.4.7 added:
- Automatic destination-class selection when a clear best match is found
- Event-name, month, year, sport wording, and active-status match scoring
- A visible Best match label while keeping every alternative selectable

1.4.6 added:
- Current event capacity in the assignment preview
- Optional bulk capacity updates alongside class/team assignment
- Default capacity of 20 for Freestyle and 25 for hockey or Stick & Puck
- Capacity difference labels, confirmation text, and post-update verification

1.4.5 added:
- Dedicated Object Explorer and Event Assignment permissions
- Automatic access for Administrator and Editor roles
- Settings, connection testing, and schema controls remain Administrator-only

1.4.4 added:
- A Stop Working control while assignment is in progress
- Safe stopping after the currently active batch finishes
- The same control becomes Completed when processing has ended

1.4.3 added:
- A full-screen progress lightbox for event assignment
- Live event, batch, and percentage progress during large updates
- A clear completion summary that remains visible until dismissed

1.4.2 added:
- A single month selector in place of separate start and end dates
- Automatic first-through-last-day event searching for the selected month
- Automatic month-and-year text in the destination class search box

1.4.1 added:
- Search results can include events assigned to a different destination class
- Events already assigned to the selected destination are excluded
- Reassignments are clearly labeled and require selection plus confirmation

1.4.0 added:
- A guarded Event Assignment screen for roster-only schedule events
- Date, event-name, and event-type search with an update preview
- Searchable existing class/team destinations with season and level context
- Select/deselect all controls and explicit confirmation before any Dash write
- Protection against accidental or unconfirmed reassignment
- Per-event verification and clear skipped/failed update reporting

1.3.0 added:
- Dash Object Explorer shortcuts for common resources
- Clickable navigation for every relationship exposed by a record
- Featured Team-to-Registered-Customers navigation
- Automatically learned Dash schema reference
- Schema page listing discovered attributes, relationships, and endpoints
- Clear-schema control for restarting discovery

Existing authentication, caching, pagination, diagnostics, raw API access, and public helper functions remain intact.
