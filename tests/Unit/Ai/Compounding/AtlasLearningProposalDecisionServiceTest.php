<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Compounding;

use App\Services\Ai\Compounding\AtlasLearningProposalDecisionService;
use Tests\TestCase;

/**
 * Pins the documented Learning Proposals contract.
 *
 * @see docs/engineering-knowledge-base/system-graph/learning-proposals.md
 */
final class AtlasLearningProposalsTest extends TestCase
{
    private AtlasLearningProposalDecisionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasLearningProposalDecisionService();
    }

    /**
     * Contratos: "Aprendizado sem evidencia nao deve virar canon."
     * A signal with no evidence is rejected even when its sample/effect are huge.
     */
    public function testSignalWithoutEvidenceIsNeverAdmitted(): void
    {
        $verdict = $this->service->evaluate([
            'kind' => 'policy',
            'summary' => 'loosen retry policy',
            'evidence_refs' => [],
            'sample_size' => 1000,
            'effect_size' => 1.0,
        ]);

        $this->assertSame(AtlasLearningProposalDecisionService::STATUS_REJECTED, $verdict['status']);
        $this->assertSame('no_evidence_cannot_become_canon', $verdict['justification']);
        $this->assertFalse($verdict['may_auto_apply']);
        $this->assertTrue($verdict['requires_review']);
    }

    /**
     * Riscos: "Proposta baseada em sinal fraco." A single weak observation is
     * held for more evidence — it is NOT admitted as a canon-bound proposal.
     */
    public function testWeakSignalIsHeldForMoreEvidence(): void
    {
        $verdict = $this->service->evaluate([
            'kind' => 'memory',
            'summary' => 'weak memory hint',
            'evidence_refs' => ['ev-1'],
            'sample_size' => 1,
            'effect_size' => 0.05,
        ]);

        $this->assertSame(AtlasLearningProposalDecisionService::STATUS_NEEDS_MORE_EVIDENCE, $verdict['status']);
        $this->assertSame('weak_signal_below_floor', $verdict['justification']);
        $this->assertLessThan(AtlasLearningProposalDecisionService::WEAK_SIGNAL_FLOOR, $verdict['strength']);
        $this->assertFalse($verdict['may_auto_apply']);
    }

    /**
     * Invariante: "proposta nao e aplicacao automatica" + "Aprendizado virar
     * mutacao silenciosa." A strong, well-evidenced CRITICAL proposal is
     * admitted but can NEVER auto-apply — it always requires review.
     */
    public function testStrongCriticalProposalIsAdmittedButNeverAutoApplies(): void
    {
        $verdict = $this->service->evaluate([
            'kind' => 'routing',
            'summary' => 'route summarisation default to challenger',
            'evidence_refs' => ['ev-1', 'ev-2', 'ev-3'],
            'sample_size' => 40,
            'effect_size' => 0.8,
        ]);

        $this->assertSame(AtlasLearningProposalDecisionService::STATUS_ADMITTED, $verdict['status']);
        $this->assertTrue($verdict['critical']);
        $this->assertFalse($verdict['may_auto_apply'], 'critical kinds must never auto-apply');
        $this->assertTrue($verdict['requires_review']);
        // The three documented output fields must be present and non-empty.
        $this->assertNotEmpty($verdict['justification']);
        $this->assertContains($verdict['risk'], [
            AtlasLearningProposalDecisionService::RISK_LOW,
            AtlasLearningProposalDecisionService::RISK_MEDIUM,
            AtlasLearningProposalDecisionService::RISK_HIGH,
        ]);
        $this->assertNotEmpty($verdict['suggested_action']);
        // Critical changes are never low risk.
        $this->assertNotSame(AtlasLearningProposalDecisionService::RISK_LOW, $verdict['risk']);
    }

    /**
     * A strong, well-evidenced NON-critical proposal IS allowed to auto-apply.
     * This proves the auto-apply gate keys on criticality, not on admission.
     */
    public function testStrongNonCriticalProposalMayAutoApply(): void
    {
        $verdict = $this->service->evaluate([
            'kind' => 'retrieval_hint',
            'summary' => 'boost a retrieval hint',
            'evidence_refs' => ['ev-1', 'ev-2', 'ev-3'],
            'sample_size' => 30,
            'effect_size' => 0.9,
        ]);

        $this->assertSame(AtlasLearningProposalDecisionService::STATUS_ADMITTED, $verdict['status']);
        $this->assertFalse($verdict['critical']);
        $this->assertTrue($verdict['may_auto_apply']);
        $this->assertSame(AtlasLearningProposalDecisionService::RISK_LOW, $verdict['risk']);
    }

    /**
     * Regras para IA: "IA deve separar sugestao, decisao e aplicacao." The
     * application stage is human_review for critical kinds and auto otherwise.
     */
    public function testStageSeparationForcesReviewOnlyForCriticalKinds(): void
    {
        $routing = $this->service->classifyStages('routing');
        $memory = $this->service->classifyStages('memory');

        $this->assertSame('learning_emits_proposal', $routing['suggestion']);
        $this->assertSame('human_or_policy_decides', $routing['decision']);
        $this->assertSame(AtlasLearningProposalDecisionService::APPLY_REVIEW, $routing['application']);
        $this->assertSame(AtlasLearningProposalDecisionService::APPLY_AUTO, $memory['application']);
    }

    /**
     * Escopo: "Permitido: propostas, rankings e curadoria." Ranking surfaces
     * admitted proposals first and excludes non-admitted ones from canon_ready.
     */
    public function testRankSurfacesAdmittedFirstAndCuratesCanonReady(): void
    {
        $weak = $this->service->evaluate([
            'kind' => 'memory',
            'evidence_refs' => ['ev-1'],
            'sample_size' => 1,
            'effect_size' => 0.05,
        ]);
        $noEvidence = $this->service->evaluate([
            'kind' => 'policy',
            'evidence_refs' => [],
            'sample_size' => 99,
            'effect_size' => 1.0,
        ]);
        $strong = $this->service->evaluate([
            'kind' => 'retrieval_hint',
            'evidence_refs' => ['ev-1', 'ev-2', 'ev-3'],
            'sample_size' => 30,
            'effect_size' => 0.9,
        ]);

        $ranked = $this->service->rank([$weak, $noEvidence, $strong]);

        // The admitted strong proposal sorts to the front.
        $this->assertSame(AtlasLearningProposalDecisionService::STATUS_ADMITTED, $ranked['ranked'][0]['status']);
        // Only the admitted one is canon-ready; weak + no-evidence are excluded.
        $this->assertSame(1, $ranked['canon_ready_count']);
        $this->assertSame(3, $ranked['total']);
    }

    /**
     * The doc's Exemplo made executable: a provider-comparison win is a routing
     * (critical) proposal — admitted with strong evidence, yet never auto-applied.
     */
    public function testProviderComparisonExampleIsCriticalAndReviewGated(): void
    {
        $verdict = $this->service->evaluateProviderComparison([
            'challenger' => 'model-b',
            'incumbent' => 'model-a',
            'task_class' => 'code_review',
            'win_rate' => 0.9,
            'sample_size' => 50,
            'evidence_refs' => ['bench-run-1', 'bench-run-2', 'bench-run-3'],
        ]);

        $this->assertSame(AtlasLearningProposalDecisionService::STATUS_ADMITTED, $verdict['status']);
        $this->assertTrue($verdict['critical']);
        $this->assertFalse($verdict['may_auto_apply']);
        $this->assertTrue($verdict['requires_review']);
    }
}
