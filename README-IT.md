# WMR Universal Developer Trace Dashboard v2.8

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

**v2.8**

La v2.8 mantiene invariato il comportamento WMR200 e migliora l'interpretazione di pressione e previsione per WMR100/WMR88:

- supporto nativo a `forecastIcon` quando fornito dai driver hardened WMR100/WMR88 più recenti;
- fallback verificato al pacchetto pressione raw `0x46` per trace compatibili più vecchi;
- visualizzazione separata di pressione stazione/assoluta e pressione relativa console / SLP;
- la pressione relativa WMR100/WMR88 non viene più indicata impropriamente come `altimeter`;
- WMR200 continua a usare il valore `altimeter` fornito dal proprio driver;
- schema live-cache portato alla versione 5 per evitare stato obsoleto ereditato dalla v2.7.

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

Il pannello RF/canali viene mostrato per la famiglia WMR100/WMR88 e nascosto per WMR200/WMR200A.

## Pressione e previsione

### WMR100 / WMR88

La scheda Pressione distingue:

- **Pressione stazione / assoluta**
- **Pressione relativa console / SLP**

La sorgente della previsione viene scelta in questo ordine:

1. `forecastIcon` fornito dal driver, quando disponibile;
2. fallback di compatibilità dal pacchetto pressione nativo raw `0x46`.

### WMR200 / WMR200A

Per WMR200 il comportamento rimane invariato: vengono mostrati pressione, `altimeter` e previsione forniti dal developer trace del relativo driver.

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
- parte sui push a `main`, sulle pull request verso `main` e manualmente.

Per ora il progetto può rimanere su un modello semplice con il solo branch permanente `main`. Non è necessario introdurre `develop` finché gli interventi rimangono occasionali e contenuti.

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

Questo aggiornamento documentale non introduce una nuova licenza. Vanno preservati i requisiti di licenza e attribuzione del codice sottostante e degli eventuali componenti upstream.
