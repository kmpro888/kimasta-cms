<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Entity;

use InvalidArgumentException;

/**
 * Immutable capability entity.
 *
 * Capabilities are global (not site-scoped): they describe what an actor is
 * allowed to do, while role_capabilities decides which role grants them and
 * user_roles decides who holds which role on a given site.
 *
 * The database column is `capability_key`; we expose it as `key` and also
 * accept the schema wording in the constructor argument name for clarity.
 */
final class Capability
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $key,
        public readonly ?string $description = null,
    ) {
        if ($key === '') {
            throw new InvalidArgumentException('Capability key must not be empty.');
        }
    }
}
