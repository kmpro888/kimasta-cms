<?php

declare(strict_types=1);

namespace CMS\Modules\Api\Resources;

use CMS\Modules\Identity\Entity\User;

/**
 * Explicit user projection.
 *
 * `password_hash` is not listed, and that is the point: the field cannot leak from
 * this resource because nobody wrote it. A security test asserts the hash never
 * appears anywhere in a serialized user.
 */
final class UserResource implements ResourceInterface
{
    /**
     * @param User $subject
     *
     * @return array<string, mixed>
     */
    public function toArray(mixed $subject): array
    {
        if (!$subject instanceof User) {
            throw new \InvalidArgumentException('UserResource expects a User entity.');
        }

        return [
            'id' => $subject->id,
            'username' => $subject->username,
            'email' => $subject->email,
            'display_name' => $subject->displayName,
            'status' => $subject->status,
            'last_login_at' => $subject->lastLoginAt?->format(DATE_ATOM),
            'created_at' => $subject->createdAt->format(DATE_ATOM),
        ];
    }
}
