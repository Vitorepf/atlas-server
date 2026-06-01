<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;
use App\Services\Engineering\AtlasDocumentationRealityBidirectionalReconciliationService;
use App\Services\Engineering\AtlasDocumentationRealityCausalSelfModelService;
use App\Services\Engineering\AtlasDocumentationRealityOutcomeGroundingService;
use App\Services\Engineering\AtlasDocumentationRealityReflectiveStatusService;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * L-inf (ONE promoted fragment: R1 CAUSAL SELF-MODEL) — the queryable causal account
 * (intent -> truth -> result -> why). The four composed signals (capability truth
 * ledger, L2-O1 grades, bidirectional reconciliation, R2 reflective status) are
 * MOCKED for determinism; no RefreshDatabase — the service is pure composition over
 * those reports and touches no schema of its own.
 *
 * The cardinal rule under test (the SUPREME drift, reflective-self-model.md:169): a
 * causal claim WITHOUT calibrated uncertainty is structurally impossible. Every
 * why-link carries a real `basis`, an `inference` label (proven|inferred), a
 * calibrated confidence AND a non-empty uncertainty.blind_spot; linf_complete is HARD
 * false. An inferred correlation is never asserted as proven causation; no cause is
 * ever fabricated. Read-only end to end.
 */
final class AtlasDocumentationRealityCausalSelfModelTest extends TestCase
{
    /**
     * Build the service with the four composed collaborators mocked. The ledger is the
     * primary signal (claimed/computed/drift/under_claim + per-ref evidence); O1 grades
     * supply result; reconciliation supplies the directional why; R2 supplies
     * calibration. Each mock is deterministic so the causal chain is fully predictable.
     *
     * @param  array<int,array<string,mixed>>  $ledgerRows
     * @param  array<int,array<string,mixed>>  $grades
     * @param  array<string,mixed>  $reconcile
     */
    private function service(
        array $ledgerRows,
        array $grades = [],
        bool $outcomeSourceAvailable = false,
        array $reconcile = [],
        string $r2HeadlineConfidence = 'medium',
        bool $r2Throws = false,
    ): AtlasDocumentationRealityCausalSelfModelService {
        $this->mock(AtlasAaeosImplementationTruthService::class, function (MockInterface $mock) use ($ledgerRows): void {
            $mock->shouldReceive('ledger')->andReturn([
                'schema_version' => AtlasAaeosImplementationTruthService::LEDGER_SCHEMA,
                'summary' => ['evaluated' => count($ledgerRows)],
                'capabilities' => $ledgerRows,
            ]);
        });

        $this->mock(AtlasDocumentationRealityOutcomeGroundingService::class, function (MockInterface $mock) use ($grades, $outcomeSourceAvailable): void {
            $graded = [
                'summary' => ['outcome_signal_source_available' => $outcomeSourceAvailable],
                'grades' => $grades,
            ];
            $mock->shouldReceive('gradeAll')->andReturn($graded);
            $mock->shouldReceive('gradeForDoc')->andReturn($graded);
        });

        $this->mock(AtlasDocumentationRealityBidirectionalReconciliationService::class, function (MockInterface $mock) use ($reconcile): void {
            $packet = $reconcile === []
                ? ['degraded' => false, 'under_claim_upgrades' => [], 'over_claim_repairs' => []]
                : $reconcile;
            $mock->shouldReceive('reconcileAll')->andReturn($packet);
            $mock->shouldReceive('reconcileForDoc')->andReturn($packet);
        });

        $this->mock(AtlasDocumentationRealityReflectiveStatusService::class, function (MockInterface $mock) use ($r2HeadlineConfidence, $r2Throws): void {
            if ($r2Throws) {
                $mock->shouldReceive('selfAssessment')->andThrow(new \RuntimeException('R2 unavailable'));
            } else {
                $mock->shouldReceive('selfAssessment')->andReturn([
                    'headline' => ['confidence' => $r2HeadlineConfidence],
                ]);
            }
        });

        return app(AtlasDocumentationRealityCausalSelfModelService::class);
    }

    /**
     * A ledger row in the shape AtlasAaeosImplementationTruthService::ledger emits.
     *
     * @param  array<int,array{kind:string,ref:string,resolved:bool}>  $evidence
     * @return array<string,mixed>
     */
    private function ledgerRow(
        string $id,
        string $claimed,
        string $computed,
        bool $drift,
        bool $underClaim,
        array $evidence,
        array $resolved = [],
        array $unmet = [],
    ): array {
        return [
            'capability_id' => $id,
            'owner_doc' => "docs/engineering-knowledge-base/{$id}.md",
            'claimed_state' => $claimed,
            'claimed_state_raw' => $claimed,
            'computed_state' => $computed,
            'drift' => $drift,
            'under_claim' => $underClaim,
            'resolved' => $resolved,
            'unmet_evidence' => $unmet,
            'evidence' => $evidence,
        ];
    }

    /**
     * Assert the supreme-drift invariant on a whole chain: EVERY why-link carries a
     * basis, an inference label, a calibrated confidence and a non-empty blind_spot.
     *
     * @param  array<string,mixed>  $chain
     */
    private function assertEveryWhyLinkCalibrated(array $chain): void
    {
        $this->assertNotEmpty($chain['why'], 'a causal chain must carry at least one why-link');
        foreach ($chain['why'] as $link) {
            $this->assertNotSame('', trim((string) ($link['basis'] ?? '')), 'every why-link must name a real grounding basis');
            $this->assertContains(
                $link['inference'] ?? null,
                [
                    AtlasDocumentationRealityCausalSelfModelService::INFERENCE_PROVEN,
                    AtlasDocumentationRealityCausalSelfModelService::INFERENCE_INFERRED,
                ],
                'every why-link must label inference as proven|inferred',
            );
            $this->assertContains(
                data_get($link, 'uncertainty.confidence'),
                [
                    AtlasDocumentationRealityCausalSelfModelService::CONFIDENCE_HIGH,
                    AtlasDocumentationRealityCausalSelfModelService::CONFIDENCE_MEDIUM,
                    AtlasDocumentationRealityCausalSelfModelService::CONFIDENCE_LOW,
                ],
                'every why-link must carry a calibrated confidence',
            );
            $this->assertNotSame('', trim((string) data_get($link, 'uncertainty.blind_spot', '')), 'every why-link must declare a non-empty blind_spot');
        }
    }

    public function test_drift_false_capability_chain_intent_equals_truth_grounded_in_all_refs_resolve(): void
    {
        // A clean, drift-false capability: claimed == computed, all declared refs resolve.
        $row = $this->ledgerRow(
            id: 'atlas-clean-cap',
            claimed: 'partial',
            computed: 'partial',
            drift: false,
            underClaim: false,
            evidence: [
                ['kind' => 'symbol', 'ref' => 'CleanService', 'resolved' => true],
                ['kind' => 'command', 'ref' => 'atlas:clean', 'resolved' => true],
            ],
            resolved: ['symbol' => true, 'wiring' => true, 'test' => false, 'receipt' => false],
        );

        $payload = $this->service([$row])->explainCapability('atlas-clean-cap');
        $chain = $payload['causal_chains'][0];

        // intent == truth.
        $this->assertSame('partial', data_get($chain, 'intent.claimed_state'));
        $this->assertSame('partial', data_get($chain, 'truth.computed_state'));

        // The agreement why-link is grounded in "all refs resolve" and is PROVEN.
        $agree = collect($chain['why'])->first(
            static fn (array $l): bool => str_contains((string) $l['statement'], 'AGREE'),
        );
        $this->assertNotNull($agree, 'a drift-false chain must carry an agreement why-link');
        $this->assertStringContainsString('all declared evidence_refs resolve', (string) $agree['statement']);
        $this->assertStringContainsString('drift=false', (string) $agree['basis']);
        $this->assertSame(AtlasDocumentationRealityCausalSelfModelService::INFERENCE_PROVEN, $agree['inference']);

        $this->assertEveryWhyLinkCalibrated($chain);
    }

    public function test_under_claim_partial_capability_why_cites_unresolved_signal_and_labels_inference(): void
    {
        // Under-claim: code resolves MORE than the doc claims (claimed spec, computed
        // partial). One declared ref is still unresolved — the why must cite it. The
        // reconciliation proposes a doc upgrade on existence-only proof (inferred).
        $row = $this->ledgerRow(
            id: 'atlas-underclaim-cap',
            claimed: 'spec',
            computed: 'partial',
            drift: false,
            underClaim: true,
            evidence: [
                ['kind' => 'symbol', 'ref' => 'UnderService', 'resolved' => true],
                ['kind' => 'command', 'ref' => 'atlas:under', 'resolved' => true],
                ['kind' => 'test', 'ref' => 'UnderServiceTest', 'resolved' => false],
            ],
            resolved: ['symbol' => true, 'wiring' => true, 'test' => false, 'receipt' => false],
        );

        $reconcile = [
            'degraded' => false,
            'over_claim_repairs' => [],
            'under_claim_upgrades' => [[
                'capability_id' => 'atlas-underclaim-cap',
                'owner_doc' => 'docs/engineering-knowledge-base/atlas-underclaim-cap.md',
                'claimed_state' => 'spec',
                'computed_state' => 'partial',
                'requires_human_confirmation' => true,
            ]],
        ];

        $payload = $this->service([$row], reconcile: $reconcile)->explainCapability('atlas-underclaim-cap');
        $chain = $payload['causal_chains'][0];

        // The under-claim why-link names the direction and is grounded in under_claim.
        $under = collect($chain['why'])->first(
            static fn (array $l): bool => str_contains((string) $l['statement'], 'UNDER-claim'),
        );
        $this->assertNotNull($under, 'an under-claim chain must carry an under-claim why-link');
        $this->assertStringContainsString('under_claim', (string) $under['basis']);

        // The reconciliation link cites the unresolved/under-claim signal and, because
        // it rests on existence-only proof, is LABELLED inferred (not proven causation).
        $reconcileLink = collect($chain['why'])->first(
            static fn (array $l): bool => str_contains((string) $l['statement'], 'reconciliation'),
        );
        $this->assertNotNull($reconcileLink, 'an under-claim chain must carry the reconciliation why-link');
        $this->assertSame(AtlasDocumentationRealityCausalSelfModelService::INFERENCE_INFERRED, $reconcileLink['inference']);
        $this->assertStringContainsString('BidirectionalReconciliationService', (string) $reconcileLink['basis']);

        // The unresolved declared ref is surfaced in truth.unmet/unresolved path.
        $this->assertSame('partial', data_get($chain, 'truth.computed_state'));
        $this->assertEveryWhyLinkCalibrated($chain);
    }

    public function test_built_but_no_outcome_capability_explains_intent_vs_result_as_no_world_signal_not_failure(): void
    {
        // Implemented (partial, drift false) but NO outcome signal. The intent-vs-result
        // gap must be explained as "built but not yet validated in the world", NOT as a
        // failure, and that no-signal reading must be LABELLED inferred.
        $row = $this->ledgerRow(
            id: 'atlas-built-no-outcome',
            claimed: 'partial',
            computed: 'partial',
            drift: false,
            underClaim: false,
            evidence: [
                ['kind' => 'symbol', 'ref' => 'BuiltService', 'resolved' => true],
                ['kind' => 'command', 'ref' => 'atlas:built', 'resolved' => true],
            ],
            resolved: ['symbol' => true, 'wiring' => true, 'test' => false, 'receipt' => false],
        );

        $grades = [[
            'capability_id' => 'atlas-built-no-outcome',
            'owner_doc' => 'docs/engineering-knowledge-base/atlas-built-no-outcome.md',
            'grade' => AtlasDocumentationRealityOutcomeGroundingService::GRADE_NO_SIGNAL,
            'outcome_grounded' => false,
        ]];

        $payload = $this->service([$row], grades: $grades, outcomeSourceAvailable: true)
            ->explainCapability('atlas-built-no-outcome');
        $chain = $payload['causal_chains'][0];

        // result is no-signal, NOT grounded.
        $this->assertFalse(data_get($chain, 'result.outcome_grounded'));
        $this->assertSame(AtlasDocumentationRealityOutcomeGroundingService::GRADE_NO_SIGNAL, data_get($chain, 'result.grade'));

        // The world-result why-link frames it as built-but-not-validated, NOT a failure,
        // and is LABELLED inferred (absence of signal is not proven causation).
        $resultLink = collect($chain['why'])->first(
            static fn (array $l): bool => str_contains((string) $l['statement'], 'no_outcome_signal'),
        );
        $this->assertNotNull($resultLink, 'a built-but-no-outcome chain must carry the world-result why-link');
        $this->assertStringContainsString('NOT a failure', (string) $resultLink['statement']);
        $this->assertStringContainsStringIgnoringCase('not yet validated in the world', (string) $resultLink['statement']);
        $this->assertSame(AtlasDocumentationRealityCausalSelfModelService::INFERENCE_INFERRED, $resultLink['inference']);

        // The word "failure" must only appear negated — never assert the capability failed.
        $this->assertStringNotContainsString('result=failure', (string) $resultLink['statement']);

        $this->assertEveryWhyLinkCalibrated($chain);
    }

    public function test_supreme_drift_guard_rejects_a_causal_claim_without_blind_spot_or_basis(): void
    {
        // The service CANNOT emit a causal claim without calibrated uncertainty: the
        // private guard throws. Exercise it directly via reflection to prove the supreme
        // drift is unreachable by construction (mirrors R2's guard test).
        $service = $this->service([]);
        $assert = new \ReflectionMethod($service, 'assertCausalClaimCalibrated');

        // (a) No basis => rejected (a cause without a real signal is fabrication).
        try {
            $assert->invoke($service, [
                'statement' => 'X because Y',
                'inference' => 'proven',
                'uncertainty' => ['confidence' => 'high', 'blind_spot' => 'a limit'],
            ], 'cap');
            $this->fail('a causal claim without a basis must be rejected as fabrication');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('basis', $e->getMessage());
        }

        // (b) No inference label => rejected (unlabelled correlation as causation).
        try {
            $assert->invoke($service, [
                'statement' => 'X because Y',
                'basis' => 'the ledger',
                'uncertainty' => ['confidence' => 'high', 'blind_spot' => 'a limit'],
            ], 'cap');
            $this->fail('a causal claim without an inference label must be rejected');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('inference', $e->getMessage());
        }

        // (c) No calibrated confidence => rejected.
        try {
            $assert->invoke($service, [
                'statement' => 'X because Y',
                'basis' => 'the ledger',
                'inference' => 'proven',
                'uncertainty' => ['blind_spot' => 'a limit'],
            ], 'cap');
            $this->fail('a causal claim without calibrated confidence must be rejected');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('calibrated', $e->getMessage());
        }

        // (d) No declared blind_spot => rejected (the supreme drift — no declared limit).
        try {
            $assert->invoke($service, [
                'statement' => 'X because Y',
                'basis' => 'the ledger',
                'inference' => 'proven',
                'uncertainty' => ['confidence' => 'high'],
            ], 'cap');
            $this->fail('a causal claim without a declared blind_spot must be rejected as the supreme drift');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('blind_spot', $e->getMessage());
        }

        // (e) The truly well-formed link passes — the guard rejects only the drift.
        $assert->invoke($service, [
            'statement' => 'computed=partial BECAUSE refs resolve',
            'basis' => 'capability_truth_ledger.drift=false',
            'inference' => 'proven',
            'uncertainty' => ['confidence' => 'high', 'blind_spot' => 'existence-only resolution'],
        ], 'cap');
        $this->addToAssertionCount(1);
    }

    public function test_claim_policy_linf_complete_false_and_every_causal_claim_calibrated_true(): void
    {
        $row = $this->ledgerRow(
            id: 'atlas-policy-cap',
            claimed: 'partial',
            computed: 'partial',
            drift: false,
            underClaim: false,
            evidence: [
                ['kind' => 'symbol', 'ref' => 'PolicyService', 'resolved' => true],
                ['kind' => 'command', 'ref' => 'atlas:policy', 'resolved' => true],
            ],
            resolved: ['symbol' => true, 'wiring' => true, 'test' => false, 'receipt' => false],
        );

        $payload = $this->service([$row])->explainCapability('atlas-policy-cap');

        $this->assertSame(AtlasDocumentationRealityCausalSelfModelService::SCHEMA, $payload['schema_version']);
        $this->assertFalse($payload['linf_complete'], 'linf_complete must be HARD false — the asymptote is never done');
        $this->assertTrue($payload['is_one_fragment_not_asymptote']);
        $this->assertSame('R1_causal_self_model', $payload['fragment']);
        $this->assertStringContainsString('one promoted fragment', (string) $payload['level']);

        $this->assertTrue(data_get($payload, 'claim_policy.linf_fragment'));
        $this->assertFalse(data_get($payload, 'claim_policy.linf_complete'));
        $this->assertTrue(data_get($payload, 'claim_policy.every_causal_claim_calibrated'));
        $this->assertTrue(data_get($payload, 'claim_policy.distinguishes_inference_from_proof'));
        $this->assertTrue(data_get($payload, 'claim_policy.never_fabricates_a_cause'));
        $this->assertTrue(data_get($payload, 'claim_policy.is_one_linf_fragment_not_the_asymptote'));

        // Read-only end to end.
        $this->assertFalse($payload['writes']);
        $this->assertFalse(data_get($payload, 'claim_policy.writes'));
        $this->assertFalse(data_get($payload, 'claim_policy.executes'));
        $this->assertFalse(data_get($payload, 'claim_policy.mutates'));
        $this->assertIsString($payload['causal_self_model_hash']);

        // The chain-level flags echo the same hard guarantees.
        $chain = $payload['causal_chains'][0];
        $this->assertTrue($chain['linf_fragment']);
        $this->assertFalse($chain['linf_complete']);
        $this->assertSame('R1_causal_self_model', $chain['fragment']);
    }

    public function test_composed_signal_failure_degrades_with_calibrated_note_never_a_fabricated_cause(): void
    {
        // When the capability truth ledger cannot be read (throws), the model must NOT
        // fabricate a cause: it degrades to available=false with a calibrated note.
        $this->mock(AtlasAaeosImplementationTruthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('ledger')->andThrow(new \RuntimeException('index unavailable'));
        });
        $this->mock(AtlasDocumentationRealityOutcomeGroundingService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('gradeForDoc')->andReturn(['summary' => [], 'grades' => []]);
            $mock->shouldReceive('gradeAll')->andReturn(['summary' => [], 'grades' => []]);
        });
        $this->mock(AtlasDocumentationRealityBidirectionalReconciliationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reconcileForDoc')->andReturn(['degraded' => true]);
            $mock->shouldReceive('reconcileAll')->andReturn(['degraded' => true]);
        });
        $this->mock(AtlasDocumentationRealityReflectiveStatusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('selfAssessment')->andReturn(['headline' => ['confidence' => 'medium']]);
        });

        $payload = app(AtlasDocumentationRealityCausalSelfModelService::class)->explainCapability('atlas-anything');

        $this->assertFalse($payload['available'], 'a composed-signal failure must degrade, not fabricate');
        $this->assertSame('capability_truth_ledger_unavailable', $payload['degraded_reason']);
        $this->assertSame([], $payload['causal_chains'], 'a degraded model emits NO causal chains — no invented cause');

        // The degraded note itself honours the invariant: it carries a basis, an
        // inference label and a declared blind_spot — never a bare/fabricated claim.
        $this->assertNotSame('', trim((string) data_get($payload, 'note.basis', '')));
        $this->assertContains(
            data_get($payload, 'note.inference'),
            [
                AtlasDocumentationRealityCausalSelfModelService::INFERENCE_PROVEN,
                AtlasDocumentationRealityCausalSelfModelService::INFERENCE_INFERRED,
            ],
        );
        $this->assertNotSame('', trim((string) data_get($payload, 'note.uncertainty.blind_spot', '')));
        $this->assertFalse($payload['linf_complete']);
    }

    public function test_over_claim_drift_capability_why_cites_unresolved_ref_as_proven_gap(): void
    {
        // Over-claim drift: doc claims verified, code only resolves partial because the
        // declared test/receipt refs do not resolve. The why must cite the unresolved
        // ref and label the doc<->index gap PROVEN (a direct read of the resolver).
        $row = $this->ledgerRow(
            id: 'atlas-overclaim-cap',
            claimed: 'verified',
            computed: 'partial',
            drift: true,
            underClaim: false,
            evidence: [
                ['kind' => 'symbol', 'ref' => 'OverService', 'resolved' => true],
                ['kind' => 'command', 'ref' => 'atlas:over', 'resolved' => true],
                ['kind' => 'test', 'ref' => 'OverServiceTest', 'resolved' => false],
                ['kind' => 'receipt', 'ref' => 'storage/receipts/over.json', 'resolved' => false],
            ],
            resolved: ['symbol' => true, 'wiring' => true, 'test' => false, 'receipt' => false],
            unmet: ['needs >=1 resolved test for verified', 'needs >=1 resolved receipt (evidence file) for verified'],
        );

        $payload = $this->service([$row])->explainCapability('atlas-overclaim-cap');
        $chain = $payload['causal_chains'][0];

        $driftLink = collect($chain['why'])->first(
            static fn (array $l): bool => str_contains((string) $l['statement'], 'OVER-claim drift'),
        );
        $this->assertNotNull($driftLink, 'an over-claim chain must carry the drift why-link');
        // It names the unresolved refs and the unmet evidence.
        $this->assertStringContainsString('OverServiceTest', (string) $driftLink['statement']);
        $this->assertStringContainsString('do not resolve', (string) $driftLink['statement']);
        // The doc<->index gap is PROVEN, but its blind_spot disclaims behavioral proof.
        $this->assertSame(AtlasDocumentationRealityCausalSelfModelService::INFERENCE_PROVEN, $driftLink['inference']);
        $this->assertStringContainsString('not a proven behavioral defect', (string) data_get($driftLink, 'uncertainty.blind_spot'));

        $this->assertEveryWhyLinkCalibrated($chain);
    }
}
