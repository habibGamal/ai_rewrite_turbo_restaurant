#!/usr/bin/env bash
# Pre-fill Composer's download cache from shallow git fetches.
#
# Why: in Claude Code cloud sessions the egress proxy blocks GitHub archive
# downloads (api.github.com/.../zipball, codeload.github.com) but allows git.
# Composer then falls back to full `git clone --mirror` of every package, which
# is very slow (laravel/framework, phpstan/phpstan, ...). This script fetches
# only the locked commit of each package (`git fetch --depth 1 <sha>`), builds
# the same zip GitHub would serve (`git archive` honours export-ignore), and
# stores it under the exact cache key Composer uses (sha1 of the dist URL).
# A normal `composer install` afterwards loads every package from cache.
#
# Usage: scripts/composer-prefetch.sh [composer.lock] [parallel jobs]
set -euo pipefail

LOCK="${1:-composer.lock}"
JOBS="${2:-12}"
CACHE_DIR="${COMPOSER_CACHE_DIR:-$(composer config --global cache-dir 2>/dev/null || echo "$HOME/.cache/composer")}"
FILES_DIR="$CACHE_DIR/files"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# name<TAB>dist-url<TAB>git-url<TAB>sha for every GitHub zip dist missing from cache
php -r '
$l = json_decode(file_get_contents($argv[1]), true);
foreach (array_merge($l["packages"] ?? [], $l["packages-dev"] ?? []) as $p) {
    $d = $p["dist"] ?? null;
    if (!$d || ($d["type"] ?? "") !== "zip") continue;
    if (!preg_match("#^https://api\.github\.com/repos/([^/]+/[^/]+)/zipball/([0-9a-f]{40})$#", $d["url"], $m)) continue;
    // Some packages (e.g. phpstan/phpstan) lock no source; the dist URL names the repo.
    $git = "https://github.com/" . $m[1] . ".git";
    if (!empty($d["shasum"])) continue; // cannot reproduce a GitHub-made checksum
    $file = $argv[2] . "/" . $p["name"] . "/" . sha1($d["url"]) . ".zip";
    if (is_file($file)) continue;
    echo $p["name"], "\t", $file, "\t", $git, "\t", $m[2], "\n";
}' "$LOCK" "$FILES_DIR" > "$WORK/todo.tsv"

total=$(wc -l < "$WORK/todo.tsv")
if [ "$total" -eq 0 ]; then
  echo "composer-prefetch: cache already complete"
  exit 0
fi
echo "composer-prefetch: fetching $total packages with $JOBS parallel shallow git fetches"

fetch_one() {
  local name="$1" file="$2" url="$3" sha="$4" dir
  dir="$WORK/$(echo "$name" | tr '/' '_')"
  for attempt in 1 2 3; do
    rm -rf "$dir" && git init -q "$dir" \
      && git -C "$dir" fetch -q --depth 1 "$url" "$sha" 2>/dev/null \
      && mkdir -p "$(dirname "$file")" \
      && git -C "$dir" archive --format=zip --prefix="pkg/" -o "$file.tmp" FETCH_HEAD \
      && mv "$file.tmp" "$file" && { rm -rf "$dir"; return 0; }
    sleep $((attempt * 2))
  done
  rm -rf "$dir" "$file.tmp"
  echo "composer-prefetch: failed $name (Composer will fall back to cloning it)" >&2
}
export -f fetch_one
export WORK

# Never fail the caller: anything missing is simply downloaded by Composer.
tr '\t' '\n' < "$WORK/todo.tsv" | xargs -d '\n' -n 4 -P "$JOBS" bash -c 'fetch_one "$@"' _ || true
echo "composer-prefetch: done"
