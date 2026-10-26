<?php

declare(strict_types=1);

namespace CMS\Modules\Identity\Entity;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Immutable user entity.
 *
 * Password hashes are treated as opaque: this object never knows or exposes
 * plaintext and never compares passwords itself.
 */
final class User
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    public function __construct(
        public readonly ?int $id,
        public readonly int $siteId,
        public readonly string $username,
        public readonly string $email,
        public readonly string $passwordHash,
        public readonly string $displayName,
        public readonly string $status,
        public readonly ?DateTimeImmutable $lastLoginAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
        if ($siteId <= 0) {
            throw new InvalidArgumentException('User siteId must be positive.');
        }

        if ($username === '') {
            throw new InvalidArgumentException('User username must not be empty.');
        }

        if ($passwordHash === '') {
            throw new InvalidArgumentException('User passwordHash must not be empty.');
        }

        if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_DISABLED], true)) {
            throw new InvalidArgumentException('Unknown user status: ' . $status);
        }
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isDisabled(): bool
    {
        return $this->status === self::STATUS_DISABLED;
    }

    public function withLastLoginAt(?DateTimeImmutable $lastLoginAt): self
    {
        return new self(
            $this->id,
            $this->siteId,
            $this->username,
            $this->email,
            $this->passwordHash,
            $this->displayName,
            $this->status,
            $lastLoginAt,
            $this->createdAt,
            $this->updatedAt,
        );
    }

    public function withPasswordHash(string $passwordHash, DateTimeImmutable $updatedAt): self
    {
        return new self(
            $this->id,
            $this->siteId,
            $this->username,
            $this->email,
            $passwordHash,
            $this->displayName,
            $this->status,
            $this->lastLoginAt,
            $this->createdAt,
            $updatedAt,
        );
    }
}
