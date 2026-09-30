<?php

declare(strict_types=1);

namespace Errata\Http\Server;

use Errata\Http\HttpProblem;
use Errata\ProblemException;

/**
 * @api
 */
final class InsufficientStorage extends HttpProblem
{
    public ?string $title {
        get {
            return 'Insufficient Storage';
        }
        set {
            throw ProblemException::immutableTitle();
        }
    }

    public ?int $status {
        get {
            return 507;
        }
        set {
            throw ProblemException::immutableStatus();
        }
    }
}
