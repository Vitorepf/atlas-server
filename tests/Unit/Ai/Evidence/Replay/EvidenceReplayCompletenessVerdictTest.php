<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Evidence\Replay;

use App\Services\Ai\Evidence\Replay\EvidenceReplayCompletenessVerdict;
use Tests\TestCase;

final class EvidenceReplayCompletenessVerdictTest extends TestCase
{
    private EvidenceReplayCompletenessVerdict $verdict;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verdict = new EvidenceReplayCompletenessVerdict();
    }

    public function testCaseOneFullyCompleteReplayIsGreen(): void
    {
        $result = $this->verdict->decide(
            ['ledger', 'receipt'],
            ['ledger', 'receipt'],
            3,
            0,
            0,
        );

        $this->assertSame('complete', $result['verdict']);
        $this->assertTrue($result['replay_green']);
        $this->assertSame(1.0, $result['completeness_ratio']);
        $this->assertSame([], $result['missing_components']);
        $this->assertSame([], $result['reasons']);
        $this->assertSame('atlas.evidence.replay_completeness.v1', $result['schema_version']);
    }

    public function testCaseTwoOnlyMissingComponentsIsIncompleteNotUnreplayable(): void
    {
        $result = $this->verdict->decide(
            ['trace', 'ledger', 'receipt'],
            ['ledger'],
            2,
            0,
            0,
        );

        $this->assertSame('incomplete_missing_components', $result['verdict']);
        $this->assertFalse($result['replay_green']);
        $this->assertSame(0.33, $result['completeness_ratio']);
        // Missing sorted ascending and reindexed via array_values.
        $this->assertSame(['receipt', 'trace'], $result['missing_components']);
        $this->assertSame(['required_components_missing'], $result['reasons']);
    }

    public function testCaseThreeBrokenRefsForceUnreplayableAndNegativeCountsClamp(): void
    {
        $result = $this->verdict->decide(
            ['ledger'],
            ['ledger'],
            5,
            -5,
            2,
        );

        // Negative chainGapCount clamps to 0 so it does NOT fire the chain rule.
        $this->assertSame('incomplete_unreplayable', $result['verdict']);
        $this->assertFalse($result['replay_green']);
        $this->assertSame(1.0, $result['completeness_ratio']);
        $this->assertSame(['broken_evidence_refs'], $result['reasons']);
    }

    public function testCaseFourChainVetoWinsVerdictWhileAllThreeReasonsRecorded(): void
    {
        $result = $this->verdict->decide(
            ['ledger', 'receipt'],
            ['ledger'],
            0,
            4,
            3,
        );

        // Chain/ref veto wins the verdict even though a component is missing.
        $this->assertSame('incomplete_unreplayable', $result['verdict']);
        $this->assertFalse($result['replay_green']);
        $this->assertSame(0.5, $result['completeness_ratio']);
        $this->assertSame(['receipt'], $result['missing_components']);
        // All three fired conditions accumulated in declared order.
        $this->assertSame(
            ['broken_evidence_refs', 'hash_chain_discontinuous', 'required_components_missing'],
            $result['reasons'],
        );
    }

    public function testCaseFiveEmptyRequiredYieldsRatioOneAndCompleteVerdict(): void
    {
        $result = $this->verdict->decide(
            [],
            [],
            1,
            0,
            0,
        );

        $this->assertSame(1.0, $result['completeness_ratio']);
        $this->assertSame('complete', $result['verdict']);
        $this->assertTrue($result['replay_green']);
        $this->assertSame([], $result['reasons']);
        $this->assertSame('atlas.evidence.replay_completeness.v1', $result['schema_version']);
    }

    public function testNumericStringComponentsStayStringsAndSortLexically(): void
    {
        $result = $this->verdict->decide(
            ['10', '2', '1', 'ledger'],
            ['ledger'],
            3,
            0,
            0,
        );

        // missing_components must stay list<string>: numeric-looking names must
        // not be coerced to int (array_keys trap), and ordering is lexical (SORT_STRING),
        // not numeric, so '10' precedes '2'.
        $this->assertSame(['1', '10', '2'], $result['missing_components']);
        foreach ($result['missing_components'] as $component) {
            $this->assertIsString($component);
        }
        $this->assertTrue(array_is_list($result['missing_components']));
        $this->assertSame(0.25, $result['completeness_ratio']);
    }

    public function testDuplicateRequiredComponentIsNotListedTwice(): void
    {
        $result = $this->verdict->decide(
            ['alpha', 'alpha', 'beta'],
            ['beta'],
            2,
            0,
            0,
        );

        // Duplicate required entry collapses to a single missing entry so the
        // ratio denominator/numerator stay consistent.
        $this->assertSame(['alpha'], $result['missing_components']);
        $this->assertSame(0.67, $result['completeness_ratio']);
    }

    public function testZeroLengthChainAloneForcesUnreplayableEvenWhenOtherwiseComplete(): void
    {
        // Spec R2 fires on chainGapCount>0 OR chainLength<=0. Here gaps and broken
        // refs are both zero and every required component is present (ratio 1.0),
        // so the ONLY trigger is the empty chain (chainLength=0). It must still be
        // incomplete_unreplayable with hash_chain_discontinuous as the sole reason.
        $result = $this->verdict->decide(
            ['ledger', 'receipt'],
            ['ledger', 'receipt'],
            0,
            0,
            0,
        );

        $this->assertSame('incomplete_unreplayable', $result['verdict']);
        $this->assertFalse($result['replay_green']);
        $this->assertSame(1.0, $result['completeness_ratio']);
        $this->assertSame([], $result['missing_components']);
        $this->assertSame(['hash_chain_discontinuous'], $result['reasons']);
    }
}
