Ice & Field Dash Connector
Version 1.7.17

Shared Dash/DaySmart connection for Ice & Field WordPress plugins.

1.7.17 updates:
- Automatic event-update emails display Team 0 as “Unassigned” and identify assigned Teams as “Name (ID)”

1.7.16 fixes:
- Event searches use smaller Dash API pages and release each weekly response after filtering, preventing memory-limit HTTP 500 errors on 128 MB hosts.

1.7.15 fixes:
- Event Assignment searches no longer use object-valued min(), which could cause an HTTP 500 on some PHP versions.

1.7.14 protects:
- Public Skating capacities are automatically set to 250 only when the existing value is 0 or missing
- Existing nonzero Public Skating capacities are treated as intentional manual adjustments and preserved during Team assignment and name repairs
- The explicit single-event capacity editor remains available for deliberate manual changes

1.7.13 adds:
- A guarded optional Event Type ID update in the single-event assignment workflow
- Event-type preview, stale-data protection, post-write verification, history display, and Undo support
- Reliable name searching performed locally across seven-day Dash event batches so matching events are not omitted by Dash's incomplete description filter

1.7.12 updates:
- Simplifies both schedule-gap email section descriptions to “Openings of 45 minutes or longer:”
- Displays an event-history Team ID of 0 as “Unassigned”

1.7.11 updates:
- Highlights the entire First cut and Second cut rows on-screen and in the ice-cut email
- Shortens staffing-conflict text to “Overlaps Gold Rink” or “Overlaps Silver Rink”

1.7.10 updates:
- Uses the supplied official white Ice & Field at The Crossover logo in both email headers
- Adds subtle 6px rounded corners to the email card, tables, day headings, and empty states
- Includes every schedule gap of 45 minutes or longer in both the urgent two-week and following eight-week sections

1.7.9 adds:
- Separate weekly Ice Cut Schedule and Schedule Gaps emails, each with independent recipients, Monday send time, and send-now action
- Ice & Field branded HTML email design using the public Ice & Field logo and brand colors rather than the host site's configured logo
- Ice-cut email columns ordered as Time, Length, Resource, and Resurfacing, with daily sections and prominent staffing-conflict warnings
- Separate delivery-history labels for ice-cut and schedule-gap reports

1.7.8 fixes:
- Weekly email generation processes Dash events in seven-day chunks instead of retaining the raw ten-week collection in memory
- Prevents the 128 MB PHP memory exhaustion observed on DreamHost when sending the report manually

1.7.7 adds:
- An administrator-only Email History screen covering automatic event-update and weekly ice-schedule messages
- The newest 25 attempts with timestamp, subject, recipients, accepted/failed transport status, errors, and expandable message content

1.7.6 fixes:
- Overlapping resurfacing rows always list First cut before Second cut, including matching start times

1.7.5 adds:
- Shared mail-delivery diagnostics for automatic event updates and the weekly ice schedule
- Per-recipient delivery attempts to avoid mail-provider issues with recipient arrays
- Last-attempt timestamps, accepted-recipient counts, and captured WordPress mail errors on both admin screens

1.7.4 adds:
- An administrator-only Send Weekly Ice Cut Schedule Now button using the saved recipients
- Clear success or failure feedback after an immediate report attempt

1.7.3 adds:
- Scheduled events named “Ice maintenance” to the ice-cut list regardless of their duration
- A distinct Scheduled ice maintenance label on-screen and in the Monday email

1.7.2 adds:
- First/second resurfacing sequencing when Gold and Silver ice-cut windows overlap
- Red “Two operators required” staffing alerts when overlapping 15-minute-only cuts require both resurfacing machines at once
- Flexible-order labels when two 30-minute windows can be serviced sequentially

1.7.1 fixes:
- Forces the day-grouped ice-cut interface to load after sites cached the original 1.7.0 admin assets
- Limits schedule gaps to same-day openings bounded by events on Gold and Silver
- Uses the Rink Display timezone for calculations, screen output, and weekly email output

1.7.0 adds:
- A read-only Schedule Gaps utility for week and month scans limited to Gold and Silver (Dash resource IDs 1 and 2)
- Separate results for true schedule openings (45+ minutes by default) and likely ice cuts (exactly 15 or 30 minutes between events)
- Same-day, between-event gap detection that ignores time before the first event and after the last event
- Correct interval merging for overlapping events plus configurable daily search hours
- Rink Display timezone handling with a Central Time safety fallback, independent of the testing site's WordPress timezone
- Printable results grouped by date and resource
- One optional Monday-morning email to multiple recipients with an urgent section for 45+ minute gaps and likely ice cuts in the next two weeks
- A planning section in the same email for 45+ minute gaps in the following eight weeks

1.6.11 adds:
- Manual Event Assignment changes to the persistent Event Update History
- Verified before-and-after Team, capacity, and event-name values for every successful manual event write
- Manual history attribution to the WordPress user who applied the update
- The same guarded per-event Undo and automation-pause protection used by scheduled changes

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
