<?php

declare(strict_types=1);

namespace CMS\Modules\Media\Contracts;

use CMS\Modules\Media\Entity\Media;
use CMS\Modules\Media\Entity\UploadedFile;

/**
 * Media application service: the only supported way to create or change media.
 *
 * The upload pipeline lives behind this interface so no controller ever touches
 * the filesystem or an image library directly.
 *
 * FROZEN CONTRACT.
 */
interface MediaServiceInterface
{
    /**
     * Run the full upload pipeline for a site.
     *
     * Pipeline: validate PHP upload -> MIME inspection -> extension whitelist ->
     * safe filename -> strip EXIF -> resize/compress -> WebP when supported ->
     * persist records.
     *
     * @throws \CMS\Modules\Media\Exceptions\InvalidUploadException
     * @throws \CMS\Modules\Media\Exceptions\UnsupportedMediaTypeException
     * @throws \CMS\Modules\Media\Exceptions\MediaTooLargeException
     */
    public function upload(int $siteId, int $uploadedBy, UploadedFile $file, ?string $altText = null): Media;

    /**
     * Update the editorial metadata of a media row.
     *
     * @throws \CMS\Modules\Media\Exceptions\MediaNotFoundException
     */
    public function update(int $id, int $siteId, array $attributes): Media;

    /**
     * @throws \CMS\Modules\Media\Exceptions\MediaNotFoundException
     */
    public function get(int $id, int $siteId): Media;

    /**
     * @return list<Media>
     */
    public function listForSite(int $siteId, int $limit = 20, int $offset = 0): array;

    /**
     * Delete a media row and its files.
     *
     * Refuses when the file is still referenced as a content featured image
     * unless `$force` is set.
     *
     * @throws \CMS\Modules\Media\Exceptions\MediaNotFoundException
     * @throws \CMS\Modules\Media\Exceptions\MediaInUseException
     */
    public function delete(int $id, int $siteId, bool $force = false): bool;
}
