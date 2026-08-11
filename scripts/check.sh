#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

VERSION="$(tr -d '[:space:]' < VERSION)"
EXPECTED="v${VERSION}"

php -l index.php

grep -Fq "WMR Universal Developer Trace Dashboard ${EXPECTED}" index.php
grep -Fq "wmr100-developer-trace.jsonl" index.php
grep -Fq "wmr200-developer-trace.jsonl" index.php
grep -Fq "forecast" index.php
grep -Fq "SOURCE_PREF_COOKIE_TRACE_MODE" index.php

grep -Fq "${EXPECTED}" README.md
grep -Fq "${EXPECTED}" README-IT.md
grep -Fq "${EXPECTED}" CHANGELOG.md

echo "Repository checks passed for ${EXPECTED}."
