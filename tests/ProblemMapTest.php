<?php

declare(strict_types=1);

namespace Errata\Tests;

use Errata\Problem;
use Errata\ProblemMap;
use Errata\Tests\Fixtures\NotificationException;
use Errata\Tests\Fixtures\Recoverable;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

#[CoversClass(ProblemMap::class)]
final class ProblemMapTest extends TestCase
{
    public function testReturnsNullForAnEmptyMap(): void
    {
        $map = new ProblemMap();

        $this->assertNull($map->get(new NotificationException()));
    }

    public function testReturnsNullWhenNoClassMatches(): void
    {
        $map = new ProblemMap([
            stdClass::class => static fn(object $_instance): Problem => new Problem(),
        ]);

        $this->assertNull($map->get(new NotificationException()));
    }

    public function testReturnsTheFactoryForTheExactClass(): void
    {
        $factory = static fn(NotificationException $_instance): Problem => new Problem();
        $map = new ProblemMap([NotificationException::class => $factory]);

        $this->assertSame($factory, $map->get(new NotificationException()));
    }

    public function testReturnsTheFactoryForAParentClass(): void
    {
        $factory = static fn(NotificationException $_instance): Problem => new Problem();
        $map = new ProblemMap([RuntimeException::class => $factory]);

        $this->assertSame($factory, $map->get(new NotificationException()));
    }

    public function testReturnsTheFactoryForAnInterface(): void
    {
        $factory = static fn(NotificationException $_instance): Problem => new Problem();
        $map = new ProblemMap([Recoverable::class => $factory]);

        $this->assertSame($factory, $map->get(new NotificationException()));
    }

    public function testPrefersTheExactClassOverAParent(): void
    {
        $exact = static fn(NotificationException $_instance): Problem => new Problem();
        $parent = static fn(NotificationException $_instance): Problem => new Problem();
        $map = new ProblemMap([
            NotificationException::class => $exact,
            RuntimeException::class => $parent,
        ]);

        $this->assertSame($exact, $map->get(new NotificationException()));
    }

    public function testPrefersTheExactClassOverAnInterface(): void
    {
        $exact = static fn(NotificationException $_instance): Problem => new Problem();
        $interface = static fn(NotificationException $_instance): Problem => new Problem();
        $map = new ProblemMap([
            NotificationException::class => $exact,
            Recoverable::class => $interface,
        ]);

        $this->assertSame($exact, $map->get(new NotificationException()));
    }

    public function testPrefersTheNearestParent(): void
    {
        $nearest = static fn(NotificationException $_instance): Problem => new Problem();
        $farther = static fn(NotificationException $_instance): Problem => new Problem();
        $map = new ProblemMap([
            RuntimeException::class => $nearest,
            Exception::class => $farther,
        ]);

        $this->assertSame($nearest, $map->get(new NotificationException()));
    }

    public function testPrefersAParentOverAnInterface(): void
    {
        $parent = static fn(NotificationException $_instance): Problem => new Problem();
        $interface = static fn(NotificationException $_instance): Problem => new Problem();
        $map = new ProblemMap([
            RuntimeException::class => $parent,
            Recoverable::class => $interface,
        ]);

        $this->assertSame($parent, $map->get(new NotificationException()));
    }

    public function testTheFactoryReceivesTheInstance(): void
    {
        $instance = new NotificationException('boom');
        $map = new ProblemMap([
            NotificationException::class => static fn(NotificationException $e): Problem => new Problem(
                detail: $e->getMessage(),
            ),
        ]);

        $factory = $map->get($instance);

        $this->assertNotNull($factory);
        $this->assertSame('boom', $factory($instance)->detail);
    }
}
