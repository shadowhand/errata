<?php

declare(strict_types=1);

namespace Errata\Demo;

use Errata\Mode;
use RuntimeException;

require_once __DIR__ . '/bootstrap.php';

/**
 * @internal
 */
final class RecursiveNode
{
    public function __construct(
        public readonly string $name,
    ) {}

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

    $first->visit($second, 35);
});
