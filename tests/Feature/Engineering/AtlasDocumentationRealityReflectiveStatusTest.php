<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;
use App\Services\Engineering\AtlasDocumentationRealityOutcomeGroundingService;
use App\Services\Engineering\AtlasDocumentationRealityReflectiveStatusService;
use App\Services\Engineering\AtlasDocumentationRealitySystemService;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * L-inf (ONE promoted fragment: R2 epistemic humility) — the reflective self-status.
 * The three composed ladder collaborators (coverage / O1 gradeAll / L0 report) are
 * MOCKED for determinism; no RefreshDatabase — the service is pure composition over
 * those reports and touches no schema of its own.
 *
 * The cardinal rule under test (the SUPREME drift): a self-claim WITHOUT calibrated
 * uncertainty is structurally impossible. Every claim carries a calibrated confidence
 * AND a non-empty blind_spots list when confidence != high or the claim is
 * completion-flavored; the headline answering "is the ADRS 10/10?" is NEVER a bare
 * verdict and ALWAYS carries non-empty declared_blind_spots; linf_complete is HARD
 * false. Read-only end to end.
 */
final class AtlasDocumentationRealityReflectiveStatusTest extends TestCase
{
    /** The canonical rungs the self-status must emit a claim for. */
    private const EXPECTED_RUNGS = ['L0', 'L1-P1', 'L1-P2', 'L1-P3', 'L2-O1', 'L2-O2', 'L2-O3'];

    /**
     * Mock the three composed collaborators with a deterministic, healthy reading:
     * coverage readable at a high number, O1 with zero grounded (the honest live
     * state), L0 ready. The service composes these — it never recomputes them.
     *
     * @param  array<string,mixed>  $coverage
     * @param  array<string,mixed>  $outcomeSummary
     */
    private function service(
        ?array $coverage = null,
        ?array $outcomeSummary = null,
        string $l0Status = 'ready',
    ): AtlasDocumentationRealityReflectiveStatusService {
        $coverage ??= [
            'schema_version' => 'atlas.aaeos.doc_runtime_coverage.v1',
            'total_canonical_docs' => 200,
            'claims_runtime' => 120,
            'with_evidence_refs' => 118,
            'verifiably_backed' => 114,
            'unverifiable_claims' => 6,
            'coverage_pct' => 95,
            'score_out_of_10' => 9.5,
        ];
        $outcomeSummary ??= ['outcome_grounded_count' => 0, 'outcome_signal_source_available' => false];

        $this->mock(AtlasAaeosImplementationTruthService::class, function (MockInterface $mock) use ($coverage): void {
            $mock->shouldReceive('coverage')->andReturn($coverage);
        });
        $this->mock(AtlasDocumentationRealityOutcomeGroundingService::class, function (MockInterface $mock) use ($outcomeSummary): void {
            $mock->shouldReceive('gradeAll')->andReturn(['summary' => $outcomeSummary]);
        });
        $this->mock(AtlasDocumentationRealitySystemService::class, function (MockInterface $mock) use ($l0Status): void {
            $mock->shouldReceive('report')->andReturn(['status' => $l0Status]);
        });

        return app(AtlasDocumentationRealityReflectiveStatusService::class);
    }

    public function test_self_assessment_emits_one_calibrated_claim_for_each_rung_with_blind_spots(): void
    {
        $payload = $this->service()->selfAssessment();

        $this->assertSame(AtlasDocumentationRealityReflectiveStatusService::SCHEMA, $payload['schema_version']);
        $this->assertIsArray($payload['claims']);
        $this->assertNotEmpty($payload['claims']);

        $rungs = array_column($payload['claims'], 'rung');
        foreach (self::EXPECTED_RUNGS as $rung) {
            $this->assertContains($rung, $rungs, "self-status must emit a claim for rung {$rung}");
        }

        // EVERY claim: a calibrated confidence AND, when confidence != high OR the
        // claim is completion-flavored, a non-empty blind_spots list.
        foreach ($payload['claims'] as $claim) {
            $this->assertContains(
                $claim['confidence'],
                [
                    AtlasDocumentationRealityReflectiveStatusService::CONFIDENCE_HIGH,
                    AtlasDocumentationRealityReflectiveStatusService::CONFIDENCE_MEDIUM,
                    AtlasDocumentationRealityReflectiveStatusService::CONFIDENCE_LOW,
                ],
                "claim for {$claim['rung']} must carry a calibrated confidence",
            );
            $this->assertArrayHasKey('blind_spots', $claim);
            $this->assertArrayHasKey('evidence_ref', $claim);
            $this->assertNotSame('', trim((string) $claim['evidence_ref']));

            // The doc-absolute rule: EVERY self-claim (including high confidence)
            // declares at least one blind_spot — no exception.
            $this->assertNotEmpty(
                $claim['blind_spots'],
                "claim for {$claim['rung']} (confidence={$claim['confidence']}) must declare at least one blind_spot",
            );
        }
    }

    public function test_headline_always_carries_blind_spots_and_is_not_a_bare_verdict(): void
    {
        $payload = $this->service()->selfAssessment();
        $headline = $payload['headline'];

        $this->assertSame('Is the ADRS doc<->runtime 10/10?', $headline['question']);
        $this->assertFalse($headline['is_bare_verdict']);
        $this->assertNotEmpty($headline['declared_blind_spots']);

        // The assessment is PROSE, not a bare number — it must contain a qualifying
        // clause, never just "10/10".
        $assessment = (string) $headline['assessment'];
        $this->assertNotSame('10/10', trim($assessment));
        $this->assertGreaterThan(40, strlen($assessment), 'the headline must be prose, not a bare verdict');
        $this->assertStringContainsStringIgnoringCase('blind spot', $assessment);

        // The REAL blind spots the doc demands must be present, verbatim-ish.
        $joined = strtolower(implode(' || ', $headline['declared_blind_spots']));
        $this->assertStringContainsString('first increment', $joined, 'must declare first-increments-only blind spot');
        $this->assertStringContainsString('outcome_grounded = 0', $joined, 'must declare outcome_grounded=0 blind spot');
        $this->assertStringContainsString('claim-set', $joined, 'must declare claim-set-completeness blind spot');
        $this->assertStringContainsString('one measurable fragment', $joined, 'must declare it is one fragment of L-inf');
    }

    public function test_linf_complete_is_hard_false_and_is_one_fragment_true(): void
    {
        $payload = $this->service()->selfAssessment();

        $this->assertFalse($payload['linf_complete'], 'linf_complete must be HARD false — the asymptote is never done');
        $this->assertTrue($payload['is_one_fragment_not_asymptote']);
        $this->assertSame('R2_epistemic_humility', $payload['fragment']);
        $this->assertStringContainsString('one promoted fragment', (string) $payload['level']);
        $this->assertTrue(data_get($payload, 'claim_policy.is_one_linf_fragment_not_the_asymptote'));
        $this->assertTrue(data_get($payload, 'claim_policy.never_confidently_wrong_about_itself'));
    }

    public function test_supreme_drift_guard_rejects_a_claim_built_without_uncertainty(): void
    {
        // The service CANNOT emit a self-claim without calibrated uncertainty: the
        // private guard throws. We exercise it directly via reflection to prove the
        // supreme drift is unreachable by construction.
        $service = $this->service();
        $assert = new \ReflectionMethod($service, 'assertClaimCarriesCalibratedUncertainty');

        // (a) No confidence at all => rejected.
        try {
            $assert->invoke($service, ['rung' => 'L0', 'claim' => 'L0 is wired.', 'blind_spots' => ['x']]);
            $this->fail('a claim with no confidence must be rejected as the supreme drift');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('calibrated', $e->getMessage());
        }

        // (b) Non-high confidence with NO blind_spot => rejected.
        try {
            $assert->invoke($service, ['rung' => 'L2-O1', 'claim' => 'O1 is built.', 'confidence' => 'medium', 'blind_spots' => []]);
            $this->fail('a non-high claim without a declared blind_spot must be rejected');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('blind_spot', $e->getMessage());
        }

        // (c) Completion-flavored claim at HIGH confidence with NO blind_spot => rejected.
        try {
            $assert->invoke($service, ['rung' => 'L0', 'claim' => 'The ADRS is 10/10 and done.', 'confidence' => 'high', 'blind_spots' => []]);
            $this->fail('a completion-flavored high-confidence claim without a blind_spot must be rejected');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('blind_spot', $e->getMessage());
        }

        // (d) Even a HIGH-confidence claim with NO blind_spot is rejected — the
        //     doc-absolute rule admits no exception: every self-claim declares a limit.
        try {
            $assert->invoke($service, ['rung' => 'L0', 'claim' => 'L0 is wired and reachable.', 'confidence' => 'high', 'blind_spots' => []]);
            $this->fail('a high-confidence self-claim without ANY blind_spot must be rejected — every self-claim declares a limit');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('blind_spot', $e->getMessage());
        }

        // (e) The truly well-formed claim — high confidence WITH a declared blind_spot —
        //     passes. The guard rejects only the drift (no declared limit), not honesty.
        $assert->invoke($service, ['rung' => 'L0', 'claim' => 'L0 is wired and reachable.', 'confidence' => 'high', 'blind_spots' => ['measures declared evidence, not every write path']]);
        $this->addToAssertionCount(1);
    }

    public function test_writes_false_and_read_only_claim_policy(): void
    {
        $payload = $this->service()->selfAssessment();

        $this->assertFalse($payload['writes']);
        $this->assertTrue(data_get($payload, 'claim_policy.read_only'));
        $this->assertFalse(data_get($payload, 'claim_policy.writes'));
        $this->assertFalse(data_get($payload, 'claim_policy.executes'));
        $this->assertFalse(data_get($payload, 'claim_policy.mutates'));
        $this->assertTrue(data_get($payload, 'claim_policy.every_claim_carries_calibrated_uncertainty'));
        $this->assertTrue(data_get($payload, 'claim_policy.models_own_status_not_all_reality'));
        $this->assertIsString($payload['reflective_status_hash']);
    }

    public function test_degraded_collaborators_lower_confidence_and_add_blind_spots_never_fabricate(): void
    {
        // When coverage cannot be read (throws), the service must NOT assert a number:
        // it degrades to low confidence on the coverage claim and the headline declares
        // the degradation as a blind spot.
        $this->mock(AtlasAaeosImplementationTruthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('coverage')->andThrow(new \RuntimeException('index unavailable'));
        });
        $this->mock(AtlasDocumentationRealityOutcomeGroundingService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('gradeAll')->andReturn(['summary' => ['outcome_grounded_count' => 0, 'outcome_signal_source_available' => false]]);
        });
        $this->mock(AtlasDocumentationRealitySystemService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('report')->andReturn(['status' => 'ready']);
        });

        $payload = app(AtlasDocumentationRealityReflectiveStatusService::class)->selfAssessment();

        $this->assertSame(AtlasDocumentationRealityReflectiveStatusService::CONFIDENCE_LOW, data_get($payload, 'headline.confidence'));
        $joined = strtolower(implode(' || ', data_get($payload, 'headline.declared_blind_spots', [])));
        $this->assertStringContainsString('coverage read model was unavailable', $joined);

        // The coverage claim must NOT assert a fabricated number when unreadable.
        $coverageClaim = collect($payload['claims'])->firstWhere('rung', 'L-inf/R2-coverage');
        $this->assertNotNull($coverageClaim);
        $this->assertSame(AtlasDocumentationRealityReflectiveStatusService::CONFIDENCE_LOW, $coverageClaim['confidence']);
        $this->assertNotEmpty($coverageClaim['blind_spots']);
    }

    /**
     * Mirror the service's completion-flavored heuristic for the per-claim assertion.
     */
    private function claimMentionsCompletion(string $claim): bool
    {
        foreach (['complete', 'completo', 'done', 'concluido', 'concluído', '10/10', 'finished', 'finalizado'] as $token) {
            if (str_contains(mb_strtolower($claim), $token)) {
                return true;
            }
        }

        return false;
    }
}
