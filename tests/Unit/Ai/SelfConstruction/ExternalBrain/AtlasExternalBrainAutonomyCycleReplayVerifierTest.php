<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAutonomyCycleReplayVerifier;
use Tests\TestCase;

final class AtlasExternalBrainAutonomyCycleReplayVerifierTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function completeStream(string $nextLever = 'increase_depth'): array
    {
        return [
            ['stage' => 'lever_chosen', 'sequence' => 1, 'lever' => 'widen_breadth'],
            ['stage' => 'packet_emitted', 'sequence' => 2],
            ['stage' => 'muscle_outcome', 'sequence' => 3, 'outcome' => 'success'],
            ['stage' => 'gates_judged', 'sequence' => 4],
            ['stage' => 'outcome_learning', 'sequence' => 5],
            ['stage' => 'next_decision', 'sequence' => 6, 'lever' => $nextLever],
        ];
    }

    public function test_complete_ordered_stream_returns_cycle_complete_with_all_six_stages(): void
    {
        $result = (new AtlasExternalBrainAutonomyCycleReplayVerifier)->verify($this->completeStream());

        $this->assertTrue($result['cycle_complete']);
        $this->assertNull($result['missing_stage']);
        $this->assertSame(AtlasExternalBrainAutonomyCycleReplayVerifier::REQUIRED_STAGES, $result['stages_observed']);
        $this->assertSame([], $result['ordering_violations']);
    }

    public function test_missing_outcome_learning_returns_incomplete_with_exact_missing_stage(): void
    {
        $facts = array_values(array_filter(
            $this->completeStream(),
            static fn (array $f): bool => $f['stage'] !== 'outcome_learning',
        ));

        $result = (new AtlasExternalBrainAutonomyCycleReplayVerifier)->verify($facts);

        $this->assertFalse($result['cycle_complete']);
        $this->assertSame('outcome_learning', $result['missing_stage']);
    }

    public function test_unchanged_next_decision_returns_incomplete_with_next_decision_as_missing_stage(): void
    {
        // next_decision fact IS present, but its lever is identical to lever_chosen's — no real change.
        $result = (new AtlasExternalBrainAutonomyCycleReplayVerifier)->verify($this->completeStream('widen_breadth'));

        $this->assertFalse($result['cycle_complete']);
        $this->assertSame('next_decision', $result['missing_stage']);
        $this->assertFalse($result['decision_changed']);
    }

    public function test_give_back_outcome_can_still_complete_the_cycle_when_decision_routes_to_repair(): void
    {
        $facts = $this->completeStream('repair_blocked_chain');
        foreach ($facts as &$fact) {
            if ($fact['stage'] === 'muscle_outcome') {
                $fact['outcome'] = 'give_back';
            }
        }
        unset($fact);

        $result = (new AtlasExternalBrainAutonomyCycleReplayVerifier)->verify($facts);

        $this->assertTrue($result['cycle_complete']);
        $this->assertTrue($result['decision_changed']);
    }

    public function test_out_of_order_facts_are_reported_as_ordering_violations(): void
    {
        $facts = [
            ['stage' => 'lever_chosen', 'sequence' => 1, 'lever' => 'widen_breadth'],
            // gates_judged arrives (sequence 2) BEFORE muscle_outcome (sequence 5) even though
            // muscle_outcome must precede gates_judged in the required cycle order.
            ['stage' => 'packet_emitted', 'sequence' => 2],
            ['stage' => 'gates_judged', 'sequence' => 3],
            ['stage' => 'muscle_outcome', 'sequence' => 4, 'outcome' => 'success'],
            ['stage' => 'outcome_learning', 'sequence' => 5],
            ['stage' => 'next_decision', 'sequence' => 6, 'lever' => 'increase_depth'],
        ];

        $result = (new AtlasExternalBrainAutonomyCycleReplayVerifier)->verify($facts);

        $this->assertNotEmpty($result['ordering_violations']);
        // gates_judged's own sequence (3) is earlier than muscle_outcome's (4), even though
        // muscle_outcome must precede gates_judged in the required cycle order — gates_judged is
        // the stage flagged as arriving too early.
        $this->assertSame('gates_judged', $result['ordering_violations'][0]['stage']);
        $this->assertSame('muscle_outcome', $result['ordering_violations'][0]['expected_after']);
        $this->assertFalse($result['cycle_complete'], 'an ordering violation must not be silently accepted as a complete cycle');
    }

    public function test_empty_stream_is_incomplete_with_first_stage_missing(): void
    {
        $result = (new AtlasExternalBrainAutonomyCycleReplayVerifier)->verify([]);

        $this->assertFalse($result['cycle_complete']);
        $this->assertSame('lever_chosen', $result['missing_stage']);
        $this->assertSame([], $result['stages_observed']);
    }

    public function test_result_is_deterministic_for_identical_input(): void
    {
        $verifier = new AtlasExternalBrainAutonomyCycleReplayVerifier;
        $facts = $this->completeStream();

        $this->assertSame($verifier->verify($facts), $verifier->verify($facts));
    }
}
