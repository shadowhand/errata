<?php

declare(strict_types=1);

namespace Errata\Http\Client;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class RangeNotSatisfiable extends HttpProblem
{
    public ?string $title {
        get {
            return 'Range Not Satisfiable';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 416;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
