<?php

declare(strict_types=1);

namespace Errata\Demo;

use Errata\Mode;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;

use function getenv;

require_once __DIR__ . '/bootstrap.php';

/**
 * @internal
 */
final class Credentials
{
    public readonly SensitiveParameterValue $password;

    public function __construct(
        public readonly string $username,
        #[SensitiveParameter]
        string $password,
    ) {
        // The parameter attribute does not protect a value stored in a public property.
        $this->password = new SensitiveParameterValue($password);
    }

    public function authenticate(#[SensitiveParameter] string $_token, self $credentials): never
    {
        throw new RuntimeException('Demo authentication failed for ' . $credentials->username . '.');
    }
}

namespace\run_demo(Mode::Full, static function (): never {
    $password = getenv('DEMO_PASSWORD');
    $token = getenv('DEMO_TOKEN');
    $credentials = new Credentials('demo-user', $password === false ? 'not-a-real-password' : $password);

    $credentials->authenticate($token === false ? 'not-a-real-token' : $token, $credentials);
});
