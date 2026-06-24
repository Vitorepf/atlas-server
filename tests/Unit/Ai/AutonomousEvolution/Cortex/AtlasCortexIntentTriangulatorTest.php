<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\AtlasCortexIntentTriangulator;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\DecisionHistoryFact;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\IntentExtractionFact;
use PHPUnit\Framework\TestCase;

/**
 * Proves the intent triangulator: no fabrication when every leg is empty, the >=70 confidence floor requires
 * two agreeing legs, and a conflict is recorded when the docblock purpose is disjoint from the most-recent
 * decision keyword set.
 */
final class AtlasCortexIntentTriangulatorTest extends TestCase
{
    private function triangulator(): AtlasCortexIntentTriangulator
    {
        return new AtlasCortexIntentTriangulator;
    }

    /** @param list<array{sha:string, subject:string, decided_on:int, keyword_hit:bool}> $decisions */
    private function history(array $decisions): DecisionHistoryFact
    {
        return new DecisionHistoryFact('App\\X', 'app/X.php', count($decisions), $decisions);
    }

    public function test_no_fabrication_when_all_legs_empty(): void
    {
        $fact = $this->triangulator()->triangulate('App\\X', null, null, []);

        $this->assertNull($fact->purposeStatement);
        $this->assertSame(0, $fact->confidenceScore);
        $this->assertSame([], $fact->conflicts);
    }

    public function test_doc_path_without_purpose_or_history_stays_below_floor(): void
    {
        $extractor = new IntentExtractionFact('App\\X', null, null, 'docs/x.md', null, 'low'); // docPath only, no purpose

        $fact = $this->triangulator()->triangulate('App\\X', $extractor, null, []);

        $this->assertLessThan(70, $fact->confidenceScore, 'a doc path with no purpose tokens is not evidence');
        $this->assertNull($fact->purposeStatement, 'no purpose tokens ⇒ no fabricated statement');
    }

    public function test_two_agreeing_legs_reach_the_confidence_floor(): void
    {
        $extractor = new IntentExtractionFact('App\\X', 'a durable retry budget for provider calls', null, 'docs/x.md', null, 'high');
        $history = $this->history([
            ['sha' => 'aaa', 'subject' => 'add retry budget guard for providers', 'decided_on' => 100, 'keyword_hit' => true],
            ['sha' => 'bbb', 'subject' => 'older unrelated change', 'decided_on' => 10, 'keyword_hit' => false],
        ]);

        $fact = $this->triangulator()->triangulate('App\\X', $extractor, $history, []);

        $this->assertGreaterThanOrEqual(70, $fact->confidenceScore, 'extractor + history agree (retry/budget) ⇒ >=70');
        $this->assertSame('a durable retry budget for provider calls', $fact->purposeStatement);
        $this->assertSame([], $fact->conflicts);
    }

    public function test_conflict_when_purpose_disjoint_from_recent_decision(): void
    {
        $extractor = new IntentExtractionFact('App\\X', 'circuit breaker for the lease registry', null, null, null, 'high');
        $history = $this->history([
            ['sha' => 'ccc', 'subject' => 'tweak whitespace formatting indentation', 'decided_on' => 200, 'keyword_hit' => false],
        ]);

        $fact = $this->triangulator()->triangulate('App\\X', $extractor, $history, []);

        $this->assertContains('extractor_purpose_disjoint_from_recent_decision', $fact->conflicts);
        $this->assertLessThan(70, $fact->confidenceScore, 'disjoint legs do not agree ⇒ below floor');
    }
}
