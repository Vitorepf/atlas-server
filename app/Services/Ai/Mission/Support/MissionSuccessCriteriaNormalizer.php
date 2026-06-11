<?php

declare(strict_types=1);

namespace App\Services\Ai\Mission\Support;

final class MissionSuccessCriteriaNormalizer
{
    /**
     * @return list<string>
     */
    public static function descriptions(mixed $criteria): array
    {
        $descriptions = [];

        foreach ((array) $criteria as $criterion) {
            $description = self::description($criterion);
            if ($description !== null) {
                $descriptions[] = $description;
            }
        }

        return $descriptions;
    }

    private static function description(mixed $criterion): ?string
    {
        $value = match (true) {
            is_string($criterion) => $criterion,
            is_array($criterion) => (string) ($criterion['description'] ?? ''),
            default => '',
        };

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
