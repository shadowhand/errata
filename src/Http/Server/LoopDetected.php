<?php

declare(strict_types=1);

namespace Errata\Http\Server;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class LoopDetected extends HttpProblem
{
    public ?string $title {
        get {
            return 'Loop Detected';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 508;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
