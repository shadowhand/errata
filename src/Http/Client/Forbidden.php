<?php

declare(strict_types=1);

namespace Errata\Http\Client;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class Forbidden extends HttpProblem
{
    public ?string $title {
        get {
            return 'Forbidden';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 403;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
