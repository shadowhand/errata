<?php

declare(strict_types=1);

namespace Errata;

use Errata\Document\Problem;
use Errata\Http\StatusCodeInterface;
use Errata\Path\PathRelativizer;
use Errata\Trace\ArgumentSanitizer;
use Errata\Trace\SourceContext;
use Errata\Trace\TraceFactory;
use Override;
use Throwable;

/**
 * Maps a throwable to a problem document for the active mode.
 *
 * @api
 */
final class ExceptionHandler implements ExceptionHandlerInterface
{
    private const int DEFAULT_TRACE_LIMIT = 30;

    private readonly PathRelativizer $relativizer;

    private readonly SourceContext $source;

    private readonly TraceFactory $trace;

    /**
     * A negative cap is clamped rather than trusted: `array_slice()`
     * treats a negative length as a count from the end, so it cannot
     * mean "at most N frames".
     */
    public function __construct(
        private readonly Mode $mode,
        ?string $projectDir = null,
        int $traceLimit = self::DEFAULT_TRACE_LIMIT,
    ) {
        if ($traceLimit < 0) {
            $traceLimit = 0;
        }

        $this->relativizer = new PathRelativizer($projectDir);
        $this->source = new SourceContext();
        $this->trace = new TraceFactory($this->relativizer, $this->source, new ArgumentSanitizer(), $traceLimit);
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

        return Problem::development(
            exception: $exception,
            status: $status,
            file: $this->relativizer->relativize($exception->getFile()),
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
