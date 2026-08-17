<?php
/*
 * Created on   : Sun Aug 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : Da11Generator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Generators;

use ERechnungToolkit\Entities\Gaeb\GaebBoq;
use ERechnungToolkit\Helper\Gaeb\GaebTakeoffRecord;

/**
 * Writes a DA11 file - the quantity survey as the GAEB 90 world exchanges it.
 *
 * The file is a header record and one line per computed row. Everything from
 * column 13 onwards is the record {@see GaebTakeoffRecord} also writes into the
 * X31, so both formats stay in step by construction.
 */
final class Da11Generator {
    /** Verfahren und Ausgabe, nach denen gerechnet wurde (Kopfsatz). */
    private const PROCEDURE = '23.003';
    private const EDITION = '2009';

    /** Standard-OZ-Struktur des Leistungsverzeichnisses (REB-VB 23.003). */
    public const DEFAULT_MASK = '1122PPPPI';

    private readonly GaebTakeoffRecord $record;

    public function __construct(?GaebTakeoffRecord $record = null) {
        $this->record = $record ?? new GaebTakeoffRecord;
    }

    /**
     * Ordinal numbers that did not fit the nine places of the DA11. Read after
     * {@see generate()} - the numbers were shortened, and a shortened ordinal
     * number points at the wrong item.
     *
     * @var list<string>
     */
    private array $losses = [];

    /** @return list<string> */
    public function getLosses(): array {
        return $this->losses;
    }

    public function generate(GaebBoq $boq, ?string $mask = null, ?string $contract = null): string {
        $this->losses = [];
        $lines = [$this->header($boq, $mask ?? self::DEFAULT_MASK, $contract)];

        foreach ($boq->getItems() as $item) {
            $reference = $this->ordinal($item->getReference());
            foreach ($item->getTakeoffLines() as $line) {
                // Datenart und Ordnungszahl stehen vor dem Satz; ab Stelle 13
                // ist er mit dem der X31 identisch.
                $lines[] = '11' . $reference . ' ' . mb_substr($this->record->render($line), 12);
            }
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    private function header(GaebBoq $boq, string $mask, ?string $contract): string {
        return '00'
            . str_pad(mb_substr((string) $contract, 0, 8), 8)
            . str_pad(self::PROCEDURE, 6)
            . str_pad(self::EDITION, 4)
            . str_pad(mb_substr((string) $boq->getProjectName(), 0, 51), 51)
            . str_pad(mb_substr($mask, 0, 9), 9);
    }

    /**
     * The ordinal number occupies nine fixed places. The dots of the reading
     * form are separators, not content - they go out again.
     */
    private function ordinal(string $reference): string {
        $digits = str_replace('.', '', $reference);

        // Neun Stellen sind das Maximum der REB-VB 23.003 (Ausgabe 2009). Eine
        // längere Ordnungszahl passt nicht - das wird gemeldet, nicht verschwiegen.
        if (mb_strlen($digits) > 9) {
            $this->losses[] = "Ordnungszahl {$reference} ist länger als neun Stellen und wurde gekürzt.";
        }

        return str_pad(mb_substr($digits, 0, 9), 9);
    }
}
