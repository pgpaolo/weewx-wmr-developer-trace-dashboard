# Configuration

Most deployments require no changes to `index.php`.

Important constants near the top of the file:

- `TRACE_CANDIDATES`: traces considered by automatic detection.
- `MANUAL_TRACE_ALLOWED_ROOTS`: directories allowed for manual trace selection.
- `TRACE_BACKUPS`: maximum rotated traces considered by the UI.
- `LOCAL_TIMEZONE`: timezone used for display.
- `RECENT_HEALTH_WINDOW`: recent-event window used for current health.
- `DEFAULT_LIVE_REFRESH`: default AJAX refresh interval.
- `LIVE_CACHE_ROOT`: directory used for incremental live-state cache files.

Default candidates:

```text
/var/log/weewx/wmr100-developer-trace.jsonl
/var/log/weewx/wmr200-developer-trace.jsonl
/tmp/wmr200-developer-trace.jsonl
```

## Manual mode

Manual mode is persistent in the browser. It remembers:

- source mode (Automatic / Manual),
- selected parser family,
- selected manual trace path.

The preference contains no trace content or credentials.

## Diagnostic bundles

If the PHP ZIP extension is available, bundles are generated as ZIP files.
Without it, the dashboard falls back to a non-ZIP diagnostic export.
