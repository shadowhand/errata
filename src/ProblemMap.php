<?php

declare(strict_types=1);

namespace Errata;

use function class_implements;
use function class_parents;

/**
 * @api
 * @template T of object
 * @template Tk of class-string
 * @template Tv of callable(T):Problem
 */
final readonly class ProblemMap
{
    public function __construct(
        /** @var array<Tk, Tv> */
        private array $map = [],
    ) {}

    /**
     * @param T $instance
     * @return Tv|null
     */
    public function get(object $instance): ?callable
    {
        $classes = [
            $instance::class,
            // @mago-expect analysis:invalid-array-element
            ...class_parents($instance),
            // @mago-expect analysis:invalid-array-element
            ...class_implements($instance),
        ];

        // @mago-expect analysis:mixed-assignment
        foreach ($classes as $class) {
            if ($this->map[$class] ?? null) {
                return $this->map[$class];
            }
        }

        return null;
    }
}
