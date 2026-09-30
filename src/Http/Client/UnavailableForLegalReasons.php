<?php

declare(strict_types=1);

namespace Errata\Http\Client;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class UnavailableForLegalReasons extends HttpProblem
{
    public ?string $title {
        get {
            return 'Unavailable For Legal Reasons';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 451;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
