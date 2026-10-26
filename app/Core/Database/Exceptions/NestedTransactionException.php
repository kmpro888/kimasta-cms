<?php

declare(strict_types=1);

namespace CMS\Core\Database\Exceptions;

use RuntimeException;

final class NestedTransactionException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Nested transactions are not supported. Use a single transaction boundary per unit of work.'
        );
    }
}