# XRechnung Bundle

Ein integriertes Bundle mit dem Spezifikationsdokument für den Standard [XRechnung](https://xeinkauf.de/xrechnung/) und unterstützende Komponenten.

## Überblick Bestandteile

| Name                               | Version im Bundle | Kommentar |
|------------------------------------|-------------------|-----------|
| XRechnung Specification            | 3.0.2       |           |
| XRechnung Syntax-Binding           | zu 3.0.2       |           |
| CIUS XRechnung CVD                 | zu 0.9        | initiale Fassung |
| XRechnung Model                    | 2026-01-31      |           |
| Validator                          | 1.6.0       | zusätzlich ist die Version 1.5.0 enthalten |
| XRechnung Validator Konfiguration  | 2026-01-31      |           |
| XRechnung Schematron               | 2.5.0           |           |
| XRechnung Visualization            | 2026-01-31           |           |
| XRechnung Testsuite                | 2026-01-31          |           |

## Änderungen zum letzten Release

### Spezifikation

keine Änderungen

Details siehe Anhang C. Versionshistorie der Spezifikation XRechnung 3.0.2

### Syntax-Binding

keine Änderungen

Details siehe Versionshistorie des Syntaxbinding XRechnung 3.0.2

### CIUS XRechnung CVD

initiale Fassung 0.9 - keine Änderungen

Die CIUS XRechnung CVD basiert auf der CIUS XRechnung. Sie wurde im Auftrag des Bundesministeriums für Verkehr (BMV) von der KoSIT entwickelt und dient ausschließlich der Unterstützung des Reportings im Rahmen der Clean Vehicle Directive (CVD).
Die CIUS XRechnung CVD genügt allen Anforderungen der XRechnung und EN 16931-1.

### XRechnung Model

Erstmalige Veröffentlichung 2026-01-31

SeMoX Modelle der CIUS XRechnung, Extension XRechnung und CIUS XRechnung CVD.

Details siehe README.md des XRechnung Model 2026-01-31

### Validator

#### Version 1.6.0

Die Version 1.6.0 ist mit aktuellen Java Virtual Machines nutzbar. Mit der Version 1.6.0 wurde die Unterstützung von Java 8 eingestellt.

Details siehe: https://github.com/itplr-kosit/validator/releases/tag/v1.6.0

#### Version 1.5.0

Die Version 1.5.0 ist mit Java 8 nutzbar. 

### Validator Konfiguration XRechnung

* Jetzt mit CEN Schematron 1.3.15, XRechnung Schematron 2.5.0 und Testsuite 2026-01-31
* Das Benennungsschema der Distributionsdatei wurde geändert 

Details siehe: https://projekte.kosit.org/xrechnung/validator-configuration-xrechnung/-/releases/v2026-01-31

### XRechnung Schematron Regeln

* Die Schematron-Implementierung wurde von ISO Schematron zu SchXslt umgestellt
* PEPPOL-EN16931-R120 in CII hinzugefügt
* Korrektur von BR-TMP-3
* Korrektur von BR-DE-25 in CII

Hinweis: Die Regel PEPPOL-EN16931-R120 in CII wurde zunächst mit dem Fehlerlevel "Warning" implementiert und wird mit einem zukünftigen Release auf "Error" verschärft werden.

Details siehe: https://projekte.kosit.org/xrechnung/xrechnung-schematron/-/releases/v2.5.0

### XRechnung Visualisierung

* verschiedene Bugfixes

Details siehe: https://projekte.kosit.org/xrechnung/xrechnung-visualization/-/releases/v2026-01-31

### XRechnung Testsuite

* geringfügige Anpassungen

Details siehe: https://projekte.kosit.org/xrechnung/xrechnung-testsuite/-/releases/v2026-01-31

## Bundle Bestandteile Details

### Validator (Prüftool)

Das Prüftool ist ein Programm, welches XML-Dateien (Dokumente) in Abhängigkeit von ihren Dokumenttypen gegen verschiedene Validierungsregeln (XML Schema und Schematron) prüft und das Ergebnis zu einem Konformitätsbericht (Konformitätsstatus *valid* oder *invalid*) mit einer Empfehlung zur Weiterverarbeitung (*accept*) oder Ablehnung (*reject*) aggregiert. Mittels Konfiguration kann bestimmt werden, welche der Konformitätsregeln durch ein Dokument, das zur Weiterverarbeitung empfohlen (*accept*) wird, verletzt sein dürfen.

Das Prüftool selbst ist fachunabhängig und kennt weder spezifische Dokumentinhalte noch Validierungsregeln. Diese werden im Rahmen einer Prüftool-Konfiguration definiert, welche zur Anwendung des Prüftools erforderlich ist.

Weitere Details auf der [Validator Projektseite](https://github.com/itplr-kosit/validator).

### Validator Konfiguration XRechnung

Eine eigenständige Konfiguration für den Standard [XRechnung](https://xeinkauf.de/xrechnung/) wird auf [GitLab bereitgestellt](https://github.com/itplr-kosit/validator-configuration-xrechnung) ([Releases](https://projekte.kosit.org/xrechnung/validator-configuration-xrechnung/-/releases)). Diese enthält alle notwendigen Ressourcen zu der Norm EN16931 (XML-Schema und [Schematron Regeln](https://github.com/ConnectingEurope/eInvoicing-EN16931) u.a.) und die [XRechnung Schematron Regeln](https://projekte.kosit.org/xrechnung/xrechnung-schematron) in ihren aktuellen Versionen.

Weitere Details auf der [Validator Konfiguration XRechnung Projektseite](https://projekte.kosit.org/xrechnung/validator-configuration-xrechnung).

### XRechnung Schematron Regeln

Technische Implementierung der Geschäftsregeln des Standards [XRechnung](https://xeinkauf.de/xrechnung/) in Schematron Rules für XML Validierung.

Weitere Details auf der [XRechnung Schematron Regeln Projektseite](https://projekte.kosit.org/xrechnung/xrechnung-schematron).

### XRechnung Visualisierung

XSL Transformatoren für die Generierung von HTML Web-Seiten und PDF Dateien.

Diese zeigen den Inhalt von elektronischen Rechnungen an, die dem Standard [XRechnung](https://xeinkauf.de/xrechnung/) entsprechen.

Weitere Details auf der [XRechnung Visualisierung Projektseite](https://projekte.kosit.org/xrechnung/xrechnung-visualization).

### XRechnung Testsuite

Valide Testdokumente des Standards [XRechnung](https://xeinkauf.de/xrechnung/).

Diese dienen dazu, bei Organisationen, die IT-Fachverfahren herstellen und betreiben, das Verständnis der [XRechnung-Spezifikation](https://xeinkauf.de/xrechnung/versionen-und-bundles/) zu fördern, indem die umfangreichen und komplexen Vorgaben und Besonderheiten der Spezifikation durch valide Testdokumente veranschaulicht werden. Die Testdokumente stehen zur freien Verfügung für die Einbindung in eigene Testverfahren.

Weitere Details auf der [XRechnung Testsuite Projektseite](https://projekte.kosit.org/xrechnung/xrechnung-testsuite).

### XRechnung Model

SeMoX-Modelle des Standards [XRechnung](https://xeinkauf.de/xrechnung/).

Diese bilden die semantischen Datenmodelle der CIUS und Extension XRechnung sowie der CIUS XRechnung CVD strukturiert in XML ab.
Sie dienen als Basis zur Erzeugung der Spezifikationsdokumente.

Weitere Details auf der [SeMoX Projektseite](https://projekte.kosit.org/semox/semox-model/) und unter [https://semo-xml.org](https://semo-xml.org).