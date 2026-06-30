<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmbitionBudgetGovernor;
use Tests\TestCase;

final class AtlasExternalBrainAmbitionBudgetGovernorTest extends TestCase
{
    private function svc(): AtlasExternalBrainAmbitionBudgetGovernor
    {
        return new AtlasExternalBrainAmbitionBudgetGovernor;
    }

    private function allModes(): array
    {
        return [
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT,
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_DEEP_ARCHITECTURE,
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_RESEARCH_ADAPTATION,
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_CONSOLIDATION,
        ];
    }

    private function allWithEvidence(): array
    {
        $ev = [];
        foreach ($this->allModes() as $m) {
            $ev[$m] = ["evidence_ref_{$m}_1", "evidence_ref_{$m}_2"];
        }
        return $ev;
    }

    // ── AC1: low-yield repeated waves reduce ambition budget → consolidation/self-healing ──

    public function test_ac1_low_yield_in_one_mode_triggers_reallocation_and_consolidation(): void
    {
        $r = $this->svc()->allocate([
            'quota_total'    => 100,
            'quota_consumed' => 20,
            'yield_by_mode'  => [
                AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT => 0.10, // low
            ],
            'attempted_modes' => [],
            'evidence_by_mode' => [],
        ]);

        $this->assertTrue($r['reallocation_triggered'], 'low yield must trigger reallocation');
        $this->assertSame('consolidation', $r['recommendation']);

        // The low-yield mode's weight must have been reduced
        $weights = $r['mode_weights'];
        $this->assertLessThan(
            0.40, // base weight
            $weights[AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT],
            'low-yield mode weight must be reduced below base'
        );
    }

    public function test_ac1_all_modes_low_yield_recommends_self_healing(): void
    {
        $lowYield = [];
        foreach ($this->allModes() as $m) {
            $lowYield[$m] = 0.05;
        }

        $r = $this->svc()->allocate([
            'quota_total'     => 100,
            'quota_consumed'  => 50,
            'yield_by_mode'   => $lowYield,
            'attempted_modes' => [],
            'evidence_by_mode' => [],
        ]);

        $this->assertSame('self_healing', $r['recommendation'],
            'all modes low-yield must trigger self_healing recommendation');
    }

    public function test_ac1_low_yield_mode_weight_shifts_to_deeper_mode(): void
    {
        $r = $this->svc()->allocate([
            'yield_by_mode' => [
                AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT => 0.10,
            ],
        ]);

        $weights = $r['mode_weights'];
        $this->assertGreaterThan(
            0.30, // base weight
            $weights[AtlasExternalBrainAmbitionBudgetGovernor::MODE_DEEP_ARCHITECTURE],
            'weight must have shifted to next deeper mode'
        );
    }

    // ── AC2: high-yield diverse findings increase ambition budget with explicit evidence ──

    public function test_ac2_high_yield_mode_with_diverse_evidence_receives_weight_boost(): void
    {
        $r = $this->svc()->allocate([
            'yield_by_mode'   => [
                AtlasExternalBrainAmbitionBudgetGovernor::MODE_DEEP_ARCHITECTURE => 0.90, // high
            ],
            'evidence_by_mode' => [
                AtlasExternalBrainAmbitionBudgetGovernor::MODE_DEEP_ARCHITECTURE => ['ref_a', 'ref_b', 'ref_c'],
            ],
        ]);

        $this->assertArrayHasKey(
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_DEEP_ARCHITECTURE,
            $r['escalation_evidence'],
            'high-yield mode with diverse evidence must appear in escalation_evidence'
        );

        $this->assertGreaterThan(
            0.30, // base weight
            $r['mode_weights'][AtlasExternalBrainAmbitionBudgetGovernor::MODE_DEEP_ARCHITECTURE],
            'high-yield mode weight must exceed base weight'
        );
    }

    public function test_ac2_escalation_evidence_records_yield_and_refs(): void
    {
        $refs = ['proof_1.php', 'proof_2.php'];
        $r = $this->svc()->allocate([
            'yield_by_mode'   => [AtlasExternalBrainAmbitionBudgetGovernor::MODE_RESEARCH_ADAPTATION => 0.85],
            'evidence_by_mode' => [AtlasExternalBrainAmbitionBudgetGovernor::MODE_RESEARCH_ADAPTATION => $refs],
        ]);

        $entry = $r['escalation_evidence'][AtlasExternalBrainAmbitionBudgetGovernor::MODE_RESEARCH_ADAPTATION] ?? null;
        $this->assertNotNull($entry);
        $this->assertSame(0.85, $entry['yield']);
        $this->assertSame($refs, $entry['evidence_refs']);
        $this->assertArrayHasKey('weight_boost', $entry);
        $this->assertGreaterThan(0.0, $entry['weight_boost']);
    }

    public function test_ac2_high_yield_without_diverse_evidence_does_not_boost(): void
    {
        // Only 1 ref — not diverse → no boost
        $r = $this->svc()->allocate([
            'yield_by_mode'   => [AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT => 0.95],
            'evidence_by_mode' => [AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT => ['single_ref']],
        ]);

        $this->assertArrayNotHasKey(
            AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT,
            $r['escalation_evidence'],
            'single-ref evidence must not qualify as diverse for a boost'
        );
    }

    // ── AC3: quota pressure alone cannot justify escalation ───────────────────

    public function test_ac3_full_quota_consumption_with_no_evidence_does_not_exhaust(): void
    {
        // All modes attempted but NONE have evidence → honest_exhausted must be FALSE
        $r = $this->svc()->allocate([
            'quota_total'     => 100,
            'quota_consumed'  => 100, // 100% quota consumed
            'attempted_modes' => $this->allModes(),
            'evidence_by_mode' => [],  // no evidence at all
        ]);

        $this->assertFalse($r['honest_exhausted'],
            'full quota consumption without evidence must NOT trigger honest_exhausted');
    }

    public function test_ac3_honest_exhausted_requires_evidence_for_every_mode(): void
    {
        // All attempted + all evidence → should be honest_exhausted
        $r = $this->svc()->allocate([
            'quota_total'     => 100,
            'quota_consumed'  => 80,
            'attempted_modes' => $this->allModes(),
            'evidence_by_mode' => $this->allWithEvidence(),
        ]);

        $this->assertTrue($r['honest_exhausted']);
        $this->assertSame(AtlasExternalBrainAmbitionBudgetGovernor::MODE_HONEST_EXHAUSTED, $r['next_mode']);
    }

    public function test_ac3_partial_evidence_prevents_escalation_even_at_max_quota(): void
    {
        // 3 out of 4 modes have evidence, 1 does not → not exhausted
        $evidence = $this->allWithEvidence();
        unset($evidence[AtlasExternalBrainAmbitionBudgetGovernor::MODE_CONSOLIDATION]);

        $r = $this->svc()->allocate([
            'quota_total'     => 100,
            'quota_consumed'  => 99,
            'attempted_modes' => $this->allModes(),
            'evidence_by_mode' => $evidence,
        ]);

        $this->assertFalse($r['honest_exhausted']);
        $this->assertNotSame(AtlasExternalBrainAmbitionBudgetGovernor::MODE_HONEST_EXHAUSTED, $r['next_mode']);
    }

    // ── AC4: deterministic / provider-free ────────────────────────────────────

    public function test_ac4_identical_input_yields_identical_output(): void
    {
        $state = [
            'quota_total'     => 100,
            'quota_consumed'  => 30,
            'yield_by_mode'   => [AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT => 0.80],
            'evidence_by_mode' => [AtlasExternalBrainAmbitionBudgetGovernor::MODE_EASY_BUG_HUNT => ['r1', 'r2']],
        ];

        $this->assertSame(
            json_encode($this->svc()->allocate($state), JSON_UNESCAPED_SLASHES),
            json_encode($this->svc()->allocate($state), JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_ac4_recommendation_key_always_present(): void
    {
        $r = $this->svc()->allocate([]);
        $this->assertArrayHasKey('recommendation', $r);
        $this->assertContains($r['recommendation'], ['continue', 'consolidation', 'self_healing']);
    }

    public function test_ac4_escalation_evidence_key_always_present(): void
    {
        $r = $this->svc()->allocate([]);
        $this->assertArrayHasKey('escalation_evidence', $r);
        $this->assertIsArray($r['escalation_evidence']);
    }
}
