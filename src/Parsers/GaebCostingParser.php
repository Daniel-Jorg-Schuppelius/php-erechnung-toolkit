<?php
/*
 * Created on   : Sun Aug 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebCostingParser.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Parsers;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\Helper\Data\XmlHelper;
use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Entities\Gaeb\{GaebCatalogAssignment, GaebCostElement, GaebCosting};
use ERechnungToolkit\Enums\{GaebCostingMethod, GaebCostingType};
use InvalidArgumentException;
use SimpleXMLElement;

/**
 * Reads a cost determination (X50 building cost catalogue, X51 costing).
 *
 * These documents carry no bill of quantity: instead of work to be done they
 * describe what it is expected to cost, ordered by cost groups after DIN 276
 * and nested - a group holds sub-groups, those hold building elements.
 *
 * The element number tells the two shapes apart: `EleNo` is written out in full
 * on every level, `ElePart` gives only the part of the current one. Reading
 * keeps them apart, because joining a partial number is the reader's job and
 * would be wrong to do silently.
 */
final class GaebCostingParser {
    public function parse(string $xml): GaebCosting {
        $root = XmlHelper::safeLoadString($this->stripNamespaces($xml));
        if ($root === false || $root->getName() !== 'GAEB') {
            throw new InvalidArgumentException('Kein GAEB-Dokument.');
        }

        $costing = $this->findFirst($root, 'ElementalCosting');
        if ($costing === null) {
            throw new InvalidArgumentException('Keine Kostenermittlung (ElementalCosting) gefunden.');
        }

        $info = $this->findFirst($costing, 'ECInfo');
        $body = $this->findFirst($costing, 'ECBody');
        $currency = CurrencyCode::tryFrom((string) $this->text($info, 'Cur')) ?? CurrencyCode::Euro;

        // Die Bauform steht nicht im Kopf: Sie zeigt sich am ersten Element.
        $full = !str_contains($xml, '<ElePart');

        return new GaebCosting(
            name: (string) ($this->text($info, 'Name') ?? ''),
            elements: $this->parseElements($body, $currency),
            label: $this->text($info, 'LblEC'),
            type: GaebCostingType::tryFrom((string) $this->text($info, 'ECType')),
            method: GaebCostingMethod::tryFrom((string) $this->text($info, 'ECMethod')),
            date: $this->text($info, 'Date'),
            fullElementNumbers: $full,
        );
    }

    /** @return list<GaebCostElement> */
    private function parseElements(?SimpleXMLElement $parent, CurrencyCode $currency): array {
        if ($parent === null) {
            return [];
        }

        $elements = [];
        foreach ($parent->children() as $child) {
            if ($child->getName() !== 'CostElement') {
                continue;
            }
            $elements[] = $this->parseElement($child, $currency);
        }

        return $elements;
    }

    private function parseElement(SimpleXMLElement $node, CurrencyCode $currency): GaebCostElement {
        return new GaebCostElement(
            description: (string) ($this->text($node, 'Descr') ?? ''),
            unit: (string) ($this->text($node, 'QU') ?? ''),
            number: $this->text($node, 'EleNo') ?? $this->text($node, 'ElePart'),
            quantity: $this->text($node, 'Qty'),
            unitPrice: $this->money($node, 'UP', $currency),
            total: $this->money($node, 'IT', $currency),
            unitPriceFrom: $this->money($node, 'UPFrom', $currency),
            unitPriceAverage: $this->money($node, 'UPAvg', $currency),
            unitPriceTo: $this->money($node, 'UPTo', $currency),
            // Unterelemente stecken im übergeordneten, nicht daneben.
            children: $this->parseElements($node, $currency),
            remark: $this->text($node, 'Remark'),
            catalogAssignments: $this->parseAssignments($node),
        );
    }

    /** @return list<GaebCatalogAssignment> */
    private function parseAssignments(SimpleXMLElement $node): array {
        $assignments = [];
        foreach ($node->children() as $child) {
            if ($child->getName() !== 'CtlgAssign') {
                continue;
            }
            $id = $this->text($child, 'CtlgID');
            $code = $this->text($child, 'CtlgCode');
            if ($id === null || $code === null) {
                continue;
            }
            $assignments[] = new GaebCatalogAssignment(catalogId: $id, code: $code);
        }

        return $assignments;
    }

    private function money(SimpleXMLElement $node, string $name, CurrencyCode $currency): ?Money {
        $value = $this->text($node, $name);

        return $value === null || !is_numeric($value) ? null : Money::of($value, $currency);
    }

    private function text(?SimpleXMLElement $node, string $name): ?string {
        $found = $this->findFirst($node, $name);
        if ($found === null) {
            return null;
        }
        $value = trim((string) $found);

        return $value === '' ? null : $value;
    }

    private function findFirst(?SimpleXMLElement $node, string $name): ?SimpleXMLElement {
        if ($node === null) {
            return null;
        }
        foreach ($node->children() as $child) {
            if ($child->getName() === $name) {
                return $child;
            }
        }

        return null;
    }

    /** Namensräume weg: Die Phase steht im Namensraum, nicht in den Elementen. */
    private function stripNamespaces(string $xml): string {
        return (string) preg_replace('#\sxmlns(:\w+)?="[^"]*"#', '', $xml);
    }
}
