# GAEB — Datenaustausch am Bau

Umsetzung des **GAEB-Datenaustauschs** (Gemeinsamer Ausschuss Elektronik im
Bauwesen) im Toolkit: Leistungsverzeichnisse, Angebote, Aufträge, Abrechnung und
Mengenermittlung über alle drei Formatfamilien.

## Motivation

GAEB ist im Bauwesen das, was XRechnung in der Fakturierung ist: der
verbindliche Austauschweg zwischen Auftraggeber, Ausschreibungsstelle, Bieter
und ausführendem Unternehmen. Öffentliche Auftraggeber schreiben ihn über die
Vergabehandbücher (VHB/HVA) vor; die Kette **Leistungsverzeichnis → Angebot →
Auftrag → Aufmaß → Abrechnung** läuft vollständig über GAEB-Dateien.

Anders als bei XRechnung gibt es nicht _ein_ Format, sondern drei Generationen,
die im Feld **gleichzeitig** in Gebrauch sind — ältere Vergabesoftware liefert
bis heute GAEB 90.

## Die drei Formatfamilien

| Familie         | Dateiendung    | Aufbau                                    | Enum                   |
| --------------- | -------------- | ----------------------------------------- | ---------------------- |
| **GAEB DA XML** | `.X8x`, `.X3x` | XML, schemavalidierbar (3.1 / 3.2 / 3.3)  | `GaebFormat::DaXml`    |
| **GAEB 2000**   | `.p8x`         | Satzorientiert, Zeilenart + feste Spalten | `GaebFormat::Gaeb2000` |
| **GAEB 90**     | `.d8x`         | Satzorientiert, 80 Zeichen je Satz        | `GaebFormat::Gaeb90`   |
| **DA11**        | `.d11`         | Mengenermittlung, 80 Zeichen je Satz      | `GaebFormat::Da11`     |

Die **DA11** ist die Mengenermittlung der GAEB-90-Welt und die direkte
Vorfahrin der X31: Ab Stelle 13 ist ihre Zeile Zeichen für Zeichen derselbe
Satz, den die X31 in ihrem `QTakeoff`-Attribut trägt. Beide Formate teilen sich
deshalb den Satzleser — was in einem stimmt, stimmt im anderen.

Die Familie wird **aus dem Inhalt** bestimmt, nicht aus der Endung
([`GaebFormatDetector`](../../src/Helper/Gaeb/GaebFormatDetector.php)) — Dateien
werden in der Praxis umbenannt, weitergeleitet und in ZIPs gepackt. Die Codepage
(CP850, CP1252, UTF-8) wird ebenfalls erkannt.

> **Spaltenformate immer mit `mb_substr` lesen.** Ein Umlaut in einem Textfeld
> ist in CP850 ein Byte, nach der Umwandlung nach UTF-8 aber zwei — mit `substr`
> verschiebt sich ab dem ersten „ß" jede Folgespalte. Das ist im Toolkit
> mehrfach als Fehler aufgetreten und in allen Satzparsern korrigiert.

## Austauschphasen

Die Phase steht in der Datei bzw. in der Endung und bestimmt, **welche Felder
gefüllt sein müssen**. [`GaebPhase`](../../src/Enums/GaebPhase.php) kennt 22
Phasen und beantwortet die vier Fragen, die den Unterschied ausmachen:

| Phase          | Bedeutung                          | Texte | Mengen | Preise |
| -------------- | ---------------------------------- | :---: | :----: | :----: |
| `X31`          | Mengenermittlung (REB-Aufmaß)      |   –   |   –    |   –    |
| `X80`          | Kostenanschlag                     |   ✓   |   ✓    |   ✓    |
| `X81`          | Leistungsbeschreibung              |   ✓   |   ✓    |   –    |
| `X82`          | Kostenanschlag mit Mengen          |   ✓   |   ✓    |   ✓    |
| `X83`          | Angebotsaufforderung               |   ✓   |   ✓    |   –    |
| `X84`          | Angebotsabgabe                     |   ✓   |   ✓    |   ✓    |
| `X85`          | Nebenangebot                       |   ✓   |   ✓    |   ✓    |
| `X86`          | Auftragserteilung                  |   ✓   |   ✓    |   ✓    |
| `X87`          | Abrechnung                         |   ✓   |   ✓    |   ✓    |
| `X89` / `X89B` | Nachtrag / Nachtragsangebot        |   ✓   |   ✓    |   ✓    |
| `X83Z`/`X84Z`  | Zeitvertrag (Aufforderung/Angebot) |   ✓   |   ✓    |  (✓)   |
| `X50`–`X52`    | Kostenermittlung nach DIN 276      |   ✓   |   ✓    |   ✓    |
| `X93`–`X97`    | Handel (Preisanfrage bis Rechnung) |   ✓   |   ✓    |   ✓    |

`carriesTexts()`, `carriesQuantities()`, `carriesPrices()` und
`isBillOfQuantity()` steuern damit den Preflight: Ein X84 **ohne** Preise ist
unvollständig, ein X83 **mit** Preisen verrät den Bieterpreis vor Fristende.

### Was welche Phase überhaupt kennt

Die Phasenschemata unterscheiden sich nicht nur in Kleinigkeiten — was in der
einen Pflicht ist, existiert in der anderen gar nicht. Das Enum beantwortet das,
statt es über den Generator zu verteilen:

| Element | X81 | X82 | X83 | X84 | X85 | X86 | X87 | Methode |
| ------- | :-: | :-: | :-: | :-: | :-: | :-: | :-: | ------- |
| `CTR` (Auftragnehmer) | – | – | – | ✓ | ✓ | ✓ | ✓ | `carriesContractor()` |
| `OWN` (Auftraggeber) | – | – | ✓ | – | – | **P** | **P** | `carriesClient()` / `requiresClient()` |
| `COInfo` (Nachtragskopf) | – | – | ✓ | – | – | ✓ | ✓ | `carriesChangeOrderHead()` |
| `CONo` an der Position | ✓ | ✓ | ✓ | – | – | ✓ | ✓ | `carriesItemChangeOrder()` |
| `NotOffered` | – | – | – | ✓ | – | – | – | `carriesNotOffered()` |
| `UP`/`IT`/`Markup` | ✓ | ✓ | – | ✓ | ✓ | ✓ | ✓ | `carriesPrices()` |

**P** = Pflicht. Vor der Angebotsabgabe ist der Bieter unbekannt, deshalb kennen
die Schemata von X81 bis X83 den `CTR` nicht einmal; umgekehrt ist eine
Auftragserteilung ohne Auftraggeber keine.

Die **Vergabeart** (`Cat`, `GaebAwardCategory`) steht in X83, X86, X87 und den
Zeitvertragsphasen. Elf Werte in 3.3, zehn in 3.2 — die Innovationspartnerschaft
kam später (`existsIn()`). Zwei Paare sehen sich ähnlich und sind es nicht:
`SelectCall`/`SelectCallPostOpen` und `NegProc`/`NegProcOpen` unterscheiden sich
darin, ob ein **Teilnahmewettbewerb** vorausging — das ändert Fristen und
Bieterkreis, ein Zusammenlegen wäre ein Rechtsfehler, keine Vereinfachung. Das
Vokabular ist VOB-zentriert: Verfahren, die die UVgO kennt (Verhandlungsvergabe,
Direktauftrag), haben hier keinen Wert.

**Andere Wurzelblöcke, anderer Aufbau.** Nicht jede Phase hängt unter `Award`:

| Phasen | Wurzelblock | Stand |
| ------ | ----------- | ----- |
| X80–X87, X52 | `Award` | lesen + schreiben |
| X31 | `QtyDeterm` | lesen + schreiben |
| X83Z–X86ZR (Zeitvertrag) | `Award`, **ohne** `PrjInfo` | nur lesen |
| X89/X89B (Rechnung) | `Invoice` | nur lesen |
| X93–X97 (Handel) | `Order` | nur lesen |

Was geschrieben werden kann, beantwortet `GaebPhase::isWritableAsDaXml()`. Wo
es nicht geht, wirft der Schreiber eine Ausnahme mit Begründung, statt eine halb
richtige Vergabedatei abzuliefern — bei einer Ausschreibung ist eine klare
Absage besser als eine Datei, die die Vergabestelle zurückweist.

**Zeitvertrag:** Kopf und Parteien sind vermessen und umgesetzt
(`GaebAwardCategory`, `GaebFrameworkAgreement` mit Laufzeit, Auf-/Abgebot und
Mindestwerten, je Phase eigene `AwardInfo`: 84Z trägt nur ein Datum, 86ZE die
Vertragsnummer). Offen ist die **Positionsebene** — der Zeitvertrag preist
Leistungen *ohne Menge*, sie entsteht erst beim Einzelabruf, und welche Phase
Preise trägt, unterscheidet sich. Der Einzelauftrag (86ZE) verlangt zusätzlich
Stundenlohn-, Material- und Zuschlagssätze aus `IndivAgrInfo`.

**Rechnung (X89/X89B):** `tgInvoice` verlangt `InvoiceHeader` (Nummer, Datum,
Art, Leistungszeitraum), `InvoiceCreator` und `InvoiceRecipient` (je Anschrift +
Steuernummer), `InvoiceShare` (Anteile mit Art, Betrag, Prozentsatz,
Gegenforderung) und `TotalGross` — durchweg Pflicht.

**Handel (X93–X97):** `tgOrder` mit `OrderInfo`, `SupplierInfo`, `CustomerInfo`,
`DeliveryPlaceInfo`, `PlannerInfo`, `InvoiceInfo` und `OrderItem`:
Bestellpositionen statt Gliederung.

Zwei Fallen stecken in den Sequenzen selbst: **Nummer und Status eines
Nachtrags sind eine Pflichtgruppe** (`<xs:sequence minOccurs="0">` mit beiden
Kindern erforderlich) — eine Nachtragsnummer ohne Status ist nicht darstellbar,
weshalb der Schreiber „erkannt" einsetzt, wenn das Dokument keinen nennt. Und
**die Preise stehen vor der Beschreibung**, nicht dahinter; jede Gruppe einer
Preisphase muss außerdem ihre Summe nennen.

> **Fallstrick:** `X31` ist zwar eine Phase, trägt aber **kein** Leistungs­ver­zeichnis
> — deshalb `carriesQuantities() === false`. Als die X31 dem Enum hinzugefügt
> wurde, verlangte der Preflight zunächst Menge und Einheit wie im LV und ließ
> jeden Aufmaß-Import scheitern.
>
> **Codes enden nicht immer auf Ziffern:** `fromCode()` schneidet nur ein
> führendes `X`/`D`/`P` ab — `89B`, `86ZR` und `84Z` behalten ihre Buchstaben.

## Architektur

Lesen und Schreiben laufen je über **eine** Naht, hinter der die Familien
austauschbar sind:

```text
GaebReader                     GaebWriter
 ├── GaebDaXmlParser            ├── GaebDaXmlGenerator
 ├── Gaeb2000Parser             ├── Gaeb2000Generator
 ├── Gaeb90Parser               ├── Gaeb90Generator
 └── Da11Parser  ──┐        ┌── └── Da11Generator
        ↓          └ GaebTakeoffRecord ┘      ↑
              GaebBoq (Entität)
```

- **[`GaebReader`](../../src/Parsers/GaebReader.php)** erkennt Familie und
  Codepage, wählt den Parser und liefert immer eine `GaebBoq` — Aufrufer
  brauchen die Familie nicht zu kennen.
- **[`GaebWriter`](../../src/Generators/GaebWriter.php)** schreibt in die
  gewünschte Zielfamilie und gibt ein **Verlustprotokoll** zurück: GAEB 90 kennt
  keine Katalogzuordnungen, GAEB 2000 keine BIM-GUIDs. Was beim Herunterschreiben
  wegfällt, wird benannt statt still fallengelassen.
- **[`GaebSchemaValidator`](../../src/Validators/GaebSchemaValidator.php)** prüft
  DA-XML gegen die mitgelieferten Schemata (siehe unten).

### Entitäten

| Klasse                        | Trägt                                                         |
| ----------------------------- | ------------------------------------------------------------- |
| `GaebBoq`                     | Kopf, Währung, Parteien, Gliederung, Kataloge                 |
| `GaebSection`                 | Gliederungsstufe (Los, Titel, Gewerk) inkl. `externalId`      |
| `GaebItem`                    | Position: Menge, Einheit, `Money`-Preise, Zuordnungen, Aufmaß |
| `GaebSubDescription`          | Untergliederte Leistungsbeschreibung                          |
| `GaebTextComplement`          | Bieterergänzung (Lücke im Text, vom Bieter zu füllen)         |
| `GaebUpComponent`             | Einheitspreisanteil (Lohn, Material, Gerät, Sonstiges)        |
| `GaebCatalog` / `…Assignment` | Katalog und Zuordnung (siehe DIN 276)                         |
| `GaebQuantitySplit`           | Teilmenge mit eigener Zuordnung                               |
| `GaebTakeoffLine`             | Aufmaßzeile nach REB                                          |
| `GaebTotals`                  | Summenblock                                                   |

### Zuschlagspositionen

Eine Zuschlagsposition trägt keine eigene Leistung, sondern erhöht den Preis
anderer. Welche das sind, sagt `MarkupType` — und davon hängt der Betrag ab, denn
derselbe Satz auf eine andere Grundlage ist ein anderer Betrag:

| Wert | Bemessungsgrundlage |
| ---- | ------------------- |
| `AllInCat` | alle Positionen der Gruppe, in der die Zuschlagsposition steht |
| `IdentAsMark` | Positionen mit gleichem Zuschlagskennzeichen |
| `ListInSubQty` | die unter `MarkupSubQty` aufgeführten Teilmengen |

Nur bei `AllInCat` folgt die Grundlage aus dem Dokumentaufbau
(`derivesBaseFromStructure()`); dort rechnet `GaebCalculator::markupBase()` sie
aus der Gruppe — **ohne die Zuschlagspositionen selbst**, denn ein Zuschlag auf
einen Zuschlag würde sich aufschaukeln. Die beiden anderen Arten nennen ihre
Grundlage im Dokument, sie wird von dort übernommen statt geraten.

In den Auftragsphasen verlangt das Schema beides: `ITMarkup` (die Grundlage) und
`IT` (den Betrag). Ein Satz ohne Grundlage sagt nichts aus.

**Preise sind `Money`** (`CommonToolkit\ValueObjects\Money`) mit Skala 4 — GAEB
rechnet Einheitspreise auf ein Zehntelcent genau, `float` würde beim Aufsummieren
langer Verzeichnisse abweichen.

> **`documentTotal()` summiert direkt über die Positionen**, nicht über die
> Gliederung. Jede `d84` ohne Titelstruktur hat Positionen ohne übergeordnete
> Gruppe — eine Top-down-Summe verlöre sie stillschweigend.

## Katalogzuordnung und DIN 276

Warum werben AVA-Anbieter durchgängig mit „DIN 276 integriert"? Weil GAEB dafür
einen eigenen, **universellen** Mechanismus hat: die Katalogzuordnung
(`CtlgAssign`). Eine Position verweist auf einen Katalog und einen Schlüssel
darin — das trägt gleichermaßen

- die **Kostengruppe nach DIN 276** (Ausgaben 1981 bis 2018, `GaebCatalogType`),
- den **Leistungsbereich** (STLB-Bau),
- **Gebäude/Bauteil**, **Kostenträger**, **Ort**,
- die **BIM-GUID** zur Verknüpfung mit dem Bauwerksmodell.

Zuordnungen hängen an Gliederungsstufen _und_ an Positionen, und über
`QtySplit` auch an **Teilmengen** einer Position: 60 % einer Betonleistung auf
KG 331, 40 % auf KG 333. Genau daraus entsteht die Kostenverfolgung, die
Bauherren erwarten.

Die Phasen **X50–X52** (Kostenermittlung) sind der Träger dieser Sicht: Sie
transportieren die Kostengliederung ohne vollständiges LV.

## Mengenermittlung (REB-VB 23.003)

Aufmaße kommen als **X31** bzw. `.d11`-Sätze und folgen der Verfahrensbeschreibung
**REB-VB 23.003** der Bundesanstalt für Straßenwesen: 80-Zeichen-Sätze, jeder mit
einer **Formelnummer**, Werten in Millimetern und optionalem Adressverweis, unter
dem das Ergebnis für spätere Sätze abrufbar bleibt.

[`GaebTakeoffCalculator`](../../src/Helper/Gaeb/GaebTakeoffCalculator.php) rechnet
den Formelkatalog nach:

| Nr.  | Formel                                   | Nr.  | Formel                           |
| ---- | ---------------------------------------- | ---- | -------------------------------- |
| `00` | Rechenansatz (vorzeichenbehaftete Summe) | `14` | Dreieckspyramidenstumpf          |
| `01` | Dreieck aus Grundseite und Höhe          | `15` | Rechteckpyramidenstumpf          |
| `02` | Dreieck aus zwei Seiten und Winkel       | `20` | Pythagoras                       |
| `03` | Dreieck aus drei Seiten (Heron)          | `21` | Polygonlänge aus Koordinaten     |
| `04` | Rechteck / Quader                        | `22` | Vieleckfläche (Gauß)             |
| `05` | Trapez                                   | `23` | Flächen/Massen aus Querprofilen  |
| `07` | Kreissektor / Zylindersektor             | `25` | Stationierte Trapezprofile       |
| `08` | Kreisringsektor / Hohlzylindersektor     | `30` | Quadratwurzel                    |
| `09` | Parabelsegment                           | `31` | Arithmetisches Mittel            |
| `10` | Tangenteneck                             | `32` | Quadratisches Mittel             |
| `11` | Kegelstumpfsektormantel                  | `91` | Freie Formel (Ausdruck)          |
| `12` | Kegelstumpfsektor                        |      |                                  |
| `13` | Prisma über Dreieck mit drei Höhen       |      |                                  |

Fünf Eigenheiten prägen die Umsetzung:

1. **Winkel stehen in Gon**, nicht in Grad — 100 Gon sind der rechte Winkel.
2. **Jede Flächenformel wird mit einem Zusatzwert zur Körperformel.** Ein
   Rechteck mit drittem Wert ist ein Quader; das gilt durchgängig und ist in
   `withHeight()` einmal umgesetzt statt je Formel wiederholt.
3. **Sechs Formeln laufen über mehrere Sätze** bis zu einem abschließenden `=`:
   der Rechenansatz `00`, beide Koordinatenformeln `21`/`22`, die Profilformeln
   `23`/`25` und die freie Formel `91`, deren Ausdruck sich über bis zu 20
   Zeilen erstrecken darf. Sie werden als Gruppe gesammelt und erst beim
   Abschluss gerechnet; eine Gruppe **ohne** `=` wird als unvollständig
   gemeldet.
4. **Das Vorzeichen steht vor seinem Wert**, und weil die Felder rechtsbündig
   sind, liegen Leerzeichen dazwischen (`+ 24300`). Das Vorzeichen des ersten
   Wertes einer Folgezeile steht hinter dem letzten Feld der Zeile davor — der
   Parser hält es als eigenen Eintrag fest, sonst ginge es verloren.
5. **Adressen gelten dokumentweit.** Eine Position darf die Zwischensumme einer
   anderen übernehmen (REB 23.003 Ausgabe 2009: Verweise auf höhere
   Ordnungszahlen sind ausdrücklich erlaubt). Dafür gibt es `document()`, das
   ein ganzes Verzeichnis in Dateireihenfolge rechnet und die Adressen
   fortschreibt — `total()` je Position allein liefe bei solchen Verweisen ins
   Leere.

Die Koordinatenformeln `21`/`22` nehmen eine unbegrenzte Liste von y/z-Paaren;
ein einzelner Wert am Ende ist die Dicke `D` und hebt das Ergebnis eine
Dimension höher (Länge → Fläche, Fläche → Volumen). Die Profilformeln `23`/`25`
sind beides Trapezregeln über eine Stationskette
(`R = |Stᵢ − Stᵢ₋₁| · (Fᵢ + Fᵢ₋₁) / 2`) und unterscheiden sich nur darin, woher
die Fläche einer Station kommt: `23` bekommt sie fertig (meist per Adresse aus
einer vorangegangenen `21`/`22`), `25` rechnet sie als Trapez `(a + b)/2 · h`.

Die freie Formel `91` enthält einen Ausdruck aus einer fremden Datei. Er wird
**geparst, nicht ausgeführt** ([`GaebExpression`](../../src/Helper/Gaeb/GaebExpression.php),
rekursiver Abstieg über die vier Grundrechenarten, Potenz und Klammern) — `eval`
würde einer eingelieferten Datei den Prozess öffnen.

> Der Formelkatalog ist gegen die **BVBS-Prüfdatei zur Mengenermittlung**
> abgeglichen: Alle 105 Rechenzeilen werden gerechnet, keine bleibt übrig. Wo
> eine Formel doch einmal unbekannt ist, meldet der Rechner sie über `skipped` —
> ein falsch geratenes Aufmaß wäre in der Abrechnung teurer als eine fehlende
> Zeile.

### Satzaufbau

Die feste Spaltenaufteilung des DA11-Satzes gilt in beide Richtungen;
[`GaebTakeoffRecord`](../../src/Helper/Gaeb/GaebTakeoffRecord.php) schreibt sie
zurück:

| Spalte (0-basiert) | Inhalt                                                                        |
| ------------------ | ----------------------------------------------------------------------------- |
| 12                 | Kennzeichen (` ` Rechenzeile, `*` Kommentar, `H` Hilfswert, `Z` Zwischensumme) |
| 13–21              | Erläuterung (bei Kommentarzeilen 13–68)                                       |
| 22                 | Vorzeichen des Faktors                                                        |
| 23–28              | Faktor                                                                        |
| 29–30              | Formelnummer                                                                  |
| 33–67              | fünf Wertfelder à 7, rechtsbündig                                             |
| 40–67              | **abweichend bei 21/22:** vier Wertfelder ab Spalte 41                        |
| 69–74              | Adresse                                                                       |

Die Sätze der Prüfdatei überstehen diesen Weg **byteweise** — der schärfste
Nachweis für die Grenzen, weil eine Verschiebung um ein Zeichen dort sofort
auffällt, während sie in der Rechnung lange unbemerkt bliebe.

Die DA11-Datei setzt vor genau diesen Satz ihren eigenen Rahmen:

| Spalte (0-basiert) | Inhalt                                        |
| ------------------ | --------------------------------------------- |
| 0–1                | Datenart (`00` Kopfsatz, `11` Rechenansatzzeile) |
| 2–10               | Ordnungszahl (neun Stellen, das Maximum)      |
| 11                 | Zwischensummen-Index (V)                      |
| 12–79              | der Satz von oben                             |

Der Kopfsatz nennt Verfahren (`23.003`), Ausgabe (`2009`), Überschrift und die
**OZ-Maske**, die den Aufbau der Ordnungszahl beschreibt (`1122PPPPI` =
zwei Hierarchiestufen à 2, Position 4, Index 1). Fehlt die Maske, gilt die
Struktur der Ausgabe 1979. Eine Ordnungszahl über neun Stellen passt nicht
hinein — sie wird **gemeldet**, weil eine gekürzte Nummer auf die falsche
Position zeigt.

## Schema-Validierung

Die Schemata liegen — wie im `php-financial-formats` — flach im Paket unter
[`data/gaeb/xsd/`](../../data/gaeb/xsd/):

| Ausgabe                       | `VersDate` | Verwendung                        |
| ----------------------------- | ---------- | --------------------------------- |
| DA XML 3.1 (2007-11, 2009-12) | je Datei   | Altbestand                        |
| DA XML 3.2                    | `2021-05`  | verbreiteter Regelfall            |
| DA XML 3.3                    | `2021-05`  | aktuell; X31 abweichend `2023-01` |

Flache Ablage ist **zwingend**: Die Schemata nutzen `xs:redefine` mit relativen
Pfaden; eine Unterordnerstruktur bricht die Auflösung. Der Validator ermittelt
die Ausgabe aus dem Namensraum, behandelt den 3.1-Sonderfall (`…/200407`) und
wählt anhand von `VersDate` das passende Schema.

> Beim Erzeugen gilt: `@ID` ist an `tgItem` und `tgBoQCtgy` **Pflicht**
> (`use="required"`), `Cur` gehört in den Kopf, und `LblBoQ`, `OutlCompl`
> sowie `BoQBkdn` dürfen nicht fehlen — sonst ist die Datei nicht schemavalide,
> auch wenn sie fachlich vollständig aussieht.

## Verwendung

```php
use ERechnungToolkit\Parsers\GaebReader;
use ERechnungToolkit\Generators\GaebWriter;
use ERechnungToolkit\Helper\Gaeb\{GaebCalculator, GaebTakeoffCalculator};

// Lesen - Familie und Codepage werden erkannt
$boq = (new GaebReader)->readFile('/pfad/angebot.x84');

echo $boq->getPhase()?->label();
echo (new GaebCalculator)->documentTotal($boq)->format();

// Aufmaß nachrechnen - dokumentweit, damit Adressverweise zwischen
// Positionen aufgehen
foreach ((new GaebTakeoffCalculator)->document($boq) as $reference => $survey) {
    // ['quantity' => 51.3, 'lines' => 4, 'skipped' => [], 'results' => [...]]
}

// Schreiben - Verlust beim Formatwechsel wird protokolliert
$writer = new GaebWriter;
$xml = $writer->write($boq, GaebFormat::DaXml);
foreach ($writer->getLosses() as $loss) {
    // z. B. "Katalogzuordnungen gehen in GAEB 90 verloren"
}
```

## Referenzdateien

Die Konformitätstests laufen gegen echte Dateien (GAEB-Musterdateien,
`gaeb-online.de`, BVBS-Prüfdateien). Sie liegen **außerhalb** des Repos unter
`~/gaeb-referenz/` (überschreibbar per `GAEB_REFERENCE_DIR`), weil ihre
Weitergabe lizenzrechtlich nicht geklärt ist: kostenfrei beziehbar heißt nicht
weiterverteilbar. Fehlen sie, **überspringt** `GaebConformanceTest` die
betroffenen Fälle, statt zu scheitern — die Suite bleibt ohne die Dateien
lauffähig.

**Woher man sie bekommt, steht in [referenzmaterial.md](referenzmaterial.md)**:
Bezugsquellen je Datei, erwartete Ordnerstruktur und
`scripts/fetch-gaeb-reference.sh` für die stabil verlinkbaren Teile. Die
Schemata sind der einzige mitgelieferte Teil — ohne sie könnte das Toolkit
nichts validieren.

## Status / offen

- [x] Formaterkennung (Inhalt + Codepage) über alle drei Familien
- [x] Parser DA XML 3.1–3.3, GAEB 2000, GAEB 90
- [x] Generatoren aller drei Familien + `GaebWriter` mit Verlustprotokoll
- [x] `GaebPhase` (22 Phasen) mit Feldsemantik für den Preflight
- [x] Kataloge, Katalogzuordnungen, Teilmengen (DIN 276, STLB, BIM)
- [x] `Money` für alle Preise, Summen direkt über die Positionen
- [x] XSD-Validierung inkl. Ausgabenwahl über `VersDate`
- [x] REB-Aufmaß: **vollständiger Formelkatalog** (00–15, 20–23, 25, 30–32, 91)
- [x] Mehrzeilige Formelgruppen, dokumentweite Adressverweise, Gon
- [x] Round-Trip GAEB 2000 ohne Verlust
- [x] X31-Export (schemavalide, mengengleich) über `GaebTakeoffRecord`
- [x] DA11-Datei lesen und schreiben (`.d11`) inkl. OZ-Maske und Verlustprotokoll
- [x] Phasengetreues Schreiben X80–X87 (alle acht schemavalide, je Phase geprüft)
- [x] Nachtragskopf `COInfo` (Phase, Ersteller, Begründung, Datum)
- [x] Zuschlagsarten mit Bemessungsgrundlage und Zuschlagsrechnung
- [x] Vergabeart `Cat` als Enum (11 Werte, versionsabhängig, Zeitvertragsfilter)
- [ ] Zeitvertrag **schreiben** (`CnstSite`/`IndivAgrInfo` fehlen im Modell)
- [ ] Rechnung X89/X89B schreiben (`Invoice`: Kopf, Ersteller, Empfänger, Anteile)
- [ ] Handel X93–X97 schreiben (`Order`: Bestellpositionen statt Gliederung)
- [ ] GAEB-90-Feinwerk: Zeilenart 24, Zuschläge, Lose, T0/T1/T9
- [ ] `MarkupType`-Semantiken, `COPhase`/`COInfo` auf Kopfebene
