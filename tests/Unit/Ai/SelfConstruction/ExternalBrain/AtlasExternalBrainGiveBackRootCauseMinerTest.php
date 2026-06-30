<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainGiveBackRootCauseMiner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainGiveBackRootCauseMinerTest extends TestCase
{
    private AtlasExternalBrainGiveBackRootCauseMiner $miner;

    protected function setUp(): void
    {
        $this->miner = new AtlasExternalBrainGiveBackRootCauseMiner;
    }

    // ── empty input ───────────────────────────────────────────────────────────

    public function test_empty_input_returns_empty_result(): void
    {
        $r = $this->miner->mine([]);
        $this->assertSame(AtlasExternalBrainGiveBackRootCauseMiner::SCHEMA, $r['schema']);
        $this->assertSame([], $r['root_causes']);
        $this->assertSame([], $r['poison_packets']);
    }

    // ── root cause classification ─────────────────────────────────────────────

    public function test_scope_repair_class_maps_to_bad_allowed_files_respec(): void
    {
        $r = $this->miner->mine([[
            'task_packet_id' => 'task-scope-01',
            'give_back_class' => 'scope_repair_missing_impl',
            'give_back_count' => 3,
        ]]);

        $cause = $r['root_causes'][0];
        $this->assertSame('bad_allowed_files', $cause['root_cause']);
        $this->assertSame(AtlasExternalBrainGiveBackRootCauseMiner::DEFECT_PACKET, $cause['defect_type']);
        $this->assertSame(AtlasExternalBrainGiveBackRootCauseMiner::ACTION_RESPEC, $cause['recommended_action']);
        $this->assertContains('task-scope-01', $cause['task_packet_ids']);
    }

    public function test_forbidden_class_maps_to_forbidden_target_respec(): void
    {
        $r = $this->miner->mine([[
            'task_packet_id' => 'task-forbidden-01',
            'give_back_class' => 'forbidden_file',
            'give_back_count' => 2,
        ]]);

        $cause = $r['root_causes'][0];
        $this->assertSame('forbidden_target', $cause['root_cause']);
        $this->assertSame(AtlasExternalBrainGiveBackRootCauseMiner::ACTION_RESPEC, $cause['recommended_action']);
    }

    public function test_contradictory_class_maps_to_contradictory_acceptance_respec(): void
    {
        $r = $this->miner->mine([[
            'task_packet_id' => 'task-contra-01',
            'give_back_class' => 'contradictory_acceptance',
            'give_back_count' => 2,
        ]]);

        $cause = $r['root_causes'][0];
        $this->assertSame('contradictory_acceptance', $cause['root_cause']);
        $this->assertSame(AtlasExternalBrainGiveBackRootCauseMiner::ACTION_RESPEC, $cause['recommended_action']);
    }

    public function test_schema_class_maps_to_missing_schema_respec(): void
    {
        $r = $this->miner->mine([[
            'task_packet_id' => 'task-schema-01',
            'give_back_class' => 'schema_missing',
            'give_back_count' => 2,
        ]]);

        $cause = $r['root_causes'][0];
        $this->assertSame('missing_schema', $cause['root_cause']);
        $this->assertSame(AtlasExternalBrainGiveBackRootCauseMiner::ACTION_RESPEC, $cause['recommended_action']);
    }

    public function test_duplicate_class_maps_to_duplicate_implemented_cancel(): void
    {
        $r = $this->miner->mine([[
            'task_packet_id' => 'task-dup-01',
            'give_back_class' => 'duplicate_capability',
            'give_back_count' => 2,
        ]]);

        $cause = $r['root_causes'][0];
        $this->assertSame('duplicate_implemented', $cause['root_cause']);
        $this->assertSame(AtlasExternalBrainGiveBackRootCauseMiner::ACTION_CANCEL, $cause['recommended_action']);
    }

    public function test_flaky_test_class_maps_to_flaky_test_respec(): void
    {
        $r = $this->miner->mine([[
            'task_packet_id' => 'task-flaky-01',
            'give_back_class' => 'flaky_test',
            'give_back_count' => 3,
        ]]);

        $cause = $r['root_causes'][0];
        $this->assertSame('flaky_test', $cause['root_cause']);
        $this->assertSame(AtlasExternalBrainGiveBackRootCauseMiner::ACTION_RESPEC, $cause['recommended_action']);
    }

    public function test_impossible_dependency_maps_to_operator_only(): void
    {
        $r = $this->miner->mine([[
            'task_packet_id' => 'task-dep-01',
            'give_back_class' => 'impossible_dependency',
            'give_back_count' => 2,
        ]]);

        $cause = $r['root_causes'][0];
        $this->assertSame('impossible_dependency', $cause['root_cause']);
        $this->assertSame(AtlasExternalBrainGiveBackRootCauseMiner::DEFECT_PACKET, $cause['defect_type']);
        $this->assertSame(AtlasExternalBrainGiveBackRootCauseMiner::ACTION_OPERATOR_ONLY, $cause['recommended_action']);
    }

    // ── worker weakness vs packet defect ──────────────────────────────────────

    public function test_context_overflow_is_worker_weakness_not_packet_defect(): void
    {
        $r = $this->miner->mine([[
            'task_packet_id' => 'task-ctx-01',
            'give_back_class' => 'context_overflow',
            'give_back_count' => 5,
        ]]);

        $cause = $r['root_causes'][0];
        $this->assertSame('worker_weakness', $cause['root_cause']);
        $this->assertSame(AtlasExternalBrainGiveBackRootCauseMiner::DEFECT_WORKER, $cause['defect_type']);
        $this->assertSame(AtlasExternalBrainGiveBackRootCauseMiner::ACTION_RETRY_DIFFERENT_WORKER, $cause['recommended_action']);
    }

    public function test_capability_gap_is_worker_weakness(): void
    {
        $r = $this->miner->mine([[
            'task_packet_id' => 'task-cap-01',
            'give_back_class' => 'capability_gap',
            'give_back_count' => 3,
        ]]);

        $this->assertSame(AtlasExternalBrainGiveBackRootCauseMiner::DEFECT_WORKER, $r['root_causes'][0]['defect_type']);
    }

    // ── poison packets invariant ──────────────────────────────────────────────

    public function test_packet_defect_at_threshold_becomes_poison_packet(): void
    {
        $r = $this->miner->mine([[
            'task_packet_id' => 'task-poison-01',
            'give_back_class' => 'scope_repair_missing_impl',
            'give_back_count' => AtlasExternalBrainGiveBackRootCauseMiner::POISON_THRESHOLD,
        ]]);

        $this->assertContains('task-poison-01', $r['poison_packets']);
    }

    public function test_worker_weakness_never_becomes_poison_packet(): void
    {
        $r = $this->miner->mine([[
            'task_packet_id' => 'task-worker-01',
            'give_back_class' => 'context_overflow',
            'give_back_count' => 99,  // many give_backs, but it is worker weakness
        ]]);

        $this->assertNotContains('task-worker-01', $r['poison_packets']);
    }

    public function test_below_threshold_packet_defect_not_yet_poison(): void
    {
        $r = $this->miner->mine([[
            'task_packet_id' => 'task-below-01',
            'give_back_class' => 'forbidden_file',
            'give_back_count' => AtlasExternalBrainGiveBackRootCauseMiner::POISON_THRESHOLD - 1,
        ]]);

        $this->assertNotContains('task-below-01', $r['poison_packets']);
    }

    // ── clustering ────────────────────────────────────────────────────────────

    public function test_multiple_tasks_with_same_root_cause_cluster_together(): void
    {
        $r = $this->miner->mine([
            ['task_packet_id' => 'task-a', 'give_back_class' => 'scope_repair_missing_impl', 'give_back_count' => 3],
            ['task_packet_id' => 'task-b', 'give_back_class' => 'scope_repair_bad_file', 'give_back_count' => 2],
        ]);

        $this->assertCount(1, $r['root_causes']);
        $this->assertContains('task-a', $r['root_causes'][0]['task_packet_ids']);
        $this->assertContains('task-b', $r['root_causes'][0]['task_packet_ids']);
    }

    public function test_different_root_causes_produce_separate_clusters(): void
    {
        $r = $this->miner->mine([
            ['task_packet_id' => 'task-a', 'give_back_class' => 'scope_repair_missing_impl', 'give_back_count' => 3],
            ['task_packet_id' => 'task-b', 'give_back_class' => 'forbidden_file', 'give_back_count' => 2],
        ]);

        $this->assertCount(2, $r['root_causes']);
    }

    // ── respec_plan ───────────────────────────────────────────────────────────

    public function test_every_cluster_has_respec_plan_with_required_keys(): void
    {
        $r = $this->miner->mine([
            ['task_packet_id' => 'task-a', 'give_back_class' => 'scope_repair_missing_impl', 'give_back_count' => 3],
            ['task_packet_id' => 'task-b', 'give_back_class' => 'context_overflow',           'give_back_count' => 2],
        ]);

        foreach ($r['root_causes'] as $cause) {
            $this->assertArrayHasKey('respec_plan', $cause, "cluster '{$cause['root_cause']}' missing respec_plan");
            $plan = $cause['respec_plan'];
            $this->assertArrayHasKey('action', $plan);
            $this->assertArrayHasKey('target_fields', $plan);
            $this->assertArrayHasKey('why_not_retry_unchanged', $plan);
            $this->assertIsString($plan['action']);
            $this->assertIsArray($plan['target_fields']);
            $this->assertNotEmpty($plan['why_not_retry_unchanged']);
        }
    }

    public function test_respec_plan_for_bad_allowed_files_is_rewrite_allowed_files(): void
    {
        $r    = $this->miner->mine([[
            'task_packet_id'  => 'task-scope-x',
            'give_back_class' => 'scope_repair_missing_impl',
            'give_back_count' => 3,
        ]]);
        $plan = $r['root_causes'][0]['respec_plan'];

        $this->assertSame(AtlasExternalBrainGiveBackRootCauseMiner::ACTION_REWRITE_ALLOWED_FILES, $plan['action']);
        $this->assertContains('allowed_files', $plan['target_fields']);
    }

    public function test_respec_plan_for_contradictory_acceptance_is_rewrite_acceptance(): void
    {
        $r    = $this->miner->mine([[
            'task_packet_id'  => 'task-contra-x',
            'give_back_class' => 'contradictory_acceptance',
            'give_back_count' => 2,
        ]]);
        $plan = $r['root_causes'][0]['respec_plan'];

        $this->assertSame(AtlasExternalBrainGiveBackRootCauseMiner::ACTION_REWRITE_ACCEPTANCE, $plan['action']);
        $this->assertContains('acceptance_criteria', $plan['target_fields']);
    }

    public function test_respec_plan_for_duplicate_is_cancel(): void
    {
        $r    = $this->miner->mine([[
            'task_packet_id'  => 'task-dup-x',
            'give_back_class' => 'duplicate_capability',
            'give_back_count' => 2,
        ]]);
        $plan = $r['root_causes'][0]['respec_plan'];

        $this->assertSame(AtlasExternalBrainGiveBackRootCauseMiner::ACTION_CANCEL, $plan['action']);
        $this->assertSame([], $plan['target_fields']);
    }

    public function test_respec_plan_for_impossible_dependency_is_split_dependencies(): void
    {
        $r    = $this->miner->mine([[
            'task_packet_id'  => 'task-dep-x',
            'give_back_class' => 'impossible_dependency',
            'give_back_count' => 2,
        ]]);
        $plan = $r['root_causes'][0]['respec_plan'];

        $this->assertSame(AtlasExternalBrainGiveBackRootCauseMiner::ACTION_SPLIT_DEPENDENCIES, $plan['action']);
    }

    public function test_respec_plan_for_worker_weakness_is_reroute_not_packet_quarantine(): void
    {
        $r    = $this->miner->mine([[
            'task_packet_id'  => 'task-ctx-x',
            'give_back_class' => 'context_overflow',
            'give_back_count' => 5,
        ]]);
        $plan = $r['root_causes'][0]['respec_plan'];

        $this->assertSame(AtlasExternalBrainGiveBackRootCauseMiner::ACTION_REROUTE_WORKER_CLASS, $plan['action']);
        // Must NOT cancel or quarantine the packet.
        $this->assertNotSame(AtlasExternalBrainGiveBackRootCauseMiner::ACTION_CANCEL,    $plan['action']);
        $this->assertNotSame(AtlasExternalBrainGiveBackRootCauseMiner::ACTION_OPERATOR_ONLY, $plan['action']);
        $this->assertSame([], $plan['target_fields'], 'worker reroute must not mutate packet fields');
    }

    public function test_respec_plan_worker_weakness_why_explains_packet_is_fine(): void
    {
        $r    = $this->miner->mine([[
            'task_packet_id'  => 'task-cap-x',
            'give_back_class' => 'capability_gap',
            'give_back_count' => 3,
        ]]);
        $why = $r['root_causes'][0]['respec_plan']['why_not_retry_unchanged'];

        $this->assertStringContainsString('worker', $why);
    }

    public function test_respec_plan_why_not_retry_unchanged_is_non_empty_for_all_packet_defects(): void
    {
        $classes = [
            'scope_repair_missing_impl',
            'forbidden_file',
            'contradictory_acceptance',
            'schema_missing',
            'duplicate_capability',
            'flaky_test',
            'impossible_dependency',
            'unknown_weird_class',
        ];

        foreach ($classes as $class) {
            $r    = $this->miner->mine([['task_packet_id' => 'x', 'give_back_class' => $class, 'give_back_count' => 2]]);
            $plan = $r['root_causes'][0]['respec_plan'];
            $this->assertNotEmpty($plan['why_not_retry_unchanged'], "empty why for class: {$class}");
        }
    }

    // ── never retry unchanged invariant ──────────────────────────────────────

    public function test_poison_packets_are_not_recommended_for_unchanged_retry(): void
    {
        $r = $this->miner->mine([[
            'task_packet_id' => 'task-poison-99',
            'give_back_class' => 'scope_repair_missing_impl',
            'give_back_count' => 5,
        ]]);

        $this->assertContains('task-poison-99', $r['poison_packets']);

        // No cause recommends plain retry (only retry_different_worker is worker-side, not unchanged retry).
        foreach ($r['root_causes'] as $cause) {
            if (in_array('task-poison-99', $cause['task_packet_ids'], true)) {
                $this->assertNotSame('retry', $cause['recommended_action']);
                $this->assertNotSame('retry_unchanged', $cause['recommended_action']);
            }
        }
    }
}
