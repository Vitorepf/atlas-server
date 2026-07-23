<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Proof-backed scope gate: large compression plans fail in practice when a worker receives a
 * broad write set instead of an atomic, provable slice. This slicer splits a plan's targets into
 * one slice per target — bounded allowed_files, one primary target, its own tests, and the
 * dependency order it must wait on. Any target whose slice would be unsafe (too many files, no
 * declared tests, no declared files) is rejected outright with the exact split reason, never
 * silently chunked or summarized away.
 *
 * Input shape:
 *   { plan: {
 *       targets?: list<{
 *           target?:         string,
 *           allowed_files?:  list<string>,
 *           tests?:          list<string>,
 *           depends_on?:     list<string>,
 *       }>,
 *       max_files_per_slice?: int,  // default 5
 *   } }
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainCompressionScopeSlicer
{
    public const SCHEMA = 'atlas.self_construction.external_brain.compression_scope_slicer.v1';

    private const DEFAULT_MAX_FILES_PER_SLICE = 5;

    /**
     * @param  array{plan?: array<string,mixed>}  $facts
     * @return array{schema:string, slices:list<array<string,mixed>>, rejected:list<array<string,mixed>>}
     */
    public function slice(array $facts): array
    {
        $plan = is_array($facts['plan'] ?? null) ? $facts['plan'] : [];
        $targets = is_array($plan['targets'] ?? null) ? $plan['targets'] : [];
        $maxFilesPerSlice = max(1, (int) ($plan['max_files_per_slice'] ?? self::DEFAULT_MAX_FILES_PER_SLICE));

        $slices = [];
        $rejected = [];

        foreach ($targets as $t) {
            if (! is_array($t)) {
                continue;
            }
            $target = trim((string) ($t['target'] ?? ''));
            if ($target === '') {
                continue;
            }

            $allowedFiles = $this->stringList($t['allowed_files'] ?? null);
            $tests = $this->stringList($t['tests'] ?? null);
            $dependsOn = $this->stringList($t['depends_on'] ?? null);

            $splitReasons = [];
            if ($allowedFiles === []) {
                $splitReasons[] = 'missing_allowed_files';
            }
            if (count($allowedFiles) > $maxFilesPerSlice) {
                $splitReasons[] = 'allowed_files_exceeds_bound';
            }
            if ($tests === []) {
                $splitReasons[] = 'missing_tests';
            }

            if ($splitReasons !== []) {
                $rejected[] = [
                    'target' => $target,
                    'split_reasons' => $splitReasons,
                ];

                continue;
            }

            $slices[] = [
                'primary_target' => $target,
                'allowed_files' => $allowedFiles,
                'tests' => $tests,
                'dependency_order' => $dependsOn,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'slices' => $slices,
            'rejected' => $rejected,
        ];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $value), static fn (string $v): bool => $v !== ''));
    }
}
