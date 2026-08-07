# v2.6 - Console Forecast and Universal WMR Trace Monitoring

Version 2.6 adds the original console forecast to the universal WMR diagnostics
view while preserving the stability improvements introduced in v2.4 and v2.5.

## Highlights

- WMR100/WMR88 forecast extraction from valid `0x46` pressure packets.
- WMR200 forecast display from the hardened driver's forecast field.
- Original console forecast code, description and built-in SVG icon.
- Persistent Automatic/Manual trace-source selection.
- Incremental WMR200 AJAX state with trace rotation and partial-line handling.
- WMR100/WMR88 RF-channel panel hidden automatically for WMR200.
- Current operational health separated from historical diagnostics.
- CSV and diagnostic-bundle export.

The dashboard remains a single standalone `index.php` at runtime and requires no
database or external JavaScript framework.
