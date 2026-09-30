<?php

declare(strict_types=1);

namespace Errata\Http\Client;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class ProxyAuthenticationRequired extends HttpProblem
{
    public ?string $title {
        get {
            return 'Proxy Authentication Required';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 407;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
