<?php
/*
 * Created on   : Sat Nov 07 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : SupportingDocumentAugmenter.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Generators;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use ERechnungToolkit\Entities\SupportingDocument;
use ERechnungToolkit\Enums\{AugmentFailure, ERechnungProfile, ERechnungSyntax};
use ERechnungToolkit\Exceptions\AugmentException;
use ERechnungToolkit\Helper\SupportingDocumentXml;
use ERechnungToolkit\Validators\{DocumentRulesValidator, ValidationMessage};
use ERRORToolkit\Traits\ErrorLog;

/**
 * Ergänzt eine vorhandene, fremd erzeugte XML-Rechnung (UBL Invoice, UBL
 * CreditNote, CII) um rechnungsbegründende Unterlagen (BG-24).
 *
 * Anders als Parser → Document → Generator bleibt jeder Knoten des Originals
 * erhalten: unbekannte Erweiterungen, Kommentare, Verarbeitungsanweisungen,
 * Namensraum-Präfixe und Leerraum. Hinzu kommen nur die neuen Elemente an der
 * Stelle, die UBL 2.1 bzw. CII D16B vorschreibt, gebaut wie im Generator
 * ({@see SupportingDocumentXml}). Kanonisch (C14N) ist der Rest gleich; byte-gleich
 * nicht ganz, weil libxml XML-Deklaration, Anführungszeichen, leere Elemente
 * und Zeichenreferenzen in eigener Form schreibt.
 *
 * Was sich nicht verlustfrei ergänzen lässt, lehnt {@see augment()} mit
 * {@see AugmentException} ab; {@see canAugment()} prüft vorab ohne Unterlagen.
 */
final class SupportingDocumentAugmenter {
    use ErrorLog;

    private const DSIG_NS = 'http://www.w3.org/2000/09/xmldsig#';

    /** Was in UBL 2.1 auf cac:AdditionalDocumentReference folgt, je Wurzel (maindoc-XSD). */
    private const UBL_FOLLOWERS = [
        'Invoice' => ['ProjectReference', 'Signature', 'AccountingSupplierParty'],
        'CreditNote' => ['StatementDocumentReference', 'OriginatorDocumentReference', 'Signature', 'AccountingSupplierParty'],
    ];

    /** Was in HeaderTradeAgreementType (CII D16B) auf ram:AdditionalReferencedDocument folgt. */
    private const CII_FOLLOWERS = [
        'RequisitionerReferencedDocument',
        'BuyerAgentTradeParty',
        'PurchaseConditionsReferencedDocument',
        'SpecifiedProcuringProject',
        'UltimateCustomerOrderReferencedDocument',
    ];

    /**
     * Syntax am Wurzelelement; null für kein XML oder eine andere Wurzel.
     */
    public function detectSyntax(string $xml): ?ERechnungSyntax {
        $dom = $this->loadOrNull($xml);

        return $dom !== null ? $this->syntaxOf($dom) : null;
    }

    /**
     * Trägt das XML eine XML-Signatur (ds:Signature an beliebiger Stelle)?
     * Dieselbe Regel lehnt {@see augment()} ab; kein XML ergibt false.
     */
    public function isSigned(string $xml): bool {
        $dom = $this->loadOrNull($xml);

        return $dom !== null && $this->hasSignature($dom);
    }

    /**
     * Profil aus BT-24 (UBL CustomizationID, CII GuidelineSpecifiedDocumentContextParameter);
     * null, wenn das XML keine ergänzbare Rechnung ist.
     */
    public function detectProfile(string $xml): ?ERechnungProfile {
        $dom = $this->loadOrNull($xml);
        $syntax = $dom !== null ? $this->syntaxOf($dom) : null;

        return $dom !== null && $syntax !== null ? $this->profileOf($this->xpath($dom), $syntax) : null;
    }

    /**
     * Vorprüfung ohne Unterlagen: der Grund, aus dem {@see augment()} ablehnen
     * würde (kein XML, fremde Syntax, Signatur, Profil, Struktur), oder null.
     */
    public function canAugment(string $xml): ?AugmentFailure {
        try {
            $dom = $this->load($xml);
            [$syntax] = $this->inspect($dom);
            $xpath = $this->xpath($dom);
            $this->insertionPoint($this->parentOf($dom, $xpath, $syntax), $syntax);

            return null;
        } catch (AugmentException $e) {
            return $e->getReason();
        }
    }

    /**
     * Fügt die Unterlagen hinter den vorhandenen ein und liefert die ergänzte XML.
     * Ohne Unterlagen kommt die Eingabe unverändert zurück.
     *
     * @param iterable<SupportingDocument> $documents
     * @throws AugmentException
     */
    public function augment(string $xml, iterable $documents): string {
        $documents = is_array($documents) ? array_values($documents) : iterator_to_array($documents, false);

        $dom = $this->load($xml);
        [$syntax, $profile] = $this->inspect($dom);
        $xpath = $this->xpath($dom);
        $parent = $this->parentOf($dom, $xpath, $syntax);
        $before = $this->insertionPoint($parent, $syntax);
        if ($documents === []) {
            return $xml;
        }

        $this->checkDocuments($documents, $profile, $syntax, $xpath, $parent);

        foreach ($documents as $document) {
            if ($syntax->isUbl()) {
                SupportingDocumentXml::ubl(
                    $parent,
                    $document,
                    $before,
                    $this->prefix($parent, SupportingDocumentXml::CAC_NS, 'cac'),
                    $this->prefix($parent, SupportingDocumentXml::CBC_NS, 'cbc')
                );
            } else {
                SupportingDocumentXml::cii($parent, $document, $before, $this->prefix($parent, SupportingDocumentXml::RAM_NS, 'ram'));
            }
        }

        $result = $dom->saveXML();
        if ($result === false) {
            throw $this->reject(AugmentFailure::INVALID_XML, 'Die ergänzte XML ließ sich nicht schreiben.');
        }

        $this->logInfo('Unterlagen in vorhandene Rechnung eingefügt', ['syntax' => $syntax->value, 'count' => count($documents)]);

        return $result;
    }

    /**
     * @return array{ERechnungSyntax, ERechnungProfile}
     */
    private function inspect(DOMDocument $dom): array {
        $syntax = $this->syntaxOf($dom);
        if ($syntax === null) {
            $root = $dom->documentElement;
            throw $this->reject(AugmentFailure::UNSUPPORTED_SYNTAX, sprintf(
                'Keine ergänzbare Rechnung: Wurzel <%s> im Namensraum %s; erwartet UBL Invoice, UBL CreditNote oder CII CrossIndustryInvoice.',
                $root->localName ?? '?',
                $root->namespaceURI ?? '-'
            ));
        }
        if ($this->hasSignature($dom)) {
            throw $this->reject(AugmentFailure::SIGNED, 'Die Rechnung trägt eine XML-Signatur (ds:Signature); jede Ergänzung bräche sie.');
        }

        $profile = $this->profileOf($this->xpath($dom), $syntax);
        if (!$profile->supportsReferencedDocuments()) {
            throw $this->reject(AugmentFailure::UNSUPPORTED_PROFILE, "Das Profil {$profile->label()} kennt keine Unterlagen (BG-24); nötig ist mindestens EN 16931.");
        }

        return [$syntax, $profile];
    }

    private function parentOf(DOMDocument $dom, DOMXPath $xpath, ERechnungSyntax $syntax): DOMElement {
        if ($syntax->isUbl()) {
            return $dom->documentElement ?? throw $this->reject(AugmentFailure::INVALID_XML, 'Kein Wurzelelement.');
        }

        $agreement = $this->firstElement($xpath, '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement');
        if ($agreement === null) {
            throw $this->reject(AugmentFailure::INVALID_STRUCTURE, 'ram:ApplicableHeaderTradeAgreement fehlt; dort stehen die Unterlagen.');
        }

        return $agreement;
    }

    /**
     * Hinter der letzten vorhandenen Unterlage, sonst vor dem ersten Element,
     * das laut Schema folgt; in CII notfalls am Ende.
     */
    private function insertionPoint(DOMElement $parent, ERechnungSyntax $syntax): ?DOMNode {
        [$namespace, $name, $followers] = $syntax->isUbl()
            ? [SupportingDocumentXml::CAC_NS, 'AdditionalDocumentReference', self::UBL_FOLLOWERS[$syntax->rootName()]]
            : [SupportingDocumentXml::RAM_NS, 'AdditionalReferencedDocument', self::CII_FOLLOWERS];

        $last = null;
        $follower = null;
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof DOMElement || $child->namespaceURI !== $namespace) {
                continue;
            }
            if ($child->localName === $name) {
                $last = $child;
            } elseif ($follower === null && in_array($child->localName, $followers, true)) {
                $follower = $child;
            }
        }

        if ($last !== null) {
            return $last->nextSibling;
        }
        if ($follower !== null) {
            return $follower;
        }
        if ($syntax->isUbl()) {
            throw $this->reject(AugmentFailure::INVALID_STRUCTURE, 'cac:AccountingSupplierParty fehlt; ohne sie gibt es keine schemakonforme Einfügestelle.');
        }

        return null;
    }

    /**
     * Regeln der Unterlagen selbst, dann Kennungen und Dateinamen gegen den Bestand.
     *
     * @param list<SupportingDocument> $documents
     */
    private function checkDocuments(array $documents, ERechnungProfile $profile, ERechnungSyntax $syntax, DOMXPath $xpath, DOMElement $parent): void {
        $errors = (new DocumentRulesValidator)->validateSupportingDocuments($documents, $profile)->getErrors();
        if ($errors !== []) {
            $onlyDuplicates = array_filter($errors, static fn (ValidationMessage $m): bool => $m->getCode() !== 'BR-DE-22') === [];
            throw $this->reject(
                $onlyDuplicates ? AugmentFailure::DUPLICATE_FILENAME : AugmentFailure::INVALID_DOCUMENT,
                implode(' ', array_map(static fn (ValidationMessage $m): string => "[{$m->getCode()}] {$m->getText()}", $errors))
            );
        }

        $references = $this->existingReferences($xpath, $syntax, $parent);
        $filenames = $this->existingFilenames($xpath, $syntax);
        foreach ($documents as $document) {
            $reference = trim($document->getReference());
            if (isset($references[$reference])) {
                throw $this->reject(AugmentFailure::DUPLICATE_REFERENCE, "Die Kennung \"{$reference}\" (BT-122) ist schon vergeben.");
            }
            $references[$reference] = true;

            $filename = $document->getFilename();
            if ($document->isEmbedded() && $filename !== null && isset($filenames[mb_strtolower($filename)])) {
                throw $this->reject(
                    AugmentFailure::DUPLICATE_FILENAME,
                    "Der Dateiname \"{$filename}\" kommt in der Rechnung schon vor (\"{$filenames[mb_strtolower($filename)]}\"); Dateinamen müssen ohne Rücksicht auf die Schreibweise eindeutig sein (BR-DE-22)."
                );
            }
        }
    }

    /**
     * Kennungen (BT-122) der vorhandenen Unterlagen, gelesen wie im Parser:
     * UBL jede AdditionalDocumentReference außer Objektkennung (130) und Projektreferenz
     * einer CreditNote (50), CII TypeCode 916.
     *
     * @return array<string, true>
     */
    private function existingReferences(DOMXPath $xpath, ERechnungSyntax $syntax, DOMElement $parent): array {
        $expression = $syntax->isUbl()
            ? "cac:AdditionalDocumentReference[not(normalize-space(cbc:DocumentTypeCode) = '" . SupportingDocumentXml::DOCUMENT_TYPE_INVOICED_OBJECT
                . "' or normalize-space(cbc:DocumentTypeCode) = '" . SupportingDocumentXml::DOCUMENT_TYPE_PROJECT . "')]/cbc:ID"
            : "ram:AdditionalReferencedDocument[normalize-space(ram:TypeCode) = '" . SupportingDocumentXml::DOCUMENT_TYPE_SUPPORTING . "']/ram:IssuerAssignedID";

        $references = [];
        foreach ($xpath->query($expression, $parent) ?: [] as $node) {
            $references[trim($node->nodeValue ?? '')] = true;
        }

        return $references;
    }

    /**
     * Dateinamen aller eingebetteten Anhänge im Dokument (Kleinschreibung => Original).
     *
     * @return array<string, string>
     */
    private function existingFilenames(DOMXPath $xpath, ERechnungSyntax $syntax): array {
        $expression = $syntax->isUbl() ? '//cbc:EmbeddedDocumentBinaryObject/@filename' : '//ram:AttachmentBinaryObject/@filename';

        $filenames = [];
        foreach ($xpath->query($expression) ?: [] as $node) {
            $filenames[mb_strtolower($node->nodeValue ?? '')] = $node->nodeValue ?? '';
        }

        return $filenames;
    }

    /**
     * Präfix, unter dem das Original den Namensraum am Einfügeort kennt; '' für
     * den Vorgabe-Namensraum. Fehlt die Deklaration, trägt sie das neue Element.
     */
    private function prefix(DOMElement $context, string $namespace, string $fallback): string {
        // lookupPrefix() liefert null, wenn der Namensraum kein Präfix hat; die Stubs sagen string.
        $prefix = (string) $context->lookupPrefix($namespace);
        if ($prefix !== '') {
            return $prefix;
        }

        return $context->isDefaultNamespace($namespace) ? '' : $fallback;
    }

    private function profileOf(DOMXPath $xpath, ERechnungSyntax $syntax): ERechnungProfile {
        $expression = $syntax->isUbl()
            ? '/*/cbc:CustomizationID'
            : '/rsm:CrossIndustryInvoice/rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID';
        $identifier = trim($this->firstElement($xpath, $expression)->textContent ?? '');

        return ERechnungProfile::fromSpecificationIdentifier($identifier !== '' ? $identifier : null);
    }

    private function firstElement(DOMXPath $xpath, string $expression): ?DOMElement {
        $nodes = $xpath->query($expression);
        $node = $nodes !== false ? $nodes->item(0) : null;

        return $node instanceof DOMElement ? $node : null;
    }

    private function syntaxOf(DOMDocument $dom): ?ERechnungSyntax {
        $root = $dom->documentElement;

        return $root !== null ? ERechnungSyntax::fromRoot($root->namespaceURI, $root->localName) : null;
    }

    private function hasSignature(DOMDocument $dom): bool {
        return $dom->getElementsByTagNameNS(self::DSIG_NS, 'Signature')->length > 0;
    }

    private function xpath(DOMDocument $dom): DOMXPath {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('rsm', ERechnungSyntax::CII->namespace());
        $xpath->registerNamespace('ram', SupportingDocumentXml::RAM_NS);
        $xpath->registerNamespace('cac', SupportingDocumentXml::CAC_NS);
        $xpath->registerNamespace('cbc', SupportingDocumentXml::CBC_NS);

        return $xpath;
    }

    /**
     * Lädt ohne Netz und ohne Entity-Ersetzung; Leerraum bleibt, nichts wird umformatiert.
     */
    private function load(string $xml): DOMDocument {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;

        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = trim($xml) !== '' && $dom->loadXML($xml, LIBXML_NONET);
            $error = libxml_get_errors()[0] ?? null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$loaded || $dom->documentElement === null) {
            // Ohne Protokoll: detectSyntax() und isSigned() prüfen auch Eingaben, die gar kein XML sind.
            throw new AugmentException(AugmentFailure::INVALID_XML, 'Kein wohlgeformtes XML' . ($error !== null ? ': ' . trim($error->message) : '') . '.');
        }

        return $dom;
    }

    private function loadOrNull(string $xml): ?DOMDocument {
        try {
            return $this->load($xml);
        } catch (AugmentException) {
            return null;
        }
    }

    private function reject(AugmentFailure $reason, string $message): AugmentException {
        $this->logInfo('Ergänzung abgelehnt: ' . $message, ['reason' => $reason->value]);

        return new AugmentException($reason, $message);
    }
}
