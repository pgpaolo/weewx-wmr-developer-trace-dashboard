# WMR Universal Developer Trace Dashboard

[Italiano](README-IT.md)

A standalone PHP dashboard for JSONL developer traces produced by hardened
WeeWX drivers for Oregon Scientific WMR weather stations.

**Current release: v2.6**

## Supported station families

| Family | Models | Trace source |
|---|---|---|
| WMR100 protocol | WMR100, WMR100N, WMR88, WMR88A, WMR180, WMR180A, WMRS200 | `wmr100-developer-trace.jsonl` |
| WMR200 protocol | WMR200, WMR200A and compatible W200 variants | `wmr200-developer-trace.jsonl` |

The dashboard is designed for the **hardened drivers that generate JSONL
developer traces**. The original WeeWX drivers do not generate these traces by
default.

## Highlights

- Automatic WMR100/WMR88 vs WMR200 trace detection.
- Persistent **Automatic / Manual** trace-source switch.
- Manual trace file selection with allowed-root validation.
- Live AJAX updates with incremental state, including WMR200 trace rotation.
- Current health separated from historical problems.
- USB timeout, recovery, reinitialisation and reopen diagnostics.
- Packet checksum, malformed-frame and parser-resynchronisation monitoring.
- Live weather values and packet timing.
- WMR100/WMR88 RF channel panel; automatically hidden for WMR200.
- Console forecast code, description and built-in SVG icon.
- CSV filtered export and downloadable diagnostic bundle.
- Rotated trace support.
- No database and no external JavaScript or icon dependency.

## Forecast display

For WMR100/WMR88-family stations, v2.6 can extract the console forecast code
directly from valid `0x46` pressure packets, so the forecast works even when
the driver does not yet expose `forecastIcon` in the WeeWX LOOP packet.

For WMR200-family stations, the dashboard uses the forecast field emitted by
the hardened WMR200 driver.

## Requirements

- Linux / Raspberry Pi or another WeeWX host.
- PHP 8.1 or newer recommended.
- Apache, nginx + PHP-FPM, or another PHP-capable web server.
- Read access to the selected WeeWX JSONL trace.
- `php-zip` is optional and enables ZIP diagnostic bundles.

## Quick installation

```bash
unzip weewx-wmr-developer-trace-dashboard-v2.6.zip
cd weewx-wmr-developer-trace-dashboard-v2.6
sudo ./scripts/install.sh /var/www/html/wmr-trace
```

Then open:

```text
http://YOUR-WEEWX-HOST/wmr-trace/
```

The installer only deploys the web dashboard. It does not modify the WeeWX
driver, `weewx.conf`, USB rules or trace permissions.

See [Installation](docs/INSTALL.md) and
[Configuration](docs/CONFIGURATION.md) for production setup.

## Default trace locations

```text
/var/log/weewx/wmr100-developer-trace.jsonl
/var/log/weewx/wmr200-developer-trace.jsonl
/tmp/wmr200-developer-trace.jsonl
```

Manual mode accepts readable `.jsonl` files under the roots configured in
`MANUAL_TRACE_ALLOWED_ROOTS`. The supplied configuration allows:

```text
/var/log/weewx
/tmp
```

## Production permissions

A group-based read-only configuration is preferred over `chmod 666`:

```bash
sudo usermod -aG weewx www-data
sudo chmod 0750 /var/log/weewx
sudo chmod 0640 /var/log/weewx/wmr100-developer-trace.jsonl
sudo chmod 0640 /var/log/weewx/wmr200-developer-trace.jsonl
sudo systemctl restart apache2
```

Only apply commands for trace files that exist on the host. File ownership and
rotation policy should remain under WeeWX control.

## Validate the release

```bash
php -l index.php
./scripts/check.sh
```

## Repository description

Suggested GitHub description:

> Universal PHP diagnostics dashboard for hardened WeeWX Oregon Scientific WMR100/WMR88 and WMR200 developer traces, with auto-detection, live health, protocol analysis and forecast display.

Suggested topics:

```text
weewx
oregon-scientific
wmr100
wmr88
wmr88a
wmr200
weather-station
php
jsonl
usb
diagnostics
developer-trace
raspberry-pi
meteorology
```

## License

MIT. See [LICENSE](LICENSE).
