<?php

declare(strict_types=1);

namespace Snafu\Document;

use CodeInc\HttpReasonPhraseLookup\HttpReasonPhraseLookup;
use JsonSerializable;
use Override;
use Throwable;

use function strpos;
use function strrpos;
use function substr;

/**
 * An RFC 9457 problem document.
 *
 * @api
 */
final readonly class Problem implements JsonSerializable
{
    /**
     * RFC 9457's "no semantic identification beyond the status" value.
     */
    private const string TYPE = 'about:blank';

    // @mago-ignore lint:excessive-parameter-list
    public function __construct(
        public string $type,
        public string $title,
        public int $status,
        public int $code,
        public ?string $detail = null,
        public ?string $file = null,
        public ?int $line = null,
        /** @var list<SourceLine> */
        public array $source = [],
        public ?Trace $trace = null,
        public ?Problem $previous = null,
    ) {}

    /**
     * The minimal document: the status phrase, the short class name as
     * `detail`, and the code, nothing else.
     */
    public static function minimal(Throwable $exception, int $status): self
    {
        return new self(
            type: self::TYPE,
            title: self::title($status),
            status: $status,
            code: (int) $exception->getCode(),
            detail: self::shortClass($exception),
        );
    }

    /**
     * The full document: everything `minimal()` carries, plus `detail`
     * as `class: message`, the origin, the source window, the trace,
     * and the cause.
     *
     * @param list<SourceLine> $source
     *
     * @mago-ignore lint:excessive-parameter-list
     */
    public static function development(
        Throwable $exception,
        int $status,
        string $file,
        int $line,
        array $source,
        Trace $trace,
        ?Problem $previous,
    ): self {
        return new self(
            type: self::TYPE,
            title: self::title($status),
            status: $status,
            code: (int) $exception->getCode(),
            detail: self::detail($exception),
            file: $file,
            line: $line,
            source: $source,
            trace: $trace,
            previous: $previous,
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function jsonSerialize(): array
    {
        $document = [
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
            'code' => $this->code,
        ];

        if ($this->detail !== null) {
            $document['detail'] = $this->detail;
        }

        if ($this->file !== null) {
            $document['file'] = $this->file;
        }

        if ($this->line !== null) {
            $document['line'] = $this->line;
        }

        if ($this->source !== []) {
            $document['source'] = $this->source;
        }

        if ($this->trace !== null) {
            $document['trace'] = $this->trace;

            if ($this->trace->truncated) {
                $document['traceTruncated'] = true;
            }
        }

        if ($this->previous !== null) {
            $document['previous'] = $this->previous;
        }

        return $document;
    }

    /**
     * The reason phrase for `$status`, from
     * `codeinc/http-reason-phrase-lookup`. A status with no registered
     * phrase falls back to the code itself, so the title is never empty.
     */
    private static function title(int $status): string
    {
        return HttpReasonPhraseLookup::getReasonPhrase($status) ?? (string) $status;
    }

    /**
     * The dev-mode exception identity: the short class name, then the
     * message, always both.
     */
    private static function detail(Throwable $exception): string
    {
        return self::shortClass($exception) . ': ' . $exception->getMessage();
    }

    /**
     * The unqualified class name, truncated at the NUL byte that an
     * anonymous class carries before its origin path.
     */
    private static function shortClass(Throwable $exception): string
    {
        $class = $exception::class;
        $nul = strpos(haystack: $class, needle: "\0");

        if ($nul !== false) {
            $class = substr(string: $class, offset: 0, length: $nul);
        }

        $position = strrpos(haystack: $class, needle: '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
