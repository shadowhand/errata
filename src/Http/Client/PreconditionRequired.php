<?php

declare(strict_types=1);

namespace Errata\Http\Client;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class PreconditionRequired extends HttpProblem
{
    public ?string $title {
        get {
            return 'Precondition Required';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 428;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
