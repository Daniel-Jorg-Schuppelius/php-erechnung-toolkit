<?php
/*
 * Created on   : Sun Aug 24 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : BmecatMime.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Bmecat;

/**
 * Eine Medienreferenz eines BMEcat-Artikels (MIME-Element): Quelle (URL oder
 * relativer Pfad), Verwendungszweck (MIME_PURPOSE, z. B. "normal",
 * "thumbnail", "data_sheet") und MIME-Typ.
 */
final class BmecatMime {
    public function __construct(
        private readonly string $source,
        private readonly ?string $purpose = null,
        private readonly ?string $mimeType = null
    ) {}

    /** MIME_SOURCE — URL oder relativer Pfad, leer wenn nicht übertragen. */
    public function getSource(): string {
        return $this->source;
    }

    /** MIME_PURPOSE wie übertragen (Vergleich in {@see hasPurpose()} case-insensitiv). */
    public function getPurpose(): ?string {
        return $this->purpose;
    }

    /** MIME_TYPE (z. B. "image/jpeg"). */
    public function getMimeType(): ?string {
        return $this->mimeType;
    }

    /**
     * Passt der Zweck (case-insensitiv) zu einer der Angaben? Referenzen ohne
     * Quelle zählen nie als Treffer.
     *
     * @param  list<string>  $purposes  Zwecke in Kleinschreibung (z. B. "normal", "data_sheet").
     */
    public function hasPurpose(array $purposes): bool {
        return $this->source !== ''
            && in_array(mb_strtolower(trim((string) $this->purpose)), $purposes, true);
    }
}
