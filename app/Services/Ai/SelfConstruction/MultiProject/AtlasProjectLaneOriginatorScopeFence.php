<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiProject;

/**
 * Fences originator task specs to the active project lane so
 * cross-workspace symbols do not leak into allowed_files.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasProjectLaneOriginatorScopeFence
{
    public const SCHEMA = 'atlas.self_construction.project_lane_originator_scope_fence.v1';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function fence(array $input): array
    {
        $laneRoot = (string) ($input['lane_root'] ?? '');
        $allowedFiles = (array) ($input['allowed_files'] ?? []);

        $violations = [];
        $fenced = [];

        foreach ($allowedFiles as $file) {
            $file = (string) $file;

            // Check if file is within the lane root
            $inLane = $this->isWithinLane($file, $laneRoot);

            if ($inLane) {
                $fenced[] = $file;
            } else {
                $violations[] = [
                    'file' => $file,
                    'reason' => 'outside_lane_scope',
                    'lane_root' => $laneRoot,
                ];
            }
        }

        $clean = $violations === [];

        return [
            'schema' => self::SCHEMA,
            'clean' => $clean,
            'fenced_files' => $fenced,
            'violations' => $violations,
            'violation_count' => count($violations),
            'lane_root' => $laneRoot,
        ];
    }

    private function isWithinLane(string $file, string $laneRoot): bool
    {
        if ($laneRoot === '') {
            // No lane root — accept app/ and tests/ as relative
            return str_starts_with($file, 'app/') || str_starts_with($file, 'tests/');
        }

        // Check if file is within the lane root
        $normalizedFile = str_replace('\\', '/', $file);
        $normalizedRoot = rtrim(str_replace('\\', '/', $laneRoot), '/');

        return str_starts_with($normalizedFile, $normalizedRoot.'/')
            || $normalizedFile === $normalizedRoot;
    }
}
