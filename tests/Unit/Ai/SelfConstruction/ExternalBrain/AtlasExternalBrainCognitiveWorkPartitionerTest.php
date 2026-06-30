<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCognitiveWorkPartitioner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCognitiveWorkPartitionerTest extends TestCase
{
    private function partitioner(): AtlasExternalBrainCognitiveWorkPartitioner
    {
        return new AtlasExternalBrainCognitiveWorkPartitioner;
    }

    private function phase(array $overrides = []): array
    {
        return array_merge(['id' => 'p1', 'description' => 'gather facts'], $overrides);
    }

    // ── AC4: output shape ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->partitioner()->partition([]);
        $this->assertSame(AtlasExternalBrainCognitiveWorkPartitioner::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('phase_plan', $r);
        $this->assertArrayHasKey('model_tier_hint', $r);
        $this->assertArrayHasKey('required_artifacts', $r);
        $this->assertArrayHasKey('escalation_points', $r);
        $this->assertArrayHasKey('fallback_plan', $r);
    }

    // ── AC2: phase type classification ────────────────────────────────────────

    public function test_explicit_type_extraction_accepted(): void
    {
        $r = $this->partitioner()->partition(['phases' => [$this->phase(['type' => 'extraction'])]]);
        $this->assertSame('extraction', $r['phase_plan'][0]['phase_type']);
    }

    public function test_keyword_gather_infers_extraction(): void
    {
        $r = $this->partitioner()->partition(['phases' => [$this->phase(['description' => 'gather all relevant facts'])]]);
        $this->assertSame('extraction', $r['phase_plan'][0]['phase_type']);
    }

    public function test_keyword_validate_infers_verification(): void
    {
        $r = $this->partitioner()->partition(['phases' => [$this->phase(['description' => 'validate the produced output'])]]);
        $this->assertSame('verification', $r['phase_plan'][0]['phase_type']);
    }

    public function test_keyword_critique_infers_critique(): void
    {
        $r = $this->partitioner()->partition(['phases' => [$this->phase(['description' => 'critique the proposed design'])]]);
        $this->assertSame('critique', $r['phase_plan'][0]['phase_type']);
    }

    public function test_keyword_draft_infers_synthesis(): void
    {
        $r = $this->partitioner()->partition(['phases' => [$this->phase(['description' => 'draft the final proposal'])]]);
        $this->assertSame('synthesis', $r['phase_plan'][0]['phase_type']);
    }

    public function test_high_blast_radius_forces_escalation_type(): void
    {
        // blast_radius >= 0.70 → escalation regardless of description.
        $r = $this->partitioner()->partition(['phases' => [$this->phase([
            'description'  => 'gather facts',
            'blast_radius' => 0.80,
        ])]]);
        $this->assertSame('escalation', $r['phase_plan'][0]['phase_type']);
    }

    // ── AC3: model tier assignment ────────────────────────────────────────────

    public function test_extraction_gets_small_model(): void
    {
        $r = $this->partitioner()->partition(['phases' => [$this->phase(['type' => 'extraction'])]]);
        $this->assertSame('small_model', $r['phase_plan'][0]['model_tier']);
    }

    public function test_verification_gets_small_model(): void
    {
        $r = $this->partitioner()->partition(['phases' => [$this->phase(['type' => 'verification'])]]);
        $this->assertSame('small_model', $r['phase_plan'][0]['model_tier']);
    }

    public function test_critique_gets_scaffolded(): void
    {
        $r = $this->partitioner()->partition(['phases' => [$this->phase(['type' => 'critique'])]]);
        $this->assertSame('scaffolded_small_model', $r['phase_plan'][0]['model_tier']);
    }

    public function test_synthesis_without_high_risk_gets_scaffolded(): void
    {
        $r = $this->partitioner()->partition([
            'phases'       => [$this->phase(['type' => 'synthesis'])],
            'risk_profile' => ['ambiguity' => 0.3, 'conflicting_evidence' => false],
        ]);
        $this->assertSame('scaffolded_small_model', $r['phase_plan'][0]['model_tier']);
    }

    public function test_synthesis_with_high_ambiguity_escalates_to_frontier(): void
    {
        $r = $this->partitioner()->partition([
            'phases'       => [$this->phase(['type' => 'synthesis'])],
            'risk_profile' => ['ambiguity' => 0.80],
        ]);
        $this->assertSame('frontier_model', $r['phase_plan'][0]['model_tier']);
        $this->assertSame('escalation',     $r['phase_plan'][0]['phase_type']);
    }

    public function test_synthesis_with_conflicting_evidence_escalates(): void
    {
        $r = $this->partitioner()->partition([
            'phases'       => [$this->phase(['type' => 'synthesis'])],
            'risk_profile' => ['conflicting_evidence' => true],
        ]);
        $this->assertSame('frontier_model', $r['phase_plan'][0]['model_tier']);
    }

    public function test_escalation_type_gets_frontier(): void
    {
        $r = $this->partitioner()->partition(['phases' => [$this->phase(['type' => 'escalation'])]]);
        $this->assertSame('frontier_model', $r['phase_plan'][0]['model_tier']);
    }

    // ── AC4: aggregate outputs ────────────────────────────────────────────────

    public function test_model_tier_hint_is_highest_tier_in_plan(): void
    {
        $r = $this->partitioner()->partition(['phases' => [
            $this->phase(['id' => 'a', 'type' => 'extraction']),
            $this->phase(['id' => 'b', 'type' => 'escalation']),
            $this->phase(['id' => 'c', 'type' => 'verification']),
        ]]);
        $this->assertSame('frontier_model', $r['model_tier_hint']);
    }

    public function test_escalation_points_lists_frontier_phase_ids(): void
    {
        $r = $this->partitioner()->partition(['phases' => [
            $this->phase(['id' => 'extract', 'type' => 'extraction']),
            $this->phase(['id' => 'synth',   'type' => 'escalation']),
        ]]);
        $this->assertContains('synth',   $r['escalation_points']);
        $this->assertNotContains('extract', $r['escalation_points']);
    }

    public function test_required_artifacts_union_across_all_phases(): void
    {
        $r = $this->partitioner()->partition(['phases' => [
            $this->phase(['id' => 'a', 'type' => 'extraction',   'produces_artifacts' => ['fact_list']]),
            $this->phase(['id' => 'b', 'type' => 'synthesis',    'produces_artifacts' => ['proposal']]),
        ]]);
        $this->assertContains('fact_list', $r['required_artifacts']);
        $this->assertContains('proposal',  $r['required_artifacts']);
    }

    public function test_fallback_plan_names_degraded_frontier_phases(): void
    {
        $r = $this->partitioner()->partition(['phases' => [
            $this->phase(['id' => 'risky', 'type' => 'escalation']),
        ]]);
        $this->assertContains('risky', $r['fallback_plan']['degraded_phases']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'phases' => [
                $this->phase(['id' => 'a', 'type' => 'extraction']),
                $this->phase(['id' => 'b', 'type' => 'synthesis']),
                $this->phase(['id' => 'c', 'blast_radius' => 0.9]),
            ],
            'risk_profile' => ['ambiguity' => 0.5],
        ];
        $a = $this->partitioner()->partition($facts);
        $b = $this->partitioner()->partition($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
