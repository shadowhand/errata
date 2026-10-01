<?php

declare(strict_types=1);

namespace Snafu\Demo;

use function header;
use function http_response_code;
use function parse_url;

use const PHP_URL_PATH;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$demo = match ($path) {
    '/minimal' => 'minimal.php',
    '/full' => 'full.php',
    '/recursion' => 'recursion.php',
    '/sensitive' => 'sensitive.php',
    default => null,
};

if ($demo !== null) {
    require __DIR__ . '/' . $demo;

    return;
}

if ($path !== '/') {
    http_response_code(response_code: 404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';

    return;
}

header('Content-Type: text/html; charset=utf-8');

echo <<<'HTML'
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Snafu demos</title>
        <style>
            * { box-sizing: border-box; }
            body {
                margin: 0;
                min-height: 100svh;
                display: grid;
                place-items: center;
                background: #f5f5f5;
                color: #202020;
                font-family: system-ui, sans-serif;
                text-align: center;
            }
            main {
                width: min(28rem, 100% - 3rem);
                padding-block: 2rem;
            }
            h1 { margin: 0; }
            p { margin: 1rem 0 2rem; color: #595959; }
            nav {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 1rem;
            }
            a {
                display: grid;
                place-items: center;
                min-height: 6rem;
                padding: 1rem;
                border: 1px solid #d0d0d0;
                border-radius: 0.5rem;
                background: white;
                color: inherit;
                text-decoration: none;
                font-weight: 600;
            }
            a:hover, a:focus-visible { border-color: #202020; background: #ededed; }
        </style>
    </head>
    <body>
        <main>
            <h1>Snafu demos</h1>
            <p>Choose a demo to view its JSON error response.</p>
            <nav aria-label="Demos">
                <a href="/minimal">minimal</a>
                <a href="/full">full</a>
                <a href="/recursion">recursion</a>
                <a href="/sensitive">sensitive</a>
            </nav>
        </main>
    </body>
    </html>
    HTML;
