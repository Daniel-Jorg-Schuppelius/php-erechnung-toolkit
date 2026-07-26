<?php
/*
 * Created on   : Wed Jun 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OrderXPdfGenerator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Generators;

use ERechnungToolkit\Entities\Order;
use ERechnungToolkit\Enums\OrderXProfile;
use ERRORToolkit\Traits\ErrorLog;

/**
 * Generator for Order-X hybrid PDF/A-3 documents.
 *
 * Embeds the Order-X CII XML (as `order-x.xml`) into a PDF/A-3 file — the
 * order-side counterpart to {@see ZugferdPdfGenerator}. Reuses the same
 * PDF writer from dschuppelius/php-pdf-toolkit; the embedded attachment name is
 * overridden via the writer's `invoice_filename` option.
 *
 * Requires dschuppelius/php-pdf-toolkit.
 */
final class OrderXPdfGenerator {
    use ErrorLog;

    /** @var class-string<\PDFToolkit\Writers\ZugferdWriter> */
    private const ZUGFERD_WRITER_CLASS = 'PDFToolkit\\Writers\\ZugferdWriter';
    /** @var class-string<\PDFToolkit\Entities\PDFContent> */
    private const PDF_CONTENT_CLASS = 'PDFToolkit\\Entities\\PDFContent';

    /** Spec-conformant attachment filename for Order-X. */
    public const ORDERX_FILENAME = 'order-x.xml';

    /** @var \PDFToolkit\Writers\ZugferdWriter|null */
    private ?object $writer = null;

    /**
     * Checks whether the PDF toolkit is available.
     */
    public function isAvailable(): bool {
        return class_exists(self::ZUGFERD_WRITER_CLASS)
            && class_exists(self::PDF_CONTENT_CLASS);
    }

    /**
     * Generates an Order-X hybrid PDF from an order document.
     *
     * @param Order $order The order document
     * @param string|null $visualHtml Optional HTML for the visual representation
     * @param array<string, mixed> $options Additional PDF generation options
     * @return string|null PDF bytes, or null on failure
     */
    public function generate(
        Order $order,
        ?string $visualHtml = null,
        array $options = [],
        OrderXProfile $profile = OrderXProfile::COMFORT
    ): ?string {
        if (!$this->isAvailable()) {
            $this->logError('Order-X PDF generation requires dschuppelius/php-pdf-toolkit. Install with: composer require dschuppelius/php-pdf-toolkit');
            return null;
        }

        if ($visualHtml === null) {
            $visualHtml = $this->generateDefaultHtml($order);
        }

        $orderXml = (new OrderXGenerator)->generateCii($order, $profile);

        $contentClass = self::PDF_CONTENT_CLASS;
        $content = $contentClass::fromHtml($visualHtml, [
            'invoice_xml' => $orderXml,
            'title' => 'Bestellung ' . $order->getId(),
            'subject' => 'Order-X Bestellung',
        ]);

        $writer = $this->getWriter();
        return $writer->createPdfString($content, array_merge($options, [
            'facturx' => true,
            'invoice_filename' => self::ORDERX_FILENAME,
        ]));
    }

    /**
     * Generates an Order-X PDF and writes it to a file.
     *
     * @param array<string, mixed> $options
     */
    public function generateToFile(
        Order $order,
        string $outputPath,
        ?string $visualHtml = null,
        array $options = [],
        OrderXProfile $profile = OrderXProfile::COMFORT
    ): bool {
        $pdfBytes = $this->generate($order, $visualHtml, $options, $profile);
        if ($pdfBytes === null) {
            return false;
        }
        return file_put_contents($outputPath, $pdfBytes) !== false;
    }

    /**
     * @return \PDFToolkit\Writers\ZugferdWriter
     */
    private function getWriter(): object {
        if ($this->writer === null) {
            $writerClass = self::ZUGFERD_WRITER_CLASS;
            $this->writer = new $writerClass;
        }
        return $this->writer;
    }

    /**
     * Generates a minimal default HTML representation of the order.
     */
    private function generateDefaultHtml(Order $order): string {
        $currency = $order->getCurrency()->value;
        $rows = '';
        foreach ($order->getLines() as $line) {
            $rows .= sprintf(
                '<tr><td>%s</td><td>%s</td><td style="text-align:right">%s %s</td>'
                    . '<td style="text-align:right">%s %s</td><td style="text-align:right">%s %s</td></tr>',
                htmlspecialchars($line->getId()),
                htmlspecialchars($line->getItemName()),
                number_format($line->getQuantity(), 2, ',', '.'),
                htmlspecialchars($line->getUnitCode()->value),
                $line->getUnitPrice()->format(false),
                $currency,
                $line->getNetAmount()->format(false),
                $currency
            );
        }

        return sprintf(
            '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">'
                . '<style>body{font-family:sans-serif;font-size:12px}table{width:100%%;border-collapse:collapse}'
                . 'th,td{border:1px solid #ccc;padding:4px}th{background:#f0f0f0;text-align:left}</style></head>'
                . '<body><h1>Bestellung %s</h1>'
                . '<p><strong>Datum:</strong> %s</p>'
                . '<p><strong>Besteller:</strong> %s<br><strong>Lieferant:</strong> %s</p>'
                . '<table><thead><tr><th>Pos.</th><th>Artikel</th><th>Menge</th><th>Einzelpreis</th><th>Betrag</th></tr></thead>'
                . '<tbody>%s</tbody></table>'
                . '<p style="text-align:right"><strong>Summe netto: %s %s</strong></p>'
                . '</body></html>',
            htmlspecialchars($order->getId()),
            $order->getIssueDate()->format('d.m.Y'),
            htmlspecialchars($order->getBuyer()->getName()),
            htmlspecialchars($order->getSeller()->getName()),
            $rows,
            $order->getPayableAmount()->format(false),
            $currency
        );
    }
}
