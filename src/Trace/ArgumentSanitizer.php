<?php

declare(strict_types=1);

namespace Snafu\Trace;

use Closure;
use SensitiveParameterValue;
use Snafu\Document\SanitizedMap;
use Snafu\Document\SanitizedObject;
use SplObjectStorage;
use UnitEnum;

use function array_is_list;
use function array_slice;
use function count;
use function get_object_vars;
use function get_resource_type;
use function is_array;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_nan;
use function is_object;
use function is_resource;
use function is_string;
use function sprintf;
use function strlen;
use function substr;

/**
 * Converts an arbitrary argument value into something JSON-encodable.
 *
 * This is a total function: it must not throw, must not invoke user code
 * (`__toString`, `__debugInfo`, `JsonSerializable`), and must not loop
 * forever. It runs while an exception is being reported, so failing here
 * would destroy the report. It must not produce a value `json_encode()`
 * refuses.
 *
 * @internal
 */
final class ArgumentSanitizer
{
    private const int MAX_DEPTH = 5;

    private const int MAX_ITEMS = 50;

    private const int MAX_STRING_LENGTH = 500;

    /**
     * Objects on the current recursion path, used to stop cycles.
     *
     * @var SplObjectStorage<object, null>
     */
    private SplObjectStorage $processing;

    public function __construct()
    {
        /** @var SplObjectStorage<object, null> $processing */
        $processing = new SplObjectStorage();

        $this->processing = $processing;
    }

    public function sanitize(mixed $value): mixed
    {
        return $this->sanitizeValue($value, 0);
    }

    private function sanitizeValue(mixed $value, int $depth): mixed
    {
        return match (true) {
            $depth >= self::MAX_DEPTH => '*depth limit*',
            $value === null, is_bool($value), is_int($value) => $value,
            is_float($value) => self::float($value),
            is_string($value) => $this->sanitizeString($value),
            is_array($value) => $this->sanitizeArray($value, $depth),
            $value instanceof SensitiveParameterValue => '*redacted*',
            $value instanceof Closure => 'Closure',
            $value instanceof UnitEnum => $value::class . '::' . $value->name,
            is_object($value) => $this->sanitizeObject($value, $depth),
            is_resource($value) => 'resource(' . get_resource_type($value) . ')',
            default => 'resource(closed)',
        };
    }

    /**
     * `json_encode()` refuses INF and NAN, and a document that cannot be
     * encoded is a document that cannot be reported, so non-finite
     * floats become their names.
     */
    private static function float(float $value): float|string
    {
        if (is_finite($value)) {
            return $value;
        }

        if (is_nan($value)) {
            return 'NAN';
        }

        return $value > 0.0 ? 'INF' : '-INF';
    }

    private function sanitizeString(string $value): string
    {
        if (strlen($value) <= self::MAX_STRING_LENGTH) {
            return $value;
        }

        return substr(string: $value, offset: 0, length: self::MAX_STRING_LENGTH) . '...';
    }

    /**
     * A list stays a list; anything else becomes a SanitizedMap.
     *
     * @param array<array-key, mixed> $value
     *
     * @return list<mixed>|SanitizedMap
     */
    private function sanitizeArray(array $value, int $depth): array|SanitizedMap
    {
        $total = count($value);
        $slice = array_slice(array: $value, offset: 0, length: self::MAX_ITEMS, preserve_keys: true);

        if (array_is_list($value)) {
            $items = [];

            /** @var mixed $item */
            foreach ($slice as $item) {
                $items[] = $this->sanitizeValue($item, $depth + 1);
            }

            if ($total > self::MAX_ITEMS) {
                $items[] = sprintf('... (%d more items)', $total - self::MAX_ITEMS);
            }

            return $items;
        }

        return new SanitizedMap($this->sanitizeEntries($slice, $depth, $total));
    }

    /**
     * Sanitizes a capped slice of entries, appending the truncation marker
     * when the source held more items than the cap.
     *
     * @param array<array-key, mixed> $slice
     *
     * @return array<string, mixed>
     */
    private function sanitizeEntries(array $slice, int $depth, int $total): array
    {
        $entries = [];

        /** @var mixed $item */
        foreach ($slice as $key => $item) {
            $entries[(string) $key] = $this->sanitizeValue($item, $depth + 1);
        }

        if ($total > self::MAX_ITEMS) {
            $entries['*truncated*'] = sprintf('%d more items', $total - self::MAX_ITEMS);
        }

        return $entries;
    }

    private function sanitizeObject(object $value, int $depth): SanitizedObject
    {
        if ($this->processing->offsetExists($value)) {
            return new SanitizedObject($value::class, []);
        }

        $this->processing->offsetSet($value, null);

        try {
            /** @var array<string, mixed> $all */
            $all = get_object_vars($value);
            $slice = array_slice(array: $all, offset: 0, length: self::MAX_ITEMS, preserve_keys: true);

            return new SanitizedObject($value::class, $this->sanitizeEntries($slice, $depth, count($all)));
        } finally {
            $this->processing->offsetUnset($value);
        }
    }
}
