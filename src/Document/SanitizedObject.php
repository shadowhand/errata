<?php

declare(strict_types=1);

namespace Snafu\Document;

use JsonSerializable;
use Override;

/**
 * A sanitized object argument: class name plus public properties.
 *
 * @api
 */
final readonly class SanitizedObject implements JsonSerializable
{
    public function __construct(
        public string $class,
        /** @var array<string, mixed> */
        public array $properties,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['@class' => $this->class, 'props' => $this->properties];
    }
}
