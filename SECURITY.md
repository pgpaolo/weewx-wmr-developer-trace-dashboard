# Security Policy

## Scope

This repository contains a standalone PHP diagnostics dashboard that reads structured WeeWX WMR developer traces from the local filesystem.

The dashboard is intended primarily for troubleshooting, development and short-lived technical monitoring.

## Deployment guidance

Do **not** assume that the dashboard is safe to expose permanently to the public Internet simply because it does not contain an application login.

If remote access is required, place it behind an appropriate access-control layer, for example:

- authenticated reverse proxy;
- VPN;
- web-server authentication;
- trusted-network/IP restrictions;
- another equivalent administrative control.

TLS should be used whenever the dashboard is accessed across an untrusted network.

## Manual trace-file boundary

The dashboard intentionally restricts manual trace selection to readable `.jsonl` files below:

```text
/var/log/weewx
/tmp
```

Do not broaden `MANUAL_TRACE_ALLOWED_ROOTS` without reviewing the security consequences.

The existing boundary reduces the chance that the web process can be used to inspect arbitrary local files.

## Filesystem permissions

Grant the web-server account only the read permissions it actually needs.

Avoid making trace directories globally writable or granting unnecessary privileges to the web-server user.

## Diagnostic bundles

Diagnostic bundles are meant for technical troubleshooting and can contain system-specific information, including:

- driver and model identifiers;
- USB metadata;
- local timestamps;
- error messages and reasons;
- counters and selected packet information;
- local trace paths.

Review every bundle before publishing it in a public issue, forum or repository.

## Cookies

The source-selection cookies are same-origin preferences and do not intentionally store credentials or trace contents.

They must not be treated as authentication.

## Reporting a vulnerability

Please avoid publishing a working exploit or sensitive host data in a public issue.

Open a GitHub Security Advisory if private vulnerability reporting is enabled for the repository. Otherwise contact the repository maintainer privately before disclosing sensitive details.

Include:

- affected dashboard version;
- PHP/web-server environment;
- reproduction steps;
- impact;
- minimal logs with secrets and unrelated host information removed.

## Supported version

Security fixes are expected to target the current dashboard version. At the time of this policy update, the current documented version is **v2.8**.
