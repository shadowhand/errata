<?php

declare(strict_types=1);

namespace Errata\Http\Server;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class InternalServerError extends HttpProblem
{
    public ?string $title {
        get {
            return 'Internal Server Error';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 500;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
