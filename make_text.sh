#!/usr/bin/env bash
# Dump project files into a single text file for sharing/debugging.
# Includes: index.php, public/**, scripts/**, docker/**, and key root files.
# Excludes: venv/, vendor/, .git/, logs/, database/, *.db/sqlite*, and the snapshot itself.
# Usage:
#   ./make_text.sh [PROJECT_ROOT] [OUTPUT_FILE]
# Defaults:
#   PROJECT_ROOT = current directory
#   OUTPUT_FILE  = app_snapshot_YYYYMMDD_HHMMSS.txt

set -Eeuo pipefail

ROOT="${1:-$(pwd)}"
OUT="${2:-app_snapshot_$(date +%Y%m%d_%H%M%S).txt}"

cd "$ROOT" || { echo "Project root not found: $ROOT" >&2; exit 1; }
ROOT="$(pwd)"

# Create/empty output file first so we can exclude it later
: > "$OUT" || { echo "Cannot write to $OUT" >&2; exit 1; }

# Relative path to the OUT file for filtering
REL_OUT="$OUT"
if command -v realpath >/dev/null 2>&1; then
  REL_OUT="$(realpath --relative-to="$ROOT" "$OUT" || echo "$OUT")"
fi
OUT_BASENAME="$(basename "$REL_OUT")"

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

TMP="$(mktemp)"
trap 'rm -f "$TMP" "$TMP.filtered" 2>/dev/null || true' EXIT

# 1) Add specific root files if present
for f in index.php dockerfile docker-compose.yml README.md LICENSE .env.example .gitignore; do
  [[ -f "$f" ]] && printf '%s\n' "$f" >> "$TMP"
done

# 2) Add everything under these directories
for d in public scripts docker; do
  [[ -d "$d" ]] || continue
  find "$d" -type f \
    -not -path '*/venv/*' \
    -not -path '*/vendor/*' \
    -not -path '*/.git/*' \
    -not -path '*/logs/*' \
    -not -path '*/database/*' \
    -not -name '*.db' \
    -not -name '*.sqlite' \
    -not -name '*.sqlite3' \
    -not -name "$OUT_BASENAME" \
    -print >> "$TMP"
done

# 3) Sort & uniq, exclude the snapshot itself just in case, then dump
sort -u "$TMP" -o "$TMP"
grep -v -F -- "$REL_OUT" "$TMP" > "$TMP.filtered" || true
mv "$TMP.filtered" "$TMP"

while IFS= read -r rel; do
  [[ -n "$rel" && -f "$rel" ]] || continue
  dump_one "$ROOT/$rel"
done < "$TMP"

echo "Wrote $count file(s) to: $OUT"
