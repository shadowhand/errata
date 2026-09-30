<?php

declare(strict_types=1);

namespace Errata\Http\Server;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class NetworkAuthenticationRequired extends HttpProblem
{
    public ?string $title {
        get {
            return 'Network Authentication Required';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 511;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
