<?php

declare(strict_types=1);

namespace Errata\Tests\Trace;

use Errata\Document\SanitizedMap;
use Errata\Document\SanitizedObject;
use Errata\Trace\ArgumentSanitizer;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SensitiveParameterValue;
use stdClass;

use function acos;
use function array_slice;
use function count;
use function fclose;
use function fdiv;
use function fopen;
use function json_encode;
use function range;
use function str_repeat;
use function strlen;
use function substr_count;

#[CoversClass(ArgumentSanitizer::class)]
final class ArgumentSanitizerTest extends TestCase
{
    private ArgumentSanitizer $sanitizer;

    #[Override]
    protected function setUp(): void
    {
        $this->sanitizer = new ArgumentSanitizer();
    }

    public function testItPassesScalarsThroughUnchanged(): void
    {
        $this->assertNull($this->sanitizer->sanitize(null));
        $this->assertTrue($this->sanitizer->sanitize(true));
        $this->assertSame(7, $this->sanitizer->sanitize(7));
        $this->assertSame(1.5, $this->sanitizer->sanitize(1.5));
        $this->assertSame('short', $this->sanitizer->sanitize('short'));
    }

    public function testItNamesNonFiniteFloatsSoTheDocumentStaysEncodable(): void
    {
        $this->assertSame(1.5, $this->sanitizer->sanitize(1.5));
        $this->assertSame('INF', $this->sanitizer->sanitize(fdiv(num1: 1.0, num2: 0.0)));
        $this->assertSame('-INF', $this->sanitizer->sanitize(fdiv(num1: -1.0, num2: 0.0)));
        $this->assertSame('NAN', $this->sanitizer->sanitize(acos(2.0)));
    }

    public function testItTruncatesLongStrings(): void
    {
        /** @var string $sanitized */
        $sanitized = $this->sanitizer->sanitize(str_repeat(string: 'a', times: 501));

        $this->assertIsString($sanitized);
        $this->assertSame(503, strlen($sanitized));
        $this->assertStringEndsWith('...', $sanitized);
    }

    public function testItKeepsListsAsLists(): void
    {
        $this->assertSame([1, 'two'], $this->sanitizer->sanitize([1, 'two']));
    }

    public function testItTreatsNonSequentialKeysAsAMap(): void
    {
        /** @var SanitizedMap $sanitized */
        $sanitized = $this->sanitizer->sanitize([0 => 'a', 2 => 'b']);

        $this->assertInstanceOf(SanitizedMap::class, $sanitized);
        $this->assertSame('{"0":"a","2":"b"}', json_encode($sanitized));
    }

    public function testItMarksATruncatedList(): void
    {
        $json = json_encode($this->sanitizer->sanitize(range(start: 1, end: 60)));

        $this->assertIsString($json);
        $this->assertStringEndsWith(',"... (10 more items)"]', $json);
        $this->assertSame(51, substr_count(haystack: $json, needle: ',') + 1);
    }

    public function testItMarksATruncatedMap(): void
    {
        $entries = [];

        for ($i = 0; $i < 60; $i++) {
            $entries['key' . $i] = $i;
        }

        /** @var SanitizedMap $sanitized */
        $sanitized = $this->sanitizer->sanitize($entries);

        $this->assertInstanceOf(SanitizedMap::class, $sanitized);
        $this->assertSame(51, count($sanitized->entries));
        $this->assertSame(
            ['*truncated*' => '10 more items'],
            array_slice(array: $sanitized->entries, offset: -1, length: null, preserve_keys: true),
        );
    }

    public function testItStopsAtTheDepthLimit(): void
    {
        $nested = ['end'];

        for ($i = 0; $i < 10; $i++) {
            $nested = [$nested];
        }

        $json = json_encode($this->sanitizer->sanitize($nested));

        $this->assertIsString($json);
        $this->assertStringContainsString('*depth limit*', $json);
        $this->assertSame(5, substr_count(haystack: $json, needle: '['));
    }

    public function testItEmptiesARepeatedObjectInsteadOfRecursing(): void
    {
        $node = new stdClass();
        $node->self = $node;

        $this->assertSame(
            '{"@class":"stdClass","props":{"self":{"@class":"stdClass","props":[]}}}',
            json_encode($this->sanitizer->sanitize($node)),
        );
    }

    public function testItKeepsPublicPropertiesOnly(): void
    {
        $json = json_encode($this->sanitizer->sanitize(new ArgumentSanitizerFixture()));

        $this->assertIsString($json);
        $this->assertStringContainsString('"props":{"public":"yes"}', $json);
        $this->assertStringNotContainsString('protected', $json);
        $this->assertStringNotContainsString('private', $json);
    }

    public function testItCapsThePublicPropertiesOfAnObject(): void
    {
        /** @var SanitizedObject $sanitized */
        $sanitized = $this->sanitizer->sanitize(ArgumentSanitizerManyPropertiesFixture::withSixtyProperties());

        $this->assertInstanceOf(SanitizedObject::class, $sanitized);
        $this->assertSame(51, count($sanitized->properties));
        $this->assertSame('10 more items', $sanitized->properties['*truncated*'] ?? '');
    }

    public function testItNeverCallsToString(): void
    {
        /** @var SanitizedObject $sanitized */
        $sanitized = $this->sanitizer->sanitize(new ArgumentSanitizerHostileFixture());

        $this->assertInstanceOf(SanitizedObject::class, $sanitized);
        $this->assertSame([], $sanitized->properties);
    }

    public function testItRedactsSensitiveParameters(): void
    {
        $this->assertSame('*redacted*', $this->sanitizer->sanitize(new SensitiveParameterValue('hunter2')));
    }

    public function testItNamesClosuresAndEnums(): void
    {
        $this->assertSame('Closure', $this->sanitizer->sanitize(static fn(): int => 1));
        $this->assertSame(
            'Errata\Tests\Trace\ArgumentSanitizerEnum::Second',
            $this->sanitizer->sanitize(ArgumentSanitizerEnum::Second),
        );
    }

    public function testItDescribesResources(): void
    {
        $handle = fopen(filename: 'php://memory', mode: 'r');

        $this->assertSame('resource(stream)', $this->sanitizer->sanitize($handle));

        fclose($handle);

        $this->assertSame('resource(closed)', $this->sanitizer->sanitize($handle));
    }

    public function testItSanitizesPropertiesRecursively(): void
    {
        $payload = new stdClass();
        $payload->token = new SensitiveParameterValue('hunter2');
        $payload->nested = ['a' => str_repeat(string: 'b', times: 501)];

        $json = json_encode($this->sanitizer->sanitize($payload));

        $this->assertIsString($json);
        $this->assertStringContainsString('"token":"*redacted*"', $json);
        $this->assertStringContainsString('"nested":{"a":"' . str_repeat(string: 'b', times: 500) . '..."}', $json);
    }
}
