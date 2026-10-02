<?php

declare(strict_types=1);

namespace Errata;

use Errata\Document\Problem;
use Errata\Http\StatusCodeInterface;
use Errata\Trace\ArgumentTyper;
use Errata\Trace\SourceContext;
use Errata\Trace\TraceFactory;
use Override;
use Throwable;

/**
 * Maps a throwable to a problem document for the active mode.
 *
 * @api
 */
final readonly class ExceptionHandler implements ExceptionHandlerInterface
{
    private const int DEFAULT_TRACE_LIMIT = 30;

    private SourceContext $source;
    private TraceFactory $trace;

    public function __construct(
        private Mode $mode,
        int $traceLimit = self::DEFAULT_TRACE_LIMIT,
    ) {
        // Negative values MUST be clamped, otherwise array_slice() will count from the end.
        if ($traceLimit < 0) {
            $traceLimit = 0;
        }

        $this->source = new SourceContext();
        $this->trace = new TraceFactory($this->source, new ArgumentTyper(), $traceLimit);
    }

    #[Override]
    public function handle(Throwable $exception): Problem
    {
        $status = self::status($exception);

        if ($this->mode === Mode::Minimal) {
            return Problem::minimal($exception, $status);
        }

        $previous = $exception->getPrevious();

        /** @var list<array<string, mixed>> $trace */
        $trace = $exception->getTrace();

        return Problem::full(
            exception: $exception,
            status: $status,
            file: $exception->getFile(),
            line: $exception->getLine(),
            source: $this->source->line($exception->getFile(), $exception->getLine()),
            trace: $this->trace->frames($trace),
            previous: $previous === null ? null : $this->handle($previous),
        );
    }

    /**
     * Only 4xx and 5xx are meaningful for a problem document, so a buggy
     * interface implementation falls back to 500 rather than lying.
     */
    private static function status(Throwable $exception): int
    {
        if ($exception instanceof StatusCodeInterface) {
            $status = $exception->getStatusCode();

            if ($status >= 400 && $status <= 599) {
                return $status;
            }
        }

        return 500;
    }
}
