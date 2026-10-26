<?php

declare(strict_types=1);

namespace CMS\Modules\Taxonomy\Exceptions;

use InvalidArgumentException;

/**
 * Raised when an unregistered taxonomy is requested.
 */
final class UnknownTaxonomyException extends InvalidArgumentException
{
    public function __construct(string $name)
    {
        parent::__construct('Unknown taxonomy: ' . $name);
    }
}
