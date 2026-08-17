<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebReader.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Parsers;

use ERechnungToolkit\Entities\Gaeb\GaebBoq;
use ERechnungToolkit\Enums\GaebFormat;
use ERechnungToolkit\Helper\Gaeb\GaebFormatDetector;
use InvalidArgumentException;

/**
 * One entry point for all three GAEB families.
 *
 * Callers rarely know which family they hold: award offices hand out `.d83`,
 * `.p83` and `.x83` for the same tender, and the extension is set by whoever
 * exported the file - the sample file `GAEB2000.d83` is GAEB 2000 despite its
 * name. The family is therefore taken from the content, and the reading is
 * delegated.
 *
 * Encoding is part of the job. GAEB 90 files travel in the DOS code page,
 * GAEB 2000 in Windows-1252, DA XML in UTF-8; text read under the wrong one
 * turns umlauts into rubbish, silently.
 */
final class GaebReader {
    public function __construct(
        private readonly GaebFormatDetector $detector = new GaebFormatDetector,
        private readonly GaebDaXmlParser $xml = new GaebDaXmlParser,
        private readonly Gaeb90Parser $gaeb90 = new Gaeb90Parser,
        private readonly Gaeb2000Parser $gaeb2000 = new Gaeb2000Parser,
    ) {}

    /**
     * Reads a file of any family. `$raw` is the file as it came in - the
     * encoding is applied here, not by the caller.
     */
    public function read(string $raw, ?string $fileName = null): GaebBoq {
        $format = $this->detector->format($raw);
        $content = $this->decode($raw, $format);

        return match ($format) {
            GaebFormat::DaXml => $this->xml->parse($content),
            GaebFormat::Gaeb90 => $this->gaeb90->parse($content),
            GaebFormat::Gaeb2000 => $this->gaeb2000->parse($content),
            GaebFormat::Unknown => throw new InvalidArgumentException(
                'File is not a recognised GAEB document' . ($fileName !== null ? ": {$fileName}" : '.')
            ),
        };
    }

    /** Family of a file without reading it. */
    public function detect(string $raw, ?string $fileName = null): GaebFormat {
        return $this->detector->detect($raw, $fileName)['format'];
    }

    /**
     * Content as UTF-8. A file that already is UTF-8 stays untouched - the code
     * page of the family only applies to what predates it.
     */
    public function decode(string $raw, GaebFormat $format): string {
        if ($format === GaebFormat::DaXml || mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        $from = $format === GaebFormat::Gaeb2000 ? 'CP1252' : 'CP850';

        return mb_convert_encoding($raw, 'UTF-8', $from);
    }
}
