#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
php -l index.php

grep -q "WMR Universal Developer Trace Dashboard v2.6" index.php
grep -q "wmr100-developer-trace.jsonl" index.php
grep -q "wmr200-developer-trace.jsonl" index.php
grep -q "forecast" index.php
grep -q "SOURCE_PREF_COOKIE_TRACE_MODE" index.php

echo "Repository checks passed."
