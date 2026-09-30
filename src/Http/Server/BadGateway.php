<?php

declare(strict_types=1);

namespace Errata\Http\Server;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class BadGateway extends HttpProblem
{
    public ?string $title {
        get {
            return 'Bad Gateway';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 502;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
