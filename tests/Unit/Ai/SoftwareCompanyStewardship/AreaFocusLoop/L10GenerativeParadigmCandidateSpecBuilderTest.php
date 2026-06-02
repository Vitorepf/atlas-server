<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L10GenerativeParadigmCandidateSpecBuilder;
use PHPUnit\Framework\TestCase;

final class L10GenerativeParadigmCandidateSpecBuilderTest extends TestCase
{
    private L10GenerativeParadigmCandidateSpecBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new L10GenerativeParadigmCandidateSpecBuilder();
    }

    public function testBuildReturnsRequiredProposalOnlyCandidateFieldsFromMeasuredOutcome(): void
    {
        $result = $this->builder->build([
            'r3_bound' => ['proven' => true, 'ref' => 'cert:R3-7714'],
            'outcomes' => [
                [
                    'paradigm' => 'Effect-Indexed Build Graph',
                    'scope' => 'engineering',
                    'engineering_stage' => 'design',
                    'outcome_before' => 12.0,
                    'outcome_after' => 30.0,
                    'safety_refs' => ['twin:run-914', 'twin:run-914'],
                ],
            ],
        ]);

        $this->assertSame('atlas.aaeos.l10.generative_paradigm_candidate_spec.v1', $result['schema_version']);
        $this->assertSame('engineering_only', $result['scope']);
        // Top-level proposal_only is the literal contract: true.
        $this->assertTrue($result['proposal_only']);
        $this->assertSame(1, $result['candidate_count']);
        $this->assertCount(1, $result['candidates']);
        $this->assertFalse($result['blocked']);
        $this->assertSame([], $result['blockers']);

        $candidate = $result['candidates'][0];

        // Acceptance return keys: paradigm_candidate_id, novelty_claim,
        // expected_outcome_delta, safety_refs, proposal_only=true.
        $this->assertArrayHasKey('paradigm_candidate_id', $candidate);
        $this->assertArrayHasKey('novelty_claim', $candidate);
        $this->assertArrayHasKey('expected_outcome_delta', $candidate);
        $this->assertArrayHasKey('safety_refs', $candidate);
        $this->assertArrayHasKey('proposal_only', $candidate);

        // paradigm_candidate_id is the deterministic slug of the named paradigm.
        $this->assertSame('effect_indexed_build_graph', $candidate['paradigm_candidate_id']);
        // novelty_claim is a non-empty computed string naming paradigm, stage, direction.
        $this->assertIsString($candidate['novelty_claim']);
        $this->assertStringContainsString('effect_indexed_build_graph', $candidate['novelty_claim']);
        $this->assertStringContainsString('improves', $candidate['novelty_claim']);
        $this->assertStringContainsString('design', $candidate['novelty_claim']);
        // expected_outcome_delta = after - before = 30 - 12 = 18.0 (computed, not canned).
        $this->assertEqualsWithDelta(18.0, $candidate['expected_outcome_delta'], 0.0001);
        // Each candidate is proposal-only: never an autonomous adoption.
        $this->assertTrue($candidate['proposal_only']);
        // safety_refs pins the proven R3 bound + measured-or-reverted gate, then
        // row refs (deduplicated).
        $this->assertContains('r3_bound:cert:R3-7714', $candidate['safety_refs']);
        $this->assertContains('measured_or_reverted', $candidate['safety_refs']);
        $this->assertContains('twin:run-914', $candidate['safety_refs']);
        // Duplicate row ref collapses: exactly one occurrence.
        $this->assertSame(1, $this->countOccurrences($candidate['safety_refs'], 'twin:run-914'));
    }

    public function testNoOutcomeBasisBlocks(): void
    {
        $result = $this->builder->build([
            'r3_bound_proven' => true,
            'outcomes' => [
                [
                    'paradigm' => 'speculative_calculus',
                    'scope' => 'engineering',
                    'engineering_stage' => 'spec',
                    // No outcome_before/after, no outcome_delta, no measured_value.
                ],
            ],
        ]);

        // An outcome with no measured basis cannot seed a candidate, even when
        // the R3 bound is proven.
        $this->assertSame(0, $result['candidate_count']);
        $this->assertSame([], $result['candidates']);
        $this->assertTrue($result['blocked']);
        $this->assertTrue($result['insufficient_evidence']);
        $this->assertSame(0, $result['measured_outcome_count']);
        $this->assertSame(['speculative_calculus'], $result['rejected_no_basis']);
        $this->assertContains('no_outcome_basis', $result['blockers']);
    }

    public function testMissingR3BoundBlocks(): void
    {
        $result = $this->builder->build([
            // No r3_bound / r3_bound_proven at all.
            'outcomes' => [
                [
                    'paradigm' => 'continuation_typed_pipelines',
                    'scope' => 'engineering',
                    'engineering_stage' => 'implementation',
                    'outcome_delta' => 9.0,
                ],
            ],
        ]);

        // The R3 convergence bound is the non-negotiable precondition: without a
        // proven bound, no generative candidate may be specified.
        $this->assertFalse($result['r3_bound_proven']);
        $this->assertSame(0, $result['candidate_count']);
        $this->assertSame([], $result['candidates']);
        $this->assertTrue($result['blocked']);
        $this->assertContains('r3_bound_missing', $result['blockers']);
        // The measured row is still counted (auditable basis), proving the block
        // is the bound and not a missing measurement.
        $this->assertSame(1, $result['measured_outcome_count']);
    }

    public function testUnprovenR3BoundFlagAlsoBlocks(): void
    {
        $result = $this->builder->build([
            'r3_bound' => ['proven' => false, 'ref' => 'cert:R3-draft'],
            'outcomes' => [
                [
                    'paradigm' => 'region_polymorphic_memory',
                    'scope' => 'engineering',
                    'outcome_delta' => 5.0,
                ],
            ],
        ]);

        // An explicitly unproven bound is treated as missing.
        $this->assertFalse($result['r3_bound_proven']);
        $this->assertSame(0, $result['candidate_count']);
        $this->assertContains('r3_bound_missing', $result['blockers']);
    }

    public function testCandidateNeverMutatesRuntime(): void
    {
        $result = $this->builder->build([
            'r3_bound_proven' => true,
            'outcomes' => [
                [
                    'paradigm' => 'algebraic_effect_router',
                    'scope' => 'engineering',
                    'engineering_stage' => 'integration',
                    'outcome_delta' => 4.0,
                ],
            ],
        ]);

        // Proposal-only, read-only spec: the builder never mutates runtime.
        $this->assertFalse($result['mutates_runtime']);
        $this->assertTrue($result['proposal_only']);
        // Even with a valid candidate, the candidate itself is only a proposal.
        $this->assertSame(1, $result['candidate_count']);
        $this->assertTrue($result['candidates'][0]['proposal_only']);
    }

    public function testEmptyOutcomesBlocks(): void
    {
        $result = $this->builder->build([]);

        $this->assertTrue($result['blocked']);
        $this->assertTrue($result['insufficient_evidence']);
        $this->assertSame(0, $result['candidate_count']);
        $this->assertSame([], $result['candidates']);
        $this->assertSame('engineering_only', $result['scope']);
        $this->assertTrue($result['proposal_only']);
        // No bound and no candidate both surface as ordered blockers.
        $this->assertContains('r3_bound_missing', $result['blockers']);
        $this->assertContains('no_paradigm_candidate', $result['blockers']);
    }

    public function testNonEngineeringScopeRejectsEvenWithProvenBoundAndMeasurement(): void
    {
        $result = $this->builder->build([
            'r3_bound_proven' => true,
            'outcomes' => [
                [
                    'paradigm' => 'campaign_attribution_model',
                    'scope' => 'marketing',
                    'outcome_before' => 1.0,
                    'outcome_after' => 9.0,
                ],
                [
                    'paradigm' => 'portfolio_optimizer',
                    'domain' => 'trading',
                    'outcome_delta' => 7.0,
                ],
            ],
        ]);

        // Both outcomes leave engineering scope: zero candidates, both rejected,
        // and neither counts as a measured engineering outcome.
        $this->assertSame(0, $result['candidate_count']);
        $this->assertSame([], $result['candidates']);
        $this->assertSame(0, $result['measured_outcome_count']);
        $this->assertSame(
            ['campaign_attribution_model:marketing', 'portfolio_optimizer:trading'],
            $result['rejected_non_engineering']
        );
    }

    public function testExpectedOutcomeDeltaUsesExplicitDeltaAndComputesRegressionDirection(): void
    {
        $result = $this->builder->build([
            'r3_bound_proven' => true,
            'outcomes' => [
                [
                    'paradigm' => 'aggressive_specialization',
                    'scope' => 'engineering',
                    'stage' => 'testing',
                    'outcome_delta' => -4.5,
                ],
            ],
        ]);

        $candidate = $result['candidates'][0];

        $this->assertSame('aggressive_specialization', $candidate['paradigm_candidate_id']);
        // Explicit outcome_delta wins over before/after derivation; sign preserved.
        $this->assertEqualsWithDelta(-4.5, $candidate['expected_outcome_delta'], 0.0001);
        // Negative delta yields a "regresses" novelty-claim direction.
        $this->assertStringContainsString('regresses', $candidate['novelty_claim']);
    }

    public function testUnknownEngineeringStageFallsBackToUnspecifiedButStaysInScope(): void
    {
        $result = $this->builder->build([
            'r3_bound_proven' => true,
            'outcomes' => [
                [
                    'paradigm' => 'context_pack_prewarm',
                    'scope' => 'engineering',
                    'stage' => 'galactic_navigation',
                    'measured_value' => 6.0,
                ],
            ],
        ]);

        $candidate = $result['candidates'][0];

        // Unknown stage token is not in the lexicon -> default stage in the claim.
        $this->assertStringContainsString('unspecified', $candidate['novelty_claim']);
        $this->assertSame('engineering_only', $candidate['scope']);
        // Standalone measured_value is used as the delta.
        $this->assertEqualsWithDelta(6.0, $candidate['expected_outcome_delta'], 0.0001);
    }

    public function testDuplicateParadigmIsNotReemitted(): void
    {
        $result = $this->builder->build([
            'r3_bound_proven' => true,
            'outcomes' => [
                [
                    'paradigm' => 'Capability-Scoped Effects',
                    'scope' => 'engineering',
                    'stage' => 'design',
                    'outcome_before' => 10.0,
                    'outcome_after' => 25.0,
                ],
                [
                    // Same paradigm after slugging -> duplicate, must not re-emit.
                    'paradigm' => 'capability-scoped-effects',
                    'scope' => 'engineering',
                    'stage' => 'design',
                    'outcome_delta' => 99.0,
                ],
            ],
        ]);

        $this->assertSame(1, $result['candidate_count']);
        $this->assertCount(1, $result['candidates']);
        $this->assertSame('capability_scoped_effects', $result['candidates'][0]['paradigm_candidate_id']);
        // The first (earlier) measurement seeds the candidate; the duplicate is discarded.
        $this->assertEqualsWithDelta(15.0, $result['candidates'][0]['expected_outcome_delta'], 0.0001);
    }

    public function testParadigmWithABasisRowIsNotAlsoReportedAsNoBasis(): void
    {
        // Same paradigm appears twice: one row has no measured basis, another
        // carries a real delta. The paradigm DOES have a basis, so it must become
        // a candidate and must NOT also surface in rejected_no_basis — otherwise a
        // contradictory no_outcome_basis blocker would be raised on a paradigm that
        // is, in fact, a valid candidate. Order must not matter.
        $noBasisFirst = $this->builder->build([
            'r3_bound_proven' => true,
            'outcomes' => [
                ['paradigm' => 'dual_basis', 'scope' => 'engineering', 'stage' => 'design'],
                ['paradigm' => 'dual_basis', 'scope' => 'engineering', 'stage' => 'design', 'outcome_delta' => 5.0],
            ],
        ]);

        $this->assertSame(1, $noBasisFirst['candidate_count']);
        $this->assertSame('dual_basis', $noBasisFirst['candidates'][0]['paradigm_candidate_id']);
        // The valid candidate is never contradicted by a no-basis rejection.
        $this->assertNotContains('dual_basis', $noBasisFirst['rejected_no_basis']);
        $this->assertSame([], $noBasisFirst['rejected_no_basis']);
        // No spurious no_outcome_basis blocker, and the build is not blocked.
        $this->assertNotContains('no_outcome_basis', $noBasisFirst['blockers']);
        $this->assertSame([], $noBasisFirst['blockers']);
        $this->assertFalse($noBasisFirst['blocked']);
        // The measured row is still counted.
        $this->assertSame(1, $noBasisFirst['measured_outcome_count']);

        // Reversed row order yields the same decision (basis row first).
        $basisFirst = $this->builder->build([
            'r3_bound_proven' => true,
            'outcomes' => [
                ['paradigm' => 'dual_basis', 'scope' => 'engineering', 'stage' => 'design', 'outcome_delta' => 5.0],
                ['paradigm' => 'dual_basis', 'scope' => 'engineering', 'stage' => 'design'],
            ],
        ]);
        $this->assertSame([], $basisFirst['rejected_no_basis']);
        $this->assertSame([], $basisFirst['blockers']);

        // A genuinely distinct basis-less paradigm is still rejected and still
        // raises the no_outcome_basis blocker alongside the valid candidate.
        $mixed = $this->builder->build([
            'r3_bound_proven' => true,
            'outcomes' => [
                ['paradigm' => 'has_basis', 'scope' => 'engineering', 'outcome_delta' => 5.0],
                ['paradigm' => 'truly_empty', 'scope' => 'engineering'],
            ],
        ]);
        $this->assertSame(['truly_empty'], $mixed['rejected_no_basis']);
        $this->assertContains('no_outcome_basis', $mixed['blockers']);
        $this->assertSame(1, $mixed['candidate_count']);
        $this->assertFalse($mixed['blocked']);
    }

    public function testSafetyRefsAreAListOfStringsEvenWithNumericRowRefs(): void
    {
        $result = $this->builder->build([
            'r3_bound_proven' => true,
            'outcomes' => [
                [
                    'paradigm' => 'numeric_ref_paradigm',
                    'scope' => 'engineering',
                    'outcome_delta' => 3.0,
                    // Numeric refs must coerce to strings and never break list<string>
                    // via integer key coercion.
                    'safety_refs' => [42, 7, 42],
                ],
            ],
        ]);

        $safetyRefs = $result['candidates'][0]['safety_refs'];

        // Contract: list<string> — sequential 0..n-1 integer keys, every value a string.
        $this->assertSame(array_values($safetyRefs), $safetyRefs);
        $this->assertSame(range(0, count($safetyRefs) - 1), array_keys($safetyRefs));
        foreach ($safetyRefs as $ref) {
            $this->assertIsString($ref);
        }
        // Numeric row refs are coerced to strings and deduplicated.
        $this->assertContains('42', $safetyRefs);
        $this->assertContains('7', $safetyRefs);
        $this->assertSame(1, $this->countOccurrences($safetyRefs, '42'));
        // The proven R3 bound ref is always present.
        $this->assertContains('r3_bound:proven', $safetyRefs);
    }

    public function testCandidatesAreSortedDeterministicallyAndIdempotent(): void
    {
        $outcomes = [
            'r3_bound_proven' => true,
            'outcomes' => [
                [
                    'paradigm' => 'zeta_paradigm',
                    'scope' => 'engineering',
                    'stage' => 'merge',
                    'outcome_delta' => 3.0,
                ],
                [
                    'paradigm' => 'alpha_paradigm',
                    'scope' => 'engineering',
                    'stage' => 'spec',
                    'outcome_before' => 5.0,
                    'outcome_after' => 8.0,
                ],
            ],
        ];

        $first = $this->builder->build($outcomes);
        $second = $this->builder->build($outcomes);

        $ids = array_map(
            static fn (array $candidate): string => $candidate['paradigm_candidate_id'],
            $first['candidates']
        );

        // Deterministic ascending order by paradigm_candidate_id.
        $this->assertSame(['alpha_paradigm', 'zeta_paradigm'], $ids);
        // Same input -> identical output (pure, no clock, no randomness).
        $this->assertSame($first, $second);
    }

    public function testRejectedListsSortAsStringsForNumericLookingParadigmSlugs(): void
    {
        // Paradigm names that slug to pure-numeric strings must sort lexicographically
        // (SORT_STRING), the same string ordering candidates use — never numerically.
        // SORT_REGULAR would yield ['9','10','100'] and could treat '007'/'7' as equal,
        // breaking the list<string> ordering contract.
        $result = $this->builder->build([
            'r3_bound_proven' => true,
            'outcomes' => [
                // No measured basis -> land in rejected_no_basis.
                ['paradigm' => '10', 'scope' => 'engineering'],
                ['paradigm' => '9', 'scope' => 'engineering'],
                ['paradigm' => '100', 'scope' => 'engineering'],
                ['paradigm' => '007', 'scope' => 'engineering'],
                // Non-engineering -> land in rejected_non_engineering.
                ['paradigm' => '20', 'scope' => 'marketing'],
                ['paradigm' => '3', 'scope' => 'finance'],
            ],
        ]);

        // Lexicographic (string) order, identical to strcmp; NOT numeric ['007','7','9','10','100'].
        $this->assertSame(
            ['007', '10', '100', '9'],
            $result['rejected_no_basis'],
        );
        $this->assertSame(
            ['20:marketing', '3:finance'],
            $result['rejected_non_engineering'],
        );
        // Every entry stays a string under a real list (0..n-1 keys).
        $this->assertSame(range(0, 3), array_keys($result['rejected_no_basis']));
        foreach ($result['rejected_no_basis'] as $slug) {
            $this->assertIsString($slug);
        }
    }

    public function testNonFiniteMeasurementIsNotAnOutcomeBasisAndDeltaStaysFiniteAndSerialisable(): void
    {
        $result = $this->builder->build([
            'r3_bound_proven' => true,
            'outcomes' => [
                // Finite measured outcome -> a real candidate.
                [
                    'paradigm' => 'finite_paradigm',
                    'scope' => 'engineering',
                    'stage' => 'design',
                    'outcome_delta' => 12.5,
                ],
                // Numeric string that overflows to INF ("1e999"): not a real
                // measured value, so it carries no basis and must be rejected, not
                // emitted as a candidate with an INF delta.
                [
                    'paradigm' => 'overflow_string_paradigm',
                    'scope' => 'engineering',
                    'outcome_delta' => '1e999',
                ],
                // Two finite operands whose subtraction overflows to +INF: the
                // candidate still has a basis, but the emitted delta collapses to a
                // finite value rather than leaking INF.
                [
                    'paradigm' => 'overflow_subtraction_paradigm',
                    'scope' => 'engineering',
                    'outcome_before' => -1.0e308,
                    'outcome_after' => 1.0e308,
                ],
            ],
        ]);

        // The non-finite-only measurement contributes no basis: it lands in
        // rejected_no_basis and never becomes a candidate.
        $this->assertContains('overflow_string_paradigm', $result['rejected_no_basis']);
        $ids = array_map(
            static fn (array $candidate): string => $candidate['paradigm_candidate_id'],
            $result['candidates']
        );
        $this->assertNotContains('overflow_string_paradigm', $ids);

        // Every emitted delta is finite (never INF/NAN), so the whole proposal
        // record stays JSON-serialisable for the evidence pipeline.
        foreach ($result['candidates'] as $candidate) {
            $this->assertIsFloat($candidate['expected_outcome_delta']);
            $this->assertTrue(
                is_finite($candidate['expected_outcome_delta']),
                'expected_outcome_delta must be finite for '.$candidate['paradigm_candidate_id'],
            );
        }

        // The finite candidate keeps its exact measured delta.
        $finite = null;
        foreach ($result['candidates'] as $candidate) {
            if ($candidate['paradigm_candidate_id'] === 'finite_paradigm') {
                $finite = $candidate;
            }
        }
        $this->assertNotNull($finite);
        $this->assertEqualsWithDelta(12.5, $finite['expected_outcome_delta'], 0.0001);

        // INF/NAN break json_encode (returns false); the fix guarantees a clean encode.
        $this->assertNotFalse(json_encode($result));
    }

    /**
     * @param  list<string>  $haystack
     */
    private function countOccurrences(array $haystack, string $needle): int
    {
        $count = 0;
        foreach ($haystack as $value) {
            if ($value === $needle) {
                $count++;
            }
        }

        return $count;
    }
}
