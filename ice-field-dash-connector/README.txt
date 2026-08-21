Ice & Field Dash Connector
Version 1.6.2

Shared Dash/DaySmart connection for Ice & Field WordPress plugins.

1.6.2 adds:
- Hourly automatic assignment and capacity repair from 5:45 AM through 6:45 PM for the current and following month
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
