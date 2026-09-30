<?php

declare(strict_types=1);

namespace Errata\Http\Client;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class UnsupportedMediaType extends HttpProblem
{
    public ?string $title {
        get {
            return 'Unsupported Media Type';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 415;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
