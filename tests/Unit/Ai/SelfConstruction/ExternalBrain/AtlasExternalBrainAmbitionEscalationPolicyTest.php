<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmbitionEscalationPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasExternalBrainAmbitionEscalationPolicy:
 *   - Low-yield waves produce the next deterministic mode, never stop/pad/duplicate.
 *   - honest_exhausted is only returned after every mode has been attempted with evidence.
 *   - Policy is pure and the ladder order is deterministic.
 */
final class AtlasExternalBrainAmbitionEscalationPolicyTest extends TestCase
{
    private AtlasExternalBrainAmbitionEscalationPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new AtlasExternalBrainAmbitionEscalationPolicy;
    }

    // ---------- fresh state (no prior attempts) ----------

    public function test_no_prior_attempts_returns_first_ladder_mode(): void
    {
        $r = $this->policy->decide([]);
        $this->assertSame(AtlasExternalBrainAmbitionEscalationPolicy::MODE_CONTRACT_MISMATCH, $r['next_mode']);
        $this->assertFalse($r['honest_exhausted']);
        $this->assertNotEmpty($r['rationale']);
    }

    // ---------- sequential escalation ----------

    public function test_after_first_mode_returns_second(): void
    {
        $r = $this->policy->decide(['attempted_modes' => [AtlasExternalBrainAmbitionEscalationPolicy::MODE_CONTRACT_MISMATCH]]);
        $this->assertSame(AtlasExternalBrainAmbitionEscalationPolicy::MODE_CROSS_DOMAIN_PATTERN, $r['next_mode']);
        $this->assertFalse($r['honest_exhausted']);
    }

    public function test_after_five_modes_returns_sixth(): void
    {
        $five = [
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CONTRACT_MISMATCH,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CROSS_DOMAIN_PATTERN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RESEARCH_BACKED_DESIGN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_ARCHITECTURE_SIMPLIFICATION,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RUNTIME_HEALTH,
        ];
        $r = $this->policy->decide(['attempted_modes' => $five]);
        $this->assertSame(AtlasExternalBrainAmbitionEscalationPolicy::MODE_CERTIFICATION_GAP, $r['next_mode']);
        $this->assertFalse($r['honest_exhausted']);
    }

    // ---------- honest_exhausted gate ----------

    public function test_honest_exhausted_only_after_all_modes_have_evidence(): void
    {
        $all = [
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CONTRACT_MISMATCH,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CROSS_DOMAIN_PATTERN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RESEARCH_BACKED_DESIGN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_ARCHITECTURE_SIMPLIFICATION,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RUNTIME_HEALTH,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CERTIFICATION_GAP,
        ];
        $evidenceByMode = [];
        foreach ($all as $m) {
            $evidenceByMode[$m] = ['evidence_ref:receipt-'.$m];
        }

        $r = $this->policy->decide(['attempted_modes' => $all, 'evidence_by_mode' => $evidenceByMode]);
        $this->assertTrue($r['honest_exhausted']);
        $this->assertSame(AtlasExternalBrainAmbitionEscalationPolicy::MODE_HONEST_EXHAUSTED, $r['next_mode']);
    }

    public function test_all_attempted_but_missing_evidence_on_one_is_not_exhausted(): void
    {
        $all = [
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CONTRACT_MISMATCH,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CROSS_DOMAIN_PATTERN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RESEARCH_BACKED_DESIGN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_ARCHITECTURE_SIMPLIFICATION,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RUNTIME_HEALTH,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CERTIFICATION_GAP,
        ];
        // Only 5 modes have evidence; certification_gap has none.
        $evidenceByMode = [];
        foreach (array_slice($all, 0, 5) as $m) {
            $evidenceByMode[$m] = ['ref:'.$m];
        }

        $r = $this->policy->decide(['attempted_modes' => $all, 'evidence_by_mode' => $evidenceByMode]);
        $this->assertFalse($r['honest_exhausted']);
        // Should route back to the mode lacking evidence.
        $this->assertSame(AtlasExternalBrainAmbitionEscalationPolicy::MODE_CERTIFICATION_GAP, $r['next_mode']);
    }

    // ---------- determinism ----------

    public function test_same_state_produces_byte_identical_result(): void
    {
        $state = [
            'attempted_modes' => [
                AtlasExternalBrainAmbitionEscalationPolicy::MODE_CONTRACT_MISMATCH,
                AtlasExternalBrainAmbitionEscalationPolicy::MODE_CROSS_DOMAIN_PATTERN,
            ],
            'evidence_by_mode' => [
                AtlasExternalBrainAmbitionEscalationPolicy::MODE_CONTRACT_MISMATCH => ['ref:a'],
            ],
            'wave_number' => 3,
            'wave_yield'  => 0.12,
        ];

        $this->assertSame(
            json_encode($this->policy->decide($state), JSON_UNESCAPED_SLASHES),
            json_encode($this->policy->decide($state), JSON_UNESCAPED_SLASHES),
        );
    }

    // ---------- no stop / no pad / no duplicate ----------

    public function test_low_yield_never_produces_stop_or_padding_before_all_modes_done(): void
    {
        $attempted = [];
        $policy    = $this->policy;

        foreach (range(1, 6) as $i) {
            $r = $policy->decide(['attempted_modes' => $attempted]);
            $this->assertNotSame(AtlasExternalBrainAmbitionEscalationPolicy::MODE_HONEST_EXHAUSTED, $r['next_mode'], "wave $i must not stop early");
            $this->assertFalse($r['honest_exhausted'], "wave $i must not be exhausted before all modes tried");
            $this->assertNotEmpty($r['rationale'], "wave $i must emit a rationale");
            $attempted[] = $r['next_mode'];
        }

        // After 6 attempts, even without evidence, still NOT honest_exhausted (evidence required).
        $r = $policy->decide(['attempted_modes' => $attempted]);
        $this->assertFalse($r['honest_exhausted'], 'no evidence recorded → must not be honest_exhausted');
    }

    public function test_schema_is_correct(): void
    {
        $r = $this->policy->decide([]);
        $this->assertSame(AtlasExternalBrainAmbitionEscalationPolicy::SCHEMA, $r['schema']);
    }

    // ---------- anti-template-farm: volume irrelevant without evidence ----------

    public function test_high_wave_number_without_evidence_does_not_short_circuit_to_exhausted(): void
    {
        // A very high wave_number with no evidence must never trigger honest_exhausted.
        $r = $this->policy->decide(['wave_number' => 99, 'wave_yield' => 0.01, 'attempted_modes' => []]);
        $this->assertFalse($r['honest_exhausted']);
        $this->assertNotSame(AtlasExternalBrainAmbitionEscalationPolicy::MODE_HONEST_EXHAUSTED, $r['next_mode']);
    }

    public function test_wave_metadata_does_not_affect_mode_selection(): void
    {
        // wave_number and wave_yield are anti-template-farm signals only; mode selection is evidence+attempt driven.
        $with    = $this->policy->decide(['wave_number' => 50, 'wave_yield' => 0.01]);
        $without = $this->policy->decide([]);
        $this->assertSame($with['next_mode'], $without['next_mode']);
    }

    // ---------- refusal: attempts without evidence must not stop the ladder ----------

    public function test_all_modes_attempted_with_no_evidence_refuses_to_claim_exhausted(): void
    {
        $all = [
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CONTRACT_MISMATCH,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CROSS_DOMAIN_PATTERN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RESEARCH_BACKED_DESIGN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_ARCHITECTURE_SIMPLIFICATION,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RUNTIME_HEALTH,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CERTIFICATION_GAP,
        ];
        $r = $this->policy->decide(['attempted_modes' => $all, 'evidence_by_mode' => []]);
        $this->assertFalse($r['honest_exhausted']);
        $this->assertNotSame(AtlasExternalBrainAmbitionEscalationPolicy::MODE_HONEST_EXHAUSTED, $r['next_mode']);
    }
}
