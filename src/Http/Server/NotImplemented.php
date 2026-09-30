<?php

declare(strict_types=1);

namespace Errata\Http\Server;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class NotImplemented extends HttpProblem
{
    public ?string $title {
        get {
            return 'Not Implemented';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 501;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
