<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Decides whether a task is ready to be sent to worker muscles (Claude/Codex/Cursor/native).
 *
 * Eleven readiness checks (all must pass for ready=true):
 *   scoped_files                    — allowed_files non-empty, no bare dirs, no wildcards, no path traversal.
 *   no_test_only_packet             — allowed_files must contain at least one non-test implementation file.
 *   implementation_plus_test_scope  — allowed_files must contain at least one impl AND one test file.
 *   no_collision                    — allowed_files don't overlap with any active claim's claimed_files.
 *   runnable_proof                  — at least one acceptance criterion mentions a runnable test or gate.
 *   enough_context                  — objective non-empty and long enough to act on (>= 20 chars); not vague.
 *   concrete_symbol_presence        — objective references at least one concrete code symbol (PascalCase class, ::method, artisan:cmd).
 *   acceptance_contradiction_risk   — acceptance criteria must not contain contradictory assertions.
 *   bounded_risk                    — risk_level is 'low' or 'medium' (critical tasks need operator gate).
 *   clear_give_back_path            — required_evidence non-empty (worker knows how to prove done/give_back).
 *   give_back_escape_hatch          — spec tells the worker when to give_back (give_back_condition | give_back_on_blocked | max_attempts).
 *   worker_capability_fit           — when worker_capabilities is supplied, at least one entry must support task_family/risk_level/model_tier.
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
 *     active_claims?:list<{worker_id:string, claimed_files:list<string>}>,
 *     give_back_condition?:string, give_back_on_blocked?:bool, max_attempts?:int }
 *
 * OUTPUT:
 *   { schema, task_packet_id, ready:bool, readiness_category:string,
 *     failure_category:string|null, give_back_risk_score:float,
 *     blocking_deficiencies:list<string>, checks:list<Check>, repair_hints:list<string> }
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

    /** Negation markers in acceptance criteria that signal a contradiction. */
    private const CONTRADICTION_NEGATIVE = ['must not', 'should not', 'must fail', 'should fail', 'never ', 'cannot '];

    /** Positive assertion markers in acceptance criteria. */
    private const CONTRADICTION_POSITIVE = ['must pass', 'must return', 'must be ', 'should pass', 'should return', 'should be ', 'always '];

    /**
     * @param  array{
     *   task_packet_id?:string, objective?:string, allowed_files?:list<string>,
     *   acceptance_criteria?:list<string>, required_evidence?:list<string>,
     *   risk_level?:string, duplicate?:bool, implemented_files?:list<string>,
     *   active_claims?:list<array{worker_id:string, claimed_files:list<string>}>,
     *   give_back_condition?:string, give_back_on_blocked?:bool, max_attempts?:int
     * }  $spec
     * @return array{schema:string, task_packet_id:string, ready:bool, readiness_category:string, failure_category:string|null, give_back_risk_score:float, blocking_deficiencies:list<string>, checks:list<array<string,mixed>>, repair_hints:list<string>}
     */
    public function check(array $spec): array
    {
        $id               = (string) ($spec['task_packet_id'] ?? '');
        $objective        = (string) ($spec['objective'] ?? '');
        $allowedFiles     = is_array($spec['allowed_files'] ?? null) ? array_values(array_filter(array_map('strval', $spec['allowed_files']))) : [];
        $criteria         = is_array($spec['acceptance_criteria'] ?? null) ? $spec['acceptance_criteria'] : [];
        $evidence         = is_array($spec['required_evidence'] ?? null) ? $spec['required_evidence'] : [];
        $riskLevel        = strtolower(trim((string) ($spec['risk_level'] ?? 'low')));
        $isDuplicate      = (bool) ($spec['duplicate'] ?? false);
        $implementedFiles = is_array($spec['implemented_files'] ?? null) ? array_flip(array_map('strval', $spec['implemented_files'])) : [];
        $activeClaims     = is_array($spec['active_claims'] ?? null) ? $spec['active_claims'] : [];
        $taskFamily       = (string) ($spec['task_family'] ?? '');
        $modelTier        = (string) ($spec['model_tier'] ?? '');
        $workerCapabilities = is_array($spec['worker_capabilities'] ?? null) ? $spec['worker_capabilities'] : [];

        $checks = [
            $this->checkScopedFiles($allowedFiles),
            $this->checkNoTestOnlyPacket($allowedFiles),
            $this->checkImplementationPlusTestScope($allowedFiles),
            $this->checkNoCollision($allowedFiles, $activeClaims),
            $this->checkRunnableProof($criteria),
            $this->checkEnoughContext($objective),
            $this->checkConcreteSymbolPresence($objective),
            $this->checkAcceptanceContradictionRisk($criteria),
            $this->checkBoundedRisk($riskLevel, $objective),
            $this->checkClearGiveBackPath($evidence),
            $this->checkGiveBackEscapeHatch($spec),
            $this->checkWorkerCapabilityFit($taskFamily, $riskLevel, $modelTier, $workerCapabilities),
        ];

        $result = $this->assembleCheckResult($checks);

        $failureCategory = null;
        if (! $result['ready']) {
            $failureCategory = $this->classifyFailure($isDuplicate, $allowedFiles, $implementedFiles, $riskLevel, $objective, $checks);
        }

        return [
            'schema'                => self::SCHEMA,
            'task_packet_id'        => $id,
            'ready'                 => $result['ready'],
            'readiness_category'    => $this->deriveReadinessCategory($result['ready'], $failureCategory, $checks),
            'failure_category'      => $failureCategory,
            'give_back_risk_score'  => $result['give_back_risk_score'],
            'blocking_deficiencies' => $result['blocking_deficiencies'],
            'checks'                => $result['checks'],
            'repair_hints'          => $result['repair_hints'],
            'worker_readiness'      => $this->assessWorkerReadiness($spec),
            'worker_contract_summary' => $result['ready']
                ? $this->buildWorkerContractSummary($spec, $allowedFiles)
                : null,
        ];
    }

    /**
     * Single pass over the raw check results: derives ready, blocking_deficiencies,
     * give_back_risk_score, repair_hints, and the stripped check output from ONE
     * shared list of failed checks — so a multi-failure packet cannot show a
     * blocking deficiency that isn't reflected in the risk score, or vice versa.
     *
     * @param  list<array<string,mixed>>  $checks
     * @return array{ready:bool, blocking_deficiencies:list<string>, give_back_risk_score:float, repair_hints:list<string>, checks:list<array<string,mixed>>}
     */
    private function assembleCheckResult(array $checks): array
    {
        $riskWeights = [
            'no_test_only_packet'            => 0.30,
            'runnable_proof'                 => 0.25,
            'implementation_plus_test_scope' => 0.20,
            'clear_give_back_path'           => 0.20,
            'acceptance_contradiction_risk'  => 0.15,
            'concrete_symbol_presence'       => 0.15,
            'give_back_escape_hatch'         => 0.10,
            'enough_context'                 => 0.10,
            'worker_capability_fit'          => 0.20,
        ];

        $ready = true;
        $blockingDeficiencies = [];
        $riskScore = 0.0;
        $hints = [];
        $checkOutput = [];

        foreach ($checks as $check) {
            $checkOutput[] = ['check' => $check['check'], 'passed' => $check['passed'], 'reason' => $check['reason']];
            $hints = array_merge($hints, (array) ($check['hints'] ?? []));

            if (! $check['passed']) {
                $ready = false;
                $blockingDeficiencies[] = $check['check'];
                if (isset($riskWeights[$check['check']])) {
                    $riskScore += $riskWeights[$check['check']];
                }
            }
        }

        return [
            'ready'                 => $ready,
            'blocking_deficiencies' => array_values($blockingDeficiencies),
            'give_back_risk_score'  => round(min(1.0, $riskScore), 4),
            'repair_hints'          => array_values(array_unique($hints)),
            'checks'                => $checkOutput,
        ];
    }

    /** @param list<string> $allowedFiles @return array<string, mixed> */
    private function checkScopedFiles(array $allowedFiles): array
    {
        $reasons = [];
        $hints   = [];

        if ($allowedFiles === []) {
            $reasons[] = 'allowed_files_empty';
            $hints[]   = 'add_at_least_one_implementation_file_to_allowed_files';
        }
        foreach ($allowedFiles as $f) {
            if (str_contains($f, '*')) {
                $reasons[] = 'wildcard_in_allowed_files';
                $hints[]   = 'expand_wildcard_to_explicit_file_paths';
            }
            if (str_contains($f, '..')) {
                $reasons[] = 'path_traversal_in_allowed_files';
                $hints[]   = 'use_repo_relative_paths_without_traversal';
            }
            if (! str_contains($f, '.') && ! str_contains($f, '*')) {
                $reasons[] = 'bare_directory_in_allowed_files:'.$f;
                $hints[]   = 'expand_directory_to_explicit_php_file_paths';
            }
        }

        return $this->build('scoped_files', $reasons, $hints);
    }

    /** Spec must not be test-only: at least one implementation file must be present. */
    private function checkNoTestOnlyPacket(array $allowedFiles): array
    {
        if ($allowedFiles === []) {
            return $this->build('no_test_only_packet', [], []);
        }

        foreach ($allowedFiles as $f) {
            if (! str_starts_with($f, 'tests/')) {
                return $this->build('no_test_only_packet', [], []);
            }
        }

        return $this->build(
            'no_test_only_packet',
            ['all_allowed_files_are_test_files_no_implementation_file_present'],
            ['add_the_implementation_file_to_allowed_files_alongside_its_test'],
        );
    }

    /** Spec must scope both an implementation file AND a test file. */
    private function checkImplementationPlusTestScope(array $allowedFiles): array
    {
        if ($allowedFiles === []) {
            return $this->build('implementation_plus_test_scope', [], []);
        }

        $hasImpl = false;
        $hasTest = false;

        foreach ($allowedFiles as $f) {
            if (str_starts_with($f, 'tests/')) {
                $hasTest = true;
            } else {
                $hasImpl = true;
            }
        }

        $reasons = [];
        $hints   = [];

        if (! $hasImpl) {
            $reasons[] = 'no_implementation_file_in_allowed_files';
            $hints[]   = 'add_the_implementation_php_class_file_to_allowed_files';
        }
        if (! $hasTest) {
            $reasons[] = 'no_test_file_in_allowed_files';
            $hints[]   = 'add_the_corresponding_phpunit_test_file_to_allowed_files';
        }

        return $this->build('implementation_plus_test_scope', $reasons, $hints);
    }

    /**
     * @param list<string> $allowedFiles
     * @param list<array{worker_id:string, claimed_files:list<string>}> $activeClaims
     * @return array<string, mixed>
     */
    private function checkNoCollision(array $allowedFiles, array $activeClaims): array
    {
        $reasons = [];
        $hints   = [];

        if ($allowedFiles === []) {
            return $this->build('no_collision', [], []);
        }

        foreach ($activeClaims as $claim) {
            $wid     = (string) ($claim['worker_id'] ?? '');
            $claimed = is_array($claim['claimed_files'] ?? null) ? $claim['claimed_files'] : [];
            $overlap = array_intersect($allowedFiles, $claimed);
            if ($overlap !== []) {
                $reasons[] = 'file_collision_with_worker:'.$wid.':'.implode(',', array_values($overlap));
                $hints[]   = 'wait_for_worker_'.$wid.'_to_release_claim_or_split_task';
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
        $hints   = [];

        if (strlen($objective) < 20) {
            $reasons[] = 'objective_too_short_to_act_on';
            $hints[]   = 'expand_objective_to_describe_what_capability_atlas_gains';
        }

        $lower = strtolower($objective);
        foreach (self::VAGUE_OBJECTIVE_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                $reasons[] = 'objective_is_vague:'.$pattern;
                $hints[]   = 'replace_vague_objective_with_specific_capability_statement';
                break;
            }
        }

        return $this->build('enough_context', $reasons, $hints);
    }

    /** Objective must contain at least one concrete code symbol (PascalCase class, ::method, artisan cmd). */
    private function checkConcreteSymbolPresence(string $objective): array
    {
        // PascalCase compound: e.g. AtlasDriftDetector (capital → lower → capital)
        if (preg_match('/[A-Z][a-z]+[A-Z][a-zA-Z0-9]+/', $objective) === 1) {
            return $this->build('concrete_symbol_presence', [], []);
        }

        // Static method reference: e.g. AtlasFoo::bar
        if (preg_match('/[A-Z][a-zA-Z0-9]+::/', $objective) === 1) {
            return $this->build('concrete_symbol_presence', [], []);
        }

        // Artisan command: e.g. "php artisan atlas:task" or "artisan atlas:"
        if (preg_match('/\bartisan[: ]+[a-z]/i', $objective) === 1) {
            return $this->build('concrete_symbol_presence', [], []);
        }

        return $this->build(
            'concrete_symbol_presence',
            ['no_concrete_code_symbol_in_objective'],
            ['name_the_specific_class_method_or_artisan_command_the_task_must_touch'],
        );
    }

    /**
     * Detects contradictory assertions within individual acceptance criteria.
     * A contradiction is detected when a single criterion contains both a positive
     * assertion marker and a negation marker that logically conflict.
     */
    private function checkAcceptanceContradictionRisk(array $criteria): array
    {
        foreach ($criteria as $criterion) {
            $lower = strtolower((string) $criterion);

            $hasNegation = false;
            foreach (self::CONTRADICTION_NEGATIVE as $neg) {
                if (str_contains($lower, $neg)) {
                    $hasNegation = true;
                    break;
                }
            }

            if (! $hasNegation) {
                continue;
            }

            $hasPositive = false;
            foreach (self::CONTRADICTION_POSITIVE as $pos) {
                if (str_contains($lower, $pos)) {
                    $hasPositive = true;
                    break;
                }
            }

            if ($hasPositive) {
                return $this->build(
                    'acceptance_contradiction_risk',
                    ['criterion_contains_both_positive_and_negative_assertion: '.substr($criterion, 0, 80)],
                    ['split_into_separate_positive_and_negative_criteria_or_remove_the_contradiction'],
                );
            }
        }

        return $this->build('acceptance_contradiction_risk', [], []);
    }

    /** @return array<string, mixed> */
    private function checkBoundedRisk(string $riskLevel, string $objective): array
    {
        $reasons = [];
        $hints   = [];

        if (! in_array($riskLevel, ['low', 'medium'], true)) {
            $reasons[] = 'risk_level_is_'.$riskLevel.'_requires_operator_gate';
            $hints[]   = 'escalate_to_operator_before_queueing_critical_or_unknown_risk_task';
        }

        $lower = strtolower($objective);
        foreach (self::OPERATOR_OBJECTIVE_PATTERNS as $pat) {
            if (str_contains($lower, $pat)) {
                $reasons[] = 'objective_requires_operator_intervention:'.$pat;
                $hints[]   = 'redesign_task_to_complete_autonomously_or_escalate_to_operator';
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
     * Spec must tell the worker WHEN to give_back (not just what to prove when done).
     * Passes if: give_back_condition string is set, OR give_back_on_blocked=true,
     * OR max_attempts > 0 is provided.
     */
    private function checkGiveBackEscapeHatch(array $spec): array
    {
        $condition = trim((string) ($spec['give_back_condition'] ?? ''));
        if ($condition !== '') {
            return $this->build('give_back_escape_hatch', [], []);
        }

        if ((bool) ($spec['give_back_on_blocked'] ?? false)) {
            return $this->build('give_back_escape_hatch', [], []);
        }

        if ((int) ($spec['max_attempts'] ?? 0) > 0) {
            return $this->build('give_back_escape_hatch', [], []);
        }

        return $this->build(
            'give_back_escape_hatch',
            ['no_give_back_stopping_criterion_in_spec'],
            [
                'add_give_back_condition_string_explaining_when_to_give_back',
                'or_set_give_back_on_blocked_true_or_max_attempts_integer',
            ],
        );
    }

    /**
     * When worker_capabilities is supplied, at least one capability entry must support
     * the task's task_family, risk_level, and model_tier. Empty list = no constraint, passes.
     *
     * @param list<array<string, mixed>> $workerCapabilities
     */
    private function checkWorkerCapabilityFit(string $taskFamily, string $riskLevel, string $modelTier, array $workerCapabilities): array
    {
        if ($workerCapabilities === []) {
            return $this->build('worker_capability_fit', [], []);
        }

        foreach ($workerCapabilities as $cap) {
            $families = is_array($cap['task_families'] ?? null) ? $cap['task_families'] : [];
            $risks    = is_array($cap['risk_levels'] ?? null) ? $cap['risk_levels'] : [];
            $tiers    = is_array($cap['model_tiers'] ?? null) ? $cap['model_tiers'] : [];

            $familyOk = $taskFamily === '' || in_array($taskFamily, $families, true);
            $riskOk   = $riskLevel === '' || in_array($riskLevel, $risks, true);
            $tierOk   = $modelTier === '' || in_array($modelTier, $tiers, true);

            if ($familyOk && $riskOk && $tierOk) {
                return $this->build('worker_capability_fit', [], []);
            }
        }

        return $this->build(
            'worker_capability_fit',
            ['no_worker_capability_matches_task_family_risk_level_model_tier'],
            ['route_to_a_worker_capability_that_supports_the_task_family_risk_level_and_model_tier'],
        );
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
        if ($isDuplicate) {
            return self::CATEGORY_ALREADY_IMPLEMENTED;
        }
        foreach ($allowedFiles as $f) {
            if (isset($implementedFiles[$f])) {
                return self::CATEGORY_ALREADY_IMPLEMENTED;
            }
        }

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

    /** @param list<array<string,mixed>> $checks */
    private function deriveReadinessCategory(bool $ready, ?string $failureCategory, array $checks): string
    {
        if ($ready) {
            return 'ready';
        }

        if ($failureCategory === self::CATEGORY_ALREADY_IMPLEMENTED) {
            return 'already_implemented';
        }
        if ($failureCategory === self::CATEGORY_OPERATOR_ONLY) {
            return 'operator_gate_required';
        }

        $map = [
            'scoped_files'                   => 'missing_scope',
            'no_test_only_packet'            => 'test_only_packet',
            'implementation_plus_test_scope' => 'missing_impl_or_test_file',
            'no_collision'                   => 'file_collision',
            'runnable_proof'                 => 'missing_runnable_proof',
            'enough_context'                 => 'vague_objective',
            'concrete_symbol_presence'       => 'vague_objective',
            'acceptance_contradiction_risk'  => 'contradiction_risk',
            'bounded_risk'                   => 'operator_gate_required',
            'clear_give_back_path'           => 'missing_evidence',
            'give_back_escape_hatch'         => 'no_escape_hatch',
            'worker_capability_fit'          => 'no_matching_worker_capability',
        ];

        foreach ($checks as $check) {
            if (! $check['passed'] && isset($map[$check['check']])) {
                return $map[$check['check']];
            }
        }

        return 'repairable_spec_defect';
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
        $sd    = is_array($spec['scope_discipline_facts'] ?? null) ? $spec['scope_discipline_facts'] : null;
        $sdDef = [];
        if ($sd === null) {
            $sdDef[] = 'scope_discipline_evidence_missing';
        } else {
            $totalTasks = max(1, (int) ($sd['total_tasks'] ?? 0));
            $incidents  = (int) ($sd['out_of_scope_incidents'] ?? 0);
            if (($incidents / $totalTasks) > 0.2) {
                $sdDef[] = 'scope_discipline_out_of_scope_rate_too_high';
            }
        }
        $scopeDiscipline = ['ready' => $sdDef === [], 'deficiency_codes' => $sdDef];

        // runnable_proof_support
        $rp    = is_array($spec['runnable_proof_facts'] ?? null) ? $spec['runnable_proof_facts'] : null;
        $rpDef = [];
        if ($rp === null) {
            $rpDef[] = 'runnable_proof_support_evidence_missing';
        } elseif (! (bool) ($rp['can_run_phpunit'] ?? false) && ! (bool) ($rp['can_run_artisan'] ?? false)) {
            $rpDef[] = 'runnable_proof_support_no_test_runner_available';
        }
        $runnableProof = ['ready' => $rpDef === [], 'deficiency_codes' => $rpDef];

        // give_back_hygiene
        $gb    = is_array($spec['give_back_hygiene_facts'] ?? null) ? $spec['give_back_hygiene_facts'] : null;
        $gbDef = [];
        if ($gb === null) {
            $gbDef[] = 'give_back_hygiene_evidence_missing';
        } elseif (isset($gb['give_back_rate']) && (float) $gb['give_back_rate'] > 0.5) {
            $gbDef[] = 'give_back_hygiene_rate_too_high';
        }
        $giveBack = ['ready' => $gbDef === [], 'deficiency_codes' => $gbDef];

        // current_load
        $cl    = is_array($spec['current_load_facts'] ?? null) ? $spec['current_load_facts'] : null;
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
            'check'  => $name,
            'passed' => $reasons === [],
            'reason' => $reasons === [] ? '' : implode('; ', $reasons),
            'hints'  => $hints,
        ];
    }

    /**
     * Build a concise worker contract summary for ready candidates.
     *
     * @param  array<string, mixed>  $spec
     * @param  list<string>  $allowedFiles
     * @return array{objective:string, allowed_files:list<string>, required_evidence:list<string>, give_back_condition:string, risk_level:string, task_family:string}|null
     */
    private function buildWorkerContractSummary(array $spec, array $allowedFiles): ?array
    {
        return [
            'objective'         => (string) ($spec['objective'] ?? ''),
            'allowed_files'     => $allowedFiles,
            'required_evidence' => is_array($spec['required_evidence'] ?? null) ? $spec['required_evidence'] : [],
            'give_back_condition' => (string) ($spec['give_back_condition'] ?? ''),
            'risk_level'        => (string) ($spec['risk_level'] ?? 'low'),
            'task_family'       => (string) ($spec['task_family'] ?? ''),
        ];
    }
}
