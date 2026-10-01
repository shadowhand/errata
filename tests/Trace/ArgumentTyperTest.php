<?php

declare(strict_types=1);

namespace Errata\Tests\Trace;

use Errata\Mode;
use Errata\Trace\ArgumentTyper;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SensitiveParameterValue;
use stdClass;

use function fclose;
use function fopen;

#[CoversClass(ArgumentTyper::class)]
final class ArgumentTyperTest extends TestCase
{
    private ArgumentTyper $typer;

    #[Override]
    protected function setUp(): void
    {
        $this->typer = new ArgumentTyper();
    }

    public function testItNamesScalars(): void
    {
        $this->assertSame('null', $this->typer->type(null));
        $this->assertSame('bool', $this->typer->type(true));
        $this->assertSame('int', $this->typer->type(7));
        $this->assertSame('float', $this->typer->type(1.5));
        $this->assertSame('string', $this->typer->type('plain'));
    }

    public function testItSplitsArraysIntoVecAndDict(): void
    {
        $this->assertSame('vec', $this->typer->type([1, 2]));
        $this->assertSame('vec', $this->typer->type([]));
        $this->assertSame('dict', $this->typer->type(['key' => 'value']));
        $this->assertSame('dict', $this->typer->type([0 => 'a', 2 => 'b']));
    }

    public function testItNamesObjectsClosuresAndEnums(): void
    {
        $this->assertSame(stdClass::class, $this->typer->type(new stdClass()));
        $this->assertSame('Closure', $this->typer->type(static fn(): int => 1));
        $this->assertSame(Mode::class, $this->typer->type(Mode::Full));
    }

    public function testItNamesResources(): void
    {
        $handle = fopen(filename: 'php://memory', mode: 'r');

        try {
            $this->assertSame('resource (stream)', $this->typer->type($handle));
        } finally {
            fclose($handle);
        }

        $this->assertSame('resource (closed)', $this->typer->type($handle));
    }

    public function testItUnwrapsSensitiveParametersToTheProtectedType(): void
    {
        $this->assertSame('string', $this->typer->type(new SensitiveParameterValue('hunter2')));
        $this->assertSame('vec', $this->typer->type(new SensitiveParameterValue([1, 2])));
        $this->assertSame('dict', $this->typer->type(new SensitiveParameterValue(['a' => 1])));
    }
}
