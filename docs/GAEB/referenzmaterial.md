# GAEB-Referenzmaterial beschaffen

Der Konformitätstest
([`tests/Validators/GaebConformanceTest.php`](../../tests/Validators/GaebConformanceTest.php))
prüft Leser, Schreiber und Rechenwerk gegen **echte** GAEB-Dateien: amtliche
Prüfdateien, Musterdateien und Fachdokumentationen.

Dieses Material liegt **nicht im Repository**. Es stammt von Dritten (GAEB,
BVBS, BASt), und seine Weitergabe ist nicht durchweg geklärt — kostenfrei
beziehbar heißt nicht weiterverteilbar. Fehlt es, **überspringt** der Test die
betroffenen Fälle, statt zu scheitern; die Suite bleibt also auch ohne grün.

## Wo es erwartet wird

Standardpfad ist `~/gaeb-referenz`, überschreibbar per Umgebungsvariable:

```bash
export GAEB_REFERENCE_DIR=/pfad/zum/material
```

Erwartete Struktur — der Test greift über Glob-Muster auf diese Ordner zu:

| Ordner | Inhalt | Wofür der Test es braucht |
| --- | --- | --- |
| `pruefdateien/` | amtliche Prüfdateien (ZIP) | Schemavalidierung, Aufmaß-Round-Trip |
| `pruefdateien/extracted/` | **entpackte** Prüfdateien | dorthin entpacken, der Test liest nur hier |
| `musterdateien/` | GAEB-Musterdateien 3.1 + Zeitvertragsbeispiele 3.2 | Lesen und Schemavalidierung |
| `gaeb90/` | GAEB-90-Testdateien (`.d81`–`.d86`) | Satzraster, Positionszahl aus dem Abschlusssatz |
| `doku/` | Fachdokumentationen, REB-VB | Nachschlagewerk, nicht vom Test gelesen |
| `schemas/` | Schema-Pakete (ZIP) | Quelle für [`data/gaeb/xsd/`](../../data/gaeb/xsd/) |

> **`realdaten/` ist tabu.** Wer dort echte Kundendateien ablegt, sollte sie
> nirgendwohin kopieren, wo sie versehentlich mitwandern — weder ins Repo noch
> in einen Bugreport. Der Test rührt diesen Ordner nicht an.

## Bezugsquellen

Alle folgenden Quellen sind kostenfrei zugänglich; der Bezug erfolgt selbst.

### Direkt herunterladbar

| Datei | Quelle |
| --- | --- |
| REB-VB 23.003, Ausgabe 2009 (Formelsammlung, Satzaufbau DA11) | <https://www.bast.de/DE/Publikationen/Regelwerke/Verkehrstechnik/V-REB-VB/REB-VB-23-003-Ausgabe-2009.pdf> |
| Fachdokumentation GAEB DA XML 3.3, Ausgabe 2021-05 | <https://www.gaeb.de/wp-content/uploads/2021/07/Fachdokumentation_GAEB-DA-XML_3.3_2021-05.pdf> |
| Fachdokumentation GAEB DA XML 3.3, Ausgabe 2023-01 | <https://www.gaeb.de/wp-content/uploads/2023/09/Fachdokumentation_GAEB-DA-XML_3.3_2023-01.pdf> |
| Prüfkriterien GAEB DA XML 3.3 Bauausführung (04.04.2024) | <https://www.bvbs.de/wp-content/uploads/2024/05/Pruefkriterien-GAEB-DA-XML-3.3-Bauausfuehrung-V-04-04-2024.pdf> |
| „Das Freie GAEB Buch" (GAEB 90/2000: Zeilenarten, Felder) | <https://www.bvbs.de/wp-content/uploads/2018/07/Das-Freie-GAEB-Buch.pdf> |

`scripts/fetch-gaeb-reference.sh` holt genau diese fünf.

### Über eine Auswahlseite

Diese lassen sich nicht stabil verlinken — die Dateinamen wechseln mit jeder
Ausgabe. Sie stammen von den folgenden Seiten und gehören in den genannten
Ordner:

| Material | Seite | Ziel |
| --- | --- | --- |
| Schemata (LV, Handel, Rechnung, Zeitvertrag, Kosten/Kalkulation), Musterdateien 3.1, Zeitvertragsbeispiele 3.2 | <https://www.gaeb.de/de/service/downloads/gaeb-datenaustausch/> | `schemas/`, `musterdateien/` |
| BVBS-Prüfdateien aller Bereiche (AVA, Bauausführung, Mengenermittlung, Texterstellung) | <https://www.bvbs.de/zertifizierungen/> | `pruefdateien/` |
| GAEB-90-Testdateien `test.d81`–`test.d86`, `GAEB2000.d83` | <https://www.gaeb-online.de/gaeb-download.html> | `gaeb90/` |
| „Das Freie REB Buch" (REB-Praxis, DA11-Muster) | <https://www.mwm.de/> (Downloadbereich) | `doku/` |

Nach dem Download die Prüfdateien entpacken — der Test liest ausschließlich
`pruefdateien/extracted/`:

```bash
cd "${GAEB_REFERENCE_DIR:-$HOME/gaeb-referenz}/pruefdateien"
mkdir -p extracted && for z in *.zip; do unzip -o -j "$z" -d extracted; done
```

## Schemata ins Paket übernehmen

Die Schemata sind der einzige Teil, der **mitgeliefert** wird
([`data/gaeb/xsd/`](../../data/gaeb/xsd/), ~2,6 MB) — ohne sie könnte das
Toolkit nichts validieren. Sie liegen dort **flach**: Die Schemata nutzen
`xs:redefine` mit relativen Pfaden, eine Unterordnerstruktur bricht die
Auflösung. Beim Nachziehen einer neuen Ausgabe also entpacken und die `.xsd`
ohne Verzeichnisse ablegen.

## Prüfen, ob es reicht

```bash
composer test -- --filter GaebConformance
```

Übersprungene Fälle nennen den fehlenden Ordner. Läuft alles durch, sind
Formatschicht und Rechenwerk gegen das amtliche Material abgesichert.
