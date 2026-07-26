<?php
/*
 * Created on   : Thu Apr 03 2025
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : BaseTestCase.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

namespace Tests\Contracts;

use DOMNode;
use DOMXPath;
use ERRORToolkit\Factories\ConsoleLoggerFactory;
use ERRORToolkit\LoggerRegistry;
use ERRORToolkit\Traits\ErrorLog;
use PHPUnit\Framework\TestCase;

abstract class BaseTestCase extends TestCase {
    use ErrorLog;

    protected function setUp(): void {
        parent::setUp();

        LoggerRegistry::setLogger(ConsoleLoggerFactory::getLogger());
    }

    /**
     * Returns the first node matching the XPath expression, or null.
     */
    protected function xpathNode(DOMXPath $xpath, string $expression, ?DOMNode $context = null): ?DOMNode {
        $nodes = $xpath->query($expression, $context);
        if ($nodes === false) {
            return null;
        }
        $node = $nodes->item(0);

        return $node instanceof DOMNode ? $node : null;
    }

    /**
     * Returns the text content of the first node matching the XPath expression,
     * or an empty string when no node matches.
     */
    protected function xpathText(DOMXPath $xpath, string $expression, ?DOMNode $context = null): string {
        $node = $this->xpathNode($xpath, $expression, $context);

        return $node !== null ? $node->textContent : '';
    }

    /**
     * Returns the number of nodes matching the XPath expression.
     */
    protected function xpathCount(DOMXPath $xpath, string $expression, ?DOMNode $context = null): int {
        $nodes = $xpath->query($expression, $context);

        return $nodes === false ? 0 : $nodes->length;
    }
}
