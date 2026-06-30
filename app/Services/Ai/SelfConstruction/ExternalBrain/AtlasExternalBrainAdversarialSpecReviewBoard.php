<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Deterministic adversarial review board — critiques a candidate task spec through seven cheap
 * structural lenses before enqueue (high-stakes originator mode).
 *
 * LENSES (each returns passed bool + reasons + repair_hints):
 *   1. implementability      — can a worker act on this spec without human guesses?
 *   2. leverage              — does this produce structural Atlas capability, not one-off polish?
 *   3. anti_proxy            — is the objective real work, not a metric/count/cleanup proxy?
 *   4. collision_safety      — safe for parallel workers, no wildcard or contested file patterns?
 *   5. steady_state_autonomy — can Atlas execute this fully autonomously (no human dependency)?
 *   6. evidence_strength     — is required_evidence concrete/runnable, not vague assurance?
 *   7. duplicate_objective_shape — is the objective structurally distinct from queued specs
 *                                  (token-overlap similarity), not a cosmetic rewording?
 *
 * The board does NOT mutate, enqueue, call providers, or rely on model judgment. Pure heuristics.
 * A task is APPROVED only when all seven lenses pass AND risk_score stays below the ceiling.
 *
 * risk_score: sum of per-lens weights for every FAILED lens (capped at 100). hard_blockers lists
 * the failed lenses whose weight marks them as high-severity (implementability, anti_proxy,
 * collision_safety); other failures are repairable soft findings.
 *
 * INPUT spec:
 *   { task_packet_id:string, objective:string, allowed_files:list<string>,
 *     acceptance_criteria:list<string>, required_evidence?:list<string>,
 *     unblocked_by?:list<string> }
 *
 * OUTPUT:
 *   { schema, task_packet_id, approved:bool, risk_score:int, lens_results:list<LensResult>,
 *     repair_hints:list<string>, hard_blockers:list<string>, enqueue_recommendation:string }
 *
 * LensResult: { lens:string, passed:bool, reasons:list<string>, repair_hints:list<string> }
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasExternalBrainAdversarialSpecReviewBoard
{
    public const SCHEMA = 'atlas.external_brain.adversarial_spec_review_board.v1';

    public const LENS_IMPLEMENTABILITY = 'implementability';

    public const LENS_LEVERAGE = 'leverage';

    public const LENS_ANTI_PROXY = 'anti_proxy';

    public const LENS_COLLISION_SAFETY = 'collision_safety';

    public const LENS_STEADY_STATE_AUTONOMY = 'steady_state_autonomy';

    public const LENS_EVIDENCE_STRENGTH = 'evidence_strength';

    public const LENS_DUPLICATE_OBJECTIVE_SHAPE = 'duplicate_objective_shape';

    private const LENS_WEIGHTS = [
        self::LENS_IMPLEMENTABILITY => 20,
        self::LENS_LEVERAGE => 15,
        self::LENS_ANTI_PROXY => 20,
        self::LENS_COLLISION_SAFETY => 20,
        self::LENS_STEADY_STATE_AUTONOMY => 15,
        self::LENS_EVIDENCE_STRENGTH => 15,
        self::LENS_DUPLICATE_OBJECTIVE_SHAPE => 10,
    ];

    private const HARD_LENSES = [
        self::LENS_IMPLEMENTABILITY,
        self::LENS_ANTI_PROXY,
        self::LENS_COLLISION_SAFETY,
    ];

    private const APPROVAL_RISK_CEILING = 50;

    private const STOPWORDS = ['a', 'an', 'the', 'to', 'of', 'for', 'so', 'that', 'and', 'with', 'from', 'into', 'is', 'on', 'in'];

    /**
     * @param  array{
     *   task_packet_id?:string,
     *   objective?:string,
     *   allowed_files?:list<string>,
     *   acceptance_criteria?:list<string>,
     *   required_evidence?:list<string>,
     *   unblocked_by?:list<string>,
     * }  $spec
     * @return array{schema:string, task_packet_id:string, approved:bool, lens_results:list<array<string,mixed>>, repair_hints:list<string>}
     */
    public function review(array $spec): array
    {
        $id = (string) ($spec['task_packet_id'] ?? '');
        $objective = (string) ($spec['objective'] ?? '');
        $allowedFiles = is_array($spec['allowed_files'] ?? null) ? array_values($spec['allowed_files']) : [];
        $criteria = is_array($spec['acceptance_criteria'] ?? null) ? $spec['acceptance_criteria'] : [];
        $evidence = is_array($spec['required_evidence'] ?? null) ? $spec['required_evidence'] : [];
        $liveQueuedTargets   = is_array($spec['live_queued_targets']   ?? null) ? array_values($spec['live_queued_targets'])   : [];
        $knownSpecObjectives = is_array($spec['known_spec_objectives'] ?? null) ? array_values($spec['known_spec_objectives']) : [];

        $lenses = [
            $this->implementability($allowedFiles, $criteria, $evidence),
            $this->leverage($objective, $criteria, $allowedFiles),
            $this->antiProxy($objective, $allowedFiles, $criteria),
            $this->collisionSafety($allowedFiles, $liveQueuedTargets, $objective, $knownSpecObjectives),
            $this->steadyStateAutonomy($objective, $criteria),
            $this->evidenceStrength($evidence),
            $this->duplicateObjectiveShape($objective, $knownSpecObjectives),
        ];

        $allLensesPassed = array_reduce($lenses, static fn (bool $carry, array $l): bool => $carry && $l['passed'], true);
        $repairHints = array_values(array_unique(array_merge(...array_column($lenses, 'repair_hints'))));

        $riskScore = 0;
        $hardBlockers = [];
        foreach ($lenses as $l) {
            if ($l['passed']) {
                continue;
            }
            $riskScore += self::LENS_WEIGHTS[$l['lens']] ?? 10;
            if (in_array($l['lens'], self::HARD_LENSES, true)) {
                $hardBlockers[] = $l['lens'];
            }
        }
        $riskScore = min(100, $riskScore);

        $approved = $allLensesPassed && $riskScore < self::APPROVAL_RISK_CEILING;

        $enqueueRecommendation = match (true) {
            $approved => 'enqueue',
            $hardBlockers !== [] => 'reject_and_repair_hard_blockers',
            default => 'repair_soft_findings_then_resubmit',
        };

        return [
            'schema' => self::SCHEMA,
            'task_packet_id' => $id,
            'approved' => $approved,
            'risk_score' => $riskScore,
            'lens_results' => $lenses,
            'repair_hints' => $repairHints,
            'hard_blockers' => $hardBlockers,
            'enqueue_recommendation' => $enqueueRecommendation,
        ];
    }

    /** @param list<string> $evidence */
    private function evidenceStrength(array $evidence): array
    {
        $reasons = [];
        $hints = [];

        if ($evidence === []) {
            $reasons[] = 'required_evidence_empty';
            $hints[] = 'add_required_evidence_fields_eg_tests_or_gates_result';

            return $this->lens(self::LENS_EVIDENCE_STRENGTH, $reasons, $hints);
        }

        $vaguePhrases = ['tests pass', 'looks good', 'works fine', 'should be fine', 'seems ok', 'it works'];
        $strongMarkers = ['phpunit', 'artisan', 'pytest', 'jest', 'rspec', 'gate', 'ledger', 'receipt', 'bin/php'];

        $hasStrong = false;
        foreach ($evidence as $e) {
            $lower = strtolower((string) $e);
            foreach ($strongMarkers as $marker) {
                if (str_contains($lower, $marker)) {
                    $hasStrong = true;
                    break;
                }
            }
            foreach ($vaguePhrases as $vague) {
                if (str_contains($lower, $vague)) {
                    $reasons[] = 'evidence_entry_is_vague:'.$vague;
                    $hints[] = 'replace_vague_assurance_with_a_runnable_test_command_or_auditable_gate_reference';
                    break;
                }
            }
        }

        if (! $hasStrong) {
            $reasons[] = 'no_runnable_or_auditable_evidence_present';
            $hints[] = 'evidence_must_reference_a_runnable_test_gate_or_auditable_ledger_entry';
        }

        return $this->lens(self::LENS_EVIDENCE_STRENGTH, array_values(array_unique($reasons)), array_values(array_unique($hints)));
    }

    /** @param list<string> $knownSpecObjectives */
    private function duplicateObjectiveShape(string $objective, array $knownSpecObjectives): array
    {
        $reasons = [];
        $hints = [];

        if ($objective === '' || $knownSpecObjectives === []) {
            return $this->lens(self::LENS_DUPLICATE_OBJECTIVE_SHAPE, $reasons, $hints);
        }

        $objectiveTokens = $this->shapeTokens($objective);
        foreach ($knownSpecObjectives as $known) {
            $known = (string) $known;
            if (strtolower(trim($known)) === strtolower(trim($objective))) {
                // Exact duplicate is already handled by collision_safety; skip here.
                continue;
            }

            $knownTokens = $this->shapeTokens($known);
            if ($objectiveTokens === [] || $knownTokens === []) {
                continue;
            }

            $similarity = $this->jaccardSimilarity($objectiveTokens, $knownTokens);
            if ($similarity >= 0.6) {
                $reasons[] = 'objective_shape_near_duplicate_of_existing_spec:'.$known;
                $hints[] = 'reword_objective_to_target_a_materially_different_capability_or_merge_with_existing_spec';
                break;
            }
        }

        return $this->lens(self::LENS_DUPLICATE_OBJECTIVE_SHAPE, $reasons, $hints);
    }

    /** @return list<string> */
    private function shapeTokens(string $text): array
    {
        $clean = (string) preg_replace('/[^a-z0-9\s]/', ' ', strtolower($text));
        $words = array_filter(preg_split('/\s+/', trim($clean)) ?: [], static fn (string $w): bool => $w !== '' && ! in_array($w, self::STOPWORDS, true));

        return array_values(array_unique($words));
    }

    /** @param list<string> $a @param list<string> $b */
    private function jaccardSimilarity(array $a, array $b): float
    {
        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $union === 0 ? 0.0 : $intersection / $union;
    }

    /** @param list<string> $allowedFiles @param list<string> $criteria @param list<string> $evidence */
    private function implementability(array $allowedFiles, array $criteria, array $evidence): array
    {
        $reasons = [];
        $hints = [];

        if ($allowedFiles === []) {
            $reasons[] = 'allowed_files_empty';
            $hints[] = 'add_at_least_one_impl_file_to_allowed_files';
        }

        foreach ($allowedFiles as $f) {
            if (! str_contains((string) $f, '.') && ! str_contains((string) $f, '*')) {
                $reasons[] = 'bare_directory_in_allowed_files:'.(string) $f;
                $hints[] = 'expand_bare_directory_to_explicit_file_paths';
            }
        }

        if ($criteria === []) {
            $reasons[] = 'acceptance_criteria_empty';
            $hints[] = 'add_at_least_one_falsifiable_acceptance_criterion';
        }

        if ($evidence === []) {
            $reasons[] = 'required_evidence_empty';
            $hints[] = 'add_required_evidence_fields_eg_tests_or_gates_result';
        }

        return $this->lens(self::LENS_IMPLEMENTABILITY, $reasons, $hints);
    }

    /** @param list<string> $criteria @param list<string> $allowedFiles */
    private function leverage(string $objective, array $criteria, array $allowedFiles = []): array
    {
        $reasons = [];
        $hints = [];
        $lower = strtolower($objective);

        // Template-placeholder detection: generic scaffolding text signals an uninstantiated template.
        $templateSignals = ['[service_name]', '[class_name]', '[placeholder]', '{{', '}}',
            'your_class_name', 'example_task', 'todo: fill', 'your service here'];
        foreach ($templateSignals as $tmpl) {
            if (str_contains($lower, $tmpl)) {
                $reasons[] = 'objective_contains_template_placeholder:' . $tmpl;
                $hints[] = 'replace_template_placeholder_with_concrete_atlas_specific_implementation_target';
                break;
            }
        }

        $proxyOnlyVerbs = ['remove whitespace', 'fix typo', 'rename variable', 'sort imports', 'reformat'];
        foreach ($proxyOnlyVerbs as $proxy) {
            if (str_contains($lower, $proxy)) {
                $reasons[] = 'objective_is_cosmetic_proxy:'.$proxy;
                $hints[] = 'replace_cosmetic_objective_with_structural_capability_statement';
            }
        }

        $capabilityWords = ['implement', 'add', 'build', 'create', 'integrate', 'wire', 'certify',
            'detect', 'emit', 'route', 'classify', 'generate', 'upgrade', 'enable', 'expose'];
        $hasCapability = false;
        foreach ($capabilityWords as $word) {
            if (str_contains($lower, $word)) {
                $hasCapability = true;
                break;
            }
        }
        if (! $hasCapability) {
            $reasons[] = 'objective_lacks_capability_verb';
            $hints[] = 'state_what_atlas_can_do_after_this_task_ships_using_a_capability_verb';
        }

        // Criteria should mention a measurable outcome.
        $measurableWords = ['test', 'assert', 'verify', 'prove', 'green', 'pass', 'exit', 'return', 'emit', 'count', '>'];
        $hasMeasurable = false;
        foreach ($criteria as $c) {
            $cl = strtolower((string) $c);
            foreach ($measurableWords as $mw) {
                if (str_contains($cl, $mw)) {
                    $hasMeasurable = true;
                    break 2;
                }
            }
        }
        if ($criteria !== [] && ! $hasMeasurable) {
            $reasons[] = 'acceptance_criteria_lack_measurable_outcome';
            $hints[] = 'add_a_runnable_test_or_runtime_gate_to_acceptance_criteria';
        }

        // AC2: runnable criteria (containing --filter=) must mention implementation target or capability outcome.
        $implTargets = [];
        foreach ($allowedFiles as $f) {
            if (! str_ends_with((string) $f, 'Test.php')) {
                $implTargets[] = pathinfo(basename((string) $f), PATHINFO_FILENAME);
            }
        }
        $capabilityWords = ['return', 'assert', 'prove', 'detect', 'emit', 'output', 'exits', 'expect', 'when', 'yield'];
        $hasFilterCriterion  = false;
        $hasCapabilityOrTarget = false;
        foreach ($criteria as $c) {
            $cl = strtolower((string) $c);
            if (str_contains($cl, '--filter=')) {
                $hasFilterCriterion = true;
            }
            foreach ($capabilityWords as $word) {
                if (str_contains($cl, $word)) {
                    $hasCapabilityOrTarget = true;
                    break;
                }
            }
            // Strip --filter=... so target names inside the filter value don't count.
            $criterionBody = (string) preg_replace('/--filter=\S+/', '', (string) $c);
            foreach ($implTargets as $target) {
                if (str_contains($criterionBody, $target)) {
                    $hasCapabilityOrTarget = true;
                    break;
                }
            }
        }
        if ($hasFilterCriterion && ! $hasCapabilityOrTarget) {
            $reasons[] = 'runnable_criteria_lack_implementation_target_or_capability_outcome';
            $hints[] = 'runnable_test_criteria_must_name_the_service_class_or_describe_what_it_proves';
        }

        return $this->lens(self::LENS_LEVERAGE, $reasons, $hints);
    }

    /** @param list<string> $allowedFiles @param list<string> $criteria */
    private function antiProxy(string $objective, array $allowedFiles, array $criteria): array
    {
        $reasons = [];
        $hints = [];
        $lower = strtolower($objective);

        $proxySignals = ['count how many', 'report on', 'measure the', 'log the number of', 'track metrics'];
        foreach ($proxySignals as $signal) {
            if (str_contains($lower, $signal)) {
                $reasons[] = 'objective_is_metric_proxy:'.$signal;
                $hints[] = 'replace_metric_reporting_objective_with_capability_that_uses_the_metric';
            }
        }

        // If allowed_files has only test files → no impl → proxy risk.
        if ($allowedFiles !== []) {
            $implFiles = array_filter($allowedFiles, static fn (string $f): bool => ! str_ends_with($f, 'Test.php'));
            if ($implFiles === []) {
                $reasons[] = 'allowed_files_contain_only_test_files_no_impl';
                $hints[] = 'add_the_production_implementation_file_to_allowed_files';
            }
        }

        // Cleanup-only patterns in criteria.
        $cleanupPatterns = ['remove dead code', 'delete unused', 'fix formatting', 'reformat'];
        foreach ($criteria as $c) {
            $cl = strtolower((string) $c);
            foreach ($cleanupPatterns as $cp) {
                if (str_contains($cl, $cp)) {
                    $reasons[] = 'acceptance_criteria_is_cleanup_proxy:'.$cp;
                    $hints[] = 'acceptance_criteria_must_prove_capability_gain_not_code_cleanup';
                    break;
                }
            }
        }

        return $this->lens(self::LENS_ANTI_PROXY, $reasons, $hints);
    }

    /** @param list<string> $allowedFiles @param list<string> $liveQueuedTargets @param list<string> $knownSpecObjectives */
    private function collisionSafety(array $allowedFiles, array $liveQueuedTargets = [], string $objective = '', array $knownSpecObjectives = []): array
    {
        $reasons = [];
        $hints = [];

        // Duplicate-objective detection: same objective already queued → block to avoid twin tasks.
        if ($objective !== '' && $knownSpecObjectives !== []) {
            $normalizedObjective = strtolower(trim($objective));
            foreach ($knownSpecObjectives as $known) {
                if (strtolower(trim((string) $known)) === $normalizedObjective) {
                    $reasons[] = 'duplicate_objective_already_in_queue';
                    $hints[] = 'differentiate_objective_from_existing_queued_task_or_merge_with_it';
                    break;
                }
            }
        }

        foreach ($allowedFiles as $f) {
            $f = (string) $f;
            if (str_contains($f, '*')) {
                $reasons[] = 'wildcard_in_allowed_files:'.$f;
                $hints[] = 'expand_wildcard_to_explicit_file_list_for_safe_parallel_assignment';
            }
            if (str_contains($f, '..')) {
                $reasons[] = 'path_traversal_in_allowed_files:'.$f;
                $hints[] = 'use_absolute_repo_relative_paths_without_traversal';
            }
        }

        // Warn if the spec touches high-contention shared infrastructure.
        $highContention = ['AppServiceProvider.php', 'routes/api.php', 'routes/web.php',
            'config/atlas.php', 'bootstrap/app.php'];
        foreach ($allowedFiles as $f) {
            foreach ($highContention as $hot) {
                if (str_ends_with((string) $f, $hot)) {
                    $reasons[] = 'high_contention_file_requires_coordination:'.$hot;
                    $hints[] = 'check_active_claims_before_queuing_tasks_touching_'.str_replace('.', '_', $hot);
                    break;
                }
            }
        }

        // AC1: fail if any allowed_files path is already live in the queue.
        foreach ($allowedFiles as $f) {
            if (in_array((string) $f, $liveQueuedTargets, true)) {
                $reasons[] = 'file_already_live_in_queue:'.(string) $f;
                $hints[] = 'wait_for_active_task_claiming_this_file_to_complete_before_queuing';
            }
        }

        return $this->lens(self::LENS_COLLISION_SAFETY, $reasons, $hints);
    }

    /** @param list<string> $criteria */
    private function steadyStateAutonomy(string $objective, array $criteria): array
    {
        $reasons = [];
        $hints = [];
        $lower = strtolower($objective);

        $humanSignals = ['operator approval', 'human review', 'manual step', 'ask the user',
            'requires approval', 'wait for', 'ping on slack', 'send email'];
        foreach ($humanSignals as $signal) {
            if (str_contains($lower, $signal)) {
                $reasons[] = 'objective_requires_human_intervention:'.$signal;
                $hints[] = 'redesign_task_so_it_completes_without_human_input_in_steady_state';
            }
        }

        foreach ($criteria as $c) {
            $cl = strtolower((string) $c);
            foreach ($humanSignals as $signal) {
                if (str_contains($cl, $signal)) {
                    $reasons[] = 'acceptance_criterion_requires_human_step:'.$signal;
                    $hints[] = 'replace_human_gated_criterion_with_automated_gate_or_observable_evidence';
                    break;
                }
            }
        }

        $externalSignals = ['external api', 'third-party', 'vendor api', 'requires internet',
            'manually run', 'ssh into'];
        foreach ([$lower] as $text) {
            foreach ($externalSignals as $signal) {
                if (str_contains($text, $signal)) {
                    $reasons[] = 'objective_has_external_provider_dependency:'.$signal;
                    $hints[] = 'gate_external_dependency_behind_a_provider-free_local_stub_for_autonomy';
                    break;
                }
            }
        }

        return $this->lens(self::LENS_STEADY_STATE_AUTONOMY, $reasons, $hints);
    }

    /** @param list<string> $reasons @param list<string> $hints @return array{lens:string, passed:bool, reasons:list<string>, repair_hints:list<string>} */
    private function lens(string $name, array $reasons, array $hints): array
    {
        return [
            'lens' => $name,
            'passed' => $reasons === [],
            'reasons' => array_values($reasons),
            'repair_hints' => array_values($hints),
        ];
    }
}
