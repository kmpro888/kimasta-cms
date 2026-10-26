<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Auth;

use CMS\Shared\Contracts\ActorInterface;
use InvalidArgumentException;

/**
 * Immutable acting principal.
 *
 * Capabilities are resolved ONCE, when the actor is built, and held as a set.
 * An authorisation check is therefore a hash-free in_array on a short list, not
 * a query — which matters because a single admin page performs dozens of them.
 *
 * The anonymous actor is represented by a null user id with an empty capability
 * set, and `can()` always returns false for it. There is no "everyone is an
 * administrator" branch to get wrong later.
 */
final class Actor implements ActorInterface
{
    /** @var array<string, true> */
    private readonly array $capabilitySet;

    /**
     * @param list<string> $capabilities
     */
    public function __construct(
        private readonly ?int $userId,
        private readonly int $siteId,
        array $capabilities = [],
    ) {
        if ($siteId <= 0) {
            throw new InvalidArgumentException('Actor siteId must be positive.');
        }

        if ($userId !== null && $userId <= 0) {
            throw new InvalidArgumentException('Actor userId must be positive when present.');
        }

        $set = [];

        foreach ($capabilities as $capability) {
            if ($capability !== '') {
                $set[$capability] = true;
            }
        }

        $this->capabilitySet = $set;
    }

    /**
     * Anonymous actor bound to a resolved site.
     */
    public static function anonymous(int $siteId): self
    {
        return new self(null, $siteId, []);
    }

    public function id(): ?int
    {
        return $this->userId;
    }

    public function siteId(): int
    {
        return $this->siteId;
    }

    public function authenticated(): bool
    {
        return $this->userId !== null;
    }

    public function can(string $capability, mixed $resource = null): bool
    {
        // An anonymous actor can never hold a capability, regardless of what a
        // caller passes in.
        if ($this->userId === null || $capability === '') {
            return false;
        }

        // Resource-level rules are owned by the modules that define the
        // resource; this layer answers the site-wide capability question only.
        return isset($this->capabilitySet[$capability]);
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return array_keys($this->capabilitySet);
    }

    public function has(string $capability): bool
    {
        return isset($this->capabilitySet[$capability]);
    }
}
