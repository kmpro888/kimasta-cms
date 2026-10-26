<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Support;

use CMS\Core\Database\Connection;
use CMS\Modules\Media\Contracts\MediaUsageCheckerInterface;

/**
 * Tells the Media module how many content rows still use a media row as their
 * featured image.
 *
 * This lives in Content because Content owns the `contents` table. Media asks the
 * question through an interface; the module that owns the data answers it. That
 * keeps the module boundary intact — neither module queries the other's tables.
 */
final class ContentMediaUsageChecker implements MediaUsageCheckerInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function countReferences(int $mediaId, int $siteId): int
    {
        return (int) ($this->connection->scalar(
            'SELECT COUNT(*) FROM contents WHERE site_id = :site_id AND featured_media_id = :media_id',
            ['site_id' => $siteId, 'media_id' => $mediaId]
        ) ?? 0);
    }
}
