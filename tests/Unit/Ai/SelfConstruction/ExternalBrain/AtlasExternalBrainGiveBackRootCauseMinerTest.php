<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainGiveBackRootCauseMiner;
use Tests\TestCase;

final class AtlasExternalBrainGiveBackRootCauseMinerTest extends TestCase
{
    private AtlasExternalBrainGiveBackRootCauseMiner $miner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->miner = new AtlasExternalBrainGiveBackRootCauseMiner();
    }

    private function gb(string $id, string $class, int $count = 1, string $reason = ''): array
    {
        return array_filter([
            'task_packet_id' => $id,
            'give_back_class' => $class,
            'give_back_count' => $count,
            'reason' => $reason !== '' ? $reason : null,
        ], static fn ($v) => $v !== null);
    }

    // ── Schema and output structure ──────────────────────────────────────────────

    public function test_schema_constant(): void
    {
        $this->assertSame('atlas.external_brain.giveback_root_cause_miner.v1', AtlasExternalBrainGiveBackRootCauseMiner::SCHEMA);
    }

    public function test_output_has_all_canonical_keys(): void
    {
        $result = $this->miner->mine([]);
        $this->assertArrayHasKey('schema', $result);
        $this->assertArrayHasKey('root_causes', $result);
        $this->assertArrayHasKey('poison_packets', $result);
    }

    // ── Empty input ──────────────────────────────────────────────────────────────

    public function test_empty_give_backs_returns_empty(): void
    {
        $result = $this->miner->mine([]);
        $this->assertSame([], $result['root_causes']);
        $this->assertSame([], $result['poison_packets']);
    }

    // ── Classification: scope_repair → bad_allowed_files ─────────────────────────

    public function test_scope_repair_classifies_as_bad_allowed_files(): void
    {
        $result = $this->miner->mine([$this->gb('t1', 'scope_repair_missing')]);
        $this->assertSame('bad_allowed_files', $result['root_causes'][0]['root_cause']);
        $this->assertSame('packet_defect', $result['root_causes'][0]['defect_type']);
        $this->assertSame('respec', $result['root_causes'][0]['recommended_action']);
    }

    // ── Classification: forbidden → forbidden_target ─────────────────────────────

    public function test_forbidden_classifies_as_forbidden_target(): void
    {
        $result = $this->miner->mine([$this->gb('t1', 'forbidden_file')]);
        $this->assertSame('forbidden_target', $result['root_causes'][0]['root_cause']);
        $this->assertSame('packet_defect', $result['root_causes'][0]['defect_type']);
    }

    // ── Classification: contradictory → contradictory_acceptance ─────────────────

    public function test_contradictory_classifies_as_contradictory_acceptance(): void
    {
        $result = $this->miner->mine([$this->gb('t1', 'contradictory_criteria')]);
        $this->assertSame('contradictory_acceptance', $result['root_causes'][0]['root_cause']);
        $this->assertSame('respec', $result['root_causes'][0]['recommended_action']);
    }

    // ── Classification: schema → missing_schema ──────────────────────────────────

    public function test_schema_classifies_as_missing_schema(): void
    {
        $result = $this->miner->mine([$this->gb('t1', 'schema_violation')]);
        $this->assertSame('missing_schema', $result['root_causes'][0]['root_cause']);
        $this->assertSame('respec', $result['root_causes'][0]['recommended_action']);
    }

    // ── Classification: duplicate → duplicate_implemented ────────────────────────

    public function test_duplicate_classifies_as_duplicate_implemented(): void
    {
        $result = $this->miner->mine([$this->gb('t1', 'duplicate_capability')]);
        $this->assertSame('duplicate_implemented', $result['root_causes'][0]['root_cause']);
        $this->assertSame('cancel', $result['root_causes'][0]['recommended_action']);
    }

    // ── Classification: flaky_test → flaky_test ──────────────────────────────────

    public function test_flaky_classifies_as_flaky_test(): void
    {
        $result = $this->miner->mine([$this->gb('t1', 'flaky_test')]);
        $this->assertSame('flaky_test', $result['root_causes'][0]['root_cause']);
        $this->assertSame('respec', $result['root_causes'][0]['recommended_action']);
    }

    // ── Classification: impossible_dep → impossible_dependency ───────────────────

    public function test_impossible_dep_classifies_as_impossible_dependency(): void
    {
        $result = $this->miner->mine([$this->gb('t1', 'impossible_dependency')]);
        $this->assertSame('impossible_dependency', $result['root_causes'][0]['root_cause']);
        $this->assertSame('operator_only', $result['root_causes'][0]['recommended_action']);
    }

    // ── Classification: worker weakness → retry_different_worker ─────────────────

    public function test_context_overflow_classifies_as_worker_weakness(): void
    {
        $result = $this->miner->mine([$this->gb('t1', 'context_overflow')]);
        $this->assertSame('worker_weakness', $result['root_causes'][0]['root_cause']);
        $this->assertSame('worker_weakness', $result['root_causes'][0]['defect_type']);
        $this->assertSame('retry_different_worker', $result['root_causes'][0]['recommended_action']);
    }

    public function test_timeout_classifies_as_worker_weakness(): void
    {
        $result = $this->miner->mine([$this->gb('t1', 'timeout')]);
        $this->assertSame('worker_weakness', $result['root_causes'][0]['root_cause']);
    }

    public function test_provider_error_classifies_as_worker_weakness(): void
    {
        $result = $this->miner->mine([$this->gb('t1', 'provider_error')]);
        $this->assertSame('worker_weakness', $result['root_causes'][0]['root_cause']);
    }

    // ── Unclassified → operator_only ─────────────────────────────────────────────

    public function test_unclassified_classifies_as_operator_only(): void
    {
        $result = $this->miner->mine([$this->gb('t1', 'unknown_class')]);
        $this->assertSame('unclassified_repeat', $result['root_causes'][0]['root_cause']);
        $this->assertSame('operator_only', $result['root_causes'][0]['recommended_action']);
    }

    // ── Poison packets ───────────────────────────────────────────────────────────

    public function test_poison_packet_when_packet_defect_and_count_above_threshold(): void
    {
        $result = $this->miner->mine([$this->gb('t1', 'scope_repair_missing', 3)]);
        $this->assertContains('t1', $result['poison_packets']);
    }

    public function test_not_poison_when_count_below_threshold(): void
    {
        $result = $this->miner->mine([$this->gb('t1', 'scope_repair_missing', 1)]);
        $this->assertNotContains('t1', $result['poison_packets']);
    }

    public function test_not_poison_when_worker_weakness_even_with_high_count(): void
    {
        $result = $this->miner->mine([$this->gb('t1', 'context_overflow', 5)]);
        $this->assertNotContains('t1', $result['poison_packets']);
    }

    // ── Clustering: multiple events with same root cause grouped ─────────────────

    public function test_same_root_cause_clustered_together(): void
    {
        $result = $this->miner->mine([
            $this->gb('t1', 'scope_repair_missing'),
            $this->gb('t2', 'scope_repair_extra'),
        ]);
        $this->assertCount(1, $result['root_causes']);
        $this->assertSame('bad_allowed_files', $result['root_causes'][0]['root_cause']);
        $this->assertCount(2, $result['root_causes'][0]['task_packet_ids']);
    }

    // ── Respec plan: action and target fields ────────────────────────────────────

    public function test_bad_allowed_files_respec_plan(): void
    {
        $result = $this->miner->mine([$this->gb('t1', 'scope_repair_missing')]);
        $plan = $result['root_causes'][0]['respec_plan'];
        $this->assertSame('rewrite_allowed_files', $plan['action']);
        $this->assertContains('allowed_files', $plan['target_fields']);
    }

    public function test_contradictory_acceptance_respec_plan(): void
    {
        $result = $this->miner->mine([$this->gb('t1', 'contradictory_criteria')]);
        $plan = $result['root_causes'][0]['respec_plan'];
        $this->assertSame('rewrite_acceptance', $plan['action']);
        $this->assertContains('acceptance_criteria', $plan['target_fields']);
    }

    public function test_duplicate_respec_plan_is_cancel(): void
    {
        $result = $this->miner->mine([$this->gb('t1', 'duplicate_capability')]);
        $plan = $result['root_causes'][0]['respec_plan'];
        $this->assertSame('cancel', $plan['action']);
        $this->assertSame([], $plan['target_fields']);
    }

    public function test_worker_weakness_respec_plan_is_reroute(): void
    {
        $result = $this->miner->mine([$this->gb('t1', 'timeout')]);
        $plan = $result['root_causes'][0]['respec_plan'];
        $this->assertSame('reroute_worker_class', $plan['action']);
        $this->assertSame([], $plan['target_fields']);
    }

    // ── Evidence ─────────────────────────────────────────────────────────────────

    public function test_evidence_contains_task_packet_id_and_class(): void
    {
        $result = $this->miner->mine([$this->gb('t1', 'scope_repair_missing', 2)]);
        $evidence = $result['root_causes'][0]['evidence'];
        $this->assertCount(1, $evidence);
        $this->assertSame('t1', $evidence[0]['task_packet_id']);
        $this->assertSame('scope_repair_missing', $evidence[0]['give_back_class']);
        $this->assertSame(2, $evidence[0]['give_back_count']);
    }

    // ── Determinism ──────────────────────────────────────────────────────────────

    public function test_result_is_deterministic(): void
    {
        $input = [
            $this->gb('t1', 'scope_repair_missing'),
            $this->gb('t2', 'duplicate_capability'),
        ];
        $this->assertSame($this->miner->mine($input), $this->miner->mine($input));
    }

    // ── Constants ─────────────────────────────────────────────────────────────────

    public function test_defect_type_constants(): void
    {
        $this->assertSame('packet_defect', AtlasExternalBrainGiveBackRootCauseMiner::DEFECT_PACKET);
        $this->assertSame('worker_weakness', AtlasExternalBrainGiveBackRootCauseMiner::DEFECT_WORKER);
    }

    public function test_action_constants(): void
    {
        $this->assertSame('respec', AtlasExternalBrainGiveBackRootCauseMiner::ACTION_RESPEC);
        $this->assertSame('cancel', AtlasExternalBrainGiveBackRootCauseMiner::ACTION_CANCEL);
        $this->assertSame('operator_only', AtlasExternalBrainGiveBackRootCauseMiner::ACTION_OPERATOR_ONLY);
        $this->assertSame('retry_different_worker', AtlasExternalBrainGiveBackRootCauseMiner::ACTION_RETRY_DIFFERENT_WORKER);
    }

    public function test_poison_threshold(): void
    {
        $this->assertSame(2, AtlasExternalBrainGiveBackRootCauseMiner::POISON_THRESHOLD);
    }

    // ── Reason-based classification ──────────────────────────────────────────────

    public function test_reason_allowed_files_classifies_as_bad_allowed_files(): void
    {
        $result = $this->miner->mine([$this->gb('t1', 'unknown', 1, 'missing allowed_files')]);
        $this->assertSame('bad_allowed_files', $result['root_causes'][0]['root_cause']);
    }

    public function test_reason_already_implemented_classifies_as_duplicate(): void
    {
        $result = $this->miner->mine([$this->gb('t1', 'unknown', 1, 'already_implemented')]);
        $this->assertSame('duplicate_implemented', $result['root_causes'][0]['root_cause']);
    }

    // ── Multiple clusters in same batch ──────────────────────────────────────────

    public function test_multiple_distinct_root_causes_produce_multiple_clusters(): void
    {
        $result = $this->miner->mine([
            $this->gb('t1', 'scope_repair_missing'),
            $this->gb('t2', 'duplicate_capability'),
            $this->gb('t3', 'timeout'),
        ]);
        $this->assertCount(3, $result['root_causes']);
    }
}
