<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DatanormGenerator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Generators;

use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Entities\Datanorm\{DatanormArticle, DatanormCatalog, DatanormPriceChange, DatanormRawMaterialSurcharge, DatanormScalePrice, DatanormTextBlock};
use ERechnungToolkit\Enums\{DatanormDiscountKind, DatanormVersion};
use ERechnungToolkit\Helper\DatanormCharset;
use ERRORToolkit\Traits\ErrorLog;
use InvalidArgumentException;

/**
 * Generator for DATANORM 4 and 5 files.
 *
 * Produces article files (DATANORM.nnn), product group files (DATANORM.WRG),
 * discount group files (DATANORM.RAB) and price files (DATPREIS.nnn) from a
 * {@see DatanormCatalog}. Output is codepage 850 with CRLF line endings, every
 * record ends with the field separator, DATANORM 5 files close with an
 * E-record whose line count includes the V- and E-record.
 *
 * DATANORM 4 splits each article into an A- and a B-record, uses the
 * fixed-width 128-character header and codes the price unit (only 1/10/100/
 * 1000 are possible — other unit amounts raise an exception). Long texts are
 * emitted as text blocks (T-records; insert blocks become E-records in
 * DATANORM 4). Per article, scale prices and raw material surcharges become
 * Z-records and working times C-records (ARBA/ARBEIT-1); G-records are not
 * generated.
 */
final class DatanormGenerator {
    use ErrorLog;

    private const EOL = "\r\n";

    /** Unit amount → DATANORM 4 price unit code. */
    private const V4_PRICE_UNIT_CODES = [1 => '0', 10 => '1', 100 => '2', 1000 => '3'];

    /** Common ISO (UN/ECE) unit codes → DATANORM 4 free-text units. */
    private const V4_UNIT_MAP = [
        'PCE' => 'Stck',
        'C62' => 'Stck',
        'H87' => 'Stck',
        'MTR' => 'm',
        'MMT' => 'mm',
        'CMT' => 'cm',
        'KMT' => 'km',
        'MTK' => 'qm',
        'MTQ' => 'cbm',
        'KGM' => 'kg',
        'GRM' => 'g',
        'TNE' => 't',
        'LTR' => 'l',
        'HUR' => 'Std',
        'MIN' => 'min',
        'PR' => 'Paar',
        'SET' => 'Satz',
        'STG' => 'Stg',
        'DZN' => 'Dtzd',
    ];

    /**
     * Generates an article file (DATANORM.nnn): header, optional customer
     * record, text blocks, articles (A/B), deletions/renumberings, footer.
     */
    public function generateArticleFile(DatanormCatalog $catalog, ?DatanormVersion $version = null): string {
        $version = $version ?? $catalog->getVersion();
        $lines = [$this->header($catalog, $version)];
        $this->appendCustomer($lines, $catalog, $version);

        foreach ($catalog->getTextBlocks() as $block) {
            foreach ($this->textBlockRecords($block, $version) as $record) {
                $lines[] = $record;
            }
        }

        foreach ($catalog->getArticles() as $article) {
            $lines[] = $this->articleRecord($article, $catalog, $version);
            if ($version === DatanormVersion::V4) {
                $lines[] = $this->articleSupplementRecordV4($article);
            }
            foreach ($this->surchargeRecords($article, $version) as $record) {
                $lines[] = $record;
            }
            foreach ($this->workTimeRecords($article, $version) as $record) {
                $lines[] = $record;
            }
        }

        foreach ($catalog->getArticleChanges() as $change) {
            $lines[] = $version === DatanormVersion::V4
                ? ($change->isRenumber()
                    ? $this->record(['A', 'X', $change->getArticleNumber(), '', $change->getNewArticleNumber() ?? ''], $version)
                    : $this->record(['A', 'L', $change->getArticleNumber()], $version))
                : ($change->isRenumber()
                    ? $this->record(['B', 'A', $change->getArticleNumber(), $change->getNewArticleNumber() ?? ''], $version)
                    : $this->record([
                        'B',
                        'L',
                        $change->getArticleNumber(),
                        $change->getSuccessorArticleNumber() ?? '',
                        $change->getExpirationDate()?->format('Ymd') ?? '',
                    ], $version));
        }

        return $this->finish($lines, $catalog, $version);
    }

    /**
     * Generates a product group file (DATANORM.WRG).
     */
    public function generateProductGroupFile(DatanormCatalog $catalog, ?DatanormVersion $version = null): string {
        $version = $version ?? $catalog->getVersion();
        $lines = [$this->header($catalog, $version, 'S')];
        $this->appendCustomer($lines, $catalog, $version);

        foreach ($catalog->getProductGroups() as $group) {
            $lines[] = $version === DatanormVersion::V4
                ? ($group->isMainGroup()
                    ? $this->record(['S', '', $group->getMainGroup(), $group->getLabel(), '', ''], $version)
                    : $this->record(['S', '', $group->getMainGroup(), '', $group->getGroup() ?? '', $group->getLabel()], $version))
                : $this->record(['S', $group->getMainGroup(), $group->getGroup() ?? '', $group->getLabel()], $version);
        }

        return $this->finish($lines, $catalog, $version);
    }

    /**
     * Generates a discount group file (DATANORM.RAB).
     */
    public function generateDiscountGroupFile(DatanormCatalog $catalog, ?DatanormVersion $version = null): string {
        $version = $version ?? $catalog->getVersion();
        $lines = [$this->header($catalog, $version, 'R')];
        $this->appendCustomer($lines, $catalog, $version);

        foreach ($catalog->getDiscountGroups() as $group) {
            $fields = [
                'R',
                $group->getCode(),
                (string) $group->getKind()->value,
                $this->discountValueField($group->getKind(), $group->getValue()),
                $group->getLabel() ?? '',
            ];
            if ($version === DatanormVersion::V4) {
                array_splice($fields, 1, 0, ['']);
            }
            $lines[] = $this->record($fields, $version);
        }

        return $this->finish($lines, $catalog, $version);
    }

    /**
     * Generates a price change file (DATPREIS.nnn). DATANORM 4 packs up to
     * three articles into one P-record and cannot transport a price unit.
     */
    public function generatePriceFile(DatanormCatalog $catalog, ?DatanormVersion $version = null): string {
        $version = $version ?? $catalog->getVersion();
        $lines = [$this->header($catalog, $version, 'P')];
        $this->appendCustomer($lines, $catalog, $version);

        if ($version === DatanormVersion::V5) {
            foreach ($catalog->getPriceChanges() as $change) {
                $fields = [
                    'P',
                    $change->getArticleNumber(),
                    (string) $change->getPriceIndicator()->value,
                    (string) ($change->getPriceUnitAmount() ?? 1),
                    $this->minor($change->getPrice()),
                    $change->getDiscountGroup() ?? '',
                ];
                foreach ($this->discountPairs($change, 3) as $pair) {
                    array_push($fields, ...$pair);
                }
                $fields[] = $change->getValidFrom()?->format('Ymd') ?? '';
                $lines[] = $this->record($fields, $version);
            }
        } else {
            foreach (array_chunk($catalog->getPriceChanges(), 3) as $chunk) {
                $fields = ['P', 'A'];
                foreach ($chunk as $change) {
                    if ($change->getPriceUnitAmount() !== null && $change->getPriceUnitAmount() !== 1 && !isset(self::V4_PRICE_UNIT_CODES[$change->getPriceUnitAmount()])) {
                        $this->logErrorAndThrow(InvalidArgumentException::class, sprintf(
                            'DATANORM 4 price records carry no price unit — article "%s" with unit amount %d cannot be exported.',
                            $change->getArticleNumber(),
                            $change->getPriceUnitAmount()
                        ));
                    }
                    $fields[] = $change->getArticleNumber();
                    $fields[] = (string) $change->getPriceIndicator()->value;
                    $fields[] = $this->minor($change->getPrice());
                    if ($change->getDiscountGroup() !== null) {
                        $fields[] = '0';
                        $fields[] = $change->getDiscountGroup();
                        $fields[] = '';
                        $fields[] = '';
                        $fields[] = '';
                        $fields[] = '';
                    } else {
                        foreach ($this->discountPairs($change, 3) as $pair) {
                            array_push($fields, ...$pair);
                        }
                    }
                }
                $lines[] = $this->record($fields, $version);
            }
        }

        return $this->finish($lines, $catalog, $version);
    }

    // ------------------------------------------------------------------
    // Records
    // ------------------------------------------------------------------

    private function header(DatanormCatalog $catalog, DatanormVersion $version, ?string $dataMark = null): string {
        $date = $catalog->getCreationDate();
        if ($version === DatanormVersion::V4) {
            $line = 'V ';
            $line .= $date?->format('dmy') ?? '000000';
            $info = preg_split('/\r\n|\r|\n/', $catalog->getInfoText() ?? $catalog->getDescription() ?? '') ?: [];
            $line .= str_pad($this->enc($info[0] ?? '', $version, 40), 40);
            $line .= str_pad($this->enc($info[1] ?? '', $version, 40), 40);
            $line .= str_pad($this->enc($info[2] ?? '', $version, 35), 35);
            $line .= DatanormVersion::V4->value;
            $line .= str_pad(substr($catalog->getCurrency()->value, 0, 3), 3);

            return $line;
        }

        return $this->record([
            'V',
            DatanormVersion::V5->value,
            $dataMark ?? $catalog->getDataMark()->value ?? 'A',
            $date?->format('Ymd') ?? '',
            $catalog->getCurrency()->value,
            $catalog->getDescription() ?? '',
            $catalog->getCopyright() ?? '',
            $catalog->getCreatorShortName() ?? '',
            $catalog->getCreatorName() ?? '',
            '',
            '',
            $catalog->getCreatorStreet() ?? '',
            $catalog->getCreatorCountry() ?? '',
            $catalog->getCreatorZip() ?? '',
            $catalog->getCreatorCity() ?? '',
        ], $version);
    }

    /** @param list<string> $lines */
    private function appendCustomer(array &$lines, DatanormCatalog $catalog, DatanormVersion $version): void {
        $customer = $catalog->getCustomer();
        if ($customer === null) {
            return;
        }
        $lines[] = $version === DatanormVersion::V4
            ? $this->record(['K', '', $customer->getCustomerNumber(), ' '], $version)
            : $this->record([
                'K',
                $customer->getCustomerNumber(),
                $customer->getName() ?? '',
                '',
                '',
                $customer->getStreet() ?? '',
                $customer->getCountry() ?? '',
                $customer->getZip() ?? '',
                $customer->getCity() ?? '',
            ], $version);
    }

    /** @return list<string> */
    private function textBlockRecords(DatanormTextBlock $block, DatanormVersion $version): array {
        $records = [];
        $lines = $block->getLines();
        if ($version === DatanormVersion::V5) {
            foreach ($lines as $lineNo => $text) {
                $records[] = $this->record([
                    'T',
                    'N',
                    $block->getNumber(),
                    (string) $block->getUsage(),
                    str_pad((string) $lineNo, 2, '0', STR_PAD_LEFT),
                    $text,
                ], $version, [5 => $block->getUsage() === DatanormTextBlock::USAGE_UNBOUND ? 70 : 40]);
            }

            return $records;
        }

        // DATANORM 4: two lines per record; insert blocks travel as E-records.
        $type = $block->getUsage() === DatanormTextBlock::USAGE_INSERT ? 'E' : 'T';
        $pairs = [];
        foreach ($lines as $lineNo => $text) {
            $pairs[] = [(string) $lineNo, $text];
        }
        foreach (array_chunk($pairs, 2) as $pair) {
            $fields = [$type, 'N', $block->getNumber(), '', $pair[0][0], '', $pair[0][1]];
            if (isset($pair[1])) {
                $fields[] = $pair[1][0];
                $fields[] = '';
                $fields[] = $pair[1][1];
            }
            $records[] = $this->record($fields, $version, [6 => 40, 9 => 40]);
        }

        return $records;
    }

    private function articleRecord(DatanormArticle $article, DatanormCatalog $catalog, DatanormVersion $version): string {
        if ($version === DatanormVersion::V5) {
            return $this->record([
                'A',
                $article->getProcessingFlag()->value,
                $article->getArticleNumber(),
                $article->getShortDescription1(),
                $article->getShortDescription2(),
                $article->getUnit() ?? '',
                (string) $article->getPriceIndicator()->value,
                (string) $article->getPriceUnitAmount(),
                $this->minor($article->getPrice()),
                $article->getDiscountGroup() ?? '',
                $article->getMainProductGroup() ?? '',
                $article->getProductGroup() ?? '',
                $article->getMatchcode() ?? '',
                $article->getAltArticleNumberCreator() ?? '',
                $article->getAltArticleNumber() ?? '',
                $article->getManufacturerNumberCreator() ?? '',
                $article->getManufacturerNumber() ?? '',
                $article->getManufacturerType() ?? '',
                $article->getEan() ?? '',
                $article->getGraphicNumber() ?? '',
                (string) $article->getMinPackagingAmount(),
                $article->getCataloguePage() ?? '',
                (string) $article->getTextFlag(),
                $article->getLongTextNumber() ?? '',
                $article->getCostIndicator() ?? '',
                $article->getStockIndicator() ?? '',
                $article->getReferenceNumberCreator() ?? '',
                $article->getReferenceNumber() ?? '',
                $article->getVatIndicator() ?? '',
            ], $version, [3 => 40, 4 => 40, 5 => 4, 12 => 15, 21 => 15]);
        }

        $code = self::V4_PRICE_UNIT_CODES[$article->getPriceUnitAmount()] ?? null;
        if ($code === null) {
            $this->logErrorAndThrow(InvalidArgumentException::class, sprintf(
                'DATANORM 4 supports only price units 1/10/100/1000 — article "%s" has %d.',
                $article->getArticleNumber(),
                $article->getPriceUnitAmount()
            ));
        }

        return $this->record([
            'A',
            $article->getProcessingFlag()->value,
            $article->getArticleNumber(),
            $article->getTextFlag() . '0',
            $article->getShortDescription1(),
            $article->getShortDescription2(),
            (string) $article->getPriceIndicator()->value,
            $code,
            $this->unitV4($article->getUnit()),
            $this->minor($article->getPrice()),
            $article->getDiscountGroup() ?? '',
            $article->getMainProductGroup() ?? '',
            $article->getLongTextNumber() ?? '',
        ], $version, [4 => 40, 5 => 40, 8 => 4]);
    }

    private function articleSupplementRecordV4(DatanormArticle $article): string {
        return $this->record([
            'B',
            $article->getProcessingFlag()->value === 'A' ? 'A' : 'N',
            $article->getArticleNumber(),
            $article->getMatchcode() ?? '',
            $article->getAltArticleNumber() ?? '',
            $article->getCataloguePage() ?? '',
            $article->getCopperWeightIndicator() ?? '0',
            $article->getCopperRawPrice() ?? '0',
            $article->getCopperWeight() ?? '0',
            $article->getEan() ?? '',
            $article->getGraphicNumber() ?? '',
            $article->getProductGroup() ?? '',
            $article->getCostIndicator() ?? '0',
            (string) $article->getMinPackagingAmount(),
            $article->getReferenceNumberCreator() ?? '',
            $article->getReferenceNumber() ?? '',
        ], DatanormVersion::V4, [3 => 15, 4 => 15]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Z-records of one article: scale prices (DATANORM 5 working flag 1,
     * DATANORM 4 flags 1/2) followed by raw material surcharges (DATANORM 5
     * flags 2/3, DATANORM 4 flags 3/4). Line/set numbers count per article.
     *
     * @return list<string>
     */
    private function surchargeRecords(DatanormArticle $article, DatanormVersion $version): array {
        $records = [];
        $no = 0;
        foreach ($article->getScalePrices() as $scale) {
            $records[] = $this->scalePriceRecord($article->getArticleNumber(), $scale, $version, ++$no);
        }
        foreach ($article->getRawMaterialSurcharges() as $surcharge) {
            $records[] = $this->rawMaterialRecord($article->getArticleNumber(), $surcharge, $version, ++$no);
        }

        return $records;
    }

    private function scalePriceRecord(string $articleNumber, DatanormScalePrice $scale, DatanormVersion $version, int $no): string {
        $isPercent = $scale->getIndicator() === DatanormScalePrice::INDICATOR_PERCENT;
        $value = $isPercent
            ? (string) (int) round(($scale->getPercent() ?? 0.0) * 100)
            : $this->minor($scale->getAmount());

        if ($version === DatanormVersion::V4) {
            // Plain scale prices travel as working flag 1, surcharges/discounts
            // as flag 2 with the four-valued kind code.
            return $scale->getIndicator() === DatanormScalePrice::INDICATOR_SCALE_PRICE
                ? $this->record([
                    'Z', 'N', $articleNumber, (string) $no, '1',
                    (string) ($scale->getBasis() ?? DatanormScalePrice::BASIS_QUANTITY),
                    $scale->getDescription() ?? '',
                    (string) ($scale->getPriceIndicator()->value ?? ''),
                    $value,
                    $scale->getFrom() ?? '',
                    $scale->getTo() ?? '',
                ], $version, [6 => 28])
                : $this->record([
                    'Z', 'N', $articleNumber, (string) $no, '2',
                    (string) ($scale->getBasis() ?? DatanormScalePrice::BASIS_QUANTITY),
                    $scale->getDescription() ?? '',
                    (string) (($isPercent ? 1 : 3) + ($scale->isDiscount() ? 1 : 0)),
                    $value,
                    $scale->getFrom() ?? '',
                    $scale->getTo() ?? '',
                ], $version, [6 => 28]);
        }

        return $this->record([
            'Z', 'N', $articleNumber, sprintf('%02d', $no), '1',
            $scale->getDescription() ?? '',
            '',
            (string) $scale->getIndicator(),
            $scale->getIndicator() === DatanormScalePrice::INDICATOR_SCALE_PRICE ? '' : ($scale->isDiscount() ? '-' : '+'),
            $isPercent ? '' : (string) ($scale->getPriceIndicator()->value ?? ''),
            $isPercent ? '' : (string) $scale->getPriceUnitAmount(),
            $value,
            '',
            (string) ($scale->getBasis() ?? ''),
            $scale->getFrom() ?? '',
            $scale->getTo() ?? '',
        ], $version, [5 => 40]);
    }

    private function rawMaterialRecord(string $articleNumber, DatanormRawMaterialSurcharge $surcharge, DatanormVersion $version, int $no): string {
        $german = $surcharge->getMethod() === DatanormRawMaterialSurcharge::METHOD_GERMAN;
        if ($version === DatanormVersion::V4) {
            return $german
                ? $this->record([
                    'Z', 'N', $articleNumber, (string) $no, '4',
                    $surcharge->getRawMaterial(),
                    $this->whole($surcharge->getIncludedBasePrice()),
                    (string) (int) round(($surcharge->getBaseFactor() ?? 0.0) * 1000),
                    (string) (int) round(($surcharge->getWeight() ?? 0.0) * 100),
                    (string) (int) round(($surcharge->getWeightFactor() ?? 1.0) * 1000),
                ], $version)
                : $this->record([
                    'Z', 'N', $articleNumber, (string) $no, '3',
                    $surcharge->getRawMaterial(),
                    (string) (($surcharge->isPercent() === true ? 1 : 3) + ($surcharge->isDiscount() === true ? 1 : 0)),
                    $surcharge->isPercent() === true
                        ? (string) (int) round(($surcharge->getPercent() ?? 0.0) * 100)
                        : $this->minor($surcharge->getAmount()),
                    $this->whole($surcharge->getFromDayPrice()),
                    $this->whole($surcharge->getToDayPrice()),
                    $surcharge->getDayPriceFactor() !== null ? (string) (int) round($surcharge->getDayPriceFactor() * 1000) : '',
                ], $version);
        }

        return $german
            ? $this->record([
                'Z', 'N', $articleNumber, sprintf('%02d', $no), '3',
                $surcharge->getRawMaterial(),
                (string) ($surcharge->getPriceIndicator()->value ?? ''),
                (string) $surcharge->getPriceUnitAmount(),
                $this->minor($surcharge->getIncludedBasePrice()),
                (string) (int) round(($surcharge->getBaseFactor() ?? 0.0) * 1000),
                (string) (int) round(($surcharge->getWeight() ?? 0.0) * 100),
                (string) (int) round(($surcharge->getWeightFactor() ?? 1.0) * 1000),
            ], $version)
            : $this->record([
                'Z', 'N', $articleNumber, sprintf('%02d', $no), '2',
                $surcharge->getRawMaterial(),
                $surcharge->isPercent() === true ? '3' : '2',
                $surcharge->isDiscount() === true ? '-' : '+',
                $surcharge->isPercent() === true ? '' : (string) ($surcharge->getPriceIndicator()->value ?? ''),
                $surcharge->isPercent() === true ? '' : (string) $surcharge->getPriceUnitAmount(),
                $surcharge->isPercent() === true
                    ? (string) (int) round(($surcharge->getPercent() ?? 0.0) * 100)
                    : $this->minor($surcharge->getAmount()),
                $this->minor($surcharge->getFromDayPrice()),
                $this->minor($surcharge->getToDayPrice()),
                $surcharge->getDayPriceFactor() !== null ? (string) (int) round($surcharge->getDayPriceFactor() * 1000) : '',
                $surcharge->getWeight() !== null ? (string) (int) round($surcharge->getWeight() * 100) : '',
                $surcharge->getWeightFactor() !== null ? (string) (int) round($surcharge->getWeightFactor() * 1000000) : '',
            ], $version);
    }

    /**
     * C-records (working times) of one article: indicator ARBA (DATANORM 5)
     * respectively ARBEIT-1 (DATANORM 4), always transferred in minutes.
     *
     * @return list<string>
     */
    private function workTimeRecords(DatanormArticle $article, DatanormVersion $version): array {
        $records = [];
        foreach ($article->getWorkTimes() as $workTime) {
            $records[] = $this->record([
                'C', 'N',
                $version === DatanormVersion::V4 ? 'ARBEIT-1' : 'ARBA',
                $article->getArticleNumber(),
                (string) $workTime->getPurpose(),
                '2',
                (string) (int) round($workTime->getMinutes() * 10),
            ], $version);
        }

        return $records;
    }

    /** Money → whole-currency-unit field (DATANORM 4 day prices), empty when unset. */
    private function whole(?Money $price): string {
        if ($price === null) {
            return '';
        }

        return (string) $price->withScale(0)->getMinorAmount();
    }

    /**
     * Builds one record: encodes every field to the DATANORM charset, applies
     * optional per-field length limits, drops empty trailing fields and closes
     * with the field separator.
     *
     * @param  list<string>  $fields
     * @param  array<int, int>  $maxLengths  field index → max length
     */
    private function record(array $fields, DatanormVersion $version, array $maxLengths = []): string {
        $encoded = [];
        foreach ($fields as $index => $field) {
            $encoded[] = $this->enc($field, $version, $maxLengths[$index] ?? null);
        }
        while (count($encoded) > 1 && end($encoded) === '') {
            array_pop($encoded);
        }

        return implode(';', $encoded) . ';';
    }

    private function enc(string $value, DatanormVersion $version, ?int $maxLength = null): string {
        $encoded = DatanormCharset::encode($value, $version);

        return $maxLength !== null ? substr($encoded, 0, $maxLength) : $encoded;
    }

    /** Money → minor-unit field (`199,95` → `19995`), `0` when no price is set. */
    private function minor(?Money $price): string {
        if ($price === null) {
            return '0';
        }

        return (string) $price->withScale(2)->getMinorAmount();
    }

    private function unitV4(?string $unit): string {
        if ($unit === null || $unit === '') {
            return '';
        }

        return self::V4_UNIT_MAP[strtoupper($unit)] ?? substr($unit, 0, 4);
    }

    private function discountValueField(DatanormDiscountKind $kind, float $value): string {
        return (string) (int) round($kind === DatanormDiscountKind::Factor ? $value * 1000 : $value * 100);
    }

    /**
     * @return list<array{0: string, 1: string}> kind/value field pairs, padded to $count
     */
    private function discountPairs(DatanormPriceChange $change, int $count): array {
        $pairs = [];
        foreach (array_slice($change->getDiscounts(), 0, $count) as $discount) {
            $pairs[] = [(string) $discount->getKind()->value, $this->discountValueField($discount->getKind(), $discount->getValue())];
        }
        while (count($pairs) < $count) {
            $pairs[] = ['', ''];
        }

        return $pairs;
    }

    /**
     * Encodes the header line count footer (DATANORM 5) and joins all records
     * with CRLF. The declared count includes the V- and the E-record.
     *
     * @param  list<string>  $lines
     */
    private function finish(array $lines, DatanormCatalog $catalog, DatanormVersion $version): string {
        if ($version === DatanormVersion::V5) {
            $remark = $catalog->getFooterRemark() ?? '';
            $lines[] = $this->record(['E', (string) (count($lines) + 1), $remark], $version, [2 => 60]);
        }

        return implode(self::EOL, $lines) . self::EOL;
    }
}
