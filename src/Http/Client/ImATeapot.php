<?php

declare(strict_types=1);

namespace Errata\Http\Client;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class ImATeapot extends HttpProblem
{
    public ?string $title {
        get {
            return 'I\'m a Teapot';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 418;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
