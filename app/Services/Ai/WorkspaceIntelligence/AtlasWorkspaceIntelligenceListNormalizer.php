<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence;

final class AtlasWorkspaceIntelligenceListNormalizer
{
    /**
     * @param  mixed  $values
     * @return list<string>
     */
    public function uniqueStrings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== '',
        )));
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    public function uniqueSingleLineStrings(mixed $values): array
    {
        return array_values(array_filter(
            $this->uniqueStrings($values),
            static fn (string $value): bool => ! str_contains($value, "\n"),
        ));
    }
}
