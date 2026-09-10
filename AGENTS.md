# Ice & Field WordPress Suite — Project Instructions

This repository is the source project for Sarah's Ice & Field WordPress plugins and Productions Theme. Read `PROJECT-HANDOFF.md` before making changes.

## Sources of truth

- GitHub repository: `https://github.com/sarahspins/wordpress-plugins`
- Release assets: GitHub suite releases and the generated `ice-field-updates.json`
- User documentation: the repository GitHub wiki
- Local working folder: this iCloud-synced directory

The iCloud folder makes the files available across Sarah's Macs, but GitHub `main` is the authoritative shared code history. Never assume the local branch is current merely because iCloud finished syncing.

## Start every development task

1. Read `PROJECT-HANDOFF.md`, the relevant component README/changelog/roadmap, and current Git status.
2. Fetch `origin` and compare the working tree with `origin/main` before editing.
3. Preserve all existing uncommitted work. The working tree may contain changes from another computer or Codex thread.
4. If the checked-out branch predates `origin/main`, build from a clean worktree based on `origin/main`; do not reset or overwrite the shared iCloud working copy.
5. Confirm the component's current published version before choosing the next version.

## Components and dependency order

1. `ice-field-dash-connector`
2. `ice-field-programming`
3. `ice-field-productions`
4. `ice-field-rink-displays`
5. `ice-field-productions-theme`

Programming and Rink Displays require Dash Connector. Productions requires Dash Connector and Programming. The Productions Theme is packaged separately.

## Development and release rules

- Keep plugin header, version constant, README stable tag/version, and changelog aligned.
- Use `apply_patch` for source edits and preserve unrelated changes.
- Package ZIPs with exactly one correctly named component directory at the archive root.
- Exclude `.DS_Store`, AppleDouble `._*`, caches, and development-only files.
- Run `git diff --check`, available syntax checks, and `unzip -t` before handoff.
- Test host-sensitive changes on the main Flywheel website before publishing when practical.
- Do not push, merge, publish a GitHub release, or alter the wiki unless Sarah explicitly requests it.
- Releases use `.github/workflows/publish-release.yml`, which builds all five components and the updater manifest.
- Update the GitHub wiki when behavior, workflows, defaults, troubleshooting, or versions change.

## Continuity requirement

Keep `PROJECT-HANDOFF.md` current as part of the task whenever any of these change:

- Published suite or component versions
- Active development or test ZIPs
- Confirmed decisions or default behavior
- Known blockers, hosting limitations, or Dash API issues
- Tabled/resumed work
- Highest-priority next steps

Write durable facts, not a transcript. Never store credentials, tokens, private API payloads, or customer information in handoff files.

## Product boundaries

- Dash Connector owns DaySmart/Dash credentials, API access, shared automation, event assignment/history, schedule-gap tools, and GitHub updates.
- Programming owns Seasons, Levels, Programs, classifications, imports, registration catalogs, and protected synchronization.
- Productions extends Programming Seasons marked as Productions with show-specific content and workflows.
- Rink Displays owns schedule/calendar/TV/media displays. Participant-display work is tabled unless Sarah explicitly resumes it.
- Productions Theme provides presentation for the dedicated productions website.
- Elementor Slide Scheduler is an optional standalone helper for Elementor Pro Slides and is not yet part of the five-component suite updater.
