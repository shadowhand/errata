<?php

declare(strict_types=1);

namespace Errata\Http\Server;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class GatewayTimeout extends HttpProblem
{
    public ?string $title {
        get {
            return 'Gateway Timeout';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 504;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
