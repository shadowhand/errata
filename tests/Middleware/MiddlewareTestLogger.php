<?php

declare(strict_types=1);

namespace Errata\Tests\Middleware;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;
use Throwable;

use function is_int;
use function is_string;

final class MiddlewareTestLogger extends AbstractLogger
{
    public int $calls = 0;

    public string $level = '';

    public string $message = '';

    public string $method = '';

    public string $path = '';

    public int $status = 0;

    public ?Throwable $exception = null;

    #[Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->calls++;
        $this->level = is_string($level) ? $level : '';
        $this->message = (string) $message;
        $this->method = self::stringFrom($context, 'method');
        $this->path = self::stringFrom($context, 'path');
        $this->status = self::intFrom($context, 'status');

        /** @var mixed $exception */
        $exception = $context['exception'] ?? null;

        $this->exception = $exception instanceof Throwable ? $exception : null;
    }

    /**
     * @param array<array-key, mixed> $context
     */
    private static function stringFrom(array $context, string $key): string
    {
        /** @var mixed $value */
        $value = $context[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<array-key, mixed> $context
     */
    private static function intFrom(array $context, string $key): int
    {
        /** @var mixed $value */
        $value = $context[$key] ?? null;

        return is_int($value) ? $value : 0;
    }
}
