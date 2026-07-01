<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainValueDecayMonitor;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainValueDecayMonitorTest extends TestCase
{
    private function monitor(): AtlasExternalBrainValueDecayMonitor
    {
        return new AtlasExternalBrainValueDecayMonitor;
    }

    private function monitorOne(array $task): array
    {
        $result = $this->monitor()->monitor(['tasks' => [$task]]);

        return $result['per_task'][0];
    }

    // ── AC2: fresh high-leverage stays keep/active; stale/superseded flagged ──

    public function test_fresh_high_leverage_task_stays_keep(): void
    {
        $entry = $this->monitorOne([
            'id' => 'fresh-1',
            'queued_at_days_ago' => 1,
            'has_value_proof' => true,
        ]);

        $this->assertSame('fresh', $entry['value_status']);
        $this->assertSame('retain', $entry['recommended_action']);
        $this->assertNull($entry['next_evidence_needed']);
    }

    public function test_stale_task_without_value_proof_is_downgraded_or_retired(): void
    {
        $entry = $this->monitorOne([
            'id' => 'stale-1',
            'queued_at_days_ago' => 45,
            'has_value_proof' => false,
        ]);

        $this->assertSame('expired', $entry['value_status']);
        $this->assertSame('retire', $entry['recommended_action']);
    }

    public function test_superseded_task_is_flagged_for_retirement(): void
    {
        $entry = $this->monitorOne([
            'id' => 'superseded-1',
            'superseded_target' => true,
        ]);

        $this->assertSame('retire', $entry['recommended_action']);
        $this->assertContains('superseded_target', $entry['reasons']);
    }

    // ── AC3: decay uses age, leverage, unlocks, confidence, superseded — not raw depth ──

    public function test_high_blocking_count_keeps_task_despite_age_and_no_proof(): void
    {
        $entry = $this->monitorOne([
            'id' => 'load-bearing-1',
            'queued_at_days_ago' => 60,
            'has_value_proof' => false,
            'blocking_count' => 5,
        ]);

        $this->assertSame('retain', $entry['recommended_action']);
    }

    public function test_scope_drift_with_high_current_value_score_prefers_respec_over_retire(): void
    {
        $entry = $this->monitorOne([
            'id' => 'valuable-1',
            'prerequisites_changed' => true,
            'landscape_shifted' => true,
            'has_value_proof' => false,
            'current_value_score' => 0.9,
        ]);

        $this->assertSame('refresh', $entry['recommended_action']);
        $this->assertContains('prerequisite_drift', $entry['reasons']);
    }

    public function test_stale_evidence_with_repeated_give_back_and_low_muscle_success_consolidates(): void
    {
        $entry = $this->monitorOne([
            'id' => 'underperforming-1',
            'stale_evidence_age' => 20,
            'give_back_count' => 4,
            'muscle_success_rate' => 0.1,
        ]);

        $this->assertSame('consolidate', $entry['recommended_action']);
        $this->assertNotNull($entry['next_evidence_needed']);
    }

    // ── AC4: deterministic decay_score, action, reasons, next_evidence_needed ─

    public function test_output_includes_deterministic_decay_score_action_reasons(): void
    {
        $result = $this->monitor()->monitor(['tasks' => [
            ['id' => 't1', 'prerequisites_changed' => true, 'landscape_shifted' => true],
        ]]);

        $entry = $result['per_task'][0];
        $this->assertIsFloat($entry['decay_score']);
        $this->assertGreaterThan(0.0, $entry['decay_score']);
        $this->assertNotEmpty($entry['recommended_action']);
        $this->assertIsArray($entry['reasons']);
        $this->assertArrayHasKey('next_evidence_needed', $entry);
    }

    public function test_uncertain_respec_case_names_concrete_next_evidence_needed(): void
    {
        $entry = $this->monitorOne([
            'id' => 'blocked-1',
            'blocked_dependency' => true,
        ]);

        $this->assertSame('refresh', $entry['recommended_action']);
        $this->assertSame('blocking_dependency_resolution_status', $entry['next_evidence_needed']);
    }

    public function test_certain_keep_and_retire_cases_have_null_next_evidence_needed(): void
    {
        $keep = $this->monitorOne(['id' => 'keep-1', 'has_value_proof' => true]);
        $retire = $this->monitorOne(['id' => 'retire-1', 'superseded_target' => true]);

        $this->assertNull($keep['next_evidence_needed']);
        $this->assertNull($retire['next_evidence_needed']);
    }
}
