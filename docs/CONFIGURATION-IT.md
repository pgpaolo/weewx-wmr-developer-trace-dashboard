# Configurazione

Nella maggior parte delle installazioni non è necessario modificare
`index.php`.

Costanti principali all'inizio del file:

- `TRACE_CANDIDATES`: trace considerati dal rilevamento automatico.
- `MANUAL_TRACE_ALLOWED_ROOTS`: directory ammesse nella selezione manuale.
- `TRACE_BACKUPS`: numero massimo di trace ruotati considerati dalla UI.
- `LOCAL_TIMEZONE`: timezone visualizzata.
- `RECENT_HEALTH_WINDOW`: finestra temporale usata per lo stato corrente.
- `DEFAULT_LIVE_REFRESH`: intervallo AJAX predefinito.
- `LIVE_CACHE_ROOT`: directory della cache incrementale dello stato live.

Trace predefiniti:

```text
/var/log/weewx/wmr100-developer-trace.jsonl
/var/log/weewx/wmr200-developer-trace.jsonl
/tmp/wmr200-developer-trace.jsonl
```

## Modalità manuale

La modalità Manuale è persistente nel browser e ricorda:

- modalità sorgente Automatico / Manuale;
- famiglia/parser selezionato;
- percorso del trace manuale.

Le preferenze non contengono contenuti del trace o credenziali.

## Bundle diagnostici

Se PHP dispone dell'estensione ZIP, il bundle viene prodotto come archivio ZIP.
In assenza dell'estensione la dashboard utilizza il formato di fallback previsto.
