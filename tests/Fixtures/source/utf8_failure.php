<?php declare(strict_types=1);

function errata_fixture_utf8_failure(): never
{
    throw new RuntimeException("invalid utf8 fixture"); // ÿþ invalid utf8 €
}
