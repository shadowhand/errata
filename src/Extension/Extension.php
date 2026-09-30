<?php

declare(strict_types=1);

namespace Errata\Extension;

use Errata\Problem;
use Throwable;

/**
 * @api
 */
interface Extension
{
    public function extend(Problem $problem, Throwable $throwable): void;
}
