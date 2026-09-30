<?php

declare(strict_types=1);

namespace Errata\Http\Client;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class MisdirectedRequest extends HttpProblem
{
    public ?string $title {
        get {
            return 'Misdirected Request';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 421;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
