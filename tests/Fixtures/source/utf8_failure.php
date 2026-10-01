<?php declare(strict_types=1);

function snafu_fixture_utf8_failure(): never
{
    // ÿþ invalid utf8 €
    throw new RuntimeException("invalid utf8 fixture");
}
