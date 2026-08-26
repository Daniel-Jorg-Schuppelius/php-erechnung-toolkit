<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ParticipantId.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Peppol;

use InvalidArgumentException;
use Stringable;

/**
 * Peppol-Teilnehmerkennung (Participant Identifier).
 *
 * Ein Teilnehmer wird in Peppol über ein Schema (fast immer
 * `iso6523-actorid-upis`) und einen Wert aus ICD-Code und Kennung adressiert,
 * z.B. `9930:DE123456789` (deutsche USt-IdNr.) oder `0204:04011000-12345-67`
 * (Leitweg-ID). Die kanonische Schreibweise trennt beide Teile mit `::`:
 * `iso6523-actorid-upis::9930:DE123456789`.
 *
 * Peppol behandelt Teilnehmerkennungen case-insensitiv; der Originalwert bleibt
 * erhalten, {@see equals()}, {@see lowercased()} und die SML-Hashbildung
 * arbeiten case-insensitiv.
 *
 * @see https://docs.peppol.eu/edelivery/policies/ Policy for use of Identifiers
 */
final class ParticipantId implements Stringable {
    /** Peppol-Schema für Teilnehmerkennungen. */
    public const DEFAULT_SCHEME = 'iso6523-actorid-upis';

    /** Trenner zwischen Schema und Wert in der kanonischen Form. */
    public const SEPARATOR = '::';

    /** Maximale Länge des Werts laut Peppol Policy for use of Identifiers. */
    public const MAX_VALUE_LENGTH = 50;

    /** ICD des GLN-Schemas (GS1). */
    public const ICD_GLN = '0088';

    /** ICD der deutschen Leitweg-ID. */
    public const ICD_LEITWEG_ID = '0204';

    /** ICD der deutschen USt-IdNr. */
    public const ICD_DE_VAT = '9930';

    /** ICD von D-U-N-S. */
    public const ICD_DUNS = '0060';

    /**
     * Auszug der gebräuchlichen ICD-Codes (ISO 6523) mit Bezeichnung.
     *
     * Die Liste dient der Anzeige und Plausibilisierung; sie ist bewusst nicht
     * abschließend, weil die Peppol-Codeliste laufend ergänzt wird. Unbekannte
     * ICDs sind daher gültig, liefern aber kein Label.
     */
    private const KNOWN_ICD = [
        '0002' => 'FR:SIRENE',
        '0007' => 'SE:ORGNR',
        '0009' => 'FR:SIRET',
        '0037' => 'FI:OVT',
        '0060' => 'DUNS',
        '0088' => 'GLN (GS1)',
        '0096' => 'DK:P',
        '0097' => 'IT:FTI',
        '0106' => 'NL:KVK',
        '0130' => 'EU:NAL',
        '0135' => 'IT:SIA',
        '0142' => 'IT:SECETI',
        '0151' => 'AU:ABN',
        '0183' => 'CH:UIDB',
        '0184' => 'DK:DIGST',
        '0188' => 'JP:SST',
        '0190' => 'NL:OINO',
        '0191' => 'EE:CC',
        '0192' => 'NO:ORG',
        '0193' => 'UBLBE',
        '0195' => 'SG:UEN',
        '0196' => 'IS:KTNR',
        '0198' => 'DK:ERST',
        '0199' => 'LEI',
        '0200' => 'LT:LEC',
        '0201' => 'IT:IPA',
        '0204' => 'DE:LWID (Leitweg-ID)',
        '0208' => 'BE:EN',
        '0209' => 'GS1:IDENTIFICATION-KEYS',
        '0210' => 'IT:CFI',
        '0211' => 'IT:VAT',
        '0212' => 'FI:ORG',
        '0213' => 'FI:VAT',
        '0215' => 'NL:OIN',
        '9906' => 'IT:VAT',
        '9907' => 'IT:CF',
        '9910' => 'HU:VAT',
        '9913' => 'BE:VAT',
        '9914' => 'AT:VAT',
        '9915' => 'AT:GOV',
        '9918' => 'IBAN',
        '9919' => 'AT:KUR',
        '9920' => 'ES:VAT',
        '9922' => 'AD:VAT',
        '9923' => 'AL:VAT',
        '9925' => 'BE:VAT',
        '9930' => 'DE:VAT (USt-IdNr.)',
        '9931' => 'EE:VAT',
        '9932' => 'GB:VAT',
        '9933' => 'GR:VAT',
        '9934' => 'HR:VAT',
        '9935' => 'IE:VAT',
        '9936' => 'LI:VAT',
        '9937' => 'LT:VAT',
        '9938' => 'LU:VAT',
        '9939' => 'LV:VAT',
        '9940' => 'MC:VAT',
        '9941' => 'ME:VAT',
        '9942' => 'MK:VAT',
        '9943' => 'MT:VAT',
        '9944' => 'NL:VAT',
        '9945' => 'PL:VAT',
        '9946' => 'PT:VAT',
        '9947' => 'RO:VAT',
        '9948' => 'RS:VAT',
        '9949' => 'SI:VAT',
        '9950' => 'SK:VAT',
        '9951' => 'SM:VAT',
        '9952' => 'TR:VAT',
        '9953' => 'VA:VAT',
        '9957' => 'FR:VAT',
        '9959' => 'US:EIN',
    ];

    private readonly string $scheme;

    private readonly string $value;

    /**
     * @param string $value  Wert in der Form `<ICD>:<Kennung>`, z.B. "9930:DE123456789".
     * @param string $scheme Identifier-Schema, in Peppol immer "iso6523-actorid-upis".
     *
     * @throws InvalidArgumentException wenn Schema oder Wert nicht der Peppol-Policy entsprechen.
     */
    public function __construct(string $value, string $scheme = self::DEFAULT_SCHEME) {
        $value = trim($value);
        $scheme = trim($scheme);

        if ($scheme === '' || preg_match('/^[a-zA-Z0-9]+(-[a-zA-Z0-9]+)*$/', $scheme) !== 1) {
            throw new InvalidArgumentException("Ungültiges Identifier-Schema: \"$scheme\".");
        }

        if ($value === '') {
            throw new InvalidArgumentException('Teilnehmerkennung darf nicht leer sein.');
        }

        if (strlen($value) > self::MAX_VALUE_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Teilnehmerkennung ist länger als %d Zeichen: "%s".',
                self::MAX_VALUE_LENGTH,
                $value
            ));
        }

        if (preg_match('/^[0-9]{4}:\S(.*\S)?$/', $value) !== 1) {
            throw new InvalidArgumentException(
                "Teilnehmerkennung muss die Form \"<ICD>:<Kennung>\" haben (4-stelliger ICD), erhalten: \"$value\"."
            );
        }

        $this->scheme = strtolower($scheme);
        $this->value = $value;
    }

    /**
     * Zerlegt die kanonische Schreibweise `<schema>::<wert>`.
     *
     * Ein Wert ohne Schema-Präfix wird als Peppol-Standardschema interpretiert.
     *
     * @throws InvalidArgumentException bei ungültiger Kennung.
     */
    public static function parse(string $canonical): self {
        $canonical = trim($canonical);
        $position = strpos($canonical, self::SEPARATOR);

        if ($position === false) {
            return new self($canonical);
        }

        return new self(
            substr($canonical, $position + strlen(self::SEPARATOR)),
            substr($canonical, 0, $position)
        );
    }

    /**
     * Wie {@see parse()}, liefert aber null statt einer Exception.
     */
    public static function tryParse(string $canonical): ?self {
        try {
            return self::parse($canonical);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Prüft, ob die Zeichenkette eine gültige Teilnehmerkennung ist.
     */
    public static function isValid(string $canonical): bool {
        return self::tryParse($canonical) !== null;
    }

    /** Teilnehmerkennung aus einer deutschen USt-IdNr. (ICD 9930). */
    public static function germanVatId(string $vatId): self {
        return new self(self::ICD_DE_VAT . ':' . strtoupper(preg_replace('/\s+/', '', $vatId) ?? $vatId));
    }

    /** Teilnehmerkennung aus einer Leitweg-ID (ICD 0204). */
    public static function leitwegId(string $leitwegId): self {
        return new self(self::ICD_LEITWEG_ID . ':' . trim($leitwegId));
    }

    /** Teilnehmerkennung aus einer GLN (ICD 0088). */
    public static function gln(string $gln): self {
        return new self(self::ICD_GLN . ':' . trim($gln));
    }

    public function getScheme(): string {
        return $this->scheme;
    }

    public function getValue(): string {
        return $this->value;
    }

    /** Der 4-stellige ICD-Code (ISO 6523), z.B. "9930". */
    public function getIcd(): string {
        return substr($this->value, 0, 4);
    }

    /** Die Kennung hinter dem ICD, z.B. "DE123456789". */
    public function getIdentifier(): string {
        return substr($this->value, 5);
    }

    /** Bezeichnung des ICD-Codes oder null, wenn nicht in der hinterlegten Liste. */
    public function getIcdLabel(): ?string {
        return self::KNOWN_ICD[$this->getIcd()] ?? null;
    }

    /** Ob der ICD in der hinterlegten Codeliste steht (kein Gültigkeitskriterium). */
    public function hasKnownIcd(): bool {
        return isset(self::KNOWN_ICD[$this->getIcd()]);
    }

    /** Ob das Peppol-Standardschema verwendet wird. */
    public function isPeppolScheme(): bool {
        return $this->scheme === self::DEFAULT_SCHEME;
    }

    /** Kanonische Schreibweise `<schema>::<wert>`. */
    public function canonical(): string {
        return $this->scheme . self::SEPARATOR . $this->value;
    }

    /** Kanonische Schreibweise in Kleinbuchstaben (Basis für SML-Hash und SMP-URL). */
    public function lowercased(): string {
        return strtolower($this->canonical());
    }

    /** Kanonische Schreibweise für die Verwendung in einem SMP-URL-Pfadsegment. */
    public function urlEncoded(): string {
        return rawurlencode($this->lowercased());
    }

    /** Vergleich nach Peppol-Regel: Schema und Wert case-insensitiv. */
    public function equals(self $other): bool {
        return $this->lowercased() === $other->lowercased();
    }

    public function __toString(): string {
        return $this->canonical();
    }
}
