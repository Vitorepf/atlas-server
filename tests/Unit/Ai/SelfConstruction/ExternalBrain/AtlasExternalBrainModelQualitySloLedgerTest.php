<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainModelQualitySloLedger;
use Tests\TestCase;

final class AtlasExternalBrainModelQualitySloLedgerTest extends TestCase
{
    private function svc(): AtlasExternalBrainModelQualitySloLedger
    {
        return new AtlasExternalBrainModelQualitySloLedger;
    }

    private function row(
        string $tier,
        string $variant,
        string $class,
        int $sample,
        float $commit = 0.80,
        float $giveBack = 0.10,
        float $valueProof = 0.80,
        float $duplicate = 0.05,
        float $evidence = 0.80,
    ): array {
        return [
            'model_tier' => $tier,
            'scaffold_variant' => $variant,
            'task_class' => $class,
            'sample_size' => $sample,
            'commit_success_rate' => $commit,
            'give_back_rate' => $giveBack,
            'value_proof_rate' => $valueProof,
            'duplicate_rate' => $duplicate,
            'evidence_strength' => $evidence,
        ];
    }

    private function compute(array $rows): array
    {
        return $this->svc()->compute(['outcome_rows' => $rows]);
    }

    // ── green segment ──────────────────────────────────────────────────────────

    public function test_all_slos_passing_gives_green_status(): void
    {
        $r = $this->compute([$this->row('small', 'v1', 'refactor', 20)]);

        $this->assertSame('green', $r['slo_rows'][0]['status']);
        $this->assertSame([], $r['slo_rows'][0]['failing_slos']);
        $this->assertSame([], $r['failing_segments']);
    }

    // ── red segment ────────────────────────────────────────────────────────────

    public function test_commit_below_threshold_gives_red(): void
    {
        $r = $this->compute([$this->row('small', 'v1', 'refactor', 15, commit: 0.60)]);

        $this->assertSame('red', $r['slo_rows'][0]['status']);
        $this->assertContains('commit_success_rate', $r['slo_rows'][0]['failing_slos']);
        $this->assertContains('small:v1:refactor', $r['failing_segments']);
    }

    public function test_give_back_above_threshold_gives_red(): void
    {
        $r = $this->compute([$this->row('small', 'v1', 'refactor', 15, giveBack: 0.25)]);

        $this->assertContains('give_back_rate', $r['slo_rows'][0]['failing_slos']);
    }

    public function test_duplicate_rate_above_threshold_gives_red(): void
    {
        $r = $this->compute([$this->row('small', 'v1', 'refactor', 15, duplicate: 0.15)]);

        $this->assertContains('duplicate_rate', $r['slo_rows'][0]['failing_slos']);
    }

    // ── insufficient_evidence ─────────────────────────────────────────────────

    public function test_sample_below_minimum_is_insufficient_not_green(): void
    {
        $r = $this->compute([$this->row('small', 'v1', 'refactor', 5)]);

        $this->assertSame('insufficient_evidence', $r['slo_rows'][0]['status']);
        $this->assertSame([], $r['failing_segments']);
        $this->assertCount(1, $r['insufficient_segments']);
    }

    public function test_exact_min_sample_is_not_insufficient(): void
    {
        $r = $this->compute([$this->row('small', 'v1', 'refactor', 10)]);

        $this->assertNotSame('insufficient_evidence', $r['slo_rows'][0]['status']);
        $this->assertSame([], $r['insufficient_segments']);
    }

    // ── multiple segments ──────────────────────────────────────────────────────

    public function test_multiple_segments_tracked_independently(): void
    {
        $r = $this->compute([
            $this->row('small', 'v1', 'refactor', 20),              // green
            $this->row('frontier', 'v2', 'feature', 15, commit: 0.50),  // red
            $this->row('mid', 'v3', 'fix', 3),                      // insufficient
        ]);

        $statuses = array_column($r['slo_rows'], 'status');
        $this->assertContains('green', $statuses);
        $this->assertContains('red', $statuses);
        $this->assertContains('insufficient_evidence', $statuses);

        $this->assertCount(1, $r['failing_segments']);
        $this->assertCount(1, $r['insufficient_segments']);
    }

    // ── routing adjustments ───────────────────────────────────────────────────

    public function test_red_segment_gets_routing_adjustment(): void
    {
        $r = $this->compute([$this->row('small', 'v1', 'refactor', 15, commit: 0.60)]);

        $this->assertCount(1, $r['recommended_routing_adjustments']);
        $adj = $r['recommended_routing_adjustments'][0];
        $this->assertSame('small:v1:refactor', $adj['segment']);
        $this->assertArrayHasKey('adjustment', $adj);
    }

    public function test_three_or_more_failing_slos_recommends_downgrade(): void
    {
        // Fail commit, give_back, value_proof, duplicate → 4 failures
        $r = $this->compute([
            $this->row('small', 'v1', 'refactor', 15,
                commit: 0.50, giveBack: 0.30, valueProof: 0.40, duplicate: 0.20),
        ]);

        $this->assertSame('downgrade_tier', $r['recommended_routing_adjustments'][0]['adjustment']);
    }

    public function test_fewer_than_three_failing_slos_recommends_extra_validation(): void
    {
        $r = $this->compute([$this->row('small', 'v1', 'refactor', 15, commit: 0.60)]);

        $this->assertSame('add_extra_validation', $r['recommended_routing_adjustments'][0]['adjustment']);
    }

    // ── empty + schema ─────────────────────────────────────────────────────────

    public function test_empty_rows_returns_empty_output(): void
    {
        $r = $this->svc()->compute([]);

        $this->assertSame([], $r['slo_rows']);
        $this->assertSame([], $r['failing_segments']);
        $this->assertSame([], $r['insufficient_segments']);
        $this->assertSame([], $r['recommended_routing_adjustments']);
    }

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->compute([]);

        $this->assertSame(AtlasExternalBrainModelQualitySloLedger::SCHEMA, $r['schema_version']);
    }
}
