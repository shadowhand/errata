<?php

declare(strict_types=1);

namespace Errata\Http\Client;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class ExpectationFailed extends HttpProblem
{
    public ?string $title {
        get {
            return 'Expectation Failed';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 417;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
