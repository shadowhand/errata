<?php

declare(strict_types=1);

namespace Errata\Tests\Http;

use Closure;
use Errata\Http\Client\BadRequest;
use Errata\Http\Client\Conflict;
use Errata\Http\Client\ContentTooLarge;
use Errata\Http\Client\ExpectationFailed;
use Errata\Http\Client\FailedDependency;
use Errata\Http\Client\Forbidden;
use Errata\Http\Client\Gone;
use Errata\Http\Client\ImATeapot;
use Errata\Http\Client\LengthRequired;
use Errata\Http\Client\Locked;
use Errata\Http\Client\MethodNotAllowed;
use Errata\Http\Client\MisdirectedRequest;
use Errata\Http\Client\NotAcceptable;
use Errata\Http\Client\NotFound;
use Errata\Http\Client\PaymentRequired;
use Errata\Http\Client\PreconditionFailed;
use Errata\Http\Client\PreconditionRequired;
use Errata\Http\Client\ProxyAuthenticationRequired;
use Errata\Http\Client\RangeNotSatisfiable;
use Errata\Http\Client\RequestHeaderFieldsTooLarge;
use Errata\Http\Client\RequestTimeout;
use Errata\Http\Client\TooEarly;
use Errata\Http\Client\TooManyRequests;
use Errata\Http\Client\Unauthorized;
use Errata\Http\Client\UnavailableForLegalReasons;
use Errata\Http\Client\UnprocessableContent;
use Errata\Http\Client\UnsupportedMediaType;
use Errata\Http\Client\UpgradeRequired;
use Errata\Http\Client\UriTooLong;
use Errata\Http\HttpProblem;
use Errata\Http\Server\BadGateway;
use Errata\Http\Server\GatewayTimeout;
use Errata\Http\Server\HttpVersionNotSupported;
use Errata\Http\Server\InsufficientStorage;
use Errata\Http\Server\InternalServerError;
use Errata\Http\Server\LoopDetected;
use Errata\Http\Server\NetworkAuthenticationRequired;
use Errata\Http\Server\NotExtended;
use Errata\Http\Server\NotImplemented;
use Errata\Http\Server\ServiceUnavailable;
use Errata\Http\Server\VariantAlsoNegotiates;
use Errata\Problem;
use Errata\ProblemException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(HttpProblem::class)]
#[CoversClass(Problem::class)]
#[CoversClass(ProblemException::class)]
#[CoversClass(BadGateway::class)]
#[CoversClass(BadRequest::class)]
#[CoversClass(Conflict::class)]
#[CoversClass(ContentTooLarge::class)]
#[CoversClass(ExpectationFailed::class)]
#[CoversClass(FailedDependency::class)]
#[CoversClass(Forbidden::class)]
#[CoversClass(GatewayTimeout::class)]
#[CoversClass(Gone::class)]
#[CoversClass(HttpVersionNotSupported::class)]
#[CoversClass(ImATeapot::class)]
#[CoversClass(InsufficientStorage::class)]
#[CoversClass(InternalServerError::class)]
#[CoversClass(LengthRequired::class)]
#[CoversClass(Locked::class)]
#[CoversClass(LoopDetected::class)]
#[CoversClass(MethodNotAllowed::class)]
#[CoversClass(MisdirectedRequest::class)]
#[CoversClass(NetworkAuthenticationRequired::class)]
#[CoversClass(NotAcceptable::class)]
#[CoversClass(NotExtended::class)]
#[CoversClass(NotFound::class)]
#[CoversClass(NotImplemented::class)]
#[CoversClass(PaymentRequired::class)]
#[CoversClass(PreconditionFailed::class)]
#[CoversClass(PreconditionRequired::class)]
#[CoversClass(ProxyAuthenticationRequired::class)]
#[CoversClass(RangeNotSatisfiable::class)]
#[CoversClass(RequestHeaderFieldsTooLarge::class)]
#[CoversClass(RequestTimeout::class)]
#[CoversClass(ServiceUnavailable::class)]
#[CoversClass(TooEarly::class)]
#[CoversClass(TooManyRequests::class)]
#[CoversClass(Unauthorized::class)]
#[CoversClass(UnavailableForLegalReasons::class)]
#[CoversClass(UnprocessableContent::class)]
#[CoversClass(UnsupportedMediaType::class)]
#[CoversClass(UpgradeRequired::class)]
#[CoversClass(UriTooLong::class)]
#[CoversClass(VariantAlsoNegotiates::class)]
final class HttpProblemTest extends TestCase
{
    /**
     * @param Closure(): HttpProblem $factory
     */
    #[DataProvider('catalog')]
    public function testDefaultsForEachStatus(Closure $factory, string $title, int $status): void
    {
        $problem = $factory();

        $this->assertNull($problem->type);
        $this->assertSame($title, $problem->title);
        $this->assertNull($problem->detail);
        $this->assertSame($status, $problem->status);
        $this->assertNull($problem->instance);
        $this->assertSame([], $problem->extensions);
        $this->assertSame(['title' => $title, 'status' => $status], $problem->toArray());
    }

    /**
     * @param Closure(): HttpProblem $factory
     */
    #[DataProvider('catalog')]
    public function testTitleAndStatusAreImmutable(Closure $factory, string $title, int $status): void
    {
        $problem = $factory();

        try {
            $problem->title = 'Changed';
            $this->fail('The title should be immutable.');
        } catch (ProblemException $exception) {
            $this->assertSame('Cannot overwrite HTTP title', $exception->getMessage());
        }

        try {
            $problem->status = 500;
            $this->fail('The status should be immutable.');
        } catch (ProblemException $exception) {
            $this->assertSame('Cannot overwrite HTTP status', $exception->getMessage());
        }

        $this->assertSame($title, $problem->title);
        $this->assertSame($status, $problem->status);
    }

    public function testConstructorArgumentsAreCarriedThrough(): void
    {
        $problem = new NotFound(
            type: 'https://example.com/problems/not-found',
            detail: 'No such page.',
            instance: '/pages/7',
        );

        $this->assertSame('https://example.com/problems/not-found', $problem->type);
        $this->assertSame('Not Found', $problem->title);
        $this->assertSame('No such page.', $problem->detail);
        $this->assertSame(404, $problem->status);
        $this->assertSame('/pages/7', $problem->instance);
        $this->assertSame(
            [
                'type' => 'https://example.com/problems/not-found',
                'title' => 'Not Found',
                'detail' => 'No such page.',
                'status' => 404,
                'instance' => '/pages/7',
            ],
            $problem->toArray(),
        );
    }

    public function testMutableMembersCanBeChanged(): void
    {
        $problem = new NotFound();

        $problem->type = 'https://example.com/problems/not-found';
        $problem->detail = 'No such page.';
        $problem->instance = '/pages/7';
        $problem->extend('refcode', 'NF.7');

        $this->assertSame('https://example.com/problems/not-found', $problem->type);
        $this->assertSame('No such page.', $problem->detail);
        $this->assertSame('/pages/7', $problem->instance);
        $this->assertSame(
            [
                'type' => 'https://example.com/problems/not-found',
                'title' => 'Not Found',
                'detail' => 'No such page.',
                'status' => 404,
                'instance' => '/pages/7',
                'refcode' => 'NF.7',
            ],
            $problem->toArray(),
        );
    }

    public function testJsonEncodeProducesTheProblemDocument(): void
    {
        $this->assertSame('{"title":"Not Found","status":404}', json_encode(new NotFound(), JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, array{Closure(): HttpProblem, string, int}>
     */
    public static function catalog(): array
    {
        return [
            'BadGateway' => [static fn(): HttpProblem => new BadGateway(), 'Bad Gateway', 502],
            'BadRequest' => [static fn(): HttpProblem => new BadRequest(), 'Bad Request', 400],
            'Conflict' => [static fn(): HttpProblem => new Conflict(), 'Conflict', 409],
            'ContentTooLarge' => [static fn(): HttpProblem => new ContentTooLarge(), 'Content Too Large', 413],
            'ExpectationFailed' => [static fn(): HttpProblem => new ExpectationFailed(), 'Expectation Failed', 417],
            'FailedDependency' => [static fn(): HttpProblem => new FailedDependency(), 'Failed Dependency', 424],
            'Forbidden' => [static fn(): HttpProblem => new Forbidden(), 'Forbidden', 403],
            'GatewayTimeout' => [static fn(): HttpProblem => new GatewayTimeout(), 'Gateway Timeout', 504],
            'Gone' => [static fn(): HttpProblem => new Gone(), 'Gone', 410],
            'HttpVersionNotSupported' => [
                static fn(): HttpProblem => new HttpVersionNotSupported(),
                'HTTP Version Not Supported',
                505,
            ],
            'ImATeapot' => [static fn(): HttpProblem => new ImATeapot(), 'I\'m a Teapot', 418],
            'InsufficientStorage' => [
                static fn(): HttpProblem => new InsufficientStorage(),
                'Insufficient Storage',
                507,
            ],
            'InternalServerError' => [
                static fn(): HttpProblem => new InternalServerError(),
                'Internal Server Error',
                500,
            ],
            'LengthRequired' => [static fn(): HttpProblem => new LengthRequired(), 'Length Required', 411],
            'Locked' => [static fn(): HttpProblem => new Locked(), 'Locked', 423],
            'LoopDetected' => [static fn(): HttpProblem => new LoopDetected(), 'Loop Detected', 508],
            'MethodNotAllowed' => [static fn(): HttpProblem => new MethodNotAllowed(), 'Method Not Allowed', 405],
            'MisdirectedRequest' => [static fn(): HttpProblem => new MisdirectedRequest(), 'Misdirected Request', 421],
            'NetworkAuthenticationRequired' => [
                static fn(): HttpProblem => new NetworkAuthenticationRequired(),
                'Network Authentication Required',
                511,
            ],
            'NotAcceptable' => [static fn(): HttpProblem => new NotAcceptable(), 'Not Acceptable', 406],
            'NotExtended' => [static fn(): HttpProblem => new NotExtended(), 'Not Extended', 510],
            'NotFound' => [static fn(): HttpProblem => new NotFound(), 'Not Found', 404],
            'NotImplemented' => [static fn(): HttpProblem => new NotImplemented(), 'Not Implemented', 501],
            'PaymentRequired' => [static fn(): HttpProblem => new PaymentRequired(), 'Payment Required', 402],
            'PreconditionFailed' => [static fn(): HttpProblem => new PreconditionFailed(), 'Precondition Failed', 412],
            'PreconditionRequired' => [
                static fn(): HttpProblem => new PreconditionRequired(),
                'Precondition Required',
                428,
            ],
            'ProxyAuthenticationRequired' => [
                static fn(): HttpProblem => new ProxyAuthenticationRequired(),
                'Proxy Authentication Required',
                407,
            ],
            'RangeNotSatisfiable' => [
                static fn(): HttpProblem => new RangeNotSatisfiable(),
                'Range Not Satisfiable',
                416,
            ],
            'RequestHeaderFieldsTooLarge' => [
                static fn(): HttpProblem => new RequestHeaderFieldsTooLarge(),
                'Request Header Fields Too Large',
                431,
            ],
            'RequestTimeout' => [static fn(): HttpProblem => new RequestTimeout(), 'Request Timeout', 408],
            'ServiceUnavailable' => [static fn(): HttpProblem => new ServiceUnavailable(), 'Service Unavailable', 503],
            'TooEarly' => [static fn(): HttpProblem => new TooEarly(), 'Too Early', 425],
            'TooManyRequests' => [static fn(): HttpProblem => new TooManyRequests(), 'Too Many Requests', 429],
            'Unauthorized' => [static fn(): HttpProblem => new Unauthorized(), 'Unauthorized', 401],
            'UnavailableForLegalReasons' => [
                static fn(): HttpProblem => new UnavailableForLegalReasons(),
                'Unavailable For Legal Reasons',
                451,
            ],
            'UnprocessableContent' => [
                static fn(): HttpProblem => new UnprocessableContent(),
                'Unprocessable Content',
                422,
            ],
            'UnsupportedMediaType' => [
                static fn(): HttpProblem => new UnsupportedMediaType(),
                'Unsupported Media Type',
                415,
            ],
            'UpgradeRequired' => [static fn(): HttpProblem => new UpgradeRequired(), 'Upgrade Required', 426],
            'UriTooLong' => [static fn(): HttpProblem => new UriTooLong(), 'URI Too Long', 414],
            'VariantAlsoNegotiates' => [
                static fn(): HttpProblem => new VariantAlsoNegotiates(),
                'Variant Also Negotiates',
                506,
            ],
        ];
    }
}
