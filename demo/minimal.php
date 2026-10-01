<?php

declare(strict_types=1);

namespace Snafu\Demo;

use RuntimeException;
use Snafu\Mode;

require_once __DIR__ . '/bootstrap.php';

namespace\run_demo(Mode::Minimal, static function (): never {
    throw new RuntimeException('The demo request could not be completed.');
});
