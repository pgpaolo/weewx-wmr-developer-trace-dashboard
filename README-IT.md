# WMR Universal Developer Trace Dashboard

[English](README.md)

Dashboard PHP standalone per i developer trace JSONL prodotti dai driver
hardened WeeWX per le stazioni meteo Oregon Scientific WMR.

**Versione corrente: v2.6**

## Famiglie supportate

| Famiglia | Modelli | Trace |
|---|---|---|
| Protocollo WMR100 | WMR100, WMR100N, WMR88, WMR88A, WMR180, WMR180A, WMRS200 | `wmr100-developer-trace.jsonl` |
| Protocollo WMR200 | WMR200, WMR200A e varianti W200 compatibili | `wmr200-developer-trace.jsonl` |

La dashboard è progettata per i **driver hardened che generano il developer
trace JSONL**. I driver WeeWX originali non producono questi trace per default.

## Funzioni principali

- Riconoscimento automatico del trace WMR100/WMR88 oppure WMR200.
- Switch persistente **Automatico / Manuale** per la sorgente del trace.
- Selezione manuale del file con controllo delle directory consentite.
- Aggiornamento live AJAX con stato incrementale e gestione della rotazione WMR200.
- Stato operativo corrente separato dai problemi storici.
- Diagnostica timeout USB, recovery, soft reinitialisation e reopen.
- Controllo checksum, frame malformati e resincronizzazione del parser.
- Valori meteo live e intervalli di ricezione dei pacchetti.
- Pannello canali RF solo per WMR100/WMR88; nascosto automaticamente su WMR200.
- Codice previsione console, descrizione e icona SVG integrata.
- Export CSV filtrato e bundle diagnostico scaricabile.
- Lettura opzionale dei trace ruotati.
- Nessun database e nessuna dipendenza JavaScript/iconografica esterna.

## Previsione console

Per la famiglia WMR100/WMR88 la v2.6 può ricavare il codice di previsione
direttamente dai pacchetti pressione validi `0x46`. La previsione è quindi
visibile anche se il driver non espone ancora `forecastIcon` nel LOOP WeeWX.

Per WMR200/WMR200A viene utilizzato il campo previsione prodotto dal driver
hardened WMR200.

## Requisiti

- Linux / Raspberry Pi o altro host WeeWX.
- PHP 8.1 o superiore consigliato.
- Apache, nginx + PHP-FPM o altro web server con PHP.
- Permessi di sola lettura sul trace JSONL selezionato.
- `php-zip` opzionale per generare bundle diagnostici ZIP.

## Installazione rapida

```bash
unzip weewx-wmr-developer-trace-dashboard-v2.6.zip
cd weewx-wmr-developer-trace-dashboard-v2.6
sudo ./scripts/install.sh /var/www/html/wmr-trace
```

Apri quindi:

```text
http://HOST-WEEWX/wmr-trace/
```

L'installer distribuisce soltanto la dashboard web: non modifica driver WeeWX,
`weewx.conf`, regole USB o permessi dei log.

Per la configurazione di produzione consulta
[Installazione](docs/INSTALL-IT.md) e
[Configurazione](docs/CONFIGURATION-IT.md).

## Trace predefiniti

```text
/var/log/weewx/wmr100-developer-trace.jsonl
/var/log/weewx/wmr200-developer-trace.jsonl
/tmp/wmr200-developer-trace.jsonl
```

La modalità manuale accetta file `.jsonl` leggibili sotto le directory definite
in `MANUAL_TRACE_ALLOWED_ROOTS`. Nella configurazione fornita:

```text
/var/log/weewx
/tmp
```

## Permessi consigliati in produzione

È preferibile una configurazione di sola lettura tramite gruppo rispetto a
`chmod 666`:

```bash
sudo usermod -aG weewx www-data
sudo chmod 0750 /var/log/weewx
sudo chmod 0640 /var/log/weewx/wmr100-developer-trace.jsonl
sudo chmod 0640 /var/log/weewx/wmr200-developer-trace.jsonl
sudo systemctl restart apache2
```

Esegui soltanto i comandi relativi ai trace realmente presenti. Ownership e
rotazione dei file dovrebbero restare sotto il controllo di WeeWX.

## Verifica

```bash
php -l index.php
./scripts/check.sh
```

## Descrizione GitHub consigliata

> Universal PHP diagnostics dashboard for hardened WeeWX Oregon Scientific WMR100/WMR88 and WMR200 developer traces, with auto-detection, live health, protocol analysis and forecast display.

## Topics GitHub consigliati

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

## Licenza

MIT. Vedi [LICENSE](LICENSE).
