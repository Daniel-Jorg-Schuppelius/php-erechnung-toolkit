<?php
/*
 * Created on   : Sat Jun 27 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : UglParser.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Parsers;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use DateTimeImmutable;
use ERechnungToolkit\Entities\{AllowanceCharge, Order, OrderLine, Party, PostalAddress};
use ERechnungToolkit\Enums\{AllowanceChargeReasonCode, UnitCode};
use ERRORToolkit\Traits\ErrorLog;
use InvalidArgumentException;
use RuntimeException;

/**
 * Parser for UGL 5.0 order documents (GC-Gruppe / SHK trade interface).
 *
 * Reads the KOP header and POA article positions of a fixed-width UGL order and
 * maps them onto the shared {@see Order} entity — the inverse of
 * {@see \ERechnungToolkit\Generators\UglGenerator}. Surcharge (POZ), text (POT),
 * address (ADR) and invoice (RGD) records are skipped: only the order core is
 * reconstructed.
 *
 * UGL carries no full seller address in the header (only a supplier number), so
 * the seller party is reconstructed as a minimal placeholder.
 */
final class UglParser {
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
     * Parses a UGL order from a string.
     */
    public function parse(string $content): Order {
        // Sätze bleiben Single-Byte (ISO-8859-1); Felder werden einzeln dekodiert,
        // damit Byte-Positionen auch bei Mehrbyte-Inhalten stimmen.
        $records = preg_split('/\r\n|\r|\n/', $content) ?: [];

        $kop = null;
        $adr = null;
        $pozRecords = [];
        /** @var list<array{poa: string, texts: list<string>}> $lineGroups */
        $lineGroups = [];
        foreach ($records as $record) {
            if (strlen($record) < 3) {
                continue;
            }
            $type = substr($record, 0, 3);
            if ($type === 'KOP') {
                $kop = $record;
            } elseif ($type === 'ADR') {
                $adr = $record;
            } elseif ($type === 'POA') {
                $lineGroups[] = ['poa' => $record, 'texts' => []];
            } elseif ($type === 'POT') {
                $lastKey = array_key_last($lineGroups);
                if ($lastKey !== null) {
                    $lineGroups[$lastKey]['texts'][] = $record; // gehört zur vorigen POA
                }
            } elseif ($type === 'POZ') {
                $pozRecords[] = $record;
            } elseif ($type === 'END') {
                break;
            }
        }

        if ($kop === null) {
            $this->logErrorAndThrow(RuntimeException::class, 'Unknown format. Expected a UGL document with a KOP record.');
        }

        return $this->buildOrder($kop, $adr, $lineGroups, $pozRecords);
    }

    /**
     * Parses a UGL order from a file.
     */
    public function parseFile(string $filePath): Order {
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
     * @param  list<array{poa: string, texts: list<string>}>  $lineGroups
     * @param  list<string>  $pozRecords
     */
    private function buildOrder(string $kop, ?string $adr, array $lineGroups, array $pozRecords): Order {
        $id = $this->alpha($kop, 26, 40);
        $currency = CurrencyCode::tryFrom($this->alpha($kop, 114, 116) ?: 'EUR') ?? CurrencyCode::Euro;
        $issueDate = $this->date($this->alpha($kop, 162, 169)) ?? new DateTimeImmutable('now');
        $deliveryDate = $this->date($this->alpha($kop, 106, 113));
        $salesOrderId = $this->alpha($kop, 91, 105) ?: null;

        $buyerName = $this->alpha($kop, 170, 209);
        $contact = $this->alpha($kop, 122, 161) ?: null;
        $supplierNo = $this->alpha($kop, 14, 23);

        $buyer = new Party(
            name: $buyerName !== '' ? $buyerName : ($contact ?? ''),
            contactName: $contact
        );
        $seller = new Party(name: $supplierNo !== '' ? $supplierNo : 'Großhandel');

        $order = new Order(
            id: $id,
            issueDate: $issueDate,
            buyer: $buyer,
            seller: $seller,
            currency: $currency,
            salesOrderId: $salesOrderId,
            requestedDeliveryStartDate: $deliveryDate
        );

        if ($adr !== null) {
            $order->setDeliveryAddress(
                new PostalAddress(
                    streetName: $this->alpha($adr, 94, 123) ?: null,
                    postalCode: $this->alpha($adr, 127, 132) ?: null,
                    city: $this->alpha($adr, 133, 162) ?: null,
                    country: $this->alpha($adr, 124, 126) ?: null
                ),
                $this->alpha($adr, 4, 33) ?: null,
                $this->alpha($adr, 245, 294) ?: null,
                $this->alpha($adr, 295, 344) ?: null
            );
        }

        foreach ($lineGroups as $group) {
            $order->addLine($this->parseLine($group['poa'], $currency, $group['texts']));
        }

        foreach ($pozRecords as $poz) {
            $typeCode = $this->alpha($poz, 24, 25);
            $reasonCode = match ($typeCode) {
                '07' => AllowanceChargeReasonCode::FREIGHT,
                '01' => AllowanceChargeReasonCode::PACKING,
                default => null,
            };
            $label = $this->alpha($poz, 26, 105);
            $reason = $label !== '' ? $label : ($reasonCode?->label() ?? 'Zuschlag');
            $order->addAllowanceCharge(
                AllowanceCharge::surcharge($this->money($poz, 117, 127, 2, $currency), $reason, $reasonCode, null, null)
            );
        }

        return $order;
    }

    /**
     * @param  list<string>  $texts  POT records belonging to this position
     */
    private function parseLine(string $record, CurrencyCode $currency, array $texts = []): OrderLine {
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
            taxPercent: ($tax = $this->num($record, 189, 193, 2)) > 0 ? $tax : null,
            note: $this->joinTexts($texts)
        );
    }

    /**
     * Joins the Infotext fields (24-63, 64-103, 104-143) of all POT records of a
     * position into a single note. Decoding is per field, so byte positions stay
     * correct even with multi-byte content in earlier records.
     *
     * @param  list<string>  $texts
     */
    private function joinTexts(array $texts): ?string {
        if ($texts === []) {
            return null;
        }
        $note = '';
        foreach ($texts as $pot) {
            $note .= $this->raw($pot, 24, 63) . $this->raw($pot, 64, 103) . $this->raw($pot, 104, 143);
        }
        $note = rtrim($note);

        return $note !== '' ? $note : null;
    }

    /** Reads a trimmed alphanumeric field, decoded from the single-byte UGL codepage. */
    private function alpha(string $record, int $from, int $to): string {
        return rtrim($this->raw($record, $from, $to));
    }

    /** Reads a field's raw bytes and decodes them to UTF-8 (no trimming). */
    private function raw(string $record, int $from, int $to): string {
        return $this->decode(substr($record, $from - 1, $to - $from + 1));
    }

    /** Reads a numeric field with implicit decimals. */
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

    /** Decodes a single-byte UGL field value to UTF-8. */
    private function decode(string $value): string {
        $decoded = @iconv(self::ENCODING, 'UTF-8', $value);

        return $decoded !== false ? $decoded : $value;
    }
}
