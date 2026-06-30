<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricGiveBackLearningIntegrator;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasTaskFabricGiveBackLearningIntegrator: scope_repair with missing impl yields
 * add_missing_impl_file with the named candidate; contradictory acceptance yields respec;
 * repeated_give_back_8 events are quarantined and recommended for respec (never auto-reopened);
 * duplicate givebacks collapse to one recommendation per packet; output is deterministic.
 */
final class AtlasTaskFabricGiveBackLearningIntegratorTest extends TestCase
{
    public function test_scope_repair_missing_impl_yields_add_missing_impl_file_with_candidate(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-a', 'reason' => 'scope_repair_attempted', 'blocking_deficiencies' => ['missing_impl_file: app/Demo/Foo.php']],
        ]);

        $rec = $r['recommendations'][0];
        $this->assertSame('pkt-a', $rec['task_packet_id']);
        $this->assertSame('scope_repair_missing_impl', $rec['failure_class']);
        $this->assertSame(AtlasTaskFabricGiveBackLearningIntegrator::REC_ADD_IMPL, $rec['recommendation']);
        $this->assertSame('app/Demo/Foo.php', $rec['evidence']['missing_impl_candidate']);
    }

    public function test_contradictory_acceptance_yields_respec(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-b', 'reason' => 'acceptance_contradiction', 'blocking_deficiencies' => ['scalar_score_required_but_anti_goodhart_forbids']],
        ]);
        $this->assertSame(AtlasTaskFabricGiveBackLearningIntegrator::REC_RESPEC, $r['recommendations'][0]['recommendation']);
    }

    public function test_repeated_quarantine_emits_quarantine_requires_respec_never_reopened(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-q', 'reason' => 'scope_repair', 'blocking_deficiencies' => ['missing_impl'], 'give_back_count' => 8],
        ]);
        $this->assertSame(AtlasTaskFabricGiveBackLearningIntegrator::REC_QUARANTINE, $r['recommendations'][0]['recommendation']);
    }

    public function test_cli_clobber_yields_cancel(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-c', 'reason' => 'cli_clobber', 'blocking_deficiencies' => ['cli_clobber:atlas:task']],
        ]);
        $this->assertSame(AtlasTaskFabricGiveBackLearningIntegrator::REC_CANCEL, $r['recommendations'][0]['recommendation']);
    }

    public function test_many_deficiencies_in_one_event_yields_split_packet(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-s', 'reason' => 'too_broad', 'blocking_deficiencies' => ['a', 'b', 'c', 'd', 'e']],
        ]);
        $this->assertSame(AtlasTaskFabricGiveBackLearningIntegrator::REC_SPLIT, $r['recommendations'][0]['recommendation']);
    }

    public function test_duplicate_givebacks_for_same_packet_collapse_to_one_recommendation(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-dup', 'reason' => 'scope_repair', 'blocking_deficiencies' => ['missing_impl_file: app/X.php']],
            ['task_packet_id' => 'pkt-dup', 'reason' => 'scope_repair', 'blocking_deficiencies' => ['missing_impl_file: app/X.php']],
            ['task_packet_id' => 'pkt-dup', 'reason' => 'scope_repair', 'blocking_deficiencies' => ['missing_impl_file: app/X.php']],
        ]);
        $this->assertCount(1, $r['recommendations']);
        $this->assertSame(3, $r['recommendations'][0]['evidence']['event_count']);
    }

    public function test_output_is_deterministic_across_invocations(): void
    {
        $events = [
            ['task_packet_id' => 'pkt-a', 'reason' => 'scope_repair', 'blocking_deficiencies' => ['missing_impl_file: app/A.php']],
            ['task_packet_id' => 'pkt-b', 'reason' => 'cli_clobber', 'blocking_deficiencies' => ['cli_clobber']],
        ];
        $ig = new AtlasTaskFabricGiveBackLearningIntegrator;
        $this->assertSame(json_encode($ig->integrate($events)), json_encode($ig->integrate($events)));
    }

    public function test_recommendations_sorted_by_task_packet_id_ascending(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'z-pkt', 'reason' => 'cli_clobber', 'blocking_deficiencies' => []],
            ['task_packet_id' => 'a-pkt', 'reason' => 'cli_clobber', 'blocking_deficiencies' => []],
            ['task_packet_id' => 'm-pkt', 'reason' => 'cli_clobber', 'blocking_deficiencies' => []],
        ]);
        $ids = array_column($r['recommendations'], 'task_packet_id');
        $this->assertSame(['a-pkt', 'm-pkt', 'z-pkt'], $ids);
    }

    // ── respec_contract_draft ─────────────────────────────────────────────────

    public function test_scope_repair_emits_respec_contract_draft_with_allowed_files_delta(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-x', 'reason' => 'scope_repair_attempted', 'blocking_deficiencies' => ['missing_impl_file: app/Demo/Bar.php']],
        ]);

        $draft = $r['recommendations'][0]['respec_contract_draft'];

        $this->assertNotNull($draft);
        $this->assertSame(['app/Demo/Bar.php'], $draft['allowed_files_delta']);
        $this->assertIsString($draft['acceptance_repair_hint']);
        $this->assertNotEmpty($draft['acceptance_repair_hint']);
        $this->assertIsArray($draft['evidence_to_preserve']);
    }

    public function test_respec_contract_draft_evidence_to_preserve_excludes_missing_impl_deficiency(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-y', 'reason' => 'scope_repair', 'blocking_deficiencies' => [
                'missing_impl_file: app/Svc/Alpha.php',
                'acceptance_criteria_requires_test_authoring',
                'required_evidence:tests_or_gates_result',
            ]],
        ]);

        $draft = $r['recommendations'][0]['respec_contract_draft'];

        $this->assertNotNull($draft);
        foreach ($draft['evidence_to_preserve'] as $item) {
            $this->assertStringNotContainsStringIgnoringCase('missing_impl', $item);
        }
        $this->assertContains('acceptance_criteria_requires_test_authoring', $draft['evidence_to_preserve']);
        $this->assertContains('required_evidence:tests_or_gates_result', $draft['evidence_to_preserve']);
    }

    public function test_respec_contract_draft_is_null_for_non_scope_repair(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-z', 'reason' => 'acceptance_contradiction', 'blocking_deficiencies' => ['scalar_score_required_but_anti_goodhart_forbids']],
        ]);

        $this->assertNull($r['recommendations'][0]['respec_contract_draft']);
    }

    // ── do_not_requeue_reason ─────────────────────────────────────────────────

    public function test_quarantine_emits_do_not_requeue_reason(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-q8', 'reason' => 'scope_repair', 'blocking_deficiencies' => ['missing_impl'], 'give_back_count' => 8],
        ]);

        $rec = $r['recommendations'][0];
        $this->assertSame(AtlasTaskFabricGiveBackLearningIntegrator::REC_QUARANTINE, $rec['recommendation']);
        $this->assertNotNull($rec['do_not_requeue_reason']);
        $this->assertStringContainsString('quarantine', $rec['do_not_requeue_reason']);
        $this->assertNull($rec['respec_contract_draft']);
    }

    public function test_cli_clobber_emits_do_not_requeue_reason_and_no_respec_draft(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-clob', 'reason' => 'cli_clobber', 'blocking_deficiencies' => ['cli_clobber:atlas:task']],
        ]);

        $rec = $r['recommendations'][0];
        $this->assertSame(AtlasTaskFabricGiveBackLearningIntegrator::REC_CANCEL, $rec['recommendation']);
        $this->assertNotNull($rec['do_not_requeue_reason']);
        $this->assertNull($rec['respec_contract_draft']);
    }

    public function test_petreo_emits_do_not_requeue_reason_and_no_respec_draft(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-pet', 'reason' => 'petreo_core_violation', 'blocking_deficiencies' => ['petreo_file_touched']],
        ]);

        $rec = $r['recommendations'][0];
        $this->assertSame(AtlasTaskFabricGiveBackLearningIntegrator::REC_CANCEL, $rec['recommendation']);
        $this->assertNotNull($rec['do_not_requeue_reason']);
        $this->assertStringContainsString('petreo', $rec['do_not_requeue_reason']);
        $this->assertNull($rec['respec_contract_draft']);
    }

    public function test_non_blocking_recommendation_has_null_do_not_requeue_reason(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-ok', 'reason' => 'scope_repair', 'blocking_deficiencies' => ['missing_impl_file: app/X.php']],
        ]);

        $this->assertNull($r['recommendations'][0]['do_not_requeue_reason']);
    }

    // ── worker_shape_learning ─────────────────────────────────────────────────

    public function test_worker_shape_learning_key_exists_in_output(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-1', 'reason' => 'generic'],
        ]);
        $this->assertArrayHasKey('worker_shape_learning', $r);
        $this->assertIsArray($r['worker_shape_learning']);
    }

    public function test_worker_shape_learning_empty_when_no_shape_metadata(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-1', 'reason' => 'generic'],
            ['task_packet_id' => 'pkt-2', 'reason' => 'cli_clobber'],
        ]);
        $this->assertSame([], $r['worker_shape_learning']);
    }

    public function test_worker_shape_learning_groups_by_task_shape_and_worker_client_id(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-1', 'reason' => 'generic', 'task_shape' => 'test_authoring', 'worker_client_id' => 'w-1'],
            ['task_packet_id' => 'pkt-2', 'reason' => 'generic', 'task_shape' => 'test_authoring', 'worker_client_id' => 'w-1'],
            ['task_packet_id' => 'pkt-3', 'reason' => 'generic', 'task_shape' => 'implementation',  'worker_client_id' => 'w-2'],
        ]);

        $groups = $r['worker_shape_learning'];
        $this->assertCount(2, $groups);
        // deterministic order by key (shape||worker)
        $byKey = array_column($groups, null, 'task_shape');
        $this->assertSame(2, $byKey['test_authoring']['give_back_count']);
        $this->assertSame(1, $byKey['implementation']['give_back_count']);
        $this->assertSame('w-1', $byKey['test_authoring']['worker_client_id']);
    }

    public function test_worker_shape_learning_counts_quarantine_separately(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-1', 'reason' => 'generic', 'task_shape' => 'impl', 'worker_client_id' => 'w-1', 'give_back_count' => 8],
            ['task_packet_id' => 'pkt-2', 'reason' => 'generic', 'task_shape' => 'impl', 'worker_client_id' => 'w-1', 'give_back_count' => 2],
        ]);

        $groups = $r['worker_shape_learning'];
        $this->assertCount(1, $groups);
        $this->assertSame(2, $groups[0]['give_back_count']);
        $this->assertSame(1, $groups[0]['quarantine_count']);
        $this->assertSame(0, $groups[0]['success_count']);
    }

    public function test_worker_shape_learning_counts_success_outcome(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-1', 'reason' => 'generic', 'task_shape' => 'impl', 'worker_client_id' => 'w-1', 'outcome' => 'success'],
            ['task_packet_id' => 'pkt-2', 'reason' => 'generic', 'task_shape' => 'impl', 'worker_client_id' => 'w-1'],
        ]);

        $groups = $r['worker_shape_learning'];
        $this->assertSame(1, $groups[0]['success_count']);
        $this->assertSame(1, $groups[0]['give_back_count']);
    }
}
