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
 * Pure: no I/O, no provider calls, deterministic.
 */
final class AtlasExternalBrainAmplifierFallbackRunbook
{
    public const SCHEMA = 'atlas.external_brain.amplifier_fallback_runbook.v1';

    public const TRIGGER_BENCHMARK_MISS           = 'benchmark_miss';
    public const TRIGGER_PROXY_LEAKAGE            = 'proxy_leakage';
    public const TRIGGER_REPEATED_REPAIR_FAILURE  = 'repeated_repair_failure';

    private const REPAIR_EXHAUSTION_THRESHOLD = 3;

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

        $proxyLeakage    = $this->hasProxyLeakage($proxyCheckResults);
        $benchmarkPassed = $this->allBenchmarksPassed($benchmarkResults);
        $repairExhausted = $this->isRepairExhausted($repairHistory);

        $triggers = $this->collectTriggers($proxyLeakage, $benchmarkPassed, $repairExhausted);

        return [
            'schema'                 => self::SCHEMA,
            'runbook_steps'          => $this->buildSteps($contextAssembly, $exemplarReplays, $proxyCheckResults, $repairHistory),
            'escalation_recommended' => $triggers !== [],
            'escalation_triggers'    => $triggers,
            'proxy_leakage_detected' => $proxyLeakage,
            'benchmark_passed'       => $benchmarkPassed,
            'repair_exhausted'       => $repairExhausted,
        ];
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
    private function collectTriggers(bool $proxyLeakage, bool $benchmarkPassed, bool $repairExhausted): array
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

        return $triggers;
    }
}
