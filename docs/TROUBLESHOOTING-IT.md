# Risoluzione problemi

## "Trace non leggibile"

Verifica che il file esista e che l'utente PHP/web server possa leggerlo:

```bash
sudo -u www-data head -1 /var/log/weewx/wmr100-developer-trace.jsonl
sudo -u www-data head -1 /var/log/weewx/wmr200-developer-trace.jsonl
```

## La modalità Manuale torna su Automatico

La v2.6 include la persistenza della sorgente introdotta nella v2.5. Se il
browser blocca i cookie per il sito, la preferenza non può essere mantenuta.
Consenti i cookie same-site per la dashboard.

## I valori WMR200 sembrano non aggiornarsi correttamente

La v2.6 eredita lo stato AJAX incrementale introdotto nella v2.4. Verifica che
`/tmp` sia scrivibile dal processo PHP, perché la dashboard vi mantiene la
cache live.

```bash
sudo -u www-data touch /tmp/wmr-dashboard-test && sudo rm /tmp/wmr-dashboard-test
```

## Il bundle ZIP non è disponibile

Installa il supporto ZIP di PHP:

```bash
sudo apt install php-zip
sudo systemctl restart apache2
```

## Previsione assente su WMR100/WMR88

La dashboard necessita degli eventi pressione `packet_valid` nel developer
trace. Nel driver hardened abilita:

```ini
developer_trace = true
developer_trace_packets = true
developer_trace_raw_reports = false
```
