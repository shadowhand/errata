<?php

declare(strict_types=1);

namespace Errata\Tests\Extension;

use Errata\Extension\Timestamp;
use Errata\Problem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Timestamp::class)]
final class TimestampTest extends TestCase
{
    public function testItExtendsTheProblemWithAnRfc3339Timestamp(): void
    {
        $problem = new Problem();

        new Timestamp()->extend($problem, new RuntimeException());

        /** @var string|null $timestamp */
        $timestamp = $problem->extensions['timestamp'] ?? null;

        $this->assertIsString($timestamp);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{2}:\d{2}$/',
            $timestamp,
        );
    }

    public function testItUsesACustomFormatAndKey(): void
    {
        $problem = new Problem();

        new Timestamp(key: 'time', format: 'Y')->extend($problem, new RuntimeException());

        /** @var string|null $time */
        $time = $problem->extensions['time'] ?? null;

        $this->assertIsString($time);
        $this->assertMatchesRegularExpression('/^\d{4}$/', $time);
    }
}
