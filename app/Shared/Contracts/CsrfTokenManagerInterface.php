<?php

declare(strict_types=1);

namespace CMS\Shared\Contracts;

/**
 * CSRF token lifecycle.
 *
 * FROZEN CONTRACT — implementation belongs to Agent 03.
 */
interface CsrfTokenManagerInterface
{
    public function token(): string;

    public function validate(string $token): bool;

    public function regenerate(): string;
}
