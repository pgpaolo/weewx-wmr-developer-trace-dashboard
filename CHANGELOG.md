# Changelog

## v2.7 - 2026-08-09

### Fixed
- Fixed missing WMR100/WMR88/WMR88A driver version and model after JSONL trace rotation.
- The dashboard now harvests `driver`, `driver_version` and `model` from every trace event instead of depending only on `driver_start`.
- WMR100 startup-only metadata (VID, PID, USB endpoint, model profile, timeout thresholds and channel count) is recovered from the active trace or its rotated backups without adding backup events to live counters.
- Fixed missing WMR100/WMR88 session uptime when `driver_start` is no longer present in the active JSONL.
- Improved the `USB / profilo` field to show VID:PID, model profile and endpoint when available.
- Bumped the live-cache schema to force regeneration of stale v2.6 metadata.

### Unchanged
- WMR200 parsing and live-monitor behaviour are unchanged.
- Rotated trace records are not included in event counters unless `Backup ruotati` is explicitly enabled.

## v2.8 - 2026-08-09

### Changed
- Added native support for WMR100/WMR88 driver `forecastIcon` introduced by driver 3.5.6-gp6.
- Preserved automatic forecast-code fallback from raw pressure packet `0x46` for gp5 and older traces.
- Replaced the misleading WMR100/WMR88 label `Altimetro console` with `Pressione relativa console / SLP`.
- WMR100/WMR88 console relative pressure is now decoded directly from the native `0x46` pressure packet for diagnostics.
- WMR200 continues to display its driver-provided `altimeter` value unchanged.
- Added an explicit indication of forecast source: gp6 driver LOOP, raw 0x46 compatibility fallback, or WMR200 driver.
- Incremented the live-cache schema to version 5 to prevent stale v2.7 metadata/weather state.

### Compatibility
- WMR100/WMR88/WMR88A gp6: uses driver-provided `forecastIcon`.
- WMR100/WMR88/WMR88A gp5 and earlier hardened traces: uses verified raw `0x46` fallback.
- WMR200/WMR200A: unchanged pressure/altimeter/forecast behavior.
