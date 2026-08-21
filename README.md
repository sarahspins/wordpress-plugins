# Ice & Field WordPress

Private monorepo for the actively maintained Ice & Field WordPress plugins and theme.

## Components

- `ice-field-dash-connector` — shared DaySmart/Dash connection and API client
- `ice-field-programming` — Season discovery, synchronization, and programming catalog
- `ice-field-productions` — Production companions, participants, communications, and public Production features
- `ice-field-rink-displays` — rink display and schedule presentation tools
- `ice-field-productions-theme` — Productions website theme

Generated installation ZIP files and historical local build snapshots are intentionally excluded. Each component keeps its own version history and changelog in its source directory.

## Dependency order

1. Ice & Field Dash Connector
2. Ice & Field Programming
3. Ice & Field Productions

Rink Displays and the Productions Theme are maintained in the same repository but are not part of that plugin activation chain.

## Private WordPress updates

Dash Connector provides the shared updater for all five components. The `Publish WordPress release` GitHub Actions workflow builds correctly rooted ZIP packages and an `ice-field-updates.json` manifest, then publishes them as a private GitHub Release.

On each WordPress installation, configure a fine-grained GitHub token in **Dash Connector → Settings → Private GitHub Updates**. Restrict the token to this repository with read-only Contents access. WordPress then discovers newer release versions through its normal Plugins and Themes update screens.
