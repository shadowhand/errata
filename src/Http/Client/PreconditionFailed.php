<?php

declare(strict_types=1);

namespace Errata\Http\Client;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class PreconditionFailed extends HttpProblem
{
    public ?string $title {
        get {
            return 'Precondition Failed';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 412;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
