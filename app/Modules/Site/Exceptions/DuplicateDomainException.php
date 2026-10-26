<?php

declare(strict_types=1);

namespace CMS\Modules\Site\Exceptions;

use CMS\Core\Http\HttpException;

/**
 * Raised when attempting to map a domain that is already mapped.
 *
 * site_domains.domain carries a global unique key, so one host can never
 * point at two tenants. Status 409 with the frozen CONFLICT envelope code.
 */
final class DuplicateDomainException extends HttpException
{
    public function __construct(string $message = 'Domain is already mapped to a site.', ?\Throwable $previous = null)
    {
        parent::__construct(409, $message, 'CONFLICT', [], $previous);
    }
}