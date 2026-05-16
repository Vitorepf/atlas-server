<?php

declare(strict_types=1);

namespace App\Domain\Captures;

/**
 * Read-only fixture for the property-based test case.
 *
 * Round-trip contract: `serialize(parse($md)) === $md` for every
 * well-formed markdown string the generator produces (alphanumerics +
 * spaces, trimmed).
 */
final class CaptureMarkdownParser
{
    public static function parse(string $markdown): array
    {
        return ['body' => trim($markdown)];
    }

    public static function serialize(array $row): string
    {
        return (string) ($row['body'] ?? '');
    }
}
