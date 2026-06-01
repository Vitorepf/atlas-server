<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasContextBuilderRoadmapService;
use Tests\TestCase;

/**
 * Pins the documented Context Builder routing, graph maturity and gate rules.
 *
 * @see docs/engineering-knowledge-base/evolution/context-builder-roadmap.md
 */
class AtlasContextBuilderRoadmapTest extends TestCase
{
    private function service(): AtlasContextBuilderRoadmapService
    {
        return new AtlasContextBuilderRoadmapService();
    }

    /** Routing Rules table: each question type maps to the documented primary + secondary source. */
    public function test_routing_rules_match_the_doc_table(): void
    {
        $svc = $this->service();

        $why = $svc->route('Why did we decide X?');
        $this->assertTrue($why['routed']);
        $this->assertSame('evidence_replay', $why['primary']);
        $this->assertSame('decision_receipt_chain', $why['secondary']);

        $breaks = $svc->route('What breaks if this changes?');
        $this->assertSame('graph_retrieval', $breaks['primary']);
        $this->assertSame('code_intelligence', $breaks['secondary']);

        $factual = $svc->route('Factual lookup');
        $this->assertSame('vector_retrieval', $factual['primary']);
        $this->assertSame('kb_docs', $factual['secondary']);

        $vitor = $svc->route('What does Vitor need now?');
        $this->assertSame('personal_memory_projection', $vitor['primary']);
        $this->assertSame('policy_profile', $vitor['secondary']);
    }

    /** Unknown intent must not be silently assumed: routed=false with a safe fallback. */
    public function test_unknown_question_type_is_not_routed(): void
    {
        $r = $this->service()->route('something undefined');

        $this->assertFalse($r['routed']);
        $this->assertSame('vector_retrieval', $r['primary']);
    }

    /**
     * HARD RULE: "Inferred relations never become default context without
     * approval or strong evidence policy." Plain Inferred is held out.
     */
    public function test_inferred_relation_is_not_default_context_without_approval(): void
    {
        $held = $this->service()->mayBecomeDefaultContext('inferred');
        $this->assertFalse($held['default_context']);
        $this->assertTrue($held['requires_approval']);
        $this->assertContains('inferred_never_default_without_approval_or_strong_evidence', $held['reasons']);

        $viaApproval = $this->service()->mayBecomeDefaultContext('inferred', approvalGranted: true);
        $this->assertTrue($viaApproval['default_context']);
        $this->assertContains('inferred_admitted_via_approval', $viaApproval['reasons']);

        $viaEvidence = $this->service()->mayBecomeDefaultContext('inferred', strongEvidencePolicy: true);
        $this->assertTrue($viaEvidence['default_context']);
        $this->assertContains('inferred_admitted_via_strong_evidence_policy', $viaEvidence['reasons']);
    }

    /** Only Approved relations are default context outright; Explicit/Observed must be promoted. */
    public function test_only_approved_stage_is_default_context_outright(): void
    {
        $this->assertTrue($this->service()->mayBecomeDefaultContext('approved')['default_context']);
        $this->assertFalse($this->service()->mayBecomeDefaultContext('explicit')['default_context']);
        $this->assertFalse($this->service()->mayBecomeDefaultContext('observed')['default_context']);
    }

    /** Maturity ladder promotes explicit -> observed -> inferred -> approved (terminal). */
    public function test_maturity_ladder_promotion_order(): void
    {
        $svc = $this->service();

        $this->assertSame('observed', $svc->promote('explicit')['to']);
        $this->assertSame('inferred', $svc->promote('observed')['to']);
        $this->assertSame('approved', $svc->promote('inferred')['to']);

        $terminal = $svc->promote('approved');
        $this->assertFalse($terminal['promoted']);
        $this->assertTrue($terminal['terminal']);
    }

    /** Required-source gate: a high-risk run missing a required source is BLOCKED. */
    public function test_high_risk_missing_required_source_is_blocked(): void
    {
        $blocked = $this->service()->gateCheck([
            'risk_level' => 'high',
            'required_sources' => ['evidence_replay', 'code_intelligence'],
            'available_sources' => ['code_intelligence'],
        ]);

        $this->assertSame('block', $blocked['decision']);
        $this->assertTrue($blocked['blocked']);
        $this->assertContains('high_risk_missing_required_source', $blocked['reasons']);
        $this->assertSame(['evidence_replay'], $blocked['missing_required_sources']);

        // Same missing source at low risk does not trigger the required-source gate.
        $lowRisk = $this->service()->gateCheck([
            'risk_level' => 'low',
            'required_sources' => ['evidence_replay'],
            'available_sources' => [],
        ]);
        $this->assertSame('allow', $lowRisk['decision']);
    }

    /** Gates: external call that is not provider-safe is blocked; budget overflow is blocked. */
    public function test_provider_safe_and_budget_gates(): void
    {
        $unsafe = $this->service()->gateCheck([
            'risk_level' => 'low',
            'external_call' => true,
            'provider_safe' => false,
        ]);
        $this->assertTrue($unsafe['blocked']);
        $this->assertContains('external_call_not_provider_safe', $unsafe['reasons']);

        $overBudget = $this->service()->gateCheck([
            'risk_level' => 'low',
            'context_refs' => 30,
            'context_budget' => 12,
        ]);
        $this->assertTrue($overBudget['blocked']);
        $this->assertContains('context_budget_exceeded:30/12', $overBudget['reasons']);
        $this->assertFalse($overBudget['receipt']['budget_ok']);

        // Every decision emits the retrieval-plan evidence event.
        $this->assertSame('context_builder.retrieval_plan', $overBudget['evidence_event']);
    }
}
