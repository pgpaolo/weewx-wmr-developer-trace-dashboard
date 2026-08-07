# Publishing on GitHub

Suggested repository name:

```text
weewx-wmr-developer-trace-dashboard
```

Suggested description:

```text
Universal PHP diagnostics dashboard for hardened WeeWX Oregon Scientific WMR100/WMR88 and WMR200 developer traces, with auto-detection, live health, protocol analysis and forecast display.
```

Create the repository and push:

```bash
git init -b main
git add .
git commit -m "Initial release v2.6"
git remote add origin https://github.com/OWNER/weewx-wmr-developer-trace-dashboard.git
git push -u origin main
```

Create the release tag:

```bash
git tag -a v2.6 -m "WMR Universal Developer Trace Dashboard v2.6"
git push origin v2.6
```

Recommended release title:

```text
v2.6 - Console Forecast and Universal WMR Trace Monitoring
```
