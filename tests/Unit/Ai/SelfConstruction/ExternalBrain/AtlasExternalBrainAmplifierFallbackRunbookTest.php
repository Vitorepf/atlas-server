<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierFallbackRunbook;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAmplifierFallbackRunbookTest extends TestCase
{
    private AtlasExternalBrainAmplifierFallbackRunbook $runbook;

    protected function setUp(): void
    {
        $this->runbook = new AtlasExternalBrainAmplifierFallbackRunbook;
    }

    private function passingBench(string $metric = 'accuracy'): array
    {
        return ['metric' => $metric, 'actual' => 0.90, 'threshold' => 0.80, 'passed' => true];
    }

    private function failingBench(string $metric = 'accuracy'): array
    {
        return ['metric' => $metric, 'actual' => 0.60, 'threshold' => 0.80, 'passed' => false];
    }

    private function passingProxy(string $name = 'cyclomatic_guard'): array
    {
        return ['check_name' => $name, 'passed' => true];
    }

    private function failingProxy(string $name = 'cyclomatic_guard'): array
    {
        return ['check_name' => $name, 'passed' => false];
    }

    private function repairAttempt(int $attempt, bool $succeeded): array
    {
        return ['attempt' => $attempt, 'succeeded' => $succeeded];
    }

    private function baseInput(array $overrides = []): array
    {
        return array_merge([
            'context_assembly'    => ['task_objective', 'allowed_files', 'prior_evidence'],
            'exemplar_replays'    => [['input' => 'sample', 'expected_output' => 'result']],
            'proxy_check_results' => [$this->passingProxy()],
            'benchmark_results'   => [$this->passingBench()],
            'repair_loop_history' => [],
            'model_tier'          => 'small',
        ], $overrides);
    }

    // ── Schema / output shape ──────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $result = $this->runbook->compile($this->baseInput());

        foreach (['schema', 'runbook_steps', 'escalation_recommended', 'escalation_triggers', 'proxy_leakage_detected', 'benchmark_passed', 'repair_exhausted'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainAmplifierFallbackRunbook::SCHEMA, $result['schema']);
    }

    // ── Runbook steps always include required phases ───────────────────────────

    public function test_runbook_steps_include_context_assembly(): void
    {
        $result = $this->runbook->compile($this->baseInput());

        $steps = implode(' ', $result['runbook_steps']);
        $this->assertStringContainsStringIgnoringCase('context', $steps);
    }

    public function test_runbook_steps_include_exemplar_replay_when_exemplars_present(): void
    {
        $result = $this->runbook->compile($this->baseInput([
            'exemplar_replays' => [['input' => 'a', 'expected_output' => 'b']],
        ]));

        $steps = implode(' ', $result['runbook_steps']);
        $this->assertStringContainsStringIgnoringCase('exemplar', $steps);
    }

    public function test_runbook_steps_omit_exemplar_step_when_no_exemplars(): void
    {
        $result = $this->runbook->compile($this->baseInput(['exemplar_replays' => []]));

        foreach ($result['runbook_steps'] as $step) {
            $this->assertStringNotContainsStringIgnoringCase('exemplar', $step);
        }
    }

    public function test_runbook_steps_include_proxy_check(): void
    {
        $result = $this->runbook->compile($this->baseInput());

        $steps = implode(' ', $result['runbook_steps']);
        $this->assertStringContainsStringIgnoringCase('proxy', $steps);
    }

    public function test_runbook_steps_include_benchmark_comparison(): void
    {
        $result = $this->runbook->compile($this->baseInput());

        $steps = implode(' ', $result['runbook_steps']);
        $this->assertStringContainsStringIgnoringCase('benchmark', $steps);
    }

    public function test_runbook_steps_include_repair_loop(): void
    {
        $result = $this->runbook->compile($this->baseInput());

        $steps = implode(' ', $result['runbook_steps']);
        $this->assertStringContainsStringIgnoringCase('repair', $steps);
    }

    public function test_runbook_steps_include_escalation_verification(): void
    {
        $result = $this->runbook->compile($this->baseInput());

        $steps = implode(' ', $result['runbook_steps']);
        $this->assertStringContainsStringIgnoringCase('escalation', $steps);
    }

    public function test_runbook_always_has_at_least_five_steps(): void
    {
        $result = $this->runbook->compile($this->baseInput(['exemplar_replays' => []]));

        $this->assertGreaterThanOrEqual(5, count($result['runbook_steps']));
    }

    // ── Escalation: benchmark miss ────────────────────────────────────────────

    public function test_escalation_recommended_on_benchmark_miss(): void
    {
        $result = $this->runbook->compile($this->baseInput([
            'benchmark_results' => [$this->failingBench()],
        ]));

        $this->assertTrue($result['escalation_recommended']);
        $this->assertContains(AtlasExternalBrainAmplifierFallbackRunbook::TRIGGER_BENCHMARK_MISS, $result['escalation_triggers']);
        $this->assertFalse($result['benchmark_passed']);
    }

    public function test_no_escalation_when_all_benchmarks_pass(): void
    {
        $result = $this->runbook->compile($this->baseInput([
            'benchmark_results' => [$this->passingBench(), $this->passingBench('f1')],
        ]));

        $this->assertFalse($result['escalation_recommended']);
        $this->assertNotContains(AtlasExternalBrainAmplifierFallbackRunbook::TRIGGER_BENCHMARK_MISS, $result['escalation_triggers']);
    }

    // ── Escalation: proxy leakage ─────────────────────────────────────────────

    public function test_escalation_recommended_on_proxy_leakage(): void
    {
        $result = $this->runbook->compile($this->baseInput([
            'proxy_check_results' => [$this->failingProxy()],
        ]));

        $this->assertTrue($result['escalation_recommended']);
        $this->assertContains(AtlasExternalBrainAmplifierFallbackRunbook::TRIGGER_PROXY_LEAKAGE, $result['escalation_triggers']);
        $this->assertTrue($result['proxy_leakage_detected']);
    }

    public function test_no_escalation_when_proxy_checks_pass(): void
    {
        $result = $this->runbook->compile($this->baseInput([
            'proxy_check_results' => [$this->passingProxy(), $this->passingProxy('line_count')],
        ]));

        $this->assertFalse($result['proxy_leakage_detected']);
        $this->assertNotContains(AtlasExternalBrainAmplifierFallbackRunbook::TRIGGER_PROXY_LEAKAGE, $result['escalation_triggers']);
    }

    // ── Escalation: repeated repair failure ───────────────────────────────────

    public function test_escalation_recommended_on_repeated_repair_failure(): void
    {
        $result = $this->runbook->compile($this->baseInput([
            'repair_loop_history' => [
                $this->repairAttempt(1, false),
                $this->repairAttempt(2, false),
                $this->repairAttempt(3, false),
            ],
        ]));

        $this->assertTrue($result['escalation_recommended']);
        $this->assertContains(AtlasExternalBrainAmplifierFallbackRunbook::TRIGGER_REPEATED_REPAIR_FAILURE, $result['escalation_triggers']);
        $this->assertTrue($result['repair_exhausted']);
    }

    public function test_no_repair_exhaustion_when_fewer_than_threshold_attempts(): void
    {
        $result = $this->runbook->compile($this->baseInput([
            'repair_loop_history' => [
                $this->repairAttempt(1, false),
                $this->repairAttempt(2, false),
            ],
        ]));

        $this->assertFalse($result['repair_exhausted']);
    }

    public function test_no_repair_exhaustion_when_last_attempt_succeeded(): void
    {
        $result = $this->runbook->compile($this->baseInput([
            'repair_loop_history' => [
                $this->repairAttempt(1, false),
                $this->repairAttempt(2, false),
                $this->repairAttempt(3, true),
            ],
        ]));

        $this->assertFalse($result['repair_exhausted']);
        $this->assertNotContains(AtlasExternalBrainAmplifierFallbackRunbook::TRIGGER_REPEATED_REPAIR_FAILURE, $result['escalation_triggers']);
    }

    // ── No escalation merely because model_tier is small ─────────────────────

    public function test_small_model_tier_alone_does_not_trigger_escalation(): void
    {
        $result = $this->runbook->compile($this->baseInput(['model_tier' => 'small']));

        $this->assertFalse($result['escalation_recommended']);
        $this->assertSame([], $result['escalation_triggers']);
    }

    // ── Multiple triggers ─────────────────────────────────────────────────────

    public function test_multiple_triggers_collected(): void
    {
        $result = $this->runbook->compile($this->baseInput([
            'benchmark_results'   => [$this->failingBench()],
            'proxy_check_results' => [$this->failingProxy()],
        ]));

        $this->assertContains(AtlasExternalBrainAmplifierFallbackRunbook::TRIGGER_BENCHMARK_MISS,  $result['escalation_triggers']);
        $this->assertContains(AtlasExternalBrainAmplifierFallbackRunbook::TRIGGER_PROXY_LEAKAGE,   $result['escalation_triggers']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $input = $this->baseInput([
            'benchmark_results' => [$this->failingBench()],
        ]);

        $a = $this->runbook->compile($input);
        $b = $this->runbook->compile($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── mode / required_checks / blocked_task_families / escalation_trigger ───

    public function test_small_model_tier_uses_non_frontier_fallback_mode(): void
    {
        $result = $this->runbook->compile($this->baseInput(['model_tier' => 'small']));

        $this->assertSame(AtlasExternalBrainAmplifierFallbackRunbook::MODE_NON_FRONTIER_FALLBACK, $result['mode']);
    }

    public function test_frontier_model_tier_uses_frontier_direct_mode(): void
    {
        $result = $this->runbook->compile($this->baseInput(['model_tier' => 'frontier']));

        $this->assertSame(AtlasExternalBrainAmplifierFallbackRunbook::MODE_FRONTIER_DIRECT, $result['mode']);
    }

    public function test_required_checks_includes_all_five_named_safe_steps(): void
    {
        $result = $this->runbook->compile($this->baseInput());

        $this->assertSame([
            'reduce_scope',
            'require_replay',
            'require_dedup',
            'critique_output',
            'escalate_on_ambiguity',
        ], $result['required_checks']);
    }

    public function test_high_impact_ambiguous_insufficient_evidence_blocks_the_task_family(): void
    {
        $result = $this->runbook->compile($this->baseInput([
            'candidate_task_families' => [[
                'family' => 'risky_family',
                'impact' => 'high',
                'ambiguous' => true,
                'fallback_evidence_sufficient' => false,
            ]],
        ]));

        $this->assertContains('risky_family', $result['blocked_task_families']);
        $this->assertTrue($result['escalation_recommended']);
        $this->assertContains(
            AtlasExternalBrainAmplifierFallbackRunbook::TRIGGER_HIGH_IMPACT_AMBIGUOUS_INSUFFICIENT_EVIDENCE,
            $result['escalation_triggers'],
        );
    }

    public function test_high_impact_ambiguous_with_sufficient_evidence_is_not_blocked(): void
    {
        $result = $this->runbook->compile($this->baseInput([
            'candidate_task_families' => [[
                'family' => 'well_evidenced_family',
                'impact' => 'high',
                'ambiguous' => true,
                'fallback_evidence_sufficient' => true,
            ]],
        ]));

        $this->assertSame([], $result['blocked_task_families']);
    }

    public function test_low_impact_ambiguous_insufficient_evidence_is_not_blocked(): void
    {
        $result = $this->runbook->compile($this->baseInput([
            'candidate_task_families' => [[
                'family' => 'low_impact_family',
                'impact' => 'low',
                'ambiguous' => true,
                'fallback_evidence_sufficient' => false,
            ]],
        ]));

        $this->assertSame([], $result['blocked_task_families']);
    }

    public function test_escalation_trigger_is_first_of_escalation_triggers(): void
    {
        $result = $this->runbook->compile($this->baseInput([
            'benchmark_results' => [$this->failingBench()],
        ]));

        $this->assertSame($result['escalation_triggers'][0], $result['escalation_trigger']);
    }

    public function test_escalation_trigger_is_null_when_no_escalation(): void
    {
        $result = $this->runbook->compile($this->baseInput());

        $this->assertNull($result['escalation_trigger']);
    }
}
