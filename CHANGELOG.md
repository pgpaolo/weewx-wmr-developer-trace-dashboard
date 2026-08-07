# Changelog

## v2.6 — Console forecast code and icon

- Added console forecast icon and Italian forecast description to the Pressure card.
- Added WMR100/WMR88/WMR88A forecast extraction directly from valid `0x46` pressure packets.
- Added original console forecast code display alongside the icon.
- Added WMR200 forecast rendering using the existing `forecastIcon` field.
- Added family-aware forecast maps, including WMR200 night forecast codes.
- Added standalone inline SVG weather icons with no external dependencies.
- Bumped live-cache schema to invalidate pre-v2.6 cached weather state.

## v2.5 — Persistent manual trace selection

- Fixed manual trace mode persistence across browser refresh (`F5`).
- The selected source mode is now remembered immediately when the Auto/Manual switch changes; pressing **Applica** is not required just to persist the mode.
- The manual trace path is remembered across refreshes and browser reopenings.
- The selected parser/family (`Auto`, `WMR100/WMR88`, `WMR200`) is remembered as well.
- Explicitly selecting **Automatico** overwrites the stored manual mode.
- **Azzera filtri** now clears display/search parameters without silently discarding the selected trace source.
- Source preferences are stored in same-origin `SameSite=Lax` cookies for one year; no credentials or trace contents are stored.
- Retains all v2.4 fixes: incremental AJAX state for WMR200, JSONL partial-line handling, trace rotation handling, and RF channel panel only for WMR100/WMR88 family.

## v2.4

- Incremental AJAX live state for WMR200.
- Correct handling of trace rotation/truncation and partial JSONL writes.
- RF/channel panel hidden for WMR200/WMR200A and retained for WMR100/WMR88 family.
