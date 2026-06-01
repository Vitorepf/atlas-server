<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingFrontendProductProofLiveModeRepairLoopService;
use Tests\TestCase;

/**
 * Pins the documented "Contratos", "Fluxo" (ordered stages + evidence) and
 * headline "Regras para IA" of the live-mode repair-loop manifest. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-product-proof-live_mode_repair_loop.md
 */
class AtlasProgrammingFrontendProductProofLiveModeRepairLoopTest extends TestCase
{
    private function service(): AtlasProgrammingFrontendProductProofLiveModeRepairLoopService
    {
        return new AtlasProgrammingFrontendProductProofLiveModeRepairLoopService;
    }

    public function test_canonical_sample_proves_the_local_cycle_without_authorizing_parity(): void
    {
        // Fluxo: all four stages, in order, each with its Contratos evidence.
        // Escopo: a manifest run is a proven LOCAL cycle, never operational parity.
        $service = $this->service();
        $result = $service->evaluate($service->provenSample());

        $this->assertSame(
            AtlasProgrammingFrontendProductProofLiveModeRepairLoopService::VERDICT_PROVEN,
            $result['verdict'],
        );
        $this->assertTrue($result['proven']);
        $this->assertTrue($result['stages_proven']);
        $this->assertSame([], $result['blockers']);
        $this->assertNull($result['order_violation']);
        // Riscos/Escopo: local contract proof is NOT "paridade operacional completa".
        $this->assertFalse($result['operational_parity_authorized']);
    }

    public function test_missing_recovery_stage_and_its_evidence_makes_run_incomplete(): void
    {
        // Fluxo last step "provar recovery" + its Contratos evidence "recover_session".
        $service = $this->service();
        $run = $service->provenSample();
        $run['completed_stages'] = ['select_element', 'generate_preview', 'accept_patch'];
        $run['evidence'] = ['browser_pick_event', 'preview_variant_event', 'accepted_variant_diff'];

        $result = $service->evaluate($run);

        $this->assertSame(
            AtlasProgrammingFrontendProductProofLiveModeRepairLoopService::VERDICT_INCOMPLETE,
            $result['verdict'],
        );
        $this->assertFalse($result['proven']);
        $this->assertContains('missing_stage:prove_recovery', $result['blockers']);
        $this->assertContains('missing_evidence:recover_session', $result['blockers']);
    }

    public function test_accepting_a_patch_before_previewing_is_an_order_violation(): void
    {
        // Fluxo order is load-bearing: you cannot accept a patch you never previewed.
        $service = $this->service();
        $run = $service->provenSample();
        // accept_patch placed before generate_preview.
        $run['completed_stages'] = ['select_element', 'accept_patch', 'generate_preview', 'prove_recovery'];

        $result = $service->evaluate($run);

        $this->assertSame(
            AtlasProgrammingFrontendProductProofLiveModeRepairLoopService::VERDICT_INCOMPLETE,
            $result['verdict'],
        );
        $this->assertSame('generate_preview', $result['order_violation']);
        $this->assertContains('out_of_order:generate_preview', $result['blockers']);
    }

    public function test_parity_claim_without_external_replay_is_a_forbidden_change(): void
    {
        // Regras para IA / forbidden_changes: no live-mode parity claim without a
        // real external-baseline replay — even when every stage is otherwise proven.
        $service = $this->service();
        $run = $service->provenSample();
        $run['claims_live_mode_parity'] = true;
        $run['external_replay_present'] = false;

        $result = $service->evaluate($run);

        $this->assertSame(
            AtlasProgrammingFrontendProductProofLiveModeRepairLoopService::VERDICT_CLAIM_VIOLATION,
            $result['verdict'],
        );
        $this->assertFalse($result['parity_claim_allowed']);
        $this->assertContains('live_mode_parity_claim_without_external_replay', $result['claim_violations']);
        $this->assertFalse($service->mayPublish($run));
    }

    public function test_parity_claim_with_external_replay_and_real_journal_is_allowed_and_authorizes_parity(): void
    {
        // The escape from the forbidden change: a real external replay backs the
        // claim, and a real journal lifts the run from local proof to parity.
        $service = $this->service();
        $run = $service->provenSample();
        $run['claims_live_mode_parity'] = true;
        $run['external_replay_present'] = true;
        $run['real_journal_present'] = true;

        $result = $service->evaluate($run);

        $this->assertSame(
            AtlasProgrammingFrontendProductProofLiveModeRepairLoopService::VERDICT_PROVEN,
            $result['verdict'],
        );
        $this->assertTrue($result['parity_claim_allowed']);
        $this->assertSame([], $result['claim_violations']);
        $this->assertTrue($result['operational_parity_authorized']);
    }

    public function test_non_desktop_viewport_is_off_contract(): void
    {
        // Contratos: viewport desktop.
        $service = $this->service();
        $run = $service->provenSample();
        $run['viewport'] = 'mobile';

        $result = $service->evaluate($run);

        $this->assertFalse($result['viewport_ok']);
        $this->assertContains('wrong_viewport:expected_desktop', $result['blockers']);
        $this->assertSame(
            AtlasProgrammingFrontendProductProofLiveModeRepairLoopService::VERDICT_INCOMPLETE,
            $result['verdict'],
        );
    }
}
