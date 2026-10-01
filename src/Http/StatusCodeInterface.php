<?php

declare(strict_types=1);

namespace Errata\Http;

/**
 * Implemented by exceptions that carry their own HTTP status code.
 *
 * Values outside 400..599 are ignored in favour of 500.
 *
 * @api
 */
interface StatusCodeInterface
{
    public function getStatusCode(): int;
}
