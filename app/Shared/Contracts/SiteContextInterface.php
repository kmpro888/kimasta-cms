<?php

declare(strict_types=1);

namespace CMS\Shared\Contracts;

/**
 * Resolved site context for the current request.
 *
 * FROZEN CONTRACT — implementers live in later modules (Agent 02).
 */
interface SiteContextInterface
{
    public function id(): int;

    public function slug(): string;

    public function name(): string;

    public function host(): string;

    public function defaultLocale(): string;

    public function timezone(): string;

    public function baseUrl(): string;

    public function isActive(): bool;
}
