<?php

declare(strict_types=1);

namespace Errata\Trace;

use Errata\Document\Frame;
use Errata\Document\Trace;
use Errata\Path\PathRelativizer;

use function array_key_exists;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function is_array;
use function is_int;
use function is_string;
use function preg_replace_callback;

/**
 * Assembles the trace section from PHP's raw trace array.
 *
 * @internal
 */
final class TraceFactory
{
    /** Patterns for source paths embedded in PHP-generated backtrace names. */
    private const string CLOSURE_PATH_PATTERN = '/(?<=\\{closure:).+(?=:\\d+\\}$)/s';

    private const string ANONYMOUS_CLASS_PATH_PATTERN = '/(?<=\\x00).+(?=:\\d+\\$\\d+$)/s';

    public function __construct(
        private readonly PathRelativizer $relativizer,
        private readonly SourceContext $source,
        private readonly ArgumentTyper $types,
        private readonly int $traceLimit,
    ) {}

    /**
     * `Throwable::getTrace()` already lists the frame nearest the throw
     * first, so the first `$traceLimit` entries — the innermost frames —
     * are kept when the list is longer than that.
     *
     * @param list<array<string, mixed>> $trace
     */
    public function frames(array $trace): Trace
    {
        $truncated = count($trace) > $this->traceLimit;

        $frames = [];

        foreach (array_slice(array: $trace, offset: 0, length: $this->traceLimit) as $entry) {
            $frames[] = $this->frame($entry);
        }

        return new Trace($frames, $truncated);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function frame(array $entry): Frame
    {
        $file = $this->string($entry, 'file');
        $line = $this->int($entry, 'line');

        return new Frame(
            file: $file === null ? null : $this->relativizer->relativize($file),
            line: $line,
            function: $this->functionName($entry),
            class: $this->className($entry),
            type: $this->string($entry, 'type'),
            args: $this->args($entry),
            source: $file === null || $line === null ? null : $this->source->line($file, $line),
        );
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function functionName(array $entry): ?string
    {
        $function = $this->string($entry, 'function');

        if ($function === null) {
            return null;
        }

        return $this->relativizeEmbeddedPath($function, self::CLOSURE_PATH_PATTERN);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function className(array $entry): ?string
    {
        $class = $this->string($entry, 'class');

        if ($class === null) {
            return null;
        }

        return $this->relativizeEmbeddedPath($class, self::ANONYMOUS_CLASS_PATH_PATTERN);
    }

    private function relativizeEmbeddedPath(string $name, string $pattern): string
    {
        return preg_replace_callback($pattern, $this->relativizePath(...), $name) ?? $name;
    }

    /**
     * @param array<array-key, string> $matches
     */
    private function relativizePath(array $matches): string
    {
        return $this->relativizer->relativize($matches[0] ?? '');
    }

    /**
     * PHP omits the `args` key entirely when
     * `zend.exception_ignore_args=On`, so an absent or malformed key
     * normalizes to `null` and `Frame` omits the member; a frame PHP
     * reports with an empty argument list still carries `args: []`.
     *
     * @param array<string, mixed> $entry
     *
     * @return list<string>|null
     */
    private function args(array $entry): ?array
    {
        if (!array_key_exists('args', $entry) || !is_array($entry['args'])) {
            return null;
        }

        return array_map($this->types->type(...), array_values($entry['args']));
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function string(array $entry, string $key): ?string
    {
        if (!array_key_exists($key, $entry) || !is_string($entry[$key])) {
            return null;
        }

        return $entry[$key];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function int(array $entry, string $key): ?int
    {
        if (!array_key_exists($key, $entry) || !is_int($entry[$key])) {
            return null;
        }

        return $entry[$key];
    }
}
