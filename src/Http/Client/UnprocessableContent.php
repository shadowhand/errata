<?php

declare(strict_types=1);

namespace Errata\Http\Client;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class UnprocessableContent extends HttpProblem
{
    public ?string $title {
        get {
            return 'Unprocessable Content';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 422;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
