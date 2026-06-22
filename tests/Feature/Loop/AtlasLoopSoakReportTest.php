<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopSoakReportService;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * THE SOAK INSTRUMENT — proven provider-free over seeded data (NO loop run). Each dimension is asserted: (a)
 * deliveries count + work-type + size; (b) quality reuses the honest scorecard and trips proxy_alarm on faxina
 * drift; (c) compounding — the dared RUNG-SIZE climbing is detected (and a flat rung is not); (d) spend per
 * provider + regressions the sentinel caught. An empty window is idle, never a crash.
 */
final class AtlasLoopSoakReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_proposals')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
                '2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
    }

    private function campaign(): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'soak report',
            'config' => [],
            'max_seconds' => 60,
        ]);
    }

    /** Seed a task (carrying objective_kind + node_count) and return its id. */
    private function task(string $campaignId, string $objectiveKind, int $nodeCount, int $i, string $source = 'discovery'): string
    {
        $task = app(AtlasLoopStore::class)->enqueueTask(
            $campaignId,
            "do work {$objectiveKind} #{$i}",
            ['objective_kind' => $objectiveKind, 'node_count' => $nodeCount],
            $source,
            "app/Target{$i}.php",
        );

        return (string) $task->id;
    }

    /** Seed a merged-to-main proposal aged $ageHours ago, linked to $taskId. */
    private function mergeDelivery(string $campaignId, string $taskId, int $ageHours, string $provider, bool $canaryGreen, int $diffBytes, int $i): void
    {
        AtlasLoopProposal::$governedMergeInProgress = true;
        $p = AtlasLoopProposal::create([
            'campaign_id' => $campaignId,
            'task_id' => $taskId,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => AtlasLoopProposal::STATUS_CERTIFIED,
            'objective' => "delivery #{$i}",
            'provider' => $provider,
            'target_path' => "app/Target{$i}.php",
            'diff_text' => str_repeat('x', max(0, $diffBytes)),
            'proposal_hash' => substr(hash('sha256', $campaignId.$i.$ageHours), 0, 40),
            'merged_to_main' => true,
        ]);
        AtlasLoopProposal::$governedMergeInProgress = false;
        DB::table('atlas_loop_proposals')->where('id', $p->id)->update([
            'quality' => json_encode(['_canary' => ['ran' => true, 'passed' => $canaryGreen]]),
            'updated_at' => Carbon::now()->subHours($ageHours),
        ]);
    }

    private function report(string $campaignId, int $hours = 6): array
    {
        return app(AtlasLoopSoakReportService::class)->report($hours, $campaignId);
    }

    public function test_deliveries_count_work_type_and_size(): void
    {
        $c = $this->campaign();
        $t1 = $this->task((string) $c->id, 'feature', 4, 1);
        $t2 = $this->task((string) $c->id, 'bug_fix', 3, 2);
        $t3 = $this->task((string) $c->id, 'feature', 6, 3);
        $this->mergeDelivery((string) $c->id, $t1, 1, 'glm', true, 400, 1);
        $this->mergeDelivery((string) $c->id, $t2, 1, 'glm', true, 200, 2);
        $this->mergeDelivery((string) $c->id, $t3, 1, 'minimax', true, 600, 3);

        $d = $this->report((string) $c->id)['deliveries'];

        $this->assertSame(3, $d['merged_count']);
        $this->assertSame(2, $d['by_work_type']['feature'] ?? 0);
        $this->assertSame(1, $d['by_work_type']['bug_fix'] ?? 0);
        $this->assertGreaterThan(0, $d['diff_bytes_avg']);
    }

    public function test_rung_size_climbing_proves_compounding_and_flat_does_not(): void
    {
        config(['atlas.loop.capability_trend_enabled' => true]);
        $c = $this->campaign();
        // RISING dared rung-size over time + a rising clean-delivery rate => compounding.
        $this->mergeDelivery((string) $c->id, $this->task((string) $c->id, 'feature', 2, 1), 5, 'glm', false, 100, 1); // oldest, small, red
        $this->mergeDelivery((string) $c->id, $this->task((string) $c->id, 'feature', 5, 2), 3, 'glm', true, 300, 2);  // mid
        $this->mergeDelivery((string) $c->id, $this->task((string) $c->id, 'feature', 9, 3), 1, 'glm', true, 500, 3);  // newest, big, green

        $comp = $this->report((string) $c->id)['compounding'];
        $this->assertTrue($comp['rung_size']['climbing'], 'a rising dared rung-size is detected as climbing; buckets='.json_encode($comp['rung_size']['buckets']));
        $this->assertTrue($comp['capability_trend']['bending'], 'the rising clean-rate bends the capability trend up');
        $this->assertTrue($comp['compounding'], 'bend ∧ rung-climb => FIBONACCI compounding');

        // A FLAT rung (constant node_count) is NOT climbing — activity without evolution.
        $flat = $this->campaign();
        $this->mergeDelivery((string) $flat->id, $this->task((string) $flat->id, 'feature', 5, 1), 5, 'glm', true, 300, 1);
        $this->mergeDelivery((string) $flat->id, $this->task((string) $flat->id, 'feature', 5, 2), 3, 'glm', true, 300, 2);
        $this->mergeDelivery((string) $flat->id, $this->task((string) $flat->id, 'feature', 5, 3), 1, 'glm', true, 300, 3);

        $flatComp = $this->report((string) $flat->id)['compounding'];
        $this->assertFalse($flatComp['rung_size']['climbing'], 'a constant rung-size is flat, not climbing');
    }

    public function test_quality_trips_proxy_alarm_on_faxina_drift(): void
    {
        // 1 real (bug_fix) + 2 proxy (refactor) => proxy dominates => ALARM.
        $c = $this->campaign();
        $this->task((string) $c->id, 'bug_fix', 3, 1);
        $this->task((string) $c->id, 'refactor', 2, 2);
        $this->task((string) $c->id, 'refactor', 2, 3);

        $q = $this->report((string) $c->id)['quality'];
        $this->assertTrue($q['available']);
        $this->assertTrue($q['proxy_alarm'], 'proxy refactor dominating real work trips the faxina alarm');
        $this->assertGreaterThan($q['real_work_ratio'], $q['proxy_ratio']);

        // A real-work-dominated campaign does NOT alarm.
        $clean = $this->campaign();
        $this->task((string) $clean->id, 'bug_fix', 3, 1);
        $this->task((string) $clean->id, 'bug_fix', 3, 2);
        $this->task((string) $clean->id, 'feature', 3, 3);
        $this->assertFalse($this->report((string) $clean->id)['quality']['proxy_alarm'], 'real-work-dominated => no alarm');
    }

    public function test_spend_per_provider_and_regressions_caught(): void
    {
        $c = $this->campaign();
        $this->mergeDelivery((string) $c->id, $this->task((string) $c->id, 'feature', 3, 1), 1, 'glm', true, 200, 1);
        $this->mergeDelivery((string) $c->id, $this->task((string) $c->id, 'feature', 3, 2), 1, 'glm', true, 200, 2);
        $this->mergeDelivery((string) $c->id, $this->task((string) $c->id, 'feature', 3, 3), 1, 'minimax', true, 200, 3);
        // Two regressions the sentinel caught (fix-forward tasks).
        $this->task((string) $c->id, 'repair', 1, 91, 'regression_sentinel');
        $this->task((string) $c->id, 'repair', 1, 92, 'regression_sentinel');

        $s = $this->report((string) $c->id)['spend'];
        $this->assertSame(2, $s['deliveries_by_provider']['glm'] ?? 0);
        $this->assertSame(1, $s['deliveries_by_provider']['minimax'] ?? 0);
        $this->assertSame(2, $s['regressions_caught']);
    }

    public function test_empty_window_is_idle_not_crash(): void
    {
        $c = $this->campaign();
        $report = $this->report((string) $c->id);

        $this->assertSame(0, $report['deliveries']['merged_count']);
        $this->assertFalse($report['verdict']['evolving']);
        $this->assertContains('no_deliveries', $report['verdict']['flags']);
        $this->assertSame('atlas.loop.soak_report.v1', $report['schema_version']);
    }
}
