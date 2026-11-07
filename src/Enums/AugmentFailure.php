<?php
/*
 * Created on   : Sat Nov 07 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : AugmentFailure.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * Grund, aus dem eine vorhandene E-Rechnung nicht um Unterlagen (BG-24)
 * ergänzt wird. Die Werte sind stabil und für Aufrufer maschinenlesbar.
 */
enum AugmentFailure: string {
    /** Kein wohlgeformtes XML. */
    case INVALID_XML = 'invalid_xml';

    /** Weder UBL Invoice noch UBL CreditNote noch CII CrossIndustryInvoice. */
    case UNSUPPORTED_SYNTAX = 'unsupported_syntax';

    /** Profil ohne BG-24 (MINIMUM, BASIC WL, BASIC). */
    case UNSUPPORTED_PROFILE = 'unsupported_profile';

    /** XML-Signatur (ds:Signature) oder signiertes PDF: jede Änderung bräche sie. */
    case SIGNED = 'signed';

    /** Pflichtelement für die Einfügestelle fehlt (UBL AccountingSupplierParty, CII ApplicableHeaderTradeAgreement). */
    case INVALID_STRUCTURE = 'invalid_structure';

    /** Neue Unterlage verletzt BR-52, BR-CL-24 oder die Anhangsregeln des Toolkits. */
    case INVALID_DOCUMENT = 'invalid_document';

    /** Dateiname (BT-125-2) schon vergeben, ohne Rücksicht auf Groß-/Kleinschreibung (BR-DE-22). */
    case DUPLICATE_FILENAME = 'duplicate_filename';

    /** Kennung (BT-122) schon vergeben. */
    case DUPLICATE_REFERENCE = 'duplicate_reference';

    /** Datei fehlt oder ist kein lesbares PDF. */
    case INVALID_PDF = 'invalid_pdf';

    /** PDF ist verschlüsselt. */
    case ENCRYPTED = 'encrypted';

    /** PDF ohne eingebettete Rechnungs-XML. */
    case NO_INVOICE_XML = 'no_invoice_xml';

    /** php-pdf-toolkit ab 0.31 oder seine Werkzeuge (pdfdetach, mutool) fehlen. */
    case PDF_TOOLKIT_UNAVAILABLE = 'pdf_toolkit_unavailable';

    /** Ein vorhandener Anhang des PDF ließ sich nicht lesen und ginge verloren. */
    case ATTACHMENT_UNREADABLE = 'attachment_unreadable';

    /** Das neue PDF ließ sich nicht schreiben. */
    case PDF_WRITE_FAILED = 'pdf_write_failed';
}
