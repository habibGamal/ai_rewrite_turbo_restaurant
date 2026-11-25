#!/usr/bin/env bash
# Keeps the shared docs theme identical across the POS, website and platform repos.
# The POS repo (ai_rewrite_turbo_restaurant) owns the theme. This script is the same
# file in all three repos.
#
#   bash scripts/docs-theme.sh verify          # theme files match docs/.vitepress/theme.lock
#   bash scripts/docs-theme.sh lock            # POS only: rewrite the lock after editing the theme
#   bash scripts/docs-theme.sh pull [pos-dir]  # website/platform: copy theme + lock from a POS checkout
#                                              # (default: $POS_REPO or ../ai_rewrite_turbo_restaurant)
set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
theme_dir="docs/.vitepress/theme"
lock_file="docs/.vitepress/theme.lock"
cd "$repo_root"

hash_cmd() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$@"
  else
    shasum -a 256 "$@"
  fi
}

# Prints "<sha256>  <file name>" for every theme file, sorted, with CRLF normalised
# so Windows checkouts (core.autocrlf) hash the same as Linux CI.
compute_lock() {
  local f
  for f in $(cd "$theme_dir" && find . -type f | sed 's#^\./##' | LC_ALL=C sort); do
    printf '%s  %s\n' "$(tr -d '\r' < "$theme_dir/$f" | hash_cmd | cut -d' ' -f1)" "$f"
  done
}

cmd="${1:-verify}"
case "$cmd" in
  lock)
    compute_lock > "$lock_file"
    echo "Wrote $lock_file. Copy the theme and lock to the website and platform repos with: bash scripts/docs-theme.sh pull"
    ;;
  verify)
    if [ ! -f "$lock_file" ]; then
      echo "ERROR: $lock_file is missing. Run 'bash scripts/docs-theme.sh pull' (website/platform) or 'lock' (POS)." >&2
      exit 1
    fi
    if ! diff <(compute_lock) <(tr -d '\r' < "$lock_file") >/dev/null; then
      echo "ERROR: docs/.vitepress/theme/ differs from theme.lock. The shared theme is edited only in the POS repo" >&2
      echo "(ai_rewrite_turbo_restaurant): change it there, run 'bash scripts/docs-theme.sh lock', then 'pull' it here." >&2
      diff <(compute_lock) <(tr -d '\r' < "$lock_file") >&2 || true
      exit 1
    fi
    echo "docs theme matches theme.lock."
    ;;
  pull)
    pos_dir="${2:-${POS_REPO:-../ai_rewrite_turbo_restaurant}}"
    if [ ! -f "$pos_dir/$lock_file" ]; then
      echo "ERROR: no $lock_file in $pos_dir. Pass the POS checkout path or set POS_REPO." >&2
      exit 1
    fi
    rm -rf "$theme_dir"
    mkdir -p "$theme_dir"
    cp -R "$pos_dir/$theme_dir/." "$theme_dir/"
    cp "$pos_dir/$lock_file" "$lock_file"
    bash "$0" verify
    ;;
  *)
    echo "Usage: bash scripts/docs-theme.sh [verify|lock|pull [pos-dir]]" >&2
    exit 2
    ;;
esac
