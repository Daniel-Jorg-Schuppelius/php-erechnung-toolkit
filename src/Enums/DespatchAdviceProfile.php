<?php
/*
 * Created on   : Wed Jun 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DespatchAdviceProfile.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * Despatch advice conformance profile (Peppol BIS Despatch Advice).
 *
 * The enum value is the ProfileID; the document URNs are returned by
 * {@see self::customizationId()} and {@see self::profileId()}.
 *
 * @see https://docs.peppol.eu/poacc/upgrade-3/syntax/DespatchAdvice/
 */
enum DespatchAdviceProfile: string {
    /** Peppol BIS Despatch Advice 3. */
    case PEPPOL_DESPATCH_ADVICE = 'urn:fdc:peppol.eu:poacc:bis:despatch_advice:3';

    /**
     * Returns the cbc:CustomizationID emitted in the UBL DespatchAdvice.
     */
    public function customizationId(): string {
        return 'urn:fdc:peppol.eu:poacc:trns:despatch_advice:3';
    }

    /**
     * Returns the cbc:ProfileID emitted in the UBL DespatchAdvice.
     */
    public function profileId(): string {
        return 'urn:fdc:peppol.eu:poacc:bis:despatch_advice:3';
    }

    /**
     * Returns the profile name.
     */
    public function label(): string {
        return match ($this) {
            self::PEPPOL_DESPATCH_ADVICE => 'Peppol BIS Despatch Advice',
        };
    }
}
