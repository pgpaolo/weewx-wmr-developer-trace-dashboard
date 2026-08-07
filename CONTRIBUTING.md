# Contributing

Contributions are welcome, especially fixes validated against real WMR100,
WMR88/WMR88A or WMR200/WMR200A developer traces.

Before opening a pull request:

1. Run `php -l index.php`.
2. Run `./scripts/check.sh`.
3. Avoid changing the interpretation of protocol fields without trace evidence.
4. Keep WMR100-family and WMR200-family parsing paths separate where their
   trace schemas differ.
5. Do not include private trace files, credentials, hostnames or IP addresses
   in commits.

Please describe which station model and driver version were used for testing.
