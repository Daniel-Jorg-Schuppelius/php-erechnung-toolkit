<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebDaXmlGenerator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Generators;

use CommonToolkit\ValueObjects\Money;
use DOMDocument;
use DOMElement;
use ERechnungToolkit\Entities\Gaeb\{GaebBoq, GaebCatalogAssignment, GaebChangeOrder, GaebItem, GaebParty, GaebSection, GaebTotals};
use ERechnungToolkit\Enums\{GaebChangeOrderStatus, GaebItemType, GaebPhase};
use ERechnungToolkit\Helper\Gaeb\{GaebCalculator, GaebTakeoffRecord};

/**
 * Writer for GAEB DA XML, the counterpart of {@see \ERechnungToolkit\Parsers\GaebDaXmlParser}.
 *
 * The output is deterministic: with the same document and the same date the
 * result is byte identical, which lets the calling application hash it.
 *
 * GAEBInfo always describes the *writing* program. Copying it from an imported
 * file is not allowed (GAEB DA XML 3.3, appendix GAEBInfo).
 */
final class GaebDaXmlGenerator {
    private const VERSION = '3.3';

    private readonly GaebCalculator $calculator;

    private readonly GaebTakeoffRecord $takeoff;

    public function __construct(?GaebCalculator $calculator = null, ?GaebTakeoffRecord $takeoff = null) {
        $this->takeoff = $takeoff ?? new GaebTakeoffRecord;
        $this->calculator = $calculator ?? new GaebCalculator;
    }

    /**
     * Edition of the schema, not of the writing program. The bill of quantities,
     * trade, invoice and framework schemas all still carry 2021-05; only the X31
     * quantity survey was reissued as 2023-01. Both values are enumerations - a
     * wrong one makes the file invalid.
     */
    private const VERS_DATE = '2021-05';

    /**
     * Verfahren, nach dem die Mengen gerechnet wurden. Die X31 nennt es im
     * Kopf; alles andere wäre für den Prüfenden nicht nachvollziehbar.
     */
    private const SURVEY_METHOD = 'REB23003-2009';

    /**
     * @param string|null    $openingDate Date of the bid opening. Required by the
     *                                    schema for X83 and ignored elsewhere.
     * @param GaebParty|null $contractor  Bidder or contractor. The schema demands
     *                                    it in X84, X86 and X87; without it those
     *                                    phases stay incomplete.
     * @param GaebParty|null $client      Awarding body. Mandatory in the award
     *                                    (X86) - the schema has `OWN` without
     *                                    `minOccurs="0"` there.
     */
    public function generate(
        GaebBoq $boq,
        GaebPhase $phase,
        string $currency = 'EUR',
        ?string $date = null,
        string $progSystem = 'php-erechnung-toolkit',
        ?string $projectName = null,
        ?string $openingDate = null,
        ?GaebParty $contractor = null,
        ?GaebParty $client = null
    ): string {
        $name = $projectName ?? $boq->getProjectName() ?? '';
        $ns = sprintf('http://www.gaeb.de/GAEB_DA_XML/DA%s/%s', $phase->value, self::VERSION);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS($ns, 'GAEB');
        $dom->appendChild($root);

        $info = $dom->createElement('GAEBInfo');
        $info->appendChild($dom->createElement('Version', self::VERSION));
        $info->appendChild($dom->createElement('VersDate', self::VERS_DATE));
        $info->appendChild($dom->createElement('Date', $date ?? '2026-01-01'));
        $info->appendChild($this->textElement($dom, 'ProgSystem', $progSystem));
        $root->appendChild($info);

        // PrjInfo carries no currency: the X84 project block is reduced to name
        // and identifiers. The currency belongs to AwardInfo, where it is
        // mandatory in every phase.
        // Die Mengenermittlung kennt keinen PrjInfo-Block; ihr Projektname steht
        // im Kopf der Mengenermittlung selbst.
        if ($phase !== GaebPhase::QuantitySurvey) {
            $prj = $dom->createElement('PrjInfo');
            $prj->appendChild($this->textElement($dom, 'NamePrj', $name));
            $root->appendChild($prj);
        }

        // Die Mengenermittlung ist kein Vergabevorgang: Sie hängt unter
        // QtyDeterm statt unter Award und nennt statt Währung und Bieter das
        // Verfahren, nach dem gerechnet wurde.
        $isSurvey = $phase === GaebPhase::QuantitySurvey;

        $award = $dom->createElement($isSurvey ? 'QtyDeterm' : 'Award');
        if ($isSurvey) {
            $surveyInfo = $dom->createElement('QtyDetermInfo');
            $surveyInfo->appendChild($dom->createElement('MethodDescription', self::SURVEY_METHOD));
            if ($name !== '') {
                $surveyInfo->appendChild($this->textElement($dom, 'ProjDescr', mb_substr($name, 0, 60)));
            }
            $award->appendChild($surveyInfo);
        }
        $award->appendChild($dom->createElement('DP', $phase->value));

        if (!$isSurvey) {
            $awardInfo = $dom->createElement('AwardInfo');
            $awardInfo->appendChild($dom->createElement('Cur', $currency));
            if ($openingDate !== null) {
                $awardInfo->appendChild($dom->createElement('OpenDate', $openingDate));
            }
            // Nachtragsköpfe beschreiben den Vorgang, nicht das Verzeichnis -
            // sie stehen deshalb in AwardInfo (Schema tgAwardInfo).
            foreach ($phase->carriesChangeOrderHead() ? $boq->getChangeOrders() : [] as $order) {
                $awardInfo->appendChild($this->changeOrderElement($dom, $order));
            }
            $award->appendChild($awardInfo);

            // Der Auftraggeber steht im Schema vor dem Auftragnehmer und ist
            // in der Auftragserteilung Pflicht - ohne ihn ist die Datei für
            // X86 unvollständig, was der Preflight meldet.
            if ($client !== null && $phase->carriesClient()) {
                $award->appendChild($this->partyElement($dom, 'OWN', $client));
            }
            if ($contractor !== null && $phase->carriesContractor()) {
                $award->appendChild($this->partyElement($dom, 'CTR', $contractor));
            }
        }

        $boqEl = $dom->createElement('BoQ');
        // Die ID ist Pflicht (xs:ID) und darf nicht mit einer Ziffer beginnen.
        $boqEl->setAttribute('ID', $boq->getExternalId() ?? $this->identifier('B', $name === '' ? 'boq' : $name));

        // Name is the short key (20 characters), LblBoQ the label (60). The bid
        // header knows neither label nor text scope nor share captions - it
        // answers a document the other side already has. Die Mengenermittlung
        // hat gar keinen BoQInfo-Block: dort hängt die Gliederung unmittelbar
        // unter BoQ.
        $boqInfo = $isSurvey ? $boqEl : $dom->createElement('BoQInfo');
        if (!$isSurvey) {
            $boqInfo->appendChild($this->textElement($dom, 'Name', mb_substr($name, 0, 20)));
        }
        if ($phase->carriesTexts()) {
            $boqInfo->appendChild($this->textElement($dom, 'LblBoQ', mb_substr($name, 0, 60)));
            $boqInfo->appendChild($dom->createElement('OutlCompl', $this->textScope($boq)));
        }
        $this->appendBreakdown($dom, $boqInfo, $boq);
        // How many shares the client requires the unit price to be split into
        // (up to six, form 223 of the German VHB).
        $components = $boq->getUpComponents();
        if ($components !== [] && $phase->carriesTexts()) {
            $boqInfo->appendChild($dom->createElement('NoUPComps', (string) count($components)));
            foreach ($components as $component) {
                $label = $this->textElement($dom, 'LblUPComp' . $component->getNo(), (string) $component->getLabel());
                if ($component->getCategory() !== null) {
                    $label->setAttribute('Type', $component->getCategory());
                }
                $boqInfo->appendChild($label);
            }
        }
        // Kataloge und Zuordnungen beschreiben das Dokument; die Angebotsabgabe
        // antwortet darauf und trägt sie nicht erneut.
        foreach ($phase->carriesTexts() ? $boq->getCatalogs() : [] as $catalog) {
            $el = $dom->createElement('Ctlg');
            $el->appendChild($this->textElement($dom, 'CtlgID', $catalog->getId()));
            if ($catalog->getType() !== null) {
                $el->appendChild($this->textElement($dom, 'CtlgType', $catalog->getType()));
            }
            if ($catalog->getName() !== null) {
                $el->appendChild($this->textElement($dom, 'CtlgName', $catalog->getName()));
            }
            if ($catalog->getAssignType() !== null) {
                $el->appendChild($this->textElement($dom, 'CtlgAssignType', $catalog->getAssignType()));
            }
            $boqInfo->appendChild($el);
        }
        if (!$isSurvey) {
            $this->appendTotals($dom, $boqInfo, $boq->getTotals());
            $boqEl->appendChild($boqInfo);
        }

        $body = $dom->createElement('BoQBody');
        $this->appendBody($dom, $body, $boq, $phase, null, '');
        $boqEl->appendChild($body);

        $award->appendChild($boqEl);
        $root->appendChild($award);

        return (string) $dom->saveXML();
    }

    private function appendBody(DOMDocument $dom, DOMElement $body, GaebBoq $boq, GaebPhase $phase, ?string $parentReference, string $parentRef): void {
        foreach ($this->sectionsOf($boq, $parentReference) as $section) {
            $ctgy = $dom->createElement('BoQCtgy');
            $ctgy->setAttribute('ID', $section->getExternalId() ?? $this->identifier('C', $section->getReference()));
            $ctgy->setAttribute('RNoPart', $this->localPart($section->getReference(), $parentRef));
            if ($section->getLabel() !== null && $phase->carriesTexts()) {
                $ctgy->appendChild($this->htmlText($dom, 'LblTx', $section->getLabel()));
            }
            if ($phase->carriesTexts()) {
                $this->appendCatalogAssignments($dom, $ctgy, $section->getCatalogAssignments());
            }
            $childBody = $dom->createElement('BoQBody');
            $this->appendBody($dom, $childBody, $boq, $phase, $section->getReference(), $section->getReference());
            $ctgy->appendChild($childBody);
            // The bid demands a sum on every group; where the document carries
            // none it is added up from the items below. Ohne Preise gibt es
            // nichts zu summieren - die Mengenermittlung weist ihr Schema ab.
            // Jede Gruppe einer Preisphase muss ihre Summe nennen; wo das
            // Dokument keine mitbringt, wird sie aus den Positionen gebildet.
            if ($phase->carriesPrices()) {
                if ($section->getTotals()?->getTotal() === null) {
                    $ctgy->appendChild($this->totalElement($dom, $this->calculator->sectionTotal($boq, $section->getReference())));
                } else {
                    $this->appendTotals($dom, $ctgy, $section->getTotals());
                }
            }
            $body->appendChild($ctgy);
        }

        $items = $this->itemsOf($boq, $parentReference);
        if ($items === []) {
            return;
        }

        $list = $dom->createElement('Itemlist');
        foreach ($items as $item) {
            $list->appendChild($this->itemElement($dom, $boq, $item, $phase, $parentRef));
        }
        $body->appendChild($list);
    }

    private function itemElement(DOMDocument $dom, GaebBoq $boq, GaebItem $item, GaebPhase $phase, string $parentRef): DOMElement {
        // Markup items and remarks are own elements of an Itemlist, not item
        // variants.
        $el = $dom->createElement(match ($item->getType()) {
            GaebItemType::Markup => 'MarkupItem',
            GaebItemType::Note => 'Remark',
            default => 'Item',
        });

        // The identifier is mandatory on items, markup items and remarks
        // (xs:ID). Where the source did not carry one, it is derived from the
        // ordinal number so that repeated exports stay byte identical.
        $el->setAttribute('ID', $item->getExternalId() ?? $this->identifier('I', $item->getReference()));

        [$part, $index] = $this->splitIndex($this->localPart($item->getReference(), $parentRef));
        // The H level is an internal helper for remarks without an ordinal
        // number (see the parser) and does not belong back into the file.
        if (!($item->getType() === GaebItemType::Note && preg_match('/^H\d+$/', $part) === 1)) {
            $el->setAttribute('RNoPart', $part);
        }
        if ($index !== null) {
            $el->setAttribute('RNoIndex', $index);
        }

        // Die Reihenfolge ist im Schema festgelegt: Alternativkennzeichen vor
        // der Bedarfsart, die Zuschlagsart erst hinter dem Nachtragsstatus.
        if ($item->getAlternativeGroup() !== null) {
            $el->appendChild($dom->createElement('ALNGroupNo', $item->getAlternativeGroup()));
        }
        if ($item->getAlternativeNo() !== null) {
            $el->appendChild($dom->createElement('ALNSerNo', (string) $item->getAlternativeNo()));
        }
        if ($item->getProvisionKind() !== null) {
            $el->appendChild($dom->createElement('Provis', $item->getProvisionKind()));
        }
        if ($item->isNotApplicable()) {
            $el->appendChild($dom->createElement('NotAppl', 'Yes'));
        }
        if ($item->isNotOffered() && $phase->carriesNotOffered()) {
            $el->appendChild($dom->createElement('NotOffered', 'Yes'));
        }
        if ($item->isHourlyItem()) {
            $el->appendChild($dom->createElement('HourIt', 'Yes'));
        }
        // The breakdown flag is the client's requirement and travels with the
        // document, not with the bid - there the shares themselves are enough.
        if ($item->getUnitPriceComponents() !== [] && $phase->carriesTexts()) {
            $el->appendChild($dom->createElement('UPBkdn', 'Yes'));
        }
        // Addendum items must carry their number (GAEB rules for X80 to X86,
        // object Item, rule 8); the status travels with them.
        // Nummer und Status bilden im Schema eine Pflichtgruppe: Eine
        // Nachtragsnummer ohne Status ist nicht darstellbar. Fehlt der Status,
        // gilt „erkannt" - der schwächste, der nur die Existenz behauptet.
        if ($item->getChangeOrderNo() !== null && $phase->carriesItemChangeOrder()) {
            $el->appendChild($this->textElement($dom, 'CONo', $item->getChangeOrderNo()));
            $el->appendChild($dom->createElement(
                'COStatus',
                ($item->getChangeOrderStatus() ?? GaebChangeOrderStatus::Recognised)->value
            ));
        }
        if ($item->getType() === GaebItemType::Markup && $item->getMarkupType() !== null && $phase->carriesTexts()) {
            $el->appendChild($dom->createElement('MarkupType', $item->getMarkupType()->value));
        }

        // Bei freier Menge fordert der Ausschreibende die Menge vom Bieter an;
        // eine Mengenvorgabe ist dann unzulässig (GAEB 3.3, freie Menge).
        if ($item->hasFreeQuantity()) {
            $el->appendChild($dom->createElement('QtyTBD', 'Yes'));
        }
        $carriesQuantity = $item->getType() !== GaebItemType::Markup && $item->getType() !== GaebItemType::Note;
        if ($carriesQuantity && $item->getQuantity() !== null && $phase->carriesQuantities() && !($item->hasFreeQuantity() && $phase === GaebPhase::RequestForBid)) {
            $el->appendChild($dom->createElement('Qty', $this->num($item->getQuantity())));
        }
        foreach ($phase->carriesTexts() ? $item->getQuantitySplits() : [] as $split) {
            $splitEl = $dom->createElement('QtySplit');
            if ($split->getPercent() !== null) {
                $splitEl->appendChild($dom->createElement('QtyPcnt', $this->num($split->getPercent())));
            }
            if ($split->getQuantity() !== null) {
                $splitEl->appendChild($dom->createElement('Qty', $this->num($split->getQuantity())));
            }
            $this->appendCatalogAssignments($dom, $splitEl, $split->getCatalogAssignments());
            $el->appendChild($splitEl);
        }
        if ($carriesQuantity && $item->getUnit() !== null && $phase->carriesQuantities()) {
            $el->appendChild($this->textElement($dom, 'QU', $item->getUnit()));
        }
        if ($phase->carriesTexts()) {
            $this->appendCatalogAssignments($dom, $el, $item->getCatalogAssignments());
        }

        if ($phase->carriesPrices() && $item->getType() === GaebItemType::Markup && $item->getUnitPrice() !== null) {
            // Die Zuschlagsposition trägt einen Satz statt eines Einheitspreises
            // - und davor die Bemessungsgrundlage, ohne die der Satz nichts
            // aussagt. Das Schema verlangt beides in den Auftragsphasen.
            $el->appendChild($dom->createElement('ITMarkup', $this->amount($this->calculator->markupBase($boq, $item))));
            $el->appendChild($dom->createElement('Markup', $this->amount($item->getUnitPrice())));
            $el->appendChild($dom->createElement('IT', $this->amount(
                $item->getTotalPrice() ?? $this->calculator->markupAmount($boq, $item)
            )));
        } elseif ($phase->carriesPrices() && $item->getUnitPrice() !== null && !$item->isNotOffered()) {
            $el->appendChild($dom->createElement('UP', $this->amount($item->getUnitPrice())));
            foreach ($item->getUnitPriceComponents() as $i => $value) {
                $el->appendChild($dom->createElement('UPComp' . ($i + 1), $this->amount($value)));
            }
            if ($item->getTotalPrice() !== null) {
                $el->appendChild($dom->createElement('IT', $this->amount($item->getTotalPrice())));
            }
        }
        if ($item->getDiscountPercent() !== null) {
            $el->appendChild($dom->createElement('DiscountPcnt', $this->num($item->getDiscountPercent())));
        }
        if ($item->getVatRate() !== null) {
            $el->appendChild($dom->createElement('VAT', $this->num($item->getVatRate())));
        }
        if ($item->getBidderComment() !== null) {
            $el->appendChild($this->htmlText($dom, 'BidComm', $item->getBidderComment()));
        }
        // The bid carries no texts of its own: only the complements the bidder
        // filled in travel back, inside a CompleteText without markers.
        if (!$phase->carriesTexts()) {
            if ($item->getTextComplements() !== []) {
                $desc = $dom->createElement('Description');
                $complete = $dom->createElement('CompleteText');
                $complete->appendChild($this->detailText($dom, $item, true));
                $desc->appendChild($complete);
                $el->appendChild($desc);
            }
        }

        // A description is either a CompleteText or a bare OutlineText - never
        // both. Inside CompleteText the long text is mandatory and comes first,
        // so an item without one carries its short text directly.
        $shortText = $item->getShortText();
        $longText = $item->getLongText();
        if ($phase->carriesTexts() && ($shortText !== null || $longText !== null)) {
            $desc = $dom->createElement('Description');

            if ($longText !== null) {
                $complete = $dom->createElement('CompleteText');
                $complements = $item->getTextComplements();
                if ($complements !== []) {
                    // Marks which side fills the gaps (GAEB object Description, rule 1).
                    $hasBidder = false;
                    foreach ($complements as $complement) {
                        $hasBidder = $hasBidder || $complement->isBidderComplement();
                    }
                    $complete->appendChild($dom->createElement($hasBidder ? 'ComplTSB' : 'ComplTSA', 'Yes'));
                }
                $complete->appendChild($this->detailText($dom, $item));
                if ($shortText !== null) {
                    $complete->appendChild($this->outlineText($dom, $shortText));
                }
                $desc->appendChild($complete);
            } else {
                $desc->appendChild($this->outlineText($dom, (string) $shortText));
            }

            $el->appendChild($desc);
        }

        // Leading description: sub descriptions carry their own quantity but
        // never their own price.
        if ($item->getSubDescriptions() !== []) {
            $el->appendChild($dom->createElement('SumDescr', 'Yes'));
            foreach ($item->getSubDescriptions() as $sub) {
                $subEl = $dom->createElement('SubDescr');
                if ($sub->getNo() !== null) {
                    $subEl->appendChild($this->textElement($dom, 'SubDNo', $sub->getNo()));
                }
                if ($sub->getQuantity() !== null) {
                    $subEl->appendChild($dom->createElement('Qty', $this->num($sub->getQuantity())));
                }
                if ($sub->getUnit() !== null && $phase->carriesQuantities()) {
                    $subEl->appendChild($this->textElement($dom, 'QU', $sub->getUnit()));
                }
                $el->appendChild($subEl);
            }
        }

        if ($item->getAlternativeBidStatus() !== null) {
            $el->appendChild($dom->createElement('AlterBidStatus', $item->getAlternativeBidStatus()->value));
        }

        // Die Mengenermittlung trägt ihre Rechenzeilen an der Position - auch
        // dann als leeres Element, wenn zu dieser Ordnungszahl nichts aufgemessen
        // wurde: Die Datei bildet das ganze Verzeichnis ab.
        if ($phase === GaebPhase::QuantitySurvey) {
            $determ = $dom->createElement('QtyDeterm');
            foreach ($item->getTakeoffLines() as $line) {
                $entry = $dom->createElement('QDetermItem');
                $takeoff = $dom->createElement('QTakeoff');
                $takeoff->setAttribute('Row', $this->takeoff->render($line));
                $entry->appendChild($takeoff);
                $determ->appendChild($entry);
            }
            $el->appendChild($determ);
        }

        return $el;
    }

    /**
     * Long text with text complements: the markers become TextComplement blocks
     * between the text sections again. The number stays untouched - it has to
     * be returned unchanged with the bid.
     */
    /**
     * @param bool $complementsOnly leave out the client's text and transmit only
     *                              the complements - what the bid returns
     */
    private function detailText(DOMDocument $dom, GaebItem $item, bool $complementsOnly = false): DOMElement {
        $detail = $dom->createElement('DetailTxt');
        $longText = (string) $item->getLongText();
        $complements = $item->getTextComplements();

        if ($complements === []) {
            $detail->appendChild($this->htmlText($dom, 'Text', $longText));

            return $detail;
        }

        $byMark = [];
        foreach ($complements as $complement) {
            $byMark[$complement->getMark()] = $complement;
        }

        $chunks = preg_split('/\[\[TC:([^\]]+)\]\]/', $longText, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        foreach ($chunks as $i => $chunk) {
            if ($i % 2 === 0) {
                $text = trim($chunk);
                if ($text !== '' && !$complementsOnly) {
                    $detail->appendChild($this->htmlText($dom, 'Text', $text));
                }

                continue;
            }

            $complement = $byMark[$chunk] ?? null;
            if ($complement === null) {
                continue;
            }

            $el = $dom->createElement('TextComplement');
            $el->setAttribute('MarkLbl', $complement->getMark());
            if ($complement->getKind() !== null) {
                $el->setAttribute('Kind', $complement->getKind());
            }
            foreach (['ComplCaption' => $complement->getCaption(), 'ComplBody' => $complement->getBody(), 'ComplTail' => $complement->getTail()] as $tag => $value) {
                if ($value === null) {
                    continue;
                }
                $part = $dom->createElement($tag);
                $part->appendChild($this->textElement($dom, 'span', $value));
                $el->appendChild($part);
            }
            $detail->appendChild($el);
        }

        return $detail;
    }

    /**
     * Sums with their discount. Whenever a discount exists, the sum after
     * discount has to travel with it (GAEB 3.3, discounts).
     */
    private function appendTotals(DOMDocument $dom, DOMElement $parent, ?GaebTotals $totals): void {
        if ($totals === null || $totals->isEmpty()) {
            return;
        }

        $el = $dom->createElement('Totals');
        foreach ([
            'Total' => $totals->getTotal(),
            'DiscountPcnt' => $totals->getDiscountPercent(),
            'DiscountAmt' => $totals->getDiscountAmount(),
            'TotAfterDisc' => $totals->getTotalAfterDiscount(),
            'VAT' => $totals->getVatRate(),
            'TotalNet' => $totals->getTotalNet(),
            'VATAmount' => $totals->getVatAmount(),
            'TotalGross' => $totals->getTotalGross(),
        ] as $tag => $value) {
            if ($value === null) {
                continue;
            }
            // Percentages stay decimal strings, amounts are money.
            $el->appendChild($dom->createElement($tag, $value instanceof Money ? $this->amount($value) : $this->num($value)));
        }
        $parent->appendChild($el);
    }

    /** @return list<GaebSection> */
    private function sectionsOf(GaebBoq $boq, ?string $parentReference): array {
        $sections = [];
        foreach ($boq->getSections() as $section) {
            if ($section->getParentReference() === $parentReference) {
                $sections[] = $section;
            }
        }

        usort($sections, static fn (GaebSection $a, GaebSection $b): int => $a->getPosition() <=> $b->getPosition());

        return $sections;
    }

    /** @return list<GaebItem> */
    private function itemsOf(GaebBoq $boq, ?string $sectionReference): array {
        $items = [];
        foreach ($boq->getItems() as $item) {
            if ($item->getSectionReference() === $sectionReference) {
                $items[] = $item;
            }
        }

        usort($items, static fn (GaebItem $a, GaebItem $b): int => $a->getPosition() <=> $b->getPosition());

        return $items;
    }

    /**
     * Splits the index level off the item part (counterpart of the parser). It
     * is exactly one character long in GAEB 3.3 and belongs into the RNoIndex
     * attribute - inside RNoPart it would illegally extend the item level.
     *
     * @return array{string, ?string}
     */
    private function splitIndex(string $localPart): array {
        $pos = strrpos($localPart, '.');
        if ($pos === false || strlen($localPart) - $pos !== 2) {
            return [$localPart, null];
        }

        return [substr($localPart, 0, $pos), substr($localPart, $pos + 1)];
    }

    /** Local RNoPart: the ordinal number without the prefix of the parent node. */
    private function localPart(string $reference, string $parentRef): string {
        if ($parentRef !== '' && str_starts_with($reference, $parentRef . '.')) {
            return substr($reference, strlen($parentRef) + 1);
        }

        return $reference;
    }

    /**
     * Which texts the file carries (GAEB `OutlCompl`). Derived from the stock
     * instead of assumed, because the reader on the other side uses it to decide
     * whether a missing long text is a gap or intended.
     */
    private function textScope(GaebBoq $boq): string {
        $short = false;
        $long = false;
        foreach ($boq->getItems() as $item) {
            $short = $short || $item->getShortText() !== null;
            $long = $long || $item->getLongText() !== null;
        }

        if ($short && !$long) {
            return 'OutTxt';
        }

        return $long && !$short ? 'DetailTxt' : 'AllTxt';
    }

    /**
     * Structure of the ordinal number (GAEB `BoQBkdn`): one entry per hierarchy
     * level, then the item level and, where used, the index. Derived from the
     * ordinal numbers actually present - at most seven entries, which is also
     * the schema limit.
     */
    private function appendBreakdown(DOMDocument $dom, DOMElement $boqInfo, GaebBoq $boq): void {
        $levels = [];
        $itemLength = 0;
        $indexUsed = false;
        $numeric = true;

        foreach ($boq->getSections() as $section) {
            $parts = explode('.', $section->getReference());
            $last = (string) end($parts);
            $depth = count($parts);
            $levels[$depth] = max($levels[$depth] ?? 0, strlen($last));
            $numeric = $numeric && ctype_digit($last);
        }

        foreach ($boq->getItems() as $item) {
            $parts = explode('.', $item->getReference());
            [$part, $index] = $this->splitIndex((string) end($parts));
            // Remarks without an ordinal number carry the internal H level; it
            // never reaches the file and must not widen the mask.
            if ($item->getType() === GaebItemType::Note && preg_match('/^H\d+$/', $part) === 1) {
                continue;
            }
            $itemLength = max($itemLength, strlen($part));
            $indexUsed = $indexUsed || $index !== null;
            $numeric = $numeric && ctype_digit($part);
        }

        $breakdown = [];
        ksort($levels);
        foreach ($levels as $length) {
            $breakdown[] = ['BoQLevel', $length];
        }
        if ($itemLength > 0) {
            $breakdown[] = ['Item', $itemLength];
        }
        if ($indexUsed) {
            $breakdown[] = ['Index', 1];
        }

        foreach (array_slice($breakdown, 0, 7) as [$type, $length]) {
            $entry = $dom->createElement('BoQBkdn');
            $entry->appendChild($dom->createElement('Type', $type));
            $entry->appendChild($dom->createElement('Length', (string) $length));
            $entry->appendChild($dom->createElement('Num', $numeric ? 'Yes' : 'No'));
            $boqInfo->appendChild($entry);
        }
    }

    /** Head of an addendum - the order of the elements is fixed by the schema. */
    private function changeOrderElement(DOMDocument $dom, GaebChangeOrder $order): DOMElement {
        $el = $dom->createElement('COInfo');
        $el->appendChild($dom->createElement('CONo', $order->getNumber()));
        if ($order->getPhase() !== null) {
            $el->appendChild($dom->createElement('COPhase', $order->getPhase()->value));
        }
        if ($order->getStatus() !== null) {
            $el->appendChild($dom->createElement('COStatus', $order->getStatus()->value));
        }
        if ($order->getInitiator() !== null) {
            $el->appendChild($dom->createElement('COInit', $order->getInitiator()->value));
        }
        if ($order->getReason() !== null) {
            $el->appendChild($this->htmlText($dom, 'COReas', $order->getReason()));
        }
        if ($order->getContractReference() !== null) {
            $el->appendChild($this->textElement($dom, 'RefBoQCOInfo', mb_substr($order->getContractReference(), 0, 60)));
        }
        if ($order->getDate() !== null) {
            $el->appendChild($dom->createElement('CODate', $order->getDate()));
        }

        return $el;
    }

    private function partyElement(DOMDocument $dom, string $name, GaebParty $party): DOMElement {
        $el = $dom->createElement($name);

        $address = $dom->createElement('Address');
        $address->appendChild($this->textElement($dom, 'Name1', $party->getName()));
        $address->appendChild($this->textElement($dom, 'Street', $party->getStreet()));
        $address->appendChild($this->textElement($dom, 'PCode', $party->getPostalCode()));
        $address->appendChild($this->textElement($dom, 'City', $party->getCity()));
        $el->appendChild($address);
        $el->appendChild($dom->createElement('CntryType', $party->isWithinEea() ? 'EEA' : 'Other'));

        return $el;
    }

    /** Short text of an item or sub description. */
    private function outlineText(DOMDocument $dom, string $text): DOMElement {
        $outline = $dom->createElement('OutlineText');
        $outlTxt = $dom->createElement('OutlTxt');
        $outlTxt->appendChild($this->htmlText($dom, 'TextOutlTxt', $text));
        $outline->appendChild($outlTxt);

        return $outline;
    }

    /**
     * Identifier derived from an ordinal number. `xs:ID` demands an NCName, so
     * the value never starts with a digit and carries no characters outside the
     * allowed set; the prefix keeps groups and items apart.
     */
    private function identifier(string $prefix, string $reference): string {
        $slug = preg_replace('/[^A-Za-z0-9._-]/', '-', $reference) ?? '';

        return $prefix . ($slug === '' ? '0' : $slug);
    }

    /**
     * Catalogue assignments of a node. One mechanism for cost group, work
     * category, building, cost unit and model identifier alike.
     *
     * @param list<GaebCatalogAssignment> $assignments
     */
    private function appendCatalogAssignments(DOMDocument $dom, DOMElement $parent, array $assignments): void {
        foreach ($assignments as $assignment) {
            $el = $dom->createElement('CtlgAssign');
            $el->appendChild($this->textElement($dom, 'CtlgID', $assignment->getCatalogId()));
            $el->appendChild($this->textElement($dom, 'CtlgCode', $assignment->getCode()));
            if ($assignment->getQuantity() !== null) {
                $el->appendChild($dom->createElement('Quantity', $this->num($assignment->getQuantity())));
            }
            $parent->appendChild($el);
        }
    }

    /** A `Totals` element that carries nothing but the mandatory sum. */
    private function totalElement(DOMDocument $dom, Money $total): DOMElement {
        $el = $dom->createElement('Totals');
        $el->appendChild($dom->createElement('Total', $this->amount($total)));

        return $el;
    }

    /**
     * Money as written in the file: the canonical decimal of the amount with
     * trailing zeros cut, never a float. `Money` keeps the tenth of a cent that
     * a GAEB unit price may carry.
     */
    private function amount(Money $money): string {
        $value = $money->getAmount();
        if (!str_contains($value, '.')) {
            return $value;
        }

        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }

    private function num(string $value): string {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.') ?: '0';
    }

    private function textElement(DOMDocument $dom, string $name, string $value): DOMElement {
        return $dom->createElement($name, htmlspecialchars($value, ENT_XML1));
    }

    private function htmlText(DOMDocument $dom, string $name, string $value): DOMElement {
        $wrapper = $dom->createElement($name);
        $p = $dom->createElement('p');
        $span = $dom->createElement('span', htmlspecialchars($value, ENT_XML1));
        $p->appendChild($span);
        $wrapper->appendChild($p);

        return $wrapper;
    }
}
