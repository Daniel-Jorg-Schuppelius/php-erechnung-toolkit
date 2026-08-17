<?php
/*
 * Created on   : Sun Aug 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebAwardCategory.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * Award procedure (GAEB DA XML, `Cat`).
 *
 * Eleven values in 3.3, ten in 3.2 - the innovation partnership came later. Two
 * pairs look alike but are not: `SelectCall`/`SelectCallPostOpen` and
 * `NegProc`/`NegProcOpen` differ in whether a call for participation preceded
 * the procedure, which changes the deadlines and who may bid at all. Merging
 * them would be a legal error, not a simplification.
 *
 * The vocabulary is VOB-centric. Procedures the UVgO knows and GAEB does not
 * (Verhandlungsvergabe, Direktauftrag) have no value here - an application-side
 * enum is the wider one, GAEB only its projection.
 */
enum GaebAwardCategory: string {
    case OpenProcedure = 'OpenProc';                   // Offenes Verfahren
    case RestrictedProcedure = 'ClosedProc';           // Nichtoffenes Verfahren
    case NegotiatedProcedure = 'NegProc';              // Verhandlungsverfahren ohne Teilnahmewettbewerb
    case NegotiatedProcedureWithCall = 'NegProcOpen';  // Verhandlungsverfahren mit Teilnahmewettbewerb
    case PublicInvitation = 'OpenCall';                // Öffentliche Ausschreibung
    case RestrictedInvitation = 'SelectCall';          // Beschränkte Ausschreibung ohne Teilnahmewettbewerb
    case RestrictedInvitationWithCall = 'SelectCallPostOpen'; // Beschränkte Ausschreibung mit Teilnahmewettbewerb
    case DirectContract = 'NegCont';                   // Freihändige Vergabe
    case NatoTender = 'IntNATO';                       // Internationale NATO-Ausschreibung
    case CompetitiveDialogue = 'CompetDialog';         // Wettbewerblicher Dialog
    case InnovationPartnership = 'InnovationPartnership';

    public function label(): string {
        return match ($this) {
            self::OpenProcedure => 'Offenes Verfahren',
            self::RestrictedProcedure => 'Nichtoffenes Verfahren',
            self::NegotiatedProcedure => 'Verhandlungsverfahren ohne Teilnahmewettbewerb',
            self::NegotiatedProcedureWithCall => 'Verhandlungsverfahren mit Teilnahmewettbewerb',
            self::PublicInvitation => 'Öffentliche Ausschreibung',
            self::RestrictedInvitation => 'Beschränkte Ausschreibung ohne Teilnahmewettbewerb',
            self::RestrictedInvitationWithCall => 'Beschränkte Ausschreibung mit Teilnahmewettbewerb',
            self::DirectContract => 'Freihändige Vergabe',
            self::NatoTender => 'Internationale NATO-Ausschreibung',
            self::CompetitiveDialogue => 'Wettbewerblicher Dialog',
            self::InnovationPartnership => 'Innovationspartnerschaft',
        };
    }

    /** Did a call for participation precede the procedure? */
    public function hasCallForParticipation(): bool {
        return match ($this) {
            self::RestrictedProcedure, self::NegotiatedProcedureWithCall,
            self::RestrictedInvitationWithCall, self::CompetitiveDialogue,
            self::InnovationPartnership => true,
            default => false,
        };
    }

    /**
     * Is this value already defined in the given edition? The innovation
     * partnership exists only from 3.3 - writing it into a 3.2 file produces a
     * document the other side cannot read.
     */
    public function existsIn(string $version): bool {
        return $this !== self::InnovationPartnership || version_compare($version, '3.3', '>=');
    }

    /** The four values a framework agreement (Zeitvertrag) may carry. */
    public function allowedInFrameworkAgreement(): bool {
        return match ($this) {
            self::PublicInvitation, self::RestrictedInvitation,
            self::DirectContract, self::RestrictedInvitationWithCall => true,
            default => false,
        };
    }
}
