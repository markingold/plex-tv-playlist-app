#!/usr/bin/env bash
# Dump contents of public/, scripts/, and index.php into a single text file.
# Usage:
#   ./make_text.sh [PROJECT_ROOT] [OUTPUT_FILE]
# Defaults:
#   PROJECT_ROOT = current directory
#   OUTPUT_FILE  = app_snapshot_YYYYMMDD_HHMMSS.txt

set -Eeuo pipefail

ROOT="${1:-$(pwd)}"
OUT="${2:-app_snapshot_$(date +%Y%m%d_%H%M%S).txt}"

# Normalize ROOT to absolute path
cd "$ROOT" || { echo "Project root not found: $ROOT" >&2; exit 1; }
ROOT="$(pwd)"

# Create/empty output file
: > "$OUT" || { echo "Cannot write to $OUT" >&2; exit 1; }

count=0

dump_one () {
  local abs="$1"
  local rel="${abs#$ROOT/}"
  {
    printf '===== START %s =====\n' "$rel"
    if [[ -r "$abs" ]]; then
      cat "$abs"
    else
      printf '[[ cannot read %s ]]\n' "$rel"
    fi
    printf '\n===== END %s =====\n\n' "$rel"
  } >> "$OUT"
  count=$((count+1))
}

# 1) index.php
[[ -f "$ROOT/index.php" ]] && dump_one "$ROOT/index.php"

# 2) everything under public/ and scripts/ (if they exist)
TMP="$(mktemp)"
if [[ -d "$ROOT/public" || -d "$ROOT/scripts" ]]; then
  # Collect paths, ignore errors if a dir is missing
  find "$ROOT/public" "$ROOT/scripts" -type f 2>/dev/null | sort > "$TMP" || true
  while IFS= read -r f; do
    [[ -n "$f" ]] && dump_one "$f"
  done < "$TMP"
else
  echo "Note: no 'public/' or 'scripts/' directory found under $ROOT" >&2
fi
rm -f "$TMP"

echo "Wrote $count file(s) to: $OUT"
