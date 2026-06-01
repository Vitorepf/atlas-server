<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Services\Engineering\AtlasDocumentationRealityCausalSelfModelService;
use App\Services\Engineering\AtlasDocumentationRealityReflectiveStatusService;
use App\Services\Engineering\AtlasDocumentationRealitySelfImprovementModelingService;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * L-inf (ONE promoted fragment: R3 SELF-IMPROVING MODELING / meta-learning) — the
 * proposal-only maintainer of the self-model's OWN evolution ladder. The two composed
 * fragments (R1 causal self-model, R2 reflective status) are MOCKED for determinism;
 * no RefreshDatabase — the service is pure composition over those reports and touches
 * no schema of its own.
 *
 * The cardinal rules under test:
 *  - PROPOSAL-ONLY: never self-modifies, never auto-applies; every proposal is
 *    is_proposal=true / would_self_modify=false / auto_applied=false.
 *  - DATA-limit vs MODELING-limit: a modeling-limit earns a concrete proposed_next_rung;
 *    a data-limit is excluded (proposed_next_rung=null, awaits external reality) — R3
 *    NEVER proposes manufacturing external data.
 *  - GROUNDED + CALIBRATED: every proposal quotes a real declared R1/R2 limit and
 *    carries a non-empty blind_spot; the guard throws otherwise. linf_complete HARD false.
 */
final class AtlasDocumentationRealitySelfImprovementModelingTest extends TestCase
{
    /**
     * Build the service with R1 + R2 mocked. R1 returns a causal_self_model envelope
     * whose chains carry the why-links/calibration blind_spots R3 reads as declared
     * limits; R2 returns a reflective-status envelope whose claims/headline carry
     * declared blind_spots. Each mock is deterministic so classification is predictable.
     *
     * @param  array<string,mixed>  $r1
     * @param  array<string,mixed>  $r2
     */
    private function service(
        array $r1,
        array $r2,
        bool $r1Throws = false,
        bool $r2Throws = false,
    ): AtlasDocumentationRealitySelfImprovementModelingService {
        $this->mock(AtlasDocumentationRealityCausalSelfModelService::class, function (MockInterface $mock) use ($r1, $r1Throws): void {
            if ($r1Throws) {
                $mock->shouldReceive('explainAll')->andThrow(new \RuntimeException('R1 unavailable'));
            } else {
                $mock->shouldReceive('explainAll')->andReturn($r1);
            }
        });

        $this->mock(AtlasDocumentationRealityReflectiveStatusService::class, function (MockInterface $mock) use ($r2, $r2Throws): void {
            if ($r2Throws) {
                $mock->shouldReceive('selfAssessment')->andThrow(new \RuntimeException('R2 unavailable'));
            } else {
                $mock->shouldReceive('selfAssessment')->andReturn($r2);
            }
        });

        return app(AtlasDocumentationRealitySelfImprovementModelingService::class);
    }

    /**
     * A minimal R1 envelope with one causal chain, parameterised by its why-links and
     * calibration blind_spots — the exact shape R1::explainAll() emits.
     *
     * @param  array<int,array<string,mixed>>  $whyLinks
     * @param  array<int,string>  $calibrationBlindSpots
     * @return array<string,mixed>
     */
    private function r1Envelope(string $capability, array $whyLinks, array $calibrationBlindSpots = []): array
    {
        return [
            'schema_version' => AtlasDocumentationRealityCausalSelfModelService::SCHEMA,
            'fragment' => 'R1_causal_self_model',
            'linf_complete' => false,
            'causal_chains' => [[
                'capability_id' => $capability,
                'owner_doc' => "docs/engineering-knowledge-base/{$capability}.md",
                'why' => $whyLinks,
                'calibration' => [
                    'confidence' => 'low',
                    'blind_spots' => $calibrationBlindSpots,
                ],
                'linf_fragment' => true,
                'linf_complete' => false,
                'fragment' => 'R1_causal_self_model',
            ]],
        ];
    }

    /**
     * One R1 why-link in the exact shape R1 emits.
     *
     * @return array<string,mixed>
     */
    private function whyLink(string $statement, string $inference, string $confidence, string $blindSpot): array
    {
        return [
            'statement' => $statement,
            'basis' => 'capability_truth_ledger (test)',
            'inference' => $inference,
            'uncertainty' => ['confidence' => $confidence, 'blind_spot' => $blindSpot],
        ];
    }

    /**
     * A minimal R2 envelope with parameterised claims + headline blind_spots — the exact
     * shape R2::selfAssessment() emits.
     *
     * @param  array<int,array<string,mixed>>  $claims
     * @param  array<int,string>  $headlineBlindSpots
     * @return array<string,mixed>
     */
    private function r2Envelope(array $claims = [], array $headlineBlindSpots = []): array
    {
        return [
            'schema_version' => AtlasDocumentationRealityReflectiveStatusService::SCHEMA,
            'fragment' => 'R2_epistemic_humility',
            'linf_complete' => false,
            'claims' => $claims,
            'headline' => [
                'question' => 'Is the ADRS doc<->runtime 10/10?',
                'confidence' => 'medium',
                'declared_blind_spots' => $headlineBlindSpots,
            ],
        ];
    }

    public function test_modeling_limit_claim_set_completeness_earns_a_concrete_grounded_proposal(): void
    {
        // A MODELING-limit straight from R1: the agreement why-link's real blind_spot
        // ("does NOT prove the claim-SET is complete"). The model could measure claim-set
        // completeness regardless of data, so R3 must PROPOSE a concrete next rung.
        $r1 = $this->r1Envelope('atlas-clean-cap', [
            $this->whyLink(
                'doc and code AGREE (drift=false)',
                AtlasDocumentationRealityCausalSelfModelService::INFERENCE_PROVEN,
                'low',
                'Agreement means the declared claim-set is backed by resolvable refs; it does NOT prove the claim-SET is complete, nor that the resolved code is behaviorally correct (resolution is existence-only).',
            ),
        ]);
        $r2 = $this->r2Envelope();

        $payload = $this->service($r1, $r2)->proposeModelingImprovements();

        $proposal = collect($payload['proposals'])->first(
            static fn (array $p): bool => $p['classification'] === AtlasDocumentationRealitySelfImprovementModelingService::CLASSIFICATION_MODELING_LIMIT
                && str_contains((string) $p['limit_ref'], 'claim-SET is complete'),
        );
        $this->assertNotNull($proposal, 'the claim-set-completeness limit must yield a modeling-limit proposal');

        // It is GROUNDED in the exact declared limit (quotes the real blind_spot).
        $this->assertStringContainsString('does NOT prove the claim-SET is complete', (string) $proposal['limit_ref']);
        // It carries a CONCRETE, measurable proposed next rung.
        $this->assertNotSame('', trim((string) $proposal['proposed_next_rung']));
        $this->assertStringContainsString('completeness', mb_strtolower((string) $proposal['proposed_next_rung']));
        $this->assertNotSame('', trim((string) ($proposal['measurable_signal'] ?? '')));
        // CALIBRATED: a non-empty declared blind_spot + a calibrated confidence.
        $this->assertNotSame('', trim((string) data_get($proposal, 'uncertainty.blind_spot')));
        $this->assertContains(data_get($proposal, 'uncertainty.confidence'), ['high', 'medium', 'low']);
        // PROPOSAL-ONLY: proposes, never applies, never self-modifies.
        $this->assertTrue($proposal['is_proposal']);
        $this->assertFalse($proposal['auto_applied']);
        $this->assertFalse($proposal['would_self_modify']);

        $this->assertGreaterThanOrEqual(1, data_get($payload, 'summary.modeling_limits'));
        $this->assertSame(data_get($payload, 'summary.modeling_limits'), data_get($payload, 'summary.proposals'));
    }

    public function test_data_limit_no_world_signal_is_classified_data_limit_with_null_rung_and_no_build_proposal(): void
    {
        // A DATA-limit straight from R1: the no-outcome-signal why-link's real blind_spot
        // ("Absence of an outcome signal ... never proven world-causation"). It can only
        // be resolved by an EXTERNAL outcome signal that does not exist yet — R3 must NOT
        // propose to manufacture it.
        $r1 = $this->r1Envelope('atlas-built-no-outcome', [
            $this->whyLink(
                'intent=implemented BUT result=no_outcome_signal BECAUSE no real outcome signal is currently linked',
                AtlasDocumentationRealityCausalSelfModelService::INFERENCE_INFERRED,
                'low',
                'Absence of an outcome signal is NOT proof the capability was never used — it may have been used without a logged ai_outcome_links row. This link is an inferred reading of a no-signal state, never proven world-causation.',
            ),
        ]);
        $r2 = $this->r2Envelope([[
            'rung' => 'L2-O1',
            'confidence' => 'medium',
            'blind_spots' => [
                'outcome_grounded = 0 right now: the ladder is internally true (drift-checked) but NOT yet validated in the world.',
            ],
        ]]);

        $payload = $this->service($r1, $r2)->proposeModelingImprovements();

        // The R1 no-signal limit is a data-limit: null rung, awaits external reality, no
        // build proposal.
        $dataLimit = collect($payload['proposals'])->first(
            static fn (array $p): bool => $p['classification'] === AtlasDocumentationRealitySelfImprovementModelingService::CLASSIFICATION_DATA_LIMIT
                && str_contains((string) $p['limit_ref'], 'Absence of an outcome signal'),
        );
        $this->assertNotNull($dataLimit, 'the no-outcome-signal limit must be classified as a data-limit');
        $this->assertNull($dataLimit['proposed_next_rung'], 'a data-limit must carry proposed_next_rung=null — never a build proposal');
        $this->assertSame(
            AtlasDocumentationRealitySelfImprovementModelingService::AWAITS_EXTERNAL_REALITY,
            $dataLimit['awaits'],
        );
        $this->assertFalse($dataLimit['is_proposal'], 'a data-limit is surfaced honestly, never proposed as a fixable rung');
        $this->assertFalse($dataLimit['would_self_modify']);
        // It is still GROUNDED (quotes the real limit) and CALIBRATED (declared blind_spot).
        $this->assertNotSame('', trim((string) $dataLimit['limit_ref']));
        $this->assertNotSame('', trim((string) data_get($dataLimit, 'uncertainty.blind_spot')));

        // The R2 outcome_grounded=0 limit is ALSO a data-limit (no world validation yet).
        $r2DataLimit = collect($payload['proposals'])->first(
            static fn (array $p): bool => $p['classification'] === AtlasDocumentationRealitySelfImprovementModelingService::CLASSIFICATION_DATA_LIMIT
                && str_contains((string) $p['limit_ref'], 'NOT yet validated in the world'),
        );
        $this->assertNotNull($r2DataLimit, 'outcome_grounded=0 / not-yet-validated must be a data-limit');
        $this->assertNull($r2DataLimit['proposed_next_rung']);

        // No data-limit anywhere carries a build proposal.
        foreach ($payload['proposals'] as $p) {
            if ($p['classification'] === AtlasDocumentationRealitySelfImprovementModelingService::CLASSIFICATION_DATA_LIMIT) {
                $this->assertNull($p['proposed_next_rung'], 'NO data-limit may carry a proposed_next_rung');
                $this->assertFalse($p['is_proposal']);
            }
        }

        $this->assertGreaterThanOrEqual(2, data_get($payload, 'summary.data_limits'));
    }

    public function test_outcome_quality_over_time_is_a_data_limit_not_a_modeling_build_proposal(): void
    {
        // "Measuring outcome quality over time" can only be closed by real world-outcome
        // data, so it is a DATA-limit even though the prose names a model verb ("does not
        // yet measure"). The world-signal dependency is DECISIVE in the classifier (not a
        // single branch's prose), so R3 never proposes a build that would need to
        // manufacture the missing outcome data.
        $r2 = $this->r2Envelope([[
            'rung' => 'L2-O1',
            'confidence' => 'medium',
            'blind_spots' => [
                'First increment only: O1 grades whether a real outcome signal LINKS to a capability; it does not yet measure outcome quality over time.',
            ],
        ]]);

        $payload = $this->service($this->r1Envelope('atlas-x', []), $r2)->proposeModelingImprovements();

        $entry = collect($payload['proposals'])->first(
            static fn (array $p): bool => str_contains((string) $p['limit_ref'], 'outcome quality over time'),
        );
        $this->assertNotNull($entry, 'the outcome-quality-over-time limit must be surfaced');
        $this->assertSame(
            AtlasDocumentationRealitySelfImprovementModelingService::CLASSIFICATION_DATA_LIMIT,
            $entry['classification'],
            'outcome quality over time needs real world data — it is a data-limit, never a modeling build proposal',
        );
        $this->assertNull($entry['proposed_next_rung'], 'a data-limit never carries a build proposal');
        $this->assertFalse($entry['is_proposal']);
    }

    public function test_guard_rejects_a_proposal_without_a_grounded_limit_ref_or_blind_spot(): void
    {
        // The service CANNOT emit a proposal without a grounded limit_ref or a declared
        // blind_spot: the private guard throws. Exercise it directly via reflection to
        // prove the supreme drift is unreachable (mirrors R1/R2's guard tests).
        $service = $this->service($this->r1Envelope('x', []), $this->r2Envelope());
        $assert = new \ReflectionMethod($service, 'assertProposalCalibratedAndGrounded');

        // (a) No grounded limit_ref => rejected (an ungrounded proposal is a fabricated limit).
        try {
            $assert->invoke($service, [
                'classification' => AtlasDocumentationRealitySelfImprovementModelingService::CLASSIFICATION_MODELING_LIMIT,
                'capability' => 'cap',
                'proposed_next_rung' => 'do a thing',
                'is_proposal' => true,
                'auto_applied' => false,
                'would_self_modify' => false,
                'uncertainty' => ['confidence' => 'medium', 'blind_spot' => 'a limit'],
            ]);
            $this->fail('a proposal without a grounded limit_ref must be rejected as a fabricated limit');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('limit_ref', $e->getMessage());
        }

        // (b) No declared blind_spot => rejected (the supreme drift — no declared uncertainty).
        try {
            $assert->invoke($service, [
                'classification' => AtlasDocumentationRealitySelfImprovementModelingService::CLASSIFICATION_MODELING_LIMIT,
                'capability' => 'cap',
                'limit_ref' => 'a real declared limit',
                'proposed_next_rung' => 'do a thing',
                'is_proposal' => true,
                'auto_applied' => false,
                'would_self_modify' => false,
                'uncertainty' => ['confidence' => 'medium'],
            ]);
            $this->fail('a proposal without a declared blind_spot must be rejected as the supreme drift');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('blind_spot', $e->getMessage());
        }

        // (c) The well-formed modeling proposal passes — the guard rejects only the drift.
        $assert->invoke($service, [
            'classification' => AtlasDocumentationRealitySelfImprovementModelingService::CLASSIFICATION_MODELING_LIMIT,
            'capability' => 'cap',
            'limit_ref' => 'does NOT prove the claim-SET is complete',
            'proposed_next_rung' => 'add a claim-set completeness measure',
            'is_proposal' => true,
            'auto_applied' => false,
            'would_self_modify' => false,
            'uncertainty' => ['confidence' => 'medium', 'blind_spot' => 'expected set must be defined'],
        ]);
        $this->addToAssertionCount(1);
    }

    public function test_guard_rejects_a_data_limit_that_carries_a_next_rung_never_propose_fabricating_data(): void
    {
        // The deepest guard: a data-limit must NEVER carry a build/next_rung proposal —
        // that would be proposing to manufacture missing external data (fabrication).
        $service = $this->service($this->r1Envelope('x', []), $this->r2Envelope());
        $assert = new \ReflectionMethod($service, 'assertProposalCalibratedAndGrounded');

        try {
            $assert->invoke($service, [
                'classification' => AtlasDocumentationRealitySelfImprovementModelingService::CLASSIFICATION_DATA_LIMIT,
                'capability' => 'cap',
                'limit_ref' => 'outcome_grounded = 0; not yet validated in the world',
                // ILLEGAL: a data-limit with a build proposal.
                'proposed_next_rung' => 'manufacture an outcome signal',
                'is_proposal' => false,
                'would_self_modify' => false,
                'auto_applied' => false,
                'uncertainty' => ['confidence' => 'high', 'blind_spot' => 'awaits world'],
            ]);
            $this->fail('a data-limit carrying a proposed_next_rung must be rejected — R3 never proposes manufacturing data');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('proposed_next_rung', $e->getMessage());
            $this->assertStringContainsString('fabrication', mb_strtolower($e->getMessage()).' '.$e->getMessage());
        }

        // A data-limit marked is_proposal=true is also rejected.
        try {
            $assert->invoke($service, [
                'classification' => AtlasDocumentationRealitySelfImprovementModelingService::CLASSIFICATION_DATA_LIMIT,
                'capability' => 'cap',
                'limit_ref' => 'no real world-outcome signal',
                'proposed_next_rung' => null,
                'is_proposal' => true,
                'would_self_modify' => false,
                'auto_applied' => false,
                'uncertainty' => ['confidence' => 'high', 'blind_spot' => 'awaits world'],
            ]);
            $this->fail('a data-limit marked is_proposal=true must be rejected');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('is_proposal', $e->getMessage());
        }

        // The honest data-limit (null rung, is_proposal=false) passes.
        $assert->invoke($service, [
            'classification' => AtlasDocumentationRealitySelfImprovementModelingService::CLASSIFICATION_DATA_LIMIT,
            'capability' => 'cap',
            'limit_ref' => 'outcome_grounded = 0; not yet validated in the world',
            'proposed_next_rung' => null,
            'is_proposal' => false,
            'would_self_modify' => false,
            'auto_applied' => false,
            'uncertainty' => ['confidence' => 'high', 'blind_spot' => 'awaits world'],
        ]);
        $this->addToAssertionCount(1);
    }

    public function test_claim_policy_self_modifies_false_auto_applies_false_linf_complete_false(): void
    {
        $r1 = $this->r1Envelope('atlas-clean-cap', [
            $this->whyLink(
                'doc and code AGREE',
                AtlasDocumentationRealityCausalSelfModelService::INFERENCE_PROVEN,
                'low',
                'it does NOT prove the claim-SET is complete (resolution is existence-only).',
            ),
        ]);
        $payload = $this->service($r1, $this->r2Envelope())->proposeModelingImprovements();

        $this->assertSame(AtlasDocumentationRealitySelfImprovementModelingService::SCHEMA, $payload['schema_version']);
        $this->assertFalse($payload['linf_complete'], 'linf_complete must be HARD false — the asymptote is never done');
        $this->assertTrue($payload['is_one_fragment_not_asymptote']);
        $this->assertSame('R3_self_improving_modeling', $payload['fragment']);
        $this->assertStringContainsString('one promoted fragment', (string) $payload['level']);

        // The load-bearing policy flags: proposal-only, never self-modifies, never
        // auto-applies, never proposes fabricating data, one fragment not the asymptote.
        $this->assertTrue(data_get($payload, 'claim_policy.read_only'));
        $this->assertTrue(data_get($payload, 'claim_policy.proposal_only'));
        $this->assertFalse(data_get($payload, 'claim_policy.self_modifies'), 'self_modifies MUST be false');
        $this->assertFalse(data_get($payload, 'claim_policy.auto_applies'), 'auto_applies MUST be false');
        $this->assertTrue(data_get($payload, 'claim_policy.never_proposes_fabricating_data'));
        $this->assertTrue(data_get($payload, 'claim_policy.never_fabricates_a_limit'));
        $this->assertTrue(data_get($payload, 'claim_policy.every_proposal_calibrated_and_grounded'));
        $this->assertTrue(data_get($payload, 'claim_policy.distinguishes_data_limit_from_modeling_limit'));
        $this->assertFalse(data_get($payload, 'claim_policy.linf_complete'));
        $this->assertTrue(data_get($payload, 'claim_policy.is_one_linf_fragment_not_the_asymptote'));

        // Read-only end to end.
        $this->assertFalse($payload['writes']);
        $this->assertFalse(data_get($payload, 'claim_policy.writes'));
        $this->assertFalse(data_get($payload, 'claim_policy.executes'));
        $this->assertFalse(data_get($payload, 'claim_policy.mutates'));
        $this->assertIsString($payload['self_improvement_modeling_hash']);
    }

    public function test_composed_signal_failure_degrades_with_calibrated_note_never_a_fabricated_limit(): void
    {
        // When R1 cannot be read (throws), R3 must NOT fabricate a limit: it degrades to
        // available=false with a calibrated note and zero proposals.
        $payload = $this->service($this->r1Envelope('x', []), $this->r2Envelope(), r1Throws: true)
            ->proposeModelingImprovements();

        $this->assertFalse($payload['available'], 'a composed-fragment failure must degrade, not fabricate');
        $this->assertSame('r1_causal_self_model_unavailable', $payload['degraded_reason']);
        $this->assertSame([], $payload['proposals'], 'a degraded R3 emits NO proposals — no invented limit');
        $this->assertSame(0, data_get($payload, 'summary.modeling_limits'));
        $this->assertSame(0, data_get($payload, 'summary.data_limits'));

        // The degraded note itself honours the contract: it carries a declared blind_spot.
        $this->assertNotSame('', trim((string) data_get($payload, 'note.uncertainty.blind_spot', '')));
        $this->assertFalse(data_get($payload, 'note.is_proposal'), 'the degraded note is not a proposal');
        $this->assertFalse($payload['linf_complete']);

        // Symmetrically, an R2 failure also degrades (never fabricates).
        $payload2 = $this->service($this->r1Envelope('x', []), $this->r2Envelope(), r2Throws: true)
            ->proposeModelingImprovements();
        $this->assertFalse($payload2['available']);
        $this->assertSame('r2_reflective_status_unavailable', $payload2['degraded_reason']);
        $this->assertSame([], $payload2['proposals']);
    }
}
