<?php

declare(strict_types=1);

namespace Errata\Http\Client;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class UpgradeRequired extends HttpProblem
{
    public ?string $title {
        get {
            return 'Upgrade Required';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 426;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
