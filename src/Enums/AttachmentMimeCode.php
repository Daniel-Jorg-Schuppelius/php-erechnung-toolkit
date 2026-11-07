<?php
/*
 * Created on   : Sat Nov 07 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : AttachmentMimeCode.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * MIME codes of an attached supporting document (BT-125-1).
 *
 * EN 16931 (BR-CL-24), XRechnung 3.0 and Peppol BIS Billing 3.0
 * (PEPPOL-EN16931-CL001) accept exactly the first six codes; the
 * XRechnung extension additionally allows application/xml (BR-DEX-01).
 */
enum AttachmentMimeCode: string {
    case PDF = 'application/pdf';
    case PNG = 'image/png';
    case JPEG = 'image/jpeg';
    case CSV = 'text/csv';
    case XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    case ODS = 'application/vnd.oasis.opendocument.spreadsheet';
    case XML = 'application/xml';

    /**
     * @return list<self>
     */
    public static function allowedFor(ERechnungProfile $profile): array {
        $codes = [self::PDF, self::PNG, self::JPEG, self::CSV, self::XLSX, self::ODS];
        if ($profile === ERechnungProfile::XRECHNUNG_EXTENSION) {
            $codes[] = self::XML;
        }

        return $codes;
    }

    public function isAllowedFor(ERechnungProfile $profile): bool {
        return in_array($this, self::allowedFor($profile), true);
    }

    public function extension(): string {
        return match ($this) {
            self::PDF => 'pdf',
            self::PNG => 'png',
            self::JPEG => 'jpg',
            self::CSV => 'csv',
            self::XLSX => 'xlsx',
            self::ODS => 'ods',
            self::XML => 'xml',
        };
    }

    public static function fromExtension(string $extension): ?self {
        return match (strtolower(ltrim($extension, '.'))) {
            'pdf' => self::PDF,
            'png' => self::PNG,
            'jpg', 'jpeg' => self::JPEG,
            'csv' => self::CSV,
            'xlsx' => self::XLSX,
            'ods' => self::ODS,
            'xml' => self::XML,
            default => null,
        };
    }

    /**
     * Whether a MIME type detected from the content fits the declared code.
     *
     * Content sniffing reports CSV as text and the spreadsheet formats often
     * as their ZIP container, so those count as a match.
     */
    public function acceptsDetected(string $detected): bool {
        $detected = strtolower(trim(explode(';', $detected)[0]));

        return match ($this) {
            self::PDF => $detected === 'application/pdf',
            self::PNG => $detected === 'image/png',
            self::JPEG => in_array($detected, ['image/jpeg', 'image/pjpeg'], true),
            self::CSV => str_starts_with($detected, 'text/') || $detected === 'application/csv',
            self::XLSX => in_array($detected, [self::XLSX->value, 'application/zip'], true),
            self::ODS => in_array($detected, [self::ODS->value, 'application/zip'], true),
            self::XML => in_array($detected, ['application/xml', 'text/xml'], true),
        };
    }
}
