<?php

declare(strict_types=1);

namespace Errata;

use JsonSerializable;
use Override;

use function Psl\Dict\filter_nulls;
use function Psl\Iter\contains;

/**
 * @api
 * @throws ProblemException if the status is invalid.
 */
class Problem implements JsonSerializable
{
    public ?string $type = null {
        set {
            $this->type = self::normalizeString($value);
        }
    }

    public ?string $title = null {
        set {
            $this->title = self::normalizeString($value);
        }
    }

    public ?string $detail = null {
        set {
            $this->detail = self::normalizeString($value);
        }
    }

    public ?int $status = null {
        set {
            $this->status = self::normalizeStatus($value);
        }
    }

    public ?string $instance = null {
        set {
            $this->instance = self::normalizeString($value);
        }
    }

    /** @var array<string, mixed> */
    final public private(set) array $extensions = [];

    public function __construct(
        ?string $type = null,
        ?string $title = null,
        ?string $detail = null,
        ?int $status = null,
        ?string $instance = null,
    ) {
        $this->type = $type;
        $this->title = $title;
        $this->detail = $detail;
        $this->status = $status;
        $this->instance = $instance;
    }

    /**
     * @throws ProblemException if the name is invalid or reserved.
     */
    final public function extend(string $name, mixed $value): self
    {
        $this->extensions[self::normalizeExtension($name)] = $value;

        return $this;
    }

    /**
     * @return array<string, mixed>
     **/
    final public function toArray(): array
    {
        $members = [
            'type' => $this->type,
            'title' => $this->title,
            'detail' => $this->detail,
            'status' => $this->status,
            'instance' => $this->instance,
        ];

        return filter_nulls($members + $this->extensions);
    }

    #[Override]
    final public function jsonSerialize(): object
    {
        return (object) $this->toArray();
    }

    /**
     * @return non-empty-string
     */
    final protected static function normalizeExtension(string $value): string
    {
        if ($value === '') {
            throw ProblemException::extensionEmpty();
        }

        if (contains(['type', 'title', 'detail', 'status', 'instance'], $value)) {
            throw ProblemException::extensionInvalid($value);
        }

        return $value;
    }

    /**
     * @return positive-int|null
     */
    final protected static function normalizeStatus(?int $value): ?int
    {
        if ($value === null || $value === 0) {
            return null;
        }

        if ($value < 100 || $value > 599) {
            throw ProblemException::invalidStatus();
        }

        return $value;
    }

    final protected static function normalizeString(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }
}
