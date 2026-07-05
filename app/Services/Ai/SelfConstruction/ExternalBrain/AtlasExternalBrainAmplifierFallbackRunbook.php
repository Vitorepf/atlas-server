<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure runbook compiler. Turns a small-model run description into an ordered
 * sequence of strengthening steps and decides whether to recommend escalation
 * to a frontier model — based only on evidence, never on model tier alone.
 *
 * Input fields:
 *   context_assembly    list<string>  — required context pieces
 *   exemplar_replays    list<array>   — {input, expected_output} exemplar pairs
 *   proxy_check_results list<array>   — {check_name, passed: bool}
 *   benchmark_results   list<array>   — {metric, actual, threshold, passed: bool}
 *   repair_loop_history list<array>   — {attempt: int, succeeded: bool}
 *   model_tier          string        — 'small' | 'frontier'
 *
 * Output:
 *   schema                 string
 *   runbook_steps          list<string>  — ordered, always compiled
 *   escalation_recommended bool
 *   escalation_triggers    list<string>  — reasons (empty when no escalation)
 *   proxy_leakage_detected bool
 *   benchmark_passed       bool
 *   repair_exhausted       bool
 *
 * Escalation triggers (any fires → escalate; model_tier alone never triggers):
 *   benchmark_miss           — at least one benchmark result has passed=false
 *   proxy_leakage            — at least one proxy check has passed=false
 *   repeated_repair_failure  — ≥ REPAIR_EXHAUSTION_THRESHOLD attempts and last failed
 *
 * FAILURE-KIND FALLBACK PATHS (new, opt-in via failure_signal):
 *   failure_signal  string|null  — one of provider_outage | quota_exhaustion |
 *                                  weak_output_regression | missing_context
 *   fallback_intent {lower_proof_quality_for_throughput?: bool}
 * Every named fallback path re-runs the same proxy/benchmark gates, stays within
 * allowed_files discipline, and routes to an Atlas-native client — never a permanent
 * human or external dependency — so steady-state autonomy is never lost. A caller that
 * requests lowering proof quality merely to keep throughput high is refused
 * (fallback_rejected=true); the gates are never relaxed to satisfy that request.
 *
 * Pure: no I/O, no provider calls, deterministic.
 */
final class AtlasExternalBrainAmplifierFallbackRunbook
{
    public const SCHEMA = 'atlas.external_brain.amplifier_fallback_runbook.v1';

    public const TRIGGER_BENCHMARK_MISS           = 'benchmark_miss';
    public const TRIGGER_PROXY_LEAKAGE            = 'proxy_leakage';
    public const TRIGGER_REPEATED_REPAIR_FAILURE  = 'repeated_repair_failure';
    public const TRIGGER_HIGH_IMPACT_AMBIGUOUS_INSUFFICIENT_EVIDENCE = 'high_impact_ambiguous_insufficient_evidence';

    public const MODE_NON_FRONTIER_FALLBACK = 'non_frontier_fallback';
    public const MODE_FRONTIER_DIRECT       = 'frontier_direct';

    public const REQUIRED_CHECKS = [
        'reduce_scope',
        'require_replay',
        'require_dedup',
        'critique_output',
        'escalate_on_ambiguity',
    ];

    private const REPAIR_EXHAUSTION_THRESHOLD = 3;

    public const FAILURE_PROVIDER_OUTAGE       = 'provider_outage';
    public const FAILURE_QUOTA_EXHAUSTION      = 'quota_exhaustion';
    public const FAILURE_WEAK_OUTPUT_REGRESSION = 'weak_output_regression';
    public const FAILURE_MISSING_CONTEXT       = 'missing_context';

    /**
     * Structured fallback steps. Each step carries:
     *   trigger                  — what condition activates this step
     *   command_or_receipt_ref   — the Atlas-native command or receipt reference
     *   expected_outcome         — what success looks like
     *   rollback_condition       — when to abandon this step and proceed to the next
     *
     * No step depends on a human or paid external provider in steady state.
     * The final step in each path is an advisory bootstrap audit — never a
     * blocking human dependency.
     *
     * @var array<string,list<array<string,string>>>
     */
    private const FALLBACK_PATHS = [
        self::FAILURE_PROVIDER_OUTAGE => [
            [
                'trigger'                => 'health_check_failure_on_current_provider',
                'command_or_receipt_ref' => 'atlas:ai:replay-native --provider=atlas-native',
                'expected_outcome'       => 'Atlas-native replay completes without external provider dependency',
                'rollback_condition'     => 'replay fails or output does not pass proxy/benchmark gates',
            ],
            [
                'trigger'                => 'native_replay_unavailable_or_gates_failed',
                'command_or_receipt_ref' => 'atlas:ai:scaffold:lower-tier --mode=non_frontier_fallback',
                'expected_outcome'       => 'Lower-tier scaffold produces output meeting proxy and benchmark gates',
                'rollback_condition'     => 'scaffold output fails gates after repair loop exhaustion',
            ],
            [
                'trigger'                => 'scaffold_retry_failed_gates',
                'command_or_receipt_ref' => 'atlas:queue:defer-repair --safe --autonomous-retry',
                'expected_outcome'       => 'Task safely deferred to autonomous repair queue for later retry',
                'rollback_condition'     => 'queue depth exceeds safe threshold or task is stale beyond TTL',
            ],
            [
                'trigger'                => 'deferred_repair_enqueued',
                'command_or_receipt_ref' => 'atlas:audit:bootstrap-advisory --reason=provider_outage',
                'expected_outcome'       => 'Advisory audit record emitted for operator review (non-blocking)',
                'rollback_condition'     => 'advisory only — never blocks steady-state autonomy',
            ],
        ],
        self::FAILURE_QUOTA_EXHAUSTION => [
            [
                'trigger'                => 'rate_limit_or_quota_exceeded_signal',
                'command_or_receipt_ref' => 'atlas:provider:rotate --to=next-atlas-native --preserve-quota',
                'expected_outcome'       => 'Rotated to Atlas-native provider with available quota, thresholds unchanged',
                'rollback_condition'     => 'no Atlas-native provider has available quota',
            ],
            [
                'trigger'                => 'no_provider_with_quota_available',
                'command_or_receipt_ref' => 'atlas:ai:scaffold:lower-tier --mode=non_frontier_fallback',
                'expected_outcome'       => 'Lower-tier scaffold produces output meeting gates without quota-dependent provider',
                'rollback_condition'     => 'scaffold output fails gates after repair loop',
            ],
            [
                'trigger'                => 'scaffold_retry_failed_gates',
                'command_or_receipt_ref' => 'atlas:queue:defer-repair --safe --autonomous-retry',
                'expected_outcome'       => 'Task safely deferred to autonomous repair queue',
                'rollback_condition'     => 'queue depth exceeds safe threshold or task stale beyond TTL',
            ],
            [
                'trigger'                => 'deferred_repair_enqueued',
                'command_or_receipt_ref' => 'atlas:audit:bootstrap-advisory --reason=quota_exhaustion',
                'expected_outcome'       => 'Advisory audit record emitted (non-blocking)',
                'rollback_condition'     => 'advisory only — never blocks steady-state autonomy',
            ],
        ],
        self::FAILURE_WEAK_OUTPUT_REGRESSION => [
            [
                'trigger'                => 'benchmark_miss_or_proxy_leakage_on_current_output',
                'command_or_receipt_ref' => 'atlas:ai:repair-loop --max-attempts=3',
                'expected_outcome'       => 'Repair loop addresses regression and output passes gates',
                'rollback_condition'     => 'repair loop exhausted (3 attempts, last failed)',
            ],
            [
                'trigger'                => 'repair_loop_exhausted',
                'command_or_receipt_ref' => 'atlas:ai:scaffold:lower-tier --mode=non_frontier_fallback',
                'expected_outcome'       => 'Lower-tier scaffold produces non-regressed output meeting gates',
                'rollback_condition'     => 'scaffold output also fails gates',
            ],
            [
                'trigger'                => 'scaffold_retry_failed_gates',
                'command_or_receipt_ref' => 'atlas:queue:defer-repair --safe --autonomous-retry',
                'expected_outcome'       => 'Task safely deferred to autonomous repair queue',
                'rollback_condition'     => 'queue depth exceeds safe threshold or task stale beyond TTL',
            ],
            [
                'trigger'                => 'deferred_repair_enqueued',
                'command_or_receipt_ref' => 'atlas:audit:bootstrap-advisory --reason=weak_output_regression',
                'expected_outcome'       => 'Advisory audit record emitted (non-blocking)',
                'rollback_condition'     => 'advisory only — never blocks steady-state autonomy',
            ],
        ],
        self::FAILURE_MISSING_CONTEXT => [
            [
                'trigger'                => 'unresolved_context_assembly_items',
                'command_or_receipt_ref' => 'atlas:context:resolve --required-items=context_assembly',
                'expected_outcome'       => 'Required context assembled without guessing or proceeding without it',
                'rollback_condition'     => 'context items remain unresolved after resolution attempt',
            ],
            [
                'trigger'                => 'context_resolution_failed',
                'command_or_receipt_ref' => 'atlas:ai:scaffold:lower-tier --mode=non_frontier_fallback --context-aware',
                'expected_outcome'       => 'Lower-tier scaffold compensates for missing context within allowed_files',
                'rollback_condition'     => 'scaffold cannot produce gate-passing output without context',
            ],
            [
                'trigger'                => 'scaffold_cannot_compensate',
                'command_or_receipt_ref' => 'atlas:queue:defer-repair --safe --autonomous-retry --reason=missing_context',
                'expected_outcome'       => 'Task safely deferred until context becomes available',
                'rollback_condition'     => 'task stale beyond TTL or context permanently unavailable',
            ],
            [
                'trigger'                => 'deferred_repair_enqueued',
                'command_or_receipt_ref' => 'atlas:audit:bootstrap-advisory --reason=missing_context',
                'expected_outcome'       => 'Advisory audit record emitted (non-blocking)',
                'rollback_condition'     => 'advisory only — never blocks steady-state autonomy',
            ],
        ],
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function compile(array $input): array
    {
        $contextAssembly   = (array) ($input['context_assembly']    ?? []);
        $exemplarReplays   = (array) ($input['exemplar_replays']    ?? []);
        $proxyCheckResults = (array) ($input['proxy_check_results'] ?? []);
        $benchmarkResults  = (array) ($input['benchmark_results']   ?? []);
        $repairHistory     = (array) ($input['repair_loop_history'] ?? []);
        $modelTier         = (string) ($input['model_tier']         ?? 'small');
        $candidateTaskFamilies = (array) ($input['candidate_task_families'] ?? []);

        $proxyLeakage    = $this->hasProxyLeakage($proxyCheckResults);
        $benchmarkPassed = $this->allBenchmarksPassed($benchmarkResults);
        $repairExhausted = $this->isRepairExhausted($repairHistory);

        [$blockedTaskFamilies, $hasBlockedHighImpact] = $this->evaluateTaskFamilies($candidateTaskFamilies);

        $triggers = $this->collectTriggers($proxyLeakage, $benchmarkPassed, $repairExhausted, $hasBlockedHighImpact);

        $failureSignal = $input['failure_signal'] ?? null;
        $failureSignal = is_string($failureSignal) ? $failureSignal : null;

        $fallbackIntent = (array) ($input['fallback_intent'] ?? []);
        $fallbackRejected = (bool) ($fallbackIntent['lower_proof_quality_for_throughput'] ?? false);
        $fallbackRejectedReason = $fallbackRejected
            ? 'proof_quality_must_never_be_lowered_to_preserve_throughput'
            : null;

        $fallbackPath = $failureSignal !== null && isset(self::FALLBACK_PATHS[$failureSignal]) ? [
            'failure_kind'                        => $failureSignal,
            'steps'                                => self::FALLBACK_PATHS[$failureSignal],
            'fallback_steps'                       => self::FALLBACK_PATHS[$failureSignal],
            'preserves_runnable_gates'             => true,
            'preserves_allowed_files_discipline'   => true,
            'preserves_atlas_native_autonomy'      => true,
            'steady_state_human_dependency'       => false,
            'bootstrap_audit_advisory_only'        => true,
        ] : null;

        return [
            'schema'                 => self::SCHEMA,
            'mode'                   => $modelTier === 'frontier' ? self::MODE_FRONTIER_DIRECT : self::MODE_NON_FRONTIER_FALLBACK,
            'required_checks'        => self::REQUIRED_CHECKS,
            'runbook_steps'          => $this->buildSteps($contextAssembly, $exemplarReplays, $proxyCheckResults, $repairHistory),
            'escalation_recommended' => $triggers !== [],
            'escalation_triggers'    => $triggers,
            'escalation_trigger'     => $triggers[0] ?? null,
            'proxy_leakage_detected' => $proxyLeakage,
            'benchmark_passed'       => $benchmarkPassed,
            'repair_exhausted'       => $repairExhausted,
            'blocked_task_families'  => $blockedTaskFamilies,
            'fallback_path'          => $fallbackPath,
            'fallback_rejected'      => $fallbackRejected,
            'fallback_rejected_reason' => $fallbackRejectedReason,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $candidateTaskFamilies
     * @return array{0: list<string>, 1: bool}
     */
    private function evaluateTaskFamilies(array $candidateTaskFamilies): array
    {
        $blocked = [];
        foreach ($candidateTaskFamilies as $candidate) {
            if (! is_array($candidate) || ! isset($candidate['family'])) {
                continue;
            }
            $impact = (string) ($candidate['impact'] ?? 'low');
            $ambiguous = (bool) ($candidate['ambiguous'] ?? false);
            $fallbackEvidenceSufficient = (bool) ($candidate['fallback_evidence_sufficient'] ?? true);

            if ($impact === 'high' && $ambiguous && ! $fallbackEvidenceSufficient) {
                $blocked[] = (string) $candidate['family'];
            }
        }

        return [$blocked, $blocked !== []];
    }

    /** @return list<string> */
    private function buildSteps(
        array $contextAssembly,
        array $exemplarReplays,
        array $proxyCheckResults,
        array $repairHistory,
    ): array {
        $steps = [];

        // 1. Context assembly — always required
        if ($contextAssembly !== []) {
            $items   = implode(', ', array_map('strval', $contextAssembly));
            $steps[] = "Assemble required context: {$items}";
        } else {
            $steps[] = 'Assemble required context: no context items specified — add context_assembly to scaffold';
        }

        // 2. Exemplar replay — only when exemplars are present
        if ($exemplarReplays !== []) {
            $count   = count($exemplarReplays);
            $steps[] = "Replay {$count} exemplar(s) to prime expected model behavior";
        }

        // 3. Proxy-detector checks — always
        $steps[] = 'Run proxy-detector checks on model output';

        // 4. Benchmark comparison — always
        $steps[] = 'Compare output against benchmark thresholds';

        // 5. Repair loop — always included as a conditional gate
        $attempts = count($repairHistory);
        if ($attempts > 0) {
            $steps[] = "Apply repair loop (attempt {$attempts}): address benchmark miss or proxy leak and re-evaluate";
        } else {
            $steps[] = 'Apply repair loop if benchmark miss or proxy leak detected';
        }

        // 6. Verify repair before escalation — always last
        $steps[] = 'Verify repair outcome before triggering escalation to frontier model';

        return $steps;
    }

    private function hasProxyLeakage(array $proxyCheckResults): bool
    {
        foreach ($proxyCheckResults as $check) {
            if (is_array($check) && (bool) ($check['passed'] ?? true) === false) {
                return true;
            }
        }

        return false;
    }

    private function allBenchmarksPassed(array $benchmarkResults): bool
    {
        if ($benchmarkResults === []) {
            return true;
        }

        foreach ($benchmarkResults as $bench) {
            if (is_array($bench) && (bool) ($bench['passed'] ?? true) === false) {
                return false;
            }
        }

        return true;
    }

    private function isRepairExhausted(array $repairHistory): bool
    {
        if (count($repairHistory) < self::REPAIR_EXHAUSTION_THRESHOLD) {
            return false;
        }

        $last = end($repairHistory);

        return is_array($last) && (bool) ($last['succeeded'] ?? true) === false;
    }

    /** @return list<string> */
    private function collectTriggers(bool $proxyLeakage, bool $benchmarkPassed, bool $repairExhausted, bool $hasBlockedHighImpact): array
    {
        $triggers = [];

        if (! $benchmarkPassed) {
            $triggers[] = self::TRIGGER_BENCHMARK_MISS;
        }
        if ($proxyLeakage) {
            $triggers[] = self::TRIGGER_PROXY_LEAKAGE;
        }
        if ($repairExhausted) {
            $triggers[] = self::TRIGGER_REPEATED_REPAIR_FAILURE;
        }
        if ($hasBlockedHighImpact) {
            $triggers[] = self::TRIGGER_HIGH_IMPACT_AMBIGUOUS_INSUFFICIENT_EVIDENCE;
        }

        return $triggers;
    }
}
