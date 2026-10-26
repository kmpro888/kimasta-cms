<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Support;

use CMS\Core\Database\Connection;
use CMS\Modules\Media\Contracts\MediaUsageCheckerInterface;

/**
 * Tells the Media module how many content rows still use a media row.
 *
 * This lives in Content because Content owns the `contents` table. Media asks the question
 * through an interface; the module that owns the data answers it. That keeps the module
 * boundary intact -- neither module queries the other's tables.
 *
 * Two kinds of reference exist, and both are counted:
 *
 *   - the featured image, which stores the media id;
 *   - an image inside the body, which stores the file's URL in `content_json`, because a URL
 *     is what the page actually renders.
 *
 * Counting only the first was a real defect: the admin happily deleted a file that a published
 * article was displaying, leaving a broken image behind.
 *
 * The body reference is matched by **path**, not by id, because blocks have never carried an
 * id. Matching the path also covers articles written before this fix, which no id-based
 * backfill could reach without guessing. The stored path contains a random suffix, so a
 * substring match cannot collide with another file.
 */
final class ContentMediaUsageChecker implements MediaUsageCheckerInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function countReferences(int $mediaId, int $siteId, ?string $path = null): int
    {
        $sql = 'SELECT COUNT(*) FROM contents WHERE site_id = :site_id AND featured_media_id = :media_id';
        $bindings = ['site_id' => $siteId, 'media_id' => $mediaId];

        if ($path !== null && $path !== '') {
            $sql = 'SELECT COUNT(*) FROM contents WHERE site_id = :site_id'
                . ' AND (featured_media_id = :media_id OR content_json LIKE :path_match)';
            $bindings['path_match'] = '%' . $path . '%';
        }

        return (int) ($this->connection->scalar($sql, $bindings) ?? 0);
    }
}
