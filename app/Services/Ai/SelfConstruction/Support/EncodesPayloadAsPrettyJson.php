<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Support;

/**
 * Pretty-prints an array payload as JSON with consistent flags: throw on error,
 * unescape slashes, unescape unicode. Extracted from the duplicated
 * `private function encode(array $payload): string` body that lived in 5+
 * god-class collaborators; centralised here so any class can drop the trait
 * in without bringing any pre-existing constants or properties.
 */
trait EncodesPayloadAsPrettyJson
{
    private function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
