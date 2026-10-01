<?php

declare(strict_types=1);

namespace Snafu;

use Snafu\Document\Problem;
use Throwable;

/**
 * Maps a throwable to a problem document.
 *
 * The middleware depends on this seam rather than on the final
 * `ExceptionHandler`, so its handler-failure guard is reachable in tests.
 *
 * @api
 */
interface ExceptionHandlerInterface
{
    public function handle(Throwable $exception): Problem;
}
