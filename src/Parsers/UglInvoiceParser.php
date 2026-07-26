<?php
/*
 * Created on   : Sun Jun 28 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : UglInvoiceParser.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Parsers;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use DateTimeImmutable;
use ERechnungToolkit\Entities\{OrderLine, UglInvoice};
use ERechnungToolkit\Enums\UnitCode;
use ERRORToolkit\Traits\ErrorLog;
use InvalidArgumentException;
use RuntimeException;

/**
 * Parser for inbound UGL 5.0 invoices (Satzart RGD), wholesaler → craftsman.
 *
 * Maps the RGD header and the article positions (POA across one or more KOP
 * Vorgänge) onto a {@see UglInvoice} for reconciliation against a purchase order.
 * Per the UGL spec, POT and ADR records do not occur in invoices and are ignored;
 * KOP records merely group Vorgänge, so all POA positions are flattened.
 */
final class UglInvoiceParser {
    use ErrorLog;

    private const ENCODING = 'ISO-8859-1';

    /** UGL Mengeneinheit → UN/ECE unit code. */
    private const UNIT_MAP = [
        'ST' => 'H87',
        'STK' => 'H87',
        'STD' => 'HUR',
        'M' => 'MTR',
        'QM' => 'MTK',
        'CBM' => 'MTQ',
        'KG' => 'KGM',
        'L' => 'LTR',
    ];

    /**
     * Parses a UGL invoice from a string.
     */
    public function parse(string $content): UglInvoice {
        $records = preg_split('/\r\n|\r|\n/', $content) ?: [];

        $rgd = null;
        $lineRecords = [];
        foreach ($records as $record) {
            if (strlen($record) < 3) {
                continue;
            }
            $type = substr($record, 0, 3);
            if ($type === 'RGD' && $rgd === null) {
                $rgd = $record;
            } elseif ($type === 'POA') {
                $lineRecords[] = $record;
            } elseif ($type === 'END') {
                break;
            }
        }

        if ($rgd === null) {
            $this->logErrorAndThrow(RuntimeException::class, 'Unknown format. Expected a UGL invoice with an RGD record.');
        }

        return $this->buildInvoice($rgd, $lineRecords);
    }

    /**
     * Parses a UGL invoice from a file.
     */
    public function parseFile(string $filePath): UglInvoice {
        if (!file_exists($filePath)) {
            $this->logErrorAndThrow(InvalidArgumentException::class, "File not found: {$filePath}");
        }
        $content = file_get_contents($filePath);
        if ($content === false) {
            $this->logErrorAndThrow(RuntimeException::class, "Failed to read file: {$filePath}");
        }

        return $this->parse($content);
    }

    /**
     * @param  list<string>  $lineRecords  POA-Rohsätze (die Währung steht erst im RGD-Kopf)
     */
    private function buildInvoice(string $rgd, array $lineRecords): UglInvoice {
        $type = $this->alpha($rgd, 14, 15);
        // Die Belegwährung steht im RGD-Kopf und gilt auch für alle Positionen.
        $currency = CurrencyCode::tryFrom($this->alpha($rgd, 24, 26) ?: 'EUR') ?? CurrencyCode::Euro;

        return new UglInvoice(
            number: $this->alpha($rgd, 4, 13),
            documentType: $type !== '' ? $type : UglInvoice::TYPE_INVOICE,
            date: $this->date($this->alpha($rgd, 16, 23)) ?? new DateTimeImmutable('now'),
            currency: $currency,
            grossTotal: $this->money($rgd, 27, 37, 2, $currency),
            vatAmount: $this->money($rgd, 38, 48, 2, $currency),
            netTotal: $this->money($rgd, 54, 64, 2, $currency),
            dueDate: $this->date($this->alpha($rgd, 113, 120)),
            lines: array_map(fn (string $record): OrderLine => $this->parseLine($record, $currency), $lineRecords)
        );
    }

    private function parseLine(string $record, CurrencyCode $currency): OrderLine {
        $position = $this->num($record, 4, 13, 0);
        $unitRaw = $this->alpha($record, 184, 186);

        return new OrderLine(
            id: (string) (int) $position,
            quantity: $this->num($record, 39, 49, 3),
            unitCode: UnitCode::tryFrom(self::UNIT_MAP[$unitRaw] ?? $unitRaw) ?? UnitCode::PIECE,
            netAmount: $this->money($record, 142, 152, 2, $currency),
            itemName: $this->alpha($record, 50, 89),
            unitPrice: $this->money($record, 130, 140, 2, $currency),
            itemDescription: $this->alpha($record, 90, 129) ?: null,
            sellersItemId: $this->alpha($record, 24, 38) ?: null,
            taxPercent: ($tax = $this->num($record, 189, 193, 2)) > 0 ? $tax : null
        );
    }

    private function alpha(string $record, int $from, int $to): string {
        $raw = substr($record, $from - 1, $to - $from + 1);
        $decoded = @iconv(self::ENCODING, 'UTF-8', $raw);

        return rtrim($decoded !== false ? $decoded : $raw);
    }

    /**
     * Betragsfeld mit impliziten Nachkommastellen → Money (kein float-Zwischenschritt).
     */
    private function money(string $record, int $from, int $to, int $decimals, CurrencyCode $currency): Money {
        $raw = trim(substr($record, $from - 1, $to - $from + 1));
        if ($raw === '' || !ctype_digit($raw)) {
            return Money::zero($currency);
        }

        return Money::ofMinor((int) $raw, $currency, $decimals)->withScale($currency->getDefaultFractionDigits());
    }

    private function num(string $record, int $from, int $to, int $decimals): float {
        $raw = trim(substr($record, $from - 1, $to - $from + 1));
        if ($raw === '' || !ctype_digit($raw)) {
            return 0.0;
        }

        return (int) $raw / (10 ** $decimals);
    }

    private function date(string $value): ?DateTimeImmutable {
        $value = trim($value);
        if (!preg_match('/^\d{8}$/', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Ymd', $value);

        return $date !== false ? $date : null;
    }
}
