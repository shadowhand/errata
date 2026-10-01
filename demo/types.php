<?php

declare(strict_types=1);

namespace Errata\Demo;

use Closure;
use Errata\Mode;
use RuntimeException;
use stdClass;

use function count;

require_once __DIR__ . '/bootstrap.php';

/**
 * @internal
 */
final class TypedCaller
{
    /**
     * @param list<int> $items
     * @param array<string, int> $counts
     */
    // @mago-ignore lint:excessive-parameter-list
    public function call(
        int $count,
        string $label,
        // @mago-ignore lint:no-boolean-flag-parameter
        bool $enabled,
        ?string $note,
        float $ratio,
        array $items,
        array $counts,
        stdClass $payload,
        Mode $mode,
        Closure $callback,
    ): never {
        $callback();

        $summary = $label . ':' . $count . ':' . count($items) . ':' . count($counts);
        $summary .= ':' . $payload::class . ':' . $mode->name;
        $summary .= ':' . ($enabled ? 'on' : 'off') . ':' . ($note ?? 'none') . ':' . $ratio;

        throw new RuntimeException('The typed-arguments demo always fails: ' . $summary);
    }
}

namespace\run_demo(Mode::Full, static function (): never {
    new TypedCaller()->call(
        count: 3,
        label: 'demo',
        enabled: true,
        note: null,
        ratio: 0.5,
        items: [1, 2, 3],
        counts: ['a' => 1, 'b' => 2],
        payload: new stdClass(),
        mode: Mode::Full,
        callback: static fn(): int => 1,
    );
});
