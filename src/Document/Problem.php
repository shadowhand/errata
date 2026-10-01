<?php

declare(strict_types=1);

namespace Errata\Document;

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

    // @mago-ignore lint:excessive-parameter-list
    public static function full(
        Throwable $exception,
        int $status,
        string $file,
        int $line,
        ?string $source,
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

    // @mago-ignore lint:excessive-parameter-list
    public function __construct(
        public string $type,
        public string $title,
        public int $status,
        public int $code,
        public ?string $detail = null,
        public ?string $file = null,
        public ?int $line = null,
        public ?string $source = null,
        public ?Trace $trace = null,
        public ?Problem $previous = null,
    ) {}

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

        if ($this->source !== null) {
            $document['source'] = $this->source;
        }

        if ($this->trace !== null) {
            $document['trace'] = $this->trace;

            if ($this->trace->truncated) {
                $document['truncated'] = true;
            }
        }

        if ($this->previous !== null) {
            $document['previous'] = $this->previous;
        }

        return $document;
    }

    private static function title(int $status): string
    {
        return HttpReasonPhraseLookup::getReasonPhrase($status) ?? (string) $status;
    }

    private static function detail(Throwable $exception): string
    {
        return self::shortClass($exception) . ': ' . $exception->getMessage();
    }

    private static function shortClass(Throwable $exception): string
    {
        $class = $exception::class;
        $nul = strpos($class, needle: "\0");

        if ($nul !== false) {
            $class = substr($class, offset: 0, length: $nul);
        }

        $position = strrpos($class, needle: '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
