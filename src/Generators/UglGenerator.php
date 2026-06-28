<?php
/*
 * Created on   : Sat Jun 27 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : UglGenerator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Generators;

use ERechnungToolkit\Entities\{Order, OrderLine};
use ERechnungToolkit\Enums\UnitCode;
use ERRORToolkit\Traits\ErrorLog;

/**
 * Generator for UGL 5.0 order documents (GC-Gruppe / SHK trade interface).
 *
 * UGL ("UeberGabeschnittstelle Lang") is a fixed-width ASCII record format used
 * for order data exchange between craftsmen (Handwerker, HW) and wholesalers
 * (Großhandel, GH) in the German sanitary/heating/plumbing trade. Unlike the XML
 * formats (UBL/Order-X/openTRANS) it is byte-positional: every record is exactly
 * 350 bytes, terminated by CR/LF; numeric fields are right-aligned, zero-padded
 * with an implicit decimal point; alphanumeric fields are left-aligned, space-
 * padded.
 *
 * This generator maps the shared {@see Order} entity onto a craftsman→wholesaler
 * order (Anfrageart `BE` = Lieferauftrag): one KOP header, one POA per line, one
 * END trailer. The buyer is the craftsman (sender), the seller the wholesaler.
 *
 * Record sequence: KOP, POA*, END.
 *
 * @see https://www.gc-gruppe.de — UGL 5.0 specification
 */
final class UglGenerator {
    use ErrorLog;

    private const RECORD_LENGTH = 350;
    private const VERSION = '05.00';
    private const EOL = "\r\n";

    /** Output codepage — UGL uses a single-byte (Datanorm 4.0) charset. */
    private const ENCODING = 'ISO-8859-1';

    /** Anfrageart `BE` = Lieferauftrag des Handwerkers beim Großhandel (purchase order). */
    public const TYPE_ORDER = 'BE';

    /** UN/ECE unit code → UGL Mengeneinheit (3 chars). */
    private const UNIT_MAP = [
        'H87' => 'ST',  // Stück / piece
        'C62' => 'ST',  // one
        'HUR' => 'STD', // hour
        'MTR' => 'M',   // metre
        'MTK' => 'QM',  // square metre
        'MTQ' => 'CBM', // cubic metre
        'KGM' => 'KG',  // kilogram
        'LTR' => 'L',   // litre
    ];

    /**
     * Generates a UGL 5.0 order (KOP + POA* + END).
     *
     * @param  string  $anfrageart  KOP record type code (default `BE` = order)
     * @param  string|null  $buyerCustomerNo  craftsman's customer no. at the wholesaler (KOP 4-13)
     * @param  string|null  $supplierNo  wholesaler's supplier no. at the craftsman (KOP 14-23)
     */
    public function generateOrder(
        Order $order,
        string $anfrageart = self::TYPE_ORDER,
        ?string $buyerCustomerNo = null,
        ?string $supplierNo = null
    ): string {
        $this->logDebug('Generating UGL order', ['id' => $order->getId(), 'type' => $anfrageart]);

        $records = [];
        $records[] = $this->kop($order, $anfrageart, $buyerCustomerNo, $supplierNo);

        $position = 0;
        foreach ($order->getLines() as $line) {
            $position++;
            $records[] = $this->poa($line, $position);
        }

        $records[] = $this->end();

        $out = '';
        foreach ($records as $record) {
            $out .= $this->encode($record) . self::EOL;
        }

        return $out;
    }

    private function kop(Order $order, string $anfrageart, ?string $buyerCustomerNo, ?string $supplierNo): string {
        $buyer = $order->getBuyer();
        $deliveryDate = $order->getRequestedDeliveryStartDate() ?? $order->getIssueDate();
        $note = $order->getNotes()[0] ?? '';

        $rec = $this->blank();
        $rec = $this->putAlpha($rec, 1, 3, 'KOP');
        $rec = $this->putAlpha($rec, 4, 13, (string) ($buyerCustomerNo ?? $order->getBuyerReference() ?? ''));
        $rec = $this->putAlpha($rec, 14, 23, (string) ($supplierNo ?? ''));
        $rec = $this->putAlpha($rec, 24, 25, $anfrageart);
        $rec = $this->putAlpha($rec, 26, 40, $order->getId());
        $rec = $this->putAlpha($rec, 41, 90, $note);
        $rec = $this->putAlpha($rec, 91, 105, (string) ($order->getSalesOrderId() ?? ''));
        $rec = $this->putAlpha($rec, 106, 113, $deliveryDate->format('Ymd'));
        $rec = $this->putAlpha($rec, 114, 116, $order->getCurrency()->value);
        $rec = $this->putAlpha($rec, 117, 121, self::VERSION);
        $rec = $this->putAlpha($rec, 122, 161, (string) ($buyer->getContactName() ?? ''));
        $rec = $this->putAlpha($rec, 162, 169, $order->getIssueDate()->format('Ymd'));
        $rec = $this->putAlpha($rec, 170, 209, (string) ($buyer->getContactName() ?? $buyer->getName()));

        return $rec;
    }

    private function poa(OrderLine $line, int $position): string {
        $rec = $this->blank();
        $rec = $this->putAlpha($rec, 1, 3, 'POA');
        $rec = $this->putNum($rec, 4, 13, (float) $position, 0);
        $rec = $this->putNum($rec, 14, 23, 0.0, 0);
        $rec = $this->putAlpha($rec, 24, 38, (string) ($line->getSellersItemId() ?? ''));
        $rec = $this->putNum($rec, 39, 49, $line->getQuantity(), 3);
        $rec = $this->putAlpha($rec, 50, 89, $line->getItemName());
        $rec = $this->putAlpha($rec, 90, 129, (string) ($line->getItemDescription() ?? ''));
        $rec = $this->putNum($rec, 130, 140, $line->getUnitPrice(), 2);
        $rec = $this->putAlpha($rec, 141, 141, '0'); // Preiseinheit 0 = je 1 (Datanorm 4.0)
        $rec = $this->putNum($rec, 142, 152, $line->getNetAmount(), 2);
        $rec = $this->putNum($rec, 153, 157, 0.0, 2); // Rabatt 1
        $rec = $this->putNum($rec, 158, 162, 0.0, 2); // Rabatt 2
        $rec = $this->putAlpha($rec, 181, 181, ' ');  // Originalposition
        $rec = $this->putAlpha($rec, 182, 182, 'H');  // reguläre Artikel-Hauptposition
        $rec = $this->putAlpha($rec, 184, 186, $this->unit($line->getUnitCode()));
        $rec = $this->putAlpha($rec, 187, 187, '2');  // Brutto-Einzelpreis + Netto-Positionswert gefüllt
        $rec = $this->putAlpha($rec, 188, 188, 'B');  // Bestellware
        $rec = $this->putNum($rec, 189, 193, $line->getTaxPercent() ?? 0.0, 2);

        return $rec;
    }

    private function end(): string {
        return $this->putAlpha($this->blank(), 1, 3, 'END');
    }

    private function unit(UnitCode $unit): string {
        return self::UNIT_MAP[$unit->value] ?? substr($unit->value, 0, 3);
    }

    private function blank(): string {
        return str_repeat(' ', self::RECORD_LENGTH);
    }

    /** Writes a left-aligned, space-padded alphanumeric field (1-based positions, inclusive). */
    private function putAlpha(string $record, int $from, int $to, string $value): string {
        $length = $to - $from + 1;
        $value = mb_substr($value, 0, $length);
        $value .= str_repeat(' ', $length - mb_strlen($value));

        return $this->place($record, $from, $value, $length);
    }

    /** Writes a right-aligned, zero-padded numeric field with implicit decimals. */
    private function putNum(string $record, int $from, int $to, float $value, int $decimals): string {
        $length = $to - $from + 1;
        $scaled = (int) round(abs($value) * (10 ** $decimals));
        $digits = (string) $scaled;
        if (strlen($digits) > $length) {
            $digits = substr($digits, -$length); // Überlauf: niederwertige Stellen behalten
        }
        $digits = str_pad($digits, $length, '0', STR_PAD_LEFT);

        return $this->place($record, $from, $digits, $length);
    }

    private function place(string $record, int $from, string $value, int $length): string {
        return substr_replace($record, $value, $from - 1, $length);
    }

    /** Converts a 350-char (UTF-8) record to the single-byte UGL codepage. */
    private function encode(string $record): string {
        $encoded = @iconv('UTF-8', self::ENCODING . '//TRANSLIT', $record);

        return $encoded !== false ? $encoded : $record;
    }
}
