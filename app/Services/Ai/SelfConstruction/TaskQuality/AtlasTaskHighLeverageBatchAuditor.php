<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Non-petrified batch auditor for brain-seeded task specs.
 *
 * audit(specs) evaluates a batch as a whole and returns:
 *   - creditable (bool)        : false when any anti-proxy pattern fires
 *   - anti_proxy_facts (list)  : one fact record per detected pattern
 *   - remediation_hints (list) : actionable human-readable strings
 *
 * Detected patterns (each fires independently; creditable=false if any fire):
 *   - homogeneous_dormant_cli_arm      : ≥50% of specs have dormant_cli_arm_proxy=true
 *   - singleton_microtest_batch        : batch is exactly 1 spec with test_only_has_contract=true
 *   - duplicate_target_family          : 2+ specs share the exact same allowed_files set
 *   - thin_quota_farming               : ≥50% of specs have objectives under MIN_WORD_COUNT words
 *                                        or match known thin-farming phrases
 *   - low_diversity_leverage_class     : ≥80% of specs map to the same leverage class (batch ≥ 3)
 *   - non_self_sufficient_spec         : any spec has packet_quality.self_sufficient=false
 *
 * Pure: no I/O, no DB, no providers, no queue writes.
 */
final class AtlasTaskHighLeverageBatchAuditor
{
    public const SCHEMA = 'atlas.task_quality.high_leverage_batch_auditor.v1';

    private const DORMANT_ARM_RATIO = 0.5;

    private const LOW_DIVERSITY_RATIO = 0.8;

    private const THIN_FARMING_RATIO = 0.5;

    private const MIN_WORD_COUNT = 8;

    private const MIN_BATCH_FOR_DIVERSITY = 3;

    /** @var array<string,list<string>> */
    private const LEVERAGE_CLASS_KEYWORDS = [
        'bug_fix'             => ['fix', 'bug', 'repair', 'patch', 'correct'],
        'gate_hardening'      => ['gate', 'guard', 'harden', 'certif', 'verif'],
        'runtime_integration' => ['wire', 'wiring', 'integrat', 'connect', 'register'],
        'docs_sync'           => ['doc', 'wiki', 'readme', 'journal', 'canon'],
        'refactor'            => ['refactor', 'restructure', 'reorganiz'],
        'test_authoring'      => ['test', 'spec', 'contract', 'assert'],
        'feature'             => ['implement', 'creat', 'add', 'build', 'extend'],
        'research'            => ['research', 'investigat', 'explor', 'discover', 'survey'],
    ];

    private const THIN_FARMING_PHRASES = [
        'add return type', 'add type hint', 'add phpdoc', 'add docblock',
        'remove trailing', 'fix whitespace', 'add blank line', 'add null check',
    ];

    private const RUNNABLE_ACCEPTANCE_TOKENS = ['test', 'artisan', 'php ', 'runs ', 'executes '];

    private const SIMPLIFICATION_KEYWORDS = ['simplif', 'dedupe', 'dedup', 'consolidat', 'reduce complexity', 'remove duplicate'];

    private const RISK_REDUCTION_KEYWORDS = ['harden', 'guard', 'regression', 'safety', 'fail-closed', 'fail closed', 'prevent'];

    /** AC4: patterns that indict the whole batch's composition, not one salvageable spec — need rewrite. */
    private const BATCH_WIDE_PATTERNS = [
        'homogeneous_dormant_cli_arm',
        'singleton_microtest_batch',
        'thin_quota_farming',
        'low_diversity_leverage_class',
        'duplicate_target_family',
        'semantic_near_duplicate_template_farm',
        'single_family_volume',
        'worker_coverage_insufficient',
        'missing_batch_value_proof',
    ];

    /**
     * @param  list<array<string,mixed>>  $specs  raw task packet arrays
     * @param  array<string,mixed>  $batchContext  optional batch-level facts:
     *         target_claimable_floor : int  minimum specs the batch must replenish
     *         active_worker_count    : int  workers currently drawing from the queue
     * @return array{schema:string, creditable:bool, anti_proxy_facts:list<array<string,mixed>>, remediation_hints:list<string>}
     */
    public function audit(array $specs, array $batchContext = []): array
    {
        if ($specs === []) {
            return $this->result(true, [], []);
        }

        $total = count($specs);
        $antiProxy = [];
        $hints = [];

        // 1. Homogeneous dormant CLI arm
        $dormantCount = count(array_filter($specs, static fn (array $s): bool =>
            (bool) ($s['packet_quality']['facts']['dormant_cli_arm_proxy'] ?? false)));
        if ($dormantCount / $total >= self::DORMANT_ARM_RATIO) {
            $antiProxy[] = [
                'pattern' => 'homogeneous_dormant_cli_arm',
                'dormant_count' => $dormantCount,
                'total' => $total,
                'ratio' => round($dormantCount / $total, 2),
            ];
            $hints[] = "Remove or wire the {$dormantCount} dormant CLI arm wrapper(s) before submitting as a creditable batch.";
        }

        // 2. Singleton characterisation microtest
        if ($total === 1 && (bool) ($specs[0]['packet_quality']['facts']['test_only_has_contract'] ?? false)) {
            $antiProxy[] = [
                'pattern' => 'singleton_microtest_batch',
                'spec_id' => (string) ($specs[0]['task_packet_id'] ?? ''),
            ];
            $hints[] = 'A single test-only characterisation spec is not a creditable batch; pair it with an implementation spec.';
        }

        // 3. Duplicate target families
        $targetCounts = [];
        foreach ($specs as $s) {
            $files = is_array($s['allowed_files'] ?? null) ? (array) $s['allowed_files'] : [];
            sort($files, SORT_STRING);
            $key = implode('|', $files);
            if ($key !== '') {
                $targetCounts[$key] = ($targetCounts[$key] ?? 0) + 1;
            }
        }
        $duplicateSets = array_filter($targetCounts, static fn (int $c): bool => $c >= 2);
        if ($duplicateSets !== []) {
            $antiProxy[] = [
                'pattern' => 'duplicate_target_family',
                'duplicate_file_sets' => array_keys($duplicateSets),
                'collision_counts' => array_values($duplicateSets),
            ];
            $hints[] = 'Multiple specs share the same allowed_files set; consolidate or ensure distinct scopes.';
        }

        // 4. Thin quota farming
        $thinCount = 0;
        foreach ($specs as $s) {
            $obj = strtolower(trim((string) ($s['objective'] ?? '')));
            $wordCount = count(array_filter(preg_split('/\s+/', $obj) ?: []));
            $thin = $wordCount < self::MIN_WORD_COUNT;
            if (! $thin) {
                foreach (self::THIN_FARMING_PHRASES as $phrase) {
                    if (str_contains($obj, $phrase)) {
                        $thin = true;
                        break;
                    }
                }
            }
            if ($thin) {
                $thinCount++;
            }
        }
        if ($thinCount / $total >= self::THIN_FARMING_RATIO) {
            $antiProxy[] = [
                'pattern' => 'thin_quota_farming',
                'thin_count' => $thinCount,
                'total' => $total,
            ];
            $hints[] = "Batch has {$thinCount}/{$total} thin objectives (proxy metrics, not real compounding work).";
        }

        // 5. Low-diversity leverage classes (only meaningful for batches ≥ 3)
        if ($total >= self::MIN_BATCH_FOR_DIVERSITY) {
            $classCounts = [];
            foreach ($specs as $s) {
                $class = $this->leverageClass((string) ($s['objective'] ?? ''));
                $classCounts[$class] = ($classCounts[$class] ?? 0) + 1;
            }
            $topCount = max($classCounts);
            if ($topCount / $total >= self::LOW_DIVERSITY_RATIO) {
                $topClass = (string) array_search($topCount, $classCounts, true);
                $antiProxy[] = [
                    'pattern' => 'low_diversity_leverage_class',
                    'dominant_class' => $topClass,
                    'dominant_count' => $topCount,
                    'total' => $total,
                ];
                $hints[] = "Batch is {$topCount}/{$total} '{$topClass}' specs; diversify across leverage classes for real compounding.";
            }
        }

        // 5b. AC2: single-family volume — a batch of meaningful size that concentrates every spec's
        // allowed_files in one top-level directory family. Unlike worker_coverage_insufficient
        // (pattern 10), this fires unconditionally, even when the caller supplies no batchContext,
        // so raw batch size alone can never disguise single-family volume as real diversity.
        if ($total >= self::MIN_BATCH_FOR_DIVERSITY) {
            $unconditionalFamilies = [];
            foreach ($specs as $s) {
                $files = is_array($s['allowed_files'] ?? null) ? array_map('strval', (array) $s['allowed_files']) : [];
                $family = $files !== [] ? (dirname($files[0]) ?: 'unknown') : 'unknown';
                $unconditionalFamilies[$family] = true;
            }
            if (count($unconditionalFamilies) <= 1) {
                $antiProxy[] = [
                    'pattern' => 'single_family_volume',
                    'family' => (string) array_key_first($unconditionalFamilies),
                    'total' => $total,
                ];
                $hints[] = "All {$total} specs concentrate allowed_files in a single family; volume alone is not diversity — spread across distinct capability areas.";
            }
        }

        // 6. Non-self-sufficient spec
        foreach ($specs as $s) {
            if (($s['packet_quality']['self_sufficient'] ?? null) === false) {
                $antiProxy[] = [
                    'pattern' => 'non_self_sufficient_spec',
                    'spec_id' => (string) ($s['task_packet_id'] ?? ''),
                ];
                $hints[] = 'One or more specs carry blocking deficiencies (not self-sufficient); fix before crediting.';
                break;
            }
        }

        // 7. Semantic near-duplicate objectives / template-farm variants — fires even when allowed_files
        // differ, since the template-farm trick is renaming files/classes while reusing the same skeleton.
        $bySkeleton = [];
        foreach ($specs as $s) {
            $skeleton = $this->skeleton((string) ($s['objective'] ?? ''));
            if ($skeleton === '') {
                continue;
            }
            $bySkeleton[$skeleton][] = (string) ($s['task_packet_id'] ?? '');
        }
        foreach ($bySkeleton as $skeleton => $ids) {
            if (count($ids) >= 2) {
                $antiProxy[] = [
                    'pattern' => 'semantic_near_duplicate_template_farm',
                    'shared_skeleton' => $skeleton,
                    'spec_ids' => $ids,
                ];
                $hints[] = 'Specs '.implode(', ', $ids)." share the same objective template (\"{$skeleton}\") with only names/paths changed; give each spec a genuinely distinct objective.";
            }
        }

        // 8. Low implementability: missing implementation+test pair, no runnable acceptance, or no
        // required evidence — a spec a worker cannot actually prove.
        foreach ($specs as $s) {
            $files = is_array($s['allowed_files'] ?? null) ? array_map('strval', (array) $s['allowed_files']) : [];
            $isDocOnly = $files !== [] && count($files) === count(array_filter($files, static fn (string $f): bool => str_starts_with($f, 'docs/')));
            $hasTestFile = (bool) array_filter($files, static fn (string $f): bool => str_starts_with($f, 'tests/') || str_ends_with($f, 'Test.php'));
            $hasImplFile = (bool) array_filter($files, static fn (string $f): bool => ! str_starts_with($f, 'tests/') && ! str_ends_with($f, 'Test.php'));
            $criteria = is_array($s['acceptance_criteria'] ?? null) ? array_map('strval', (array) $s['acceptance_criteria']) : [];
            $hasRunnable = $this->hasRunnableAcceptance($criteria);
            $requiredEvidence = is_array($s['required_evidence'] ?? null) ? (array) $s['required_evidence'] : [];

            $reasons = [];
            if (! $isDocOnly && (! $hasTestFile || ! $hasImplFile)) {
                $reasons[] = 'missing_implementation_or_test_pair';
            }
            if (! $hasRunnable) {
                $reasons[] = 'no_runnable_acceptance_criterion';
            }
            if ($requiredEvidence === []) {
                $reasons[] = 'no_required_evidence';
            }

            if ($reasons !== []) {
                $antiProxy[] = [
                    'pattern' => 'low_implementability_spec',
                    'spec_id' => (string) ($s['task_packet_id'] ?? ''),
                    'reasons' => $reasons,
                ];
                $hints[] = 'Spec '.((string) ($s['task_packet_id'] ?? '')).' is not implementable as written: '.implode(', ', $reasons).'.';
            }
        }

        // 9. Weak acceptance criteria — empty, or every criterion is exit-code-only with no behavior assertion.
        foreach ($specs as $s) {
            $criteria = is_array($s['acceptance_criteria'] ?? null) ? array_map('strval', (array) $s['acceptance_criteria']) : [];
            if ($criteria === [] || $this->allCriteriaWeak($criteria)) {
                $antiProxy[] = [
                    'pattern' => 'weak_acceptance_criteria',
                    'spec_id' => (string) ($s['task_packet_id'] ?? ''),
                ];
                $hints[] = 'Spec '.((string) ($s['task_packet_id'] ?? '')).' has only exit-code-only or empty acceptance criteria; add a concrete behavior assertion.';
            }
        }

        // 10. Worker coverage: only meaningful when the caller supplies replenishment targets. A batch
        // that passes every per-spec gate can still fail to feed workers if it's too small for demand,
        // or if every spec concentrates in a single allowed_files family (one worker can claim it all,
        // the rest starve).
        $workerCoverage = null;
        $requiredCoverage = max(
            max(0, (int) ($batchContext['target_claimable_floor'] ?? 0)),
            max(0, (int) ($batchContext['active_worker_count'] ?? 0)),
        );
        if ($requiredCoverage > 0) {
            $families = [];
            foreach ($specs as $s) {
                $files = is_array($s['allowed_files'] ?? null) ? array_map('strval', (array) $s['allowed_files']) : [];
                $family = $files !== [] ? (dirname($files[0]) ?: 'unknown') : 'unknown';
                $families[$family] = true;
            }
            $distinctFamilies = count($families);
            $belowFloor = $total < $requiredCoverage;
            $concentrated = $total > 1 && $distinctFamilies <= 1;
            $satisfied = ! $belowFloor && ! $concentrated;
            $gap = $belowFloor ? ($requiredCoverage - $total) : 0;

            $workerCoverage = [
                'required_coverage' => $requiredCoverage,
                'total_specs' => $total,
                'distinct_families' => $distinctFamilies,
                'satisfied' => $satisfied,
            ];

            if (! $satisfied) {
                $antiProxy[] = [
                    'pattern' => 'worker_coverage_insufficient',
                    'worker_coverage_gap' => $gap,
                    'distinct_families' => $distinctFamilies,
                    'required_coverage' => $requiredCoverage,
                    'total' => $total,
                    'concentrated_in_single_family' => $concentrated,
                ];
                $hints[] = $belowFloor
                    ? "Batch supplies {$total} specs but worker demand requires at least {$requiredCoverage}; add {$gap} more creditable specs."
                    : 'All specs concentrate allowed_files in a single family; diversify so multiple workers can claim in parallel.';
            }
        }

        // AC2: missing_batch_value_proof — at least one non-doc spec must carry explicit
        // value_proof or impact_trace evidence, otherwise the batch looks green but hollow.
        // Doc-only batches are implicitly exempt since their evidence is acceptance-runnable
        // documentation, not code-level proof.
        $hasValueProof = false;
        $hasNonDocSpec = false;
        foreach ($specs as $s) {
            $files = is_array($s['allowed_files'] ?? null) ? array_map('strval', (array) $s['allowed_files']) : [];
            $isDocOnly = $files !== [] && count($files) === count(array_filter($files, static fn (string $f): bool => str_starts_with($f, 'docs/')));
            if ($isDocOnly) {
                continue; // doc-only specs are exempt
            }
            $hasNonDocSpec = true;
            if (
                (bool) ($s['value_proof'] ?? false)
                || (bool) ($s['impact_trace'] ?? false)
                || (is_array($s['packet_quality']['facts']['impact_trace'] ?? null) && $s['packet_quality']['facts']['impact_trace'] !== [])
            ) {
                $hasValueProof = true;
                break;
            }
        }
        // If all specs are doc-only, the gate is implicitly satisfied.
        if (! $hasNonDocSpec) {
            $hasValueProof = true;
        }
        if (! $hasValueProof) {
            $antiProxy[] = [
                'pattern' => 'missing_batch_value_proof',
                'total' => $total,
            ];
            $hints[] = 'No non-doc spec carries value_proof or impact_trace; add concrete value evidence to at least one spec.';
        }

        // AC3: reward distinct capability lift, simplification, risk reduction, downstream
        // unlocks and runnable proof — a batch is scored on what it ADVANCES, not just what it
        // avoids being penalized for.
        $leverageRewards = $this->leverageRewards($specs, $total);

        // AC4: accept / trim / rewrite / reject, plus concrete reasons per weak task.
        $decision = $this->decisionFor($antiProxy, $total);
        $weakTaskReasons = $this->weakTaskReasons($antiProxy);

        return $this->result($antiProxy === [], $antiProxy, $hints, $workerCoverage, $leverageRewards, $decision, $weakTaskReasons);
    }

    /**
     * @param  list<array<string,mixed>>  $specs
     * @return array{distinct_capability_classes:int, simplification_count:int, risk_reduction_count:int, downstream_unlocks_count:int, runnable_proof_count:int, reward_score:float}
     */
    private function leverageRewards(array $specs, int $total): array
    {
        $classes = [];
        $simplificationCount = 0;
        $riskReductionCount = 0;
        $downstreamUnlocksCount = 0;
        $runnableProofCount = 0;

        foreach ($specs as $s) {
            $objective = strtolower((string) ($s['objective'] ?? ''));
            $classes[$this->leverageClass($objective)] = true;

            foreach (self::SIMPLIFICATION_KEYWORDS as $kw) {
                if (str_contains($objective, $kw)) {
                    $simplificationCount++;
                    break;
                }
            }
            foreach (self::RISK_REDUCTION_KEYWORDS as $kw) {
                if (str_contains($objective, $kw)) {
                    $riskReductionCount++;
                    break;
                }
            }
            $unlocks = is_array($s['unlocks'] ?? null) ? array_filter((array) $s['unlocks']) : [];
            if ($unlocks !== []) {
                $downstreamUnlocksCount++;
            }
            $criteria = is_array($s['acceptance_criteria'] ?? null) ? array_map('strval', (array) $s['acceptance_criteria']) : [];
            if ($this->hasRunnableAcceptance($criteria)) {
                $runnableProofCount++;
            }
        }

        $distinctCapabilityClasses = count($classes);
        $rewardScore = $total > 0 ? round((
            ($distinctCapabilityClasses / count(self::LEVERAGE_CLASS_KEYWORDS)) * 0.30
            + ($simplificationCount / $total) * 0.15
            + ($riskReductionCount / $total) * 0.15
            + ($downstreamUnlocksCount / $total) * 0.15
            + ($runnableProofCount / $total) * 0.25
        ), 4) : 0.0;

        return [
            'distinct_capability_classes' => $distinctCapabilityClasses,
            'simplification_count' => $simplificationCount,
            'risk_reduction_count' => $riskReductionCount,
            'downstream_unlocks_count' => $downstreamUnlocksCount,
            'runnable_proof_count' => $runnableProofCount,
            'reward_score' => $rewardScore,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $antiProxy
     */
    private function decisionFor(array $antiProxy, int $total): string
    {
        if ($antiProxy === []) {
            return 'accept';
        }

        $hasBatchWidePattern = array_filter(
            $antiProxy,
            static fn (array $f): bool => in_array((string) ($f['pattern'] ?? ''), self::BATCH_WIDE_PATTERNS, true),
        ) !== [];
        if ($hasBatchWidePattern) {
            return 'rewrite';
        }

        $weakSpecCount = count($this->weakTaskReasons($antiProxy));
        if ($weakSpecCount > 0 && $weakSpecCount < $total) {
            return 'trim';
        }

        return 'reject';
    }

    /**
     * AC4: maps each weak task's spec_id to the concrete pattern(s) that flagged it.
     *
     * @param  list<array<string,mixed>>  $antiProxy
     * @return array<string,list<string>>
     */
    private function weakTaskReasons(array $antiProxy): array
    {
        $reasons = [];
        foreach ($antiProxy as $fact) {
            $pattern = (string) ($fact['pattern'] ?? '');
            if (isset($fact['spec_id']) && $fact['spec_id'] !== '') {
                $reasons[(string) $fact['spec_id']][] = $pattern;
            }
            if (isset($fact['spec_ids']) && is_array($fact['spec_ids'])) {
                foreach ($fact['spec_ids'] as $specId) {
                    $reasons[(string) $specId][] = $pattern;
                }
            }
        }

        return $reasons;
    }

    /**
     * @param  list<array<string,mixed>>  $antiProxy
     * @param  list<string>  $hints
     * @param  array<string,mixed>|null  $workerCoverage
     * @param  array<string,mixed>  $leverageRewards
     * @param  array<string,list<string>>  $weakTaskReasons
     * @return array{schema:string, creditable:bool, anti_proxy_facts:list<array<string,mixed>>, remediation_hints:list<string>}
     */
    private function result(
        bool $creditable,
        array $antiProxy,
        array $hints,
        ?array $workerCoverage = null,
        array $leverageRewards = [],
        string $decision = 'accept',
        array $weakTaskReasons = [],
    ): array {
        $out = [
            'schema' => self::SCHEMA,
            'creditable' => $creditable,
            'anti_proxy_facts' => array_values($antiProxy),
            'remediation_hints' => array_values($hints),
            // AC3: frontier_floor_passed/batch_hints alias the same signal under the
            // acceptance-criteria vocabulary, additive alongside creditable/remediation_hints.
            'frontier_floor_passed' => $creditable,
            'batch_hints' => array_values($hints),
            'leverage_rewards' => $leverageRewards,
            'decision' => $decision,
            'weak_task_reasons' => $weakTaskReasons,
        ];

        if ($workerCoverage !== null) {
            $out['worker_coverage'] = $workerCoverage;
        }

        return $out;
    }

    /**
     * Lowercases and replaces concrete class-name-looking tokens and numbers with placeholders, so two
     * objectives that differ only by a class name or a number collapse to the same template skeleton.
     */
    private function skeleton(string $text): string
    {
        $withoutIdentifiers = preg_replace('/\b[A-Z][A-Za-z0-9]{2,}\b/', '<ID>', $text) ?? $text;
        $withoutNumbers = preg_replace('/\b\d+\b/', '<NUM>', $withoutIdentifiers) ?? $withoutIdentifiers;

        return trim(preg_replace('/\s+/', ' ', strtolower($withoutNumbers)) ?? '');
    }

    /**
     * @param  list<string>  $criteria
     */
    private function hasRunnableAcceptance(array $criteria): bool
    {
        foreach ($criteria as $criterion) {
            $haystack = strtolower($criterion);
            foreach (self::RUNNABLE_ACCEPTANCE_TOKENS as $token) {
                if (str_contains($haystack, $token)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $criteria
     */
    private function allCriteriaWeak(array $criteria): bool
    {
        foreach ($criteria as $criterion) {
            $haystack = strtolower($criterion);
            $isExitCodeOnly = (bool) preg_match('/\bexit(s|ed)?\s*(code\s*)?0\b/', $haystack);
            $hasBehaviorWord = (bool) preg_match('/\b(returns|produces|contains|rejects|blocks|throws|fails|admits|denies|refuses)\b/', $haystack);
            if (! $isExitCodeOnly || $hasBehaviorWord) {
                return false;
            }
        }

        return true;
    }

    private function leverageClass(string $objective): string
    {
        $lower = strtolower($objective);
        foreach (self::LEVERAGE_CLASS_KEYWORDS as $class => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($lower, $kw)) {
                    return $class;
                }
            }
        }

        return 'unclassified';
    }
}
