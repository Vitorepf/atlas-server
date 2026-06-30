<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskFamilyYieldModel;
use Tests\TestCase;

final class AtlasExternalBrainTaskFamilyYieldModelTest extends TestCase
{
    private AtlasExternalBrainTaskFamilyYieldModel $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasExternalBrainTaskFamilyYieldModel;
    }

    private function family(string $id, int $success, int $giveBack, int $poison = 0, int $quarantine = 0): array
    {
        return [
            'family_id'       => $id,
            'success_count'   => $success,
            'give_back_count' => $giveBack,
            'poison_count'    => $poison,
            'quarantine_count' => $quarantine,
        ];
    }

    private function model(array $families): array
    {
        return $this->service->model(['families' => $families]);
    }

    // ── AC1: yield computed from outcome counts ───────────────────────────────

    public function test_yield_computed_from_success_give_back_poison_quarantine_counts(): void
    {
        // 8 success, 2 give_back → green_rate=0.8, poison_rate=0.2 → yield=0.8-0.2×0.3=0.74
        $r = $this->model([$this->family('eng', 8, 2)]);

        $f = $r['family_yields'][0];
        $this->assertSame('eng', $f['family_id']);
        $this->assertSame(0.74, round((float) $f['yield_score'], 4));
        $this->assertSame('high_yield', $f['classification']);
    }

    public function test_all_four_count_fields_are_used(): void
    {
        // 4 success, 1 give_back, 1 poison, 2 quarantine → total=8
        // green_rate=4/8=0.5; poison_rate=4/8=0.5 → yield=max(0, 0.5-0.5×0.3)=0.35
        $r = $this->model([$this->family('mixed', 4, 1, 1, 2)]);

        $f = $r['family_yields'][0];
        $this->assertSame(0.35, round((float) $f['yield_score'], 4));
        $this->assertSame('moderate_yield', $f['classification']);
    }

    public function test_high_poison_rate_triggers_high_poison_rate_penalty(): void
    {
        // 3 success, 3 give_back, 1 poison → poison_rate = 4/7 > 0.50 → penalty = high_poison_rate_penalty
        $r = $this->model([$this->family('toxic', 3, 3, 1)]);

        $f = $r['family_yields'][0];
        $this->assertSame('high_poison_rate_penalty', $f['penalty_applied']);
        $this->assertContains('high_poison_rate', $f['reasons']);
    }

    // ── AC2: low-sample families marked insufficient_evidence ─────────────────

    public function test_family_with_fewer_than_min_sample_is_insufficient_evidence(): void
    {
        // 1 success, 1 give_back = 2 total < MIN_SAMPLE_FOR_EVIDENCE (3)
        $r = $this->model([$this->family('new', 1, 1)]);

        $f = $r['family_yields'][0];
        $this->assertSame('insufficient_evidence', $f['classification']);
        $this->assertSame(0.0, (float) $f['yield_score']);
        $this->assertSame(0.0, (float) $f['roi_score']);
        $this->assertContains('insufficient_sample', $f['reasons']);
        $this->assertSame(1, $r['summary']['insufficient_evidence']);
    }

    public function test_insufficient_evidence_family_cannot_outrank_proven_high_yield(): void
    {
        // new family: only 2 attempts → insufficient_evidence
        // proven family: 5 success, 1 give_back → high_yield
        $families = [
            $this->family('new', 1, 1),            // 2 total → insufficient_evidence
            $this->family('proven', 5, 1),          // 6 total, high yield
        ];

        $r = $this->model($families);

        $ranked = $r['ranked_families'];
        $this->assertSame('proven', $ranked[0]);  // proven comes first
        $this->assertSame('new', $ranked[1]);      // insufficient after proven
    }

    public function test_three_total_is_sufficient_evidence(): void
    {
        // 2 success, 1 give_back = 3 total = exactly MIN_SAMPLE_FOR_EVIDENCE → not insufficient
        $r = $this->model([$this->family('border', 2, 1)]);

        $this->assertNotSame('insufficient_evidence', $r['family_yields'][0]['classification']);
    }

    // ── AC3: recommended_family_actions emitted ───────────────────────────────

    public function test_recommended_family_actions_key_is_present(): void
    {
        $r = $this->model([$this->family('eng', 8, 1)]);

        $this->assertArrayHasKey('recommended_family_actions', $r);
        $this->assertNotEmpty($r['recommended_family_actions']);
        $this->assertArrayHasKey('family_id', $r['recommended_family_actions'][0]);
        $this->assertArrayHasKey('action',    $r['recommended_family_actions'][0]);
    }

    public function test_promote_action_for_high_yield_family(): void
    {
        // 9 success, 1 give_back → high_yield, low poison → promote
        $r = $this->model([$this->family('eng', 9, 1)]);

        $action = $r['recommended_family_actions'][0]['action'];
        $this->assertSame('promote', $action);
    }

    public function test_quarantine_pattern_action_for_high_poison_rate(): void
    {
        // poison_rate = 4/7 > 0.50 → quarantine_pattern
        $r = $this->model([$this->family('toxic', 3, 3, 1)]);

        $action = $r['recommended_family_actions'][0]['action'];
        $this->assertSame('quarantine_pattern', $action);
    }

    public function test_watch_action_for_moderate_yield_family(): void
    {
        // 5 success, 4 give_back → green=5/9≈0.556, poison=4/9≈0.444 (<0.50 ceiling)
        // yield ≈ 0.556 - 0.444×0.3 ≈ 0.423 → moderate_yield → watch
        $r = $this->model([$this->family('avg', 5, 4)]);

        $action = $r['recommended_family_actions'][0]['action'];
        $this->assertSame('watch', $action);
    }

    public function test_watch_action_for_insufficient_evidence_family(): void
    {
        $r = $this->model([$this->family('new', 1, 0)]);

        $action = $r['recommended_family_actions'][0]['action'];
        $this->assertSame('watch', $action);
    }

    // ── AC4: pure and deterministic ───────────────────────────────────────────

    public function test_model_is_deterministic(): void
    {
        $families = [
            $this->family('a', 8, 2),
            $this->family('b', 1, 1),
            $this->family('c', 4, 4, 1),
        ];

        $a = $this->model($families);
        $b = $this->model($families);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
