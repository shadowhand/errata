<?php

declare(strict_types=1);

namespace Errata\Tests;

use Errata\Mode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\WithEnvironmentVariable;
use PHPUnit\Framework\TestCase;

#[CoversClass(Mode::class)]
final class ModeTest extends TestCase
{
    #[WithEnvironmentVariable('APP_ENV', null)]
    #[WithEnvironmentVariable('APP_DEBUG', null)]
    public function testNothingSetIsMinimal(): void
    {
        $this->assertSame(Mode::Minimal, Mode::fromEnv());
    }

    #[WithEnvironmentVariable('APP_ENV', 'dev')]
    #[WithEnvironmentVariable('APP_DEBUG', null)]
    public function testAppEnvDevIsFull(): void
    {
        $this->assertSame(Mode::Full, Mode::fromEnv());
    }

    #[WithEnvironmentVariable('APP_ENV', 'LOCAL')]
    #[WithEnvironmentVariable('APP_DEBUG', null)]
    public function testAppEnvIsCaseSensitive(): void
    {
        $this->assertSame(Mode::Minimal, Mode::fromEnv());
    }

    #[WithEnvironmentVariable('APP_ENV', 'test')]
    #[WithEnvironmentVariable('APP_DEBUG', null)]
    public function testAppEnvTestIsMinimal(): void
    {
        $this->assertSame(Mode::Minimal, Mode::fromEnv());
    }

    #[WithEnvironmentVariable('APP_ENV', 'production')]
    #[WithEnvironmentVariable('APP_DEBUG', '1')]
    public function testAppDebugOneIsFull(): void
    {
        $this->assertSame(Mode::Full, Mode::fromEnv());
    }

    #[WithEnvironmentVariable('APP_ENV', 'production')]
    #[WithEnvironmentVariable('APP_DEBUG', 'TRUE')]
    public function testAppDebugIsCaseInsensitive(): void
    {
        $this->assertSame(Mode::Full, Mode::fromEnv());
    }

    #[WithEnvironmentVariable('APP_ENV', 'development')]
    #[WithEnvironmentVariable('APP_DEBUG', null)]
    public function testAppEnvDevelopmentIsFull(): void
    {
        $this->assertSame(Mode::Full, Mode::fromEnv());
    }

    #[WithEnvironmentVariable('APP_ENV', '')]
    #[WithEnvironmentVariable('APP_DEBUG', 'on')]
    public function testEmptyAppEnvFallsThroughToAppDebug(): void
    {
        $this->assertSame(Mode::Full, Mode::fromEnv());
    }

    #[WithEnvironmentVariable('APP_ENV', 'production')]
    #[WithEnvironmentVariable('APP_DEBUG', '0')]
    public function testFalsyAppDebugIsMinimal(): void
    {
        $this->assertSame(Mode::Minimal, Mode::fromEnv());
    }

    #[WithEnvironmentVariable('APP_ENV', 'production')]
    #[WithEnvironmentVariable('APP_DEBUG', 'nonsense')]
    public function testUnrecognisedAppDebugIsMinimal(): void
    {
        $this->assertSame(Mode::Minimal, Mode::fromEnv());
    }
}
