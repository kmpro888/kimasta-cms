<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Entity;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Immutable role entity.
 *
 * Roles are site-scoped: the same slug may exist on two different sites.
 * System roles are seeded and must not be deleted by tenant requests.
 */
final class Role
{
    public const ADMINISTRATOR = 'administrator';
    public const EDITOR = 'editor';
    public const REVIEWER = 'reviewer';
    public const AUTHOR = 'author';
    public const CONTRIBUTOR = 'contributor';
    public const SUBSCRIBER = 'subscriber';

    /** @return list<string> */
    public static function systemSlugs(): array
    {
        return [
            self::ADMINISTRATOR,
            self::EDITOR,
            self::REVIEWER,
            self::AUTHOR,
            self::CONTRIBUTOR,
            self::SUBSCRIBER,
        ];
    }

    public function __construct(
        public readonly ?int $id,
        public readonly int $siteId,
        public readonly string $name,
        public readonly string $slug,
        public readonly bool $isSystem,
        public readonly DateTimeImmutable $createdAt,
    ) {
        if ($siteId <= 0) {
            throw new InvalidArgumentException('Role siteId must be positive.');
        }

        if ($slug === '') {
            throw new InvalidArgumentException('Role slug must not be empty.');
        }

        if ($name === '') {
            throw new InvalidArgumentException('Role name must not be empty.');
        }
    }
}
