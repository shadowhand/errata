<?php

declare(strict_types=1);

namespace Errata\Extension;

use Errata\Problem;
use Override;
use Throwable;

use function hash;
use function Psl\Filesystem\canonicalize;
use function Psl\Str\Byte\after;
use function Psl\Str\join;

use const Psl\Filesystem\SEPARATOR;

/**
 * @api
 */
final readonly class Fingerprint implements Extension
{
    /** @var non-empty-string|null */
    private ?string $appDir;

    /**
     * @param string|null $appDir
     */
    public function __construct(
        /** @var non-empty-string */
        private string $key = 'fingerprint',
        /** @var non-empty-string */
        private string $algo = 'xxh64',
        ?string $appDir = null,
    ) {
        $this->appDir = $appDir ? canonicalize($appDir) : null;
    }

    #[Override]
    public function extend(Problem $problem, Throwable $throwable): void
    {
        $file = $throwable->getFile();

        if ($this->appDir) {
            $file = after($file, $this->appDir . SEPARATOR) ?? $file;
        }

        $elements = [
            $throwable::class,
            (string) $throwable->getCode(),
            $file,
        ];

        $problem->extend($this->key, hash($this->algo, join($elements, glue: '|')));
    }
}
