<?php

declare(strict_types=1);

namespace CMS\Modules\Media\Contracts;

/**
 * Media persistence.
 *
 * Every method is site-scoped: a media id from another tenant resolves to null.
 *
 * FROZEN CONTRACT.
 */
interface MediaRepositoryInterface
{
    public function findById(int $id, int $siteId): ?\CMS\Modules\Media\Entity\Media;

    /**
     * @return list<\CMS\Modules\Media\Entity\Media>
     */
    public function listBySite(int $siteId, int $limit = 20, int $offset = 0): array;

    public function countBySite(int $siteId): int;

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(int $siteId, array $attributes): \CMS\Modules\Media\Entity\Media;

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(int $id, int $siteId, array $attributes): void;

    public function delete(int $id, int $siteId): bool;

    /**
     * @return list<\CMS\Modules\Media\Entity\MediaVariant>
     */
    public function variantsFor(int $mediaId, int $siteId): array;

    public function addVariant(int $mediaId, int $siteId, string $variant, string $format, string $path, ?int $width, ?int $height, ?int $filesize): void;

    public function deleteVariants(int $mediaId, int $siteId): void;
}
