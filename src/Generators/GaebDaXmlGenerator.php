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

use DOMDocument;
use DOMElement;
use ERechnungToolkit\Entities\Gaeb\{GaebBoq, GaebItem, GaebSection};
use ERechnungToolkit\Enums\{GaebItemType, GaebPhase};

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
    private const VERS_DATE = '2023-01';

    public function generate(
        GaebBoq $boq,
        GaebPhase $phase,
        string $currency = 'EUR',
        ?string $date = null,
        string $progSystem = 'php-erechnung-toolkit',
        ?string $projectName = null
    ): string {
        $name = $projectName ?? $boq->getProjectName() ?? '';
        $ns = sprintf('http://www.gaeb.de/GAEB_DA_XML/DA%s/%s', $phase->value, self::VERSION);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS($ns, 'GAEB');
        $dom->appendChild($root);

        $info = $dom->createElement('GAEBInfo');
        $info->appendChild($dom->createElement('Version', '3'));
        $info->appendChild($dom->createElement('VersDate', self::VERS_DATE));
        $info->appendChild($dom->createElement('Date', $date ?? '2026-01-01'));
        $info->appendChild($this->textElement($dom, 'ProgSystem', $progSystem));
        $root->appendChild($info);

        $prj = $dom->createElement('PrjInfo');
        $prj->appendChild($this->textElement($dom, 'NamePrj', $name));
        $prj->appendChild($dom->createElement('Cur', $currency));
        $root->appendChild($prj);

        $award = $dom->createElement('Award');
        $award->appendChild($dom->createElement('DP', $phase->value));
        $award->appendChild($dom->createElement('Cur', $currency));

        $boqEl = $dom->createElement('BoQ');
        if ($boq->getExternalId() !== null) {
            $boqEl->setAttribute('ID', $boq->getExternalId());
        }

        $boqInfo = $dom->createElement('BoQInfo');
        $boqInfo->appendChild($this->textElement($dom, 'Name', $name));
        // How many shares the client requires the unit price to be split into
        // (up to six, form 223 of the German VHB).
        $components = $boq->getUpComponents();
        if ($components !== []) {
            $boqInfo->appendChild($dom->createElement('NoUPComps', (string) count($components)));
            foreach ($components as $component) {
                $label = $this->textElement($dom, 'LblUPComp' . $component->getNo(), (string) $component->getLabel());
                if ($component->getCategory() !== null) {
                    $label->setAttribute('Type', $component->getCategory());
                }
                $boqInfo->appendChild($label);
            }
        }
        $boqEl->appendChild($boqInfo);

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
            $ctgy->setAttribute('RNoPart', $this->localPart($section->getReference(), $parentRef));
            if ($section->getLabel() !== null) {
                $ctgy->appendChild($this->htmlText($dom, 'LblTx', $section->getLabel()));
            }
            $childBody = $dom->createElement('BoQBody');
            $this->appendBody($dom, $childBody, $boq, $phase, $section->getReference(), $section->getReference());
            $ctgy->appendChild($childBody);
            $body->appendChild($ctgy);
        }

        $items = $this->itemsOf($boq, $parentReference);
        if ($items === []) {
            return;
        }

        $list = $dom->createElement('Itemlist');
        foreach ($items as $item) {
            $list->appendChild($this->itemElement($dom, $item, $phase, $parentRef));
        }
        $body->appendChild($list);
    }

    private function itemElement(DOMDocument $dom, GaebItem $item, GaebPhase $phase, string $parentRef): DOMElement {
        // Markup items and remarks are own elements of an Itemlist, not item
        // variants.
        $el = $dom->createElement(match ($item->getType()) {
            GaebItemType::Markup => 'MarkupItem',
            GaebItemType::Note => 'Remark',
            default => 'Item',
        });

        [$part, $index] = $this->splitIndex($this->localPart($item->getReference(), $parentRef));
        // The H level is an internal helper for remarks without an ordinal
        // number (see the parser) and does not belong back into the file.
        if (!($item->getType() === GaebItemType::Note && preg_match('/^H\d+$/', $part) === 1)) {
            $el->setAttribute('RNoPart', $part);
        }
        if ($index !== null) {
            $el->setAttribute('RNoIndex', $index);
        }

        if ($item->getType() === GaebItemType::Markup && $item->getMarkupType() !== null) {
            $el->appendChild($dom->createElement('MarkupType', $item->getMarkupType()));
        }
        if ($item->getProvisionKind() !== null) {
            $el->appendChild($dom->createElement('Provis', $item->getProvisionKind()));
        }
        if ($item->getAlternativeGroup() !== null) {
            $el->appendChild($dom->createElement('ALNGroupNo', $item->getAlternativeGroup()));
        }
        if ($item->getAlternativeNo() !== null) {
            $el->appendChild($dom->createElement('ALNSerNo', (string) $item->getAlternativeNo()));
        }
        if ($item->getUnitPriceComponents() !== []) {
            $el->appendChild($dom->createElement('UPBkdn', 'Yes'));
        }

        if ($item->getQuantity() !== null) {
            $el->appendChild($dom->createElement('Qty', $this->num($item->getQuantity())));
        }
        if ($item->getUnit() !== null) {
            $el->appendChild($this->textElement($dom, 'QU', $item->getUnit()));
        }

        $desc = $dom->createElement('Description');
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
        if ($item->getShortText() !== null) {
            $outline = $dom->createElement('OutlineText');
            $outlTxt = $dom->createElement('OutlTxt');
            $outlTxt->appendChild($this->htmlText($dom, 'TextOutlTxt', $item->getShortText()));
            $outline->appendChild($outlTxt);
            $complete->appendChild($outline);
        }
        if ($item->getLongText() !== null) {
            $complete->appendChild($this->detailText($dom, $item));
        }
        $desc->appendChild($complete);
        $el->appendChild($desc);

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
                if ($sub->getUnit() !== null) {
                    $subEl->appendChild($this->textElement($dom, 'QU', $sub->getUnit()));
                }
                $el->appendChild($subEl);
            }
        }

        if ($phase->carriesPrices() && $item->getUnitPrice() !== null) {
            $el->appendChild($dom->createElement('UP', $this->num($item->getUnitPrice())));
            foreach ($item->getUnitPriceComponents() as $i => $value) {
                $el->appendChild($dom->createElement('UPComp' . ($i + 1), $this->num($value)));
            }
            if ($item->getTotalPrice() !== null) {
                $el->appendChild($dom->createElement('IT', $this->num($item->getTotalPrice())));
            }
        }

        return $el;
    }

    /**
     * Long text with text complements: the markers become TextComplement blocks
     * between the text sections again. The number stays untouched - it has to
     * be returned unchanged with the bid.
     */
    private function detailText(DOMDocument $dom, GaebItem $item): DOMElement {
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
                if ($text !== '') {
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
