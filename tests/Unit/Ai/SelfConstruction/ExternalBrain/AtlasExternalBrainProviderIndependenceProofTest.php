<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderIndependenceProof;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainProviderIndependenceProofTest extends TestCase
{
    private AtlasExternalBrainProviderIndependenceProof $proof;

    protected function setUp(): void
    {
        $this->proof = new AtlasExternalBrainProviderIndependenceProof;
    }

    private function fullPhase(string $phase, array $overrides = []): array
    {
        return array_merge([
            'phase'                   => $phase,
            'has_local_evidence_path' => true,
            'has_scaffold_fallback'   => true,
            'has_benchmark_coverage'  => true,
            'has_rollback_path'       => true,
        ], $overrides);
    }

    /** Returns all 7 mandatory phases fully covered. */
    private function allMandatory(array $overridesByPhase = []): array
    {
        return array_map(
            fn ($p) => $this->fullPhase($p, $overridesByPhase[$p] ?? []),
            AtlasExternalBrainProviderIndependenceProof::MANDATORY_PHASES,
        );
    }

    // ── Schema / required keys ────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->proof->prove([]);

        foreach (['schema', 'independent', 'provider_required_phases', 'fallback_coverage', 'optional_frontier_accelerators', 'missing_proofs'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainProviderIndependenceProof::SCHEMA, $result['schema']);
    }

    // ── AC3: all 7 mandatory phases present and fully covered → independent ───

    public function test_all_mandatory_phases_fully_covered_returns_independent(): void
    {
        $result = $this->proof->prove(['proof_claims' => $this->allMandatory()]);

        $this->assertTrue($result['independent']);
        $this->assertEmpty($result['provider_required_phases']);
        $this->assertEmpty($result['missing_proofs']);
    }

    // ── AC3: missing any mandatory phase → not independent ───────────────────

    public function test_missing_mandatory_phase_blocks_independence(): void
    {
        // Only 6 of 7 — omit queue_self_healing
        $phases = array_filter(
            AtlasExternalBrainProviderIndependenceProof::MANDATORY_PHASES,
            fn ($p) => $p !== 'queue_self_healing',
        );
        $result = $this->proof->prove([
            'proof_claims' => array_map(fn ($p) => $this->fullPhase($p), array_values($phases)),
        ]);

        $this->assertFalse($result['independent']);
        $missingPhases = array_column($result['missing_proofs'], 'phase');
        $this->assertContains('queue_self_healing', $missingPhases);
    }

    // ── AC3: all 7 mandatory phase names wired ────────────────────────────────

    public function test_mandatory_phases_constant_has_all_seven(): void
    {
        $this->assertCount(7, AtlasExternalBrainProviderIndependenceProof::MANDATORY_PHASES);
        foreach (['task_origination', 'task_validation', 'outcome_learning', 'queue_self_healing', 'scaffold_promotion', 'rollback', 'autonomy_stop_go'] as $p) {
            $this->assertContains($p, AtlasExternalBrainProviderIndependenceProof::MANDATORY_PHASES);
        }
    }

    // ── AC2: reject requires_live_provider ───────────────────────────────────

    public function test_requires_live_provider_blocks_independence(): void
    {
        $claims = $this->allMandatory(['task_origination' => ['requires_live_provider' => true]]);
        $result = $this->proof->prove(['proof_claims' => $claims]);

        $this->assertFalse($result['independent']);
        $reasons = $this->reasonsForPhase($result, 'task_origination');
        $this->assertContains('requires_live_provider', $reasons);
    }

    // ── AC2: reject requires_manual_provider_selection ───────────────────────

    public function test_requires_manual_provider_selection_blocks_independence(): void
    {
        $claims = $this->allMandatory(['task_validation' => ['requires_manual_provider_selection' => true]]);
        $result = $this->proof->prove(['proof_claims' => $claims]);

        $this->assertFalse($result['independent']);
        $this->assertContains('requires_manual_provider_selection', $this->reasonsForPhase($result, 'task_validation'));
    }

    // ── AC2: reject has_provider_specific_traces ──────────────────────────────

    public function test_provider_specific_traces_blocks_independence(): void
    {
        $claims = $this->allMandatory(['rollback' => ['has_provider_specific_traces' => true]]);
        $result = $this->proof->prove(['proof_claims' => $claims]);

        $this->assertFalse($result['independent']);
        $this->assertContains('has_provider_specific_traces', $this->reasonsForPhase($result, 'rollback'));
    }

    // ── AC2: reject requires_operator_intervention ────────────────────────────

    public function test_operator_intervention_blocks_independence(): void
    {
        $claims = $this->allMandatory(['autonomy_stop_go' => ['requires_operator_intervention' => true]]);
        $result = $this->proof->prove(['proof_claims' => $claims]);

        $this->assertFalse($result['independent']);
        $this->assertContains('requires_operator_intervention', $this->reasonsForPhase($result, 'autonomy_stop_go'));
    }

    // ── AC2: reject requires_frontier_only_judgement ──────────────────────────

    public function test_frontier_only_judgement_blocks_independence(): void
    {
        $claims = $this->allMandatory(['outcome_learning' => ['requires_frontier_only_judgement' => true]]);
        $result = $this->proof->prove(['proof_claims' => $claims]);

        $this->assertFalse($result['independent']);
        $this->assertContains('requires_frontier_only_judgement', $this->reasonsForPhase($result, 'outcome_learning'));
    }

    // ── AC2: missing coverage types flagged in missing_proofs ─────────────────

    public function test_missing_coverage_flagged_per_phase(): void
    {
        $claims = $this->allMandatory([
            'scaffold_promotion' => [
                'has_local_evidence_path' => false,
                'has_scaffold_fallback'   => false,
            ],
        ]);
        $result = $this->proof->prove(['proof_claims' => $claims]);

        $this->assertFalse($result['independent']);
        $entry = $this->missingProofForPhase($result, 'scaffold_promotion');
        $this->assertContains('has_local_evidence_path', $entry['missing_coverage']);
        $this->assertContains('has_scaffold_fallback', $entry['missing_coverage']);
        $this->assertNotContains('has_benchmark_coverage', $entry['missing_coverage']);
    }

    // ── AC4: optional frontier accelerators preserved separately ─────────────

    public function test_optional_accelerators_preserved_and_do_not_affect_independence(): void
    {
        $claims = $this->allMandatory([
            'task_origination' => ['optional_frontier_accelerators' => ['gpt-5', 'claude-fable']],
        ]);
        $result = $this->proof->prove(['proof_claims' => $claims]);

        $this->assertTrue($result['independent']);
        $this->assertSame(['gpt-5', 'claude-fable'], $result['optional_frontier_accelerators']['task_origination']);
    }

    // ── Fallback_coverage reflects input flags ────────────────────────────────

    public function test_fallback_coverage_reflects_input(): void
    {
        $claims = $this->allMandatory([
            'queue_self_healing' => [
                'has_local_evidence_path' => true,
                'has_scaffold_fallback'   => false,
                'has_benchmark_coverage'  => true,
                'has_rollback_path'       => false,
            ],
        ]);
        $result = $this->proof->prove(['proof_claims' => $claims]);

        $cov = $result['fallback_coverage']['queue_self_healing'];
        $this->assertTrue($cov['local_evidence']);
        $this->assertFalse($cov['scaffold']);
        $this->assertTrue($cov['benchmark']);
        $this->assertFalse($cov['rollback']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = ['proof_claims' => $this->allMandatory()];

        $this->assertSame($this->proof->prove($input), $this->proof->prove($input));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function reasonsForPhase(array $result, string $phase): array
    {
        foreach ($result['provider_required_phases'] as $entry) {
            if ($entry['phase'] === $phase) {
                return $entry['reasons'];
            }
        }
        return [];
    }

    private function missingProofForPhase(array $result, string $phase): array
    {
        foreach ($result['missing_proofs'] as $entry) {
            if ($entry['phase'] === $phase) {
                return $entry;
            }
        }
        $this->fail("No missing_proof entry for phase '{$phase}'.");
    }

    private function classificationForPhase(array $result, string $phase): array
    {
        foreach ($result['circuit_classifications'] as $entry) {
            if ($entry['phase'] === $phase) {
                return $entry;
            }
        }
        $this->fail("No circuit_classifications entry for phase '{$phase}'.");
    }

    // ── Circuit classification ────────────────────────────────────────────────

    public function test_fully_covered_phase_with_no_accelerators_is_provider_independent(): void
    {
        $result = $this->proof->prove(['proof_claims' => [$this->fullPhase('task_origination')]]);

        $classification = $this->classificationForPhase($result, 'task_origination');
        $this->assertSame(AtlasExternalBrainProviderIndependenceProof::CLASSIFICATION_PROVIDER_INDEPENDENT, $classification['classification']);
        $this->assertFalse($classification['missing_fallback']);
    }

    public function test_phase_with_optional_frontier_accelerator_is_provider_accelerated_not_dependent(): void
    {
        $result = $this->proof->prove(['proof_claims' => [
            $this->fullPhase('task_origination', ['optional_frontier_accelerators' => ['frontier_model_x']]),
        ]]);

        $classification = $this->classificationForPhase($result, 'task_origination');
        $this->assertSame(AtlasExternalBrainProviderIndependenceProof::CLASSIFICATION_PROVIDER_ACCELERATED, $classification['classification']);
        $this->assertTrue($result['independent'] || $result['provider_required_phases'] === []);
    }

    public function test_phase_requiring_live_provider_is_provider_dependent(): void
    {
        $result = $this->proof->prove(['proof_claims' => [
            $this->fullPhase('task_origination', ['requires_live_provider' => true]),
        ]]);

        $classification = $this->classificationForPhase($result, 'task_origination');
        $this->assertSame(AtlasExternalBrainProviderIndependenceProof::CLASSIFICATION_PROVIDER_DEPENDENT, $classification['classification']);
    }

    public function test_provider_dependent_phase_without_fallback_fails_closed_with_missing_fallback(): void
    {
        $result = $this->proof->prove(['proof_claims' => [
            [
                'phase' => 'task_origination',
                'has_local_evidence_path' => false,
                'has_scaffold_fallback' => false,
                'has_benchmark_coverage' => false,
                'has_rollback_path' => false,
                'requires_live_provider' => true,
            ],
        ]]);

        $classification = $this->classificationForPhase($result, 'task_origination');
        $this->assertSame(AtlasExternalBrainProviderIndependenceProof::CLASSIFICATION_PROVIDER_DEPENDENT, $classification['classification']);
        $this->assertTrue($classification['missing_fallback']);
        $this->assertNotEmpty($classification['missing_fallback_types']);
        $this->assertFalse($result['independent']);
    }

    public function test_provider_dependent_phase_with_full_fallback_does_not_report_missing_fallback(): void
    {
        $result = $this->proof->prove(['proof_claims' => [
            $this->fullPhase('task_origination', ['requires_live_provider' => true]),
        ]]);

        $classification = $this->classificationForPhase($result, 'task_origination');
        $this->assertSame(AtlasExternalBrainProviderIndependenceProof::CLASSIFICATION_PROVIDER_DEPENDENT, $classification['classification']);
        $this->assertFalse($classification['missing_fallback']);
    }
}
