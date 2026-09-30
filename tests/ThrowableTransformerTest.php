<?php

declare(strict_types=1);

namespace Errata\Tests;

use Closure;
use Errata\Extension\ExtensionList;
use Errata\Extension\Message;
use Errata\Http\Server\InternalServerError;
use Errata\Problem;
use Errata\ProblemMap;
use Errata\ThrowableTransformer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

#[CoversClass(ThrowableTransformer::class)]
final class ThrowableTransformerTest extends TestCase
{
    public function testItReturnsAnInternalServerErrorByDefault(): void
    {
        $problem = new ThrowableTransformer()->transform(new RuntimeException('boom'));

        $this->assertInstanceOf(InternalServerError::class, $problem);
        $this->assertSame('Internal Server Error', $problem->title);
        $this->assertSame(500, $problem->status);
    }

    public function testItUsesTheMappedFactory(): void
    {
        $factory = static fn(object $e): Problem => new Problem(detail: $e::class);
        $transformer = new ThrowableTransformer(self::map(RuntimeException::class, $factory));

        $problem = $transformer->transform(new RuntimeException('boom'));

        $this->assertNotInstanceOf(InternalServerError::class, $problem);
        $this->assertSame(RuntimeException::class, $problem->detail);
    }

    public function testItFallsBackWhenNoFactoryMatches(): void
    {
        $factory = static fn(object $_e): Problem => new Problem();
        $transformer = new ThrowableTransformer(self::map(stdClass::class, $factory));

        $problem = $transformer->transform(new RuntimeException('boom'));

        $this->assertInstanceOf(InternalServerError::class, $problem);
    }

    public function testItAppliesExtensionsToTheProblem(): void
    {
        $transformer = new ThrowableTransformer(extensionList: new ExtensionList(new Message()));

        $problem = $transformer->transform(new RuntimeException('boom'));

        $this->assertSame('boom', $problem->extensions['message'] ?? null);
    }

    public function testItAppliesExtensionsToTheMappedProblem(): void
    {
        $factory = static fn(object $_e): Problem => new Problem();
        $transformer = new ThrowableTransformer(
            self::map(RuntimeException::class, $factory),
            new ExtensionList(new Message()),
        );

        $problem = $transformer->transform(new RuntimeException('boom'));

        $this->assertSame('boom', $problem->extensions['message'] ?? null);
    }

    /**
     * @param class-string $class
     * @param Closure(object):Problem $factory
     * @return ProblemMap<object, class-string, Closure(object):Problem>
     */
    private static function map(string $class, Closure $factory): ProblemMap
    {
        return new ProblemMap([$class => $factory]);
    }
}
