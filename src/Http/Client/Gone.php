<?php

declare(strict_types=1);

namespace Errata\Http\Client;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class Gone extends HttpProblem
{
    public ?string $title {
        get {
            return 'Gone';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 410;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
