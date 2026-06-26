# XBestellung / Peppol BIS Order — Umsetzungsplan

Erweiterung des Toolkits um den **Bestellstandard** (XBestellung v1.0 = CIUS auf
Peppol BIS *Order only* 3) als Gegenstück zum bestehenden Rechnungszweig.

## Motivation

Das Toolkit deckt die Rechnungsseite vollständig ab (XRechnung 3.0, ZUGFeRD/
Factur-X, EN 16931, Peppol BIS Billing). Der nächste fachliche Schritt ist die
**Bestellseite**: ein normkonformes UBL-`Order`-Dokument, das eine Beschaffungs-
Pipeline (Bestellvorschlag → Bestellung → Wareneingang) bedient.

XBestellung profiliert die Peppol-`Order`-Transaktion (T01) und nutzt damit
dieselbe UBL-Grammatik wie XRechnung — Party, Adresse, Position, Allowance/Charge
sind strukturgleich und werden wiederverwendet.

## Identifikatoren

| Feld                  | Wert                                            |
| --------------------- | ----------------------------------------------- |
| Wurzelelement / NS    | `Order` / `urn:oasis:names:specification:ubl:schema:xsd:Order-2` |
| `cbc:CustomizationID` | `urn:fdc:peppol.eu:poacc:trns:order:3`          |
| `cbc:ProfileID`       | `urn:fdc:peppol.eu:poacc:bis:order_only:3`      |
| Standard-Kennung      | `urn:xoev-de:kosit:standard:xbestellung.bis-order-only_1.0` |

> **Hinweis zur Validierung:** Die exakte `CustomizationID` für den
> öffentlich-rechtlichen XBestellung-Einsatz ist gegen das offizielle
> KoSIT-Prüftool-Szenario zu bestätigen. Beide URNs sind zentral in
> [`OrderProfile`](../../src/Enums/OrderProfile.php) gekapselt und dort in einer
> Methode anpassbar, ohne den Generator zu berühren.

## Architektur

Format (UBL) und Dokumenttyp (Rechnung/Bestellung) werden entkoppelt:

```
UblSerializer                 (geteilt: Element, Party, Adresse, Allowance, Betrag)
 ├── ERechnungGenerator       (Invoice / CreditNote — delegiert UBL-Bausteine)
 └── OrderGenerator           (Order — UBL Order-2-Hülle + Bestellpositionen)
```

- **`UblSerializer`** hält die geteilten UBL-Bausteine (`cac:Party`,
  `cac:PostalAddress`, `cac:AllowanceCharge`, Element-/Betragsformatierung).
  Beide Generatoren nutzen exakt dieselbe Party-/Adress-Serialisierung — eine
  Quelle der Wahrheit.
- **`ERechnungGenerator`** bleibt verhaltensgleich; die privaten UBL-Helfer
  delegieren nur noch an den `UblSerializer` (durch die bestehende Testsuite
  abgesichert).
- **`OrderGenerator`** baut die `Order`-Hülle (Kopf, Lieferung, Summen) und die
  `cac:OrderLine/cac:LineItem`-Positionen in schemakonformer Elementreihenfolge.

## Rollen in der Bestellung

Anders als in der Rechnung ist der **Käufer** der Dokumentersteller/Absender:

| UBL-Pfad                              | Rolle                       |
| ------------------------------------- | --------------------------- |
| `cac:BuyerCustomerParty/cac:Party`    | Besteller (Absender)        |
| `cac:SellerSupplierParty/cac:Party`   | Lieferant (Empfänger)       |

## Bausteine

| Datei                                          | Zweck                                  |
| ---------------------------------------------- | -------------------------------------- |
| `src/Enums/OrderProfile.php`                   | Profil + Customization-/ProfileID      |
| `src/Entities/Order.php`                       | Bestell-Aggregat (Kopf, Summen)        |
| `src/Entities/OrderLine.php`                   | Bestellposition                        |
| `src/Generators/UblSerializer.php`             | geteilte UBL-Bausteine                 |
| `src/Generators/OrderGenerator.php`            | UBL-Order-Erzeugung                    |
| `src/Builders/OrderBuilder.php`                | Fluent-API                             |
| `src/Parsers/OrderParser.php`                  | UBL-Order → `Order`                    |

## Validierung

`KositValidator` ist szenariengetrieben und format-agnostisch — er validiert die
übergebene XML-Datei gegen die Szenarien in `data/kosit/`. Für XBestellung sind
die offiziellen XBestellung-Prüfartefakte (Schema + Schematron) als zusätzliches
Szenario in `data/kosit/scenarios.xml` einzuhängen; danach prüft derselbe
Validator-Aufruf auch Bestellungen. Solange die Szenarien fehlen, meldet der
Validator „kein Szenario gefunden" — die Erzeugung/das Parsing funktioniert
unabhängig davon.

## Order-X (CII-Order, PDF/A-3)

Neben der UBL-Variante (XBestellung) unterstützt das Toolkit jetzt auch **Order-X**
— das CII-Pendant zur Bestellung, der Order-seitige Bruder von ZUGFeRD/Factur-X:

| Datei                                | Zweck                                          |
| ------------------------------------ | ---------------------------------------------- |
| `src/Enums/OrderXProfile.php`        | BASIC / COMFORT / EXTENDED                      |
| `src/Generators/OrderXGenerator.php` | Order-X CII (SCRDMCCBDACIO, D20B/`:128`)        |
| `src/Generators/OrderXPdfGenerator.php` | Hybrid-PDF/A-3 mit eingebettetem `order-x.xml` |
| `src/Parsers/OrderXParser.php`       | Order-X CII → `Order`                          |

`Order::toOrderXXml()` erzeugt die CII-XML; `OrderXPdfGenerator` bettet sie über
die `invoice_filename`-Option des bestehenden Writers als `order-x.xml` in ein
PDF/A-3 ein. Wurzelelement `rsm:SCRDMCCBDACIOMessageStructure`, Profil-URN
`urn:order-x.eu:1p0:{basic,comfort,extended}`, Business-Process `A1`. Die
ram/qdt/udt-Namespaces sind Version **128** (D20B), nicht 100 (D16B) wie bei der
Rechnung. Der Parser ist gegen das offizielle FeRD/FNFE-MPE-Sample getestet.

## Lieferschein (Despatch Advice)

Das dritte Dokument der Beschaffungskette (**Bestellung → Lieferschein →
Rechnung**) — als UBL **Peppol BIS Despatch Advice 3**:

| Datei                                       | Zweck                                       |
| ------------------------------------------- | ------------------------------------------- |
| `src/Enums/DespatchAdviceProfile.php`       | Peppol BIS Despatch Advice 3                |
| `src/Entities/DespatchAdvice.php` / `DespatchLine.php` | Lieferschein-Aggregat + Position  |
| `src/Generators/DespatchAdviceGenerator.php` | UBL `DespatchAdvice` (reuse `UblSerializer`) |
| `src/Builders/DespatchAdviceBuilder.php`    | Fluent-API                                  |
| `src/Parsers/DespatchAdviceParser.php`      | UBL DespatchAdvice → `DespatchAdvice`        |

Wurzel `DespatchAdvice`, `CustomizationID urn:fdc:peppol.eu:poacc:trns:despatch_advice:3`,
`ProfileID …bis:despatch_advice:3`. Der **Lieferant** ist Absender
(`cac:DespatchSupplierParty`), der **Kunde** Empfänger
(`cac:DeliveryCustomerParty`). Jede `cac:DespatchLine` referenziert über
`cac:OrderLineReference/cbc:LineID` die ursprüngliche Bestellzeile und trägt die
`cbc:DeliveredQuantity` (inkl. optionaler `BackorderQuantity`) — die natürliche
Grundlage für den **Wareneingang-Abgleich** (Bestellzeile ↔ gelieferte Menge).

## Status / offen

- [x] Geteilter `UblSerializer` + Refactor des Rechnungsgenerators
- [x] `OrderProfile`, `Order`, `OrderLine`
- [x] `OrderGenerator` (UBL Order) + `OrderBuilder` + `OrderParser`
- [x] Order-X: `OrderXProfile`, `OrderXGenerator`, `OrderXParser`, `OrderXPdfGenerator`
- [x] Lieferschein: `DespatchAdviceProfile`, `DespatchAdvice`/`DespatchLine`, Generator/Builder/Parser
- [x] Tests (Erzeugung, Builder, Parsing, Roundtrip, echtes Order-X-Sample, Hybrid-PDF)
- [ ] XBestellung-KoSIT-Szenarien bündeln (Prüfartefakte ausstehend)
- [ ] Order-X-Validierung (FeRD/FNFE-Schematron) bündeln
- [ ] Order-X PDF/A-3: spezifische XMP-Extension-Metadaten (Writer-Erweiterung)
- [ ] Order Response / Order Change — out of scope
</content>
</invoke>
