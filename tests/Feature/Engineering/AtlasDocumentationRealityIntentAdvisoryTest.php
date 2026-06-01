<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Services\Engineering\AtlasDocumentationRealityIntentAdvisoryService;
use App\Services\Engineering\AtlasSoftwareTwinRuntimeService;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * L2-O2 (first increment) — the intent advisory. simulate() is mocked for
 * determinism (no DB, no real prediction). No RefreshDatabase.
 *
 * THE CARDINAL RULE under test (operator sovereignty): the advisory is STRICTLY
 * advisory + human-gated. EVERY response carries the sovereignty block with
 * is_a_decision=false, never_overrides_operator=true, auto_acts=false and
 * writes=false — and there is NO binding allow/block/deny/gate verdict anywhere. A
 * would_duplicate proposal advises reconsider; a clean proposal WITH an objective
 * advises proceed; a clean proposal with NO objective needs the operator's
 * judgment. In all cases the advisory opines and the operator decides.
 */
final class AtlasDocumentationRealityIntentAdvisoryTest extends TestCase
{
    public function test_would_duplicate_proposal_advises_reconsider_with_sovereignty_block(): void
    {
        $this->stubSimulate($this->duplicatePrediction());

        $payload = app(AtlasDocumentationRealityIntentAdvisoryService::class)
            ->adviseProposal(['kind' => 'doc', 'graph_id' => 'atlas-existing-thing'], 'ship the loop runner');

        $this->assertSame(AtlasDocumentationRealityIntentAdvisoryService::SCHEMA, $payload['schema_version']);
        $this->assertSame(
            AtlasDocumentationRealityIntentAdvisoryService::RECO_RECONSIDER,
            data_get($payload, 'advisory_recommendation.value'),
        );

        // The recommendation is unmistakably advisory, never a decision.
        $this->assertTrue(data_get($payload, 'advisory_recommendation.is_advisory'));
        $this->assertFalse(data_get($payload, 'advisory_recommendation.is_a_decision'));

        // Sovereignty block present and load-bearing.
        $this->assertSovereignty($payload);

        // The duplication consideration surfaced, framed as a reconsider/reuse nudge.
        $kinds = array_column((array) $payload['considerations'], 'kind');
        $this->assertContains('possible_duplication', $kinds);
        $this->assertNotEmpty($payload['leverage_questions']);
    }

    public function test_clean_proposal_with_objective_advises_proceed_still_advisory(): void
    {
        $this->stubSimulate($this->cleanPrediction());

        $payload = app(AtlasDocumentationRealityIntentAdvisoryService::class)
            ->adviseProposal(
                ['kind' => 'doc', 'graph_id' => 'atlas-brand-new-thing', 'capabilities' => ['fresh_capability']],
                'reduce drift across the documentation-reality ladder',
            );

        $this->assertSame(
            AtlasDocumentationRealityIntentAdvisoryService::RECO_PROCEED,
            data_get($payload, 'advisory_recommendation.value'),
        );

        // proceed is STILL advisory — buildable is not the same as authorized.
        $this->assertTrue(data_get($payload, 'advisory_recommendation.is_advisory'));
        $this->assertFalse(data_get($payload, 'advisory_recommendation.is_a_decision'));
        $this->assertTrue(data_get($payload, 'proposal.objective_stated'));
        $this->assertSovereignty($payload);
    }

    public function test_clean_proposal_with_no_objective_needs_operator_judgment(): void
    {
        $this->stubSimulate($this->cleanPrediction());

        $payload = app(AtlasDocumentationRealityIntentAdvisoryService::class)
            ->adviseProposal(['kind' => 'doc', 'graph_id' => 'atlas-brand-new-thing']);
        // No objective passed: leverage cannot be assessed.

        $this->assertSame(
            AtlasDocumentationRealityIntentAdvisoryService::RECO_NEEDS_JUDGMENT,
            data_get($payload, 'advisory_recommendation.value'),
        );
        $this->assertFalse(data_get($payload, 'proposal.objective_stated'));

        // The "objective not stated" consideration must be present.
        $kinds = array_column((array) $payload['considerations'], 'kind');
        $this->assertContains('objective_not_stated', $kinds);
        $this->assertSovereignty($payload);
    }

    public function test_every_response_carries_the_sovereignty_guarantees(): void
    {
        // Across all three branches, the sovereignty guarantees and writes:false are
        // invariant — they never depend on the recommendation.
        $scenarios = [
            [$this->duplicatePrediction(), 'an objective'],
            [$this->cleanPrediction(), 'an objective'],
            [$this->cleanPrediction(), ''],
        ];

        foreach ($scenarios as [$prediction, $objective]) {
            $this->stubSimulate($prediction);
            $payload = app(AtlasDocumentationRealityIntentAdvisoryService::class)
                ->adviseProposal(['kind' => 'doc', 'graph_id' => 'atlas-x'], $objective);

            $this->assertTrue(data_get($payload, 'sovereignty.advisory_only'));
            $this->assertTrue(data_get($payload, 'sovereignty.never_overrides_operator'));
            $this->assertFalse(data_get($payload, 'sovereignty.is_a_decision'));
            $this->assertFalse(data_get($payload, 'sovereignty.auto_acts'));
            $this->assertFalse($payload['writes']);

            // claim_policy mirrors the sovereignty guarantees.
            $this->assertTrue(data_get($payload, 'claim_policy.advisory_only'));
            $this->assertTrue(data_get($payload, 'claim_policy.never_overrides_operator'));
            $this->assertFalse(data_get($payload, 'claim_policy.is_a_decision'));
            $this->assertFalse(data_get($payload, 'claim_policy.auto_acts'));
            $this->assertFalse(data_get($payload, 'claim_policy.writes'));
            $this->assertIsString($payload['intent_advisory_hash']);
        }
    }

    public function test_advisory_carries_no_binding_decision_or_gate_verdict(): void
    {
        // The sovereignty guarantee proved structurally: there is NO field anywhere
        // in the envelope that represents a binding decision/gate verdict
        // (allow/block/deny/gate/authorize/verdict-as-decision). The only "verdict"
        // present is the borrowed TECHNICAL signal from simulate, which is a
        // prediction, never an authorization — and it lives under technical_signal.
        $this->stubSimulate($this->duplicatePrediction());

        $payload = app(AtlasDocumentationRealityIntentAdvisoryService::class)
            ->adviseProposal(['kind' => 'doc', 'graph_id' => 'atlas-existing-thing'], 'ship it');

        $flattened = json_encode($payload, JSON_THROW_ON_ERROR);

        // No binding-decision vocabulary as a key anywhere in the advisory envelope.
        foreach (['"allow"', '"block"', '"deny"', '"blocked"', '"allowed"', '"denied"', '"gate_verdict"', '"authorized"', '"authorize"'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $flattened,
                "Advisory must not contain a binding decision/gate field: {$forbidden}",
            );
        }

        // The recommendation value itself is one of the advisory enum values only.
        $this->assertContains(
            data_get($payload, 'advisory_recommendation.value'),
            [
                AtlasDocumentationRealityIntentAdvisoryService::RECO_RECONSIDER,
                AtlasDocumentationRealityIntentAdvisoryService::RECO_NEEDS_JUDGMENT,
                AtlasDocumentationRealityIntentAdvisoryService::RECO_PROCEED,
            ],
        );
        // Every advisory enum value is suffixed _advisory — it can never be mistaken
        // for a binding verdict.
        $this->assertStringEndsWith('_advisory', (string) data_get($payload, 'advisory_recommendation.value'));
    }

    // --- helpers -----------------------------------------------------------

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertSovereignty(array $payload): void
    {
        $this->assertTrue(data_get($payload, 'sovereignty.advisory_only'));
        $this->assertTrue(data_get($payload, 'sovereignty.human_gated'));
        $this->assertTrue(data_get($payload, 'sovereignty.never_overrides_operator'));
        $this->assertTrue(data_get($payload, 'sovereignty.operator_decides'));
        $this->assertFalse(data_get($payload, 'sovereignty.is_a_decision'));
        $this->assertFalse(data_get($payload, 'sovereignty.auto_acts'));
    }

    /**
     * @param  array<string,mixed>  $prediction
     */
    private function stubSimulate(array $prediction): void
    {
        $this->mock(AtlasSoftwareTwinRuntimeService::class, function (MockInterface $mock) use ($prediction): void {
            $mock->shouldReceive('simulate')->andReturn($prediction);
        });
    }

    /**
     * A simulate() prediction whose verdict is would_duplicate (graph_id collision).
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
                    'kind' => 'doc',
                    'duplicate' => true,
                    'degraded' => false,
                    'reason' => 'graph_id_collision',
                    'graph_id_collisions' => [
                        ['graph_id' => 'atlas-existing-thing', 'existing_doc' => 'docs/engineering-knowledge-base/atlas-existing-thing.md'],
                    ],
                    'capability_overlap' => [],
                ],
                'would_drift' => false,
                'owner' => null,
                'blast_radius' => ['resolved' => false],
                'degraded' => false,
            ],
        ];
    }

    /**
     * A clean simulate() prediction: no duplication, owner resolved, no drift.
     *
     * @return array<string,mixed>
     */
    private function cleanPrediction(): array
    {
        return [
            'schema_version' => AtlasSoftwareTwinRuntimeService::PREDICTIVE_SCHEMA_VERSION,
            'prediction' => [
                'verdict' => 'clean',
                'would_duplicate' => [
                    'kind' => 'doc',
                    'duplicate' => false,
                    'degraded' => false,
                    'reason' => 'none',
                    'graph_id_collisions' => [],
                    'capability_overlap' => [],
                ],
                'would_drift' => false,
                'owner' => ['resolved' => true, 'confidence' => 90, 'owner_doc_id' => 'documentation-governance'],
                'blast_radius' => ['resolved' => false],
                'degraded' => false,
            ],
        ];
    }
}
