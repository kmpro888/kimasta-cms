<?php

declare(strict_types=1);

namespace CMS\Core\Http;

final class RedirectResponse extends Response
{
    public function __construct(string $location, int $status = 302)
    {
        parent::__construct('', $status);

        $this->setHeader('Location', $location);
    }

    public function location(): ?string
    {
        return $this->header('Location');
    }
}