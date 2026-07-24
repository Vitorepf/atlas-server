<?php

declare(strict_types=1);

namespace App\Services\Ai\Support;

use Throwable;

/**
 * Shared status/recent projection for domain control-plane cockpits.
 *
 * Full-pass reuse: de-duplicates private section() in Cyber/Strategy control plane projections.
 *
 * @param  class-string  $modelClass
 * @param  list<string>  $recentColumns
 * @return array{count: int, by_status: array<string, int>, recent: list<array<string, mixed>>}
 */
final class ControlPlaneStatusSection
{
    public static function project(string $modelClass, string $statusColumn, int $limit, array $recentColumns): array
    {
        if (! class_exists($modelClass)) {
            return ['count' => 0, 'by_status' => [], 'recent' => []];
        }

        try {
            $byStatus = $modelClass::query()
                ->selectRaw("{$statusColumn} as bucket, COUNT(*) as total")
                ->groupBy($statusColumn)
                ->pluck('total', 'bucket')
                ->all();

            $recent = $modelClass::query()
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get()
                ->map(static function ($row) use ($recentColumns): array {
                    $out = [];
                    foreach ($recentColumns as $column) {
                        $out[$column] = $row->{$column} ?? null;
                    }

                    return $out;
                })->all();

            return [
                'count' => (int) $modelClass::query()->count(),
                'by_status' => array_map(static fn ($v): int => (int) $v, $byStatus),
                'recent' => $recent,
            ];
        } catch (Throwable) {
            return ['count' => 0, 'by_status' => [], 'recent' => []];
        }
    }
}
