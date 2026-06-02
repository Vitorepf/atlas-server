<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L10LongHorizonTelosProposalSpecBuilder;
use PHPUnit\Framework\TestCase;

final class L10LongHorizonTelosProposalSpecBuilderTest extends TestCase
{
    /** Minimum whole-year horizon for a proposal to count as long-horizon. */
    private const MIN_PROPOSABLE_HORIZON = 1;

    private L10LongHorizonTelosProposalSpecBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new L10LongHorizonTelosProposalSpecBuilder();
    }

    public function testBuildReturnsRequiredProposalFieldsFromEvidenceAndHorizon(): void
    {
        $result = $this->builder->build(
            [
                'evidence' => [
                    'cycle:914',
                    ['ref' => 'aemor:outcome:77'],
                    'doc:atlas-self-directed-evolution-layer',
                ],
            ],
            3
        );

        $this->assertSame('atlas.aaeos.l10.long_horizon_telos_proposal_spec.v1', $result['schema_version']);

        // Acceptance: build returns operator_curation_required=true (byte-for-byte).
        $this->assertTrue($result['operator_curation_required']);

        // Acceptance: horizon_years is the normalised whole-year horizon.
        $this->assertSame(3, $result['horizon_years']);

        // Acceptance: telos_proposal_id is a non-empty computed identifier carrying
        // the horizon, the evidence count and a content digest.
        $this->assertIsString($result['telos_proposal_id']);
        $this->assertSame('l10.telos.3y.3.', substr($result['telos_proposal_id'], 0, 15));

        // Acceptance: evidence_refs are the deduped, sorted, normalised refs.
        $this->assertSame(
            ['aemor:outcome:77', 'cycle:914', 'doc:atlas-self-directed-evolution-layer'],
            $result['evidence_refs']
        );
        $this->assertSame(3, $result['evidence_ref_count']);

        // Acceptance: strategic_bets — one bet per distinct evidence ref, grounded
        // in evidence (computed, generalises with the evidence set).
        $this->assertCount(3, $result['strategic_bets']);
        $this->assertSame(3, $result['strategic_bet_count']);

        $firstBet = $result['strategic_bets'][0];
        $this->assertSame('aemor:outcome:77', $firstBet['evidence_ref']);
        $this->assertSame(3, $firstBet['horizon_years']);
        $this->assertSame('bet.01.aemor_outcome_77', $firstBet['bet_id']);
        // Direction is a PROPOSED direction, never a chosen final end.
        $this->assertStringContainsString('propose engineering direction', $firstBet['direction']);
        $this->assertStringContainsString('aemor:outcome:77', $firstBet['direction']);

        // Every bet references exactly one of the evidence refs.
        foreach ($result['strategic_bets'] as $bet) {
            $this->assertContains($bet['evidence_ref'], $result['evidence_refs']);
        }

        // No blocker fired: this is a proposable long-horizon telos.
        $this->assertSame([], $result['blockers']);
        $this->assertTrue($result['proposable']);
    }

    public function testNoEvidenceBlocks(): void
    {
        $result = $this->builder->build([], 3);

        // Rule: no evidence blocks.
        $this->assertContains('no_evidence', $result['blockers']);
        $this->assertFalse($result['proposable']);
        $this->assertSame([], $result['strategic_bets']);
        $this->assertSame(0, $result['strategic_bet_count']);
        $this->assertSame([], $result['evidence_refs']);
        $this->assertSame(0, $result['evidence_ref_count']);
        // Horizon was valid, so it is NOT the reason for the block.
        $this->assertNotContains('horizon_below_one_year', $result['blockers']);
        // operator curation stays required even when blocked.
        $this->assertTrue($result['operator_curation_required']);
    }

    public function testEvidenceWithOnlyEmptyRefsBlocks(): void
    {
        $result = $this->builder->build(
            [
                'evidence' => ['', '   ', ['ref' => '']],
            ],
            5
        );

        // No usable ref survives normalisation -> no_evidence blocker.
        $this->assertContains('no_evidence', $result['blockers']);
        $this->assertSame([], $result['evidence_refs']);
        $this->assertFalse($result['proposable']);
    }

    public function testHorizonBelowOneYearBlocks(): void
    {
        $result = $this->builder->build(
            ['evidence' => ['cycle:1']],
            0
        );

        // Rule: horizon below one year blocks.
        $this->assertContains('horizon_below_one_year', $result['blockers']);
        $this->assertFalse($result['proposable']);
        $this->assertSame(0, $result['horizon_years']);
        $this->assertSame([], $result['strategic_bets']);
        // Evidence was present, so no_evidence is NOT the reason.
        $this->assertNotContains('no_evidence', $result['blockers']);
    }

    public function testSubYearMonthHorizonIsRejectedAsBelowOneYear(): void
    {
        // 11 months floors to 0 years -> below one year.
        $result = $this->builder->build(
            ['evidence' => ['cycle:1']],
            ['months' => 11]
        );

        $this->assertSame(0, $result['horizon_years']);
        $this->assertContains('horizon_below_one_year', $result['blockers']);
        $this->assertFalse($result['proposable']);
    }

    public function testFractionalYearBelowOneIsRejected(): void
    {
        // 0.5 years floors to 0 -> below one year.
        $result = $this->builder->build(
            ['evidence' => ['cycle:1']],
            0.5
        );

        $this->assertSame(0, $result['horizon_years']);
        $this->assertContains('horizon_below_one_year', $result['blockers']);
        $this->assertFalse($result['proposable']);
    }

    public function testBothBlockersFireWhenEvidenceMissingAndHorizonTooShort(): void
    {
        $result = $this->builder->build([], 0);

        $this->assertContains('no_evidence', $result['blockers']);
        $this->assertContains('horizon_below_one_year', $result['blockers']);
        $this->assertFalse($result['proposable']);
        $this->assertSame([], $result['strategic_bets']);
    }

    public function testSystemChosenFinalEndsIsForbidden(): void
    {
        $result = $this->builder->build(['evidence' => ['cycle:1']], 2);

        // Rule: system_chosen_final_ends is forbidden — present in the registry
        // and reflected by the derived boolean.
        $this->assertContains('system_chosen_final_ends', $result['forbidden_actions']);
        $this->assertTrue($result['system_chosen_final_ends_forbidden']);
        // Membership is computed (real rule), so an unrelated action is not forbidden.
        $this->assertTrue($this->builder->forbids('system_chosen_final_ends'));
        $this->assertFalse($this->builder->forbids('propose_engineering_direction'));
    }

    public function testHorizonFloorsToWholeYears(): void
    {
        // 3.9 years -> 3 whole years (floor, not round).
        $result = $this->builder->build(['evidence' => ['cycle:1']], 3.9);

        $this->assertSame(3, $result['horizon_years']);
        $this->assertTrue($result['proposable']);
        $this->assertSame(3, $result['strategic_bets'][0]['horizon_years']);
    }

    public function testMonthsHorizonConvertsToYears(): void
    {
        // 36 months -> 3 years.
        $result = $this->builder->build(['evidence' => ['cycle:1']], ['months' => 36]);

        $this->assertSame(3, $result['horizon_years']);
        $this->assertTrue($result['proposable']);
    }

    public function testExplicitYearsArrayHorizonIsHonoured(): void
    {
        $result = $this->builder->build(['evidence' => ['cycle:1']], ['years' => 4]);

        $this->assertSame(4, $result['horizon_years']);
        $this->assertTrue($result['proposable']);
    }

    public function testNonFiniteHorizonResolvesToZeroAndIsRejected(): void
    {
        // INF / NAN (e.g. a numeric-string '1e400' that overflows to INF, or a raw
        // INF/NAN float) carry no usable finite multi-year horizon: they must
        // resolve to 0 (and be rejected as below one year), never emit a runtime
        // warning, and never leak a non-finite value into the output.
        foreach (['1e400', INF, NAN, ['months' => '1e400']] as $horizon) {
            $result = $this->builder->build(['evidence' => ['cycle:1']], $horizon);

            $this->assertSame(0, $result['horizon_years']);
            $this->assertContains('horizon_below_one_year', $result['blockers']);
            $this->assertFalse($result['proposable']);
        }
    }

    public function testHugeIntHorizonSaturatesNonNegativeAndNeverWrapsNegative(): void
    {
        // PHP_INT_MAX cast to float rounds up to 2^63, which is not representable
        // as an int: a naive (int) floor() would emit a runtime warning and WRAP to
        // PHP_INT_MIN (negative), leaking a negative horizon_years (breaking the
        // >= 0 contract) into both horizon_years and telos_proposal_id. It must
        // instead saturate to a valid huge non-negative horizon.
        $result = $this->builder->build(['evidence' => ['cycle:1']], PHP_INT_MAX);

        $this->assertGreaterThanOrEqual(self::MIN_PROPOSABLE_HORIZON, $result['horizon_years']);
        $this->assertTrue($result['proposable']);
        // The horizon never wraps negative and never poisons the deterministic id.
        $this->assertStringNotContainsString('-', $result['telos_proposal_id']);
        $this->assertSame($result['horizon_years'], $result['strategic_bets'][0]['horizon_years']);
    }

    public function testStrategicBetCountGeneralisesWithEvidenceSize(): void
    {
        $result = $this->builder->build(
            ['evidence' => ['e1', 'e2', 'e3', 'e4', 'e5']],
            2
        );

        // Five distinct refs -> five bets (not a canned number).
        $this->assertSame(5, $result['strategic_bet_count']);
        $this->assertCount(5, $result['strategic_bets']);

        $betEvidence = array_map(
            static fn (array $bet): string => $bet['evidence_ref'],
            $result['strategic_bets']
        );
        $this->assertSame(['e1', 'e2', 'e3', 'e4', 'e5'], $betEvidence);
    }

    public function testEvidenceRefsAreDedupedAndSorted(): void
    {
        $result = $this->builder->build(
            ['evidence' => ['zeta', 'alpha', 'zeta', ['ref' => 'alpha'], 'mid']],
            3
        );

        // Duplicates collapse; output is ascending and distinct.
        $this->assertSame(['alpha', 'mid', 'zeta'], $result['evidence_refs']);
        $this->assertSame(3, $result['strategic_bet_count']);
    }

    public function testNumericLookingEvidenceRefsSortLexicographicallyAsStrings(): void
    {
        // evidence_refs is list<string>: "ascending" must be string-ascending. A bare
        // SORT_REGULAR sort would order these numerically (['9','10','100']); the
        // string contract requires ['10','100','9'].
        $result = $this->builder->build(['evidence' => ['10', '9', '100']], 3);

        $this->assertSame(['10', '100', '9'], $result['evidence_refs']);
    }

    public function testTelosProposalIdIsStableAcrossReorderedNumericLookingEvidence(): void
    {
        // '1', '01' and '1.0' are three DISTINCT string refs that are numerically
        // equal. With SORT_REGULAR they would keep their input order, so the same
        // evidence SET in a different order would produce a different sorted list and
        // a different (supposedly deterministic) telos_proposal_id. String sort pins
        // one canonical order regardless of input order.
        $a = $this->builder->build(['evidence' => ['1', '01', '1.0']], 3);
        $b = $this->builder->build(['evidence' => ['1.0', '1', '01']], 3);
        $c = $this->builder->build(['evidence' => ['01', '1.0', '1']], 3);

        $this->assertSame($a['evidence_refs'], $b['evidence_refs']);
        $this->assertSame($a['evidence_refs'], $c['evidence_refs']);
        $this->assertSame($a['telos_proposal_id'], $b['telos_proposal_id']);
        $this->assertSame($a['telos_proposal_id'], $c['telos_proposal_id']);
        // All three distinct refs survive dedupe.
        $this->assertSame(3, $a['evidence_ref_count']);
    }

    public function testTelosProposalIdIsDeterministicAndContentSensitive(): void
    {
        $first = $this->builder->build(['evidence' => ['a', 'b']], 3);
        $sameAgain = $this->builder->build(['evidence' => ['b', 'a']], 3);
        $differentEvidence = $this->builder->build(['evidence' => ['a', 'c']], 3);
        $differentHorizon = $this->builder->build(['evidence' => ['a', 'b']], 4);

        // Same content (order-independent) -> same id (pure, deterministic).
        $this->assertSame($first['telos_proposal_id'], $sameAgain['telos_proposal_id']);
        // Different evidence -> different id.
        $this->assertNotSame($first['telos_proposal_id'], $differentEvidence['telos_proposal_id']);
        // Different horizon -> different id.
        $this->assertNotSame($first['telos_proposal_id'], $differentHorizon['telos_proposal_id']);
    }

    public function testBuildIsPureAndIdempotent(): void
    {
        $evidence = ['evidence' => ['cycle:914', ['source' => 'aemor:1'], 'doc:x']];

        $first = $this->builder->build($evidence, 3);
        $second = $this->builder->build($evidence, 3);

        // Identical inputs -> identical output (no clock, no randomness, no I/O).
        $this->assertSame($first, $second);
    }
}
