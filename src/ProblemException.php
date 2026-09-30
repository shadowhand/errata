<?php

declare(strict_types=1);

namespace Errata;

use LogicException;

/**
 * @internal
 */
final class ProblemException extends LogicException
{
    public static function extensionEmpty(): self
    {
        return new self('Extension name cannot be empty');
    }

    public static function extensionInvalid(string $name): self
    {
        return new self("Extension name cannot use reserved member '{$name}'");
    }

    public static function immutableTitle(): self
    {
        return new self('Cannot overwrite HTTP title');
    }

    public static function immutableStatus(): self
    {
        return new self('Cannot overwrite HTTP status');
    }

    public static function invalidStatus(): self
    {
        return new self('Status must be between 100 and 599');
    }
}
