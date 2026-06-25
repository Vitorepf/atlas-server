<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskGraph;

/**
 * Pure, facts-only quality gate for task drafts emitted by the task-graph missing-organ planner.
 *
 * NO scalar scoring. NO queue/filesystem/provider/process side effects.
 *
 * Checks:
 *   - objective string present
 *   - allowed_files non-empty AND every path is a concrete file (not a bare directory)
 *   - scope_in covers every allowed_files entry
 *   - acceptance_criteria non-empty
 *   - required_evidence non-empty
 *   - depends_on (when present) lists known packet ids — verified only when queueFacts.known_packet_ids supplied
 *   - autonomy contract: final_runtime_owner=atlas_native, steady_state_runtime_owner=atlas_server
 *   - dependency flags: requires_operator / requires_human / requires_external_provider all false
 *
 * Each violation contributes a blocker (machine code) AND a repair_hint (operator-facing prose).
 */
final class AtlasSelfConstructionTaskGraphDraftQualityGate
{
    public const SCHEMA = 'atlas.self_construction.task_graph_draft_quality_gate.v1';

    /**
     * @param  array<string,mixed>  $draft
     * @param  array<string,mixed>  $queueFacts {known_packet_ids?: list<string>}
     * @return array<string,mixed>
     */
    public function evaluate(array $draft, array $queueFacts = []): array
    {
        $blockers = [];
        $repairHints = [];
        $facts = [];

        // Objective
        $objective = (string) ($draft['objective'] ?? '');
        $facts['objective_present'] = $objective !== '';
        if (! $facts['objective_present']) {
            $blockers[] = 'objective_missing';
            $repairHints[] = 'Provide a non-empty objective describing what the task delivers.';
        }

        // allowed_files
        $allowed = array_values((array) ($draft['allowed_files'] ?? []));
        $facts['allowed_files_count'] = count($allowed);
        if ($allowed === []) {
            $blockers[] = 'allowed_files_empty';
            $repairHints[] = 'Add at least one concrete file path to allowed_files.';
        }
        $bare = [];
        foreach ($allowed as $p) {
            $path = (string) $p;
            if ($path === '' || $this->isBareDirectory($path)) {
                $bare[] = $path;
            }
        }
        $facts['bare_directories'] = $bare;
        if ($bare !== []) {
            $blockers[] = 'allowed_files_contains_bare_directories:'.implode(',', $bare);
            $repairHints[] = 'Replace bare directory entries with concrete file paths in allowed_files.';
        }

        // scope_in covers allowed_files
        $scopeIn = array_values((array) ($draft['scope_in'] ?? []));
        $uncovered = array_values(array_diff($allowed, $scopeIn));
        $facts['scope_uncovered_allowed_files'] = $uncovered;
        if ($uncovered !== []) {
            $blockers[] = 'scope_in_does_not_cover_allowed_files';
            $repairHints[] = 'Extend scope_in to include every allowed_files entry.';
        }

        // acceptance_criteria
        $acceptance = array_values((array) ($draft['acceptance_criteria'] ?? []));
        $facts['acceptance_criteria_count'] = count($acceptance);
        if ($acceptance === []) {
            $blockers[] = 'acceptance_criteria_missing';
            $repairHints[] = 'List at least one acceptance criterion the task must satisfy.';
        }

        // required_evidence
        $evidence = array_values((array) ($draft['required_evidence'] ?? []));
        $facts['required_evidence_count'] = count($evidence);
        if ($evidence === []) {
            $blockers[] = 'required_evidence_missing';
            $repairHints[] = 'List at least one required evidence kind (e.g. tests_or_gates_result).';
        }

        // depends_on (only verified when queueFacts.known_packet_ids is provided)
        $depends = array_values(array_map('strval', (array) ($draft['depends_on'] ?? [])));
        $known = isset($queueFacts['known_packet_ids']) ? array_values(array_map('strval', (array) $queueFacts['known_packet_ids'])) : null;
        if ($known !== null) {
            $unknown = array_values(array_diff($depends, $known));
            $facts['unknown_depends_on'] = $unknown;
            if ($unknown !== []) {
                $blockers[] = 'depends_on_unknown:'.implode(',', $unknown);
                $repairHints[] = 'Remove or correct depends_on entries that do not match an existing/declared packet id.';
            }
        } else {
            $facts['unknown_depends_on'] = [];
        }

        // Autonomy contract
        $finalOwner = (string) ($draft['final_runtime_owner'] ?? '');
        $facts['final_runtime_owner_atlas_native'] = $finalOwner === 'atlas_native';
        if (! $facts['final_runtime_owner_atlas_native']) {
            $blockers[] = 'final_runtime_owner_not_atlas_native:'.$finalOwner;
            $repairHints[] = 'Set final_runtime_owner=atlas_native; Atlas executes the task end-to-end.';
        }
        $steady = (string) ($draft['steady_state_runtime_owner'] ?? '');
        $facts['steady_state_runtime_owner_atlas_server'] = $steady === 'atlas_server';
        if (! $facts['steady_state_runtime_owner_atlas_server']) {
            $blockers[] = 'steady_state_runtime_owner_not_atlas_server:'.$steady;
            $repairHints[] = 'Set steady_state_runtime_owner=atlas_server.';
        }

        // Dependency flags
        foreach (['requires_operator', 'requires_human', 'requires_external_provider'] as $flag) {
            $value = (bool) ($draft[$flag] ?? false);
            $facts[$flag] = $value;
            if ($value === true) {
                $blockers[] = $flag.'_must_be_false';
                $repairHints[] = 'Set '.$flag.'=false; Atlas-native drafts cannot depend on external actors.';
            }
        }

        $passed = $blockers === [];

        return [
            'schema_version' => self::SCHEMA,
            'passed' => $passed,
            'blockers' => $blockers,
            'facts' => $facts,
            'repair_hints' => $repairHints,
        ];
    }

    private function isBareDirectory(string $path): bool
    {
        if ($path === '') {
            return true;
        }
        if (str_ends_with($path, '/')) {
            return true;
        }
        $basename = basename($path);
        if ($basename === '' || str_contains($basename, '.') === false) {
            return true;
        }

        return false;
    }
}
