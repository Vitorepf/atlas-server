<?php

declare(strict_types=1);

namespace App\Services\Ai\Support;

use JsonException;

/**
 * Extract a schema-versioned JSON object from free-form model output.
 *
 * Full-pass reuse: de-duplicates private parseJson() in Marketing VSL/bridge composers.
 *
 * @return array<string, mixed>|null
 */
final class SchemaVersionedJsonBlockParser
{
    public static function parse(string $output, string $schemaVersion): ?array
    {
        $blocks = [];
        if (preg_match_all('/```(?:json)?\s*(\{.*?\})\s*```/isu', $output, $m)) {
            foreach ($m[1] as $b) {
                $blocks[] = trim($b);
            }
        }
        $trimmed = trim($output);
        if (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) {
            $blocks[] = $trimmed;
        }
        $first = strpos($output, '{');
        $last = strrpos($output, '}');
        if ($first !== false && $last !== false && $last > $first) {
            $blocks[] = substr($output, $first, $last - $first + 1);
        }

        $fallback = null;
        foreach (array_values(array_unique($blocks)) as $block) {
            try {
                $decoded = json_decode($block, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }
            if (! is_array($decoded)) {
                continue;
            }
            if (($decoded['schema_version'] ?? null) === $schemaVersion) {
                return $decoded;
            }
            $fallback ??= $decoded;
        }

        return $fallback;
    }
}
