# Pubblicazione GitHub — procedura semplice

Per questo repository non è necessario mantenere un branch `develop` permanente finché le modifiche restano occasionali e facilmente verificabili.

## Flusso normale

1. Modificare i file necessari.
2. Caricarli su `main`.
3. GitHub Actions esegue automaticamente il workflow **Validate**.
4. Verificare che i due job PHP risultino verdi.
5. Se la modifica cambia il comportamento della dashboard, aggiornare anche:
   - `README.md`
   - `README-IT.md`
   - `CHANGELOG.md`
   - numero versione in `index.php`

Per una modifica solo documentale non è necessario incrementare la versione applicativa.

## Ruleset consigliato per main

Per mantenere il repository semplice:

```text
Target: main

Restrict deletions      ON
Block force pushes      ON
```

`Require a pull request before merging` può rimanere OFF finché il progetto è gestito da un singolo maintainer e si preferisce il commit diretto.

Se in futuro il numero di modifiche o contributori cresce, si potrà introdurre:

```text
develop
feature/*
fix/*
```

e richiedere Pull Request verso `main`.

## Controllo locale prima del caricamento

```bash
php -l index.php
```

Per controllare tutti i PHP:

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Aggiornamento della versione

Quando si crea una nuova versione funzionale, per esempio `v2.9`, aggiornare coerentemente:

```text
index.php
README.md
README-IT.md
CHANGELOG.md
.github/workflows/validate.yml
```

Nel workflow cambiare:

```text
expected="v2.8"
```

nel nuovo valore.

## Release

Una GitHub Release è utile per versioni funzionali consolidate, ma non è obbligatoria per piccoli aggiornamenti documentali.

Per una release applicativa:

```text
Tag: v2.x
Release title: WMR Universal Developer Trace Dashboard v2.x
```

Allegare eventualmente uno ZIP contenente `index.php` e la documentazione.
