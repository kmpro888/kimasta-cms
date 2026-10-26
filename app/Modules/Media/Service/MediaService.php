<?php

declare(strict_types=1);

namespace CMS\Modules\Media\Service;

use CMS\Core\Events\DomainEvent;
use CMS\Modules\Media\Contracts\ImageProcessorInterface;
use CMS\Modules\Media\Contracts\MediaRepositoryInterface;
use CMS\Modules\Media\Contracts\MediaServiceInterface;
use CMS\Modules\Media\Contracts\MediaUsageCheckerInterface;
use CMS\Modules\Media\Entity\Media;
use CMS\Modules\Media\Entity\UploadedFile;
use CMS\Modules\Media\Exceptions\InvalidUploadException;
use CMS\Modules\Media\Exceptions\MediaInUseException;
use CMS\Modules\Media\Exceptions\MediaNotFoundException;
use CMS\Modules\Media\Exceptions\MediaTooLargeException;
use CMS\Modules\Media\Exceptions\UnsupportedMediaTypeException;
use CMS\Shared\Contracts\ClockInterface;
use CMS\Shared\Contracts\EventDispatcherInterface;

/**
 * Media upload and lifecycle.
 *
 * The pipeline is ordered for a reason and must not be reordered:
 *
 *   PHP upload state → size limit → filename sanity → extension whitelist
 *   → real MIME inspection → safe generated name → store
 *   → strip metadata → derive variants → persist records
 *
 * Validation happens on the *inspected* MIME type, never on the client-supplied
 * one, and the stored extension is derived from that inspection rather than from
 * the uploaded filename. Together those two rules remove the classic
 * `shell.php.jpg` class of bypass: the bytes are image bytes or the upload is
 * rejected, and the name written to disk is generated.
 *
 * Files are stored under uploads/{site_id}/{year}/{month}/ so two tenants can
 * upload the same filename without colliding.
 */
final class MediaService implements MediaServiceInterface
{
    /** Extensions the pipeline will accept. */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** Inspected MIME types mapped to the extension actually written. */
    private const MIME_EXTENSION_MAP = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /** Inspected MIME types mapped to the processor format. */
    private const MIME_FORMAT_MAP = [
        'image/jpeg' => 'jpeg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Variant definitions: name => [max width, max height].
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private const VARIANTS = [
        'thumbnail' => [150, 150],
        'medium' => [640, 640],
        'large' => [1280, 1280],
    ];

    public function __construct(
        private readonly MediaRepositoryInterface $media,
        private readonly ImageProcessorInterface $processor,
        private readonly MediaUsageCheckerInterface $usage,
        private readonly EventDispatcherInterface $events,
        private readonly ClockInterface $clock,
        private readonly string $uploadsDirectory,
        private readonly int $maxUploadBytes = 10485760,
    ) {
    }

    public function upload(int $siteId, int $uploadedBy, UploadedFile $file, ?string $altText = null): Media
    {
        if ($siteId <= 0) {
            throw new InvalidUploadException('A site id is required to upload media.');
        }

        if ($uploadedBy <= 0) {
            throw new InvalidUploadException('An uploader is required.');
        }

        $this->assertUploadIsUsable($file);

        $mime = $this->inspectMime($file->tmpPath);

        if (!isset(self::MIME_EXTENSION_MAP[$mime])) {
            throw new UnsupportedMediaTypeException($mime);
        }

        $extension = self::MIME_EXTENSION_MAP[$mime];
        $format = self::MIME_FORMAT_MAP[$mime];

        $dimensions = $this->processor->inspect($file->tmpPath);

        $relativeDirectory = $this->relativeDirectory($siteId);
        $absoluteDirectory = $this->uploadsDirectory . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);

        $this->ensureDirectory($absoluteDirectory);

        // A generated name, never the client's. This removes path traversal,
        // null-byte tricks and double-extension bypasses in one step.
        $basename = $this->safeBasename($file->originalName);
        $filename = $basename . '-' . bin2hex(random_bytes(6)) . '.' . $extension;

        $absolutePath = $absoluteDirectory . DIRECTORY_SEPARATOR . $filename;
        $relativePath = $relativeDirectory . '/' . $filename;

        if (!$this->storeFile($file->tmpPath, $absolutePath)) {
            throw new InvalidUploadException('The uploaded file could not be stored.');
        }

        // Best-effort: a backend that cannot strip metadata still produced a
        // usable file, and GD's re-encode already dropped it.
        $this->processor->stripMetadata($absolutePath);

        $storedSize = (int) (filesize($absolutePath) ?: $file->size);

        $media = $this->media->create($siteId, [
            'uploaded_by' => $uploadedBy,
            'original_name' => $this->sanitizeOriginalName($file->originalName),
            'path' => $relativePath,
            'mime_type' => $mime,
            'extension' => $extension,
            'width' => $dimensions['width'] ?? null,
            'height' => $dimensions['height'] ?? null,
            'filesize' => $storedSize,
            'alt_text' => $altText !== null && trim($altText) !== '' ? trim($altText) : $basename,
        ]);

        $this->deriveVariants($media, $absoluteDirectory, $relativeDirectory, $basename, $format);

        $this->events->dispatch(DomainEvent::create(
            'media.created',
            [
                'id' => $media->id,
                'site_id' => $media->siteId,
                'path' => $media->path,
                'mime_type' => $media->mimeType,
            ],
            $siteId,
            $this->clock,
        ));

        return $media;
    }

    public function update(int $id, int $siteId, array $attributes): Media
    {
        $existing = $this->media->findById($id, $siteId);

        if ($existing === null) {
            throw new MediaNotFoundException();
        }

        $changes = [];

        if (array_key_exists('alt_text', $attributes)) {
            $changes['alt_text'] = is_string($attributes['alt_text']) ? trim($attributes['alt_text']) : null;
        }

        if (array_key_exists('caption', $attributes)) {
            $changes['caption'] = is_string($attributes['caption']) ? trim($attributes['caption']) : null;
        }

        if ($changes === []) {
            return $existing;
        }

        $this->media->update($id, $siteId, $changes);

        $updated = $this->media->findById($id, $siteId);

        if ($updated === null) {
            throw new MediaNotFoundException();
        }

        $this->events->dispatch(DomainEvent::create(
            'media.updated',
            ['id' => $updated->id, 'site_id' => $updated->siteId, 'path' => $updated->path],
            $siteId,
            $this->clock,
        ));

        return $updated;
    }

    public function get(int $id, int $siteId): Media
    {
        $media = $id > 0 ? $this->media->findById($id, $siteId) : null;

        if ($media === null) {
            throw new MediaNotFoundException();
        }

        return $media;
    }

    public function listForSite(int $siteId, int $limit = 20, int $offset = 0): array
    {
        return $this->media->listBySite($siteId, $limit, $offset);
    }

    public function delete(int $id, int $siteId, bool $force = false): bool
    {
        $media = $this->media->findById($id, $siteId);

        if ($media === null) {
            throw new MediaNotFoundException();
        }

        if (!$force) {
            $references = $this->usage->countReferences($id, $siteId, $media->path);

            if ($references > 0) {
                throw new MediaInUseException($references);
            }
        }

        $variants = $this->media->variantsFor($id, $siteId);

        $this->media->deleteVariants($id, $siteId);
        $deleted = $this->media->delete($id, $siteId);

        if (!$deleted) {
            return false;
        }

        // Files are removed only after the row is gone: a missing file with a
        // live row would render a broken image, while an orphan file is harmless
        // and recoverable.
        $this->deleteFile($media->path);

        foreach ($variants as $variant) {
            $this->deleteFile($variant->path);
        }

        $this->events->dispatch(DomainEvent::create(
            'media.deleted',
            ['id' => $media->id, 'site_id' => $media->siteId, 'path' => $media->path],
            $siteId,
            $this->clock,
        ));

        return true;
    }

    private function assertUploadIsUsable(UploadedFile $file): void
    {
        if ($file->hasUploadError()) {
            throw new InvalidUploadException('The upload did not complete.');
        }

        if ($file->size <= 0 || !is_file($file->tmpPath)) {
            throw new InvalidUploadException('The uploaded file is empty.');
        }

        if ($file->size > $this->maxUploadBytes) {
            throw new MediaTooLargeException($this->maxUploadBytes);
        }

        // A second dot is the shape of `shell.php.jpg`; the extension whitelist
        // would already reject it, but refusing early gives a clearer error.
        if ($file->hasDoubleExtension()) {
            throw new InvalidUploadException('Filenames with multiple extensions are not allowed.');
        }

        if (!in_array($file->extension(), self::ALLOWED_EXTENSIONS, true)) {
            throw new UnsupportedMediaTypeException();
        }
    }

    /**
     * Inspect the real bytes, ignoring the client-supplied content type.
     */
    private function inspectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $detected = finfo_file($finfo, $path);
                finfo_close($finfo);

                if (is_string($detected) && $detected !== '') {
                    return strtolower($detected);
                }
            }
        }

        $info = @getimagesize($path);

        return is_array($info) ? strtolower((string) ($info['mime'] ?? 'application/octet-stream')) : 'application/octet-stream';
    }

    private function relativeDirectory(int $siteId): string
    {
        $now = $this->clock->now();

        return $siteId . '/' . $now->format('Y') . '/' . $now->format('m');
    }

    private function ensureDirectory(string $absoluteDirectory): void
    {
        if (is_dir($absoluteDirectory)) {
            return;
        }

        if (!mkdir($absoluteDirectory, 0775, true) && !is_dir($absoluteDirectory)) {
            throw new InvalidUploadException('The upload directory could not be created.');
        }
    }

    private function storeFile(string $tmpPath, string $destination): bool
    {
        // move_uploaded_file only accepts a genuine PHP upload; fall back to a
        // copy so the pipeline is usable from tests and CLI imports.
        if (is_uploaded_file($tmpPath)) {
            return move_uploaded_file($tmpPath, $destination);
        }

        return copy($tmpPath, $destination);
    }

    private function deleteFile(string $relativePath): void
    {
        $absolute = $this->uploadsDirectory . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relativePath, '/'));

        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    private function deriveVariants(
        Media $media,
        string $absoluteDirectory,
        string $relativeDirectory,
        string $basename,
        string $sourceFormat,
    ): void {
        $id = $media->id;

        if ($id === null || !$this->processor->isAvailable()) {
            return;
        }

        $sourcePath = $this->uploadsDirectory . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $media->path);

        $formats = [$sourceFormat];

        // WebP is generated only when the backend can actually encode it.
        if ($sourceFormat !== 'webp' && $this->processor->supportsFormat('webp')) {
            $formats[] = 'webp';
        }

        foreach (self::VARIANTS as $name => [$maxWidth, $maxHeight]) {
            foreach ($formats as $format) {
                $extension = $format === 'jpeg' ? 'jpg' : $format;
                $filename = $basename . '-' . $name . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
                $absoluteVariantPath = $absoluteDirectory . DIRECTORY_SEPARATOR . $filename;
                $relativeVariantPath = $relativeDirectory . '/' . $filename;

                $result = $this->processor->transform(
                    $sourcePath,
                    $absoluteVariantPath,
                    $format,
                    $maxWidth,
                    $maxHeight
                );

                if ($result === null) {
                    continue;
                }

                $this->media->addVariant(
                    $id,
                    $media->siteId,
                    $name,
                    $format,
                    $relativeVariantPath,
                    $result['width'],
                    $result['height'],
                    $result['filesize']
                );
            }
        }
    }

    private function safeBasename(string $originalName): string
    {
        $name = pathinfo($originalName, PATHINFO_FILENAME);
        $name = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        $name = trim($name, '-');

        if ($name === '') {
            $name = 'file';
        }

        return substr($name, 0, 80);
    }

    private function sanitizeOriginalName(string $originalName): string
    {
        // Strip directory components and control characters for display only;
        // the stored file name is generated separately.
        $name = basename(str_replace('\\', '/', $originalName));
        $name = (string) preg_replace('/[\x00-\x1f\x7f]/', '', $name);

        return substr($name, 0, 255);
    }
}
