<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Pure, advisory pre-serve gate. Classifies a task packet and routes it before
 * a worker claims it — no queue mutations, no lease changes, no file writes.
 *
 * Input facts:
 *   packet                — {id, objective, allowed_files[], acceptance_criteria[],
 *                           test_only_has_contract, dependencies[]}.
 *   resolved_dependencies — IDs already satisfied (default []).
 *   scope_rules           — {max_files?, forbidden_targets[]?}.
 *
 * AC2 — classification (first match wins):
 *   dependency_wait — has unmet dependencies (AC3: takes priority over other issues).
 *   respec          — scope violation: allowed_files > max_files (default 5).
 *   respec          — forbidden-target: any allowed_file in forbidden_targets.
 *   respec          — empty acceptance_criteria.
 *   respec          — empty objective.
 *   serve           — packet is healthy.
 *
 * AC3 — unmet dependencies → dependency_wait, not respec/poison.
 *
 * AC4 — pure, advisory; returns classification + reasons + repair_hints.
 */
final class AtlasTaskQueuePreServeSelfHealingGate
{
    public const SCHEMA = 'atlas.task_quality.pre_serve_self_healing_gate.v1';

    private const DEFAULT_MAX_FILES = 5;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function classify(array $facts): array
    {
        $packet   = is_array($facts['packet'] ?? null)    ? $facts['packet']    : [];
        $resolved = is_array($facts['resolved_dependencies'] ?? null)
            ? array_map('strval', $facts['resolved_dependencies'])
            : [];
        $rules    = is_array($facts['scope_rules'] ?? null) ? $facts['scope_rules'] : [];

        $id           = (string) ($packet['id']        ?? '');
        $objective    = (string) ($packet['objective'] ?? '');
        $allowedFiles = is_array($packet['allowed_files']        ?? null) ? array_map('strval', $packet['allowed_files'])        : [];
        $acceptance   = is_array($packet['acceptance_criteria']  ?? null) ? $packet['acceptance_criteria']  : [];
        $dependencies = is_array($packet['dependencies']         ?? null) ? array_map('strval', $packet['dependencies'])         : [];

        $maxFiles        = (int) ($rules['max_files']         ?? self::DEFAULT_MAX_FILES);
        $forbiddenTargets = is_array($rules['forbidden_targets'] ?? null)
            ? array_map('strval', $rules['forbidden_targets'])
            : [];

        // AC3: dependency_wait first.
        $unmetDeps = array_values(array_filter($dependencies, fn($d) => ! in_array($d, $resolved, true)));
        if (! empty($unmetDeps)) {
            return $this->result('dependency_wait', ['unmet_dependencies'], $unmetDeps, [
                'resolve_dependencies_first' => $unmetDeps,
            ]);
        }

        // Respec checks.
        $reasons      = [];
        $repairHints  = [];

        if (count($allowedFiles) > $maxFiles) {
            $reasons[]                   = 'scope_too_many_files';
            $repairHints['max_files']    = $maxFiles;
        }

        $hitForbidden = array_values(array_intersect($allowedFiles, $forbiddenTargets));
        if (! empty($hitForbidden)) {
            $reasons[]                       = 'forbidden_target';
            $repairHints['forbidden_files']  = $hitForbidden;
        }

        if (empty($acceptance)) {
            $reasons[]                           = 'empty_acceptance_criteria';
            $repairHints['add_acceptance_criteria'] = true;
        }

        if (trim($objective) === '') {
            $reasons[]                    = 'empty_objective';
            $repairHints['add_objective'] = true;
        }

        if (! empty($reasons)) {
            return $this->result('respec', $reasons, [], $repairHints);
        }

        return $this->result('serve', [], [], []);
    }

    private function result(
        string $classification, array $reasons, array $unmetDeps, array $repairHints,
    ): array {
        return [
            'schema_version'      => self::SCHEMA,
            'classification'      => $classification,
            'reasons'             => $reasons,
            'unmet_dependencies'  => $unmetDeps,
            'repair_hints'        => $repairHints,
        ];
    }
}
