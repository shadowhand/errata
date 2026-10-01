<?php

declare(strict_types=1);

namespace Errata\Tests\Middleware;

use Errata\Http\StatusCodeInterface;
use Override;
use RuntimeException;
use Throwable;

final class MiddlewareStatusFixture extends RuntimeException implements StatusCodeInterface
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('status fixture', 0, $previous);
    }

    /**
     * A 422 failure whose cause chain is too deep for `json_encode()`'s
     * default depth, so the problem document cannot be encoded.
     */
    public static function unencodable(): self
    {
        $previous = new RuntimeException('link-0');

        for ($i = 1; $i < 600; $i++) {
            $previous = new RuntimeException('link-' . $i, 0, $previous);
        }

        return new self($previous);
    }

    #[Override]
    public function getStatusCode(): int
    {
        return 422;
    }
}
