<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DocumentAbstract.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Contracts\Abstracts;

use CommonToolkit\Contracts\Abstracts\XML\DomainXmlDocumentAbstract;
use ERechnungToolkit\Enums\ERechnungProfile;
use ERechnungToolkit\Enums\InvoiceType;

/**
 * Abstract base class for all E-Rechnung documents.
 * 
 * Inherits from DomainXmlDocumentAbstract for XmlDocumentInterface implementation.
 * 
 * Supported formats:
 * - XRechnung (German standard for public sector)
 * - ZUGFeRD 2.x / Factur-X (Hybrid PDF/A-3 with embedded XML)
 * - EN 16931 compliant invoices
 * 
 * @package ERechnungToolkit\Contracts\Abstracts
 */
abstract class DocumentAbstract extends DomainXmlDocumentAbstract {
    /**
     * Returns the invoice type.
     */
    abstract public function getInvoiceType(): InvoiceType;

    /**
     * Returns the conformance profile.
     */
    abstract public function getProfile(): ERechnungProfile;

    /**
     * Generates XML output for this document.
     */
    abstract public function toXml(): string;

    /**
     * Generates UBL XML output.
     */
    abstract public function toUblXml(): string;

    /**
     * Generates UN/CEFACT CII XML output.
     */
    abstract public function toCiiXml(): string;

    /**
     * Validates the document according to the profile.
     * 
     * @return string[] Array of validation errors, empty if valid.
     */
    abstract public function validate(): array;

    /**
     * @inheritDoc
     */
    protected function getDefaultXml(): string {
        return $this->toXml();
    }
}
