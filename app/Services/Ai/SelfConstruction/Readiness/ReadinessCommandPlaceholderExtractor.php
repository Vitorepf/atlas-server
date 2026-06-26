<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

/**
 * Extracts command placeholder tokens (e.g. <task>, --lease=<id>) from an
 * Artisan command string.
 *
 * Extracted from AtlasSelfConstructionReadinessService to reduce the god-class.
 * Pure regex parser — no instance state, no constructor arguments.
 */
final class ReadinessCommandPlaceholderExtractor
{
    /**
     * @return list<string>
     */
    public static function fieldsFromCommand(string $command): array
    {
        preg_match_all('/<[^>]+>/', $command, $matches);

        return array_values(array_unique(array_map('strval', $matches[0] ?? [])));
    }
}
