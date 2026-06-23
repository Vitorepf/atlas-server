<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopAbstainAndAsk;
use Tests\TestCase;

/**
 * §5 · ABSTAIN-AND-ASK — the honest frontier cerca. ANY uncertainty (ungrounded / low-confidence /
 * novel-without-precedent) forces an ABSTAIN that parks + asks the operator; a decision is only taken
 * autonomously when grounded AND confident AND not novel-without-precedent. The invariant: an abstain ALWAYS
 * carries an operator question (it NEVER fabricates a proceed).
 */
final class AtlasLoopAbstainAndAskTest extends TestCase
{
    private function gate(): AtlasLoopAbstainAndAsk
    {
        return new AtlasLoopAbstainAndAsk(confidenceFloor: 0.7);
    }

    public function test_ungrounded_decision_abstains_and_asks(): void
    {
        $v = $this->gate()->evaluate(['grounded' => false, 'confidence' => 0.99, 'summary' => 'cite AtlasLoopFoo']);
        $this->assertSame(AtlasLoopAbstainAndAsk::ACTION_ABSTAIN, $v['action']);
        $this->assertContains('ungrounded', $v['reasons']);
        $this->assertNotNull($v['operator_question'], 'an abstain ALWAYS asks');
        $this->assertStringContainsString('ABSTAINED', (string) $v['operator_question']);
    }

    public function test_low_confidence_abstains(): void
    {
        $v = $this->gate()->evaluate(['grounded' => true, 'confidence' => 0.4, 'has_precedent' => true]);
        $this->assertSame(AtlasLoopAbstainAndAsk::ACTION_ABSTAIN, $v['action']);
        $this->assertNotNull($v['operator_question']);
        $this->assertTrue((bool) preg_grep('/^low_confidence/', $v['reasons']), 'low-confidence reason present');
    }

    public function test_novel_without_precedent_abstains(): void
    {
        $v = $this->gate()->evaluate(['grounded' => true, 'confidence' => 0.95, 'novel' => true, 'has_precedent' => false, 'summary' => 'greenfield capability X']);
        $this->assertSame(AtlasLoopAbstainAndAsk::ACTION_ABSTAIN, $v['action']);
        $this->assertContains('novel_no_precedent', $v['reasons']);
        $this->assertNotNull($v['operator_question']);
    }

    public function test_grounded_confident_precedented_proceeds_autonomously(): void
    {
        $v = $this->gate()->evaluate(['grounded' => true, 'confidence' => 0.9, 'novel' => false, 'has_precedent' => true]);
        $this->assertSame(AtlasLoopAbstainAndAsk::ACTION_PROCEED, $v['action']);
        $this->assertNull($v['operator_question'], 'a confident grounded non-novel decision needs no operator');
        $this->assertSame([], $v['reasons']);
    }

    public function test_novelty_is_rescued_by_a_certified_precedent(): void
    {
        // novel BUT with a precedent + grounded + confident ⇒ the loop may proceed (precedent covers novelty).
        $v = $this->gate()->evaluate(['grounded' => true, 'confidence' => 0.85, 'novel' => true, 'has_precedent' => true]);
        $this->assertSame(AtlasLoopAbstainAndAsk::ACTION_PROCEED, $v['action']);
    }

    public function test_never_fabricates_an_uncertain_proceed(): void
    {
        // The anti-Goodhart invariant: across many uncertain inputs, an uncertain decision is NEVER a proceed.
        foreach ([
            ['grounded' => false, 'confidence' => 1.0],
            ['grounded' => true, 'confidence' => 0.0],
            ['grounded' => true, 'confidence' => 0.69],
            ['grounded' => true, 'confidence' => 0.8, 'novel' => true, 'has_precedent' => false],
        ] as $uncertain) {
            $v = $this->gate()->evaluate($uncertain);
            $this->assertSame(AtlasLoopAbstainAndAsk::ACTION_ABSTAIN, $v['action'], 'uncertain ⇒ abstain, never a faked proceed');
            $this->assertNotNull($v['operator_question']);
        }
    }

    // ── proceedOnGroundedNovelty: the autonomous-self-engineer directive (2026-06-22) ──────────────────────
    // Green scope = the TRIGGER to ORIGINATE a grounded novel leap, NOT to park-and-ask. The Goodhart floor
    // moves downstream (architect red→green + cert/refute prove materiality). Genuine ambiguity still abstains.

    private function originatingGate(): AtlasLoopAbstainAndAsk
    {
        return new AtlasLoopAbstainAndAsk(confidenceFloor: 0.7, proceedOnGroundedNovelty: true);
    }

    public function test_grounded_confident_novelty_PROCEEDS_when_originating(): void
    {
        // The exact case the default gate parks (novel + no precedent) — now the loop ORIGINATES it.
        $v = $this->originatingGate()->evaluate(['grounded' => true, 'confidence' => 0.95, 'novel' => true, 'has_precedent' => false, 'summary' => 'add capability X']);
        $this->assertSame(AtlasLoopAbstainAndAsk::ACTION_PROCEED, $v['action'], 'grounded+confident novelty ORIGINATES, not parks');
        $this->assertSame([], $v['reasons']);
        $this->assertNull($v['operator_question']);
    }

    public function test_originating_still_abstains_on_genuine_ambiguity(): void
    {
        // Originating mode relaxes ONLY novelty — ungrounded and low-confidence are real ambiguity and STILL park.
        $ungrounded = $this->originatingGate()->evaluate(['grounded' => false, 'confidence' => 0.99, 'novel' => true, 'has_precedent' => false]);
        $this->assertSame(AtlasLoopAbstainAndAsk::ACTION_ABSTAIN, $ungrounded['action'], 'ungrounded is genuine ambiguity → still abstain');
        $this->assertContains('ungrounded', $ungrounded['reasons']);

        $lowConf = $this->originatingGate()->evaluate(['grounded' => true, 'confidence' => 0.5, 'novel' => true, 'has_precedent' => false]);
        $this->assertSame(AtlasLoopAbstainAndAsk::ACTION_ABSTAIN, $lowConf['action'], 'low-confidence is genuine ambiguity → still abstain');
        $this->assertTrue((bool) preg_grep('/^low_confidence/', $lowConf['reasons']));
    }

    public function test_originating_flag_default_off_is_byte_identical(): void
    {
        // Default constructor (flag off) parks novelty exactly as before — the change is byte-identical when OFF.
        $input = ['grounded' => true, 'confidence' => 0.95, 'novel' => true, 'has_precedent' => false];
        $this->assertSame(AtlasLoopAbstainAndAsk::ACTION_ABSTAIN, $this->gate()->evaluate($input)['action']);
        $this->assertSame(AtlasLoopAbstainAndAsk::ACTION_PROCEED, $this->originatingGate()->evaluate($input)['action']);
    }
}
