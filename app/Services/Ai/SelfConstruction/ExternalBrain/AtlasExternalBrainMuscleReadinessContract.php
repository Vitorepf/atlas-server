<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Decides whether a task is ready to be sent to worker muscles (Claude/Codex/Cursor/native).
 *
 * Six readiness checks (all must pass for ready=true):
 *   scoped_files       — allowed_files non-empty, no bare dirs, no wildcards, no path traversal.
 *   no_collision       — allowed_files don't overlap with any active claim's claimed_files.
 *   runnable_proof     — at least one acceptance criterion mentions a runnable test or gate.
 *   enough_context     — objective non-empty and long enough to act on (>= 20 chars); not vague.
 *   bounded_risk       — risk_level is 'low' or 'medium' (critical tasks need operator gate).
 *   clear_give_back_path — required_evidence non-empty (worker knows how to prove done/give_back).
 *
 * FAILURE CATEGORIES (mutually exclusive, first match wins):
 *   already_implemented    — duplicate flag set on task OR target files already in implemented set.
 *   operator_only_scope    — risk_level=critical, or objective mentions forbidden operator patterns.
 *   repairable_spec_defect — all other failures: spec is broken but atlas can fix it.
 *
 * INPUT spec:
 *   { task_packet_id?:string, objective?:string, allowed_files?:list<string>,
 *     acceptance_criteria?:list<string>, required_evidence?:list<string>,
 *     risk_level?:string, duplicate?:bool, implemented_files?:list<string>,
 *     active_claims?:list<{worker_id:string, claimed_files:list<string>}> }
 *
 * OUTPUT:
 *   { schema, task_packet_id, ready:bool, failure_category:string|null,
 *     checks:list<Check>, repair_hints:list<string> }
 *
 * Check: { check:string, passed:bool, reason:string }
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasExternalBrainMuscleReadinessContract
{
    public const SCHEMA = 'atlas.external_brain.muscle_readiness_contract.v1';

    public const CATEGORY_ALREADY_IMPLEMENTED = 'already_implemented';

    public const CATEGORY_OPERATOR_ONLY = 'operator_only_scope';

    public const CATEGORY_REPAIRABLE = 'repairable_spec_defect';

    private const RUNNABLE_SIGNALS = ['test', 'assert', 'artisan', 'exit', 'passes', 'green', 'gate', 'proves', 'verify'];

    private const VAGUE_OBJECTIVE_PATTERNS = ['make it better', 'improve things', 'clean up', 'do something about'];

    private const OPERATOR_OBJECTIVE_PATTERNS = ['requires operator', 'ask operator', 'human approval', 'manual review', 'operator must'];

    /**
     * @param  array{
     *   task_packet_id?:string, objective?:string, allowed_files?:list<string>,
     *   acceptance_criteria?:list<string>, required_evidence?:list<string>,
     *   risk_level?:string, duplicate?:bool, implemented_files?:list<string>,
     *   active_claims?:list<array{worker_id:string, claimed_files:list<string>}>
     * }  $spec
     * @return array{schema:string, task_packet_id:string, ready:bool, failure_category:string|null, checks:list<array<string,mixed>>, repair_hints:list<string>}
     */
    public function check(array $spec): array
    {
        $id = (string) ($spec['task_packet_id'] ?? '');
        $objective = (string) ($spec['objective'] ?? '');
        $allowedFiles = is_array($spec['allowed_files'] ?? null) ? array_values(array_filter(array_map('strval', $spec['allowed_files']))) : [];
        $criteria = is_array($spec['acceptance_criteria'] ?? null) ? $spec['acceptance_criteria'] : [];
        $evidence = is_array($spec['required_evidence'] ?? null) ? $spec['required_evidence'] : [];
        $riskLevel = strtolower(trim((string) ($spec['risk_level'] ?? 'low')));
        $isDuplicate = (bool) ($spec['duplicate'] ?? false);
        $implementedFiles = is_array($spec['implemented_files'] ?? null) ? array_flip(array_map('strval', $spec['implemented_files'])) : [];
        $activeClaims = is_array($spec['active_claims'] ?? null) ? $spec['active_claims'] : [];

        $checks = [
            $this->checkScopedFiles($allowedFiles),
            $this->checkNoCollision($allowedFiles, $activeClaims),
            $this->checkRunnableProof($criteria),
            $this->checkEnoughContext($objective),
            $this->checkBoundedRisk($riskLevel, $objective),
            $this->checkClearGiveBackPath($evidence),
        ];

        $ready = array_reduce($checks, static fn (bool $carry, array $c): bool => $carry && $c['passed'], true);
        $hints = array_values(array_unique(array_merge(...array_column($checks, 'hints'))));

        // Strip internal hints key for output.
        $checkOutput = array_map(static function (array $c): array {
            return ['check' => $c['check'], 'passed' => $c['passed'], 'reason' => $c['reason']];
        }, $checks);

        $failureCategory = null;
        if (! $ready) {
            $failureCategory = $this->classifyFailure($isDuplicate, $allowedFiles, $implementedFiles, $riskLevel, $objective, $checks);
        }

        return [
            'schema' => self::SCHEMA,
            'task_packet_id' => $id,
            'ready' => $ready,
            'failure_category' => $failureCategory,
            'checks' => $checkOutput,
            'repair_hints' => $hints,
            'worker_readiness' => $this->assessWorkerReadiness($spec),
        ];
    }

    /** @param list<string> $allowedFiles @return array<string, mixed> */
    private function checkScopedFiles(array $allowedFiles): array
    {
        $reasons = [];
        $hints = [];

        if ($allowedFiles === []) {
            $reasons[] = 'allowed_files_empty';
            $hints[] = 'add_at_least_one_implementation_file_to_allowed_files';
        }
        foreach ($allowedFiles as $f) {
            if (str_contains($f, '*')) {
                $reasons[] = 'wildcard_in_allowed_files';
                $hints[] = 'expand_wildcard_to_explicit_file_paths';
            }
            if (str_contains($f, '..')) {
                $reasons[] = 'path_traversal_in_allowed_files';
                $hints[] = 'use_repo_relative_paths_without_traversal';
            }
            if (! str_contains($f, '.') && ! str_contains($f, '*')) {
                $reasons[] = 'bare_directory_in_allowed_files:'.$f;
                $hints[] = 'expand_directory_to_explicit_php_file_paths';
            }
        }

        return $this->build('scoped_files', $reasons, $hints);
    }

    /**
     * @param list<string> $allowedFiles
     * @param list<array{worker_id:string, claimed_files:list<string>}> $activeClaims
     * @return array<string, mixed>
     */
    private function checkNoCollision(array $allowedFiles, array $activeClaims): array
    {
        $reasons = [];
        $hints = [];

        if ($allowedFiles === []) {
            return $this->build('no_collision', [], []);
        }

        foreach ($activeClaims as $claim) {
            $wid = (string) ($claim['worker_id'] ?? '');
            $claimed = is_array($claim['claimed_files'] ?? null) ? $claim['claimed_files'] : [];
            $overlap = array_intersect($allowedFiles, $claimed);
            if ($overlap !== []) {
                $reasons[] = 'file_collision_with_worker:'.$wid.':'.implode(',', array_values($overlap));
                $hints[] = 'wait_for_worker_'.$wid.'_to_release_claim_or_split_task';
            }
        }

        return $this->build('no_collision', $reasons, $hints);
    }

    /** @param list<string> $criteria @return array<string, mixed> */
    private function checkRunnableProof(array $criteria): array
    {
        if ($criteria === []) {
            return $this->build('runnable_proof', ['acceptance_criteria_empty'], ['add_at_least_one_runnable_test_or_gate_to_criteria']);
        }

        foreach ($criteria as $c) {
            $cl = strtolower((string) $c);
            foreach (self::RUNNABLE_SIGNALS as $signal) {
                if (str_contains($cl, $signal)) {
                    return $this->build('runnable_proof', [], []);
                }
            }
        }

        return $this->build('runnable_proof',
            ['no_runnable_test_or_gate_in_acceptance_criteria'],
            ['add_a_runnable_phpunit_test_or_artisan_gate_to_acceptance_criteria']);
    }

    /** @return array<string, mixed> */
    private function checkEnoughContext(string $objective): array
    {
        $reasons = [];
        $hints = [];

        if (strlen($objective) < 20) {
            $reasons[] = 'objective_too_short_to_act_on';
            $hints[] = 'expand_objective_to_describe_what_capability_atlas_gains';
        }

        $lower = strtolower($objective);
        foreach (self::VAGUE_OBJECTIVE_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                $reasons[] = 'objective_is_vague:'.$pattern;
                $hints[] = 'replace_vague_objective_with_specific_capability_statement';
                break;
            }
        }

        return $this->build('enough_context', $reasons, $hints);
    }

    /** @return array<string, mixed> */
    private function checkBoundedRisk(string $riskLevel, string $objective): array
    {
        $reasons = [];
        $hints = [];

        if (! in_array($riskLevel, ['low', 'medium'], true)) {
            $reasons[] = 'risk_level_is_'.$riskLevel.'_requires_operator_gate';
            $hints[] = 'escalate_to_operator_before_queueing_critical_or_unknown_risk_task';
        }

        $lower = strtolower($objective);
        foreach (self::OPERATOR_OBJECTIVE_PATTERNS as $pat) {
            if (str_contains($lower, $pat)) {
                $reasons[] = 'objective_requires_operator_intervention:'.$pat;
                $hints[] = 'redesign_task_to_complete_autonomously_or_escalate_to_operator';
                break;
            }
        }

        return $this->build('bounded_risk', $reasons, $hints);
    }

    /** @param list<string> $evidence @return array<string, mixed> */
    private function checkClearGiveBackPath(array $evidence): array
    {
        if ($evidence !== []) {
            return $this->build('clear_give_back_path', [], []);
        }

        return $this->build('clear_give_back_path',
            ['required_evidence_empty_worker_cannot_prove_completion'],
            ['add_required_evidence_fields_eg_tests_or_gates_result']);
    }

    /**
     * @param array<string, bool> $implementedFiles
     * @param list<array<string, mixed>> $checks
     */
    private function classifyFailure(
        bool $isDuplicate,
        array $allowedFiles,
        array $implementedFiles,
        string $riskLevel,
        string $objective,
        array $checks,
    ): string {
        // already_implemented wins first.
        if ($isDuplicate) {
            return self::CATEGORY_ALREADY_IMPLEMENTED;
        }
        foreach ($allowedFiles as $f) {
            if (isset($implementedFiles[$f])) {
                return self::CATEGORY_ALREADY_IMPLEMENTED;
            }
        }

        // operator_only: critical risk or objective explicitly requires human.
        if ($riskLevel === 'critical') {
            return self::CATEGORY_OPERATOR_ONLY;
        }
        $lower = strtolower($objective);
        foreach (self::OPERATOR_OBJECTIVE_PATTERNS as $pat) {
            if (str_contains($lower, $pat)) {
                return self::CATEGORY_OPERATOR_ONLY;
            }
        }

        return self::CATEGORY_REPAIRABLE;
    }

    /**
     * Assess worker-level readiness dimensions from optional worker facts.
     * Missing facts → deficiency, not ready (no optimistic declarations allowed).
     *
     * @param  array<string,mixed>  $spec
     * @return array{scope_discipline:array<string,mixed>, runnable_proof_support:array<string,mixed>, give_back_hygiene:array<string,mixed>, current_load:array<string,mixed>, verdict:string}
     */
    private function assessWorkerReadiness(array $spec): array
    {
        // scope_discipline
        $sd = is_array($spec['scope_discipline_facts'] ?? null) ? $spec['scope_discipline_facts'] : null;
        $sdDef = [];
        if ($sd === null) {
            $sdDef[] = 'scope_discipline_evidence_missing';
        } else {
            $totalTasks = max(1, (int) ($sd['total_tasks'] ?? 0));
            $incidents = (int) ($sd['out_of_scope_incidents'] ?? 0);
            if (($incidents / $totalTasks) > 0.2) {
                $sdDef[] = 'scope_discipline_out_of_scope_rate_too_high';
            }
        }
        $scopeDiscipline = ['ready' => $sdDef === [], 'deficiency_codes' => $sdDef];

        // runnable_proof_support
        $rp = is_array($spec['runnable_proof_facts'] ?? null) ? $spec['runnable_proof_facts'] : null;
        $rpDef = [];
        if ($rp === null) {
            $rpDef[] = 'runnable_proof_support_evidence_missing';
        } elseif (! (bool) ($rp['can_run_phpunit'] ?? false) && ! (bool) ($rp['can_run_artisan'] ?? false)) {
            $rpDef[] = 'runnable_proof_support_no_test_runner_available';
        }
        $runnableProof = ['ready' => $rpDef === [], 'deficiency_codes' => $rpDef];

        // give_back_hygiene
        $gb = is_array($spec['give_back_hygiene_facts'] ?? null) ? $spec['give_back_hygiene_facts'] : null;
        $gbDef = [];
        if ($gb === null) {
            $gbDef[] = 'give_back_hygiene_evidence_missing';
        } elseif (isset($gb['give_back_rate']) && (float) $gb['give_back_rate'] > 0.5) {
            $gbDef[] = 'give_back_hygiene_rate_too_high';
        }
        $giveBack = ['ready' => $gbDef === [], 'deficiency_codes' => $gbDef];

        // current_load
        $cl = is_array($spec['current_load_facts'] ?? null) ? $spec['current_load_facts'] : null;
        $clDef = [];
        if ($cl === null) {
            $clDef[] = 'current_load_evidence_missing';
        } elseif ((int) ($cl['active_tasks'] ?? 0) >= max(1, (int) ($cl['capacity'] ?? 1))) {
            $clDef[] = 'current_load_worker_at_or_over_capacity';
        }
        $currentLoad = ['ready' => $clDef === [], 'deficiency_codes' => $clDef];

        $allReady = $scopeDiscipline['ready'] && $runnableProof['ready'] && $giveBack['ready'] && $currentLoad['ready'];

        return [
            'scope_discipline'       => $scopeDiscipline,
            'runnable_proof_support' => $runnableProof,
            'give_back_hygiene'      => $giveBack,
            'current_load'           => $currentLoad,
            'verdict'                => $allReady ? 'ready' : 'not_ready',
        ];
    }

    /** @param list<string> $reasons @param list<string> $hints @return array<string, mixed> */
    private function build(string $name, array $reasons, array $hints): array
    {
        return [
            'check' => $name,
            'passed' => $reasons === [],
            'reason' => $reasons === [] ? '' : implode('; ', $reasons),
            'hints' => $hints,
        ];
    }
}
