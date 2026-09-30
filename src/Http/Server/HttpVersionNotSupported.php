<?php

declare(strict_types=1);

namespace Errata\Http\Server;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class HttpVersionNotSupported extends HttpProblem
{
    public ?string $title {
        get {
            return 'HTTP Version Not Supported';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 505;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
