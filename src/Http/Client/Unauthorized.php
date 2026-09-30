<?php

declare(strict_types=1);

namespace Errata\Http\Client;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class Unauthorized extends HttpProblem
{
    public ?string $title {
        get {
            return 'Unauthorized';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 401;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
