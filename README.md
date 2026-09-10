# Ice & Field WordPress

Private monorepo for the actively maintained Ice & Field WordPress plugins and theme.

## Components

- `ice-field-dash-connector` — shared DaySmart/Dash connection and API client
- `ice-field-programming` — Season discovery, synchronization, and programming catalog
- `ice-field-productions` — Production companions, participants, communications, and public Production features
- `ice-field-rink-displays` — rink display and schedule presentation tools
- `ice-field-productions-theme` — Productions website theme
- `ice-field-elementor-slide-scheduler` — optional start/end scheduling for individual Elementor Pro Slides

Generated installation ZIP files and historical local build snapshots are intentionally excluded. Each component keeps its own version history and changelog in its source directory.

## Dependency order

1. Ice & Field Dash Connector
2. Ice & Field Programming
3. Ice & Field Productions

Rink Displays, the Productions Theme, and Elementor Slide Scheduler are maintained in the same repository but are not part of that plugin activation chain. Elementor Slide Scheduler requires Elementor and the Elementor Pro Slides widget.

## Private WordPress updates

Dash Connector provides the shared updater for the five core suite components. The `Publish WordPress release` GitHub Actions workflow builds correctly rooted ZIP packages and an `ice-field-updates.json` manifest, then publishes them as a private GitHub Release. Elementor Slide Scheduler remains outside the suite updater until its first-site compatibility test is complete.

On each WordPress installation, configure a fine-grained GitHub token in **Dash Connector → Settings → Private GitHub Updates**. Restrict the token to this repository with read-only Contents access. WordPress then discovers newer release versions through its normal Plugins and Themes update screens.
