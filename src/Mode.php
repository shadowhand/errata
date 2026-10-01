<?php

declare(strict_types=1);

namespace Snafu;

use function filter_var;
use function getenv;

use const FILTER_VALIDATE_BOOLEAN;

/**
 * The document mode the handler renders.
 *
 * `Full` carries the message, origin, source windows, trace, and causes;
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
     * Reads `APP_ENV`, then `APP_DEBUG`. Unrecognised or absent values
     * fall back to Minimal, because a mode that leaks internals must
     * never be selected by accident.
     */
    public static function fromEnv(): self
    {
        $appEnv = getenv('APP_ENV');

        if ($appEnv === 'dev' || $appEnv === 'development' || $appEnv === 'local') {
            return self::Full;
        }

        return filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN) ? self::Full : self::Minimal;
    }
}
