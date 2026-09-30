<?php

declare(strict_types=1);

namespace Errata\Extension;

use ArrayIterator;
use Errata\Problem;
use IteratorAggregate;
use Override;
use Throwable;

use function Psl\Vec\values;

/**
 * @api
 * @implements IteratorAggregate<int, Extension>
 */
final readonly class ExtensionList implements Extension, IteratorAggregate
{
    /** @var list<Extension> */
    private array $items;

    public function __construct(Extension ...$items)
    {
        $this->items = values($items);
    }

    #[Override]
    public function extend(Problem $problem, Throwable $throwable): void
    {
        foreach ($this->items as $item) {
            $item->extend($problem, $throwable);
        }
    }

    /**
     * @return ArrayIterator<int, Extension>
     */
    #[Override]
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }
}
