<?php

declare(strict_types=1);

namespace Snafu\Tests\Document;

use Exception;
use LogicException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Snafu\Document\Problem;
use Snafu\Document\SourceLine;
use Snafu\Document\Trace;

use function is_array;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(Problem::class)]
final class ProblemTest extends TestCase
{
    public function testMinimalCarriesTheStatusPhraseTheClassAndTheCode(): void
    {
        $problem = Problem::minimal(new RuntimeException('secret detail', 7), 500);

        $this->assertSame(
            [
                'type' => 'about:blank',
                'title' => 'Internal Server Error',
                'status' => 500,
                'code' => 7,
                'detail' => 'RuntimeException',
            ],
            $problem->jsonSerialize(),
        );
    }

    public function testTheShortNameOfAGlobalClassIsItsName(): void
    {
        $this->assertSame('Exception', Problem::minimal(new Exception('x'), 500)->detail);
    }

    public function testMinimalNeverLeaksTheOriginPathOfAnAnonymousClass(): void
    {
        $problem = Problem::minimal(new class extends RuntimeException {}, 500);
        $json = json_encode($problem, JSON_THROW_ON_ERROR);

        $this->assertSame('RuntimeException@anonymous', $problem->detail);
        $this->assertStringNotContainsString('\u0000', $json);
        $this->assertStringNotContainsString(__FILE__, $json);
    }

    public function testAMappedStatusCarriesTheRecommendedPhrase(): void
    {
        $this->assertSame('Not Found', Problem::minimal(new RuntimeException('x'), 404)->title);
    }

    public function testAnUnmappedStatusFallsBackToTheCode(): void
    {
        $this->assertSame('599', Problem::minimal(new RuntimeException('x'), 599)->title);
    }

    public function testANonIntegerCodeIsCast(): void
    {
        $code = $this->sqlStateCode();

        $this->assertIsString($code);
        $this->assertSame(0, Problem::minimal(new PDOException('x'), 500)->code);
        $this->assertSame(0, (int) $code);
    }

    public function testDevelopmentCarriesEveryDevelopmentMember(): void
    {
        $previous = Problem::minimal(new RuntimeException('cause'), 500);
        $problem = Problem::development(
            exception: new RuntimeException('boom', 3),
            status: 422,
            file: 'src/Foo.php',
            line: 12,
            source: [new SourceLine(12, '    throw new RuntimeException();')],
            trace: new Trace([], false),
            previous: $previous,
        );

        $this->assertSame(
            [
                'type' => 'about:blank',
                'title' => 'Unprocessable Content',
                'status' => 422,
                'code' => 3,
                'detail' => 'RuntimeException: boom',
                'file' => 'src/Foo.php',
                'line' => 12,
                'source' => [['line' => 12, 'code' => '    throw new RuntimeException();']],
                'trace' => [],
                'previous' => [
                    'type' => 'about:blank',
                    'title' => 'Internal Server Error',
                    'status' => 500,
                    'code' => 0,
                    'detail' => 'RuntimeException',
                ],
            ],
            self::json($problem),
        );
    }

    public function testDevelopmentOmitsAnEmptySourceAndANullPrevious(): void
    {
        $problem = Problem::development(
            exception: new RuntimeException('boom'),
            status: 500,
            file: 'src/Foo.php',
            line: 1,
            source: [],
            trace: new Trace([], false),
            previous: null,
        );

        $serialized = $problem->jsonSerialize();

        $this->assertArrayNotHasKey('source', $serialized);
        $this->assertArrayNotHasKey('previous', $serialized);
        $this->assertArrayNotHasKey('traceTruncated', $serialized);
    }

    public function testDevelopmentFlagsATruncatedTrace(): void
    {
        $problem = Problem::development(
            exception: new RuntimeException('boom'),
            status: 500,
            file: 'src/Foo.php',
            line: 1,
            source: [],
            trace: new Trace([], true),
            previous: null,
        );

        $this->assertTrue($problem->jsonSerialize()['traceTruncated'] ?? false);
    }

    public function testItCanBeConstructedDirectly(): void
    {
        $problem = new Problem(
            type: 'about:blank',
            title: 'Service Unavailable',
            status: 503,
            code: 0,
            detail: 'RuntimeException',
            file: 'f',
            line: 2,
        );

        $this->assertSame(
            [
                'type' => 'about:blank',
                'title' => 'Service Unavailable',
                'status' => 503,
                'code' => 0,
                'detail' => 'RuntimeException',
                'file' => 'f',
                'line' => 2,
            ],
            $problem->jsonSerialize(),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function json(Problem $problem): array
    {
        return self::decoded(json_decode(
            json: json_encode($problem, JSON_THROW_ON_ERROR),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decoded(mixed $value): array
    {
        if (!is_array($value)) {
            throw new LogicException('The problem did not serialize to a JSON object.');
        }

        return $value;
    }

    /**
     * A real PDO failure, so the string `getCode()` is genuine rather
     * than simulated: `Exception::getCode()` is final and cannot be
     * overridden.
     */
    private function sqlStateCode(): string|int
    {
        $pdo = new PDO('sqlite::memory:');

        try {
            $pdo->query('select * from snafu_missing_table');
        } catch (PDOException $exception) {
            return $exception->getCode();
        }

        return 0;
    }
}
