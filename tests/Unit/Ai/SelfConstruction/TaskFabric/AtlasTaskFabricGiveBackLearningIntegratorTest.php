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
}
