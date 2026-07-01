<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricGiveBackLearningIntegrator;
use PHPUnit\Framework\TestCase;

/**
 * Repeated give_back facts become repair, respec, cancel, split, quarantine, reroute, or
 * chain-blocking hints instead of re-serving poison: grouping by task_packet_id, class counts,
 * quarantine threshold, missing-impl recommendation, contradictory-acceptance respec,
 * cli_clobber_or_petreo cancel, split on many deficiencies, defect_patterns aggregation,
 * worker_shape_learning, and chain_repair_hints ordering. No scalar score/rank is ever emitted.
 */
final class AtlasTaskFabricGiveBackLearningIntegratorTest extends TestCase
{
    public function test_events_are_grouped_by_task_packet_id(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-a', 'reason' => 'x'],
            ['task_packet_id' => 'pkt-a', 'reason' => 'y'],
            ['task_packet_id' => 'pkt-b', 'reason' => 'z'],
        ]);

        self::assertCount(2, $r['recommendations']);
        self::assertSame(['pkt-a', 'pkt-b'], array_column($r['recommendations'], 'task_packet_id'));
        self::assertSame(2, $r['recommendations'][0]['evidence']['event_count']);
    }

    public function test_grouped_by_class_counts_recommendations_by_failure_class(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-a', 'reason' => 'cli_clobber', 'blocking_deficiencies' => ['cli_clobber']],
            ['task_packet_id' => 'pkt-b', 'reason' => 'cli_clobber', 'blocking_deficiencies' => ['cli_clobber']],
            ['task_packet_id' => 'pkt-c', 'reason' => 'acceptance_contradiction', 'blocking_deficiencies' => ['scalar_score']],
        ]);

        self::assertSame(2, $r['grouped_by_class']['cli_clobber_or_petreo']);
        self::assertSame(1, $r['grouped_by_class']['contradictory_acceptance']);
    }

    public function test_quarantine_threshold_triggers_quarantine_recommendation_never_reopened(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-q', 'reason' => 'scope_repair', 'blocking_deficiencies' => ['missing_impl'], 'give_back_count' => 8],
        ]);

        $rec = $r['recommendations'][0];
        self::assertSame(AtlasTaskFabricGiveBackLearningIntegrator::REC_QUARANTINE, $rec['recommendation']);
        self::assertNull($rec['respec_patch_fields'], 'quarantine must never carry a direct-reopen patch');
        self::assertNotNull($rec['do_not_requeue_reason']);
    }

    public function test_missing_impl_deficiency_yields_add_missing_impl_recommendation(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-a', 'reason' => 'scope_repair_attempted', 'blocking_deficiencies' => ['missing_impl_file: app/Demo/Foo.php']],
        ]);

        $rec = $r['recommendations'][0];
        self::assertSame(AtlasTaskFabricGiveBackLearningIntegrator::REC_ADD_IMPL, $rec['recommendation']);
        self::assertSame('app/Demo/Foo.php', $rec['evidence']['missing_impl_candidate']);
    }

    public function test_contradictory_acceptance_yields_respec_recommendation(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-b', 'reason' => 'acceptance_contradiction', 'blocking_deficiencies' => ['scalar_score_required_but_anti_goodhart_forbids']],
        ]);

        self::assertSame(AtlasTaskFabricGiveBackLearningIntegrator::REC_RESPEC, $r['recommendations'][0]['recommendation']);
    }

    public function test_cli_clobber_or_petreo_yields_cancel_recommendation(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-c', 'reason' => 'cli_clobber', 'blocking_deficiencies' => ['cli_clobber:atlas:task']],
        ]);

        self::assertSame(AtlasTaskFabricGiveBackLearningIntegrator::REC_CANCEL, $r['recommendations'][0]['recommendation']);
    }

    public function test_many_deficiencies_yield_split_packet_recommendation(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-s', 'reason' => 'too_broad', 'blocking_deficiencies' => ['a', 'b', 'c', 'd', 'e']],
        ]);

        self::assertSame(AtlasTaskFabricGiveBackLearningIntegrator::REC_SPLIT, $r['recommendations'][0]['recommendation']);
    }

    public function test_defect_patterns_aggregate_repeated_structural_shapes(): void
    {
        $files = ['app/Alpha.php'];
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-a1', 'reason' => 'scope_repair', 'blocking_deficiencies' => ['missing_impl'], 'allowed_files' => $files],
            ['task_packet_id' => 'pkt-a2', 'reason' => 'scope_repair', 'blocking_deficiencies' => ['missing_impl'], 'allowed_files' => $files],
        ]);

        self::assertCount(1, $r['defect_patterns']);
        self::assertSame(2, $r['defect_patterns'][0]['count']);
    }

    public function test_worker_shape_learning_groups_by_task_shape_and_worker(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-1', 'reason' => 'generic', 'task_shape' => 'impl', 'worker_client_id' => 'w-1'],
            ['task_packet_id' => 'pkt-2', 'reason' => 'generic', 'task_shape' => 'impl', 'worker_client_id' => 'w-1'],
        ]);

        self::assertCount(1, $r['worker_shape_learning']);
        self::assertSame(2, $r['worker_shape_learning'][0]['give_back_count']);
    }

    public function test_worker_shape_reroute_hint_fires_only_for_repeated_give_back_with_zero_success(): void
    {
        $repeated = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-a', 'reason' => 'generic', 'task_shape' => 'risky_impl', 'worker_client_id' => 'w-bad'],
            ['task_packet_id' => 'pkt-b', 'reason' => 'generic', 'task_shape' => 'risky_impl', 'worker_client_id' => 'w-bad'],
            ['task_packet_id' => 'pkt-c', 'reason' => 'generic', 'task_shape' => 'risky_impl', 'worker_client_id' => 'w-bad'],
        ]);
        $rerouteHints = array_values(array_filter(
            $repeated['chain_repair_hints'],
            static fn (array $h): bool => $h['action'] === AtlasTaskFabricGiveBackLearningIntegrator::CHAIN_ACTION_REROUTE,
        ));
        self::assertNotEmpty($rerouteHints, 'repeated give_back with zero success must fire a reroute hint');

        $withSuccess = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-a', 'reason' => 'generic', 'task_shape' => 'risky_impl', 'worker_client_id' => 'w-bad', 'outcome' => 'success'],
            ['task_packet_id' => 'pkt-b', 'reason' => 'generic', 'task_shape' => 'risky_impl', 'worker_client_id' => 'w-bad'],
            ['task_packet_id' => 'pkt-c', 'reason' => 'generic', 'task_shape' => 'risky_impl', 'worker_client_id' => 'w-bad'],
        ]);
        $noReroute = array_values(array_filter(
            $withSuccess['chain_repair_hints'],
            static fn (array $h): bool => $h['action'] === AtlasTaskFabricGiveBackLearningIntegrator::CHAIN_ACTION_REROUTE,
        ));
        self::assertEmpty($noReroute, 'a single success for the same worker/shape must suppress the reroute hint');
    }

    public function test_chain_repair_hints_are_ordered_deterministically(): void
    {
        $events = [
            ['task_packet_id' => 'zzz-pkt', 'reason' => 'cli_clobber', 'blocking_deficiencies' => ['cli_clobber']],
            ['task_packet_id' => 'aaa-pkt', 'reason' => 'acceptance_contradiction', 'blocking_deficiencies' => ['scalar_score']],
        ];
        $ig = new AtlasTaskFabricGiveBackLearningIntegrator;

        $a = $ig->integrate($events);
        $b = $ig->integrate($events);

        self::assertSame(json_encode($a['chain_repair_hints']), json_encode($b['chain_repair_hints']));
        self::assertSame(['aaa-pkt', 'zzz-pkt'], array_column($a['chain_repair_hints'], 'task_packet_id'));
    }

    public function test_no_scalar_score_or_rank_is_ever_output(): void
    {
        $r = (new AtlasTaskFabricGiveBackLearningIntegrator)->integrate([
            ['task_packet_id' => 'pkt-a', 'reason' => 'scope_repair', 'blocking_deficiencies' => ['missing_impl_file: app/X.php']],
        ]);

        $encoded = json_encode($r);
        foreach (['"score"', '"rank"', '"priority_score"', '"weight"'] as $forbiddenKey) {
            self::assertStringNotContainsString($forbiddenKey, $encoded, "output must never carry a scalar {$forbiddenKey}");
        }
    }
}
