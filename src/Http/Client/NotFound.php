<?php

declare(strict_types=1);

namespace Errata\Http\Client;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class NotFound extends HttpProblem
{
    public ?string $title {
        get {
            return 'Not Found';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 404;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
