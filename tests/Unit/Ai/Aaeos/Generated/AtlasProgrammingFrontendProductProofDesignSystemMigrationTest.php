<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingFrontendProductProofDesignSystemMigrationService;
use Tests\TestCase;

/**
 * Pins the documented "Contratos", "Fluxo", headline "Regras para IA" and
 * forbidden_changes of the design-system-migration manifest. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-product-proof-design_system_migration.md
 */
class AtlasProgrammingFrontendProductProofDesignSystemMigrationTest extends TestCase
{
    private function service(): AtlasProgrammingFrontendProductProofDesignSystemMigrationService
    {
        return new AtlasProgrammingFrontendProductProofDesignSystemMigrationService;
    }

    public function test_canonical_sample_passes_every_documented_gate(): void
    {
        $service = $this->service();
        $result = $service->evaluate($service->readySample());

        $this->assertSame(AtlasProgrammingFrontendProductProofDesignSystemMigrationService::VERDICT_READY, $result['verdict']);
        $this->assertTrue($result['ready']);
        $this->assertTrue($result['gates_passed']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame([], $result['claim_violations']);
        // Escopo de Implementacao: a manifest never authorizes a migration patch.
        $this->assertFalse($result['migration_patch_authorized']);
    }

    public function test_missing_tablet_viewport_blocks_proof(): void
    {
        // Contratos: viewports desktop, tablet AND mobile are all required.
        $service = $this->service();
        $demo = $service->readySample();
        $demo['viewports'] = ['desktop', 'mobile']; // tablet dropped

        $result = $service->evaluate($demo);

        $this->assertSame(AtlasProgrammingFrontendProductProofDesignSystemMigrationService::VERDICT_BLOCKED, $result['verdict']);
        $this->assertFalse($result['gates_passed']);
        $this->assertContains('missing_viewport:tablet', $result['blockers']);
        $this->assertFalse($result['viewport_checks']['tablet']);
        $this->assertTrue($result['viewport_checks']['desktop']);
        $this->assertTrue($result['viewport_checks']['mobile']);
    }

    public function test_missing_drift_check_evidence_blocks_proof(): void
    {
        // Contratos evidence gates have NO escape hatch: every one is mandatory.
        $service = $this->service();
        $demo = $service->readySample();
        $demo['evidence'] = ['anti_slop', 'visual_smoke', 'completion_hash']; // no drift check

        $result = $service->evaluate($demo);

        $this->assertSame(AtlasProgrammingFrontendProductProofDesignSystemMigrationService::VERDICT_BLOCKED, $result['verdict']);
        $this->assertFalse($result['evidence_checks']['design_system_drift_check']);
        $this->assertContains('missing_evidence:design_system_drift_check', $result['blockers']);
    }

    public function test_missing_before_after_flow_step_blocks_proof(): void
    {
        // Fluxo: "comparar before/after" must actually have happened, otherwise
        // the artifact is not a design-system migration.
        $service = $this->service();
        $demo = $service->readySample();
        $demo['flow_steps'] = ['ui_inventory', 'token_migration']; // no before_after_comparison

        $result = $service->evaluate($demo);

        $this->assertSame(AtlasProgrammingFrontendProductProofDesignSystemMigrationService::VERDICT_BLOCKED, $result['verdict']);
        $this->assertContains('missing_flow_step:before_after_comparison', $result['blockers']);
        $this->assertFalse($result['flow_step_checks']['before_after_comparison']);
    }

    public function test_completion_claim_without_diff_tokens_scope_or_regression_is_a_claim_violation(): void
    {
        // Regras para IA: "Nao declarar migracao completa sem escopo e regressao
        // visual" + forbidden_changes: "... sem diff, tokens e regressao visual".
        // Gates themselves stay green (readySample), so this proves the claim
        // violation outranks otherwise-green gates.
        $service = $this->service();
        $demo = $service->readySample();
        $demo['claims_migration_complete'] = true; // all four requirement flags false by default

        $result = $service->evaluate($demo);

        $this->assertSame(AtlasProgrammingFrontendProductProofDesignSystemMigrationService::VERDICT_CLAIM_VIOLATION, $result['verdict']);
        $this->assertFalse($result['completion_claim_allowed']);
        $this->assertFalse($result['ready']);
        $this->assertContains('completion_claim_without_migration_scope_explicit', $result['claim_violations']);
        $this->assertContains('completion_claim_without_before_after_diff', $result['claim_violations']);
        $this->assertContains('completion_claim_without_token_migration', $result['claim_violations']);
        $this->assertContains('completion_claim_without_visual_regression', $result['claim_violations']);
        // Claim violation outranks otherwise-green gates: gates still pass.
        $this->assertTrue($result['gates_passed']);
    }

    public function test_completion_claim_allowed_only_with_all_four_requirements(): void
    {
        // The single declared state where "migration complete" is permitted.
        $service = $this->service();
        $demo = $service->completedSample();

        $decision = $service->completionClaimDecision($demo);
        $this->assertTrue($decision['allowed']);
        $this->assertSame([], $decision['violations']);

        $result = $service->evaluate($demo);
        $this->assertSame(AtlasProgrammingFrontendProductProofDesignSystemMigrationService::VERDICT_READY, $result['verdict']);
        $this->assertTrue($result['completion_claim_allowed']);
        $this->assertTrue($service->mayPublish($demo));

        // Drop just the visual regression -> immediately a claim violation again.
        $demo['visual_regression'] = false;
        $this->assertFalse($service->completionClaimDecision($demo)['allowed']);
        $this->assertContains('completion_claim_without_visual_regression', $service->completionClaimDecision($demo)['violations']);
        $this->assertFalse($service->mayPublish($demo));
    }
}
