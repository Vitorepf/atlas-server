<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSimplificationOperatorReport;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainSimplificationOperatorReportTest extends TestCase
{
    private function svc(): AtlasExternalBrainSimplificationOperatorReport
    {
        return new AtlasExternalBrainSimplificationOperatorReport;
    }

    private function cleanInput(array $overrides = []): array
    {
        return array_merge([
            'fitness' => ['score' => 0.9, 'trend' => 'improving'],
            'risk' => ['high_risk_open_count' => 0, 'blockers' => []],
            'proof_debt' => ['missing_proof_count' => 0, 'items' => []],
            'outcomes' => ['give_back_rate' => 0.05, 'recent_failures' => []],
            'queue' => ['claimable_count' => 10, 'stale_count' => 0],
        ], $overrides);
    }

    // ── AC: go_report_case ─────────────────────────────────────────────────────

    public function test_go_report_case_all_sections_clean(): void
    {
        $r = $this->svc()->compose($this->cleanInput());

        $this->assertSame(AtlasExternalBrainSimplificationOperatorReport::RECOMMENDATION_GO, $r['recommendation']);
        $this->assertSame([], $r['blockers']);
        $this->assertSame('accelerate_next_batch', $r['next_batch_focus']);
    }

    // ── AC: hold_with_blockers_case ────────────────────────────────────────────

    public function test_hold_with_blockers_case_high_give_back_rate(): void
    {
        $r = $this->svc()->compose($this->cleanInput([
            'outcomes' => ['give_back_rate' => 0.5, 'recent_failures' => ['task-7', 'task-9']],
        ]));

        $this->assertSame(AtlasExternalBrainSimplificationOperatorReport::RECOMMENDATION_HOLD, $r['recommendation']);
        $this->assertContains('give_back_rate_high:0.5', $r['blockers']);
        $this->assertContains('recent_failure:task-7', $r['blockers']);
        $this->assertContains('recent_failure:task-9', $r['blockers']);
        $this->assertSame('reduce_give_back_rate_before_accelerating', $r['next_batch_focus']);
    }

    public function test_hold_with_blockers_case_declining_fitness(): void
    {
        $r = $this->svc()->compose($this->cleanInput([
            'fitness' => ['score' => 0.6, 'trend' => 'declining'],
        ]));

        $this->assertSame(AtlasExternalBrainSimplificationOperatorReport::RECOMMENDATION_HOLD, $r['recommendation']);
        $this->assertContains('fitness_declining', $r['blockers']);
        $this->assertSame('investigate_fitness_decline_before_accelerating', $r['next_batch_focus']);
    }

    public function test_hold_with_blockers_case_empty_queue(): void
    {
        $r = $this->svc()->compose($this->cleanInput([
            'queue' => ['claimable_count' => 0, 'stale_count' => 0],
        ]));

        $this->assertSame(AtlasExternalBrainSimplificationOperatorReport::RECOMMENDATION_HOLD, $r['recommendation']);
        $this->assertContains('queue_empty', $r['blockers']);
        $this->assertSame('replenish_queue_before_accelerating', $r['next_batch_focus']);
    }

    // ── repair: proof debt / high risk always win, never a mere warning ────────

    public function test_repair_when_missing_proof_present(): void
    {
        $r = $this->svc()->compose($this->cleanInput([
            'proof_debt' => ['missing_proof_count' => 3, 'items' => ['organ-a', 'organ-b']],
        ]));

        $this->assertSame(AtlasExternalBrainSimplificationOperatorReport::RECOMMENDATION_REPAIR, $r['recommendation']);
        $this->assertContains('missing_proof:3', $r['blockers']);
        $this->assertContains('missing_proof_item:organ-a', $r['blockers']);
        $this->assertContains('missing_proof_item:organ-b', $r['blockers']);
        $this->assertSame('close_proof_debt_before_next_batch', $r['next_batch_focus']);
    }

    public function test_repair_when_high_risk_open(): void
    {
        $r = $this->svc()->compose($this->cleanInput([
            'risk' => ['high_risk_open_count' => 2, 'blockers' => ['unreviewed_delete']],
        ]));

        $this->assertSame(AtlasExternalBrainSimplificationOperatorReport::RECOMMENDATION_REPAIR, $r['recommendation']);
        $this->assertContains('high_risk_open:2', $r['blockers']);
        $this->assertContains('risk_blocker:unreviewed_delete', $r['blockers']);
        $this->assertSame('resolve_high_risk_blockers_before_next_batch', $r['next_batch_focus']);
    }

    public function test_repair_takes_priority_over_hold_conditions(): void
    {
        $r = $this->svc()->compose($this->cleanInput([
            'proof_debt' => ['missing_proof_count' => 1, 'items' => []],
            'outcomes' => ['give_back_rate' => 0.8, 'recent_failures' => []],
            'fitness' => ['score' => 0.3, 'trend' => 'declining'],
            'queue' => ['claimable_count' => 0, 'stale_count' => 0],
        ]));

        $this->assertSame(AtlasExternalBrainSimplificationOperatorReport::RECOMMENDATION_REPAIR, $r['recommendation']);
        $this->assertSame('close_proof_debt_before_next_batch', $r['next_batch_focus']);
    }

    // ── stale queue is reported but does not by itself force hold/repair ─────

    public function test_stale_queue_reported_as_blocker_without_forcing_hold(): void
    {
        $r = $this->svc()->compose($this->cleanInput([
            'queue' => ['claimable_count' => 5, 'stale_count' => 3],
        ]));

        $this->assertSame(AtlasExternalBrainSimplificationOperatorReport::RECOMMENDATION_GO, $r['recommendation']);
        $this->assertContains('queue_stale:3', $r['blockers']);
    }

    // ── sections echoed back with normalized values ───────────────────────────

    public function test_sections_are_echoed_back_normalized(): void
    {
        $r = $this->svc()->compose($this->cleanInput());

        $this->assertSame(0.9, $r['fitness']['score']);
        $this->assertSame('improving', $r['fitness']['trend']);
        $this->assertSame(10, $r['queue']['claimable_count']);
    }

    // ── schema / determinism ───────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->compose([]);

        $this->assertSame(AtlasExternalBrainSimplificationOperatorReport::SCHEMA, $r['schema']);
    }

    public function test_missing_sections_default_safely_to_hold_via_empty_queue(): void
    {
        // No input at all -> claimable_count defaults to 0 -> queue_empty -> hold, never a
        // silent "go" on missing data.
        $r = $this->svc()->compose([]);

        $this->assertSame(AtlasExternalBrainSimplificationOperatorReport::RECOMMENDATION_HOLD, $r['recommendation']);
        $this->assertContains('queue_empty', $r['blockers']);
    }

    public function test_compose_is_deterministic(): void
    {
        $input = $this->cleanInput(['proof_debt' => ['missing_proof_count' => 1, 'items' => ['x']]]);

        $this->assertSame(
            $this->svc()->compose($input),
            $this->svc()->compose($input),
        );
    }
}
