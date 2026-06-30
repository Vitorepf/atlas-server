<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainEndToEndAutonomyReplayHarness;
use Tests\TestCase;

final class AtlasExternalBrainEndToEndAutonomyReplayHarnessTest extends TestCase
{
    private function harness(): AtlasExternalBrainEndToEndAutonomyReplayHarness
    {
        return new AtlasExternalBrainEndToEndAutonomyReplayHarness;
    }

    private function baseScenario(array $queueOverrides = [], array $candidates = ['c1']): array
    {
        return [
            'queue_facts' => array_merge([
                'poison_detected' => false,
                'sprawl_pressure' => false,
                'low_value_ratio' => 0.10,
            ], $queueOverrides),
            'candidate_pool' => $candidates,
        ];
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present_on_normal_replay(): void
    {
        $r = $this->harness()->replay($this->baseScenario());

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::SCHEMA, $r['schema']);
    }

    public function test_output_keys_present_on_normal_replay(): void
    {
        $r = $this->harness()->replay($this->baseScenario());

        foreach (['schema', 'brain_decision', 'queue_facts_seen', 'candidate_count'] as $k) {
            $this->assertArrayHasKey($k, $r);
        }
    }

    public function test_candidate_count_reflects_pool_size(): void
    {
        $r = $this->harness()->replay($this->baseScenario([], ['c1', 'c2', 'c3']));

        $this->assertSame(3, $r['candidate_count']);
    }

    // ── AC2: scenario decisions ───────────────────────────────────────────────

    public function test_good_queue_decides_create_more_tasks(): void
    {
        $r = $this->harness()->replay($this->baseScenario());

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::DECISION_CREATE_MORE_TASKS, $r['brain_decision']);
    }

    public function test_poison_queue_decides_drain_poison(): void
    {
        $r = $this->harness()->replay($this->baseScenario(['poison_detected' => true]));

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::DECISION_DRAIN_POISON, $r['brain_decision']);
    }

    public function test_low_value_backlog_decides_deprioritize(): void
    {
        $r = $this->harness()->replay($this->baseScenario(['low_value_ratio' => 0.80]));

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::DECISION_DEPRIORITIZE_LOW_VALUE, $r['brain_decision']);
    }

    public function test_sprawl_pressure_decides_reduce_sprawl(): void
    {
        $r = $this->harness()->replay($this->baseScenario(['sprawl_pressure' => true]));

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::DECISION_REDUCE_SPRAWL, $r['brain_decision']);
    }

    // ── decision priority ─────────────────────────────────────────────────────

    public function test_poison_beats_sprawl(): void
    {
        $r = $this->harness()->replay($this->baseScenario(['poison_detected' => true, 'sprawl_pressure' => true]));

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::DECISION_DRAIN_POISON, $r['brain_decision']);
    }

    public function test_sprawl_beats_low_value(): void
    {
        $r = $this->harness()->replay($this->baseScenario(['sprawl_pressure' => true, 'low_value_ratio' => 0.90]));

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::DECISION_REDUCE_SPRAWL, $r['brain_decision']);
    }

    public function test_low_value_at_threshold_not_deprioritized(): void
    {
        // strictly greater than 0.60 triggers deprioritize; at 0.60 → create_more_tasks
        $r = $this->harness()->replay($this->baseScenario(['low_value_ratio' => 0.60]));

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::DECISION_CREATE_MORE_TASKS, $r['brain_decision']);
    }

    // ── AC3: fail closed on missing sections ──────────────────────────────────

    public function test_missing_queue_facts_returns_evidence_missing(): void
    {
        $r = $this->harness()->replay(['candidate_pool' => ['c1']]);

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::DECISION_EVIDENCE_MISSING, $r['brain_decision']);
        $this->assertNotSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::DECISION_CREATE_MORE_TASKS, $r['brain_decision']);
    }

    public function test_missing_candidate_pool_returns_evidence_missing(): void
    {
        $r = $this->harness()->replay(['queue_facts' => ['poison_detected' => false]]);

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::DECISION_EVIDENCE_MISSING, $r['brain_decision']);
    }

    public function test_completely_empty_scenario_returns_evidence_missing(): void
    {
        $r = $this->harness()->replay([]);

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::DECISION_EVIDENCE_MISSING, $r['brain_decision']);
    }

    public function test_evidence_missing_includes_missing_section_key(): void
    {
        $r = $this->harness()->replay(['candidate_pool' => []]);

        $this->assertArrayHasKey('missing_section', $r);
        $this->assertSame('queue_facts', $r['missing_section']);
    }

    // ── AC4: pure — no side effects ───────────────────────────────────────────

    public function test_replay_is_deterministic(): void
    {
        $scenario = $this->baseScenario(['low_value_ratio' => 0.75], ['a', 'b']);

        $this->assertSame($this->harness()->replay($scenario), $this->harness()->replay($scenario));
    }

    public function test_queue_facts_echoed_in_output(): void
    {
        $queueFacts = ['poison_detected' => false, 'sprawl_pressure' => false, 'low_value_ratio' => 0.10];
        $r = $this->harness()->replay(['queue_facts' => $queueFacts, 'candidate_pool' => []]);

        $this->assertSame($queueFacts, $r['queue_facts_seen']);
    }

    // ── custom threshold ──────────────────────────────────────────────────────

    public function test_custom_low_value_threshold_respected(): void
    {
        $r = $this->harness()->replay(array_merge(
            $this->baseScenario(['low_value_ratio' => 0.45]),
            ['low_value_threshold' => 0.40],
        ));

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::DECISION_DEPRIORITIZE_LOW_VALUE, $r['brain_decision']);
    }
}
