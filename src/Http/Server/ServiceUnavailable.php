<?php

declare(strict_types=1);

namespace Errata\Http\Server;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class ServiceUnavailable extends HttpProblem
{
    public ?string $title {
        get {
            return 'Service Unavailable';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 503;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
