<?php
/*
 * Created on   : Mon Sep 28 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OpenTransNamespaceInterface.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Contracts;

/** XML namespaces of openTRANS 2.1 and the embedded BMEcat elements. */
interface OpenTransNamespaceInterface {
    public const OT_NS = 'http://www.opentrans.org/XMLSchema/2.1';
    public const BMECAT_NS = 'http://www.bmecat.org/bmecat/2005';
}
