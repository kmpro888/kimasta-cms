<?php

declare(strict_types=1);

namespace CMS\Core\Container\Exceptions;

use RuntimeException;

final class BindingNotFoundException extends RuntimeException
{
    public static function forAbstract(string $abstract): self
    {
        return new self(sprintf('No container binding registered for [%s].', $abstract));
    }

    public function __construct(string $abstract)
    {
        parent::__construct(sprintf('No container binding registered for [%s].', $abstract));
    }
}
