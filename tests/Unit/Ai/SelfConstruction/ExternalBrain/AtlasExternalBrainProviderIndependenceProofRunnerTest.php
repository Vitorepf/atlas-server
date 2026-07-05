<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderAgnosticBenchmarkSet;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderIndependenceProof;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderIndependenceProofRunner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderPoolOutcomeAttributor;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderPoolPatchDryRunPlan;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainProviderIndependenceProofRunnerTest extends TestCase
{
    // ── Full coverage for all 7 mandatory phases (redundant pools) ────────────

    private const FULL_PROOF_CLAIMS = [
        [
            'phase'                             => 'task_origination',
            'has_local_evidence_path'           => true,
            'has_scaffold_fallback'             => true,
            'has_benchmark_coverage'            => true,
            'has_rollback_path'                 => true,
            'has_local_judgement_fallback'      => true,
        ],
        [
            'phase'                             => 'task_validation',
            'has_local_evidence_path'           => true,
            'has_scaffold_fallback'             => true,
            'has_benchmark_coverage'            => true,
            'has_rollback_path'                 => true,
            'has_local_judgement_fallback'      => true,
        ],
        [
            'phase'                             => 'outcome_learning',
            'has_local_evidence_path'           => true,
            'has_scaffold_fallback'             => true,
            'has_benchmark_coverage'            => true,
            'has_rollback_path'                 => true,
            'has_local_judgement_fallback'      => true,
        ],
        [
            'phase'                             => 'queue_self_healing',
            'has_local_evidence_path'           => true,
            'has_scaffold_fallback'             => true,
            'has_benchmark_coverage'            => true,
            'has_rollback_path'                 => true,
            'has_local_judgement_fallback'      => true,
        ],
        [
            'phase'                             => 'scaffold_promotion',
            'has_local_evidence_path'           => true,
            'has_scaffold_fallback'             => true,
            'has_benchmark_coverage'            => true,
            'has_rollback_path'                 => true,
            'has_local_judgement_fallback'      => true,
        ],
        [
            'phase'                             => 'rollback',
            'has_local_evidence_path'           => true,
            'has_scaffold_fallback'             => true,
            'has_benchmark_coverage'            => true,
            'has_rollback_path'                 => true,
            'has_local_judgement_fallback'      => true,
        ],
        [
            'phase'                             => 'autonomy_stop_go',
            'has_local_evidence_path'           => true,
            'has_scaffold_fallback'             => true,
            'has_benchmark_coverage'            => true,
            'has_rollback_path'                 => true,
            'has_local_judgement_fallback'      => true,
        ],
    ];

    /** One phase requires a live provider — the load-bearing single provider. */
    private const LOAD_BEARING_CLAIMS = [
        [
            'phase'                             => 'task_origination',
            'has_local_evidence_path'           => true,
            'has_scaffold_fallback'             => true,
            'has_benchmark_coverage'            => true,
            'has_rollback_path'                 => true,
            'has_local_judgement_fallback'      => true,
        ],
        [
            'phase'                             => 'task_validation',
            'has_local_evidence_path'           => false,
            'has_scaffold_fallback'             => false,
            'has_benchmark_coverage'            => false,
            'has_rollback_path'                 => false,
            'has_local_judgement_fallback'      => false,
            'requires_live_provider'            => true,
        ],
        [
            'phase'                             => 'outcome_learning',
            'has_local_evidence_path'           => true,
            'has_scaffold_fallback'             => true,
            'has_benchmark_coverage'            => true,
            'has_rollback_path'                 => true,
            'has_local_judgement_fallback'      => true,
        ],
        [
            'phase'                             => 'queue_self_healing',
            'has_local_evidence_path'           => true,
            'has_scaffold_fallback'             => true,
            'has_benchmark_coverage'            => true,
            'has_rollback_path'                 => true,
            'has_local_judgement_fallback'      => true,
        ],
        [
            'phase'                             => 'scaffold_promotion',
            'has_local_evidence_path'           => true,
            'has_scaffold_fallback'             => true,
            'has_benchmark_coverage'            => true,
            'has_rollback_path'                 => true,
            'has_local_judgement_fallback'      => true,
        ],
        [
            'phase'                             => 'rollback',
            'has_local_evidence_path'           => true,
            'has_scaffold_fallback'             => true,
            'has_benchmark_coverage'            => true,
            'has_rollback_path'                 => true,
            'has_local_judgement_fallback'      => true,
        ],
        [
            'phase'                             => 'autonomy_stop_go',
            'has_local_evidence_path'           => true,
            'has_scaffold_fallback'             => true,
            'has_benchmark_coverage'            => true,
            'has_rollback_path'                 => true,
            'has_local_judgement_fallback'      => true,
        ],
    ];

    // ── Factory ───────────────────────────────────────────────────────────────

    private function factory(): AtlasExternalBrainProviderIndependenceProofRunner
    {
        return new AtlasExternalBrainProviderIndependenceProofRunner;
    }

    private function factoryWithDeps(
        AtlasExternalBrainProviderAgnosticBenchmarkSet $benchmarkSet,
        AtlasExternalBrainProviderIndependenceProof $proof,
        AtlasExternalBrainProviderPoolOutcomeAttributor $attributor,
        AtlasExternalBrainProviderPoolPatchDryRunPlan $dryRunPlan,
    ): AtlasExternalBrainProviderIndependenceProofRunner {
        return new AtlasExternalBrainProviderIndependenceProofRunner(
            $benchmarkSet,
            $proof,
            $attributor,
            $dryRunPlan,
        );
    }

    // ── Schema test ───────────────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $runner = $this->factory();
        $result = $runner->run();

        $this->assertArrayHasKey('schema', $result);
        $this->assertArrayHasKey('independent', $result);
        $this->assertArrayHasKey('benchmark', $result);
        $this->assertArrayHasKey('proof', $result);
        $this->assertArrayHasKey('attribution', $result);
        $this->assertArrayHasKey('dry_run', $result);
        $this->assertArrayHasKey('reasons', $result);
        $this->assertSame(
            AtlasExternalBrainProviderIndependenceProofRunner::SCHEMA,
            $result['schema'],
        );
    }

    // ── A redundant pool passes independence ───────────────────────────────────

    public function test_redundant_pool_passes_independence(): void
    {
        $runner = $this->factory();
        $result = $runner->run([
            'proof_claims' => self::FULL_PROOF_CLAIMS,
        ]);

        // Default benchmark is provider-safe.
        $this->assertTrue($result['benchmark']['provider_safe_status']['is_safe']);

        // All 7 mandatory phases fully covered → independent proof.
        $this->assertTrue($result['proof']['independent']);
        $this->assertEmpty($result['proof']['provider_required_phases']);

        // Overall verdict: independent.
        $this->assertTrue($result['independent']);
        $this->assertEmpty($result['reasons']);
    }

    // ── A load-bearing single provider fails independence proof ────────────────

    public function test_load_bearing_single_provider_fails_independence_proof(): void
    {
        $runner = $this->factory();
        $result = $runner->run([
            'proof_claims' => self::LOAD_BEARING_CLAIMS,
        ]);

        // Benchmark is still safe.
        $this->assertTrue($result['benchmark']['provider_safe_status']['is_safe']);

        // Proof identifies task_validation as provider-dependent.
        $this->assertFalse($result['proof']['independent']);
        $requiredPhases = $result['proof']['provider_required_phases'];
        $this->assertNotEmpty($requiredPhases);

        $validationPhase = array_values(array_filter(
            $requiredPhases,
            static fn (array $p): bool => ($p['phase'] ?? '') === 'task_validation',
        ));
        $this->assertCount(1, $validationPhase);
        $this->assertContains('requires_live_provider', $validationPhase[0]['reasons']);

        // Overall: not independent.
        $this->assertFalse($result['independent']);
        $this->assertStringContainsString('task_validation', implode(' ', $result['reasons']));
    }

    // ── Nullable constructor leaves null deps instantiated as real ─────────────

    public function test_nullable_constructor_instantiates_dependencies_as_real(): void
    {
        // Pass explicit real instances, leaving nothing null (control).
        // The actual test of nullable pattern is the factory() method which
        // uses no-arg constructor — already exercised by every test above.
        $benchmarkSet = new AtlasExternalBrainProviderAgnosticBenchmarkSet;
        $proof        = new AtlasExternalBrainProviderIndependenceProof;
        $attributor   = new AtlasExternalBrainProviderPoolOutcomeAttributor;
        $dryRunPlan   = new AtlasExternalBrainProviderPoolPatchDryRunPlan;

        $runner = $this->factoryWithDeps($benchmarkSet, $proof, $attributor, $dryRunPlan);
        $result = $runner->run([
            'proof_claims' => self::FULL_PROOF_CLAIMS,
        ]);

        // All real services produce correct schema in their output.
        $this->assertSame(
            AtlasExternalBrainProviderAgnosticBenchmarkSet::SCHEMA,
            $result['benchmark']['schema'],
        );
        $this->assertSame(
            AtlasExternalBrainProviderIndependenceProof::SCHEMA,
            $result['proof']['schema'],
        );
        $this->assertSame(
            AtlasExternalBrainProviderPoolOutcomeAttributor::SCHEMA,
            $result['attribution']['schema_version'],
        );
        $this->assertSame(
            AtlasExternalBrainProviderPoolPatchDryRunPlan::SCHEMA,
            $result['dry_run']['schema'],
        );
        $this->assertTrue($result['independent']);
    }
}
