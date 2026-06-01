<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasArchImplementedVsScaffoldMatrixService;
use Tests\TestCase;

/**
 * Pins the documented Implemented vs Scaffold Matrix contract: the six-token
 * status vocabulary (with unknown for out-of-vocab), the product-readiness
 * decision (only implemented_ready WITH evidence is sellable; scaffold/future/
 * partial/blocked never are; ready-without-evidence is forbidden), the
 * domain-ready != product-final guard, and the Handoff Rule (readiness=attention
 * without an explicit decision blocks, and parallel flows are forbidden). Pure,
 * no DB.
 *
 * @see docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md
 */
class AtlasArchImplementedVsScaffoldMatrixTest extends TestCase
{
    private function service(): AtlasArchImplementedVsScaffoldMatrixService
    {
        return new AtlasArchImplementedVsScaffoldMatrixService();
    }

    /**
     * Doc "Status Vocabulary": exactly six tokens, and an out-of-vocabulary
     * status collapses to `unknown` (never a guess).
     */
    public function test_status_vocabulary_has_six_tokens_and_unknown_is_the_fallback(): void
    {
        $this->assertCount(6, AtlasArchImplementedVsScaffoldMatrixService::STATUS_VOCABULARY);

        $ready = $this->service()->classifyStatus('implemented_ready');
        $this->assertSame('implemented_ready', $ready['status']);
        $this->assertTrue($ready['found']);
        $this->assertTrue($ready['is_product_ready_candidate']);

        $bogus = $this->service()->classifyStatus('shipped_to_prod');
        $this->assertSame('unknown', $bogus['status']);
        $this->assertFalse($bogus['found']);
        $this->assertFalse($bogus['is_product_ready_candidate']);
    }

    /**
     * Doc decisions: "Itens scaffold ou future nao podem ser vendidos como
     * produto pronto." Scaffold and future are never product-ready, with the
     * documented reason.
     */
    public function test_scaffold_and_future_are_never_sellable_as_product(): void
    {
        $scaffold = $this->service()->decideProductReadiness(['status' => 'scaffold', 'has_evidence' => true]);
        $this->assertFalse($scaffold['product_ready']);
        $this->assertFalse($scaffold['sellable_as_product']);
        $this->assertContains('scaffold_is_not_final_product', $scaffold['reasons']);

        $future = $this->service()->decideProductReadiness(['status' => 'future', 'has_evidence' => true]);
        $this->assertFalse($future['product_ready']);
        $this->assertContains('future_has_no_sufficient_execution', $future['reasons']);

        $partial = $this->service()->decideProductReadiness(['status' => 'implemented_partial', 'has_evidence' => true]);
        $this->assertFalse($partial['product_ready']);
        $this->assertContains('partial_lacks_runtime_or_maturity', $partial['reasons']);
    }

    /**
     * Doc forbidden_changes: "Declarar runtime, maturidade ou prontidao sem
     * evidencia verificavel e gates verdes." implemented_ready WITHOUT evidence
     * is NOT product-ready; WITH evidence it is.
     */
    public function test_implemented_ready_needs_evidence_to_be_product_ready(): void
    {
        $noEvidence = $this->service()->decideProductReadiness(['status' => 'implemented_ready', 'has_evidence' => false]);
        $this->assertFalse($noEvidence['product_ready']);
        $this->assertContains('readiness_claimed_without_verifiable_evidence', $noEvidence['reasons']);

        $withEvidence = $this->service()->decideProductReadiness(['status' => 'implemented_ready', 'has_evidence' => true]);
        $this->assertTrue($withEvidence['product_ready']);
        $this->assertTrue($withEvidence['sellable_as_product']);
        $this->assertSame([], $withEvidence['reasons']);
    }

    /**
     * Doc "Conflicts To Reconcile" row 1: domain readiness != product final.
     * 15 domains ready means contract/orchestrator/flows/gates ready, never
     * product final.
     */
    public function test_domain_ready_is_never_product_final(): void
    {
        $r = $this->service()->domainReadyIsNotProductFinal(15);

        $this->assertSame(15, $r['domains_ready']);
        $this->assertFalse($r['means_product_final']);
        $this->assertTrue($r['means_contract_orchestrator_flows_gates_ready']);
    }

    /**
     * Doc "Handoff Rule": readiness=attention without an explicit decision blocks;
     * the same attention WITH an explicit decision recorded proceeds.
     */
    public function test_handoff_blocks_on_attention_without_explicit_decision(): void
    {
        $blocked = $this->service()->evaluateHandoff([
            'readiness_status' => 'attention',
            'explicit_decision_recorded' => false,
        ]);
        $this->assertSame('block', $blocked['verdict']);
        $this->assertContains('readiness_attention_without_explicit_decision', $blocked['blockers']);

        $proceed = $this->service()->evaluateHandoff([
            'readiness_status' => 'attention',
            'explicit_decision_recorded' => true,
        ]);
        $this->assertSame('proceed', $proceed['verdict']);
        $this->assertSame([], $proceed['blockers']);
    }

    /**
     * Doc "Handoff Rule": "Nao criar fluxo paralelo para acelerar." Any proposal
     * that builds a parallel flow is blocked even when readiness is clean.
     */
    public function test_handoff_blocks_any_parallel_flow_even_when_ready(): void
    {
        $r = $this->service()->evaluateHandoff([
            'readiness_status' => 'ready',
            'creates_parallel_flow' => true,
            'capability_already_covered' => true,
        ]);

        $this->assertSame('block', $r['verdict']);
        $this->assertContains('parallel_flow_for_already_covered_capability', $r['blockers']);
        $this->assertTrue($r['parallel_flow_forbidden']);
    }

    /**
     * Doc "Safe Next Blocks": ordered 1..5, first is the Voice Realtime product
     * loop. The snapshot default models the canonical scaffold trap.
     */
    public function test_safe_next_blocks_order_and_snapshot_default_flags_scaffold(): void
    {
        $blocks = $this->service()->safeNextBlocks();
        $this->assertSame('Voice Realtime product loop', $blocks['first']);
        $this->assertCount(5, $blocks['blocks']);
        $this->assertSame(1, $blocks['blocks'][0]['order']);

        $snap = $this->service()->snapshot();
        $this->assertTrue($snap['read_only']);
        $this->assertSame(1, $snap['total']);
        $this->assertSame(0, $snap['product_ready']);
        $this->assertSame(1, $snap['not_sellable_as_product']);
    }
}
