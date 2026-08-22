# Changelog

All notable changes to the WMR Universal Developer Trace Dashboard are documented here.

## v2.9.1 — 2026-08-22

### Changed

- The thermo-hygrometer panel now shows only channels that have actually received temperature or humidity data by default.
- Added a **Show not received** control to reveal missing/unused channels when full RF diagnostics are required.
- The channel-visibility preference is retained in browser local storage and survives AJAX refreshes.
- Wind, rain and UV diagnostic cards remain visible independently of the thermo-hygrometer channel filter.
- Added the dashboard version indicator to the bottom-right footer.
- WMR200 additional T/H channels now show `N/D per canale` for battery state because the console protocol does not identify a battery status for CH2+; CH1 continues to use the real `outTempBatteryStatus` value.

## v2.9 — 2026-08-22

### Added

- Added universal thermo-hygrometer channel diagnostics for WMR100/WMR88 and WMR200/WMR200A.
- Added WMR200/WMR200A diagnostic channel coverage from CH0 through CH10.
- Added per-channel temperature, humidity, battery state when available, data age and last-reading timestamp.
- Added explicit WeeWX field mapping to each channel card.
- Added native WMR200 `temperature_N` / `humidity_N` labels to the channel cards.
- Added forward-compatible `battery_status_N` mapping for channel-specific battery observations when present in the trace.

### Changed

- The RF/channel panel is no longer hidden for WMR200/WMR200A.
- WMR100/WMR88 continues to respect `max_remote_channels`; WMR200 defaults to ten remote channels for diagnostics.
- WMR200 channel metadata remains consistent across `driver_start` session resets.
- Updated the AJAX payload and caption handling for the universal channel panel.
- Incremented the live-cache schema to version 6.

### Compatibility

- Existing USB, recovery, forecast, pressure and protocol-monitor logic is unchanged.
- Standard WeeWX mappings remain unchanged: CH1 = `outTemp` / `outHumidity`, CH2 = `extraTemp1` / `extraHumid1`, CH3 = `extraTemp2` / `extraHumid2`, through CH8.
- WMR200 CH9 and CH10 remain visible diagnostically through generic channel fields unless explicitly mapped by the driver/schema.

## v2.8 — 2026-08-09

### Changed

- Added native support for WMR100/WMR88 driver `forecastIcon` introduced by hardened driver 3.5.6-gp6.
- Preserved automatic forecast-code fallback from the raw pressure packet `0x46` for gp5 and older compatible traces.
- Replaced the misleading WMR100/WMR88 label `Altimetro console` with `Pressione relativa console / SLP`.
- WMR100/WMR88 console relative pressure is decoded directly from the native `0x46` pressure packet for diagnostics.
- WMR200 continues to display its driver-provided `altimeter` value unchanged.
- Added an explicit indication of forecast source: driver LOOP, raw `0x46` compatibility fallback, or WMR200 driver.
- Incremented the live-cache schema to version 5 to prevent stale v2.7 metadata/weather state.

### Compatibility

- WMR100/WMR88/WMR88A gp6: uses driver-provided `forecastIcon`.
- WMR100/WMR88/WMR88A gp5 and earlier hardened traces: uses the verified raw `0x46` fallback.
- WMR200/WMR200A: unchanged pressure, altimeter and forecast behavior.

## v2.7 — 2026-08-09

### Fixed

- Fixed missing WMR100/WMR88/WMR88A driver version and model after JSONL trace rotation.
- The dashboard harvests `driver`, `driver_version` and `model` from trace events instead of depending only on `driver_start`.
- WMR100 startup-only metadata such as VID, PID, USB endpoint, model profile, timeout thresholds and channel count can be recovered from the active trace or rotated backups without adding backup events to the live counters.
- Fixed missing WMR100/WMR88 session uptime when `driver_start` is no longer present in the active JSONL.
- Improved the `USB / profilo` field to show VID:PID, model profile and endpoint when available.
- Bumped the live-cache schema to force regeneration of stale v2.6 metadata.

### Unchanged

- WMR200 parsing and live-monitor behavior remained unchanged.
- Rotated trace records are not included in event counters unless rotated backups are explicitly enabled.

## v2.6 — 2026-08-07

### Added

- Added console forecast icon and Italian forecast description to the Pressure card.
- Added WMR100/WMR88/WMR88A forecast extraction from valid `0x46` pressure packets.
- Added display of the original console forecast code.
- Added WMR200 forecast rendering using the existing `forecastIcon` field.
- Added family-aware forecast maps, including WMR200 night forecast codes.
- Added inline SVG weather icons with no external image dependency.

### Changed

- Bumped the live-cache schema to invalidate pre-v2.6 cached weather state.

## v2.5 — 2026-08-07

### Fixed

- Fixed persistence of manual trace mode across browser refresh (`F5`).
- The selected source mode is remembered immediately when the Auto/Manual switch changes.
- The manual trace path and parser/family selection are remembered across refreshes and browser reopenings.
- Explicitly selecting Automatic mode overwrites the stored manual mode.
- Reset filters no longer silently discards the selected trace source.

### Security

- Source preferences are stored in same-origin `SameSite=Lax` cookies.
- No credentials or trace contents are stored in source-preference cookies.

## v2.4 — 2026-08-07

### Fixed

- Fixed the WMR200 live AJAX refresh-state regression.
- Replaced fixed-tail AJAX state reconstruction with incremental JSONL processing.
- Added safe handling of partially written JSONL records.
- Preserved accumulated state across normal trace rotation or truncation.

### Changed

- Added a per-source/filter live cache under `/tmp`.
- Preserved weather values, sessions and cumulative counters between refreshes.
- Removed the RF/channel panel from WMR200/WMR200A views.
- Kept RF/channel monitoring for WMR100/WMR88/WMR88A family consoles.
- Added stronger no-cache handling and an AJAX cache-buster.
- Re-evaluated the rolling health window during live rendering so old warnings can age out correctly.

## v2.3 — 2026-08-07

### Added

- Added a real `Automatico / Manuale` trace-source switch.
- Manual trace input is shown and enabled only when Manual mode is selected.
- Separated family/parser selection from trace-source mode.

### Fixed

- Automatic mode ignores stale `trace_file` query parameters.
- Manual mode uses only the requested trace and does not silently fall back to another source.
- Reworked the filter panel into responsive source, event-filter and action rows.
- Fixed horizontal overflow that could clip Apply, Reset, CSV or Bundle controls.
- AJAX preserves the selected trace-source mode.

## v2.2 — 2026-08-07

### Added

- Added secure manual trace-file selection.
- Added family detection from manually selected JSONL content.
- Added a visible manual-trace source indicator.

### Security

- Allowed manual trace roots are restricted to `/var/log/weewx` and `/tmp`.
- Manual traces must be readable `.jsonl` files.
- Invalid or unreadable manual paths are rejected rather than silently selecting an unrelated manual source.

## v2.1 — 2026-08-07

### Added

- Introduced the universal dashboard for WMR100/WMR88/WMR88A and WMR200/WMR200A trace families.
- Added automatic trace-source selection based on the newest event timestamp.
- Added family-aware parsing and diagnostics.
