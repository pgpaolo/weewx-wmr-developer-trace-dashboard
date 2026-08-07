#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(tr -d '[:space:]' < "$ROOT/VERSION")"
NAME="weewx-wmr-developer-trace-dashboard-v${VERSION}"
OUT="${1:-$ROOT/dist}"
mkdir -p "$OUT"
"$ROOT/scripts/check.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/$NAME"
rsync -a --exclude '.git' --exclude 'dist' "$ROOT/" "$TMP/$NAME/"
(
  cd "$TMP"
  zip -qr "$OUT/$NAME.zip" "$NAME"
)
sha256sum "$OUT/$NAME.zip" > "$OUT/$NAME.zip.sha256"
echo "Built $OUT/$NAME.zip"
