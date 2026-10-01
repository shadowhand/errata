<?php

declare(strict_types=1);

namespace Errata\Trace;

use SensitiveParameterValue;

use function array_is_list;
use function get_debug_type;
use function is_array;

/**
 * Names the type of a trace argument without exposing its value.
 *
 * @internal
 */
final class ArgumentTyper
{
    /**
     * Arrays are split into `vec` (a list) and `dict` (anything else);
     * every other value is named by `get_debug_type()`. A
     * `SensitiveParameterValue` is unwrapped so the trace reports the
     * type of the protected value without ever exposing the value.
     */
    public function type(mixed $value): string
    {
        if ($value instanceof SensitiveParameterValue) {
            return $this->type($value->getValue());
        }

        if (is_array($value)) {
            return array_is_list($value) ? 'vec' : 'dict';
        }

        return get_debug_type($value);
    }
}
