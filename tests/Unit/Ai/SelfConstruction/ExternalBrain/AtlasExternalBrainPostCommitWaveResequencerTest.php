<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPostCommitWaveResequencer;
use Tests\TestCase;

final class AtlasExternalBrainPostCommitWaveResequencerTest extends TestCase
{
    private function resequencer(): AtlasExternalBrainPostCommitWaveResequencer
    {
        return new AtlasExternalBrainPostCommitWaveResequencer;
    }

    // ── AC: a successful prerequisite commit unlocks dependent wave entries ────

    public function test_unblocked_by_commit_task_is_prioritized(): void
    {
        $result = $this->resequencer()->resequence([
            'previous_wave' => ['task_ids' => ['a', 'b']],
            'queue_tasks' => [
                ['task_id' => 'a'],
                ['task_id' => 'b', 'unblocked_by_latest_commit' => true],
            ],
        ]);

        $this->assertSame('b', $result['resequenced_task_ids'][0]);
        $this->assertContains('b', $result['newly_unblocked_task_ids']);
    }

    // ── AC: successors superseded by the commit are marked retire_or_respec ────

    public function test_superseded_successor_is_marked_retire_or_respec(): void
    {
        $result = $this->resequencer()->resequence([
            'previous_wave' => ['task_ids' => ['a', 'b']],
            'queue_tasks' => [
                ['task_id' => 'a', 'superseded_by_commit' => true],
                ['task_id' => 'b'],
            ],
        ]);

        $this->assertContains('a', $result['retire_or_respec_task_ids']);
        $this->assertStringContainsString('retire_or_respec:a', implode(' ', $result['resequence_reasons']));
    }

    public function test_non_superseded_removals_are_not_marked_retire_or_respec(): void
    {
        $result = $this->resequencer()->resequence([
            'previous_wave' => ['task_ids' => ['a', 'b']],
            'queue_tasks' => [
                ['task_id' => 'a', 'give_back_repeat_count' => 5],
                ['task_id' => 'b'],
            ],
        ]);

        $this->assertNotContains('a', $result['retire_or_respec_task_ids']);
        $this->assertSame([], $result['retire_or_respec_task_ids']);
    }

    // ── AC: resequencing output includes next_wave_order and reasons ───────────

    public function test_output_includes_next_wave_order_and_reasons(): void
    {
        $result = $this->resequencer()->resequence([
            'previous_wave' => ['task_ids' => ['a', 'b']],
            'queue_tasks' => [
                ['task_id' => 'a'],
                ['task_id' => 'b'],
            ],
        ]);

        $this->assertArrayHasKey('next_wave_order', $result);
        $this->assertArrayHasKey('reasons', $result);
        $this->assertSame($result['resequenced_task_ids'], $result['next_wave_order']);
        $this->assertSame($result['resequence_reasons'], $result['reasons']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->resequencer()->resequence([]);

        $this->assertSame(AtlasExternalBrainPostCommitWaveResequencer::SCHEMA, $result['schema_version']);
        foreach ([
            'resequenced_task_ids', 'next_wave_order', 'removed_stale_task_ids',
            'retire_or_respec_task_ids', 'newly_unblocked_task_ids', 'resequence_reasons',
            'reasons', 'mutates_queue',
        ] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertFalse($result['mutates_queue']);
    }
}
