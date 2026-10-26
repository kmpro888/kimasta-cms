<?php

declare(strict_types=1);

namespace CMS\Modules\Site\Entity;

use CMS\Shared\Contracts\SiteContextInterface;
use DateTimeImmutable;

/**
 * Immutable tenant site record and the concrete SiteContext.
 *
 * A Site carries everything the frozen SiteContextInterface requires plus the
 * administrative fields (status, theme, timestamps) used by the site service.
 * Every field is typed and set exactly once through the constructor, so a
 * resolved context can never be mutated mid-request.
 */
final class Site implements SiteContextInterface
{
    public function __construct(
        private readonly int $siteId,
        private readonly string $siteSlug,
        private readonly string $siteName,
        private readonly string $hostName,
        private readonly string $locale,
        private readonly string $tz,
        private readonly string $siteBaseUrl,
        private readonly bool $active,
        private readonly ?string $theme = null,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    public function id(): int
    {
        return $this->siteId;
    }

    public function slug(): string
    {
        return $this->siteSlug;
    }

    public function name(): string
    {
        return $this->siteName;
    }

    public function host(): string
    {
        return $this->hostName;
    }

    public function defaultLocale(): string
    {
        return $this->locale;
    }

    public function timezone(): string
    {
        return $this->tz;
    }

    public function baseUrl(): string
    {
        return $this->siteBaseUrl;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function activeTheme(): ?string
    {
        return $this->theme;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Return a copy of this site bound to a different resolved host.
     *
     * The entity is immutable; a multi-domain site resolves to the same
     * site row with the request host substituted as context host.
     */
    public function withHost(string $host): self
    {
        return new self(
            siteId: $this->siteId,
            siteSlug: $this->siteSlug,
            siteName: $this->siteName,
            hostName: $host,
            locale: $this->locale,
            tz: $this->tz,
            siteBaseUrl: $this->siteBaseUrl,
            active: $this->active,
            theme: $this->theme,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
        );
    }
}
