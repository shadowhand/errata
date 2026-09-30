<?php

declare(strict_types=1);

namespace Errata;

use Errata\Extension\ExtensionList;
use Errata\Http\Server\InternalServerError;
use Throwable;

/**
 * @api
 */
final readonly class ThrowableTransformer
{
    public function __construct(
        private ProblemMap $problemMap = new ProblemMap(),
        private ExtensionList $extensionList = new ExtensionList(),
    ) {}

    public function transform(Throwable $throwable): Problem
    {
        $problem = $this->convert($throwable);

        $this->extensionList->extend($problem, $throwable);

        return $problem;
    }

    private function convert(Throwable $throwable): Problem
    {
        $factory = $this->problemMap->get($throwable);

        if ($factory) {
            return $factory($throwable);
        }

        return new InternalServerError();
    }
}
