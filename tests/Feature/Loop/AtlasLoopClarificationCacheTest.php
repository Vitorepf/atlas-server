<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopClarificationRequest;
use App\Services\Ai\AutonomousEvolution\AtlasLoopObraExecutionAdapter;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopVaguenessPreScreener;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ACDE U6 — the clarification ANSWER CACHE. Before screening/planning, a goal the operator already answered
 * (U5 queue, status=answered) has the answer folded back in, so the loop never re-asks and plans WITH the
 * missing anchor. Default OFF => goal unchanged => byte-identical.
 */
final class AtlasLoopClarificationCacheTest extends TestCase
{
    private const GOAL = 'Make the loop better somehow';

    private const ANSWER = 'in app/Services/Ai/Foo.php reduce the worst-method cyclomatic complexity';

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_clarification_requests')) {
            (require base_path('database/migrations/2026_06_17_000200_create_atlas_loop_clarification_requests_table.php'))->up();
        }
    }

    private function adapter(): AtlasLoopObraExecutionAdapter
    {
        return new class extends AtlasLoopObraExecutionAdapter
        {
            public function clarify(string $goal): string
            {
                return $this->withCachedClarification($goal);
            }
        };
    }

    private function answeredRequest(string $answer = self::ANSWER): void
    {
        AtlasLoopClarificationRequest::create([
            'goal_fingerprint' => AtlasLoopClarificationRequest::fingerprint(self::GOAL),
            'goal' => self::GOAL,
            'reason' => 'vague_goal_no_anchor',
            'status' => AtlasLoopClarificationRequest::STATUS_ANSWERED,
            'answer' => $answer,
            'answered_at' => now(),
        ]);
    }

    public function test_cached_answer_for_returns_only_an_answered_match(): void
    {
        $this->assertNull(AtlasLoopClarificationRequest::cachedAnswerFor(self::GOAL), 'unseen goal => no cache');

        // A pending request is NOT a cached answer.
        AtlasLoopClarificationRequest::enqueue(['reason' => 'vague_goal_no_anchor'], self::GOAL);
        $this->assertNull(AtlasLoopClarificationRequest::cachedAnswerFor(self::GOAL), 'pending => no cached answer');

        // The operator answers THAT pending row (same fingerprint+reason — no duplicate).
        AtlasLoopClarificationRequest::query()->firstOrFail()->update([
            'status' => AtlasLoopClarificationRequest::STATUS_ANSWERED,
            'answer' => self::ANSWER,
            'answered_at' => now(),
        ]);
        $this->assertSame(self::ANSWER, AtlasLoopClarificationRequest::cachedAnswerFor(self::GOAL));
        // Case/space-insensitive (same fingerprint).
        $this->assertSame(self::ANSWER, AtlasLoopClarificationRequest::cachedAnswerFor('  make THE loop better somehow '));
        // A different goal is not matched.
        $this->assertNull(AtlasLoopClarificationRequest::cachedAnswerFor('some entirely different goal'));
    }

    public function test_off_leaves_the_goal_unchanged_byte_identical(): void
    {
        config(['atlas.loop.clarification_cache_enabled' => false]);
        $this->answeredRequest();

        $this->assertSame(self::GOAL, $this->adapter()->clarify(self::GOAL), 'OFF must never fold the cache in');
    }

    public function test_armed_folds_the_cached_answer_into_the_goal(): void
    {
        config(['atlas.loop.clarification_cache_enabled' => true]);
        $this->answeredRequest();

        $this->assertSame(self::GOAL.' '.self::ANSWER, $this->adapter()->clarify(self::GOAL));
    }

    public function test_armed_with_no_answer_leaves_the_goal_unchanged(): void
    {
        config(['atlas.loop.clarification_cache_enabled' => true]);
        AtlasLoopClarificationRequest::enqueue(['reason' => 'vague_goal_no_anchor'], self::GOAL); // pending only

        $this->assertSame(self::GOAL, $this->adapter()->clarify(self::GOAL), 'a pending (un-answered) request must not fold in');
    }

    public function test_the_fold_de_vagueifies_the_goal_for_the_u4_screen(): void
    {
        // The whole point: the raw goal is VAGUE (U4 would abstain); folding the operator's anchored answer
        // in makes it pass the same screen — so on the next pass the loop plans instead of re-asking.
        config(['atlas.loop.clarification_cache_enabled' => true]);
        $this->answeredRequest();
        $screener = new AtlasLoopVaguenessPreScreener;

        $this->assertTrue($screener->screen(self::GOAL)['vague'], 'the raw goal has no anchor');
        $folded = $this->adapter()->clarify(self::GOAL);
        $this->assertFalse($screener->screen($folded)['vague'], 'the folded answer supplies the anchor');
    }
}
