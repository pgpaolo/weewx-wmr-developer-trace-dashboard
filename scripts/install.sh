#!/usr/bin/env bash
set -euo pipefail

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html/wmr-trace}"
SOURCE="$SRC_DIR/index.php"
DEST="$TARGET/index.php"

if ! command -v php >/dev/null 2>&1; then
    echo "ERROR: php is not installed or not in PATH." >&2
    exit 1
fi

php -l "$SOURCE" >/dev/null

install -d -m 0755 "$TARGET"
if [[ -f "$DEST" ]]; then
    BACKUP="$DEST.$(date +%Y%m%d-%H%M%S).bak"
    cp -a "$DEST" "$BACKUP"
    echo "Backup created: $BACKUP"
fi
install -m 0644 "$SOURCE" "$DEST"

echo "Installed: $DEST"
echo "PHP syntax: OK"
echo
echo "Verify trace access with, for example:"
echo "  sudo -u www-data head -1 /var/log/weewx/wmr100-developer-trace.jsonl"
echo "  sudo -u www-data head -1 /var/log/weewx/wmr200-developer-trace.jsonl"
