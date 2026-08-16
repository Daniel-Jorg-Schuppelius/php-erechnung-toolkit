<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebDaXmlParser.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Parsers;

use CommonToolkit\Helper\Data\{NumberHelper, XmlHelper};
use ERechnungToolkit\Entities\Gaeb\{GaebBoq, GaebItem, GaebSection, GaebSubDescription, GaebTextComplement, GaebUpComponent};
use ERechnungToolkit\Enums\{GaebItemType, GaebPhase};
use InvalidArgumentException;
use SimpleXMLElement;

/**
 * Reader for GAEB DA XML (versions 3.1 to 3.3, target line 3.3).
 *
 * Reads version and phase from the namespace and the header, walks the BoQBody
 * recursively (categories to sections, items to positions) and builds ordinal
 * numbers from the RNoPart chain plus the RNoIndex attribute. Markup items and
 * remarks are siblings of Item inside an Itemlist and are read as well - they
 * are part of the bill of quantity and must not get lost.
 *
 * Deliberately tolerant: structural deviations are reported by the consuming
 * application, not rejected here.
 */
final class GaebDaXmlParser {
    /** Ordinal level used for remarks that carry no ordinal number of their own. */
    private const NOTE_LEVEL_PREFIX = 'H';

    public function parse(string $xml): GaebBoq {
        // Hardening against XXE and entity expansion: GAEB files have no
        // document type definition, so a DOCTYPE is rejected outright.
        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            throw new InvalidArgumentException('GAEB file contains an illegal DOCTYPE declaration.');
        }

        $version = $this->extractVersion($xml);
        $phaseCode = $this->extractPhase($xml);

        $root = XmlHelper::safeLoadString($this->stripNamespaces($xml));

        if ($root === false || $root->getName() !== 'GAEB') {
            throw new InvalidArgumentException('File is not a valid GAEB DA XML document.');
        }

        $boq = $this->findDeep($root, 'BoQ');
        $sections = [];
        $items = [];
        $counters = ['section' => 0, 'item' => 0];

        if ($boq !== null) {
            $body = $this->findFirst($boq, 'BoQBody');
            if ($body !== null) {
                $this->walkBody($body, null, [], $sections, $items, $counters);
            }
        }

        return new GaebBoq(
            version: $version,
            phase: GaebPhase::fromCode($phaseCode),
            phaseCode: $phaseCode,
            projectName: $this->extractProjectName($root, $boq),
            externalId: $this->attr($boq, 'ID') ?? $this->attr($boq, 'DBNr'),
            sections: $sections,
            items: $items,
            upComponents: $this->parseUpComponents($boq),
        );
    }

    /**
     * @param array<int, string>  $ancestorParts
     * @param list<GaebSection>   $sections
     * @param list<GaebItem>      $items
     * @param array{section: int, item: int} $counters
     */
    private function walkBody(
        SimpleXMLElement $body,
        ?string $parentRef,
        array $ancestorParts,
        array &$sections,
        array &$items,
        array &$counters
    ): void {
        foreach ($body->children() as $node) {
            $name = $node->getName();

            if ($name === 'BoQCtgy') {
                $part = $this->attr($node, 'RNoPart') ?? '';
                $parts = $ancestorParts;
                if ($part !== '') {
                    $parts[] = $part;
                }
                $ref = $this->joinRef($parts);

                $sections[] = new GaebSection(
                    reference: $ref,
                    parentReference: $parentRef,
                    label: $this->textOf($this->findFirst($node, 'LblTx')),
                    position: $counters['section']++,
                );

                $childBody = $this->findFirst($node, 'BoQBody');
                if ($childBody !== null) {
                    $this->walkBody($childBody, $ref, $parts, $sections, $items, $counters);
                }

                continue;
            }

            if ($name !== 'Itemlist') {
                continue;
            }

            $noteNo = 0;
            foreach ($node->children() as $entry) {
                if (!in_array($entry->getName(), ['Item', 'MarkupItem', 'Remark'], true)) {
                    continue;
                }

                // Remarks carry no binding ordinal number. Without one they
                // would inherit the section reference and collide with each
                // other, so they get a unique H level instead.
                $parts = $ancestorParts;
                if ($entry->getName() === 'Remark' && ($this->attr($entry, 'RNoPart') ?? '') === '') {
                    $parts[] = sprintf('%s%02d', self::NOTE_LEVEL_PREFIX, ++$noteNo);
                }

                $items[] = $this->parseItem($entry, $parentRef, $parts, $counters['item']++);
            }
        }
    }

    /** @param array<int, string> $ancestorParts */
    private function parseItem(SimpleXMLElement $item, ?string $sectionRef, array $ancestorParts, int $position): GaebItem {
        $part = $this->attr($item, 'RNoPart') ?? '';
        $parts = $ancestorParts;
        if ($part !== '') {
            $parts[] = $part;
        }

        // The index level lives in the RNoIndex attribute, not in the RNoPart
        // chain (GAEB 3.3, BoQBkdn type Index). Without it 0010, 0010.1 and
        // 0010.A collapse into a single ordinal number.
        $index = trim((string) ($this->attr($item, 'RNoIndex') ?? ''));
        if ($index !== '') {
            $parts[] = $index;
        }

        $description = $this->findFirst($item, 'Description');
        $shortText = null;
        $longText = null;
        $complements = [];
        if ($description !== null) {
            $shortText = $this->textOf($this->findDeep($description, 'OutlineText'))
                ?? $this->textOf($this->findDeep($description, 'OutlTxt'));
            [$longText, $complements] = $this->parseDetailText($this->findDeep($description, 'DetailTxt'));
        }

        $qty = $this->cleanNumber($this->textOf($this->findFirst($item, 'Qty')));
        $unit = $this->trimOrNull($this->textOf($this->findFirst($item, 'QU')));
        $alternativeNo = $this->cleanNumber($this->textOf($this->findFirst($item, 'ALNSerNo')));

        return new GaebItem(
            reference: $this->joinRef($parts),
            sectionReference: $sectionRef,
            type: $this->detectType($item, $alternativeNo, $qty, $unit, $shortText),
            shortText: $this->trimOrNull($shortText),
            longText: $this->trimOrNull($longText),
            quantity: $qty,
            unit: $unit,
            unitPrice: $this->cleanNumber($this->textOf($this->findFirst($item, 'UP'))),
            totalPrice: $this->cleanNumber($this->textOf($this->findFirst($item, 'IT'))),
            // Only the own text node: <Provis>WithTotal</Provis>. Older files
            // carry a ProvisQty below it which names no kind.
            provisionKind: $this->ownText($this->findFirst($item, 'Provis')),
            alternativeGroup: $this->trimOrNull($this->textOf($this->findFirst($item, 'ALNGroupNo'))),
            alternativeNo: $alternativeNo === null ? null : (int) $alternativeNo,
            markupType: $this->trimOrNull($this->textOf($this->findFirst($item, 'MarkupType'))),
            textComplements: $complements,
            subDescriptions: $this->parseSubDescriptions($item),
            unitPriceComponents: $this->parseUnitPriceComponents($item),
            addendum: $this->isAddendum($item),
            externalId: $this->attr($item, 'ID'),
            position: $position,
        );
    }

    private function detectType(SimpleXMLElement $item, ?string $alternativeNo, ?string $qty, ?string $unit, ?string $shortText): GaebItemType {
        $name = $item->getName();
        if ($name === 'MarkupItem') {
            return GaebItemType::Markup;
        }
        if ($name === 'Remark') {
            return GaebItemType::Note;
        }
        if (strtolower((string) $this->attr($item, 'LumpSumItem')) === 'yes' || $this->findFirst($item, 'LumpSumItem') !== null) {
            return GaebItemType::LumpSum;
        }
        if ($this->findFirst($item, 'Provis') !== null) {
            return GaebItemType::Optional;
        }
        // Base execution and alternatives share one ALN group; serial number 0
        // marks the base execution.
        if ($alternativeNo !== null) {
            return (float) $alternativeNo === 0.0 ? GaebItemType::Base : GaebItemType::Alternative;
        }
        if (strtolower((string) $this->attr($item, 'Alternative')) === 'yes' || $this->findFirst($item, 'Alternative') !== null) {
            return GaebItemType::Alternative;
        }
        if (($qty === null || $unit === null) && $shortText !== null) {
            return GaebItemType::Note;
        }

        return GaebItemType::Standard;
    }

    /**
     * Long text with its text complements. A DetailTxt is a sequence of Text
     * and TextComplement blocks; the complements are the gaps the bidder fills.
     * Their numbers must be returned unchanged with the bid, so a marker stays
     * behind in the text instead of melting them into one flowing paragraph.
     *
     * @return array{?string, list<GaebTextComplement>}
     */
    private function parseDetailText(?SimpleXMLElement $detail): array {
        if ($detail === null) {
            return [null, []];
        }

        $parts = [];
        $complements = [];
        foreach ($detail->children() as $child) {
            if ($child->getName() === 'TextComplement') {
                $mark = $this->trimOrNull((string) ($this->attr($child, 'MarkLbl') ?? ''))
                    ?? (string) (count($complements) + 1);
                $complements[] = new GaebTextComplement(
                    mark: $mark,
                    kind: $this->trimOrNull((string) ($this->attr($child, 'Kind') ?? '')),
                    caption: $this->textOf($this->findFirst($child, 'ComplCaption')),
                    body: $this->textOf($this->findFirst($child, 'ComplBody')),
                    tail: $this->textOf($this->findFirst($child, 'ComplTail')),
                );
                $parts[] = GaebBoq::complementMarker($mark);

                continue;
            }

            $text = $this->textOf($child);
            if ($text !== null) {
                $parts[] = $text;
            }
        }

        $text = trim(implode(' ', $parts));

        return [$text === '' ? null : $text, $complements];
    }

    /**
     * Sub descriptions of a leading description. They carry their own quantity
     * but never their own price.
     *
     * @return list<GaebSubDescription>
     */
    private function parseSubDescriptions(SimpleXMLElement $item): array {
        $subs = [];
        foreach ($item->children() as $child) {
            if ($child->getName() !== 'SubDescr') {
                continue;
            }
            $subs[] = new GaebSubDescription(
                no: $this->trimOrNull($this->textOf($this->findFirst($child, 'SubDNo'))),
                quantity: $this->cleanNumber($this->textOf($this->findFirst($child, 'Qty'))),
                unit: $this->trimOrNull($this->textOf($this->findFirst($child, 'QU'))),
            );
        }

        return $subs;
    }

    /**
     * Shares of the unit price on an item (UPComp1 to UPComp6). Their sum must
     * equal the unit price; the order follows the labels of the bill header.
     *
     * @return list<string>
     */
    private function parseUnitPriceComponents(SimpleXMLElement $item): array {
        $values = [];
        foreach ($item->children() as $child) {
            if (preg_match('/^UPComp([1-6])$/', $child->getName(), $m) !== 1) {
                continue;
            }
            $values[(int) $m[1]] = $this->cleanNumber($this->textOf($child));
        }

        if ($values === []) {
            return [];
        }

        ksort($values);

        return array_values(array_map(static fn (?string $v): string => $v ?? '0', $values));
    }

    /**
     * Breakdown of the unit price required by the client: up to six shares with
     * a label and a category (wages, material, equipment, other), which is form
     * 223 of the German VHB.
     *
     * @return list<GaebUpComponent>
     */
    private function parseUpComponents(?SimpleXMLElement $boq): array {
        $info = $this->findFirst($boq, 'BoQInfo');
        if ($info === null) {
            return [];
        }

        $components = [];
        foreach ($info->children() as $child) {
            if (preg_match('/^LblUPComp([1-6])$/', $child->getName(), $m) !== 1) {
                continue;
            }
            $components[(int) $m[1]] = new GaebUpComponent(
                no: (int) $m[1],
                label: $this->trimOrNull((string) $child),
                category: $this->trimOrNull((string) ($this->attr($child, 'Type') ?? '')),
            );
        }

        ksort($components);

        return array_values($components);
    }

    private function isAddendum(SimpleXMLElement $item): bool {
        // GAEB marks addenda through STLNo or an addendum flag; checked leniently.
        return $this->findFirst($item, 'STLNo') !== null
            || strtolower((string) $this->attr($item, 'Addendum')) === 'yes';
    }

    private function extractVersion(string $xml): ?string {
        if (preg_match('#GAEB_DA_XML/DA\d+/(\d+\.\d+)#', $xml, $m) === 1) {
            return $m[1];
        }
        if (preg_match('#<VersNr>\s*([0-9.]+)\s*</VersNr>#', $xml, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function extractPhase(string $xml): ?string {
        if (preg_match('#GAEB_DA_XML/DA(\d+)/#', $xml, $m) === 1) {
            return $m[1];
        }
        if (preg_match('#<DP>\s*(\d+)\s*</DP>#', $xml, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function extractProjectName(SimpleXMLElement $root, ?SimpleXMLElement $boq): ?string {
        $prj = $this->findDeep($root, 'PrjInfo');
        $name = $this->textOf($this->findFirst($prj, 'NamePrj')) ?? $this->textOf($this->findFirst($prj, 'LblPrj'));
        if ($name !== null && trim($name) !== '') {
            return trim($name);
        }

        $boqInfo = $boq !== null ? $this->findFirst($boq, 'BoQInfo') : null;
        $name = $this->textOf($this->findFirst($boqInfo, 'Name'));

        return $name !== null && trim($name) !== '' ? trim($name) : null;
    }

    /** @param array<int, string> $parts */
    private function joinRef(array $parts): string {
        return implode('.', array_filter($parts, static fn ($p): bool => $p !== ''));
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

    /** First match at any depth (depth first). */
    private function findDeep(?SimpleXMLElement $node, string $name): ?SimpleXMLElement {
        if ($node === null) {
            return null;
        }
        foreach ($node->children() as $child) {
            if ($child->getName() === $name) {
                return $child;
            }
            $found = $this->findDeep($child, $name);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** Collect the whole text content of a node (p/span nesting). */
    private function textOf(?SimpleXMLElement $node): ?string {
        if ($node === null) {
            return null;
        }

        $parts = [];
        $own = trim((string) $node);
        if ($own !== '') {
            $parts[] = $own;
        }
        foreach ($node->children() as $child) {
            $childText = $this->textOf($child);
            if ($childText !== null && $childText !== '') {
                $parts[] = $childText;
            }
        }

        $text = trim(implode(' ', $parts));

        return $text === '' ? null : $text;
    }

    /** Own text node without the texts of child elements. */
    private function ownText(?SimpleXMLElement $node): ?string {
        return $node === null ? null : $this->trimOrNull((string) $node);
    }

    private function attr(?SimpleXMLElement $node, string $name): ?string {
        if ($node === null) {
            return null;
        }
        $value = $node[$name];

        return $value === null ? null : (string) $value;
    }

    private function trimOrNull(?string $value): ?string {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** GAEB uses the dot as decimal separator; tolerant against comma and grouping. */
    private function cleanNumber(?string $value): ?string {
        if ($value === null) {
            return null;
        }
        // Strip NBSP before normalising (the helper only strips ASCII spaces).
        $value = str_replace([' ', "\u{00A0}"], '', trim($value));
        if ($value === '') {
            return null;
        }

        // Undecipherable content stays null instead of becoming an amount of 0 -
        // in a bill of quantity that is a meaningful difference.
        return NumberHelper::normalizeDecimalStringOrNull($value);
    }

    /** Drop the default namespace so that SimpleXML works without prefixes. */
    private function stripNamespaces(string $xml): string {
        return (string) preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $xml);
    }
}
