<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Builds a receipt explaining why a task spec is novel relative to
 * live queue targets, allowed_files, capability family and recent
 * completions.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasTaskQualitySpecNoveltyReceiptBuilder
{
    public const SCHEMA = 'atlas.self_construction.task_quality_spec_novelty_receipt.v1';

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<int, array<string, mixed>>  $liveTasks
     * @param  array<int, array<string, mixed>>  $recentCompletions
     * @return array<string, mixed>
     */
    public function build(array $spec, array $liveTasks = [], array $recentCompletions = []): array
    {
        $target = (string) ($spec['target'] ?? '');
        $allowedFiles = $this->normalize((array) ($spec['allowed_files'] ?? []));
        $capabilityFamily = (string) ($spec['capability_family'] ?? '');

        $comparedTargets = [];
        $reasonCodes = [];

        foreach ($liveTasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            $liveTarget = (string) ($task['target'] ?? '');
            $liveFiles = $this->normalize((array) ($task['allowed_files'] ?? []));
            $liveFamily = (string) ($task['capability_family'] ?? '');

            $comparedTargets[] = [
                'target' => $liveTarget,
                'allowed_files_match' => $allowedFiles === $liveFiles,
                'capability_family_match' => $capabilityFamily === $liveFamily,
                'target_match' => $target === $liveTarget,
            ];

            if ($target === $liveTarget) {
                $reasonCodes[] = 'duplicate_target:'.$liveTarget;
            }
            if ($allowedFiles === $liveFiles && $allowedFiles !== []) {
                $reasonCodes[] = 'duplicate_allowed_files';
            }
            if ($capabilityFamily === $liveFamily && $capabilityFamily !== '') {
                $reasonCodes[] = 'duplicate_capability_family:'.$liveFamily;
            }
        }

        foreach ($recentCompletions as $completion) {
            if (! is_array($completion)) {
                continue;
            }
            $completedTarget = (string) ($completion['target'] ?? '');
            if ($target === $completedTarget && $target !== '') {
                $reasonCodes[] = 'recently_completed:'.$completedTarget;
            }
        }

        $reasonCodes = array_values(array_unique($reasonCodes));
        $novel = $reasonCodes === [];

        return [
            'schema' => self::SCHEMA,
            'novel' => $novel,
            'decision' => $novel ? 'novel' : 'duplicate',
            'reason_codes' => $reasonCodes,
            'compared_targets' => $comparedTargets,
            'spec_target' => $target,
            'spec_allowed_files' => $allowedFiles,
            'spec_capability_family' => $capabilityFamily,
            'raw_prompt_material' => null,
        ];
    }

    private function normalize(array $files): array
    {
        $normalized = array_values(array_unique(array_filter(array_map('strval', $files))));
        sort($normalized, SORT_STRING);
        return $normalized;
    }
}
