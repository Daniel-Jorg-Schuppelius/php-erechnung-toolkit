<?php
/*
 * Created on   : Sat Nov 07 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : SupportingDocument.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities;

use CommonToolkit\Helper\FileSystem\File;
use ERechnungToolkit\Enums\AttachmentMimeCode;

/**
 * Additional supporting document (BG-24), e.g. a timesheet or activity report.
 *
 * BT-122 reference, BT-123 description, BT-124 external location and
 * BT-125 attached document with MIME code and filename. The content is kept
 * as raw bytes; generators encode it as Base64.
 *
 * Tax law (BMF 15.10.2025 Rn. 35, 15.10.2026 Rn. 12) only accepts an embedded
 * attachment as part of the e-invoice, a link in BT-124 does not count.
 */
final class SupportingDocument {
    public function __construct(
        private readonly string $reference,
        private readonly ?string $description = null,
        private readonly ?string $externalUri = null,
        private readonly ?string $content = null,
        private readonly ?string $mimeCode = null,
        private readonly ?string $filename = null,
    ) {}

    public static function embedded(
        string $reference,
        string $content,
        AttachmentMimeCode|string $mimeCode,
        string $filename,
        ?string $description = null,
        ?string $externalUri = null,
    ): self {
        return new self(
            $reference,
            $description,
            $externalUri,
            $content,
            $mimeCode instanceof AttachmentMimeCode ? $mimeCode->value : $mimeCode,
            $filename,
        );
    }

    /**
     * Reads a file; without an explicit MIME code it follows the file
     * extension, then content detection.
     */
    public static function fromFile(
        string $reference,
        string $path,
        ?string $filename = null,
        ?string $description = null,
        AttachmentMimeCode|string|null $mimeCode = null,
    ): self {
        $mime = $mimeCode instanceof AttachmentMimeCode ? $mimeCode->value : $mimeCode;
        $mime ??= AttachmentMimeCode::fromExtension(File::extension($path))?->value;
        $mime ??= File::mimeType($path) ?: 'application/octet-stream';

        return new self($reference, $description, null, File::read($path), $mime, $filename ?? basename($path));
    }

    public static function external(string $reference, string $uri, ?string $description = null): self {
        return new self($reference, $description, $uri);
    }

    public function getReference(): string {
        return $this->reference;
    }

    public function getDescription(): ?string {
        return $this->description;
    }

    public function getExternalUri(): ?string {
        return $this->externalUri;
    }

    /** Raw bytes of the attached document (BT-125), null if nothing is embedded. */
    public function getContent(): ?string {
        return $this->content;
    }

    public function getMimeCode(): ?string {
        return $this->mimeCode;
    }

    public function getMimeCodeEnum(): ?AttachmentMimeCode {
        return $this->mimeCode !== null ? AttachmentMimeCode::tryFrom($this->mimeCode) : null;
    }

    public function getFilename(): ?string {
        return $this->filename;
    }

    public function isEmbedded(): bool {
        return $this->content !== null;
    }

    public function base64(): ?string {
        return $this->content !== null ? base64_encode($this->content) : null;
    }

    public function sizeBytes(): int {
        return $this->content !== null ? strlen($this->content) : 0;
    }

    /** Size inside the XML after Base64 encoding (about a third larger). */
    public function encodedSizeBytes(): int {
        return $this->content !== null ? 4 * (int) ceil(strlen($this->content) / 3) : 0;
    }
}
