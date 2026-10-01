<?php

declare(strict_types=1);

namespace Snafu\Tests\Trace;

use stdClass;

/**
 * Builds an object with sixty public properties, so the sanitizer's item
 * cap is observable on the object branch.
 */
final class ArgumentSanitizerManyPropertiesFixture
{
    public static function withSixtyProperties(): stdClass
    {
        $properties = [];

        for ($index = 1; $index <= 60; $index++) {
            $properties['property' . $index] = $index;
        }

        return (object) $properties;
    }
}
