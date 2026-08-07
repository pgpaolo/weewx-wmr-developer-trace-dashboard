# Security policy

The dashboard is intended to be deployed on a trusted administrative network.
It reads local JSONL trace files and can export filtered diagnostics.

## Recommendations

- Do not expose the dashboard directly to the public Internet without access control.
- Keep PHP and the web server updated.
- Keep `MANUAL_TRACE_ALLOWED_ROOTS` restricted to trusted directories.
- Prefer read-only access for the web-server account to WeeWX trace files.
- Do not make `/var/log/weewx` world-writable.
- Review exported diagnostic bundles before sharing them externally.

## Reporting a security issue

Please report security issues privately to the repository maintainer rather
than opening a public issue containing sensitive diagnostic data.
