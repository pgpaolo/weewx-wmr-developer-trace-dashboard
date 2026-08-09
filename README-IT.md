# WMR Universal Developer Trace Dashboard v2.7

Correzione mirata alla rilevazione metadati WMR100/WMR88/WMR88A dopo la rotazione del developer trace.

La dashboard continua a supportare WMR100/WMR88/WMR88A e WMR200 con rilevamento automatico o selezione manuale.

## Correzione v2.7

Nelle versioni precedenti alcune informazioni WMR100 (versione driver, modello, VID/PID, endpoint e profilo) dipendevano dalla presenza dell'evento `driver_start` nel file JSONL attivo. Dopo la rotazione, `driver_start` poteva trovarsi nel backup `.1` e l'intestazione mostrava `sconosciuta`, `?:?` o `N/D`.

La v2.7:
- legge `driver_version` e `model` da ogni evento WMR100;
- cerca il più recente `driver_start` nel file attivo e nei backup ruotati solo per recuperare i metadati di configurazione;
- non somma questi backup ai contatori se l'opzione `Backup ruotati` è disattivata;
- invalida automaticamente la cache live precedente.

Sostituire semplicemente `index.php`, quindi usare Ctrl+F5.

## Novità v2.8

La v2.8 è allineata al driver WMR100/WMR88 `3.5.6-gp6`:

- usa `forecastIcon` direttamente dal `loop_packet` quando fornito dal driver gp6;
- mantiene il fallback sul pacchetto pressione raw `0x46` per gp5 e versioni precedenti;
- mostra separatamente `Pressione stazione / assoluta` e `Pressione relativa console / SLP`;
- non identifica più la pressione relativa console come `altimeter`;
- per WMR200 mantiene invariato l'altimetro fornito dal relativo driver.

La previsione WMR100/WMR88 è un codice nativo trasmesso dalla console nel pacchetto pressione `0x46`; il monitor non la calcola da pressione o trend meteorologici.
