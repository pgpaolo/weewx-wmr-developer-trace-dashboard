# Installazione

## 1. Requisiti

Installa PHP e, opzionalmente, il supporto ZIP:

```bash
sudo apt update
sudo apt install php php-zip
```

Se l'host pubblica già WeeWX tramite Apache/PHP, PHP è normalmente già
presente.

## 2. Installa la dashboard

```bash
sudo ./scripts/install.sh /var/www/html/wmr-trace
```

Oppure manualmente:

```bash
sudo install -d -m 0755 /var/www/html/wmr-trace
sudo install -m 0644 index.php /var/www/html/wmr-trace/index.php
php -l /var/www/html/wmr-trace/index.php
```

## 3. Consenti la lettura dei trace

Controlla prima i permessi correnti:

```bash
ls -ld /var/log/weewx
ls -l /var/log/weewx/*developer-trace*.jsonl 2>/dev/null
sudo -u www-data head -1 /var/log/weewx/wmr100-developer-trace.jsonl
```

Configurazione consigliata tramite gruppo:

```bash
sudo usermod -aG weewx www-data
sudo chmod 0750 /var/log/weewx
sudo chmod 0640 /var/log/weewx/wmr100-developer-trace.jsonl
sudo chmod 0640 /var/log/weewx/wmr200-developer-trace.jsonl
sudo systemctl restart apache2
```

Adatta i comandi ai trace realmente presenti.

## 4. Apri la dashboard

```text
http://HOST-WEEWX/wmr-trace/
```

La modalità predefinita è Automatica. Usa Manuale quando sono presenti più
trace e vuoi bloccare il monitor su un file specifico.

## 5. Aggiornamento

L'installer salva automaticamente un backup con timestamp dell'eventuale
`index.php` precedente.

```bash
sudo ./scripts/install.sh /var/www/html/wmr-trace
```

Le preferenze Auto/Manuale vengono mantenute dai cookie del browser.
