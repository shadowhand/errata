<?php

declare(strict_types=1);

namespace Errata\Tests;

use Errata\Problem;
use Errata\ProblemException;
use Error;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psl\Json\Exception\EncodeException;
use stdClass;

use function fopen;
use function get_object_vars;
use function Psl\Json\decode;
use function Psl\Json\encode;

#[CoversClass(Problem::class)]
#[CoversClass(ProblemException::class)]
final class ProblemTest extends TestCase
{
    public function testConstructsWithAllMembersNull(): void
    {
        $problem = new Problem();

        $this->assertNull($problem->type);
        $this->assertNull($problem->title);
        $this->assertNull($problem->detail);
        $this->assertNull($problem->status);
        $this->assertNull($problem->instance);
        $this->assertSame([], $problem->extensions);
        $this->assertSame([], $problem->toArray());
    }

    public function testConstructsWithEveryMember(): void
    {
        $problem = new Problem(
            type: 'https://example.com/faq/order-quantity-minimums',
            title: 'Minimum Quantity',
            detail: 'The minimum order quantity for this product is 100 units.',
            status: 400,
            instance: '/orders/1234',
        );

        $this->assertSame('https://example.com/faq/order-quantity-minimums', $problem->type);
        $this->assertSame('Minimum Quantity', $problem->title);
        $this->assertSame('The minimum order quantity for this product is 100 units.', $problem->detail);
        $this->assertSame(400, $problem->status);
        $this->assertSame('/orders/1234', $problem->instance);
        $this->assertSame(
            [
                'type' => 'https://example.com/faq/order-quantity-minimums',
                'title' => 'Minimum Quantity',
                'detail' => 'The minimum order quantity for this product is 100 units.',
                'status' => 400,
                'instance' => '/orders/1234',
            ],
            $problem->toArray(),
        );
    }

    public function testEmptyStringsAndZeroNormalizeToNull(): void
    {
        $problem = new Problem(type: '', title: '', detail: '', status: 0, instance: '');

        $this->assertNull($problem->type);
        $this->assertNull($problem->title);
        $this->assertNull($problem->detail);
        $this->assertNull($problem->status);
        $this->assertNull($problem->instance);
        $this->assertSame([], $problem->toArray());
    }

    public function testMembersAreMutable(): void
    {
        $problem = new Problem();

        $problem->type = 'https://example.com/x';
        $problem->title = 'Changed';
        $problem->detail = 'Detail';
        $problem->status = 500;
        $problem->instance = '/x';

        $this->assertSame('https://example.com/x', $problem->type);
        $this->assertSame('Changed', $problem->title);
        $this->assertSame('Detail', $problem->detail);
        $this->assertSame(500, $problem->status);
        $this->assertSame('/x', $problem->instance);
    }

    public function testMembersCanBeCleared(): void
    {
        $problem = new Problem('https://example.com/x', 'Title', 'Detail', 500, '/x');

        $problem->type = null;
        $problem->title = null;
        $problem->detail = null;
        $problem->status = null;
        $problem->instance = null;

        $this->assertSame([], $problem->toArray());
    }

    #[DataProvider('outOfRangeStatuses')]
    public function testOutOfRangeStatusIsRejected(int $status): void
    {
        $this->expectException(ProblemException::class);
        $this->expectExceptionMessageIs('Status must be between 100 and 599');

        new Problem(status: $status);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function outOfRangeStatuses(): array
    {
        return [
            'negative' => [-1],
            'below range' => [99],
            'above range' => [600],
        ];
    }

    public function testExtendAddsAnExtensionAndReturnsTheSameInstance(): void
    {
        $problem = new Problem();

        $this->assertSame($problem, $problem->extend('refcode', 'MPQ.100'));
        $this->assertSame(['refcode' => 'MPQ.100'], $problem->extensions);
    }

    public function testExtendReplacesAnExistingExtension(): void
    {
        $problem = new Problem();
        $problem->extend('refcode', 'MPQ.100')->extend('refcode', 'MPQ.101');

        $this->assertSame(['refcode' => 'MPQ.101'], $problem->extensions);
    }

    public function testExtendRejectsAnEmptyName(): void
    {
        $this->expectException(ProblemException::class);
        $this->expectExceptionMessageIs('Extension name cannot be empty');

        new Problem()->extend('', 'value');
    }

    #[DataProvider('reservedNames')]
    public function testExtendRejectsReservedNames(string $name): void
    {
        $this->expectException(ProblemException::class);
        $this->expectExceptionMessageIs("Extension name cannot use reserved member '{$name}'");

        new Problem()->extend($name, 'value');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function reservedNames(): array
    {
        return [
            'type' => ['type'],
            'title' => ['title'],
            'detail' => ['detail'],
            'status' => ['status'],
            'instance' => ['instance'],
        ];
    }

    public function testExtensionsCannotBeMutatedFromOutside(): void
    {
        $problem = new Problem();

        $this->expectException(Error::class);

        // @mago-expect analysis:invalid-property-write
        $problem->extensions['refcode'] = 'MPQ.100';
    }

    public function testToArrayOmitsNullMembers(): void
    {
        $problem = new Problem(title: 'Not Found', status: 404);

        $this->assertSame(['title' => 'Not Found', 'status' => 404], $problem->toArray());
    }

    public function testExtensionsFollowTheStandardMembers(): void
    {
        $problem = new Problem(title: 'Not Found', status: 404);
        $problem->extend('refcode', 'MPQ.100');

        $this->assertSame(['title' => 'Not Found', 'status' => 404, 'refcode' => 'MPQ.100'], $problem->toArray());
    }

    public function testToArrayDropsExtensionsWithNullValues(): void
    {
        $problem = new Problem();
        $problem->extend('empty', null);

        $this->assertSame(['empty' => null], $problem->extensions);
        $this->assertSame([], $problem->toArray());
    }

    public function testJsonSerializeReturnsTheMembersAsAnObject(): void
    {
        $problem = new Problem(title: 'Not Found', status: 404);

        $serialized = $problem->jsonSerialize();

        $this->assertInstanceOf(stdClass::class, $serialized);
        $this->assertSame(['title' => 'Not Found', 'status' => 404], get_object_vars($serialized));
    }

    public function testJsonEncodeProducesTheProblemDocument(): void
    {
        $problem = new Problem(type: 'https://example.com/min', title: 'Minimum');

        $this->assertSame('{"type":"https://example.com/min","title":"Minimum"}', encode($problem));
    }

    public function testJsonEncodeRoundTripsToTheMembers(): void
    {
        $problem = new Problem('https://example.com/x', 'Ünïcode — 100 %', 'Detail', 400, '/x');
        $problem->extend('refcode', 'MPQ.100');

        $json = encode($problem);

        $this->assertSame($problem->toArray(), decode($json));
    }

    public function testJsonEncodeThrowsForUnencodableExtensions(): void
    {
        $problem = new Problem();
        $problem->extend('stream', fopen(filename: 'php://memory', mode: 'r'));

        $this->expectException(EncodeException::class);

        encode($problem);
    }
}
