#!/usr/bin/env bash
#
# Holt die frei und stabil verlinkbaren Teile des GAEB-Referenzmaterials.
#
# Alles Übrige (Schemata, Prüf- und Musterdateien) liegt hinter Auswahlseiten
# ohne stabile Dateinamen und wird von Hand geholt - siehe
# docs/GAEB/referenzmaterial.md. Dieses Skript lädt nichts nach, was es nicht
# belegen kann, und überschreibt nichts Vorhandenes.

set -euo pipefail

TARGET="${GAEB_REFERENCE_DIR:-$HOME/gaeb-referenz}"
DOCS="$TARGET/doku"

mkdir -p "$DOCS" "$TARGET/pruefdateien/extracted" "$TARGET/musterdateien" \
         "$TARGET/gaeb90" "$TARGET/schemas"

fetch() {
    local name="$1" url="$2"
    if [ -s "$DOCS/$name" ]; then
        echo "  vorhanden: $name"
        return
    fi
    echo "  lade:      $name"
    # -f: HTTP-Fehler nicht als Datei ablegen, -L: Weiterleitungen folgen
    curl -fsSL --retry 2 -o "$DOCS/$name.part" "$url" && mv "$DOCS/$name.part" "$DOCS/$name" \
        || { rm -f "$DOCS/$name.part"; echo "  FEHLER:    $name ($url)" >&2; }
}

echo "Ziel: $TARGET"

fetch "REB-VB-23-003-Ausgabe-2009.pdf" \
    "https://www.bast.de/DE/Publikationen/Regelwerke/Verkehrstechnik/V-REB-VB/REB-VB-23-003-Ausgabe-2009.pdf?__blob=publicationFile&v=1"
fetch "Fachdokumentation_GAEB-DA-XML_3.3_2021-05.pdf" \
    "https://www.gaeb.de/wp-content/uploads/2021/07/Fachdokumentation_GAEB-DA-XML_3.3_2021-05.pdf"
fetch "Fachdokumentation_GAEB-DA-XML_3.3_2023-01.pdf" \
    "https://www.gaeb.de/wp-content/uploads/2023/09/Fachdokumentation_GAEB-DA-XML_3.3_2023-01.pdf"
fetch "pruefkriterien.pdf" \
    "https://www.bvbs.de/wp-content/uploads/2024/05/Pruefkriterien-GAEB-DA-XML-3.3-Bauausfuehrung-V-04-04-2024.pdf"
fetch "freies-gaeb-buch.pdf" \
    "https://www.bvbs.de/wp-content/uploads/2018/07/Das-Freie-GAEB-Buch.pdf"

# Prüfdateien entpacken, falls von Hand abgelegt - der Test liest nur extracted/
shopt -s nullglob
for archive in "$TARGET"/pruefdateien/*.zip; do
    unzip -qo -j "$archive" -d "$TARGET/pruefdateien/extracted"
done

cat <<'NOTE'

Von Hand zu holen (keine stabilen Dateinamen):
  Schemata + Musterdateien  https://www.gaeb.de/de/service/downloads/gaeb-datenaustausch/
  BVBS-Prüfdateien          https://www.bvbs.de/zertifizierungen/
  GAEB-90-Testdateien       https://www.gaeb-online.de/gaeb-download.html

Danach prüfen:  composer test -- --filter GaebConformance
NOTE
