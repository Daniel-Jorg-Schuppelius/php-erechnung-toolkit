<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DatanormParser.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Parsers;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use DateTimeImmutable;
use ERechnungToolkit\Entities\Datanorm\{
    DatanormArticle,
    DatanormArticleChange,
    DatanormCatalog,
    DatanormCustomer,
    DatanormDiscount,
    DatanormDiscountGroup,
    DatanormGraphicReference,
    DatanormPriceChange,
    DatanormProductGroup,
    DatanormScalePrice,
    DatanormTextBlock
};
use ERechnungToolkit\Enums\{DatanormDataMark, DatanormDiscountKind, DatanormPriceIndicator, DatanormProcessingFlag, DatanormVersion};
use ERechnungToolkit\Helper\{DatanormCharset, DatanormPriceCalculator};
use ERRORToolkit\Traits\ErrorLog;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Parser for DATANORM 4 and 5 files (article, product group, discount group
 * and DATPREIS price files).
 *
 * The version is detected from the header record: DATANORM 5 headers are
 * semicolon-separated and start with `V;050;`, DATANORM 4 headers are a
 * 128-character fixed-width line starting with `V ` and carrying `04` at
 * position 124. Prices arrive without decimal separator (`N6/2`) and are
 * mapped to {@see Money}; the DATANORM 4 price unit code (0-3) is resolved to
 * the real unit amount via {@see DatanormPriceCalculator}.
 *
 * Malformed or unsupported records never abort the run — they are collected
 * as warnings on the {@see DatanormCatalog}. Only a missing or unrecognizable
 * header record throws.
 */
final class DatanormParser {
    use ErrorLog;

    /** DATANORM raw material and special surcharge Z-flags that are collected as warnings. */
    private const UNSUPPORTED_RECORDS = ['C', 'J'];

    /** @var array<string, DatanormArticle> */
    private array $articles = [];

    /** @var array<string, list<array{indicator: string, text: string, block: string}>> article number → dimension parts */
    private array $dimensionParts = [];

    private ?DatanormCatalog $catalog = null;
    private string $encoding = 'CP850';

    /**
     * Parses a DATANORM file from a string.
     *
     * @param  string  $encoding  source encoding of the content (pass `UTF-8` for pre-converted content)
     */
    public function parse(string $content, string $encoding = 'CP850'): DatanormCatalog {
        $this->articles = [];
        $this->dimensionParts = [];
        $this->encoding = $encoding;

        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        // Drop a trailing DOS EOF marker and empty trailing lines.
        $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line, "\x1A \t") !== ''));

        if ($lines === [] || !str_starts_with($lines[0], 'V')) {
            $this->logErrorAndThrow(RuntimeException::class, 'Unknown format. Expected a DATANORM file starting with a V-record.');
        }

        $version = $this->detectVersion($lines[0]);
        $catalog = $version === DatanormVersion::V4
            ? $this->parseHeaderV4($lines[0])
            : $this->parseHeaderV5($this->fields($lines[0]));
        $this->catalog = $catalog;

        $lineCount = count($lines);
        foreach ($lines as $index => $line) {
            if ($index === 0) {
                continue;
            }
            try {
                $this->parseRecord($catalog, $version, $line, $index + 1);
            } catch (Throwable $exception) {
                $catalog->addWarning(sprintf('line %d: %s', $index + 1, $exception->getMessage()));
            }
        }

        foreach ($this->articles as $article) {
            $catalog->addArticle($article);
            $this->resolveTexts($catalog, $article);
        }

        if ($catalog->getDeclaredLineCount() !== null && $catalog->getDeclaredLineCount() !== $lineCount) {
            $catalog->addWarning(sprintf(
                'E-record declares %d lines but the file contains %d — the file may be truncated.',
                $catalog->getDeclaredLineCount(),
                $lineCount
            ));
        }

        $this->articles = [];
        $this->dimensionParts = [];
        $this->catalog = null;

        return $catalog;
    }

    /**
     * Parses a DATANORM file from disk.
     */
    public function parseFile(string $filePath, string $encoding = 'CP850'): DatanormCatalog {
        if (!file_exists($filePath)) {
            $this->logErrorAndThrow(InvalidArgumentException::class, "File not found: {$filePath}");
        }
        $content = file_get_contents($filePath);
        if ($content === false) {
            $this->logErrorAndThrow(RuntimeException::class, "Failed to read file: {$filePath}");
        }

        return $this->parse($content, $encoding);
    }

    /**
     * Detects the DATANORM version from the raw header line.
     */
    public function detectVersion(string $headerLine): DatanormVersion {
        if (str_starts_with($headerLine, 'V;')) {
            $fields = explode(';', $headerLine);
            if (($fields[1] ?? '') === DatanormVersion::V5->value) {
                return DatanormVersion::V5;
            }
            $this->logErrorAndThrow(RuntimeException::class, sprintf('Unsupported DATANORM version marker "%s" in header.', $fields[1] ?? ''));
        }
        if (str_starts_with($headerLine, 'V ')) {
            return DatanormVersion::V4;
        }

        $this->logErrorAndThrow(RuntimeException::class, 'Unrecognizable DATANORM V-record.');
    }

    // ------------------------------------------------------------------
    // Header
    // ------------------------------------------------------------------

    /** DATANORM 4: fixed-width 128-char header, decoded per field. */
    private function parseHeaderV4(string $line): DatanormCatalog {
        $date = $this->dateFromDdmmyy(trim($this->rawField($line, 3, 8)));
        $info = rtrim($this->rawField($line, 9, 48)) . "\n" . rtrim($this->rawField($line, 49, 88)) . "\n" . rtrim($this->rawField($line, 89, 123));
        $currencyRaw = trim($this->rawField($line, 126, 128));

        return new DatanormCatalog(
            version: DatanormVersion::V4,
            dataMark: null,
            creationDate: $date,
            currency: CurrencyCode::tryFrom($currencyRaw) ?? CurrencyCode::Euro,
            infoText: trim($info) !== '' ? trim($info) : null
        );
    }

    /**
     * DATANORM 5: `V;050;<mark>;<date>;<currency>;<description>;<copyright>;<creator>;…`
     *
     * @param  list<string>  $fields
     */
    private function parseHeaderV5(array $fields): DatanormCatalog {
        $addressLines = array_values(array_filter([
            $this->field($fields, 8),
            $this->field($fields, 9),
            $this->field($fields, 10),
        ], static fn (?string $value): bool => $value !== null));

        return new DatanormCatalog(
            version: DatanormVersion::V5,
            dataMark: DatanormDataMark::tryFrom($this->field($fields, 2) ?? ''),
            creationDate: $this->dateFromYmd($this->field($fields, 3) ?? ''),
            currency: CurrencyCode::tryFrom($this->field($fields, 4) ?? '') ?? CurrencyCode::Euro,
            description: $this->field($fields, 5),
            copyright: $this->field($fields, 6),
            creatorShortName: $this->field($fields, 7),
            creatorName: $addressLines !== [] ? implode(', ', $addressLines) : null,
            creatorStreet: $this->field($fields, 11),
            creatorCountry: $this->field($fields, 12),
            creatorZip: $this->field($fields, 13),
            creatorCity: $this->field($fields, 14)
        );
    }

    // ------------------------------------------------------------------
    // Record dispatch
    // ------------------------------------------------------------------

    private function parseRecord(DatanormCatalog $catalog, DatanormVersion $version, string $line, int $lineNumber): void {
        $type = $line[0] ?? '';
        if (($line[1] ?? '') !== ';') {
            $catalog->addWarning(sprintf('line %d: unrecognizable record "%s" skipped.', $lineNumber, substr($line, 0, 20)));

            return;
        }
        if (in_array($type, self::UNSUPPORTED_RECORDS, true)) {
            $catalog->addWarning(sprintf('line %d: %s-record is not supported yet and was skipped.', $lineNumber, $type));

            return;
        }

        $fields = $this->fields($line);

        match (true) {
            $type === 'A' => $this->parseArticle($catalog, $version, $fields),
            $type === 'B' && $version === DatanormVersion::V4 => $this->parseArticleSupplementV4($catalog, $fields),
            $type === 'B' => $this->parseArticleChangeV5($catalog, $fields),
            $type === 'T' || ($type === 'E' && $version === DatanormVersion::V4) => $this->parseTextBlock($version, $type, $fields),
            $type === 'E' => $this->parseFooterV5($catalog, $fields),
            $type === 'D' => $this->parseDimension($version, $fields),
            $type === 'S' => $this->parseProductGroup($catalog, $version, $fields),
            $type === 'R' => $this->parseDiscountGroup($catalog, $version, $fields),
            $type === 'P' => $this->parsePriceChanges($catalog, $version, $fields),
            $type === 'K' => $this->parseCustomer($catalog, $version, $fields),
            $type === 'G' && $version === DatanormVersion::V5 => $this->parseGraphicReference($catalog, $fields),
            $type === 'Z' => $this->parseSurcharge($catalog, $version, $fields, $lineNumber),
            default => $catalog->addWarning(sprintf('line %d: unknown record type "%s" skipped.', $lineNumber, $type)),
        };
    }

    // ------------------------------------------------------------------
    // Articles
    // ------------------------------------------------------------------

    /** @param list<string> $fields */
    private function parseArticle(DatanormCatalog $catalog, DatanormVersion $version, array $fields): void {
        $flagRaw = strtoupper($this->field($fields, 1) ?? 'N');
        $articleNumber = $this->field($fields, 2);
        if ($articleNumber === null) {
            throw new RuntimeException('A-record without article number.');
        }

        if ($version === DatanormVersion::V4 && $flagRaw === DatanormProcessingFlag::Delete->value) {
            $catalog->addArticleChange(DatanormArticleChange::delete($articleNumber));

            return;
        }
        if ($version === DatanormVersion::V4 && $flagRaw === DatanormProcessingFlag::Renumber->value) {
            $newNumber = $this->field($fields, 4);
            if ($newNumber === null) {
                throw new RuntimeException(sprintf('X-record for "%s" without a new article number.', $articleNumber));
            }
            $catalog->addArticleChange(DatanormArticleChange::renumber($articleNumber, $newNumber));

            return;
        }

        $flag = DatanormProcessingFlag::tryFrom($flagRaw) ?? DatanormProcessingFlag::New;

        if (isset($this->articles[$articleNumber]) && $flag === DatanormProcessingFlag::New) {
            $catalog->addWarning(sprintf('Duplicate A-record for article "%s" skipped (first record wins).', $articleNumber));

            return;
        }

        $article = $version === DatanormVersion::V4
            ? $this->articleFromV4($catalog, $fields, $articleNumber, $flag)
            : $this->articleFromV5($catalog, $fields, $articleNumber, $flag);

        $this->articles[$articleNumber] = $article;
    }

    /** @param list<string> $fields */
    private function articleFromV4(DatanormCatalog $catalog, array $fields, string $articleNumber, DatanormProcessingFlag $flag): DatanormArticle {
        [$priceUnitAmount, $price] = $this->priceWithUnit(
            $catalog,
            $this->field($fields, 7),
            $this->field($fields, 9),
            DatanormVersion::V4,
            $articleNumber
        );

        $textFlagRaw = $this->field($fields, 3) ?? '0';

        $article = new DatanormArticle(
            articleNumber: $articleNumber,
            shortDescription1: $this->field($fields, 4) ?? '',
            shortDescription2: $this->field($fields, 5) ?? '',
            unit: $this->field($fields, 8),
            priceIndicator: DatanormPriceIndicator::tryFrom((int) ($this->field($fields, 6) ?? '1')) ?? DatanormPriceIndicator::ListPrice,
            priceUnitAmount: $priceUnitAmount,
            price: $price,
            discountGroup: $this->field($fields, 10),
            mainProductGroup: $this->field($fields, 11),
            processingFlag: $flag
        );
        $article->setTextFlag((int) substr($textFlagRaw, 0, 1));
        $article->setLongTextNumber($this->field($fields, 12));

        return $article;
    }

    /** @param list<string> $fields */
    private function articleFromV5(DatanormCatalog $catalog, array $fields, string $articleNumber, DatanormProcessingFlag $flag): DatanormArticle {
        [$priceUnitAmount, $price] = $this->priceWithUnit(
            $catalog,
            $this->field($fields, 7),
            $this->field($fields, 8),
            DatanormVersion::V5,
            $articleNumber
        );

        $article = new DatanormArticle(
            articleNumber: $articleNumber,
            shortDescription1: $this->field($fields, 3) ?? '',
            shortDescription2: $this->field($fields, 4) ?? '',
            unit: $this->field($fields, 5),
            priceIndicator: DatanormPriceIndicator::tryFrom((int) ($this->field($fields, 6) ?? '1')) ?? DatanormPriceIndicator::ListPrice,
            priceUnitAmount: $priceUnitAmount,
            price: $price,
            discountGroup: $this->field($fields, 9),
            mainProductGroup: $this->field($fields, 10),
            productGroup: $this->field($fields, 11),
            processingFlag: $flag
        );

        $article->setMatchcode($this->field($fields, 12));
        $article->setAltArticleNumber($this->field($fields, 14), $this->field($fields, 13));
        $article->setManufacturerNumber($this->field($fields, 16), $this->field($fields, 15));
        $article->setManufacturerType($this->field($fields, 17));
        $article->setEan($this->field($fields, 18));
        $article->setGraphicNumber($this->field($fields, 19));
        $article->setMinPackagingAmount((int) ($this->field($fields, 20) ?? '1'));
        $article->setCataloguePage($this->field($fields, 21));
        $article->setTextFlag((int) ($this->field($fields, 22) ?? '0'));
        $article->setLongTextNumber($this->field($fields, 23));
        $article->setCostIndicator($this->field($fields, 24));
        $article->setStockIndicator($this->field($fields, 25));
        $article->setReferenceNumber($this->field($fields, 27), $this->field($fields, 26));
        $article->setVatIndicator($this->field($fields, 28));

        return $article;
    }

    /** DATANORM 4 B-record: matchcode, EAN, packaging etc. merged into the article. */
    /** @param list<string> $fields */
    private function parseArticleSupplementV4(DatanormCatalog $catalog, array $fields): void {
        $articleNumber = $this->field($fields, 2);
        if ($articleNumber === null) {
            throw new RuntimeException('B-record without article number.');
        }

        $article = $this->articles[$articleNumber] ?? null;
        if ($article === null) {
            // Legal in change files: a B-record may update an article that is
            // not part of this file. Keep it as a change-flagged stub.
            $article = new DatanormArticle(articleNumber: $articleNumber, processingFlag: DatanormProcessingFlag::Change);
            $this->articles[$articleNumber] = $article;
            $catalog->addWarning(sprintf('B-record for article "%s" without preceding A-record — stub created.', $articleNumber));
        }

        $article->setMatchcode($this->field($fields, 3));
        $article->setAltArticleNumber($this->field($fields, 4));
        $article->setCataloguePage($this->field($fields, 5));
        $article->setCopper($this->field($fields, 6), $this->field($fields, 7), $this->field($fields, 8));
        $article->setEan($this->field($fields, 9));
        $article->setGraphicNumber($this->field($fields, 10));
        if (($group = $this->field($fields, 11)) !== null && $article->getProductGroup() === null) {
            // V4 keeps the product group in the B-record; re-create the article
            // to keep the constructor-immutable core consistent is overkill —
            // the parser owns the entity, so widen via reflection-free path:
            $this->articles[$articleNumber] = $this->withProductGroup($article, $group);
        }
        $this->articles[$articleNumber]->setCostIndicator($this->field($fields, 12));
        $this->articles[$articleNumber]->setMinPackagingAmount((int) ($this->field($fields, 13) ?? '1'));
        $this->articles[$articleNumber]->setReferenceNumber($this->field($fields, 15), $this->field($fields, 14));
    }

    /** Rebuilds an article with the product group set (V4: group arrives in the B-record). */
    private function withProductGroup(DatanormArticle $article, string $productGroup): DatanormArticle {
        $copy = new DatanormArticle(
            articleNumber: $article->getArticleNumber(),
            shortDescription1: $article->getShortDescription1(),
            shortDescription2: $article->getShortDescription2(),
            unit: $article->getUnit(),
            priceIndicator: $article->getPriceIndicator(),
            priceUnitAmount: $article->getPriceUnitAmount(),
            price: $article->getPrice(),
            discountGroup: $article->getDiscountGroup(),
            mainProductGroup: $article->getMainProductGroup(),
            productGroup: $productGroup,
            processingFlag: $article->getProcessingFlag()
        );
        $copy->setMatchcode($article->getMatchcode());
        $copy->setAltArticleNumber($article->getAltArticleNumber(), $article->getAltArticleNumberCreator());
        $copy->setCataloguePage($article->getCataloguePage());
        $copy->setCopper($article->getCopperWeightIndicator(), $article->getCopperRawPrice(), $article->getCopperWeight());
        $copy->setEan($article->getEan());
        $copy->setGraphicNumber($article->getGraphicNumber());
        $copy->setTextFlag($article->getTextFlag());
        $copy->setLongTextNumber($article->getLongTextNumber());

        return $copy;
    }

    /** DATANORM 5 B-record: deletion or article number change. */
    /** @param list<string> $fields */
    private function parseArticleChangeV5(DatanormCatalog $catalog, array $fields): void {
        $flag = strtoupper($this->field($fields, 1) ?? '');
        $articleNumber = $this->field($fields, 2);
        if ($articleNumber === null) {
            throw new RuntimeException('B-record without article number.');
        }

        if ($flag === DatanormProcessingFlag::Change->value) {
            $newNumber = $this->field($fields, 3);
            if ($newNumber === null) {
                throw new RuntimeException(sprintf('B-record renumbering for "%s" without a new number.', $articleNumber));
            }
            $catalog->addArticleChange(DatanormArticleChange::renumber($articleNumber, $newNumber));

            return;
        }
        if ($flag === DatanormProcessingFlag::Delete->value) {
            $catalog->addArticleChange(DatanormArticleChange::delete(
                $articleNumber,
                $this->field($fields, 3),
                $this->dateFromYmd($this->field($fields, 4) ?? '')
            ));

            return;
        }

        throw new RuntimeException(sprintf('B-record with unknown processing flag "%s".', $flag));
    }

    // ------------------------------------------------------------------
    // Texts
    // ------------------------------------------------------------------

    /** @param list<string> $fields */
    private function parseTextBlock(DatanormVersion $version, string $type, array $fields): void {
        if ($this->catalog === null) {
            return;
        }
        if ($version === DatanormVersion::V4) {
            $number = $this->field($fields, 2);
            if ($number === null) {
                throw new RuntimeException(sprintf('%s-record without a text number.', $type));
            }
            $usage = $type === 'E' ? DatanormTextBlock::USAGE_INSERT : DatanormTextBlock::USAGE_LONGTEXT;
            $block = $this->catalog->getTextBlock($number, $usage) ?? new DatanormTextBlock($number, $usage);
            foreach ([[4, 6], [7, 9]] as [$lineIndex, $textIndex]) {
                $lineNo = $this->field($fields, $lineIndex);
                if ($lineNo !== null && ctype_digit($lineNo)) {
                    $block->addLine((int) $lineNo, $fields[$textIndex] ?? '');
                }
            }
            $this->catalog->addTextBlock($block);

            return;
        }

        $flag = strtoupper($this->field($fields, 1) ?? 'N');
        $number = $this->field($fields, 2);
        if ($number === null) {
            throw new RuntimeException('T-record without a text number.');
        }
        if ($flag === DatanormProcessingFlag::Delete->value) {
            $this->catalog->addWarning(sprintf('T-record deletion for text block "%s" noted but not applied.', $number));

            return;
        }
        $usage = (int) ($this->field($fields, 3) ?? '1');
        $block = $this->catalog->getTextBlock($number, $usage) ?? new DatanormTextBlock($number, $usage);
        $lineNo = $this->field($fields, 4);
        $block->addLine($lineNo !== null && ctype_digit($lineNo) ? (int) $lineNo : count($block->getLines()) + 1, $fields[5] ?? '');
        $this->catalog->addTextBlock($block);
    }

    /** @param list<string> $fields */
    private function parseDimension(DatanormVersion $version, array $fields): void {
        $articleNumber = $this->field($fields, 2);
        if ($articleNumber === null) {
            throw new RuntimeException('D-record without article number.');
        }

        if ($version === DatanormVersion::V5) {
            $this->dimensionParts[$articleNumber][] = [
                'indicator' => strtoupper($this->field($fields, 4) ?? 'F'),
                'text' => $fields[5] ?? '',
                'block' => $this->field($fields, 6) ?? '',
            ];

            return;
        }

        // DATANORM 4: up to two parts per record — [lineNo, indicator, slot, slot].
        foreach ([[3, 4, 5, 6], [7, 8, 9, 10]] as [$lineIndex, $indicatorIndex, $slotA, $slotB]) {
            $indicator = strtoupper($this->field($fields, $indicatorIndex) ?? '');
            if ($indicator === '' || $this->field($fields, $lineIndex) === null) {
                continue;
            }
            // F: slot A is a dummy, the text sits in slot B.
            // T: the referenced long-text block sits in slot A.
            // E: insert block in slot A, insert values in slot B.
            $this->dimensionParts[$articleNumber][] = match ($indicator) {
                'F' => ['indicator' => 'F', 'text' => $fields[$slotB] ?? '', 'block' => ''],
                'T' => ['indicator' => 'T', 'text' => '', 'block' => $this->field($fields, $slotA) ?? ''],
                'E' => ['indicator' => 'E', 'text' => $fields[$slotB] ?? '', 'block' => $this->field($fields, $slotA) ?? ''],
                default => throw new RuntimeException(sprintf('D-record with unknown sub indicator "%s".', $indicator)),
            };
        }
    }

    /** Resolves the article's long text number and dimension parts into plain text lines. */
    private function resolveTexts(DatanormCatalog $catalog, DatanormArticle $article): void {
        if (($number = $article->getLongTextNumber()) !== null) {
            $block = $catalog->getTextBlock($number, DatanormTextBlock::USAGE_LONGTEXT) ?? $catalog->getTextBlock($number);
            if ($block !== null) {
                foreach ($block->getLines() as $line) {
                    $article->addTextLine($line);
                }
            } else {
                $catalog->addWarning(sprintf('Article "%s" references missing long-text block "%s".', $article->getArticleNumber(), $number));
            }
        }

        foreach ($this->dimensionParts[$article->getArticleNumber()] ?? [] as $part) {
            match ($part['indicator']) {
                'F' => $article->addTextLine($part['text']),
                'T' => $this->appendReferencedBlock($catalog, $article, $part['block']),
                'E' => $this->appendInsertBlock($catalog, $article, $part['block'], $part['text']),
                default => null,
            };
        }
    }

    private function appendReferencedBlock(DatanormCatalog $catalog, DatanormArticle $article, string $number): void {
        $block = $catalog->getTextBlock($number, DatanormTextBlock::USAGE_LONGTEXT) ?? $catalog->getTextBlock($number);
        if ($block === null) {
            $catalog->addWarning(sprintf('Article "%s" references missing text block "%s".', $article->getArticleNumber(), $number));

            return;
        }
        foreach ($block->getLines() as $line) {
            $article->addTextLine($line);
        }
    }

    private function appendInsertBlock(DatanormCatalog $catalog, DatanormArticle $article, string $number, string $inserts): void {
        $block = $catalog->getTextBlock($number, DatanormTextBlock::USAGE_INSERT) ?? $catalog->getTextBlock($number);
        if ($block === null) {
            $catalog->addWarning(sprintf('Article "%s" references missing insert-text block "%s".', $article->getArticleNumber(), $number));

            return;
        }
        $values = array_values(array_filter(explode('$', $inserts), static fn (string $value): bool => $value !== ''));
        foreach ($block->getLines() as $line) {
            $article->addTextLine($block->fillInserts($line, $values));
        }
    }

    // ------------------------------------------------------------------
    // Product groups, discount groups, prices, customer, footer, graphics
    // ------------------------------------------------------------------

    /** @param list<string> $fields */
    private function parseProductGroup(DatanormCatalog $catalog, DatanormVersion $version, array $fields): void {
        if ($version === DatanormVersion::V4) {
            $mainGroup = $this->field($fields, 2);
            if ($mainGroup === null) {
                throw new RuntimeException('S-record without main product group.');
            }
            $group = $this->field($fields, 4);
            $label = $group === null ? $this->field($fields, 3) : $this->field($fields, 5);
            $catalog->addProductGroup(new DatanormProductGroup($mainGroup, $group, $label ?? ''));

            return;
        }

        $mainGroup = $this->field($fields, 1);
        if ($mainGroup === null) {
            throw new RuntimeException('S-record without main product group.');
        }
        $catalog->addProductGroup(new DatanormProductGroup($mainGroup, $this->field($fields, 2), $this->field($fields, 3) ?? ''));
    }

    /** @param list<string> $fields */
    private function parseDiscountGroup(DatanormCatalog $catalog, DatanormVersion $version, array $fields): void {
        $offset = $version === DatanormVersion::V4 ? 1 : 0;
        $code = $this->field($fields, 1 + $offset);
        $kindRaw = $this->field($fields, 2 + $offset);
        $valueRaw = $this->field($fields, 3 + $offset);
        if ($code === null || $kindRaw === null || $valueRaw === null) {
            throw new RuntimeException('R-record with missing code, kind or value.');
        }
        $kind = DatanormDiscountKind::tryFrom((int) $kindRaw)
            ?? throw new RuntimeException(sprintf('R-record "%s" with unknown discount kind "%s".', $code, $kindRaw));

        $catalog->addDiscountGroup(new DatanormDiscountGroup(
            $code,
            $kind,
            $this->discountValue($kind, $valueRaw),
            $this->field($fields, 4 + $offset)
        ));
    }

    /** @param list<string> $fields */
    private function parsePriceChanges(DatanormCatalog $catalog, DatanormVersion $version, array $fields): void {
        if ($version === DatanormVersion::V5) {
            $articleNumber = $this->field($fields, 1);
            if ($articleNumber === null) {
                throw new RuntimeException('P-record without article number.');
            }
            $catalog->addPriceChange(new DatanormPriceChange(
                articleNumber: $articleNumber,
                priceIndicator: DatanormPriceIndicator::tryFrom((int) ($this->field($fields, 2) ?? '1')) ?? DatanormPriceIndicator::ListPrice,
                price: $this->moneyFromMinor($catalog, $this->field($fields, 4)),
                priceUnitAmount: DatanormPriceCalculator::resolvePriceUnitAmount((int) ($this->field($fields, 3) ?? '1'), DatanormVersion::V5),
                discountGroup: $this->field($fields, 5),
                discounts: $this->discountChain($fields, [[6, 7], [8, 9], [10, 11]]),
                validFrom: $this->dateFromYmd($this->field($fields, 12) ?? '')
            ));

            return;
        }

        $flag = strtoupper($this->field($fields, 1) ?? 'A');
        if ($flag === 'P') {
            $catalog->addWarning('P-record price inquiry (flag "P") skipped.');

            return;
        }
        // DATANORM 4: up to three article blocks of nine fields each.
        for ($block = 0; $block < 3; $block++) {
            $base = 2 + $block * 9;
            $articleNumber = $this->field($fields, $base);
            if ($articleNumber === null) {
                continue;
            }
            $discountGroup = null;
            $discounts = [];
            $firstKind = $this->field($fields, $base + 3);
            if ($firstKind === '0') {
                $discountGroup = $this->field($fields, $base + 4);
                $discounts = $this->discountChain($fields, [[$base + 5, $base + 6], [$base + 7, $base + 8]]);
            } else {
                $discounts = $this->discountChain($fields, [[$base + 3, $base + 4], [$base + 5, $base + 6], [$base + 7, $base + 8]]);
            }
            $catalog->addPriceChange(new DatanormPriceChange(
                articleNumber: $articleNumber,
                priceIndicator: DatanormPriceIndicator::tryFrom((int) ($this->field($fields, $base + 1) ?? '1')) ?? DatanormPriceIndicator::ListPrice,
                price: $this->moneyFromMinor($catalog, $this->field($fields, $base + 2)),
                priceUnitAmount: null,
                discountGroup: $discountGroup,
                discounts: $discounts
            ));
        }
    }

    /** @param list<string> $fields */
    private function parseCustomer(DatanormCatalog $catalog, DatanormVersion $version, array $fields): void {
        if ($version === DatanormVersion::V4) {
            $number = $this->field($fields, 2);
            if ($number === null) {
                throw new RuntimeException('K-record without customer number.');
            }
            $catalog->setCustomer(new DatanormCustomer($number));

            return;
        }

        $number = $this->field($fields, 1);
        if ($number === null) {
            throw new RuntimeException('K-record without customer number.');
        }
        $catalog->setCustomer(new DatanormCustomer(
            customerNumber: $number,
            name: $this->field($fields, 2),
            street: $this->field($fields, 5),
            country: $this->field($fields, 6),
            zip: $this->field($fields, 7),
            city: $this->field($fields, 8)
        ));
    }

    /** @param list<string> $fields */
    private function parseFooterV5(DatanormCatalog $catalog, array $fields): void {
        $count = $this->field($fields, 1);
        $catalog->setDeclaredLineCount($count !== null && ctype_digit($count) ? (int) $count : null);
        $catalog->setFooterRemark($this->field($fields, 2));
    }

    /** @param list<string> $fields */
    private function parseGraphicReference(DatanormCatalog $catalog, array $fields): void {
        $graphicNumber = $this->field($fields, 2);
        if ($graphicNumber === null) {
            throw new RuntimeException('G-record without graphic binding number.');
        }
        $catalog->addGraphicReference(new DatanormGraphicReference(
            graphicNumber: $graphicNumber,
            lineNumber: (int) ($this->field($fields, 3) ?? '0'),
            type: $this->field($fields, 4) ?? '',
            filename: $this->field($fields, 5) ?? '',
            extension: $this->field($fields, 6) ?? '',
            description: $this->field($fields, 7)
        ));
    }

    // ------------------------------------------------------------------
    // Surcharges / scale prices (Z)
    // ------------------------------------------------------------------

    /** @param list<string> $fields */
    private function parseSurcharge(DatanormCatalog $catalog, DatanormVersion $version, array $fields, int $lineNumber): void {
        $articleNumber = $this->field($fields, 2);
        if ($articleNumber === null) {
            throw new RuntimeException('Z-record without article number.');
        }
        $workingFlag = (int) ($this->field($fields, 4) ?? '0');

        $scalePrice = match (true) {
            $version === DatanormVersion::V5 && $workingFlag === 1 => $this->scalePriceV5($catalog, $articleNumber, $fields),
            $version === DatanormVersion::V4 && $workingFlag === 1 => $this->scalePriceV4Flag1($catalog, $articleNumber, $fields),
            $version === DatanormVersion::V4 && $workingFlag === 2 => $this->scalePriceV4Flag2($catalog, $articleNumber, $fields),
            default => null,
        };

        if ($scalePrice === null) {
            $catalog->addWarning(sprintf('line %d: Z-record working flag %d (raw material/special surcharge) skipped.', $lineNumber, $workingFlag));

            return;
        }

        $article = $this->articles[$articleNumber] ?? null;
        if ($article !== null) {
            $article->addScalePrice($scalePrice);
        } else {
            $catalog->addWarning(sprintf('line %d: Z-record for unknown article "%s" skipped.', $lineNumber, $articleNumber));
        }
    }

    /** @param list<string> $fields */
    private function scalePriceV5(DatanormCatalog $catalog, string $articleNumber, array $fields): DatanormScalePrice {
        $indicator = (int) ($this->field($fields, 7) ?? '1');
        $valueRaw = $this->field($fields, 11) ?? '0';
        $percent = $indicator === DatanormScalePrice::INDICATOR_PERCENT ? ((int) $valueRaw) / 100 : null;
        $amount = $indicator !== DatanormScalePrice::INDICATOR_PERCENT ? $this->moneyFromMinor($catalog, $valueRaw) : null;

        return new DatanormScalePrice(
            articleNumber: $articleNumber,
            indicator: $indicator,
            amount: $amount,
            percent: $percent,
            isDiscount: ($this->field($fields, 8) ?? '+') === '-',
            priceIndicator: DatanormPriceIndicator::tryFrom((int) ($this->field($fields, 9) ?? '0')),
            priceUnitAmount: DatanormPriceCalculator::resolvePriceUnitAmount((int) ($this->field($fields, 10) ?? '1'), DatanormVersion::V5),
            basis: ($basis = $this->field($fields, 13)) !== null ? (int) $basis : null,
            from: $this->field($fields, 14),
            to: $this->field($fields, 15),
            description: $this->field($fields, 5) ?? $this->field($fields, 6)
        );
    }

    /** DATANORM 4 working flag 1: plain scale prices. */
    /** @param list<string> $fields */
    private function scalePriceV4Flag1(DatanormCatalog $catalog, string $articleNumber, array $fields): DatanormScalePrice {
        return new DatanormScalePrice(
            articleNumber: $articleNumber,
            indicator: DatanormScalePrice::INDICATOR_SCALE_PRICE,
            amount: $this->moneyFromMinor($catalog, $this->field($fields, 8)),
            percent: null,
            isDiscount: false,
            priceIndicator: DatanormPriceIndicator::tryFrom((int) ($this->field($fields, 7) ?? '0')),
            basis: ($basis = $this->field($fields, 5)) !== null ? (int) $basis : null,
            from: $this->field($fields, 9),
            to: $this->field($fields, 10),
            description: $this->field($fields, 6)
        );
    }

    /** DATANORM 4 working flag 2: surcharges/discounts by quantity, distance or date. */
    /** @param list<string> $fields */
    private function scalePriceV4Flag2(DatanormCatalog $catalog, string $articleNumber, array $fields): DatanormScalePrice {
        $kind = (int) ($this->field($fields, 7) ?? '1');
        $valueRaw = $this->field($fields, 8) ?? '0';
        $isPercent = in_array($kind, [1, 2], true);

        return new DatanormScalePrice(
            articleNumber: $articleNumber,
            indicator: $isPercent ? DatanormScalePrice::INDICATOR_PERCENT : DatanormScalePrice::INDICATOR_AMOUNT,
            amount: $isPercent ? null : $this->moneyFromMinor($catalog, $valueRaw),
            percent: $isPercent ? ((int) $valueRaw) / 100 : null,
            isDiscount: in_array($kind, [2, 4], true),
            basis: ($basis = $this->field($fields, 5)) !== null ? (int) $basis : null,
            from: $this->field($fields, 9),
            to: $this->field($fields, 10),
            description: $this->field($fields, 6)
        );
    }

    // ------------------------------------------------------------------
    // Field access & conversion helpers
    // ------------------------------------------------------------------

    /**
     * Splits a raw semicolon record and decodes each field. Splitting happens
     * on the raw bytes (the semicolon is identical in CP850 and UTF-8), so
     * multi-byte content cannot shift field boundaries.
     *
     * @return list<string>
     */
    private function fields(string $line): array {
        $fields = explode(';', $line);
        if ($this->encoding === 'UTF-8') {
            return $fields;
        }

        return array_map(
            fn (string $field): string => $this->encoding === 'CP850'
                ? DatanormCharset::decode($field)
                : (($decoded = @iconv($this->encoding, 'UTF-8', $field)) !== false ? $decoded : $field),
            $fields
        );
    }

    /** Trimmed field value, null when missing or empty. */
    /** @param list<string> $fields */
    private function field(array $fields, int $index): ?string {
        $value = $fields[$index] ?? null;
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /** Reads a fixed-width field (1-based byte positions) and decodes it. */
    private function rawField(string $line, int $from, int $to): string {
        $raw = substr($line, $from - 1, $to - $from + 1);
        if ($this->encoding === 'UTF-8') {
            return $raw;
        }

        return $this->encoding === 'CP850'
            ? DatanormCharset::decode($raw)
            : ((($decoded = @iconv($this->encoding, 'UTF-8', $raw)) !== false) ? $decoded : $raw);
    }

    /**
     * Resolves the price unit field and the price field of an A-record.
     * An invalid DATANORM 4 unit code keeps the article but drops the price
     * (with a warning) instead of silently inventing a wrong one.
     *
     * @return array{0: int, 1: Money|null}
     */
    private function priceWithUnit(DatanormCatalog $catalog, ?string $unitRaw, ?string $priceRaw, DatanormVersion $version, string $articleNumber): array {
        try {
            $priceUnitAmount = DatanormPriceCalculator::resolvePriceUnitAmount((int) ($unitRaw ?? ($version === DatanormVersion::V4 ? '0' : '1')), $version);
        } catch (InvalidArgumentException $exception) {
            $catalog->addWarning(sprintf('Article "%s": %s — price dropped.', $articleNumber, $exception->getMessage()));

            return [1, null];
        }

        return [$priceUnitAmount, $this->moneyFromMinor($catalog, $priceRaw)];
    }

    /** `N6/2`-style minor-unit value → Money, null for empty/zero/non-numeric. */
    private function moneyFromMinor(DatanormCatalog $catalog, ?string $raw): ?Money {
        $raw = $raw !== null ? trim($raw) : '';
        if ($raw === '' || !ctype_digit($raw) || (int) $raw === 0) {
            return null;
        }

        return Money::ofMinor((int) $raw, $catalog->getCurrency(), 2);
    }

    /**
     * Reads discount kind/value pairs into a discount chain.
     *
     * @param  list<string>  $fields
     * @param  list<array{0: int, 1: int}>  $pairs  kind index → value index
     * @return list<DatanormDiscount>
     */
    private function discountChain(array $fields, array $pairs): array {
        $discounts = [];
        foreach ($pairs as [$kindIndex, $valueIndex]) {
            $kindRaw = $this->field($fields, $kindIndex);
            $valueRaw = $this->field($fields, $valueIndex);
            if ($kindRaw === null || $valueRaw === null) {
                continue;
            }
            $kind = DatanormDiscountKind::tryFrom((int) $kindRaw);
            if ($kind === null) {
                continue;
            }
            $discounts[] = new DatanormDiscount($kind, $this->discountValue($kind, $valueRaw));
        }

        return $discounts;
    }

    /** Discount/surcharge values are N2/2 percent (2000 → 20.0), factors N1/3 (1200 → 1.2). */
    private function discountValue(DatanormDiscountKind $kind, string $raw): float {
        $value = (int) preg_replace('/\D/', '', $raw);

        return $kind === DatanormDiscountKind::Factor ? $value / 1000 : $value / 100;
    }

    private function dateFromDdmmyy(string $value): ?DateTimeImmutable {
        if (!preg_match('/^\d{6}$/', $value)) {
            return null;
        }
        $year = (int) substr($value, 4, 2);
        $date = DateTimeImmutable::createFromFormat('!dmY', substr($value, 0, 4) . ($year < 70 ? 2000 + $year : 1900 + $year));

        return $date !== false ? $date : null;
    }

    private function dateFromYmd(string $value): ?DateTimeImmutable {
        if (!preg_match('/^\d{8}$/', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Ymd', $value);

        return $date !== false ? $date : null;
    }
}
