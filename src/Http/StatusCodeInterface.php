<?php

declare(strict_types=1);

namespace Errata\Http;

/**
 * Optional interface for exceptions to define an HTTP status code.
 *
 * @api
 */
interface StatusCodeInterface
{
    public function getStatusCode(): int;
}
