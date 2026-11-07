<?php
/*
 * Created on   : Sat Nov 07 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : SupportingDocumentXml.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Helper;

use DOMElement;
use DOMNode;
use ERechnungToolkit\Entities\SupportingDocument;

/**
 * BG-24-Elemente für UBL und CII: eine Quelle für den Generator und die
 * Ergänzung fremder Rechnungen ({@see \ERechnungToolkit\Generators\SupportingDocumentAugmenter}).
 *
 * Das Element wird zuerst eingehängt und dann befüllt: So übernehmen die
 * Kinder die Namensraum-Deklarationen der Vorfahren, statt eigene zu tragen.
 */
final class SupportingDocumentXml {
    public const CAC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    public const CBC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    public const RAM_NS = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';

    /** UNTDID 1001: rechnungsbegründende Unterlage (BG-24, nur CII; UBL ohne Code). */
    public const DOCUMENT_TYPE_SUPPORTING = '916';

    /** UNTDID 1001: Objektkennung (BT-18, BT-128). */
    public const DOCUMENT_TYPE_INVOICED_OBJECT = '130';

    /**
     * cac:AdditionalDocumentReference in DocumentReferenceType-Sequenz: ID,
     * DocumentDescription, Attachment (EmbeddedDocumentBinaryObject vor ExternalReference).
     *
     * @param DOMNode|null $before Einfügen vor diesem Kind von $parent; null hängt an
     * @param string $cacPrefix Präfix für CAC_NS, '' für den Vorgabe-Namensraum
     * @param string $cbcPrefix Präfix für CBC_NS, '' für den Vorgabe-Namensraum
     */
    public static function ubl(DOMElement $parent, SupportingDocument $document, ?DOMNode $before = null, string $cacPrefix = 'cac', string $cbcPrefix = 'cbc'): DOMElement {
        $element = self::insert($parent, $before, self::CAC_NS, $cacPrefix, 'AdditionalDocumentReference');
        self::child($element, self::CBC_NS, $cbcPrefix, 'ID', $document->getReference());
        if ($document->getDescription() !== null && $document->getDescription() !== '') {
            self::child($element, self::CBC_NS, $cbcPrefix, 'DocumentDescription', $document->getDescription());
        }

        if ($document->isEmbedded() || $document->getExternalUri() !== null) {
            $attachment = self::child($element, self::CAC_NS, $cacPrefix, 'Attachment');
            if ($document->isEmbedded()) {
                self::setBinaryAttributes(self::child($attachment, self::CBC_NS, $cbcPrefix, 'EmbeddedDocumentBinaryObject', (string) $document->base64()), $document);
            }
            if ($document->getExternalUri() !== null) {
                $external = self::child($attachment, self::CAC_NS, $cacPrefix, 'ExternalReference');
                self::child($external, self::CBC_NS, $cbcPrefix, 'URI', $document->getExternalUri());
            }
        }

        return $element;
    }

    /**
     * ram:AdditionalReferencedDocument in ReferencedDocumentType-Sequenz:
     * IssuerAssignedID, URIID, TypeCode, Name, AttachmentBinaryObject.
     *
     * @param DOMNode|null $before Einfügen vor diesem Kind von $parent; null hängt an
     * @param string $ramPrefix Präfix für RAM_NS, '' für den Vorgabe-Namensraum
     */
    public static function cii(DOMElement $parent, SupportingDocument $document, ?DOMNode $before = null, string $ramPrefix = 'ram'): DOMElement {
        $element = self::insert($parent, $before, self::RAM_NS, $ramPrefix, 'AdditionalReferencedDocument');
        self::child($element, self::RAM_NS, $ramPrefix, 'IssuerAssignedID', $document->getReference());
        if ($document->getExternalUri() !== null) {
            self::child($element, self::RAM_NS, $ramPrefix, 'URIID', $document->getExternalUri());
        }
        self::child($element, self::RAM_NS, $ramPrefix, 'TypeCode', self::DOCUMENT_TYPE_SUPPORTING);
        if ($document->getDescription() !== null && $document->getDescription() !== '') {
            self::child($element, self::RAM_NS, $ramPrefix, 'Name', $document->getDescription());
        }
        if ($document->isEmbedded()) {
            self::setBinaryAttributes(self::child($element, self::RAM_NS, $ramPrefix, 'AttachmentBinaryObject', (string) $document->base64()), $document);
        }

        return $element;
    }

    private static function insert(DOMElement $parent, ?DOMNode $before, string $namespace, string $prefix, string $localName): DOMElement {
        $element = self::create($parent, $namespace, $prefix, $localName);
        $parent->insertBefore($element, $before);

        return $element;
    }

    private static function child(DOMElement $parent, string $namespace, string $prefix, string $localName, ?string $value = null): DOMElement {
        $element = self::create($parent, $namespace, $prefix, $localName, $value);
        $parent->appendChild($element);

        return $element;
    }

    private static function create(DOMElement $context, string $namespace, string $prefix, string $localName, ?string $value = null): DOMElement {
        $document = $context->ownerDocument;
        if ($document === null) {
            throw new \LogicException('Element ohne Dokument');
        }
        $name = $prefix === '' ? $localName : $prefix . ':' . $localName;

        // createElementNS() liest den Wert als Markup; escapen wie UblSerializer::element().
        return $value === null
            ? $document->createElementNS($namespace, $name)
            : $document->createElementNS($namespace, $name, htmlspecialchars($value, ENT_XML1, 'UTF-8'));
    }

    private static function setBinaryAttributes(DOMElement $binary, SupportingDocument $document): void {
        if ($document->getMimeCode() !== null) {
            $binary->setAttribute('mimeCode', $document->getMimeCode());
        }
        if ($document->getFilename() !== null) {
            $binary->setAttribute('filename', $document->getFilename());
        }
    }
}
