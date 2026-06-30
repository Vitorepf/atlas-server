<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainGiveBackRootCauseMiner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainGiveBackRootCauseMinerTest extends TestCase
{
    private AtlasExternalBrainGiveBackRootCauseMiner $miner;

    protected function setUp(): void
    {
        $this->miner = new AtlasExternalBrainGiveBackRootCauseMiner;
    }

    private function mine(array $events): array
    {
        return $this->miner->mine($events);
    }

    // ── AC1: repeated same-shape give_backs grouped into root_causes ──────────

    public function test_scope_repair_class_produces_bad_allowed_files_root_cause(): void
    {
        $r = $this->mine([
            ['task_packet_id' => 'task-1', 'give_back_class' => 'scope_repair_guard', 'give_back_count' => 1],
        ]);

        $this->assertNotEmpty($r['root_causes']);
        $this->assertSame('bad_allowed_files', $r['root_causes'][0]['root_cause']);
        $this->assertSame(AtlasExternalBrainGiveBackRootCauseMiner::DEFECT_PACKET, $r['root_causes'][0]['defect_type']);
        $this->assertSame(AtlasExternalBrainGiveBackRootCauseMiner::ACTION_RESPEC, $r['root_causes'][0]['recommended_action']);
    }

    public function test_two_same_class_events_grouped_into_one_root_cause(): void
    {
        $r = $this->mine([
            ['task_packet_id' => 'task-1', 'give_back_class' => 'scope_repair_guard', 'give_back_count' => 1],
            ['task_packet_id' => 'task-2', 'give_back_class' => 'scope_repair_other', 'give_back_count' => 1],
        ]);

        // Both scope_repair_* map to bad_allowed_files
        $this->assertCount(1, $r['root_causes']);
        $this->assertCount(2, $r['root_causes'][0]['task_packet_ids']);
    }

    public function test_different_classes_produce_separate_root_causes(): void
    {
        $r = $this->mine([
            ['task_packet_id' => 'task-1', 'give_back_class' => 'scope_repair_x', 'give_back_count' => 1],
            ['task_packet_id' => 'task-2', 'give_back_class' => 'forbidden_file',  'give_back_count' => 1],
        ]);

        $causes = array_column($r['root_causes'], 'root_cause');
        $this->assertContains('bad_allowed_files', $causes);
        $this->assertContains('forbidden_target',  $causes);
    }

    public function test_contradictory_class_maps_to_contradictory_acceptance(): void
    {
        $r = $this->mine([
            ['task_packet_id' => 'task-3', 'give_back_class' => 'contradictory_acceptance', 'give_back_count' => 1],
        ]);

        $this->assertSame('contradictory_acceptance', $r['root_causes'][0]['root_cause']);
    }

    public function test_duplicate_class_maps_to_cancel_action(): void
    {
        $r = $this->mine([
            ['task_packet_id' => 'task-4', 'give_back_class' => 'duplicate_scope', 'give_back_count' => 1],
        ]);

        $this->assertSame(AtlasExternalBrainGiveBackRootCauseMiner::ACTION_CANCEL, $r['root_causes'][0]['recommended_action']);
    }

    public function test_worker_weakness_class_produces_worker_defect_type(): void
    {
        $r = $this->mine([
            ['task_packet_id' => 'task-5', 'give_back_class' => 'context_overflow', 'give_back_count' => 1],
        ]);

        $this->assertSame(AtlasExternalBrainGiveBackRootCauseMiner::DEFECT_WORKER, $r['root_causes'][0]['defect_type']);
        $this->assertSame(AtlasExternalBrainGiveBackRootCauseMiner::ACTION_RETRY_DIFFERENT_WORKER, $r['root_causes'][0]['recommended_action']);
    }

    // ── AC2: poison packet ids surfaced above threshold ────────────────────────

    public function test_packet_defect_with_count_above_threshold_is_poison(): void
    {
        $r = $this->mine([
            ['task_packet_id' => 'poison-1', 'give_back_class' => 'scope_repair_x',
             'give_back_count' => AtlasExternalBrainGiveBackRootCauseMiner::POISON_THRESHOLD],
        ]);

        $this->assertContains('poison-1', $r['poison_packets']);
    }

    public function test_packet_defect_below_threshold_is_not_poison(): void
    {
        $r = $this->mine([
            ['task_packet_id' => 'ok-task', 'give_back_class' => 'scope_repair_x', 'give_back_count' => 1],
        ]);

        $this->assertNotContains('ok-task', $r['poison_packets']);
    }

    public function test_worker_weakness_is_never_poison(): void
    {
        // Worker weakness with high count: not a packet defect → never poison
        $r = $this->mine([
            ['task_packet_id' => 'ctx-task', 'give_back_class' => 'context_overflow', 'give_back_count' => 99],
        ]);

        $this->assertNotContains('ctx-task', $r['poison_packets']);
    }

    public function test_multiple_poisons_all_surfaced(): void
    {
        $r = $this->mine([
            ['task_packet_id' => 'p1', 'give_back_class' => 'scope_repair_x', 'give_back_count' => 3],
            ['task_packet_id' => 'p2', 'give_back_class' => 'forbidden_guard', 'give_back_count' => 5],
        ]);

        $this->assertContains('p1', $r['poison_packets']);
        $this->assertContains('p2', $r['poison_packets']);
    }

    // ── AC3: recommendations explain why retry_unchanged is unsafe ────────────

    public function test_respec_plan_includes_why_not_retry_unchanged(): void
    {
        $r = $this->mine([
            ['task_packet_id' => 'task-1', 'give_back_class' => 'scope_repair_x', 'give_back_count' => 1],
        ]);

        $plan = $r['root_causes'][0]['respec_plan'];
        $this->assertArrayHasKey('why_not_retry_unchanged', $plan);
        $this->assertNotEmpty($plan['why_not_retry_unchanged']);
    }

    public function test_duplicate_class_explains_why_not_retry(): void
    {
        $r = $this->mine([
            ['task_packet_id' => 'task-6', 'give_back_class' => 'duplicate_impl', 'give_back_count' => 1],
        ]);

        $reason = $r['root_causes'][0]['respec_plan']['why_not_retry_unchanged'];
        $this->assertStringContainsString('already implemented', $reason);
    }

    public function test_contradictory_acceptance_explains_why_not_retry(): void
    {
        $r = $this->mine([
            ['task_packet_id' => 'task-7', 'give_back_class' => 'contradict_something', 'give_back_count' => 1],
        ]);

        $reason = $r['root_causes'][0]['respec_plan']['why_not_retry_unchanged'];
        $this->assertStringContainsString('impossible', $reason);
    }

    // ── AC4: deterministic, does not modify queue packets ─────────────────────

    public function test_output_is_deterministic(): void
    {
        $events = [
            ['task_packet_id' => 'x', 'give_back_class' => 'scope_repair_a', 'give_back_count' => 2],
        ];

        $this->assertSame(json_encode($this->mine($events)), json_encode($this->mine($events)));
    }

    public function test_schema_is_set(): void
    {
        $r = $this->mine([]);

        $this->assertSame(AtlasExternalBrainGiveBackRootCauseMiner::SCHEMA, $r['schema']);
    }

    public function test_empty_input_returns_empty_root_causes_and_poisons(): void
    {
        $r = $this->mine([]);

        $this->assertSame([], $r['root_causes']);
        $this->assertSame([], $r['poison_packets']);
    }
}
