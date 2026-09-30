<?php

declare(strict_types=1);

namespace Errata\Http\Client;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class TooEarly extends HttpProblem
{
    public ?string $title {
        get {
            return 'Too Early';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 425;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
