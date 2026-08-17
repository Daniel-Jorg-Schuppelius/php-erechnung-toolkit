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

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\Helper\Data\{NumberHelper, XmlHelper};
use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Entities\Gaeb\{GaebBoq, GaebCatalog, GaebCatalogAssignment, GaebChangeOrder, GaebItem, GaebQuantitySplit, GaebSection, GaebSubDescription, GaebTakeoffLine, GaebTextComplement, GaebTotals, GaebUpComponent};
use ERechnungToolkit\Enums\{GaebAlternativeBidStatus, GaebChangeOrderInitiator, GaebChangeOrderPhase, GaebChangeOrderStatus, GaebItemType, GaebMarkupType};
use ERechnungToolkit\Helper\Gaeb\GaebTakeoffRecord;
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
    private readonly GaebTakeoffRecord $record;

    public function __construct(?GaebTakeoffRecord $record = null) {
        $this->record = $record ?? new GaebTakeoffRecord;
    }

    /** Ordinal level used for remarks that carry no ordinal number of their own. */
    private const NOTE_LEVEL_PREFIX = 'H';

    /** Scale of every amount: GAEB prices carry a tenth of a cent. */
    private const PRICE_SCALE = 4;

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
        $currency = $this->parseCurrency($root);
        $sections = [];
        $items = [];
        $counters = ['section' => 0, 'item' => 0];

        if ($boq !== null) {
            $body = $this->findFirst($boq, 'BoQBody');
            if ($body !== null) {
                $this->walkBody($body, null, [], $sections, $items, $counters, $currency);
            }
        }

        return new GaebBoq(
            version: $version,
            phaseCode: $phaseCode,
            projectName: $this->extractProjectName($root, $boq),
            externalId: $this->attr($boq, 'ID') ?? $this->attr($boq, 'DBNr'),
            sections: $sections,
            items: $items,
            upComponents: $this->parseUpComponents($boq),
            catalogs: $this->parseCatalogs($this->findFirst($boq, 'BoQInfo')),
            changeOrders: $this->parseChangeOrders($this->findDeep($root, 'AwardInfo')),
            totals: $this->parseTotals($this->findFirst($this->findFirst($boq, 'BoQInfo'), 'Totals'), $currency),
            currency: $currency,
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
        array &$counters,
        CurrencyCode $currency
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
                    totals: $this->parseTotals($this->findFirst($node, 'Totals'), $currency),
                    externalId: $this->attr($node, 'ID'),
                    catalogAssignments: $this->parseCatalogAssignments($node),
                );

                $childBody = $this->findFirst($node, 'BoQBody');
                if ($childBody !== null) {
                    $this->walkBody($childBody, $ref, $parts, $sections, $items, $counters, $currency);
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

                $items[] = $this->parseItem($entry, $parentRef, $parts, $counters['item']++, $currency);
            }
        }
    }

    /** @param array<int, string> $ancestorParts */
    private function parseItem(SimpleXMLElement $item, ?string $sectionRef, array $ancestorParts, int $position, CurrencyCode $currency): GaebItem {
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
            unitPrice: $this->money($this->textOf($this->findFirst($item, 'UP')), $currency),
            totalPrice: $this->money($this->textOf($this->findFirst($item, 'IT')), $currency),
            // Only the own text node: <Provis>WithTotal</Provis>. Older files
            // carry a ProvisQty below it which names no kind.
            provisionKind: $this->ownText($this->findFirst($item, 'Provis')),
            alternativeGroup: $this->trimOrNull($this->textOf($this->findFirst($item, 'ALNGroupNo'))),
            alternativeNo: $alternativeNo === null ? null : (int) $alternativeNo,
            markupType: GaebMarkupType::tryFrom((string) $this->trimOrNull($this->textOf($this->findFirst($item, 'MarkupType')))),
            bidUpDownRequired: $this->textOf($this->findFirst($item, 'BidUpDownReq')) === 'Yes',
            bidUpDownPercent: $this->trimOrNull($this->textOf($this->findFirst($item, 'BidUpDownPct'))),
            catalogueNo: $this->trimOrNull($this->textOf($this->findDeep($item, 'WICNo'))),
            textComplements: $complements,
            subDescriptions: $this->parseSubDescriptions($item),
            unitPriceComponents: $this->parseUnitPriceComponents($item, $currency),
            changeOrderNo: $this->trimOrNull($this->textOf($this->findFirst($item, 'CONo'))),
            changeOrderStatus: GaebChangeOrderStatus::tryFrom((string) $this->trimOrNull($this->textOf($this->findFirst($item, 'COStatus')))),
            notOffered: $this->isYes($item, 'NotOffered'),
            notApplicable: $this->isYes($item, 'NotAppl'),
            quantityToBeDetermined: $this->isYes($item, 'QtyTBD'),
            hourlyItem: $this->isYes($item, 'HourIt'),
            discountPercent: $this->cleanNumber($this->textOf($this->findFirst($item, 'DiscountPcnt'))),
            vatRate: $this->cleanNumber($this->textOf($this->findFirst($item, 'VAT'))),
            bidderComment: $this->trimOrNull($this->textOf($this->findFirst($item, 'BidComm'))),
            alternativeBidStatus: GaebAlternativeBidStatus::tryFrom((string) $this->trimOrNull($this->textOf($this->findFirst($item, 'AlterBidStatus')))),
            externalId: $this->attr($item, 'ID'),
            catalogAssignments: $this->parseCatalogAssignments($item),
            quantitySplits: $this->parseQuantitySplits($item),
            takeoffLines: $this->parseTakeoffLines($item),
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

    /** Yes/No element such as NotOffered, NotAppl, QtyTBD or HourIt. */
    private function isYes(SimpleXMLElement $item, string $name): bool {
        return strtolower((string) $this->ownText($this->findFirst($item, $name))) === 'yes';
    }

    /** Sums of a bill of quantity or a section, including any discount. */
    private function parseTotals(?SimpleXMLElement $totals, CurrencyCode $currency): ?GaebTotals {
        if ($totals === null) {
            return null;
        }

        $entity = new GaebTotals(
            total: $this->money($this->textOf($this->findFirst($totals, 'Total')), $currency),
            discountPercent: $this->cleanNumber($this->textOf($this->findFirst($totals, 'DiscountPcnt'))),
            discountAmount: $this->money($this->textOf($this->findFirst($totals, 'DiscountAmt')), $currency),
            totalAfterDiscount: $this->money($this->textOf($this->findFirst($totals, 'TotAfterDisc')), $currency),
            vatRate: $this->cleanNumber($this->textOf($this->findFirst($totals, 'VAT'))),
            totalNet: $this->money($this->textOf($this->findFirst($totals, 'TotalNet')), $currency),
            vatAmount: $this->money($this->textOf($this->findFirst($totals, 'VATAmount')), $currency),
            totalGross: $this->money($this->textOf($this->findFirst($totals, 'TotalGross')), $currency),
        );

        return $entity->isEmpty() ? null : $entity;
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
     * @return list<Money>
     */
    private function parseUnitPriceComponents(SimpleXMLElement $item, CurrencyCode $currency): array {
        $values = [];
        foreach ($item->children() as $child) {
            if (preg_match('/^UPComp([1-6])$/', $child->getName(), $m) !== 1) {
                continue;
            }
            $values[(int) $m[1]] = $this->money($this->textOf($child), $currency);
        }

        if ($values === []) {
            return [];
        }

        ksort($values);

        return array_values(array_map(
            static fn (?Money $v): Money => $v ?? Money::zero($currency, self::PRICE_SCALE),
            $values
        ));
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
    /**
     * Quantity survey of an item (`QtyDeterm` with `QTakeoff`). The line is a
     * fixed 80 character record of the REB world, wrapped in XML: the first
     * eleven columns stay empty, from column 12 it is identical to the REB file.
     *
     * The column layout itself lives in {@see GaebTakeoffRecord}, which reads
     * and writes it - the DA11 file uses the very same record.
     *
     * @return list<GaebTakeoffLine>
     */
    private function parseTakeoffLines(SimpleXMLElement $item): array {
        $determ = $this->findFirst($item, 'QtyDeterm');
        if ($determ === null) {
            return [];
        }

        $lines = [];
        foreach ($determ->children() as $entry) {
            if ($entry->getName() !== 'QDetermItem') {
                continue;
            }
            $takeoff = $this->findFirst($entry, 'QTakeoff');
            if ($takeoff === null) {
                continue;
            }

            $row = (string) $this->attr($takeoff, 'Row');
            if (trim($row) === '') {
                continue;
            }

            // Der Satz ist ab Spalte 13 der der DA11-Datei; gelesen wird er
            // deshalb an genau einer Stelle.
            $lines[] = $this->record->parse($row);
        }

        return $lines;
    }

    /**
     * Addendum heads of the document (`COInfo`). An item carries only the
     * number of its addendum - everything that makes it accountable stands
     * here, and a document may describe several.
     *
     * @return list<GaebChangeOrder>
     */
    private function parseChangeOrders(?SimpleXMLElement $boqInfo): array {
        if ($boqInfo === null) {
            return [];
        }

        $orders = [];
        foreach ($boqInfo->children() as $child) {
            if ($child->getName() !== 'COInfo') {
                continue;
            }
            $number = $this->trimOrNull($this->textOf($this->findFirst($child, 'CONo')));
            if ($number === null) {
                continue;
            }

            $orders[] = new GaebChangeOrder(
                number: $number,
                phase: GaebChangeOrderPhase::tryFrom((string) $this->trimOrNull($this->textOf($this->findFirst($child, 'COPhase')))),
                status: GaebChangeOrderStatus::tryFrom((string) $this->trimOrNull($this->textOf($this->findFirst($child, 'COStatus')))),
                initiator: GaebChangeOrderInitiator::tryFrom((string) $this->trimOrNull($this->textOf($this->findFirst($child, 'COInit')))),
                reason: $this->trimOrNull($this->textOf($this->findFirst($child, 'COReas'))),
                contractReference: $this->trimOrNull($this->textOf($this->findFirst($child, 'RefBoQCOInfo'))),
                date: $this->trimOrNull($this->textOf($this->findFirst($child, 'CODate'))),
            );
        }

        return $orders;
    }

    /**
     * Catalogues declared in the header. Without them an assignment is a code
     * without a meaning - the type carries the edition of DIN 276.
     *
     * @return list<GaebCatalog>
     */
    private function parseCatalogs(?SimpleXMLElement $boqInfo): array {
        if ($boqInfo === null) {
            return [];
        }

        $catalogs = [];
        foreach ($boqInfo->children() as $child) {
            if ($child->getName() !== 'Ctlg') {
                continue;
            }
            $id = $this->trimOrNull($this->textOf($this->findFirst($child, 'CtlgID')));
            if ($id === null) {
                continue;
            }
            $catalogs[] = new GaebCatalog(
                id: $id,
                type: $this->trimOrNull($this->textOf($this->findFirst($child, 'CtlgType'))),
                name: $this->trimOrNull($this->textOf($this->findFirst($child, 'CtlgName'))),
                assignType: $this->trimOrNull($this->textOf($this->findFirst($child, 'CtlgAssignType'))),
            );
        }

        return $catalogs;
    }

    /**
     * Catalogue assignments of one node - the same mechanism carries cost group,
     * work category, building and model identifier.
     *
     * @return list<GaebCatalogAssignment>
     */
    private function parseCatalogAssignments(SimpleXMLElement $node): array {
        $assignments = [];
        foreach ($node->children() as $child) {
            if ($child->getName() !== 'CtlgAssign') {
                continue;
            }
            $id = $this->trimOrNull($this->textOf($this->findFirst($child, 'CtlgID')));
            $code = $this->trimOrNull($this->textOf($this->findFirst($child, 'CtlgCode')));
            if ($id === null || $code === null) {
                continue;
            }
            $assignments[] = new GaebCatalogAssignment(
                catalogId: $id,
                code: $code,
                quantity: $this->cleanNumber($this->textOf($this->findFirst($child, 'Quantity'))),
            );
        }

        return $assignments;
    }

    /**
     * Partial quantities of an item, each with its own assignments.
     *
     * @return list<GaebQuantitySplit>
     */
    private function parseQuantitySplits(SimpleXMLElement $item): array {
        $splits = [];
        foreach ($item->children() as $child) {
            if ($child->getName() !== 'QtySplit') {
                continue;
            }
            $splits[] = new GaebQuantitySplit(
                quantity: $this->cleanNumber($this->textOf($this->findFirst($child, 'Qty'))),
                percent: $this->cleanNumber($this->textOf($this->findFirst($child, 'QtyPcnt'))),
                catalogAssignments: $this->parseCatalogAssignments($child),
            );
        }

        return $splits;
    }

    /**
     * Currency of the document (GAEB `Cur`). The schema demands it, so an
     * unreadable value falls back to the euro instead of guessing per amount.
     */
    private function parseCurrency(SimpleXMLElement $root): CurrencyCode {
        $code = $this->trimOrNull($this->textOf($this->findDeep($root, 'Cur')));
        if ($code === null) {
            return CurrencyCode::Euro;
        }

        return CurrencyCode::tryFrom(strtoupper($code)) ?? CurrencyCode::Euro;
    }

    /**
     * Amount in the currency of the document. GAEB carries a tenth of a cent in
     * the unit price (`EPZPF` in the older families), so the scale is four -
     * rounding to two would silently change a bid.
     */
    private function money(?string $value, CurrencyCode $currency): ?Money {
        return Money::ofNullable($this->cleanNumber($value), $currency, self::PRICE_SCALE);
    }

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
