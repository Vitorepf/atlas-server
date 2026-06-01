<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;
use App\Services\Engineering\AtlasDocumentationRealityAntibodyProposerService;
use App\Services\Engineering\AtlasDocumentationRealityFlowService;
use App\Services\Engineering\AtlasDocumentationRealityIntentAdvisoryService;
use App\Services\Engineering\AtlasDocumentationRealityReflectiveStatusService;
use App\Services\Engineering\AtlasDocumentationRealityRepairProposerService;
use App\Services\Engineering\AtlasDocumentationRealityWriteGateService;
use App\Services\Engineering\AtlasSoftwareTwinRuntimeService;
use App\Services\Engineering\EngineeringDocumentationHealthService;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The documented "Fluxo alvo para IA" composed as ONE read-only flow. ALL six
 * composed capabilities are MOCKED for determinism (no DB, no real prediction); no
 * RefreshDatabase — the orchestrator is pure composition over those reports.
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

        // post_write composes BOTH reconciliation (P2) and immunization (P3) stages.
        $this->assertTrue(data_get($payload, 'post_write.reconciliation.available'));
        $this->assertSame(2, data_get($payload, 'post_write.reconciliation.drift_count'));
        $this->assertSame(2, data_get($payload, 'post_write.reconciliation.proposal_count'));
        $this->assertArrayHasKey('immunization', (array) $payload['post_write']);

        // The L-inf reflective note is present and short.
        $this->assertSame('medium', data_get($payload, 'reflective_note.headline_confidence'));
        $this->assertFalse(data_get($payload, 'reflective_note.headline_is_bare_verdict'));
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
        $this->assertTrue(data_get($payload, 'reflective_note.available'));
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
     * Mock all six composed capabilities with deterministic, healthy readings. Any
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
