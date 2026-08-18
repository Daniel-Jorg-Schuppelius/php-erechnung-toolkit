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
use ERechnungToolkit\Entities\Gaeb\{GaebBoq, GaebCatalogAssignment, GaebChangeOrder, GaebCostElement, GaebCosting, GaebFrameworkAgreement, GaebInvoice, GaebItem, GaebOrder, GaebOrderItem, GaebParty, GaebSection, GaebTotals};
use ERechnungToolkit\Enums\{GaebAwardCategory, GaebChangeOrderStatus, GaebItemType, GaebPhase};
use ERechnungToolkit\Helper\Gaeb\{GaebCalculator, GaebTakeoffRecord};
use InvalidArgumentException;

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
        ?GaebParty $client = null,
        ?string $time = null,
        ?GaebAwardCategory $category = null,
        ?GaebFrameworkAgreement $frameworkAgreement = null,
        ?GaebInvoice $invoice = null,
        ?GaebOrder $order = null,
        ?GaebParty $site = null,
        ?GaebCosting $costing = null
    ): string {
        // Die Zeitvertragsphasen verlangen Angaben, die dieses Modell nicht
        // führt: Baustelle (`CnstSite`) und - beim Einzelauftrag - die Daten des
        // Einzelvertrags (`IndivAgrInfo`), beide Pflicht. Sie zu erfinden wäre
        // schlimmer als sie zu verweigern; gelesen werden die Phasen längst.
        // Der Einzelauftrag verlangt Stundenlohn- und Materialsätze des
        // Vertrags (`IndivAgrInfo`), die dieses Modell nicht führt; sie zu
        // erfinden wäre schlimmer, als die Phase zu verweigern.

        // Die Zeitvertragsphasen werden gelesen, aber noch nicht geschrieben:
        // Kopf und Parteien stimmen inzwischen, die Positionsebene weicht aber
        // ab (Menge entfällt, Preise stehen je Phase anders), und der
        // Einzelauftrag verlangt Sätze aus dem Einzelvertrag. Eine halb
        // richtige Vergabedatei ist schlimmer als eine klare Absage.
        if (!$phase->isWritableAsDaXml() && $invoice === null && $order === null) {
            throw new InvalidArgumentException(
                "Das Schreiben der Phase X{$phase->value} ist noch nicht umgesetzt - Lesen ist möglich."
            );
        }

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
        // Im Zeitvertrag sind Uhrzeit und Programmname Pflicht, in den übrigen
        // Phasen nicht - geschrieben werden sie überall, das kostet nichts und
        // erspart die Sonderbehandlung.
        $info->appendChild($dom->createElement('Time', $time ?? '00:00:00'));
        $info->appendChild($this->textElement($dom, 'ProgSystem', $progSystem));
        $info->appendChild($this->textElement($dom, 'ProgName', $progSystem));
        $root->appendChild($info);

        // PrjInfo carries no currency: the X84 project block is reduced to name
        // and identifiers. The currency belongs to AwardInfo, where it is
        // mandatory in every phase.
        // Die Mengenermittlung kennt keinen PrjInfo-Block; ihr Projektname steht
        // im Kopf der Mengenermittlung selbst.
        if ($phase !== GaebPhase::QuantitySurvey && !$phase->isFrameworkAgreement()) {
            $prj = $dom->createElement('PrjInfo');
            $prj->appendChild($this->textElement($dom, 'NamePrj', $name));
            $root->appendChild($prj);
        }

        // Die Mengenermittlung ist kein Vergabevorgang: Sie hängt unter
        // QtyDeterm statt unter Award und nennt statt Währung und Bieter das
        // Verfahren, nach dem gerechnet wurde.
        $isSurvey = $phase === GaebPhase::QuantitySurvey;

        $isInvoice = $phase === GaebPhase::Invoice || $phase === GaebPhase::InvoiceAttachment;
        $isTrade = $phase->isTrade();

        // Die Kostenermittlung beschreibt nicht, was zu tun ist, sondern was es
        // kosten soll - gegliedert nach Kostengruppen statt nach Gewerken.
        if ($phase->isCosting()) {
            $root->appendChild($this->costingElement($dom, $phase, $costing, $currency, $date));

            return (string) $dom->saveXML();
        }

        // Der Handel hat kein Leistungsverzeichnis: Er kauft Artikel, keine
        // Positionen, und ist damit hinter der Wurzel ein anderes Dokument.
        if ($isTrade) {
            $root->appendChild($this->orderElement($dom, $phase, $order, $currency, $date));

            return (string) $dom->saveXML();
        }

        $award = $dom->createElement(match (true) {
            $isSurvey => 'QtyDeterm',
            $isInvoice => 'Invoice',
            default => 'Award',
        });
        if ($isSurvey) {
            $surveyInfo = $dom->createElement('QtyDetermInfo');
            $surveyInfo->appendChild($dom->createElement('MethodDescription', self::SURVEY_METHOD));
            if ($name !== '') {
                $surveyInfo->appendChild($this->textElement($dom, 'ProjDescr', mb_substr($name, 0, 60)));
            }
            $award->appendChild($surveyInfo);
        }
        $award->appendChild($dom->createElement('DP', $phase->value));

        if ($isInvoice) {
            // Die Rechnung kennt keinen AwardInfo-Block: Ihr Kopf, die Parteien
            // und die Anteile hängen unmittelbar unter Invoice.
            // Parteien und Baustelle stehen vor dem Verzeichnis, Kopf und
            // Anteile dahinter - die Reihenfolge gibt das Schema vor.
            if ($client !== null) {
                $award->appendChild($this->partyElement($dom, 'OWN', $client));
            }
            if ($contractor !== null) {
                $award->appendChild($this->partyElement($dom, 'CTR', $contractor));
            }
            $award->appendChild($dom->createElement('CnstSite'));
        } elseif (!$isSurvey) {
            $award->appendChild($this->awardInfoElement($dom, $boq, $phase, $currency, $openingDate, $date, $category, $frameworkAgreement));

            // Der Auftraggeber steht im Schema vor dem Auftragnehmer und ist
            // in der Auftragserteilung Pflicht - ohne ihn ist die Datei für
            // X86 unvollständig, was der Preflight meldet.
            if ($phase === GaebPhase::FrameworkCallOff) {
                // Der Einzelauftrag nennt die Sätze des Einzelvertrags; ohne
                // Angaben bleibt der Block leer.
                $award->appendChild($dom->createElement('IndivAgrInfo'));
            }
            // Der Zeitvertrag führt die Parteien schlanker: ohne Länderkennung,
            // dafür mit der Vergabenummer am Auftraggeber - und das Angebot
            // nennt ihn nur über sie (an den Beispieldateien belegt).
            $framework = $phase->isFrameworkAgreement();
            if ($client !== null && $phase->carriesClient()) {
                // Angebot und Einzelauftrag nennen den Auftraggeber allein über
                // die Vergabenummer - seine Anschrift ist längst bekannt.
                $bare = $phase === GaebPhase::FrameworkBid || $phase === GaebPhase::FrameworkCallOff;
                $own = $bare
                    ? $dom->createElement('OWN')
                    : $this->partyElement($dom, 'OWN', $client, !$framework);
                if ($framework) {
                    $own->appendChild($this->textElement($dom, 'AwardNo', $frameworkAgreement?->getNumber() ?? '1'));
                }
                $award->appendChild($own);
            }
            if ($contractor !== null && $phase->carriesContractor()) {
                $award->appendChild($this->partyElement($dom, 'CTR', $contractor, !$framework));
            }
            // Die Baustelle ist im Zeitvertrag Pflicht und braucht dort eine
            // Anschrift. Fehlt sie, bleibt das Element leer - die Lücke gehört
            // in den Preflight, nicht in eine erfundene Adresse.
            if ($framework && $phase !== GaebPhase::FrameworkBid) {
                $cnstSite = $dom->createElement('CnstSite');
                if ($site !== null) {
                    $cnstSite->appendChild($this->addressElement($dom, $site));
                }
                $award->appendChild($cnstSite);
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
        // Nur das Zeitvertrag-Angebot verzichtet auf den Kurznamen.
        if (!$isSurvey && $phase !== GaebPhase::FrameworkBid) {
            $boqInfo->appendChild($this->textElement($dom, 'Name', mb_substr($name, 0, 20)));
        }
        if ($phase->carriesTexts()) {
            $boqInfo->appendChild($this->textElement($dom, 'LblBoQ', mb_substr($name, 0, 60)));
            // Der Einzelauftrag datiert das Verzeichnis, auf das er sich beruft.
            if ($phase === GaebPhase::FrameworkCallOff) {
                $boqInfo->appendChild($dom->createElement('Date', $date ?? '2026-01-01'));
            }
            // Der Textumfang gehört zur Ausschreibung; der Zeitvertrag kennt
            // ihn nicht.
            if (!$phase->isFrameworkAgreement()) {
                $boqInfo->appendChild($dom->createElement('OutlCompl', $this->textScope($boq)));
            }
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
        // Reihenfolge nach Schema: erst die Summen, dann die Kataloge. Die
        // Musterdateien führen keinen Katalog in `BoQInfo`, weshalb die
        // vertauschte Folge dort nie auffiel - eine Kundendatei mit
        // DIN-276-Kostengruppen hat sie sichtbar gemacht.
        if (!$isSurvey) {
            // Die Rechnung verlangt die Summe des Verzeichnisses; wo das
            // Dokument keine mitbringt, wird sie gebildet.
            if ($isInvoice && $boq->getTotals()?->getTotal() === null) {
                $boqInfo->appendChild($this->totalElement($dom, $this->calculator->documentTotal($boq)));
            } else {
                $this->appendTotals($dom, $boqInfo, $boq->getTotals());
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
            $boqEl->appendChild($boqInfo);
        }

        $body = $dom->createElement('BoQBody');
        $this->appendBody($dom, $body, $boq, $phase, null, '');
        $boqEl->appendChild($body);

        $award->appendChild($boqEl);
        if ($isInvoice) {
            $this->appendInvoiceDetails($dom, $award, $invoice, $boq, $phase);
        }
        $root->appendChild($award);

        return (string) $dom->saveXML();
    }

    private function appendBody(DOMDocument $dom, DOMElement $body, GaebBoq $boq, GaebPhase $phase, ?string $parentReference, string $parentRef): void {
        foreach ($this->sectionsOf($boq, $parentReference) as $section) {
            $ctgy = $dom->createElement('BoQCtgy');
            $ctgy->setAttribute('ID', $section->getExternalId() ?? $this->identifier('C', $section->getReference()));
            $ctgy->setAttribute('RNoPart', $this->localPart($section->getReference(), $parentRef));
            // Der Einzelauftrag ruft ab, was benannt ist - er beschriftet die
            // Gruppen nicht erneut.
            if ($section->getLabel() !== null && $phase->carriesTexts() && $phase !== GaebPhase::FrameworkCallOff) {
                $ctgy->appendChild($this->htmlText($dom, 'LblTx', $section->getLabel()));
            }
            if ($phase->carriesTexts()) {
                $this->appendCatalogAssignments($dom, $ctgy, $section->getCatalogAssignments());
            }
            $childBody = $dom->createElement('BoQBody');
            $this->appendBody($dom, $childBody, $boq, $phase, $section->getReference(), $section->getReference());
            $ctgy->appendChild($childBody);
            // Die Zeitvertrag-Aufforderung fragt das Auf-/Abgebot auch je
            // Gruppe ab, nicht nur je Position - hinter deren Inhalt.
            if ($phase === GaebPhase::FrameworkRequestForBid) {
                $ctgy->appendChild($dom->createElement('BidUpDownReq', 'Yes'));
            }
            // The bid demands a sum on every group; where the document carries
            // none it is added up from the items below. Ohne Preise gibt es
            // nichts zu summieren - die Mengenermittlung weist ihr Schema ab.
            // Jede Gruppe einer Preisphase muss ihre Summe nennen; wo das
            // Dokument keine mitbringt, wird sie aus den Positionen gebildet.
            // Der Zeitvertrag bepreist ohne Menge - eine Gruppensumme gäbe es
            // dort nicht zu bilden.
            if ($phase->carriesPrices() && !$phase->isFrameworkAgreement()) {
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
        if ($carriesQuantity && $phase->carriesBilledQuantity() && $item->getQuantity() !== null) {
            // Die Rechnung nennt die abgerechnete Menge, nicht die beauftragte.
            $el->appendChild($dom->createElement('BillQty', $this->num($item->getQuantity())));
        }
        if ($carriesQuantity && $item->getQuantity() !== null && $phase->carriesQuantities()
            && !$phase->carriesBilledQuantity()
            && !($item->hasFreeQuantity() && $phase === GaebPhase::RequestForBid)) {
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
        // Die Einheit gehört zur Leistung, nicht zur Menge: Der Zeitvertrag
        // bepreist „je m²", ohne schon zu wissen, wie viele es werden. Der
        // Einzelauftrag ruft umgekehrt nur eine Menge ab - Einheit und
        // Beschreibung stehen im Rahmenvertrag.
        if ($carriesQuantity && $item->getUnit() !== null && ($phase->carriesQuantities() || ($phase->carriesBidUpDown() && $phase->carriesTexts()))
            && $phase !== GaebPhase::FrameworkCallOff) {
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
        // Der Einzelauftrag beruft sich auf die Position im Rahmenvertrag,
        // statt ihren Text zu wiederholen - dafür steht die Katalognummer.
        if ($phase === GaebPhase::FrameworkCallOff) {
            $reference = $dom->createElement('Description');
            $reference->appendChild($this->textElement($dom, 'WICNo', $item->getCatalogueNo() ?? $item->getReference()));
            $el->appendChild($reference);
        }

        if ($phase->carriesTexts() && $phase !== GaebPhase::FrameworkCallOff && ($shortText !== null || $longText !== null)) {
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
        // Zeitvertrag: Die Aufforderung fragt das Auf-/Abgebot ab, Angebot und
        // Rahmenauftrag tragen den Satz. Es steht hinter der Beschreibung.
        if ($phase->carriesBidUpDown()) {
            if ($phase === GaebPhase::FrameworkRequestForBid) {
                if ($item->isBidUpDownRequired()) {
                    $el->appendChild($dom->createElement('BidUpDownReq', 'Yes'));
                }
            } elseif ($item->getBidUpDownPercent() !== null) {
                $el->appendChild($dom->createElement('BidUpDownPct', $this->num($item->getBidUpDownPercent())));
            }
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

        // Auch ein Verzeichnis ohne Positionen muss seinen Aufbau nennen -
        // eine Rechnung ohne LV ist zulässig, ein leerer Kopf nicht.
        if ($breakdown === []) {
            $breakdown[] = ['Item', 4];
        }

        foreach (array_slice($breakdown, 0, 7) as [$type, $length]) {
            $entry = $dom->createElement('BoQBkdn');
            $entry->appendChild($dom->createElement('Type', $type));
            $entry->appendChild($dom->createElement('Length', (string) $length));
            $entry->appendChild($dom->createElement('Num', $numeric ? 'Yes' : 'No'));
            $boqInfo->appendChild($entry);
        }
    }

    /**
     * Head of the award. Its content differs sharply per phase: the framework
     * bid names nothing but its date, the single call-off the contract number,
     * and only the request and the framework agreement itself describe the
     * agreement. Writing one shape for all of them produces invalid files.
     */
    private function awardInfoElement(
        DOMDocument $dom,
        GaebBoq $boq,
        GaebPhase $phase,
        string $currency,
        ?string $openingDate,
        ?string $date,
        ?GaebAwardCategory $category,
        ?GaebFrameworkAgreement $framework
    ): DOMElement {
        $el = $dom->createElement('AwardInfo');
        $day = $date ?? '2026-01-01';

        if ($phase === GaebPhase::FrameworkBid) {
            $el->appendChild($dom->createElement('BidDate', $openingDate ?? $day));

            return $el;
        }

        if ($phase === GaebPhase::FrameworkCallOff) {
            $el->appendChild($this->textElement($dom, 'ContrNo', $framework?->getNumber() ?? '1'));
            $el->appendChild($dom->createElement('ContrDate', $day));

            return $el;
        }

        // Die Vergabeart steht vor der Währung; im Zeitvertrag ist sie Pflicht
        // und auf vier Werte begrenzt - eine Rahmenvereinbarung wird nicht im
        // offenen Verfahren vergeben.
        if ($phase->carriesAwardCategory()) {
            $chosen = $category ?? ($phase->isFrameworkAgreement() ? GaebAwardCategory::PublicInvitation : null);
            if ($chosen !== null) {
                $el->appendChild($dom->createElement('Cat', $chosen->value));
            }
        }
        $el->appendChild($dom->createElement('Cur', $currency));

        if ($phase === GaebPhase::FrameworkAgreement) {
            $el->appendChild($dom->createElement('BidDate', $openingDate ?? $day));
            $el->appendChild($this->frameworkElement($dom, $framework, true));

            return $el;
        }

        if ($openingDate !== null || $phase === GaebPhase::FrameworkRequestForBid) {
            $el->appendChild($dom->createElement('OpenDate', $openingDate ?? $day));
        }
        if ($phase === GaebPhase::FrameworkRequestForBid) {
            $el->appendChild($this->frameworkElement($dom, $framework));
        }

        // Nachtragsköpfe beschreiben den Vorgang, nicht das Verzeichnis, und
        // stehen im Schema vor dem Rahmenvertrag.
        foreach ($phase->carriesChangeOrderHead() ? $boq->getChangeOrders() : [] as $order) {
            $el->appendChild($this->changeOrderElement($dom, $order));
        }

        return $el;
    }

    /**
     * Head, parties and components of an invoice - everything the X89 carries
     * behind its bill of quantity.
     *
     * The layout of the components is not GAEB's but the client's: the standard
     * only offers the list of types, the captions come from the award (see the
     * technical documentation, 8.1.2). The order is the computation order, so
     * it is written exactly as handed in.
     */
    private function appendInvoiceDetails(DOMDocument $dom, DOMElement $parent, ?GaebInvoice $invoice, GaebBoq $boq, GaebPhase $phase): void {
        if ($invoice === null) {
            return;
        }

        // Die rechnungsbegründende Unterlage ist keine Rechnung im Sinne des
        // Handelsrechts: Sie verweist auf die Nummer der Rechnung, zu der sie
        // gehört, statt eine eigene zu führen.
        if ($phase === GaebPhase::InvoiceAttachment) {
            $header = $dom->createElement('InvoiceHeader');
            $header->appendChild($this->textElement($dom, 'RefInvoiceNo', $invoice->getNumber()));
            $header->appendChild($dom->createElement('ServiceProvisionStartDate', $invoice->getServiceStart()));
            $header->appendChild($dom->createElement('ServiceProvisionEndDate', $invoice->getServiceEnd()));
            $parent->appendChild($header);

            $creator = $dom->createElement('InvoiceCreator');
            if ($invoice->getCreator() !== null) {
                $creator->appendChild($this->addressElement($dom, $invoice->getCreator()));
            }
            $creator->appendChild($this->textElement($dom, 'TaxNo', $invoice->getCreatorTaxNumber() ?? ''));
            $parent->appendChild($creator);

            return;
        }

        $header = $dom->createElement('InvoiceHeader');
        $header->appendChild($this->textElement($dom, 'InvoiceNo', $invoice->getNumber()));
        $header->appendChild($dom->createElement('InvoiceDate', $invoice->getDate()));
        $header->appendChild($dom->createElement('InvoiceType', $invoice->getType()->value));
        if ($invoice->isCreditNote()) {
            $header->appendChild($dom->createElement('CreditNote', 'Yes'));
        }
        if ($invoice->getSequentialNo() !== null) {
            $header->appendChild($this->textElement($dom, 'SequentialNo', $invoice->getSequentialNo()));
        }
        $header->appendChild($dom->createElement('ServiceProvisionStartDate', $invoice->getServiceStart()));
        $header->appendChild($dom->createElement('ServiceProvisionEndDate', $invoice->getServiceEnd()));
        $parent->appendChild($header);

        // Der Ersteller nennt seine Steuernummer - Pflichtangabe des
        // Steuerrechts, nicht des Formats.
        $creator = $dom->createElement('InvoiceCreator');
        if ($invoice->getCreator() !== null) {
            $creator->appendChild($this->addressElement($dom, $invoice->getCreator()));
        }
        $creator->appendChild($this->textElement($dom, 'TaxNo', $invoice->getCreatorTaxNumber() ?? ''));
        $parent->appendChild($creator);

        $recipient = $dom->createElement('InvoiceRecipient');
        if ($invoice->getRecipient() !== null) {
            $recipient->appendChild($this->addressElement($dom, $invoice->getRecipient()));
        }
        $parent->appendChild($recipient);

        foreach ($invoice->getShares() as $share) {
            $el = $dom->createElement('InvoiceShare');
            $el->appendChild($dom->createElement('InvoiceShareType', $share->getType()->value));
            $el->appendChild($this->textElement($dom, 'Description', mb_substr($share->getDescription(), 0, 256)));
            // Betrag oder Prozentsatz, nie beides: Das Schema stellt sie zur
            // Wahl, weil ein Anteil auf genau eine Art definiert ist. Liegt
            // beides vor, gewinnt der Betrag - er ist der konkretere Wert.
            if ($share->getTotal() !== null) {
                $el->appendChild($dom->createElement('Total', $this->amount($share->getTotal())));
            } elseif ($share->getPercent() !== null) {
                $el->appendChild($dom->createElement('Percent', $this->num($share->getPercent())));
            }
            if ($share->isCounterClaim()) {
                $el->appendChild($dom->createElement('CounterClaim', 'Yes'));
            }
            $parent->appendChild($el);
        }

        $parent->appendChild($dom->createElement(
            'TotalGross',
            $this->amount($invoice->getTotalGross() ?? $this->calculator->documentTotal($boq))
        ));
    }

    /**
     * A trade document: head, both parties and the order lines.
     *
     * Prices belong to the phases that name them - the inquiry asks without
     * them, the offer answers with them. Everything else is the same shape, so
     * one builder serves all four phases.
     */
    private function orderElement(DOMDocument $dom, GaebPhase $phase, ?GaebOrder $order, string $currency, ?string $date): DOMElement {
        $el = $dom->createElement('Order');
        $el->appendChild($dom->createElement('DP', $phase->value));

        $info = $dom->createElement('OrderInfo');
        foreach (['InquiryNo' => $order?->getInquiryNo(), 'OfferNo' => $order?->getOfferNo(), 'OrderConfNo' => $order?->getOrderConfirmationNo()] as $name => $value) {
            if ($value !== null) {
                $info->appendChild($this->textElement($dom, $name, mb_substr($value, 0, 15)));
            }
        }
        $info->appendChild($dom->createElement('DeliveryDate', $order?->getDeliveryDate() ?? $date ?? '2026-01-01'));
        $cur = $dom->createElement('Cur', $currency);
        $cur->setAttribute('ISO', $currency);
        $info->appendChild($cur);
        if ($order?->getVatRate() !== null) {
            $info->appendChild($dom->createElement('VAT', $this->num($order->getVatRate())));
        }
        $el->appendChild($info);

        // Steuer- und Handelsregisternummer des Lieferanten müssen als
        // Elemente dastehen, ihr Inhalt darf aber leer sein - und das muss er
        // können: Ein Einzelunternehmer ohne Eintrag hat keine HR-Nummer. Der
        // Kunde nennt umgekehrt nur seine Anschrift.
        $supplier = $dom->createElement('SupplierInfo');
        if ($order?->getSupplier() !== null) {
            $supplier->appendChild($this->addressElement($dom, $order->getSupplier()));
        }
        $supplier->appendChild($this->textElement($dom, 'TaxNo', $order?->getSupplierTaxNo() ?? ''));
        $supplier->appendChild($this->textElement($dom, 'RegNo', $order?->getSupplierRegisterNo() ?? ''));
        $el->appendChild($supplier);

        $customer = $dom->createElement('CustomerInfo');
        if ($order?->getCustomer() !== null) {
            $customer->appendChild($this->addressElement($dom, $order->getCustomer()));
        }
        $el->appendChild($customer);

        foreach ($order?->getItems() ?? [] as $item) {
            $el->appendChild($this->orderItemElement($dom, $phase, $item, $order));
        }

        return $el;
    }

    /** One order line - identified by article number, not by an ordinal one. */
    private function orderItemElement(DOMDocument $dom, GaebPhase $phase, GaebOrderItem $item, ?GaebOrder $order): DOMElement {
        $el = $dom->createElement('OrderItem');
        // Auch die Bestellzeile trägt eine Pflicht-ID (xs:ID); sie wird aus der
        // Artikelnummer abgeleitet, damit wiederholte Exporte gleich bleiben.
        $el->setAttribute('ID', $this->identifier('A', $item->getCatalogArticleNo()));

        if ($item->getEan() !== null) {
            $el->appendChild($dom->createElement('EAN', $item->getEan()));
        }
        if ($item->getSupplierArticleNo() !== null) {
            $el->appendChild($this->textElement($dom, 'SupplierArtNo', mb_substr($item->getSupplierArticleNo(), 0, 15)));
        }
        if ($item->getCustomerArticleNo() !== null) {
            $el->appendChild($this->textElement($dom, 'CustomerArtNo', mb_substr($item->getCustomerArticleNo(), 0, 15)));
        }
        $el->appendChild($this->textElement($dom, 'CatalogArtNo', $item->getCatalogArticleNo()));
        if ($item->getQuantity() !== null) {
            $el->appendChild($dom->createElement('Qty', $this->num($item->getQuantity())));
        }
        if ($item->getUnit() !== null) {
            $el->appendChild($this->textElement($dom, 'QU', mb_substr($item->getUnit(), 0, 4)));
        }
        if ($item->getDescription() !== null) {
            $description = $dom->createElement('Description');
            $description->appendChild($this->outlineText($dom, $item->getDescription()));
            $el->appendChild($description);
        }
        // Die Preisanfrage fragt ohne Preis; erst das Angebot nennt einen.
        if ($phase->carriesPrices() && $item->getPrice() !== null) {
            $el->appendChild($dom->createElement('NetPrice', $this->amount($item->getPrice())));
        }
        $el->appendChild($dom->createElement('DeliveryDate', $item->getDeliveryDate() ?? $order?->getDeliveryDate() ?? '2026-01-01'));
        if ($item->getVatRate() !== null) {
            $el->appendChild($dom->createElement('VAT', $this->num($item->getVatRate())));
        }
        if ($item->getWeight() !== null) {
            $el->appendChild($dom->createElement('Weight', $this->num($item->getWeight())));
            $el->appendChild($this->textElement($dom, 'UW', $item->getWeightUnit() ?? 'kg'));
        }

        return $el;
    }

    /**
     * A cost determination (X50 building cost catalogue, X51 costing).
     *
     * Elements nest, and their number decides the shape of the document: the
     * `.2` form writes it out in full on every level, the `.1` form gives only
     * the part of the current level.
     */
    private function costingElement(DOMDocument $dom, GaebPhase $phase, ?GaebCosting $costing, string $currency, ?string $date): DOMElement {
        $el = $dom->createElement('ElementalCosting');
        $el->appendChild($dom->createElement('DP', $phase->value));

        $info = $dom->createElement('ECInfo');
        $info->appendChild($this->textElement($dom, 'Name', mb_substr($costing?->getName() ?? 'Kostenermittlung', 0, 20)));
        if ($costing?->getLabel() !== null) {
            $info->appendChild($this->textElement($dom, 'LblEC', mb_substr($costing->getLabel(), 0, 60)));
        }
        if ($costing?->getType() !== null) {
            $info->appendChild($dom->createElement('ECType', $costing->getType()->value));
        }
        if ($costing?->getMethod() !== null) {
            $info->appendChild($dom->createElement('ECMethod', $costing->getMethod()->value));
        }
        $info->appendChild($dom->createElement('Date', $costing?->getDate() ?? $date ?? '2026-01-01'));
        $info->appendChild($dom->createElement('Cur', $currency));
        $el->appendChild($info);

        $body = $dom->createElement('ECBody');
        foreach ($costing?->getElements() ?? [] as $element) {
            $body->appendChild($this->costElement($dom, $element, $costing?->hasFullElementNumbers() ?? true));
        }
        $el->appendChild($body);

        return $el;
    }

    /** One cost element, with its children nested inside it. */
    private function costElement(DOMDocument $dom, GaebCostElement $element, bool $fullNumbers): DOMElement {
        $el = $dom->createElement('CostElement');

        if ($element->getNumber() !== null) {
            $el->appendChild($this->textElement($dom, $fullNumbers ? 'EleNo' : 'ElePart', $element->getNumber()));
        }
        $el->appendChild($this->textElement($dom, 'Descr', mb_substr($element->getDescription(), 0, 60)));
        $this->appendCatalogAssignments($dom, $el, $element->getCatalogAssignments());
        if ($element->getRemark() !== null) {
            $el->appendChild($this->htmlText($dom, 'Remark', $element->getRemark()));
        }
        if ($element->getQuantity() !== null) {
            $el->appendChild($dom->createElement('Qty', $this->num($element->getQuantity())));
        }
        $el->appendChild($this->textElement($dom, 'QU', $element->getUnit()));
        if ($element->getUnitPrice() !== null) {
            $el->appendChild($dom->createElement('UP', $this->amount($element->getUnitPrice())));
        }
        if ($element->getTotal() !== null) {
            $el->appendChild($dom->createElement('IT', $this->amount($element->getTotal())));
        }
        // Ein früher Kennwert ist eine Spanne, keine Zahl - das Format hält das
        // aus, und die Ehrlichkeit gehört mit übertragen.
        foreach (['UPFrom' => $element->getUnitPriceFrom(), 'UPAvg' => $element->getUnitPriceAverage(), 'UPTo' => $element->getUnitPriceTo()] as $name => $value) {
            if ($value !== null) {
                $el->appendChild($dom->createElement($name, $this->amount($value)));
            }
        }
        foreach ($element->getChildren() as $child) {
            $el->appendChild($this->costElement($dom, $child, $fullNumbers));
        }

        return $el;
    }

    /** Bare address block, as the invoice parties carry it. */
    private function addressElement(DOMDocument $dom, GaebParty $party): DOMElement {
        $address = $dom->createElement('Address');
        $address->appendChild($this->textElement($dom, 'Name1', $party->getName()));
        $address->appendChild($this->textElement($dom, 'Street', $party->getStreet()));
        $address->appendChild($this->textElement($dom, 'PCode', $party->getPostalCode()));
        $address->appendChild($this->textElement($dom, 'City', $party->getCity()));

        return $address;
    }

    /**
     * Framework agreement head. Label, description and period are mandatory;
     * where the caller names none, the document says so plainly instead of
     * inventing a term.
     */
    private function frameworkElement(DOMDocument $dom, ?GaebFrameworkAgreement $framework, bool $withDate = false): DOMElement {
        $el = $dom->createElement('MastAgrInfo');
        // BidUpDown ist ein Kennzeichen („Auf-/Abgebot zugelassen"), kein Satz.
        $el->appendChild($dom->createElement('BidUpDown', 'Yes'));
        $el->appendChild($this->htmlText($dom, 'MastAgrLbl', mb_substr($framework?->getLabel() ?? 'Zeitvertrag', 0, 60)));
        if ($framework?->getNumber() !== null) {
            $el->appendChild($this->textElement($dom, 'MastAgrNo', $framework->getNumber()));
        }
        $el->appendChild($this->htmlText($dom, 'Descrip', $framework?->getDescription() ?? 'Ohne Angabe'));
        $el->appendChild($dom->createElement('MastAgrBeg', $framework?->getBegin() ?? '2026-01-01'));
        $el->appendChild($dom->createElement('MastAgrEnd', $framework?->getEnd() ?? '2026-12-31'));
        if ($withDate) {
            // Der Rahmenauftrag nennt zusätzlich den Tag des Vertragsschlusses.
            $el->appendChild($dom->createElement('MastAgrDate', $framework?->getBegin() ?? '2026-01-01'));
        }
        if ($framework?->getMinimumValue() !== null) {
            $el->appendChild($dom->createElement('MinContrVal', $framework->getMinimumValue()));
        }
        if ($framework?->getMinimumAward() !== null) {
            $el->appendChild($dom->createElement('MinContrAwd', $framework->getMinimumAward()));
        }

        return $el;
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

    /**
     * Party block. The country type belongs to the award phases only - the
     * framework agreement schemas carry the bare address, as the official
     * example files show.
     */
    private function partyElement(DOMDocument $dom, string $name, GaebParty $party, bool $withCountryType = true): DOMElement {
        $el = $dom->createElement($name);

        $address = $dom->createElement('Address');
        $address->appendChild($this->textElement($dom, 'Name1', $party->getName()));
        $address->appendChild($this->textElement($dom, 'Street', $party->getStreet()));
        $address->appendChild($this->textElement($dom, 'PCode', $party->getPostalCode()));
        $address->appendChild($this->textElement($dom, 'City', $party->getCity()));
        $el->appendChild($address);
        if ($withCountryType) {
            $el->appendChild($dom->createElement('CntryType', $party->isWithinEea() ? 'EEA' : 'Other'));
        }

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
