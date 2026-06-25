<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Quaternity\IntentResolver;

use App\Services\Ai\AutonomousEvolution\Quaternity\IntentResolver\AtlasLoopIntentAmbiguityFollowUpScheduler;
use Tests\TestCase;

class AtlasLoopIntentAmbiguityFollowUpSchedulerTest extends TestCase
{
    private function intents(): array
    {
        return [
            [
                'intent_id' => 'i-1',
                'capture_at_utc' => '2026-06-25T00:00:02Z',
                'ambiguities' => [
                    ['ambiguity_finding_id' => 'f1', 'question_hash' => 'qh-1'],
                    ['ambiguity_finding_id' => 'f2', 'question_hash' => 'qh-2'],
                ],
            ],
            [
                'intent_id' => 'i-2',
                'capture_at_utc' => '2026-06-25T00:00:01Z',
                'ambiguities' => [
                    ['ambiguity_finding_id' => 'f3', 'question_hash' => 'qh-3'],
                ],
            ],
        ];
    }

    public function test_pending_is_set_difference_with_deterministic_sort(): void
    {
        $scheduler = new AtlasLoopIntentAmbiguityFollowUpScheduler(
            fn (): array => $this->intents(),
            fn (): array => ['qh-1'], // qh-1 resolved
        );

        $pending = $scheduler->pending();

        self::assertCount(2, $pending);
        // Sort: capture_at_utc ASC; i-2 captured earlier than i-1.
        self::assertSame('i-2', $pending[0]['intent_id']);
        self::assertSame('i-1', $pending[1]['intent_id']);
        self::assertSame('f3', $pending[0]['ambiguity_finding_id']);
        self::assertSame('f2', $pending[1]['ambiguity_finding_id']);
    }

    public function test_is_awaiting_clarification_true_until_all_resolved(): void
    {
        $scheduler = new AtlasLoopIntentAmbiguityFollowUpScheduler(
            fn (): array => $this->intents(),
            fn (): array => ['qh-1'],
        );

        self::assertTrue($scheduler->isAwaitingClarification('i-1'));
        self::assertTrue($scheduler->isAwaitingClarification('i-2'));

        $allResolved = new AtlasLoopIntentAmbiguityFollowUpScheduler(
            fn (): array => $this->intents(),
            fn (): array => ['qh-1', 'qh-2', 'qh-3'],
        );

        self::assertFalse($allResolved->isAwaitingClarification('i-1'));
        self::assertFalse($allResolved->isAwaitingClarification('i-2'));
    }

    public function test_pending_for_intent_filters_by_intent_id(): void
    {
        $scheduler = new AtlasLoopIntentAmbiguityFollowUpScheduler(
            fn (): array => $this->intents(),
            fn (): array => [],
        );

        $i1 = $scheduler->pendingForIntent('i-1');
        self::assertCount(2, $i1);
        foreach ($i1 as $row) {
            self::assertSame('i-1', $row['intent_id']);
        }
        self::assertCount(1, $scheduler->pendingForIntent('i-2'));
        self::assertCount(0, $scheduler->pendingForIntent('never-seen'));
    }

    public function test_two_calls_with_identical_fixtures_are_byte_identical(): void
    {
        $scheduler = new AtlasLoopIntentAmbiguityFollowUpScheduler(
            fn (): array => $this->intents(),
            fn (): array => ['qh-1'],
        );

        $a = $scheduler->pending();
        $b = $scheduler->pending();

        self::assertSame(json_encode($a), json_encode($b));
    }

    public function test_scheduler_does_not_call_detector_proposer_or_provider_symbols(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/AutonomousEvolution/Quaternity/IntentResolver/AtlasLoopIntentAmbiguityFollowUpScheduler.php'));
        foreach (['Detector', 'Proposer', '->compose(', 'Http::', 'curl_', 'Provider'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $src, "scheduler must not reference {$forbidden}");
        }
    }

    public function test_dont_know_resolution_still_counts_as_resolved(): void
    {
        // A "don't know" resolution still emits a ledger row whose question_hash is in the resolved set.
        $scheduler = new AtlasLoopIntentAmbiguityFollowUpScheduler(
            fn (): array => $this->intents(),
            fn (): array => ['qh-1', 'qh-2', 'qh-3'],
        );

        self::assertSame([], $scheduler->pending());
    }
}
