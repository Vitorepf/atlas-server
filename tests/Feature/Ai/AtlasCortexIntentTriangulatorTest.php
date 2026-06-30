<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\AtlasCortexIntentTriangulator;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\DecisionHistoryFact;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\IntentExtractionFact;
use PHPUnit\Framework\TestCase;

final class AtlasCortexIntentTriangulatorTest extends TestCase
{
    private function triangulator(int $overlapThreshold = 1, int $agreementFloor = 70): AtlasCortexIntentTriangulator
    {
        return new AtlasCortexIntentTriangulator($overlapThreshold, $agreementFloor);
    }

    private function extractor(string $purpose): IntentExtractionFact
    {
        return new IntentExtractionFact('Foo', $purpose, null, null, null, 'high');
    }

    private function history(string $subject, int $decidedOn = 1000): DecisionHistoryFact
    {
        return new DecisionHistoryFact('Foo', 'app/Foo.php', 1, [
            ['sha' => 'abc', 'subject' => $subject, 'decided_on' => $decidedOn, 'keyword_hit' => true],
        ]);
    }

    // ── AC2: no evidence leg → null purpose + confidence 0 ───────────────────

    public function test_no_evidence_legs_return_null_purpose_and_zero_confidence(): void
    {
        $fact = $this->triangulator()->triangulate('Foo', null, null, []);

        $this->assertNull($fact->purposeStatement);
        $this->assertSame(0, $fact->confidenceScore);
    }

    public function test_extractor_with_empty_purpose_is_treated_as_no_leg(): void
    {
        $fact = $this->triangulator()->triangulate('Foo', $this->extractor(''), null, []);

        $this->assertNull($fact->purposeStatement);
        $this->assertSame(0, $fact->confidenceScore);
    }

    public function test_single_leg_produces_low_confidence_below_agreement_floor(): void
    {
        $fact = $this->triangulator()->triangulate('Foo', $this->extractor('cache resolution service'), null, []);

        // One leg only → unconfirmed presence (40)
        $this->assertNotNull($fact->purposeStatement);
        $this->assertLessThan(70, $fact->confidenceScore);
    }

    // ── AC3: ≥2 legs sharing tokens → confidence ≥ agreement floor ───────────

    public function test_two_agreeing_legs_reach_agreement_floor(): void
    {
        // Both legs have "cache" token → overlap ≥ 1
        $fact = $this->triangulator()->triangulate(
            'Foo',
            $this->extractor('cache resolution service'),
            $this->history('cache write operation'),
        );

        $this->assertGreaterThanOrEqual(70, $fact->confidenceScore);
    }

    public function test_three_agreeing_legs_exceed_agreement_floor(): void
    {
        $fact = $this->triangulator()->triangulate(
            'Foo',
            $this->extractor('cache resolution service'),
            $this->history('cache write operation'),
            [['purpose' => 'caches resolved values']],
        );

        $this->assertGreaterThanOrEqual(70, $fact->confidenceScore);
    }

    public function test_disjoint_legs_do_not_reach_agreement_floor(): void
    {
        // "cache resolution" vs "delete entity" — no shared tokens
        $fact = $this->triangulator()->triangulate(
            'Foo',
            $this->extractor('cache resolution service'),
            $this->history('delete entity records'),
        );

        $this->assertLessThan(70, $fact->confidenceScore);
    }

    // ── AC4: disjoint extractor/history tokens → conflict emitted ────────────

    public function test_disjoint_extractor_and_history_emits_conflict(): void
    {
        $fact = $this->triangulator()->triangulate(
            'Foo',
            $this->extractor('cache resolution service'),
            $this->history('delete entity records'),
        );

        $this->assertContains('extractor_purpose_disjoint_from_recent_decision', $fact->conflicts);
    }

    public function test_overlapping_extractor_and_history_does_not_emit_conflict(): void
    {
        $fact = $this->triangulator()->triangulate(
            'Foo',
            $this->extractor('cache resolution service'),
            $this->history('cache write operation'),
        );

        $this->assertNotContains('extractor_purpose_disjoint_from_recent_decision', $fact->conflicts);
    }

    public function test_missing_extractor_does_not_emit_disjoint_conflict(): void
    {
        $fact = $this->triangulator()->triangulate(
            'Foo',
            null,
            $this->history('delete entity records'),
        );

        $this->assertNotContains('extractor_purpose_disjoint_from_recent_decision', $fact->conflicts);
    }

    public function test_missing_history_does_not_emit_disjoint_conflict(): void
    {
        $fact = $this->triangulator()->triangulate(
            'Foo',
            $this->extractor('cache resolution service'),
            null,
        );

        $this->assertNotContains('extractor_purpose_disjoint_from_recent_decision', $fact->conflicts);
    }
}
