<?php

declare(strict_types=1);

namespace CMS\Modules\Media\Entity;

/**
 * A file staged for upload.
 *
 * Wrapping the raw `$_FILES` array in a value object keeps the service free of
 * superglobal access and makes the validation surface explicit: every field is
 * checked here once, not scattered through the pipeline.
 */
final class UploadedFile
{
    public function __construct(
        public readonly string $tmpPath,
        public readonly string $originalName,
        public readonly int $size,
        public readonly ?string $clientMime,
        public readonly int $error,
    ) {
    }

    /**
     * Build from a single `$_FILES` entry.
     *
     * @param array<string, mixed> $entry
     */
    public static function fromFilesArray(array $entry): self
    {
        return new self(
            tmpPath: (string) ($entry['tmp_name'] ?? ''),
            originalName: (string) ($entry['name'] ?? ''),
            size: (int) ($entry['size'] ?? 0),
            clientMime: isset($entry['type']) ? (string) $entry['type'] : null,
            error: (int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE),
        );
    }

    /**
     * Build a synthetic instance for tests, pointing at a real temp file.
     */
    public static function fake(string $tmpPath, string $originalName, ?string $clientMime = null): self
    {
        return new self(
            tmpPath: $tmpPath,
            originalName: $originalName,
            size: is_file($tmpPath) ? (int) filesize($tmpPath) : 0,
            clientMime: $clientMime,
            error: is_file($tmpPath) ? UPLOAD_ERR_OK : UPLOAD_ERR_NO_FILE,
        );
    }

    public function hasUploadError(): bool
    {
        return $this->error !== UPLOAD_ERR_OK;
    }

    /**
     * Lowercase extension of the client-supplied filename.
     */
    public function extension(): string
    {
        return strtolower(pathinfo($this->originalName, PATHINFO_EXTENSION));
    }

    /**
     * True when the name contains more than one dot, which is the shape of a
     * classic `shell.php.jpg` bypass attempt.
     */
    public function hasDoubleExtension(): bool
    {
        return substr_count($this->originalName, '.') > 1;
    }
}
