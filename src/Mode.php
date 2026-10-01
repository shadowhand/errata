<?php

declare(strict_types=1);

namespace Errata;

use function filter_var;
use function getenv;

use const FILTER_VALIDATE_BOOLEAN;

/**
 * The document mode the handler renders.
 *
 * `Full` carries the message, origin, source lines, trace, and causes;
 * `Minimal` carries the short exception class name and the code.
 *
 * @api
 */
enum Mode: string
{
    case Full = 'full';
    case Minimal = 'minimal';

    /**
     * Detects the mode from the process environment.
     *
     * Checks both APP_ENV and APP_DEBUG to determine the appropriate mode.
     */
    public static function fromEnv(): self
    {
        $appEnv = getenv('APP_ENV');

        if ($appEnv === 'dev' || $appEnv === 'development' || $appEnv === 'local') {
            return self::Full;
        }

        if (filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN)) {
            return self::Full;
        }

        return self::Minimal;
    }
}
