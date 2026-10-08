<?php

declare(strict_types=1);

namespace Errata\Tests\Fixtures;

use Errata\Problem;

/**
 * @internal
 */
final class NotificationProblemFactory
{
    public function __invoke(NotificationException $throwable): Problem
    {
        return new Problem(detail: $throwable->getMessage());
    }
}
