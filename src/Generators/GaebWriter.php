<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebWriter.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Generators;

use ERechnungToolkit\Entities\Gaeb\{GaebBoq, GaebParty};
use ERechnungToolkit\Enums\{GaebFormat, GaebPhase};
use InvalidArgumentException;

/**
 * Writes a bill of quantity into a chosen family - and says what does not fit.
 *
 * Converting between the families is lossy in one direction: GAEB DA XML holds
 * things the older grid simply has no columns for. Silently dropping them is
 * the dangerous part, because the receiving side cannot tell a missing text
 * complement from one that was never there. Every write therefore returns a
 * list of what was left behind, meant to be shown or logged, never swallowed.
 *
 * The other direction is safe: a document read from GAEB 90 or 2000 carries
 * less, so writing it as DA XML loses nothing.
 */
final class GaebWriter {
    public function __construct(
        private readonly GaebDaXmlGenerator $xml = new GaebDaXmlGenerator,
        private readonly Gaeb90Generator $gaeb90 = new Gaeb90Generator,
        private readonly Gaeb2000Generator $gaeb2000 = new Gaeb2000Generator,
    ) {}

    /**
     * @param  GaebParty|null  $contractor bidder, mandatory in X84/X86/X87
     * @return array{content: string, losses: list<string>}
     */
    public function write(
        GaebBoq $boq,
        GaebFormat $format,
        GaebPhase $phase,
        ?string $date = null,
        ?GaebParty $contractor = null
    ): array {
        if (!$format->isWritable()) {
            throw new InvalidArgumentException("Writing {$format->value} is not supported yet.");
        }

        return match ($format) {
            GaebFormat::DaXml => [
                'content' => $this->xml->generate($boq, $phase, $boq->getCurrency()->value, $date, 'php-erechnung-toolkit', null, null, $contractor),
                'losses' => [],
            ],
            GaebFormat::Gaeb2000 => [
                'content' => $this->gaeb2000->generate($boq, $phase),
                'losses' => $this->lossesForKeywordFormat($boq),
            ],
            default => [
                'content' => $this->gaeb90->generate($boq, $phase),
                'losses' => $this->lossesForGrid($boq),
            ],
        };
    }

    /**
     * What GAEB 2000 cannot carry. It holds more than the grid - long texts
     * keep their line breaks, the ordinal number is not capped at nine digits -
     * but the additions of DA XML have no place there either.
     *
     * @return list<string>
     */
    public function lossesForKeywordFormat(GaebBoq $boq): array {
        $complements = 0;
        $assignments = 0;
        $splits = 0;
        $shares = 0;

        foreach ($boq->getItems() as $item) {
            if ($item->getTextComplements() !== []) {
                $complements++;
            }
            if ($item->getCatalogAssignments() !== []) {
                $assignments++;
            }
            if ($item->getQuantitySplits() !== []) {
                $splits++;
            }
            if ($item->getUnitPriceComponents() !== []) {
                $shares++;
            }
        }

        $losses = [];
        if ($complements > 0) {
            $losses[] = "{$complements} Positionen tragen Textergänzungen, die GAEB 2000 nicht kennt.";
        }
        if ($assignments > 0) {
            $losses[] = "{$assignments} Positionen tragen Katalogzuordnungen (z. B. Kostengruppen), die entfallen.";
        }
        if ($splits > 0) {
            $losses[] = "{$splits} Positionen haben Teilmengen, die GAEB 2000 nicht abbildet.";
        }
        if ($shares > 0) {
            $losses[] = "{$shares} Positionen tragen aufgegliederte Einheitspreise; die Anteile entfallen.";
        }

        return $losses;
    }

    /**
     * What GAEB 90 cannot carry. Each entry names the cause and how often it
     * occurs, so the finding can be acted on instead of merely noted.
     *
     * @return list<string>
     */
    public function lossesForGrid(GaebBoq $boq): array {
        $longOrdinals = 0;
        $alphanumeric = 0;
        $complements = 0;
        $shares = 0;
        $assignments = 0;
        $splits = 0;
        $changeOrders = 0;
        $notes = 0;

        foreach ($boq->getItems() as $item) {
            $reference = str_replace('.', '', $item->getReference());
            if (strlen($reference) > 9) {
                $longOrdinals++;
            }
            if (preg_match('/[^0-9]/', $reference) === 1) {
                $alphanumeric++;
            }
            if ($item->getTextComplements() !== []) {
                $complements++;
            }
            if ($item->getUnitPriceComponents() !== []) {
                $shares++;
            }
            if ($item->getCatalogAssignments() !== []) {
                $assignments++;
            }
            if ($item->getQuantitySplits() !== []) {
                $splits++;
            }
            if ($item->getChangeOrderNo() !== null) {
                $changeOrders++;
            }
            if ($item->getType()->value === 'note') {
                $notes++;
            }
        }

        $losses = [];
        if ($longOrdinals > 0) {
            $losses[] = "{$longOrdinals} Ordnungszahlen sind länger als die 9 Stellen von GAEB 90 und werden gekürzt.";
        }
        if ($alphanumeric > 0) {
            $losses[] = "{$alphanumeric} Ordnungszahlen enthalten Buchstaben; GAEB 90 sieht dafür nur den Index vor.";
        }
        if ($complements > 0) {
            $losses[] = "{$complements} Positionen tragen Textergänzungen, die GAEB 90 nicht kennt.";
        }
        if ($shares > 0) {
            $losses[] = "{$shares} Positionen tragen aufgegliederte Einheitspreise; die Anteile entfallen.";
        }
        if ($assignments > 0) {
            $losses[] = "{$assignments} Positionen tragen Katalogzuordnungen (z. B. Kostengruppen), die entfallen.";
        }
        if ($splits > 0) {
            $losses[] = "{$splits} Positionen haben Teilmengen, die GAEB 90 nicht abbildet.";
        }
        if ($changeOrders > 0) {
            $losses[] = "{$changeOrders} Nachtragspositionen verlieren Nummer und Status.";
        }
        if ($notes > 0) {
            $losses[] = "{$notes} Hinweistexte werden nicht übertragen.";
        }
        if ($boq->getCatalogs() !== []) {
            $losses[] = 'Die Katalogdefinitionen des Kopfes entfallen.';
        }

        return $losses;
    }
}
