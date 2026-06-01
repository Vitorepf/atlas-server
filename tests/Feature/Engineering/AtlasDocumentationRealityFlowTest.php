<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;
use App\Services\Engineering\AtlasDocumentationRealityAntibodyProposerService;
use App\Services\Engineering\AtlasDocumentationRealityBidirectionalReconciliationService;
use App\Services\Engineering\AtlasDocumentationRealityCausalSelfModelService;
use App\Services\Engineering\AtlasDocumentationRealityCodeContractProposerService;
use App\Services\Engineering\AtlasDocumentationRealityFlowService;
use App\Services\Engineering\AtlasDocumentationRealityIntentAdvisoryService;
use App\Services\Engineering\AtlasDocumentationRealityOutcomeGroundingService;
use App\Services\Engineering\AtlasDocumentationRealityReflectiveStatusService;
use App\Services\Engineering\AtlasDocumentationRealityRepairProposerService;
use App\Services\Engineering\AtlasSoftwareTwinRuntimeService;
use App\Services\Engineering\EngineeringDocumentationHealthService;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The documented "Fluxo alvo para IA" composed as ONE read-only flow, now reflecting
 * the COMPLETE ladder: the FULL P2 reconciliation TRIANGLE (over-claim + under-claim +
 * doc-ahead-of-code contract) and the FULL L-inf promotable TRIAD (R1 causal + R2
 * humility + R3 pointer). ALL composed capabilities are MOCKED for determinism (no DB,
 * no real prediction); no RefreshDatabase — the orchestrator is pure composition over
 * those reports.
 *
 * The cardinal property under test: this is a FAITHFUL COMPOSITION. It calls each
 * capability and surfaces its REAL output (summarised) — it never re-derives,
 * softens or alters a verdict (a would_duplicate is surfaced as would_duplicate),
 * never fabricates a result when a collaborator throws (that stage is
 * {available:false}), never writes, and is NOT the enforcement (the L0 pre-commit
 * hook is). The envelope's claim_policy proves composes_only/writes:false.
 */
final class AtlasDocumentationRealityFlowTest extends TestCase
{
    public function test_for_proposed_change_composes_pre_write_write_boundary_and_post_write_sections(): void
    {
        $this->stubAll();

        $payload = app(AtlasDocumentationRealityFlowService::class)->forProposedChange(
            ['kind' => 'doc', 'graph_id' => 'atlas-brand-new-thing', 'capabilities' => ['fresh_capability'], 'objective' => 'reduce drift'],
            ['docs/engineering-knowledge-base/atlas-brand-new-thing.md'],
        );

        $this->assertSame(AtlasDocumentationRealityFlowService::SCHEMA, $payload['schema_version']);

        // pre_write composes BOTH the predictive (P1) and intent_advisory (O2) stages,
        // each surfacing its source method and real summary.
        $this->assertTrue(data_get($payload, 'pre_write.predictive.available'));
        $this->assertSame('AtlasSoftwareTwinRuntimeService::simulate', data_get($payload, 'pre_write.predictive.source'));
        $this->assertSame('clean', data_get($payload, 'pre_write.predictive.verdict'));
        $this->assertTrue(data_get($payload, 'pre_write.intent_advisory.available'));
        $this->assertSame('AtlasDocumentationRealityIntentAdvisoryService::adviseProposal', data_get($payload, 'pre_write.intent_advisory.source'));
        $this->assertSame(
            AtlasDocumentationRealityIntentAdvisoryService::RECO_PROCEED,
            data_get($payload, 'pre_write.intent_advisory.recommendation'),
        );
        // The O2 sovereignty block is surfaced faithfully.
        $this->assertTrue(data_get($payload, 'pre_write.intent_advisory.sovereignty.never_overrides_operator'));
        $this->assertFalse(data_get($payload, 'pre_write.intent_advisory.sovereignty.is_a_decision'));

        // write_boundary: with touched paths, the REAL L0 decide() verdict is surfaced,
        // and the hook — never this flow — is named as the active enforcement.
        $this->assertSame(
            'scripts/hooks/pre-commit (atlas:documentation-reality-write-gate)',
            data_get($payload, 'write_boundary.is_active_via'),
        );
        $this->assertTrue(data_get($payload, 'write_boundary.enforcement_is_the_hook_not_this'));
        $this->assertTrue(data_get($payload, 'write_boundary.enforcement.available'));
        $this->assertSame('allowed', data_get($payload, 'write_boundary.enforcement.decision'));

        // post_write composes the FULL P2 reconciliation TRIANGLE (over-claim +
        // under-claim + code-contract) and the P3 immunization, each surfacing its real
        // summary from its own source method.
        $this->assertTrue(data_get($payload, 'post_write.reconciliation.available'));
        $this->assertSame(2, data_get($payload, 'post_write.reconciliation.drift_count'));
        $this->assertSame(2, data_get($payload, 'post_write.reconciliation.proposal_count'));
        $this->assertArrayHasKey('immunization', (array) $payload['post_write']);
        // The post_write stage label reflects the full triangle.
        $this->assertSame(
            'P2_reconcile_over_under_and_code_contract_then_P3_immunize',
            data_get($payload, 'post_write.stage'),
        );

        // The L-inf reflective note is the FULL TRIAD and remains ONE composition.
        $this->assertFalse(data_get($payload, 'reflective_note.linf_complete'));
        $this->assertSame('medium', data_get($payload, 'reflective_note.humility.headline_confidence'));
        $this->assertFalse(data_get($payload, 'reflective_note.humility.headline_is_bare_verdict'));
    }

    public function test_post_write_carries_the_full_p2_triangle_each_surfacing_its_real_summary(): void
    {
        // The completed P2 triangle: BESIDE the over-claim reconciliation + immunization,
        // post_write now carries under_claim_reconciliation (the bidirectional service,
        // UNDER-claim direction only) + code_contract (the doc-ahead-of-code proposer).
        // Each surfaces its REAL summary from its own source method.
        $this->stubAll();

        $payload = app(AtlasDocumentationRealityFlowService::class)->forProposedChange(
            ['kind' => 'doc', 'graph_id' => 'atlas-brand-new-thing'],
            ['docs/engineering-knowledge-base/atlas-brand-new-thing.md'],
        );

        // Direction 1 (over-claim) — the original repair proposer, UNCHANGED.
        $this->assertTrue(data_get($payload, 'post_write.reconciliation.available'));
        $this->assertSame('AtlasDocumentationRealityRepairProposerService::proposeForDoc', data_get($payload, 'post_write.reconciliation.source'));

        // Direction 2 (under-claim) — bidirectional service, UNDER-claim direction ONLY
        // (the over-claim it internally delegates is NOT surfaced here, to avoid
        // double-counting the reconciliation stage above).
        $this->assertTrue(data_get($payload, 'post_write.under_claim_reconciliation.available'));
        $this->assertSame('AtlasDocumentationRealityBidirectionalReconciliationService::reconcileForDoc', data_get($payload, 'post_write.under_claim_reconciliation.source'));
        $this->assertSame('under_claim_only', data_get($payload, 'post_write.under_claim_reconciliation.direction_surfaced'));
        $this->assertSame(3, data_get($payload, 'post_write.under_claim_reconciliation.under_claim_count'));
        $this->assertSame(1, data_get($payload, 'post_write.under_claim_reconciliation.under_claim_unconfirmed_count'));
        $this->assertFalse(data_get($payload, 'post_write.under_claim_reconciliation.degraded'));
        // The over-claim direction the bidirectional service delegates is NOT surfaced here.
        $this->assertNull(data_get($payload, 'post_write.under_claim_reconciliation.over_claim_count'));

        // Direction 3 (doc-ahead-of-code) — the code-contract proposer's real summary.
        $this->assertTrue(data_get($payload, 'post_write.code_contract.available'));
        $this->assertSame('AtlasDocumentationRealityCodeContractProposerService::proposeForDoc', data_get($payload, 'post_write.code_contract.source'));
        $this->assertSame(4, data_get($payload, 'post_write.code_contract.docs_with_gaps'));
        $this->assertSame(9, data_get($payload, 'post_write.code_contract.total_unresolved_refs'));
        $this->assertFalse(data_get($payload, 'post_write.code_contract.degraded'));
    }

    public function test_reflective_note_carries_the_full_linf_triad_and_stays_a_composition_not_the_asymptote(): void
    {
        // The completed L-inf triad: causal (R1, change-specific) + humility (R2) +
        // self_improvement (R3 POINTER, not inline). linf_complete stays HARD false.
        $this->stubAll();

        $payload = app(AtlasDocumentationRealityFlowService::class)->forProposedChange(
            ['kind' => 'doc', 'graph_id' => 'atlas-brand-new-thing'],
            ['docs/engineering-knowledge-base/atlas-brand-new-thing.md'],
        );

        // causal (R1) — FOCUSED explainCapability, surfacing the headline chain's
        // intent/truth/result + why-link count + chain confidence, exactly as R1 made it.
        $this->assertTrue(data_get($payload, 'reflective_note.causal.available'));
        $this->assertSame('AtlasDocumentationRealityCausalSelfModelService::explainCapability', data_get($payload, 'reflective_note.causal.source'));
        // The focus is the touched .md path (reconciliationFocus prefers the first .md
        // path over the graph_id) — surfaced exactly as the flow passed it to R1.
        $this->assertSame('docs/engineering-knowledge-base/atlas-brand-new-thing.md', data_get($payload, 'reflective_note.causal.capability_focus'));
        $this->assertTrue(data_get($payload, 'reflective_note.causal.has_chain'));
        $this->assertSame('partial', data_get($payload, 'reflective_note.causal.intent_state'));
        $this->assertSame('partial', data_get($payload, 'reflective_note.causal.truth_state'));
        $this->assertSame(AtlasDocumentationRealityOutcomeGroundingService::GRADE_NO_SIGNAL, data_get($payload, 'reflective_note.causal.result_grade'));
        $this->assertSame(2, data_get($payload, 'reflective_note.causal.why_link_count'));
        $this->assertSame('medium', data_get($payload, 'reflective_note.causal.chain_confidence'));
        $this->assertFalse(data_get($payload, 'reflective_note.causal.linf_complete'));

        // humility (R2) — the existing headline, UNCHANGED in shape.
        $this->assertTrue(data_get($payload, 'reflective_note.humility.available'));
        $this->assertSame('medium', data_get($payload, 'reflective_note.humility.headline_confidence'));
        $this->assertFalse(data_get($payload, 'reflective_note.humility.headline_is_bare_verdict'));

        // self_improvement (R3) — a SMALL STATIC POINTER, NOT run inline per-change.
        $this->assertSame(
            'atlas:documentation-reality-self-improvement-modeling',
            data_get($payload, 'reflective_note.self_improvement.available_via'),
        );
        $this->assertSame('session/global, not per-change', data_get($payload, 'reflective_note.self_improvement.scope'));
        $this->assertStringContainsString('session level', (string) data_get($payload, 'reflective_note.self_improvement.note'));

        // The note remains ONE composition of fragments, never the asymptote.
        $this->assertFalse(data_get($payload, 'reflective_note.linf_complete'));
        $this->assertTrue(data_get($payload, 'reflective_note.is_one_composition_not_the_asymptote'));
    }

    public function test_causal_stage_skips_shape_consistently_when_no_capability_focus_is_resolvable(): void
    {
        // With neither a touched .md path nor a graph_id/slug, R1 has no focus. The causal
        // stage SKIPS (never a heavy explainAll) but stays SHAPE-CONSISTENT: it carries
        // `available` (false) like every other causal outcome, so a consumer can branch on
        // causal.available uniformly. It is a deliberate skip (evaluated:false) — never a
        // fabricated chain, never linf_complete:true — and the whole flow still returns.
        $this->stubAll();

        $payload = app(AtlasDocumentationRealityFlowService::class)->forProposedChange(
            ['kind' => 'doc', 'objective' => 'a change with no resolvable id'],
            [],
        );

        $this->assertFalse(data_get($payload, 'reflective_note.causal.available'));
        $this->assertFalse(data_get($payload, 'reflective_note.causal.evaluated'));
        $this->assertFalse(data_get($payload, 'reflective_note.causal.linf_complete'));
        $this->assertStringContainsString('no resolvable capability focus', (string) data_get($payload, 'reflective_note.causal.note'));

        // The whole flow still composes: the hash + the other triad members are intact.
        $this->assertNotNull(data_get($payload, 'flow_hash'));
        $this->assertTrue(data_get($payload, 'reflective_note.humility.available'));
        $this->assertFalse(data_get($payload, 'reflective_note.linf_complete'));
    }

    public function test_a_throwing_new_collaborator_degrades_only_that_stage_and_never_breaks_the_flow(): void
    {
        // Each of the THREE new collaborators throws. Each must degrade ITS OWN stage to
        // {available:false} with a reason — never a fabricated result — while the whole
        // envelope still returns and every OTHER stage composes normally.
        $this->stubAll();
        $this->mock(AtlasDocumentationRealityBidirectionalReconciliationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reconcileForDoc')->andThrow(new \RuntimeException('ledger unavailable'));
            $mock->shouldReceive('reconcileAll')->andThrow(new \RuntimeException('ledger unavailable'));
        });
        $this->mock(AtlasDocumentationRealityCodeContractProposerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('proposeForDoc')->andThrow(new \RuntimeException('index blind'));
            $mock->shouldReceive('proposeAll')->andThrow(new \RuntimeException('index blind'));
        });
        $this->mock(AtlasDocumentationRealityCausalSelfModelService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('explainCapability')->andThrow(new \RuntimeException('truth ledger down'));
        });

        $payload = app(AtlasDocumentationRealityFlowService::class)->forProposedChange(
            ['kind' => 'doc', 'graph_id' => 'atlas-brand-new-thing'],
            ['docs/engineering-knowledge-base/atlas-brand-new-thing.md'],
        );

        // The envelope STILL returns (the whole flow never breaks), hashed and read-only.
        $this->assertSame(AtlasDocumentationRealityFlowService::SCHEMA, $payload['schema_version']);
        $this->assertIsString($payload['flow_hash']);

        // Each new stage degraded to {available:false} with a reason — no fabricated data.
        $this->assertFalse(data_get($payload, 'post_write.under_claim_reconciliation.available'));
        $this->assertArrayHasKey('reason', (array) data_get($payload, 'post_write.under_claim_reconciliation'));
        $this->assertNull(data_get($payload, 'post_write.under_claim_reconciliation.under_claim_count'));

        $this->assertFalse(data_get($payload, 'post_write.code_contract.available'));
        $this->assertArrayHasKey('reason', (array) data_get($payload, 'post_write.code_contract'));
        $this->assertNull(data_get($payload, 'post_write.code_contract.docs_with_gaps'));

        $this->assertFalse(data_get($payload, 'reflective_note.causal.available'));
        $this->assertArrayHasKey('reason', (array) data_get($payload, 'reflective_note.causal'));
        $this->assertNull(data_get($payload, 'reflective_note.causal.intent_state'));

        // Every OTHER stage is unaffected — composition is per-stage degrade-safe.
        $this->assertTrue(data_get($payload, 'pre_write.predictive.available'));
        $this->assertTrue(data_get($payload, 'write_boundary.enforcement.available'));
        $this->assertTrue(data_get($payload, 'post_write.reconciliation.available'));
        $this->assertTrue(data_get($payload, 'reflective_note.humility.available'));
        // The R3 pointer is a static constant — present even when collaborators throw.
        $this->assertSame(
            'atlas:documentation-reality-self-improvement-modeling',
            data_get($payload, 'reflective_note.self_improvement.available_via'),
        );
    }

    public function test_a_would_duplicate_prediction_is_surfaced_faithfully_never_softened(): void
    {
        // P1 predicts would_duplicate (graph_id collision). The flow must surface that
        // verdict EXACTLY — it does not soften or re-derive it.
        $this->stubAll(predictive: $this->duplicatePrediction());

        $payload = app(AtlasDocumentationRealityFlowService::class)->forProposedChange(
            ['kind' => 'doc', 'graph_id' => 'atlas-existing-thing'],
            ['docs/engineering-knowledge-base/atlas-existing-thing.md'],
        );

        $this->assertSame('would_duplicate', data_get($payload, 'pre_write.predictive.verdict'));
        $this->assertTrue(data_get($payload, 'pre_write.predictive.would_duplicate'));
        $this->assertSame('graph_id_collision', data_get($payload, 'pre_write.predictive.duplicate_reason'));
    }

    public function test_no_failure_carries_the_on_demand_note_not_a_fabricated_antibody(): void
    {
        $this->stubAll();

        $payload = app(AtlasDocumentationRealityFlowService::class)->forProposedChange(
            ['kind' => 'doc', 'graph_id' => 'atlas-brand-new-thing'],
            ['docs/engineering-knowledge-base/atlas-brand-new-thing.md'],
            // no failure supplied
        );

        $this->assertSame(
            'no escaped failure supplied; antibody synthesis is on-demand',
            data_get($payload, 'post_write.immunization.note'),
        );
        $this->assertFalse(data_get($payload, 'post_write.immunization.evaluated'));
        // No antibody count / fabricated antibody is present.
        $this->assertNull(data_get($payload, 'post_write.immunization.antibody_count'));

        // And WITH a failure, P3 is actually composed (proposeFromCapsule surfaced).
        $withFailure = app(AtlasDocumentationRealityFlowService::class)->forProposedChange(
            ['kind' => 'doc', 'graph_id' => 'atlas-brand-new-thing'],
            ['docs/engineering-knowledge-base/atlas-brand-new-thing.md'],
            ['kind' => 'missing_context', 'summary' => 'an AI edited the indexer without reading the ADRS doc'],
        );
        $this->assertTrue(data_get($withFailure, 'post_write.immunization.available'));
        $this->assertSame('AtlasDocumentationRealityAntibodyProposerService::proposeFromCapsule', data_get($withFailure, 'post_write.immunization.source'));
        $this->assertSame(1, data_get($withFailure, 'post_write.immunization.antibody_count'));
        $this->assertTrue(data_get($withFailure, 'post_write.immunization.has_reproducing_test_outline'));
    }

    public function test_a_throwing_collaborator_degrades_that_stage_never_fabricates(): void
    {
        // P2 (the repair proposer) throws. That ONE stage must degrade to
        // {available:false} with a reason — never a fabricated reconciliation — while
        // every OTHER stage still composes normally.
        $this->stubAll();
        $this->mock(AtlasDocumentationRealityRepairProposerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('proposeForDoc')->andThrow(new \RuntimeException('ledger unavailable'));
            $mock->shouldReceive('proposeAll')->andThrow(new \RuntimeException('ledger unavailable'));
        });

        $payload = app(AtlasDocumentationRealityFlowService::class)->forProposedChange(
            ['kind' => 'doc', 'graph_id' => 'atlas-brand-new-thing'],
            ['docs/engineering-knowledge-base/atlas-brand-new-thing.md'],
        );

        $this->assertFalse(data_get($payload, 'post_write.reconciliation.available'));
        $this->assertArrayHasKey('reason', (array) data_get($payload, 'post_write.reconciliation'));
        // No fabricated reconciliation numbers leaked into the degraded stage.
        $this->assertNull(data_get($payload, 'post_write.reconciliation.drift_count'));

        // The other stages are unaffected — composition is per-stage degrade-safe.
        $this->assertTrue(data_get($payload, 'pre_write.predictive.available'));
        $this->assertTrue(data_get($payload, 'write_boundary.enforcement.available'));
        // The over-claim direction throwing does NOT take down the other P2 directions.
        $this->assertTrue(data_get($payload, 'post_write.under_claim_reconciliation.available'));
        $this->assertTrue(data_get($payload, 'post_write.code_contract.available'));
        $this->assertTrue(data_get($payload, 'reflective_note.humility.available'));
        $this->assertTrue(data_get($payload, 'reflective_note.causal.available'));
    }

    public function test_claim_policy_and_envelope_prove_read_only_composition(): void
    {
        $this->stubAll();

        $payload = app(AtlasDocumentationRealityFlowService::class)->forProposedChange(
            ['kind' => 'doc', 'graph_id' => 'atlas-brand-new-thing'],
            ['docs/engineering-knowledge-base/atlas-brand-new-thing.md'],
        );

        $this->assertFalse($payload['writes']);
        $this->assertTrue(data_get($payload, 'claim_policy.read_only'));
        $this->assertFalse(data_get($payload, 'claim_policy.writes'));
        $this->assertTrue(data_get($payload, 'claim_policy.composes_only'));
        $this->assertTrue(data_get($payload, 'claim_policy.changes_no_behavior'));
        $this->assertTrue(data_get($payload, 'claim_policy.enforcement_is_the_hook_not_this'));
        $this->assertFalse(data_get($payload, 'claim_policy.executes'));
        $this->assertFalse(data_get($payload, 'claim_policy.mutates'));
        $this->assertFalse(data_get($payload, 'claim_policy.authorizes_mutation'));
        $this->assertFalse(data_get($payload, 'claim_policy.auto_acts'));
        $this->assertFalse(data_get($payload, 'claim_policy.re_derives_verdicts'));
        $this->assertIsString($payload['flow_hash']);

        // Honest labels intact: with touched paths the composed verdict is INDICATIVE,
        // never authoritative — the commit-boundary hook renders the authoritative one.
        $this->assertTrue(data_get($payload, 'write_boundary.verdict_is_indicative_not_authoritative'));
    }

    public function test_composes_capabilities_lists_the_full_p2_triangle_and_linf_triad(): void
    {
        $this->stubAll();

        $payload = app(AtlasDocumentationRealityFlowService::class)->forProposedChange(
            ['kind' => 'doc', 'graph_id' => 'atlas-brand-new-thing'],
            ['docs/engineering-knowledge-base/atlas-brand-new-thing.md'],
        );

        $sources = array_map(
            static fn (array $c): string => (string) ($c['source'] ?? ''),
            (array) $payload['composes_capabilities'],
        );

        // The original rungs.
        $this->assertContains('AtlasSoftwareTwinRuntimeService::simulate', $sources);
        $this->assertContains('AtlasDocumentationRealityIntentAdvisoryService::adviseProposal', $sources);
        $this->assertContains('AtlasDocumentationRealityWriteGateService::decide', $sources);
        $this->assertContains('AtlasDocumentationRealityRepairProposerService::proposeAll|proposeForDoc', $sources);
        $this->assertContains('AtlasDocumentationRealityAntibodyProposerService::proposeFromCapsule', $sources);
        $this->assertContains('AtlasDocumentationRealityReflectiveStatusService::selfAssessment', $sources);

        // The full P2 triangle: the two NEW directions are now listed.
        $this->assertContains('AtlasDocumentationRealityBidirectionalReconciliationService::reconcileAll|reconcileForDoc', $sources);
        $this->assertContains('AtlasDocumentationRealityCodeContractProposerService::proposeAll|proposeForDoc', $sources);

        // The full L-inf triad: the NEW R1 causal source, plus the R3 pointer (named).
        $this->assertContains('AtlasDocumentationRealityCausalSelfModelService::explainCapability', $sources);
        $r3Pointer = array_values(array_filter(
            $sources,
            static fn (string $s): bool => str_contains($s, 'atlas:documentation-reality-self-improvement-modeling'),
        ));
        $this->assertNotEmpty($r3Pointer, 'composes_capabilities must name the R3 self-improvement pointer');

        // The full set: 6 original + 2 new P2 + 1 new R1 + 1 R3 pointer = 10 rows.
        $this->assertCount(10, (array) $payload['composes_capabilities']);
    }

    public function test_without_touched_paths_the_write_boundary_names_the_hook_not_this_flow(): void
    {
        $this->stubAll();

        $payload = app(AtlasDocumentationRealityFlowService::class)->forProposedChange(
            ['kind' => 'doc', 'graph_id' => 'atlas-brand-new-thing'],
            // no touched paths
        );

        // With no paths the flow does NOT adjudicate; it explicitly defers to the hook.
        $this->assertFalse(data_get($payload, 'write_boundary.enforcement.evaluated'));
        $this->assertStringContainsString(
            'pre-commit hook',
            (string) data_get($payload, 'write_boundary.enforcement.note'),
        );
        $this->assertSame(
            'scripts/hooks/pre-commit (atlas:documentation-reality-write-gate)',
            data_get($payload, 'write_boundary.is_active_via'),
        );
    }

    // --- helpers -----------------------------------------------------------

    /**
     * Mock all composed capabilities with deterministic, healthy readings — the full
     * P2 triangle (over-claim + under-claim + code-contract) and the full L-inf triad
     * (R1 causal + R2 humility; R3 is a static pointer, not a collaborator). Any
     * argument can be overridden to drive a specific case.
     *
     * @param  array<string,mixed>|null  $predictive
     */
    private function stubAll(?array $predictive = null): void
    {
        $predictive ??= $this->cleanPrediction();

        $this->mock(AtlasSoftwareTwinRuntimeService::class, function (MockInterface $mock) use ($predictive): void {
            $mock->shouldReceive('simulate')->andReturn($predictive);
        });

        $this->mock(AtlasDocumentationRealityIntentAdvisoryService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('adviseProposal')->andReturn([
                'schema_version' => AtlasDocumentationRealityIntentAdvisoryService::SCHEMA,
                'advisory_recommendation' => [
                    'value' => AtlasDocumentationRealityIntentAdvisoryService::RECO_PROCEED,
                    'is_a_decision' => false,
                ],
                'sovereignty' => [
                    'advisory_only' => true,
                    'human_gated' => true,
                    'never_overrides_operator' => true,
                    'operator_decides' => true,
                    'is_a_decision' => false,
                    'auto_acts' => false,
                ],
            ]);
        });

        // The write-gate is FINAL (cannot be Mockery-subclassed), so we let the REAL
        // pure decider run and mock ITS two collaborators — mirroring the existing
        // AtlasDocumentationRealityWriteGateServiceTest. With clean docs-health and a
        // no-drift frontmatter, decide() on a touched canonical doc returns
        // allowed/touched_docs_clean deterministically (no filesystem, no DB).
        $this->mock(EngineeringDocumentationHealthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('report')->andReturn(['blocking' => [], 'legacy_debt' => []]);
        });
        $this->mock(AtlasAaeosImplementationTruthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('driftForFrontmatter')->andReturn(['drift' => false]);
        });

        $this->mock(AtlasDocumentationRealityRepairProposerService::class, function (MockInterface $mock): void {
            $proposals = [
                'schema_version' => AtlasDocumentationRealityRepairProposerService::SCHEMA,
                'summary' => ['drift_count' => 2, 'proposal_count' => 2],
                'degraded' => false,
                'proposals' => [],
            ];
            $mock->shouldReceive('proposeForDoc')->andReturn($proposals);
            $mock->shouldReceive('proposeAll')->andReturn($proposals);
        });

        $this->mock(AtlasDocumentationRealityAntibodyProposerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('proposeFromCapsule')->andReturn([
                'schema_version' => AtlasDocumentationRealityAntibodyProposerService::SCHEMA,
                'summary' => ['failure_count' => 1, 'antibody_count' => 1],
                'degraded' => false,
                'antibodies' => [[
                    'failure_kind' => 'missing_context',
                    'reproducing_test_outline' => ['steps' => ['arrange', 'act', 'assert']],
                    'status' => AtlasDocumentationRealityAntibodyProposerService::STATUS,
                ]],
            ]);
        });

        $this->mock(AtlasDocumentationRealityReflectiveStatusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('selfAssessment')->andReturn([
                'schema_version' => AtlasDocumentationRealityReflectiveStatusService::SCHEMA,
                'level' => 'L-inf (one promoted fragment: R2 epistemic humility)',
                'linf_complete' => false,
                'headline' => [
                    'confidence' => 'medium',
                    'is_bare_verdict' => false,
                ],
            ]);
        });

        // P2 direction 2 (bidirectional / UNDER-claim). The flow surfaces ONLY the
        // under-claim direction from its summary; the over-claim it internally delegates
        // is intentionally NOT surfaced by the flow.
        $this->mock(AtlasDocumentationRealityBidirectionalReconciliationService::class, function (MockInterface $mock): void {
            $packet = [
                'schema_version' => AtlasDocumentationRealityBidirectionalReconciliationService::SCHEMA,
                'degraded' => false,
                'over_claim_repairs' => [],
                'under_claim_upgrades' => [],
                'summary' => [
                    'over_claim_count' => 5,
                    'under_claim_count' => 3,
                    'under_claim_unconfirmed_count' => 1,
                    'total_reconciliations' => 8,
                ],
            ];
            $mock->shouldReceive('reconcileForDoc')->andReturn($packet);
            $mock->shouldReceive('reconcileAll')->andReturn($packet);
        });

        // P2 direction 3 (doc-ahead-of-code CONTRACT proposer).
        $this->mock(AtlasDocumentationRealityCodeContractProposerService::class, function (MockInterface $mock): void {
            $contract = [
                'schema_version' => AtlasDocumentationRealityCodeContractProposerService::SCHEMA,
                'degraded' => false,
                'proposals' => [],
                'summary' => [
                    'docs_with_gaps' => 4,
                    'total_unresolved_refs' => 9,
                ],
            ];
            $mock->shouldReceive('proposeForDoc')->andReturn($contract);
            $mock->shouldReceive('proposeAll')->andReturn($contract);
        });

        // L-inf fragment R1 (causal self-model). The flow calls the FOCUSED
        // explainCapability and surfaces a SHORT read of the headline causal chain.
        $this->mock(AtlasDocumentationRealityCausalSelfModelService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('explainCapability')->andReturn([
                'schema_version' => AtlasDocumentationRealityCausalSelfModelService::SCHEMA,
                'linf_complete' => false,
                'causal_chains' => [[
                    'capability_id' => 'atlas-brand-new-thing',
                    'intent' => ['claimed_state' => 'partial'],
                    'truth' => ['computed_state' => 'partial'],
                    'result' => ['grade' => AtlasDocumentationRealityOutcomeGroundingService::GRADE_NO_SIGNAL],
                    'why' => [
                        ['statement' => 'doc and code agree', 'confidence' => 'high'],
                        ['statement' => 'built but not validated in world', 'confidence' => 'low'],
                    ],
                    'calibration' => ['confidence' => 'medium'],
                    'linf_complete' => false,
                ]],
            ]);
        });
    }

    /**
     * A clean P1 prediction: no duplication, owner resolved, no drift.
     *
     * @return array<string,mixed>
     */
    private function cleanPrediction(): array
    {
        return [
            'schema_version' => AtlasSoftwareTwinRuntimeService::PREDICTIVE_SCHEMA_VERSION,
            'prediction' => [
                'verdict' => 'clean',
                'would_duplicate' => ['duplicate' => false, 'reason' => 'none'],
                'would_drift' => false,
                'owner' => ['resolved' => true, 'owner_doc_id' => 'documentation-governance', 'owner_doc_path' => 'docs/x.md'],
                'degraded' => false,
            ],
        ];
    }

    /**
     * A P1 prediction whose verdict is would_duplicate (graph_id collision).
     *
     * @return array<string,mixed>
     */
    private function duplicatePrediction(): array
    {
        return [
            'schema_version' => AtlasSoftwareTwinRuntimeService::PREDICTIVE_SCHEMA_VERSION,
            'prediction' => [
                'verdict' => 'would_duplicate',
                'would_duplicate' => [
                    'duplicate' => true,
                    'reason' => 'graph_id_collision',
                    'graph_id_collisions' => [
                        ['graph_id' => 'atlas-existing-thing', 'existing_doc' => 'docs/engineering-knowledge-base/atlas-existing-thing.md'],
                    ],
                ],
                'would_drift' => false,
                'owner' => null,
                'degraded' => false,
            ],
        ];
    }
}
