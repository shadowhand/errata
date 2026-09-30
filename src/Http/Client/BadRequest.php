<?php

declare(strict_types=1);

namespace Errata\Http\Client;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class BadRequest extends HttpProblem
{
    public ?string $title {
        get {
            return 'Bad Request';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 400;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
