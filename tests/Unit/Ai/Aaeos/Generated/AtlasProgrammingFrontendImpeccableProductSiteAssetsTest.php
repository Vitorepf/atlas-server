<?php

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingFrontendImpeccableProductSiteAssetsService;
use Tests\TestCase;

/**
 * Pins the doc's concrete decisions: CONTRATOS path classification, FLUXO
 * pipeline ordering (terminates at public demos), REGRAS PARA IA (#1 visual
 * quality, #2 site/demo must pass own detector, #3 install/download safety,
 * #4 before/after is market evidence not runtime proof) and ESCOPO (readiness
 * comes only from certification — product proof never confers it). Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-impeccable-product-site-assets.md
 */
class AtlasProgrammingFrontendImpeccableProductSiteAssetsTest extends TestCase
{
    private function service(): AtlasProgrammingFrontendImpeccableProductSiteAssetsService
    {
        return new AtlasProgrammingFrontendImpeccableProductSiteAssetsService;
    }

    public function test_classify_path_maps_documented_areas_and_flags_unknown_paths(): void
    {
        $service = $this->service();

        // CONTRATOS area table: a known prefix resolves to its role + layer.
        $installer = $service->classifyPath('cli/bin/commands/skills.mjs');
        $this->assertTrue($installer['matched']);
        $this->assertSame('cli_installer', $installer['layer']);
        $this->assertTrue($installer['is_proof_surface']);

        // A path under a documented area prefix still matches.
        $demo = $service->classifyPath('demos/landing-demo/src/index.html');
        $this->assertTrue($demo['matched']);
        $this->assertSame('installable_demo', $demo['layer']);

        $download = $service->classifyPath('functions/api/download/claude.zip');
        $this->assertTrue($download['matched']);
        $this->assertSame('download_api', $download['layer']);

        // An unrelated path is NOT product-proof surface.
        $kernel = $service->classifyPath('app/Services/Ai/Kernel/Foo.php');
        $this->assertFalse($kernel['matched']);
        $this->assertFalse($kernel['is_proof_surface']);
    }

    public function test_proof_artifact_requires_visual_quality_and_detector_pass(): void
    {
        $service = $this->service();

        // REGRA #1 + #2: a demo that passes both is admissible as proof.
        $good = $service->evaluateProofArtifact([
            'kind' => 'demo',
            'demonstrates_visual_quality' => true,
            'detector_passed' => true,
            'has_runtime' => true,
        ]);
        $this->assertTrue($good['admissible_as_proof']);
        $this->assertTrue($good['is_runtime_proof']);

        // REGRA #2: detector not passed -> not admissible, blocked by rule_2.
        $noDetector = $service->evaluateProofArtifact([
            'kind' => 'demo',
            'demonstrates_visual_quality' => true,
            'detector_passed' => false,
        ]);
        $this->assertFalse($noDetector['admissible_as_proof']);
        $this->assertContains('rule_2: site/demo has not passed its own detector.', $noDetector['blocking_reasons']);

        // REGRA #1: no visual quality -> not admissible, blocked by rule_1.
        $noVisual = $service->evaluateProofArtifact([
            'kind' => 'demo',
            'demonstrates_visual_quality' => false,
            'detector_passed' => true,
        ]);
        $this->assertFalse($noVisual['admissible_as_proof']);
        $this->assertContains('rule_1: product visual does not demonstrate visual quality.', $noVisual['blocking_reasons']);
    }

    public function test_before_after_case_is_market_evidence_not_runtime_proof(): void
    {
        $service = $this->service();

        // REGRA #4: a flawless before/after case is market evidence, never runtime proof.
        $beforeAfter = $service->evaluateProofArtifact([
            'kind' => 'before_after',
            'demonstrates_visual_quality' => true,
            'detector_passed' => true,
            'has_runtime' => true,
        ]);
        $this->assertTrue($beforeAfter['is_market_evidence_only']);
        $this->assertTrue($beforeAfter['admissible_as_market_evidence']);
        $this->assertFalse($beforeAfter['admissible_as_proof']);
        $this->assertFalse($beforeAfter['is_runtime_proof']);
        $this->assertContains('rule_4: before/after case is market evidence, not runtime proof.', $beforeAfter['blocking_reasons']);
    }

    public function test_distribution_requires_hash_match_and_dryrun_prefix_rollback(): void
    {
        $service = $this->service();

        // RISCOS: hash matches + dry-run + prefix + rollback -> distributable.
        $safe = $service->evaluateDistribution([
            'declared_hash' => 'sha256:deadbeef',
            'computed_hash' => 'sha256:deadbeef',
            'dry_run' => true,
            'prefixed' => true,
            'rollback_supported' => true,
        ]);
        $this->assertTrue($safe['hash_verified']);
        $this->assertFalse($safe['drift']);
        $this->assertTrue($safe['distributable']);

        // Download drift: declared != computed -> drift, not distributable.
        $drift = $service->evaluateDistribution([
            'declared_hash' => 'sha256:aaaa',
            'computed_hash' => 'sha256:bbbb',
            'dry_run' => true,
            'prefixed' => true,
            'rollback_supported' => true,
        ]);
        $this->assertTrue($drift['drift']);
        $this->assertFalse($drift['distributable']);
        $this->assertContains('risk_download_drift: declared hash does not match computed hash.', $drift['blocking_reasons']);

        // Unsafe installer: missing rollback -> not distributable even with good hash.
        $unsafe = $service->evaluateDistribution([
            'declared_hash' => 'x',
            'computed_hash' => 'x',
            'dry_run' => true,
            'prefixed' => true,
            'rollback_supported' => false,
        ]);
        $this->assertFalse($unsafe['installer_safe']);
        $this->assertFalse($unsafe['distributable']);
        $this->assertContains('risk_installer_wrong_skill: installer has no rollback.', $unsafe['blocking_reasons']);
    }

    public function test_pipeline_order_follows_fluxo_and_terminates_at_demos(): void
    {
        $service = $this->service();

        // FLUXO: the full documented order, ending at public demos.
        $ok = $service->evaluatePipelineOrder([
            'source_build', 'dist_bundles', 'site_copies_dist',
            'download_exposes', 'cli_installer', 'demos_prove',
        ]);
        $this->assertTrue($ok['in_order']);
        $this->assertTrue($ok['terminates_at_demos']);
        $this->assertNull($ok['first_violation']);

        // Out of order: download before the site copies dist -> violation.
        $bad = $service->evaluatePipelineOrder([
            'source_build', 'download_exposes', 'site_copies_dist', 'demos_prove',
        ]);
        $this->assertFalse($bad['in_order']);
        $this->assertSame('site_copies_dist', $bad['first_violation']['stage']);
        $this->assertSame('download_exposes', $bad['first_violation']['expected_after']);
    }

    public function test_product_proof_never_confers_readiness(): void
    {
        $service = $this->service();

        // ESCOPO: readiness comes only from certification; nothing here authorizes it.
        $proof = $service->evaluateProofArtifact([
            'kind' => 'demo',
            'demonstrates_visual_quality' => true,
            'detector_passed' => true,
            'has_runtime' => true,
        ]);
        $this->assertFalse($proof['confers_readiness']);
        $this->assertFalse($proof['readiness_authorized']);

        $this->assertFalse($service->classifyPath('site/pages/index.astro')['readiness_authorized']);
        $this->assertFalse($service->evaluateDistribution([])['readiness_authorized']);
        $this->assertFalse($service->evaluatePipelineOrder(['demos_prove'])['readiness_authorized']);
        $this->assertFalse($service->describe()['readiness_authorized']);
    }
}
