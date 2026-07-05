<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Tightens proposed allowed_files to the smallest service-plus-test
 * scope that can satisfy the objective and acceptance criteria.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasTaskQualityAllowedFilesScopeTightener
{
    public const SCHEMA = 'atlas.self_construction.task_quality_allowed_files_scope_tightener.v1';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function tighten(array $input): array
    {
        $allowedFiles = (array) ($input['allowed_files'] ?? []);
        $objective = (string) ($input['objective'] ?? '');
        $acceptanceCriteria = (array) ($input['acceptance_criteria'] ?? []);

        $kept = [];
        $removed = [];

        foreach ($allowedFiles as $file) {
            $file = (string) $file;

            // Remove bare directories
            if (str_ends_with($file, '/')) {
                $removed[] = ['file' => $file, 'reason' => 'bare_directory'];
                continue;
            }

            // Remove docs (not implementation or test)
            if (str_starts_with($file, 'docs/') || str_starts_with($file, 'README') || str_ends_with($file, '.md')) {
                $removed[] = ['file' => $file, 'reason' => 'documentation'];
                continue;
            }

            // Remove config files unless objective mentions config
            if (str_starts_with($file, 'config/') && ! str_contains(strtolower($objective), 'config')) {
                $removed[] = ['file' => $file, 'reason' => 'unrelated_config'];
                continue;
            }

            // Remove routes files unless objective mentions routes
            if (str_starts_with($file, 'routes/') && ! str_contains(strtolower($objective), 'route')) {
                $removed[] = ['file' => $file, 'reason' => 'unrelated_routes'];
                continue;
            }

            // Keep implementation and test files
            if (str_starts_with($file, 'app/') || str_starts_with($file, 'tests/')) {
                $kept[] = $file;
                continue;
            }

            // Remove unrelated services
            $removed[] = ['file' => $file, 'reason' => 'unrelated_service'];
        }

        return [
            'schema' => self::SCHEMA,
            'tightened_files' => array_values(array_unique($kept)),
            'removed_files' => $removed,
            'removed_count' => count($removed),
            'kept_count' => count($kept),
            'original_count' => count($allowedFiles),
        ];
    }
}
