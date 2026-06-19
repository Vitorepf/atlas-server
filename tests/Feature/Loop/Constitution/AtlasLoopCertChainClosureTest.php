<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Constitution;

use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopCertChainClosure;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 3 · Slice 4.5 — the cert-chain closure walker is the R4 fix: it derives the FULL transitive
 * set of verdict-bearing collaborators (not just the top certifier), repo-wide, so the per-collaborator
 * blinder sentinel + the candidate-bytes Merkle cover every delegated gate, including ones reached across the
 * subtree boundary and via declared dynamic delegation.
 */
final class AtlasLoopCertChainClosureTest extends TestCase
{
    /** The documented §3.3 closure — the set the walker must cover (it may grow, never shrink). */
    private const EXPECTED = [
        'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopSemanticImplementationCertifier',
        'App\\Services\\Ai\\AutonomousEvolution\\Verify\\AtlasEngineeringHonestyGate',
        'App\\Services\\Ai\\AutonomousEvolution\\Verify\\AtlasDeadCodeAnalyzer',
        'App\\Services\\Ai\\AutonomousEvolution\\Verify\\AtlasDeadCodeAnalyzerSupport',
        'App\\Services\\Ai\\AutonomousEvolution\\Verify\\AtlasLoopSignalAnalyzer',
        'App\\Services\\Ai\\AutonomousEvolution\\AtlasEvolutionFrozenJudge',
        'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopMutationAdequacyGateService',
        'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopCrossFileConsumerGateService',
        'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopBehavioralEquivalenceGate',
        'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopChangedSymbolCoverageCensus',
        'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopQualityGrader',
        'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopDeliveryConfidenceModel',
        'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopCompletenessGate',
        'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopJudgeConsensusGate',
        'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopHeldOutDeltaCertifier',
        'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopMetricHarness',
        'App\\Services\\Ai\\AutonomousEvolution\\Discovery\\AtlasLoopCompletenessCriteriaResolver',
        'App\\Services\\Ai\\AutonomousEvolution\\Discovery\\AtlasLoopNodeInterfaceExtractor',
        'App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\AdversarialProofPanelService',
    ];

    public function test_closure_covers_the_full_cert_chain_no_delegate_dropped(): void
    {
        $closure = (new AtlasLoopCertChainClosure())->classes();
        foreach (self::EXPECTED as $fqcn) {
            $this->assertContains($fqcn, $closure, "cert-chain closure dropped a verdict-bearing delegate: $fqcn");
        }
    }

    public function test_closure_reaches_the_delegate_OUTSIDE_the_loop_subtree(): void
    {
        // The R4 cross-subtree catch: the adversarial proof panel lives outside app/.../AutonomousEvolution/
        // but the verdict is delegated to it — a subtree-scoped walker would silently drop it.
        $this->assertContains(
            'App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\AdversarialProofPanelService',
            (new AtlasLoopCertChainClosure())->classes(),
        );
    }

    public function test_files_maps_every_closure_class_to_a_real_repo_relative_php_file(): void
    {
        $files = (new AtlasLoopCertChainClosure())->files(base_path());
        $this->assertGreaterThanOrEqual(count(self::EXPECTED), count($files));
        foreach ($files as $rel) {
            $this->assertStringEndsWith('.php', $rel);
            $this->assertFileExists(base_path().'/'.$rel);
        }
    }

    public function test_merkle_root_is_content_dependent_and_stable(): void
    {
        $closure = new AtlasLoopCertChainClosure();
        $real = $closure->merkleRoot(base_path());

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $real);
        $this->assertSame($real, $closure->merkleRoot(base_path()), 'deterministic over the same bytes');
        // A repo root where the closure files are ABSENT (empty bytes) yields a DIFFERENT root — proving the
        // Merkle binds to file CONTENT, the anchor the candidate-bytes proof needs.
        $empty = sys_get_temp_dir().'/atlas-closure-empty-'.bin2hex(random_bytes(4));
        $this->assertNotSame($real, $closure->merkleRoot($empty));
    }
}
