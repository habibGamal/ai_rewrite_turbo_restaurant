#!/usr/bin/env bash
set -euo pipefail

run_build=0
for arg in "$@"; do
  case "$arg" in
    --build)
      run_build=1
      ;;
    -h|--help)
      echo "Usage: bash scripts/docs-check.sh [--build]"
      exit 0
      ;;
    *)
      echo "Unknown option: $arg" >&2
      echo "Usage: bash scripts/docs-check.sh [--build]" >&2
      exit 2
      ;;
  esac
done

repo_root="$(git rev-parse --show-toplevel)"
cd "$repo_root"

errors=0

mapfile -t all_files < <(git -c core.quotePath=false ls-files --cached --others --exclude-standard)

# 1. Check .md allow-list (ignoring node_modules/ and vendor/)
disallowed_md=()
for path in "${all_files[@]}"; do
  case "$path" in
    node_modules/*|*/node_modules/*|vendor/*|*/vendor/*)
      continue
      ;;
  esac

  case "$path" in
    *.md)
      case "$path" in
        docs/node_modules/*)
          continue
          ;;
        docs/*|.workspace/*|.claude/*|larament/resources/ai/*|turbo_resturant/*)
          ;;
        README.md|CHANGELOG.md|AGENTS.md|CLAUDE.md|label-agent/README.md|larament/README.md)
          ;;
        *)
          disallowed_md+=("$path")
          ;;
      esac
      ;;
  esac
done

if [ "${#disallowed_md[@]}" -gt 0 ]; then
  echo "ERROR: Disallowed Markdown files found outside the allow-list:" >&2
  for f in "${disallowed_md[@]}"; do
    echo "  - $f" >&2
  done
  echo "Move agent drafts/specs into .workspace/ or permanent docs into docs/." >&2
  errors=1
fi

# 2. Check forbidden path segments
forbidden_paths=()
for path in "${all_files[@]}"; do
  if printf '%s\n' "$path" | grep -Eq '(^|/)\.(plan|plans|superpowers|remember)(/|$)|(^|/)docs/superpowers(/|$)'; then
    forbidden_paths+=("$path")
  fi
done

if [ "${#forbidden_paths[@]}" -gt 0 ]; then
  echo "ERROR: Forbidden planning/scratch path segments found (.plan/, .plans/, .superpowers/, .remember/, docs/superpowers/):" >&2
  for f in "${forbidden_paths[@]}"; do
    echo "  - $f" >&2
  done
  errors=1
fi

# 3. Check docs/ar/*.md for Egyptian dialect words (whole-word only)
ar_files=()
for path in "${all_files[@]}"; do
  case "$path" in
    docs/ar/*.md)
      if [ -f "$path" ]; then
        ar_files+=("$path")
      fi
      ;;
  esac
done

if [ "${#ar_files[@]}" -gt 0 ]; then
  dialect_hits=""
  if command -v perl >/dev/null 2>&1; then
    dialect_hits="$(
      perl -CSD -Mutf8 -ne '
        BEGIN {
          $re = qr/(?<![\p{L}\p{N}_])(مش|اللي|عشان|كده|مفيش|لسه|دلوقتي|ازاي)(?![\p{L}\p{N}_])/u;
        }
        if (/$re/) {
          print "$ARGV:$.:$_";
        }
        close ARGV if eof;
      ' "${ar_files[@]}" || true
    )"
  else
    dialect_hits="$(
      LC_ALL=C.UTF-8 grep -rnwE 'مش|اللي|عشان|كده|مفيش|لسه|دلوقتي|ازاي' "${ar_files[@]}" || true
    )"
  fi

  if [ -n "$dialect_hits" ]; then
    echo "ERROR: Dialect words found under docs/ar/ (use Modern Standard Arabic):" >&2
    printf '%s\n' "$dialect_hits" >&2
    errors=1
  fi
fi

if [ "$errors" -ne 0 ]; then
  exit 1
fi

# 4. Optional VitePress build
if [ "$run_build" -eq 1 ]; then
  (
    cd "$repo_root/docs"
    npm ci
    npx vitepress build
  )
fi

echo "docs-check passed."
