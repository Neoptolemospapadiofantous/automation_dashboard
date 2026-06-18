#!/usr/bin/env bash
# Install the tracked Hermes git hooks into this clone's .git/hooks.
# Re-run after a fresh clone (hooks live outside version control).
set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
src="$repo_root/scripts/git-hooks"
dest="$repo_root/.git/hooks"

for hook in pre-push; do
    cp "$src/$hook" "$dest/$hook"
    chmod +x "$dest/$hook"
    echo "✓ installed $hook"
done
