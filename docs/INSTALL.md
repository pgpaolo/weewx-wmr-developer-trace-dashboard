# Installation

## 1. Requirements

Install PHP and, optionally, ZIP support:

```bash
sudo apt update
sudo apt install php php-zip
```

If the host already serves WeeWX through Apache/PHP, PHP is usually already
available.

## 2. Install the dashboard

```bash
sudo ./scripts/install.sh /var/www/html/wmr-trace
```

Or install manually:

```bash
sudo install -d -m 0755 /var/www/html/wmr-trace
sudo install -m 0644 index.php /var/www/html/wmr-trace/index.php
php -l /var/www/html/wmr-trace/index.php
```

## 3. Grant read access to traces

First check the current permissions:

```bash
ls -ld /var/log/weewx
ls -l /var/log/weewx/*developer-trace*.jsonl 2>/dev/null
sudo -u www-data head -1 /var/log/weewx/wmr100-developer-trace.jsonl
```

For a group-based setup:

```bash
sudo usermod -aG weewx www-data
sudo chmod 0750 /var/log/weewx
sudo chmod 0640 /var/log/weewx/wmr100-developer-trace.jsonl
sudo chmod 0640 /var/log/weewx/wmr200-developer-trace.jsonl
sudo systemctl restart apache2
```

Adjust the commands to the traces that actually exist.

## 4. Open the dashboard

```text
http://YOUR-WEEWX-HOST/wmr-trace/
```

The default source mode is automatic. Use Manual mode when more than one trace
exists and you want to lock the dashboard to a specific file.

## 5. Upgrade

The installer creates a timestamped backup of an existing `index.php` before
replacing it.

```bash
sudo ./scripts/install.sh /var/www/html/wmr-trace
```

Browser source preferences are preserved by the browser cookies used by the
dashboard.
