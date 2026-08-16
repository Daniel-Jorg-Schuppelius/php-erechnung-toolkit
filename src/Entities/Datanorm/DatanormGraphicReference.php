<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DatanormGraphicReference.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Datanorm;

/**
 * DATANORM 5 G-record: one media reference (image, CAD, video, audio) of a
 * graphic binding number. Articles point to the binding number via their
 * `graphicNumber`; the referenced files travel outside the DATANORM file
 * (usually in a D5BILD directory).
 */
final class DatanormGraphicReference {
    public function __construct(
        private readonly string $graphicNumber,
        private readonly int $lineNumber,
        private readonly string $type,
        private readonly string $filename,
        private readonly string $extension,
        private readonly ?string $description = null
    ) {}

    public function getGraphicNumber(): string {
        return $this->graphicNumber;
    }

    public function getLineNumber(): int {
        return $this->lineNumber;
    }

    /** Graphic type code, e.g. `1A` view image, `2S` CAD, `3M` assembly video. */
    public function getType(): string {
        return $this->type;
    }

    public function getFilename(): string {
        return $this->filename;
    }

    public function getExtension(): string {
        return $this->extension;
    }

    public function getDescription(): ?string {
        return $this->description;
    }
}
