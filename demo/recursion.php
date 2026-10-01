<?php

declare(strict_types=1);

namespace Snafu\Demo;

use RuntimeException;
use Snafu\Mode;

require_once __DIR__ . '/bootstrap.php';

/**
 * @internal
 */
final class RecursiveNode
{
    public self $next;

    public function __construct(
        public readonly string $name,
    ) {
        $this->next = $this;
    }

    public function visit(self $node, int $remaining): never
    {
        if ($remaining === 0) {
            throw new RuntimeException('The recursive demo reached its call limit.');
        }

        $node->visit($this, $remaining - 1);
    }
}

namespace\run_demo(Mode::Full, static function (): never {
    $first = new RecursiveNode('first');
    $second = new RecursiveNode('second');
    $first->next = $second;
    $second->next = $first;

    $first->visit($second, 35);
});
