<?php

declare(strict_types=1);

namespace Errata\Http\Client;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class FailedDependency extends HttpProblem
{
    public ?string $title {
        get {
            return 'Failed Dependency';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 424;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
