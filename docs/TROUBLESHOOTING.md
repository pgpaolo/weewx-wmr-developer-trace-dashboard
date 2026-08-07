# Troubleshooting

## "Trace not readable"

Check that the file exists and that the PHP/web-server account can read it:

```bash
sudo -u www-data head -1 /var/log/weewx/wmr100-developer-trace.jsonl
sudo -u www-data head -1 /var/log/weewx/wmr200-developer-trace.jsonl
```

## Manual mode returns to Automatic

v2.6 includes persistent source selection inherited from v2.5. If the browser
blocks cookies for the site, source preferences cannot persist. Allow same-site
cookies for the dashboard.

## WMR200 live values appear stale

v2.6 inherits the incremental AJAX state introduced in v2.4. Ensure `/tmp` is
writable by the PHP process because the dashboard stores its live-state cache
there.

```bash
sudo -u www-data touch /tmp/wmr-dashboard-test && sudo rm /tmp/wmr-dashboard-test
```

## Diagnostic ZIP is not available

Install PHP ZIP support:

```bash
sudo apt install php-zip
sudo systemctl restart apache2
```

## Forecast missing on WMR100/WMR88

The dashboard needs `packet_valid` pressure events in the developer trace.
Enable packet tracing in the hardened driver:

```ini
developer_trace = true
developer_trace_packets = true
developer_trace_raw_reports = false
```
