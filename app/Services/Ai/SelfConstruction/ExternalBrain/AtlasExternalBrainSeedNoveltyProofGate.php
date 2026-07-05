<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Requires novelty proof for each seed against live targets,
 * allowed_files and capability family before enqueue.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasExternalBrainSeedNoveltyProofGate
{
    public const SCHEMA = 'atlas.self_construction.external_brain_seed_novelty_proof_gate.v1';

    public const BLOCK_DUPLICATE_TARGET = 'duplicate_target';
    public const BLOCK_DUPLICATE_ALLOWED_FILES = 'duplicate_allowed_files';
    public const BLOCK_DUPLICATE_CAPABILITY_FAMILY = 'duplicate_capability_family';

    /**
     * @param  array<string, mixed>  $seed
     * @param  array<int, array<string, mixed>>  $liveTasks
     * @return array<string, mixed>
     */
    public function evaluate(array $seed, array $liveTasks = []): array
    {
        $target = (string) ($seed['target'] ?? '');
        $allowedFiles = $this->normalize((array) ($seed['allowed_files'] ?? []));
        $capabilityFamily = (string) ($seed['capability_family'] ?? '');

        $blockers = [];

        foreach ($liveTasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            $liveTarget = (string) ($task['target'] ?? '');
            $liveFiles = $this->normalize((array) ($task['allowed_files'] ?? []));
            $liveFamily = (string) ($task['capability_family'] ?? '');

            if ($target !== '' && $target === $liveTarget) {
                $blockers[] = self::BLOCK_DUPLICATE_TARGET;
            }
            if ($allowedFiles !== [] && $allowedFiles === $liveFiles) {
                $blockers[] = self::BLOCK_DUPLICATE_ALLOWED_FILES;
            }
            if ($capabilityFamily !== '' && $capabilityFamily === $liveFamily) {
                $blockers[] = self::BLOCK_DUPLICATE_CAPABILITY_FAMILY;
            }
        }

        $blockers = array_values(array_unique($blockers));

        return [
            'schema' => self::SCHEMA,
            'novel' => $blockers === [],
            'blockers' => $blockers,
            'target' => $target,
            'allowed_files' => $allowedFiles,
            'capability_family' => $capabilityFamily,
        ];
    }

    private function normalize(array $files): array
    {
        $normalized = array_values(array_unique(array_filter(array_map('strval', $files))));
        sort($normalized, SORT_STRING);
        return $normalized;
    }
}
