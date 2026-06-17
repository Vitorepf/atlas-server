<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopClarificationRequest;
use App\Services\Ai\AutonomousEvolution\AtlasLoopObraExecutionAdapter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ACDE U5 — the operator CLARIFICATION QUEUE. When the structured planner abstains (vague goal / un-ready
 * spec / ill-formed DAG) the loop enqueues a pending clarification request instead of silently falling back
 * to the dumb one-shot. Default OFF => no row => byte-identical. De-duped on goal_fingerprint+reason; an
 * operator's answered/dismissed resolution is sticky across recurrences.
 */
final class AtlasLoopClarificationQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_clarification_requests')) {
            (require base_path('database/migrations/2026_06_17_000200_create_atlas_loop_clarification_requests_table.php'))->up();
        }
    }

    /** Anonymous subclass exposing the protected abstention emitter (the live wiring under test). */
    private function adapter(): AtlasLoopObraExecutionAdapter
    {
        return new class extends AtlasLoopObraExecutionAdapter
        {
            public function abstain(array $payload, string $reason): void
            {
                $this->emitPlanAbstention($payload, $reason);
            }
        };
    }

    private function payload(): array
    {
        return ['objective' => 'Make the loop better somehow', 'objective_kind' => 'refactor_extract_class'];
    }

    public function test_off_enqueues_nothing_byte_identical(): void
    {
        config(['atlas.loop.clarification_queue_enabled' => false]);

        $this->adapter()->abstain($this->payload(), 'vague_goal_no_anchor');

        $this->assertSame(0, AtlasLoopClarificationRequest::query()->count(), 'OFF must never enqueue a clarification');
    }

    public function test_armed_enqueues_a_pending_request_with_the_abstention_fields(): void
    {
        config(['atlas.loop.clarification_queue_enabled' => true]);

        $this->adapter()->abstain($this->payload(), 'vague_goal_no_anchor');

        $row = AtlasLoopClarificationRequest::query()->firstOrFail();
        $this->assertSame('Make the loop better somehow', $row->goal);
        $this->assertSame('refactor_extract_class', $row->objective_kind);
        $this->assertSame('refactor', $row->family);
        $this->assertSame('vague_goal_no_anchor', $row->reason);
        $this->assertSame(AtlasLoopClarificationRequest::STATUS_PENDING, $row->status);
        $this->assertSame(1, $row->times_seen);
        $this->assertSame(AtlasLoopClarificationRequest::fingerprint('Make the loop better somehow'), $row->goal_fingerprint);
    }

    public function test_recurrence_dedupes_and_increments_times_seen(): void
    {
        config(['atlas.loop.clarification_queue_enabled' => true]);

        $this->adapter()->abstain($this->payload(), 'vague_goal_no_anchor');
        $this->adapter()->abstain($this->payload(), 'vague_goal_no_anchor');

        $this->assertSame(1, AtlasLoopClarificationRequest::query()->count(), 'same (goal,reason) de-dupes onto one row');
        $this->assertSame(2, AtlasLoopClarificationRequest::query()->firstOrFail()->times_seen);
    }

    public function test_a_different_reason_for_the_same_goal_is_a_distinct_row(): void
    {
        config(['atlas.loop.clarification_queue_enabled' => true]);

        $this->adapter()->abstain($this->payload(), 'spec_not_ready');
        $this->adapter()->abstain($this->payload(), 'plan_not_ready');

        $this->assertSame(2, AtlasLoopClarificationRequest::query()->count(), 'distinct reasons => distinct requests');
    }

    public function test_an_answered_resolution_is_sticky_across_recurrences(): void
    {
        config(['atlas.loop.clarification_queue_enabled' => true]);
        $this->adapter()->abstain($this->payload(), 'vague_goal_no_anchor');

        $row = AtlasLoopClarificationRequest::query()->firstOrFail();
        $row->update(['status' => AtlasLoopClarificationRequest::STATUS_ANSWERED, 'answer' => 'reduce cyclomatic of X']);

        // The same abstention recurs — it must NOT reopen the answered request.
        $this->adapter()->abstain($this->payload(), 'vague_goal_no_anchor');

        $reloaded = AtlasLoopClarificationRequest::query()->firstOrFail();
        $this->assertSame(AtlasLoopClarificationRequest::STATUS_ANSWERED, $reloaded->status, 'an operator answer must be sticky');
        $this->assertSame('reduce cyclomatic of X', $reloaded->answer);
        $this->assertSame(2, $reloaded->times_seen, 'recurrence still counts');
    }

    public function test_fingerprint_is_case_and_space_insensitive_and_enqueue_guards_empty(): void
    {
        $a = AtlasLoopClarificationRequest::fingerprint('  Make The Loop Better Somehow ');
        $b = AtlasLoopClarificationRequest::fingerprint('make the loop better somehow');
        $this->assertSame($a, $b, 'normalized goals share a fingerprint (so re-asks de-dupe and U6 can cache-match)');

        $this->assertNull(AtlasLoopClarificationRequest::enqueue(['reason' => 'vague_goal_no_anchor'], '   '), 'empty goal => no enqueue');
        $this->assertNull(AtlasLoopClarificationRequest::enqueue(['reason' => ''], 'a real goal'), 'empty reason => no enqueue');
    }
}
