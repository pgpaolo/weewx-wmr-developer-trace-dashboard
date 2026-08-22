# WMR Universal Developer Trace Dashboard v2.9.1

[![Validate](https://github.com/pgpaolo/weewx-wmr-developer-trace-dashboard/actions/workflows/validate.yml/badge.svg)](https://github.com/pgpaolo/weewx-wmr-developer-trace-dashboard/actions/workflows/validate.yml)

Dashboard PHP standalone per la diagnostica dei driver hardened WeeWX delle stazioni Oregon Scientific WMR.

Famiglie supportate:

- WMR100 / WMR100N
- WMR88 / WMR88A
- WMR180 / WMR180A
- WMRS200
- WMR200 / WMR200A

La dashboard legge i developer trace JSONL strutturati prodotti dai driver hardened. Può scegliere automaticamente il trace attivo oppure utilizzare esclusivamente un file selezionato manualmente.

> **Strumento diagnostico:** la dashboard è pensata principalmente per troubleshooting e monitoraggio tecnico temporaneo. Non è consigliato esporla permanentemente su Internet senza autenticazione o un livello di controllo degli accessi.

## Versione corrente

**v2.9.1**

La v2.9 introduce la diagnostica universale dei termoigrometri multicanale senza modificare la logica hardened USB/recovery:

- le schede RF/termoigrometri sono disponibili sia per WMR100/WMR88 sia per WMR200/WMR200A;
- WMR100/WMR88 continua a rispettare il limite di canali remoti configurato;
- WMR200/WMR200A supporta in diagnostica i canali da CH0 a CH10;
- ogni canale mostra temperatura, umidità, stato batteria quando disponibile, età del dato, ultima lettura e mapping WeeWX;
- per WMR200 vengono mostrati anche i nomi nativi `temperature_N` / `humidity_N`;
- è supportato in modo forward-compatible il mapping `battery_status_N` per trace per-canale;
- lo schema live-cache passa alla versione 6 per evitare metadati canale obsoleti precedenti alla v2.9.

Il mapping WeeWX standard dei termoigrometri rimane invariato: CH1 corrisponde a `outTemp` / `outHumidity`, CH2 a `extraTemp1` / `extraHumid1`, CH3 a `extraTemp2` / `extraHumid2`, fino a CH8. I canali WMR200 CH9 e CH10 restano visibili a fini diagnostici tramite campi generici quando non sono mappati esplicitamente dallo schema WeeWX.

Il codice di previsione WMR100/WMR88 viene trasmesso dalla console. La dashboard **non calcola** la previsione dalla pressione o dal trend barometrico.

## Sorgenti trace

Il rilevamento automatico controlla i trace noti, tra cui:

```text
/var/log/weewx/wmr100-developer-trace.jsonl
/var/log/weewx/wmr200-developer-trace.jsonl
/tmp/wmr200-developer-trace.jsonl
```

La sorgente viene scelta in base al timestamp dell'evento più recente.

La modalità manuale accetta intenzionalmente soltanto file `.jsonl` leggibili sotto:

```text
/var/log/weewx
/tmp
```

Questa limitazione è parte della superficie di sicurezza e non dovrebbe essere ampliata senza una valutazione specifica.

## Modalità Automatico / Manuale

La pagina dispone dello switch:

```text
Automatico | Manuale
```

In **Automatico**, viene scelto il trace noto più recente.

In **Manuale**, viene usato esclusivamente il file indicato. La modalità sorgente, la famiglia/parser selezionata e il percorso del trace vengono ricordati in cookie same-origin `SameSite=Lax`.

I cookie non contengono credenziali né il contenuto del trace.

## Aggiornamento live

La dashboard mantiene uno stato incrementale durante gli aggiornamenti AJAX invece di ricostruire a ogni refresh tutto lo stato meteorologico da una coda fissa.

Sono gestiti:

- crescita normale del JSONL;
- ultima riga temporaneamente incompleta;
- rotazione o troncamento del trace;
- valori meteo live;
- contatori pacchetti/sessione;
- stato del protocollo;
- warning USB/protocollo recenti;
- pannelli specifici per famiglia.

Il pannello RF/termoigrometri viene mostrato per entrambe le famiglie. WMR100/WMR88 rispetta il limite di canali configurato; WMR200/WMR200A supporta in diagnostica CH0–CH10.

Per impostazione predefinita il pannello mostra soltanto i canali che hanno realmente ricevuto temperatura o umidità. Il comando **Mostra non ricevuti** consente di visualizzare temporaneamente anche i canali assenti/non utilizzati per finalità diagnostiche; la preferenza viene mantenuta nel browser.

Su WMR200/WMR200A lo stato batteria è disponibile come `outTempBatteryStatus` soltanto per il termoigrometro esterno principale. Per i canali T/H aggiuntivi viene quindi mostrato **N/D per canale**, evitando di attribuire uno stato batteria non identificabile dal protocollo.

## Pressione e previsione

### WMR100 / WMR88

La scheda Pressione distingue:

- **Pressione stazione / assoluta**
- **Pressione relativa console / SLP**

La sorgente della previsione viene scelta in questo ordine:

1. `forecastIcon` fornito dal driver, quando disponibile;
2. fallback di compatibilità dal pacchetto pressione nativo raw `0x46`.

### WMR200 / WMR200A

Per WMR200 rimane invariata la gestione di pressione e previsione: vengono mostrati pressione, `altimeter` e previsione forniti dal developer trace del relativo driver.

## Bundle diagnostico

La dashboard può generare un bundle diagnostico limitato agli eventi recenti e corredato da un riepilogo.

Il bundle può contenere dettagli tecnici come:

- versione driver e modello;
- VID/PID USB ed endpoint;
- timestamp;
- motivazioni di errore e contatori;
- contenuto selezionato dei pacchetti;
- percorsi locali dei trace.

**Controllare sempre il bundle prima di pubblicarlo o condividerlo.**

## Installazione

Copia `index.php` nella directory web desiderata.

Esempio:

```bash
sudo cp index.php /var/www/html/wmr-trace/index.php
sudo chown root:root /var/www/html/wmr-trace/index.php
sudo chmod 0644 /var/www/html/wmr-trace/index.php
```

Verifica la sintassi PHP:

```bash
php -l /var/www/html/wmr-trace/index.php
```

Risultato atteso:

```text
No syntax errors detected in /var/www/html/wmr-trace/index.php
```

L'utente del web server deve poter leggere il developer trace attivo.

Esempi:

```bash
sudo -u www-data head -1 /var/log/weewx/wmr100-developer-trace.jsonl
sudo -u www-data head -1 /var/log/weewx/wmr200-developer-trace.jsonl
```

## Configurazione trace consigliata

Per una diagnostica dettagliata dei pacchetti e dei dati live, mantenere abilitato il tracing dei pacchetti nel relativo driver hardened.

Configurazione tipica WMR100/WMR88:

```ini
developer_trace = true
developer_trace_packets = true
```

Configurazione tipica WMR200:

```ini
developer_trace = true
developer_trace_include_packets = true
```

I nomi esatti delle opzioni dipendono dalla versione del driver hardened in uso.

## GitHub

Il repository include un workflow GitHub Actions leggero che:

- esegue il lint PHP;
- verifica la coerenza della versione in `index.php`, `README.md`, `README-IT.md` e `CHANGELOG.md`;
- parte sui push a `main` e `develop`, sulle pull request verso `main` e manualmente.

Le modifiche di sviluppo vengono preparate su `develop`; dopo la validazione possono essere unite a `main`.

## Sicurezza

Leggere [SECURITY.md](SECURITY.md) prima di rendere la pagina raggiungibile da reti non fidate.

Indicazioni principali:

- non lasciare la dashboard permanentemente esposta senza controllo accessi;
- non ampliare arbitrariamente le directory ammesse per il trace manuale;
- verificare i bundle diagnostici prima di condividerli;
- mantenere aggiornati web server e PHP.

## Documentazione

- [README inglese](README.md)
- [Changelog](CHANGELOG.md)
- [Policy di sicurezza](SECURITY.md)
- [Guida pubblicazione GitHub](docs/GITHUB-PUBLISHING-IT.md)

## Licenza

Questo progetto è distribuito con licenza MIT. Vedi [LICENSE](LICENSE).
