<?php

declare(strict_types=1);

namespace CMS\Modules\Content\Support;

use CMS\Core\Database\Connection;
use CMS\Modules\Content\Entity\Content;
use CMS\Modules\Editorial\Contracts\ScheduledContentFinderInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Answers the Editorial module's "what is due for publication?" question.
 *
 * Lives in Content because Content owns the `contents` table and its
 * `scheduled_at` column. The scheduler then never queries a foreign table — the
 * module that owns the data performs the query.
 */
final class ContentScheduledFinder implements ScheduledContentFinderInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function due(?\DateTimeImmutable $now = null, ?int $siteId = null, int $limit = 50): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // Bounded, indexed by (site_id, status, scheduled_at).
        $sql = 'SELECT id, site_id FROM contents '
            . 'WHERE status = :status AND scheduled_at IS NOT NULL AND scheduled_at <= :now';

        $bindings = [
            'status' => Content::STATUS_SCHEDULED,
            'now' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];

        if ($siteId !== null) {
            $sql .= ' AND site_id = :site_id';
            $bindings['site_id'] = $siteId;
        }

        $sql .= ' ORDER BY scheduled_at ASC, id ASC LIMIT ' . max(1, $limit);

        $rows = $this->connection->select($sql, $bindings);

        $due = [];

        foreach ($rows as $row) {
            $due[(int) $row['id']] = (int) $row['site_id'];
        }

        return $due;
    }
}
