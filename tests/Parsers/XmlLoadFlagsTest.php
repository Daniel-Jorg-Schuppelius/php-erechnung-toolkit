<?php
/*
 * Created on   : Sun Oct 04 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : XmlLoadFlagsTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Parsers;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Fremdes XML (Eingangsrechnungen per Mail oder Peppol, Kataloge, Warenkörbe)
 * wird immer mit LIBXML_NONET geladen — ohne Netzzugriff beim Parsen. Der
 * Hauptparser und fünf weitere Stellen hatten das Flag nicht.
 */
final class XmlLoadFlagsTest extends TestCase {
    public function test_every_load_xml_call_passes_libxml_nonet(): void {
        $root = dirname(__DIR__, 2) . '/src';
        $violations = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) ?: [] as $index => $line) {
                if (str_contains($line, '->loadXML(') && !str_contains($line, 'LIBXML_NONET')) {
                    $violations[] = substr($file->getPathname(), strlen($root) + 1) . ':' . ($index + 1);
                }
            }
        }

        $this->assertSame([], $violations, "loadXML() ohne LIBXML_NONET:\n" . implode("\n", $violations));
    }
}
