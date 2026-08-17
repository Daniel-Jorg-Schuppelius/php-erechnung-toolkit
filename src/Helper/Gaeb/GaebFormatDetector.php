<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebFormatDetector.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Helper\Gaeb;

use ERechnungToolkit\Enums\{GaebFormat, GaebPhase};

/**
 * Which of the three GAEB families a file belongs to, and which exchange phase
 * it carries.
 *
 * The extension alone is not enough - it is chosen by whoever exported the file
 * and is wrong often enough. The content decides: GAEB DA XML declares its
 * namespace, GAEB 2000 marks its objects with `#begin[`, and GAEB 90 is the
 * fixed 80 character grid whose last six columns hold a gapless record number.
 * The DA11 quantity survey shares that grid but not the record number, so it is
 * recognised by its data type before the grid is even looked at. The extension
 * only breaks ties and supplies the phase where the content does not.
 */
final class GaebFormatDetector {
    /** @return array{format: GaebFormat, phase: ?GaebPhase, phaseCode: ?string} */
    public function detect(string $content, ?string $fileName = null): array {
        $format = $this->format($content);
        $phaseCode = $this->phaseCode($content, $format) ?? $this->phaseFromName($fileName);

        return [
            'format' => $format,
            'phase' => GaebPhase::fromCode($phaseCode),
            'phaseCode' => $phaseCode,
        ];
    }

    public function format(string $content): GaebFormat {
        $head = substr($content, 0, 4096);

        if (str_contains($head, 'gaeb.de/GAEB_DA_XML') || str_contains($head, '<GAEB')) {
            return GaebFormat::DaXml;
        }

        if (str_contains($head, '#begin[') || str_contains($head, '[Zeichensatz]')) {
            return GaebFormat::Gaeb2000;
        }

        // Die DA11 wird zuerst geprüft: Sie teilt sich zwar das 80-Zeichen-
        // Raster mit GAEB 90, führt an dessen Ende aber keine fortlaufende
        // Satznummer - die Rasterprüfung würde sie deshalb verwerfen.
        if ($this->looksLikeDa11($content)) {
            return GaebFormat::Da11;
        }

        return $this->looksLikeFixedGrid($content) ? GaebFormat::Gaeb90 : GaebFormat::Unknown;
    }

    /**
     * A DA11 file consists of the header record and lines of data type 11. The
     * bill of quantity formats use other types, so the first content line is
     * decisive.
     */
    private function looksLikeDa11(string $content): bool {
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (str_starts_with($line, '00')) {
                // Der Kopfsatz nennt die Verfahrensbeschreibung an Stelle 11.
                return str_starts_with(trim(mb_substr($line, 10, 6)), '23.003');
            }

            return str_starts_with($line, '11');
        }

        return false;
    }

    /**
     * GAEB 90 is a line of exactly 80 characters whose columns 75 to 80 carry a
     * running record number. Two consecutive lines with consecutive numbers are
     * enough - a text file rarely does that by accident.
     */
    private function looksLikeFixedGrid(string $content): bool {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $previous = null;

        foreach ($lines as $line) {
            if (strlen($line) !== 80) {
                continue;
            }
            $number = substr($line, 74, 6);
            if (!ctype_digit($number)) {
                continue;
            }

            $current = (int) $number;
            if ($previous !== null && $current === $previous + 1) {
                return true;
            }
            $previous = $current;
        }

        return false;
    }

    /** Phase from the content: the namespace in XML, the opening record in GAEB 90. */
    private function phaseCode(string $content, GaebFormat $format): ?string {
        if ($format === GaebFormat::DaXml) {
            if (preg_match('#GAEB_DA_XML/DA([0-9A-Za-z.]+)/#', $content, $matches) === 1) {
                return $matches[1];
            }
            if (preg_match('#<DP>\s*([0-9A-Za-z]+)\s*</DP>#', $content, $matches) === 1) {
                return $matches[1];
            }

            return null;
        }

        if ($format === GaebFormat::Gaeb90) {
            // Opening record: line type 00, the phase follows in columns 3 to 4.
            if (preg_match('/^00\s*(\d{2})/m', $content, $matches) === 1) {
                return $matches[1];
            }
        }

        // Die DA11 kennt nur eine Phase - sie ist die Mengenermittlung.
        if ($format === GaebFormat::Da11) {
            return GaebPhase::QuantitySurvey->value;
        }

        return null;
    }

    /** Last resort: the extension, e.g. `.x84`, `.d83`, `.p86`. */
    private function phaseFromName(?string $fileName): ?string {
        if ($fileName === null) {
            return null;
        }

        return preg_match('/\.[xdpXDP]([0-9]{2}[A-Za-z]?)$/', $fileName, $matches) === 1
            ? strtoupper($matches[1])
            : null;
    }
}
