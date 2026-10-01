<?php

declare(strict_types=1);

namespace Snafu\Tests\Trace;

final class ArgumentSanitizerFixture
{
    public string $public = 'yes';

    private string $protected = 'no';

    private string $private = 'no';

    /**
     * Reads every property, so the fixture has no unused members.
     *
     * @return list<string>
     */
    public function values(): array
    {
        return [$this->public, $this->protected, $this->private];
    }
}
