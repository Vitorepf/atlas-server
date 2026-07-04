<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Compiles one originator run into actionable retrospective data.
 *
 * Each run produces outcomes (success, give_back, rejected, proxy_smell).
 * This compiler turns that raw signal into:
 *   - integrity_signal  — honest | degraded | padding_detected
 *   - summary           — counts and ratios
 *   - high_leverage_specs — categories that delivered real value
 *   - wasted_specs        — specs/categories that burned tokens without value
 *   - lessons             — human-readable pattern findings
 *   - policy_adjustments  — next-cycle rules (avoid, prefer, reject)
 *   - next_cycle_hints    — MemoryWritebackContract-compatible proposals
 *
 * Separates "honest low-yield" (legitimate give_backs with real reasons) from
 * "bad prompt behavior" (contradictory acceptance, missing impl, padding).
 * Never rewards quota padding — padding_detected always surfaces as a lesson.
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainRunRetrospectiveCompiler
{
    public const SCHEMA = 'atlas.external_brain.run_retrospective_compiler.v1';

    public const OUTCOME_SUCCESS     = 'success';
    public const OUTCOME_GIVE_BACK   = 'give_back';
    public const OUTCOME_REJECTED    = 'rejected';
    public const OUTCOME_PROXY_SMELL = 'proxy_smell';

    public const SIGNAL_HONEST          = 'honest';
    public const SIGNAL_DEGRADED        = 'degraded';
    public const SIGNAL_PADDING_DETECTED = 'padding_detected';

    /** Give_back reasons that indicate honest low-yield (spec was reasonable but hard). */
    private const HONEST_REASONS = [
        'scope_too_large',
        'insufficient_context',
        'requires_human_decision',
        'blocked_by_dependency',
        'no_safe_implementation',
    ];

    /** Reasons that indicate bad prompt/policy behavior (spec authoring failure). */
    private const BAD_PROMPT_REASONS = [
        'missing_impl_file',
        'contradictory_acceptance',
        'unknown_poison_pattern',
        'blind_orphan_wiring',
    ];

    /** Padding-related poison patterns — quota gaming, never honest. */
    private const PADDING_PATTERNS = ['test_count_padding', 'low_variety', 'template_farm'];

    /**
     * Compile a list of run outcomes into a full retrospective.
     *
     * Each outcome may have:
     *   spec_id         string        (required)
     *   category        string        (optional, defaults to 'unknown')
     *   outcome         string        (required: success|give_back|rejected|proxy_smell)
     *   tokens_spent    int           (optional)
     *   reason          string        (optional — for non-success)
     *   leverage_score  float 0..1    (optional)
     *   poison_patterns string[]      (optional)
     *
     * @param  list<array<string,mixed>>  $outcomes
     * @param  array<string,mixed>        $options   run_id, quota
     * @return array<string,mixed>
     */
    public function compile(array $outcomes, array $options = []): array
    {
        $runId = (string) ($options['run_id'] ?? 'unknown');

        // Single outcome index: every section below reads from these pre-partitioned
        // lists instead of re-scanning $outcomes with its own outcome === '...' filter,
        // so success/give_back/proxy_smell counts cannot diverge across sections.
        $index = $this->buildOutcomeIndex($outcomes);

        $summary      = $this->buildSummary($outcomes, $index);
        $integrity    = $this->computeIntegrity($outcomes, $summary);
        $byCategory   = $this->groupByCategory($outcomes);
        $highLeverage = $this->highLeverageSpecs($index, $byCategory);
        $wasted       = $this->wastedSpecs($index, $byCategory);
        $lessons      = $this->buildLessons($outcomes, $index, $summary, $byCategory, $integrity);
        $rootCauseMap = $this->buildRootCauseMap($index);
        $policies     = $this->buildPolicyAdjustments($outcomes, $byCategory, $integrity, $rootCauseMap);
        $hints        = $this->buildNextCycleHints($summary, $byCategory, $integrity, $policies);
        $adjustments  = $this->buildNextCycleAdjustments($outcomes, $summary, $byCategory, $integrity, $rootCauseMap);

        return [
            'schema'                 => self::SCHEMA,
            'run_id'                 => $runId,
            'integrity_signal'       => $integrity,
            'summary'                => $summary,
            'high_leverage_specs'    => $highLeverage,
            'wasted_specs'           => $wasted,
            'lessons'                => $lessons,
            'root_cause_map'         => $rootCauseMap,
            'policy_adjustments'     => $policies,
            'next_cycle_hints'       => $hints,
            'next_cycle_adjustments' => $adjustments,
            'success_patterns'       => $this->successPatterns($index['success']),
            'give_back_roots'        => $this->giveBackRoots($index['give_back']),
            'cancellation_roots'     => $this->cancellationRoots($index['rejected']),
            'malformed_roots'        => $this->malformedRoots($outcomes),
            'collision_roots'        => $this->collisionRoots($outcomes),
            'queue_health_drift'     => $this->queueHealthDrift($summary),
            'next_batch_rules'       => $this->nextBatchRules($policies, $index, $summary),
        ];
    }

    /**
     * Single outcome index: pre-partitions the raw outcome list by outcome type once,
     * so every downstream section (summary, high-leverage specs, wasted specs, lessons,
     * root-cause map) reads the same success/give_back/proxy_smell/non_success sets
     * instead of running its own divergent outcome === '...' scan.
     *
     * @param  list<array<string,mixed>>  $outcomes
     * @return array{success:list<array<string,mixed>>, non_success:list<array<string,mixed>>, give_back:list<array<string,mixed>>, rejected:list<array<string,mixed>>, proxy_smell:list<array<string,mixed>>}
     */
    private function buildOutcomeIndex(array $outcomes): array
    {
        $index = [
            'success'     => [],
            'non_success' => [],
            'give_back'   => [],
            'rejected'    => [],
            'proxy_smell' => [],
        ];

        foreach ($outcomes as $o) {
            $type = (string) ($o['outcome'] ?? '');
            if ($type === self::OUTCOME_SUCCESS) {
                $index['success'][] = $o;
            } else {
                $index['non_success'][] = $o;
            }
            match ($type) {
                self::OUTCOME_GIVE_BACK   => $index['give_back'][] = $o,
                self::OUTCOME_REJECTED    => $index['rejected'][] = $o,
                self::OUTCOME_PROXY_SMELL => $index['proxy_smell'][] = $o,
                default                   => null,
            };
        }

        return $index;
    }

    /**
     * @param  list<array<string,mixed>>  $outcomes
     * @param  array<string,list<array<string,mixed>>>  $index
     */
    private function buildSummary(array $outcomes, array $index): array
    {
        $total          = count($outcomes);
        $successCount   = count($index['success']);
        $giveBackCount  = count($index['give_back']);
        $rejectedCount  = count($index['rejected']);
        $proxyCount     = count($index['proxy_smell']);
        $duplicateCount = 0;
        $totalTokens    = 0;
        $wastedTokens   = 0;

        foreach ($outcomes as $o) {
            $tokens  = (int) ($o['tokens_spent'] ?? 0);
            $reason  = (string) ($o['reason'] ?? '');
            $totalTokens += $tokens;

            if (str_contains($reason, 'duplicate')) {
                $duplicateCount++;
            }
        }
        foreach ([$index['give_back'], $index['rejected'], $index['proxy_smell']] as $group) {
            foreach ($group as $o) {
                $wastedTokens += (int) ($o['tokens_spent'] ?? 0);
            }
        }

        $yieldRate        = $total > 0 ? round($successCount / $total, 4) : 0.0;
        $wastedTokenRatio = $totalTokens > 0 ? round($wastedTokens / $totalTokens, 4) : 0.0;

        return [
            'total_outcomes'     => $total,
            'success_count'      => $successCount,
            'give_back_count'    => $giveBackCount,
            'rejected_count'     => $rejectedCount,
            'duplicate_count'    => $duplicateCount,
            'proxy_smell_count'  => $proxyCount,
            'yield_rate'         => $yieldRate,
            'total_tokens_spent' => $totalTokens,
            'wasted_token_ratio' => $wastedTokenRatio,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $outcomes
     * @param  array<string,mixed>        $summary
     */
    private function computeIntegrity(array $outcomes, array $summary): string
    {
        // Padding detected: any outcome carries a padding poison pattern,
        // or proxy_smell share > 30% of total.
        foreach ($outcomes as $o) {
            foreach ((array) ($o['poison_patterns'] ?? []) as $pattern) {
                if (in_array((string) $pattern, self::PADDING_PATTERNS, true)) {
                    return self::SIGNAL_PADDING_DETECTED;
                }
            }
        }

        $total = $summary['total_outcomes'];
        if ($total > 0 && ($summary['proxy_smell_count'] / $total) > 0.30) {
            return self::SIGNAL_PADDING_DETECTED;
        }

        if ($summary['yield_rate'] < 0.40 && $total >= 3) {
            return self::SIGNAL_DEGRADED;
        }

        return self::SIGNAL_HONEST;
    }

    /**
     * @param  list<array<string,mixed>>  $outcomes
     * @return array<string,array<string,mixed>>   category → stats
     */
    private function groupByCategory(array $outcomes): array
    {
        $groups = [];

        foreach ($outcomes as $o) {
            $cat     = (string) ($o['category'] ?? 'unknown');
            $outcome = (string) ($o['outcome'] ?? '');
            $tokens  = (int) ($o['tokens_spent'] ?? 0);

            if (! isset($groups[$cat])) {
                $groups[$cat] = ['success' => 0, 'give_back' => 0, 'rejected' => 0, 'proxy_smell' => 0, 'total' => 0, 'tokens' => 0, 'spec_ids' => []];
            }
            $groups[$cat]['total']++;
            $groups[$cat]['tokens'] += $tokens;
            $groups[$cat]['spec_ids'][] = (string) ($o['spec_id'] ?? '');

            if (isset($groups[$cat][$outcome])) {
                $groups[$cat][$outcome]++;
            }
        }

        return $groups;
    }

    /**
     * @param  array<string,list<array<string,mixed>>>  $index
     * @param  array<string,array<string,mixed>> $byCategory
     * @return list<array<string,mixed>>
     */
    private function highLeverageSpecs(array $index, array $byCategory): array
    {
        $result = [];

        // Explicit high-leverage: success outcomes with a high leverage_score
        foreach ($index['success'] as $o) {
            if (isset($o['leverage_score'])) {
                $score = (float) $o['leverage_score'];
                if ($score >= 0.70) {
                    $result[] = [
                        'spec_id'       => (string) ($o['spec_id'] ?? ''),
                        'category'      => (string) ($o['category'] ?? 'unknown'),
                        'leverage_score' => $score,
                    ];
                }
            }
        }

        // Category-level high yield (>= 70% success, >= 2 outcomes)
        foreach ($byCategory as $cat => $stats) {
            if ($stats['total'] >= 2) {
                $yieldRate = $stats['success'] / $stats['total'];
                if ($yieldRate >= 0.70) {
                    $result[] = ['category' => $cat, 'category_yield' => round($yieldRate, 4)];
                }
            }
        }

        // Sort by leverage_score desc for specs, category_yield desc for categories
        usort($result, static fn (array $a, array $b): int =>
            (int) (100 * (($b['leverage_score'] ?? $b['category_yield'] ?? 0) - ($a['leverage_score'] ?? $a['category_yield'] ?? 0)))
        );

        return $result;
    }

    /**
     * @param  array<string,list<array<string,mixed>>>  $index
     * @param  array<string,array<string,mixed>> $byCategory
     * @return list<array<string,mixed>>
     */
    private function wastedSpecs(array $index, array $byCategory): array
    {
        $result = [];

        // Proxy smells are always wasted
        foreach ($index['proxy_smell'] as $o) {
            $result[] = [
                'spec_id'  => (string) ($o['spec_id'] ?? ''),
                'category' => (string) ($o['category'] ?? 'unknown'),
                'reason'   => 'proxy_smell',
            ];
        }

        // Categories with > 60% give_back / rejected, ≥ 2 outcomes
        foreach ($byCategory as $cat => $stats) {
            if ($stats['total'] >= 2) {
                $failRate = ($stats['give_back'] + $stats['rejected']) / $stats['total'];
                if ($failRate > 0.60) {
                    $result[] = [
                        'category'  => $cat,
                        'fail_rate' => round($failRate, 4),
                        'reason'    => 'high_give_back_rate',
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * @param  list<array<string,mixed>>        $outcomes
     * @param  array<string,mixed>              $summary
     * @param  array<string,array<string,mixed>> $byCategory
     */
    private function buildLessons(array $outcomes, array $index, array $summary, array $byCategory, string $integrity): array
    {
        $lessons = [];

        if ($integrity === self::SIGNAL_PADDING_DETECTED) {
            $lessons[] = 'Integrity: quota padding detected (test_count_padding, low_variety, or proxy_smell >30%) — run signal is unreliable.';
        }

        if ($summary['yield_rate'] < 0.40 && $summary['total_outcomes'] >= 3) {
            $lessons[] = sprintf('Low overall yield (%.0f%%) — review spec quality and category selection.', $summary['yield_rate'] * 100);
        }

        // Bad prompt reasons across all outcomes
        $badPromptFound = [];
        foreach ($outcomes as $o) {
            $reason = (string) ($o['reason'] ?? '');
            if (in_array($reason, self::BAD_PROMPT_REASONS, true) && ! in_array($reason, $badPromptFound, true)) {
                $badPromptFound[] = $reason;
                $lessons[] = "Spec authoring failure detected: '{$reason}' — fix origination prompt to avoid recurrence.";
            }
        }

        // Category-level lessons
        foreach ($byCategory as $cat => $stats) {
            if ($stats['total'] < 2) {
                continue;
            }
            $yield = $stats['success'] / $stats['total'];
            if ($yield >= 0.70) {
                $lessons[] = "Category '{$cat}' delivers effectively (yield ".round($yield * 100)."%) — prefer in next cycle.";
            } elseif ($yield < 0.35) {
                $lessons[] = "Category '{$cat}' has low yield (".round($yield * 100)."%) — deprioritise or repair spec template.";
            }
        }

        // Honest low-yield insight
        $honestGiveBackCount = 0;
        foreach ($index['give_back'] as $o) {
            if (in_array((string) ($o['reason'] ?? ''), self::HONEST_REASONS, true)) {
                $honestGiveBackCount++;
            }
        }
        if ($honestGiveBackCount > 0) {
            $lessons[] = "Honest low-yield: {$honestGiveBackCount} give_back(s) with legitimate reasons (scope, context, blocking) — these reflect task complexity, not prompt quality issues.";
        }

        return $lessons;
    }

    /**
     * Map each non-success outcome to one of 5 root cause buckets.
     *
     * @param  array<string,list<array<string,mixed>>>  $index
     * @return array{bad_prompt:list<array<string,mixed>>, duplicate_target:list<array<string,mixed>>, weak_evidence:list<array<string,mixed>>, template_farm:list<array<string,mixed>>, worker_mismatch:list<array<string,mixed>>}
     */
    private function buildRootCauseMap(array $index): array
    {
        $map = [
            'bad_prompt'       => [],
            'duplicate_target' => [],
            'weak_evidence'    => [],
            'template_farm'    => [],
            'worker_mismatch'  => [],
        ];

        foreach ($index['non_success'] as $o) {
            $bucket = $this->classifyRootCause($o);
            if ($bucket !== null) {
                $map[$bucket][] = [
                    'spec_id' => (string) ($o['spec_id'] ?? ''),
                    'reason'  => (string) ($o['reason'] ?? ''),
                    'outcome' => (string) ($o['outcome'] ?? ''),
                ];
            }
        }

        return $map;
    }

    private function classifyRootCause(array $outcome): ?string
    {
        $reason   = (string) ($outcome['reason']  ?? '');
        $patterns = (array)  ($outcome['poison_patterns'] ?? []);
        $type     = (string) ($outcome['outcome'] ?? '');

        if (in_array($reason, self::BAD_PROMPT_REASONS, true)) {
            return 'bad_prompt';
        }
        if (str_contains($reason, 'duplicate')) {
            return 'duplicate_target';
        }
        if (in_array($reason, ['insufficient_context', 'weak_evidence', 'no_safe_implementation'], true)) {
            return 'weak_evidence';
        }
        if (in_array('template_farm', $patterns, true) || $reason === 'template_farm' || $type === self::OUTCOME_PROXY_SMELL) {
            return 'template_farm';
        }
        if (in_array($reason, ['requires_human_decision', 'worker_mismatch', 'scope_too_large', 'blocked_by_dependency'], true)) {
            return 'worker_mismatch';
        }

        return null;
    }

    /**
     * @param  list<array<string,mixed>>        $outcomes
     * @param  array<string,array<string,mixed>> $byCategory
     * @return list<array<string,mixed>>
     */
    private function buildPolicyAdjustments(array $outcomes, array $byCategory, string $integrity, array $rootCauseMap = []): array
    {
        $policies = [];

        // Helper: is this root cause recurring (≥2 occurrences)?
        $recurring = static fn(string $bucket) => count($rootCauseMap[$bucket] ?? []) >= 2;

        if ($integrity === self::SIGNAL_PADDING_DETECTED) {
            $policy = [
                'action'     => 'reject',
                'applies_to' => 'quota_padding_patterns',
                'reason'     => 'padding detected — integrity signal compromised',
            ];
            if ($recurring('template_farm')) {
                $policy['measurable_acceptance_target'] = 'template_farm:reduce_to_zero_in_next_cycle';
            }
            $policies[] = $policy;
        }

        // Bad prompt reasons → repair policy
        $seenBadReasons = [];
        foreach ($outcomes as $o) {
            $reason = (string) ($o['reason'] ?? '');
            if (in_array($reason, self::BAD_PROMPT_REASONS, true) && ! isset($seenBadReasons[$reason])) {
                $seenBadReasons[$reason] = true;
                $policy = [
                    'action'     => 'repair_prompt',
                    'applies_to' => "reason:{$reason}",
                    'reason'     => "spec authoring error leads to wasted cycles",
                ];
                if ($recurring('bad_prompt')) {
                    $policy['measurable_acceptance_target'] = 'bad_prompt:reduce_to_zero_in_next_cycle';
                }
                $policies[] = $policy;
            }
        }

        // Category-level prefer / avoid
        foreach ($byCategory as $cat => $stats) {
            if ($stats['total'] < 2) {
                continue;
            }
            $yield = $stats['success'] / $stats['total'];
            if ($yield >= 0.70) {
                $policies[] = ['action' => 'prefer', 'applies_to' => "category:{$cat}", 'reason' => 'high yield'];
            } elseif ($yield < 0.35) {
                // Count wasted specs from this category across all root cause buckets.
                $catWastedCount = 0;
                foreach ($rootCauseMap as $bucket => $entries) {
                    foreach ($entries as $entry) {
                        if ($entry['spec_id'] !== '' && in_array($entry['spec_id'], $stats['spec_ids'], true)) {
                            $catWastedCount++;
                        }
                    }
                }
                $policy = ['action' => 'avoid', 'applies_to' => "category:{$cat}", 'reason' => 'low yield'];
                if ($catWastedCount >= 2) {
                    $policy['measurable_acceptance_target'] = "yield:increase_above_35_percent_in_next_cycle:{$cat}";
                }
                $policies[] = $policy;
            }
        }

        return $policies;
    }

    /**
     * AC2: Build next_cycle_adjustments with promote_patterns, avoid_patterns,
     * consolidate_targets and research_gaps derived from evidence (no self-declarations).
     *
     * `adjustments` additionally carries an explicit policy_strength/confidence/evidence_window
     * per reason: a reason seen only once cannot fund a hard avoid/prefer policy on the strength
     * of one anecdote — it degrades to advisory/low-confidence until it recurs (evidence_window
     * >= 2), except padding, which is always a hard reject regardless of recurrence.
     *
     * @param  list<array<string,mixed>>        $outcomes
     * @param  array<string,mixed>              $summary
     * @param  array<string,array<string,mixed>> $byCategory
     * @param  array<string,list<array<string,mixed>>> $rootCauseMap
     * @return array{promote_patterns:list<string>,avoid_patterns:list<string>,consolidate_targets:list<string>,research_gaps:list<string>,adjustments:list<array<string,mixed>>}
     */
    private function buildNextCycleAdjustments(array $outcomes, array $summary, array $byCategory, string $integrity, array $rootCauseMap): array
    {
        $promotePatterns    = [];
        $avoidPatterns      = [];
        $consolidateTargets = [];
        $researchGaps       = [];
        $adjustments        = [];

        // promote: high-yield categories (≥70% success, ≥2 outcomes)
        foreach ($byCategory as $cat => $stats) {
            if ($stats['total'] >= 2 && ($stats['success'] / $stats['total']) >= 0.70) {
                $promotePatterns[] = "category:{$cat}";
            }
        }

        // avoid: low-yield categories + padding + high token waste (AC3)
        foreach ($byCategory as $cat => $stats) {
            if ($stats['total'] >= 2 && ($stats['success'] / $stats['total']) < 0.35) {
                $avoidPatterns[] = "category:{$cat}";
            }
        }
        if ($integrity === self::SIGNAL_PADDING_DETECTED) {
            $avoidPatterns[] = 'quota_padding_patterns';
        }
        if ($summary['wasted_token_ratio'] > 0.50) {
            $avoidPatterns[] = 'high_token_waste:reduce_expensive_patterns';
        }

        // Padding is always a hard reject regardless of how many occurrences funded it —
        // quota gaming is never given the benefit of the doubt as an anecdote.
        if ($integrity === self::SIGNAL_PADDING_DETECTED) {
            $paddingCount = 0;
            foreach ($outcomes as $o) {
                foreach ((array) ($o['poison_patterns'] ?? []) as $pattern) {
                    if (in_array((string) $pattern, self::PADDING_PATTERNS, true)) {
                        $paddingCount++;

                        break;
                    }
                }
            }
            $adjustments[] = [
                'action'          => 'reject',
                'applies_to'      => 'template_farm',
                'policy_strength' => 'hard',
                'confidence'      => 'high',
                'evidence_window' => max(1, $paddingCount),
            ];
        }

        // Bad-prompt reasons: repeated (>=2) → high-confidence hard repair_prompt policy.
        // A single occurrence is an anecdote, not a pattern — advisory/low-confidence until it recurs.
        $badPromptReasonCounts = [];
        foreach ($outcomes as $o) {
            $reason = (string) ($o['reason'] ?? '');
            if (in_array($reason, self::BAD_PROMPT_REASONS, true)) {
                $badPromptReasonCounts[$reason] = ($badPromptReasonCounts[$reason] ?? 0) + 1;
            }
        }
        foreach ($badPromptReasonCounts as $reason => $count) {
            $recurring = $count >= 2;
            $adjustments[] = [
                'action'          => 'repair_prompt',
                'applies_to'      => "reason:{$reason}",
                'policy_strength' => $recurring ? 'hard' : 'advisory',
                'confidence'      => $recurring ? 'high' : 'low',
                'evidence_window' => $count,
            ];
        }

        // Honest give_back reasons: same recurrence rule, but they never escalate past 'avoid' —
        // legitimate task-complexity signals are advice for the originator, not a hard block.
        $honestReasonCounts = [];
        foreach ($outcomes as $o) {
            $reason = (string) ($o['reason'] ?? '');
            if ((string) ($o['outcome'] ?? '') === self::OUTCOME_GIVE_BACK && in_array($reason, self::HONEST_REASONS, true)) {
                $honestReasonCounts[$reason] = ($honestReasonCounts[$reason] ?? 0) + 1;
            }
        }
        foreach ($honestReasonCounts as $reason => $count) {
            $recurring = $count >= 2;
            $adjustments[] = [
                'action'          => 'avoid',
                'applies_to'      => "reason:{$reason}",
                'policy_strength' => $recurring ? 'hard' : 'advisory',
                'confidence'      => $recurring ? 'high' : 'low',
                'evidence_window' => $count,
            ];
        }

        // consolidate: repeated rejection of same target (AC3)
        $duplicateEntries = $rootCauseMap['duplicate_target'] ?? [];
        if (count($duplicateEntries) >= 2) {
            foreach ($duplicateEntries as $entry) {
                if ($entry['spec_id'] !== '') {
                    $consolidateTargets[] = $entry['spec_id'];
                }
            }
        }
        // Also flag repeated rejection (rejected_count from summary)
        if (($summary['rejected_count'] ?? 0) >= 3) {
            $consolidateTargets[] = 'repeated_rejection:review_spec_templates';
        }

        // research_gaps: weak evidence patterns
        if (count($rootCauseMap['weak_evidence'] ?? []) >= 1) {
            $researchGaps[] = 'weak_evidence:gather_evidence_before_next_cycle';
        }
        // Low overall yield with insufficient diversity signals a discovery gap
        if (($summary['yield_rate'] ?? 1.0) < 0.40 && ($summary['total_outcomes'] ?? 0) >= 3) {
            $researchGaps[] = 'low_yield:investigate_category_selection';
        }

        return [
            'promote_patterns'    => array_values(array_unique($promotePatterns)),
            'avoid_patterns'      => array_values(array_unique($avoidPatterns)),
            'consolidate_targets' => array_values(array_unique($consolidateTargets)),
            'research_gaps'       => array_values(array_unique($researchGaps)),
            'adjustments'         => $adjustments,
        ];
    }

    /**
     * @param  array<string,mixed>              $summary
     * @param  array<string,array<string,mixed>> $byCategory
     * @param  list<array<string,mixed>>         $policies
     * @return list<array<string,mixed>>
     */
    private function buildNextCycleHints(array $summary, array $byCategory, string $integrity, array $policies): array
    {
        $hints = [];

        if ($integrity === self::SIGNAL_PADDING_DETECTED) {
            $hints[] = [
                'type'             => 'failed_pattern',
                'fact'             => 'quota padding patterns detected in run — originator must audit spec quality before next cycle',
                'evidence_strength' => 'proven',
            ];
        }

        if ($summary['yield_rate'] < 0.40 && $summary['total_outcomes'] >= 3) {
            $hints[] = [
                'type'             => 'task_family_yield',
                'fact'             => sprintf('Overall yield %.0f%% below threshold — investigate category selection and spec clarity', $summary['yield_rate'] * 100),
                'evidence_strength' => 'observed',
            ];
        }

        foreach ($policies as $policy) {
            if ($policy['action'] === 'prefer') {
                $cat = str_replace('category:', '', (string) $policy['applies_to']);
                $hints[] = [
                    'type'             => 'next_cycle_hint',
                    'fact'             => "Prioritise '{$cat}' tasks — high yield proven this run",
                    'evidence_strength' => 'observed',
                ];
            } elseif ($policy['action'] === 'avoid') {
                $cat = str_replace('category:', '', (string) $policy['applies_to']);
                $hints[] = [
                    'type'             => 'failed_pattern',
                    'fact'             => "Avoid '{$cat}' until spec template is repaired — low yield this run",
                    'evidence_strength' => 'observed',
                ];
            }
        }

        return $hints;
    }

    /**
     * @param  list<array<string,mixed>>  $successes
     * @return list<array{category:string, count:int, confidence:string}>
     */
    private function successPatterns(array $successes): array
    {
        $counts = [];
        foreach ($successes as $s) {
            $cat = (string) ($s['category'] ?? 'unknown');
            $counts[$cat] = ($counts[$cat] ?? 0) + 1;
        }
        $patterns = [];
        foreach ($counts as $cat => $count) {
            $patterns[] = [
                'category' => $cat,
                'count' => $count,
                'confidence' => $count >= 3 ? 'high' : ($count >= 2 ? 'medium' : 'low_confidence'),
            ];
        }
        usort($patterns, static fn ($a, $b) => $b['count'] <=> $a['count']);
        return $patterns;
    }

    /**
     * @param  list<array<string,mixed>>  $giveBacks
     * @return list<array{reason:string, count:int, confidence:string}>
     */
    private function giveBackRoots(array $giveBacks): array
    {
        $counts = [];
        foreach ($giveBacks as $gb) {
            $reason = (string) ($gb['reason'] ?? 'unknown');
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }
        $roots = [];
        foreach ($counts as $reason => $count) {
            $roots[] = [
                'reason' => $reason,
                'count' => $count,
                'confidence' => $count >= 3 ? 'high' : ($count >= 2 ? 'medium' : 'low_confidence'),
            ];
        }
        usort($roots, static fn ($a, $b) => $b['count'] <=> $a['count']);
        return $roots;
    }

    /**
     * @param  list<array<string,mixed>>  $rejected
     * @return list<array{reason:string, count:int}>
     */
    private function cancellationRoots(array $rejected): array
    {
        $counts = [];
        foreach ($rejected as $r) {
            $reason = (string) ($r['reason'] ?? 'unknown');
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }
        $roots = [];
        foreach ($counts as $reason => $count) {
            $roots[] = ['reason' => $reason, 'count' => $count];
        }
        usort($roots, static fn ($a, $b) => $b['count'] <=> $a['count']);
        return $roots;
    }

    /**
     * @param  list<array<string,mixed>>  $outcomes
     * @return list<array{pattern:string, count:int}>
     */
    private function malformedRoots(array $outcomes): array
    {
        $counts = [];
        foreach ($outcomes as $o) {
            foreach ((array) ($o['poison_patterns'] ?? []) as $pattern) {
                $p = (string) $pattern;
                $counts[$p] = ($counts[$p] ?? 0) + 1;
            }
        }
        $roots = [];
        foreach ($counts as $pattern => $count) {
            $roots[] = ['pattern' => $pattern, 'count' => $count];
        }
        usort($roots, static fn ($a, $b) => $b['count'] <=> $a['count']);
        return $roots;
    }

    /**
     * @param  list<array<string,mixed>>  $outcomes
     * @return list<array{spec_id:string, duplicate_of:string}>
     */
    private function collisionRoots(array $outcomes): array
    {
        $seen = [];
        $collisions = [];
        foreach ($outcomes as $o) {
            $specId = (string) ($o['spec_id'] ?? '');
            if (isset($seen[$specId])) {
                $collisions[] = ['spec_id' => $specId, 'duplicate_of' => $seen[$specId]];
            } else {
                $seen[$specId] = $specId;
            }
        }
        return $collisions;
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array{direction:string, yield_rate:float, drift_detected:bool}
     */
    private function queueHealthDrift(array $summary): array
    {
        $yieldRate = (float) ($summary['yield_rate'] ?? 0);
        $direction = $yieldRate >= 0.60 ? 'stable' : ($yieldRate >= 0.40 ? 'degrading' : 'critical');
        return [
            'direction' => $direction,
            'yield_rate' => $yieldRate,
            'drift_detected' => $yieldRate < 0.60,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $policies
     * @param  array<string,list<array<string,mixed>>>  $index
     * @param  array<string,mixed>  $summary
     * @return list<array{rule:string, action:string, confidence:string}>
     */
    private function nextBatchRules(array $policies, array $index, array $summary): array
    {
        $rules = [];
        foreach ($policies as $policy) {
            $action = (string) ($policy['action'] ?? '');
            $appliesTo = (string) ($policy['applies_to'] ?? '');
            $confidence = (string) ($policy['confidence'] ?? 'low_confidence');
            $rules[] = [
                'rule' => "{$action}:{$appliesTo}",
                'action' => $action,
                'confidence' => $confidence,
            ];
        }
        return $rules;
    }
}
