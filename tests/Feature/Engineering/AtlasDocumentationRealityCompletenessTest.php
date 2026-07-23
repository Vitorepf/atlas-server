<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasImplementationTruthService;
use App\Services\Engineering\AtlasDocumentationRealityCompletenessService;
use App\Services\Engineering\AtlasDocumentationRealityOutcomeGroundingService;
use App\Services\Engineering\AtlasDocumentationRealityReflectiveStatusService;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * ADRS RUNTIME COMPLETENESS — the disambiguated, honest "is the ladder complete?".
 *
 * The composed collaborators (AAEOS truth ledger / R2 reflective self-status / O1
 * grading) are MOCKED for determinism; no RefreshDatabase — the service is pure
 * composition over those read-only reports plus class/command/test reflection over
 * the REAL shipped artifacts (which exist in this repo).
 *
 * The cardinal rules under test (the anti-goalpost invariants):
 *   (1) all mechanisms resolve => runtime_completeness MET, with asymptote_complete
 *       false + reality_dependent reported SEPARATELY;
 *   (2) a mechanism that does NOT resolve (owner doc in drift) => runtime_completeness
 *       NOT met — honest, never faked;
 *   (3) the verdict NEVER sets asymptote_complete true and NEVER folds grounded into
 *       the completeness count;
 *   (4) the guard THROWS if asked to claim completeness while a mechanism is
 *       unresolved (goalpost-moving is unreachable by construction).
 */
final class AtlasDocumentationRealityCompletenessTest extends TestCase
{
    /**
     * The REAL owner docs every mechanism is drift-checked against. There are NO
     * drift-exemptions: each mechanism names a canonical doc whose evidence_refs
     * symbol IS its service, so the mock ledger must cover ALL of them or a
     * mechanism becomes "drift unknown" => not hardened. Derived from the live
     * registry, this list also documents the honest owner-doc wiring under test.
     *
     * @return list<string>
     */
    private function ownerDocs(): array
    {
        return [
            'atlas-documentation-reality-write-bound-enforcement',
            'atlas-documentation-reality-system',
            'atlas-documentation-reality-anticipatory-reality',
            'atlas-documentation-reality-bidirectional-reconciliation',
            'atlas-documentation-reality-generative-self-healing',
            'atlas-documentation-reality-code-contract-proposals',
            'atlas-documentation-reality-self-immunizing-antibody',
            'atlas-documentation-reality-outcome-grounded-truth',
            'atlas-documentation-reality-intent-coformation',
            'atlas-documentation-reality-multi-estate-compounding',
            'atlas-documentation-reality-causal-self-model-fragment',
            'atlas-documentation-reality-reflective-status-fragment',
            'atlas-documentation-reality-self-improvement-modeling-fragment',
            'atlas-documentation-reality-flow',
            'atlas-ai-session-bootstrap',
        ];
    }

    /**
     * Mock the three composed collaborators with a deterministic, healthy reading:
     * the truth ledger reports every owner-doc-bearing mechanism drift=false; R2
     * reports linf_complete=false (the asymptote, hard false); O1 reports the honest
     * live state outcome_grounded=0. The service composes these — it never recomputes
     * them — and reflects over the REAL class/command/test artifacts shipped in repo.
     *
     * @param  list<string>  $driftDocs  owner docs to mark as IN DRIFT (default: none)
     */
    private function service(array $driftDocs = [], bool $linfComplete = false, int $grounded = 0): AtlasDocumentationRealityCompletenessService
    {
        $this->mock(AtlasImplementationTruthService::class, function (MockInterface $mock) use ($driftDocs): void {
            $mock->shouldReceive('ledger')->andReturn($this->ledger($driftDocs));
        });
        $this->mock(AtlasDocumentationRealityReflectiveStatusService::class, function (MockInterface $mock) use ($linfComplete): void {
            $mock->shouldReceive('selfAssessment')->andReturn([
                'linf_complete' => $linfComplete,
                'headline' => [
                    'question' => 'Is the ADRS doc<->runtime 10/10?',
                    'assessment' => 'On the measure I HAVE I am at 9.5/10, but "10/10" is NOT something I can claim about myself.',
                ],
            ]);
        });
        $this->mock(AtlasDocumentationRealityOutcomeGroundingService::class, function (MockInterface $mock) use ($grounded): void {
            $mock->shouldReceive('gradeAll')->andReturn([
                'summary' => ['outcome_grounded_count' => $grounded, 'outcome_signal_source_available' => true],
            ]);
        });

        return app(AtlasDocumentationRealityCompletenessService::class);
    }

    /**
     * A ledger whose capabilities cover EVERY owner_doc the mechanism registry names
     * (all 15 — none are exempt), each drift=false unless listed in $driftDocs. Keyed
     * by capability_id; the service also keys by basename so the id form is what
     * matters here.
     *
     * @param  list<string>  $driftDocs
     * @return array<string,mixed>
     */
    private function ledger(array $driftDocs): array
    {
        $caps = [];
        foreach ($this->ownerDocs() as $id) {
            $caps[] = [
                'capability_id' => $id,
                'owner_doc' => 'docs/engineering-knowledge-base/'.$id.'.md',
                'drift' => in_array($id, $driftDocs, true),
            ];
        }

        return ['schema_version' => 'atlas.aaeos.capability_truth_ledger.v1', 'capabilities' => $caps];
    }

    public function test_all_mechanisms_resolve_runtime_completeness_met_with_axes_separate(): void
    {
        $payload = $this->service()->assess();

        $this->assertSame(AtlasDocumentationRealityCompletenessService::SCHEMA, $payload['schema_version']);

        // (a) runtime_completeness MET — every buildable mechanism built + hardened.
        $rc = $payload['runtime_completeness'];
        $this->assertTrue($rc['runtime_complete'], 'all mechanisms resolve => runtime_completeness must be MET');
        $this->assertSame($rc['total_mechanisms'], $rc['built_count'], 'every mechanism built');
        $this->assertSame($rc['total_mechanisms'], $rc['hardened_count'], 'every mechanism hardened');
        $this->assertSame([], $rc['unresolved']);
        $this->assertNotEmpty($rc['mechanisms']);

        // Each mechanism carries its per-mechanism evidence.
        foreach ($rc['mechanisms'] as $m) {
            $this->assertArrayHasKey('class_exists', $m);
            $this->assertArrayHasKey('command_registered', $m);
            $this->assertArrayHasKey('test_exists', $m);
            $this->assertTrue($m['built'], "mechanism {$m['key']} must be built");
            $this->assertTrue($m['hardened'], "mechanism {$m['key']} must be hardened");
        }

        // (b) asymptote reported SEPARATELY and false.
        $this->assertFalse($payload['asymptote']['asymptote_complete'], 'asymptote_complete must be false even when runtime is complete');
        $this->assertFalse($payload['asymptote']['counts_toward_runtime_completeness']);
        $this->assertTrue($payload['asymptote']['is_permanent_compass']);

        // (c) reality_dependent reported SEPARATELY and not counted.
        $this->assertSame(0, $payload['reality_dependent']['outcome_grounded_count']);
        $this->assertFalse($payload['reality_dependent']['counts_toward_runtime_completeness']);

        // Verdict honours the achievable 10/10 without claiming the asymptote.
        $this->assertTrue($payload['verdict']['runtime_complete']);
        $this->assertStringContainsStringIgnoringCase('runtime completeness met', (string) $payload['verdict']['statement']);
        $this->assertIsString($payload['completeness_hash']);
    }

    public function test_a_mechanism_that_does_not_resolve_means_runtime_completeness_not_met(): void
    {
        // Mark a real owner-doc mechanism IN DRIFT: it is built but NOT hardened, so
        // runtime_completeness must honestly report NOT met. Never faked.
        $payload = $this->service(driftDocs: ['atlas-documentation-reality-outcome-grounded-truth'])->assess();

        $rc = $payload['runtime_completeness'];
        $this->assertFalse($rc['runtime_complete'], 'an unresolved mechanism => runtime_completeness NOT met');
        $this->assertContains('l2_o1_outcome_grounding', $rc['unresolved']);
        $this->assertLessThan($rc['total_mechanisms'], $rc['hardened_count'], 'the drifting mechanism lowers hardened_count');

        $drifting = collect($rc['mechanisms'])->firstWhere('key', 'l2_o1_outcome_grounding');
        $this->assertNotNull($drifting);
        $this->assertTrue($drifting['built'], 'the mechanism is still built (class+command+test resolve)');
        $this->assertFalse($drifting['hardened'], 'but it is NOT hardened because its owner doc is in drift');
        $this->assertTrue($drifting['drift']);
        $this->assertNotEmpty($drifting['unresolved_reasons']);

        // The verdict honestly says NOT met rather than redefine "complete".
        $this->assertFalse($payload['verdict']['runtime_complete']);
        $this->assertStringContainsStringIgnoringCase('not met', (string) $payload['verdict']['statement']);
    }

    public function test_verdict_never_claims_asymptote_and_never_folds_grounded_into_completeness(): void
    {
        // Even with a non-zero grounded count, completeness math must ignore it and
        // the asymptote must stay false in the verdict.
        $payload = $this->service(grounded: 7)->assess();

        $verdict = $payload['verdict'];
        $this->assertFalse($verdict['asymptote_complete'], 'the verdict must NEVER set asymptote_complete true');
        $this->assertFalse($verdict['claims_asymptote']);
        $this->assertTrue($verdict['asymptote_is_permanent_compass']);
        $this->assertFalse($verdict['folds_grounded_into_completeness'], 'the verdict must NEVER fold grounded into completeness');
        $this->assertFalse($verdict['outcome_grounded_counts_toward_completeness']);
        $this->assertSame(7, $verdict['outcome_grounded_count'], 'grounded is echoed for the reader, not counted');

        // grounded=7 must not have made runtime_complete depend on it; it is still
        // true purely from the buildable mechanisms.
        $this->assertTrue($verdict['runtime_complete']);

        // The claim policy asserts the separation in the envelope itself.
        $policy = $payload['claim_policy'];
        $this->assertTrue($policy['three_axes_kept_separate']);
        $this->assertTrue($policy['asymptote_complete_is_false_forever']);
        $this->assertTrue($policy['outcome_grounded_never_counts_toward_completeness']);
        $this->assertTrue($policy['never_redefines_ten_out_of_ten_as_whatever_is_built']);
        $this->assertFalse($payload['writes']);
    }

    public function test_guard_throws_if_asked_to_claim_completeness_while_a_mechanism_is_unresolved(): void
    {
        // The honesty guard CANNOT let a dishonest envelope leave the service. We
        // exercise it directly via reflection to prove goalpost-moving is unreachable
        // by construction (mirrors the R2 supreme-drift guard test).
        $service = $this->service();
        $guard = new \ReflectionMethod($service, 'assertHonestVerdict');

        // (a) runtime_complete=true with an unresolved mechanism => rejected.
        try {
            $guard->invoke($service, [
                'runtime_completeness' => ['unresolved' => ['l2_o1_outcome_grounding'], 'built_count' => 14, 'hardened_count' => 14, 'total_mechanisms' => 15],
                'asymptote' => ['asymptote_complete' => false, 'counts_toward_runtime_completeness' => false],
                'reality_dependent' => ['counts_toward_runtime_completeness' => false],
                'verdict' => ['runtime_complete' => true, 'asymptote_complete' => false, 'claims_asymptote' => false, 'folds_grounded_into_completeness' => false, 'outcome_grounded_counts_toward_completeness' => false],
            ]);
            $this->fail('claiming completeness with an unresolved mechanism must throw');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('unresolved', $e->getMessage());
        }

        // (b) the verdict claiming the asymptote => rejected.
        try {
            $guard->invoke($service, [
                'runtime_completeness' => ['unresolved' => [], 'built_count' => 15, 'hardened_count' => 15, 'total_mechanisms' => 15],
                'asymptote' => ['asymptote_complete' => false, 'counts_toward_runtime_completeness' => false],
                'reality_dependent' => ['counts_toward_runtime_completeness' => false],
                'verdict' => ['runtime_complete' => true, 'asymptote_complete' => true, 'claims_asymptote' => true, 'folds_grounded_into_completeness' => false, 'outcome_grounded_counts_toward_completeness' => false],
            ]);
            $this->fail('a verdict that claims the asymptote must throw');
        } catch (\LogicException $e) {
            $this->assertStringContainsStringIgnoringCase('asymptote', $e->getMessage());
        }

        // (c) folding grounded into completeness => rejected.
        try {
            $guard->invoke($service, [
                'runtime_completeness' => ['unresolved' => [], 'built_count' => 15, 'hardened_count' => 15, 'total_mechanisms' => 15],
                'asymptote' => ['asymptote_complete' => false, 'counts_toward_runtime_completeness' => false],
                'reality_dependent' => ['counts_toward_runtime_completeness' => true],
                'verdict' => ['runtime_complete' => true, 'asymptote_complete' => false, 'claims_asymptote' => false, 'folds_grounded_into_completeness' => true, 'outcome_grounded_counts_toward_completeness' => true],
            ]);
            $this->fail('folding grounded into completeness must throw');
        } catch (\LogicException $e) {
            $this->assertStringContainsStringIgnoringCase('grounded', $e->getMessage());
        }

        // (d) the well-formed honest envelope passes — the guard rejects only the
        //     dishonest moves, not honesty itself.
        $guard->invoke($service, [
            'runtime_completeness' => ['unresolved' => [], 'built_count' => 15, 'hardened_count' => 15, 'total_mechanisms' => 15],
            'asymptote' => ['asymptote_complete' => false, 'counts_toward_runtime_completeness' => false],
            'reality_dependent' => ['counts_toward_runtime_completeness' => false],
            'verdict' => ['runtime_complete' => true, 'asymptote_complete' => false, 'claims_asymptote' => false, 'folds_grounded_into_completeness' => false, 'outcome_grounded_counts_toward_completeness' => false],
        ]);
        $this->addToAssertionCount(1);
    }

    /**
     * FIX 4 — LOCK THE MECHANISM SET. Read the REAL private MECHANISMS registry via
     * reflection (NOT a literal fed to a mock) and pin both the exact COUNT and the
     * exact KEY-SET. A future edit that DROPS a rung to shrink the completeness
     * denominator — the subtlest goalpost-move — then fails here as a visible,
     * reviewed change rather than silently inflating the "X/X complete" headline.
     */
    public function test_mechanism_set_is_locked_count_and_exact_keys(): void
    {
        $mechanisms = $this->realMechanisms();

        $expectedKeys = [
            'l0_write_gate',
            'l0_block_readiness',
            'l1_p1_predict',
            'l1_p2_reconcile',
            'l1_p2_repair',
            'l1_p2_code_contract',
            'l1_p3_antibody',
            'l2_o1_outcome_grounding',
            'l2_o2_intent_advisory',
            'l2_o3_multi_estate',
            'linf_r1_causal_self_model',
            'linf_r2_reflective_status',
            'linf_r3_self_improvement_modeling',
            'flow_composer',
            'live_integration',
        ];

        $actualKeys = array_map(static fn (array $m): string => (string) $m['key'], $mechanisms);

        $this->assertCount(15, $mechanisms, 'the buildable mechanism set must stay at exactly 15 rungs — dropping one to shrink the denominator is a reviewed change, not a silent edit');
        $this->assertSame($expectedKeys, $actualKeys, 'the exact mechanism key-set (and order) is locked; add/remove/rename is a visible, reviewed change');

        // The live envelope total must equal the locked registry size — they can
        // never silently diverge.
        $payload = $this->service()->assess();
        $this->assertSame(15, $payload['runtime_completeness']['total_mechanisms']);
    }

    /**
     * FIX 1 — EVERY mechanism is drift-checked against a REAL owner doc; NONE are
     * exempt. Read straight from the real registry: every spec carries a non-empty
     * owner_doc, and at runtime every mechanism reports drift_exempt=false +
     * drift_checked=true. This nails the cardinal fix — the false "no evidence_refs
     * => drift-exempt" shortcut is gone for good.
     */
    public function test_every_mechanism_has_a_real_owner_doc_and_is_drift_checked_none_exempt(): void
    {
        foreach ($this->realMechanisms() as $spec) {
            $this->assertArrayHasKey('owner_doc', $spec, "mechanism {$spec['key']} must declare an owner_doc");
            $this->assertIsString($spec['owner_doc']);
            $this->assertNotSame('', trim((string) $spec['owner_doc']), "mechanism {$spec['key']} must name a REAL owner doc, never null/empty (no drift-exemption)");
        }

        // live_integration must NOT duplicate the R2 mechanism (FIX 2): distinct
        // owner doc, command and test.
        $byKey = collect($this->realMechanisms())->keyBy('key');
        $r2 = $byKey['linf_r2_reflective_status'];
        $integration = $byKey['live_integration'];
        $this->assertNotSame($r2['owner_doc'], $integration['owner_doc'], 'live_integration must point at a DISTINCT owner doc, not duplicate R2');
        $this->assertNotSame($r2['command'], $integration['command'], 'live_integration must point at a DISTINCT command, not duplicate R2');
        $this->assertNotSame($r2['test'], $integration['test'], 'live_integration must point at a DISTINCT test, not duplicate R2');

        // At runtime every mechanism is genuinely drift-checked (no exemption flag).
        foreach ($this->service()->assess()['runtime_completeness']['mechanisms'] as $m) {
            $this->assertFalse($m['drift_exempt'], "mechanism {$m['key']} must NOT be drift-exempt");
            $this->assertTrue($m['drift_checked'], "mechanism {$m['key']} must be drift-checked against its real owner doc");
            $this->assertNotNull($m['owner_doc'], "mechanism {$m['key']} must carry a real owner doc in the envelope");
        }
    }

    /**
     * The REAL private MECHANISMS registry, read via reflection — the genuine
     * shipped set, never a hardcoded literal handed to a mock.
     *
     * @return list<array<string,mixed>>
     */
    private function realMechanisms(): array
    {
        $prop = new \ReflectionClassConstant(AtlasDocumentationRealityCompletenessService::class, 'MECHANISMS');

        /** @var list<array<string,mixed>> $value */
        $value = $prop->getValue();

        return $value;
    }
}
