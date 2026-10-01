<?php

declare(strict_types=1);

namespace Errata\Tests\Trace;

use Override;
use RuntimeException;
use Stringable;

final class ArgumentSanitizerHostileFixture implements Stringable
{
    #[Override]
    public function __toString(): string
    {
        throw new RuntimeException('__toString must not be called');
    }
}
