# Ice & Field Programming Roadmap

This roadmap keeps Dash discovery, notifications, and automatic changes in separate safety stages. Every stage remains local-first: Dash credentials stay in the shared Connector, exclusions are honored, imports are idempotent, and no remote record is deleted or changed.

## 1.8 — Season Discovery Inbox (Shipped in 1.8.0)

Goal: make new and existing Dash Seasons easy to find, classify, import, and refresh without introducing scheduled automation.

### Default Season list

- Load the discovery list automatically when the admin screen opens, using the Connector cache unless an administrator explicitly requests a fresh refresh.
- Show every non-excluded Current and Upcoming Dash Season directly in a discovery list.
- Mark each Season as New, Imported, or Needs Re-sync.
- Show the linked WordPress Season and last successful sync time for imported Seasons.
- Provide a primary action appropriate to the state:
  - Preview / Import for a new Season.
  - Sync for an imported Season.
  - Review Changes when Dash facts differ from the last imported snapshot.
- Retain the existing protected preview before any first import.

### All Seasons view

- Provide an All Seasons dropdown or secondary view for historical and distant-future Seasons.
- Include filters for Current, Upcoming, Completed, Imported, New, Changed, and Excluded.
- Keep Current and Upcoming Seasons visible without requiring the dropdown.

### Exclusions

- Add a Never Import This action keyed to the immutable Dash Season ID.
- Allow several visible Seasons to be selected and excluded together.
- Hide excluded Seasons from the default discovery list and future suggestions.
- Keep excluded Seasons available in the All / Excluded view with a Restore action.
- Never infer an exclusion merely because a Season was skipped once.

### Import and sync indicators

- Match imports by Dash Season ID rather than title.
- Show WordPress publication state, lifecycle state, last sync, and whether protected local fields exist.
- Keep Sync idempotent and preserve local titles, descriptions, taxonomies, ordering, groups, links, and explicit overrides.
- Record a compact comparison snapshot so the dashboard can identify meaningful Dash changes.

## 1.9 — Guarded Monitoring and Notifications

Goal: detect the next registration cycle and routine class changes automatically, while leaving import approval with an administrator.

### 1.9.1 — Monitoring Foundation (Shipped)

- Added global pause/readiness, planned frequency, discovery-signal, future notification-address, and activity-retention settings.
- Added configurable Season families with independent pause switches and plain-language Season-name matching phrases.
- Added a bounded local audit log for monitoring configuration, manual discovery refreshes, exclusions/restorations, protected syncs, warnings, and failures.
- Deliberately left scheduled Dash requests, email, and automatic importing disabled.

### 1.9.2 — Imported Season Daily Sync (Shipped)

- Added explicit opt-in automation to each already-imported Season.
- Run its protected import once daily only while the Dash registration window is open.
- Import newly added eligible class offerings and refresh Dash-controlled facts without replacing protected local presentation.
- Keep automatic names and descriptions behind separate, default-off per-Season choices.
- Add overlap locking, activity logging, and last-check visibility.
- Leave automatic discovery and importing of entirely new Seasons for a later stage.

### 1.9.3 — Review Notifications

- Email the configured administrator only for a genuinely new or newly changed actionable result.
- Deduplicate unchanged notifications and link to the protected admin review workflow.

### Discovery signals

- Treat the closing of a current Learn to Skate registration window as a signal to look for its successor.
- Run periodic low-frequency discovery so Seasons created early or late are still found.
- Refresh imported Current and Upcoming Seasons periodically to detect added, removed, or changed Teams/classes.
- Use bounded retries and backoff when Dash or WordPress is temporarily unavailable.

### Guardrails

- Configure monitoring by Season family, such as Learn to Skate, Learn to Play, or adult leagues.
- Require a stable Dash Season ID and reject duplicate imports.
- Respect Never Import exclusions before notifying or importing.
- Validate required relationships and source fields before presenting an import as ready.
- Never delete, unpublish, or overwrite protected local presentation automatically.
- Keep an audit log of discovery, comparison, notification, approval, import, and failure events.
- Allow scheduled monitoring to be paused globally and per Season family.

### Email notification

- Email the configured administrator when a genuinely new Season is detected.
- Include the Season name, dates, registration window, detected Levels/classes, and any validation warnings.
- Link to a nonce-protected WordPress admin review page with the Dash Season preselected.
- The email link opens the review workflow; the administrator still confirms the import and publication choice.
- Deduplicate notifications so an unchanged Season does not generate repeated messages.

## 2.0 — Opt-In Automatic Import and Announcements

Goal: promote a proven monitored workflow into controlled, policy-based automation.

### Automatic import

- Allow automatic import only for explicitly enabled Season families.
- Apply per-family policy for draft versus immediate publication.
- Require all 1.9 validation checks to pass before importing.
- Continue honoring exclusions, local overrides, routing rules, and duplicate protection.
- Stop safely and request review when the source hierarchy is incomplete or materially different from expectations.

### Announcement email

- Email a concise completion report after an automatic import.
- Include created and updated Seasons, Levels, and Programs; publication state; warnings; and direct admin links.
- Send a clearly different failure / needs-review message when automatic import is withheld.

### Release threshold

Version 2.0 should be an announcement of trusted automatic importing, not merely scheduled polling. The 1.8 discovery inbox and 1.9 monitoring/notification history should be stable before any Season family is allowed to auto-import.

## Persistent Season Routing

Some Dash relationships are intentionally different from the website presentation hierarchy.

- Dash League #86 (Adult Development Camp / drop-in) follows only the published Current Learn to Play Season.
- It never moves into an Upcoming Learn to Play Season; its registration link already exposes future drop-in dates.
- If no Current Learn to Play Season is temporarily available, sync preserves its last local Season assignment instead of restoring the unrelated Dash parent.
- If it has never been imported and no published Current Learn to Play Season exists, import pauses instead of assigning the Level to the wrong Season.
- When the next Learn to Play Season becomes Current, the next sync moves Level #86 and its linked Programs together.
- Future special cases should use explicit, auditable routing rules rather than title-only one-off edits.
