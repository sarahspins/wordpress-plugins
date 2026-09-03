#!/bin/zsh

set -u

project_dir="${0:A:h}"
cd "$project_dir" || exit 1

echo "Ice & Field WordPress Project Status"
echo "Folder: $project_dir"
echo ""
echo "Git branch and working tree"
git status --short --branch
if git show-ref --verify --quiet refs/remotes/origin/main; then
  divergence="$(git rev-list --left-right --count HEAD...origin/main 2>/dev/null || true)"
  local_ahead="${divergence%%[[:space:]]*}"
  remote_ahead="${divergence##*[[:space:]]}"
  echo "Compared with last-fetched origin/main: local-only ${local_ahead:-?}, remote-only ${remote_ahead:-?} commits"
fi
echo ""
echo "GitHub remote"
git remote get-url origin 2>/dev/null || echo "No origin remote configured"
echo ""
echo "Recent local commits"
git log --oneline --decorate -5 2>/dev/null || true
echo ""
echo "Component versions"
for plugin_file in \
  ice-field-dash-connector/ice-field-dash-connector.php \
  ice-field-programming/ice-field-programming.php \
  ice-field-productions/ice-field-productions.php \
  ice-field-rink-displays/ice-field-rink-displays.php
do
  if [[ -f "$plugin_file" ]]; then
    version_line="$(grep -m 1 'Version:' "$plugin_file" 2>/dev/null || true)"
    echo "${plugin_file:h}: ${version_line##*: }"
  fi
done

if [[ -f ice-field-productions-theme/style.css ]]; then
  theme_version="$(grep -m 1 '^Version:' ice-field-productions-theme/style.css 2>/dev/null || true)"
  echo "ice-field-productions-theme: ${theme_version##*: }"
fi

echo ""
echo "Read AGENTS.md and PROJECT-HANDOFF.md before starting work."

if [[ -t 0 ]]; then
  echo ""
  read "?Press Return to close."
fi
