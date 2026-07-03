<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\AutonomousEvolution\AtlasLoopCapabilityTrendService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Proves AtlasLoopCapabilityTrendService does not throw when the stored row
 * updated_at is an unparseable timestamp.
 */
final class AtlasLoopCapabilityTrendServiceHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureLoopProposalTable();
        config(['atlas.loop.capability_trend_enabled' => true]);
        DB::table('atlas_loop_proposals')->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::table('atlas_loop_proposals')->delete();
        parent::tearDown();
    }

    private function ensureLoopProposalTable(): void
    {
        if (! Schema::hasTable('atlas_loop_proposals')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }

        if (! Schema::hasColumn('atlas_loop_proposals', 'quality')) {
            (require base_path('database/migrations/2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php'))->up();
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            (require base_path('database/migrations/2026_06_12_000100_governed_merge_door_atlas_loop_proposals.php'))->up();
        }
    }

    private function insertMergedProposal(array $quality, $updatedAt): void
    {
        $payload = [
            'id' => (string) Str::uuid(),
            'campaign_id' => (string) Str::uuid(),
            'task_id' => null,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => 'certified_for_review',
            'objective' => 'Characterize AtlasLoopCapabilityTrendService timestamp handling',
            'provider' => 'phpunit',
            'target_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopCapabilityTrendService.php',
            'diff_text' => 'n/a',
            'proposal_hash' => hash('sha256', json_encode([$quality, $updatedAt, Str::uuid()->toString()], JSON_THROW_ON_ERROR)),
            'metric' => null,
            'quality' => json_encode($quality, JSON_THROW_ON_ERROR),
            'acceptance_hash' => null,
            'scenarios_explored' => 0,
            'scenarios_accepted' => 0,
            'winning_scenario' => null,
            'merged_to_main' => true,
            'reviewed_at' => null,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ];

        DB::transaction(function () use ($payload): void {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("SET LOCAL atlas.governed_merge='on'");
            }

            DB::table('atlas_loop_proposals')->insert($payload);
        });
    }

    public function test_trend_does_not_throw_when_updated_at_is_unparseable(): void
    {
        Carbon::setTestNow('2026-06-21 12:00:00');

        // Insert a proposal with an unparseable updated_at
        $this->insertMergedProposal(['_canary' => ['ran' => true, 'passed' => true]], 'not-a-date');

        // Insert a valid proposal so we have at least one sample
        $this->insertMergedProposal(['_canary' => ['ran' => true, 'passed' => true]], Carbon::now()->subMinutes(30));

        // Should not throw
        $trend = (new AtlasLoopCapabilityTrendService)->trend(1, 2);

        $this->assertTrue($trend['enabled']);
        // Only the valid proposal should be counted (unparseable one skipped)
        $this->assertSame(1, $trend['samples']);
    }

    public function test_trend_skips_all_unparseable_rows(): void
    {
        Carbon::setTestNow('2026-06-21 12:00:00');

        // Insert two proposals with unparseable updated_at
        $this->insertMergedProposal(['_canary' => ['ran' => true, 'passed' => true]], 'not-a-date');
        $this->insertMergedProposal(['_canary' => ['ran' => true, 'passed' => true]], '???');

        // Should not throw — all rows skipped
        $trend = (new AtlasLoopCapabilityTrendService)->trend(1, 2);

        $this->assertTrue($trend['enabled']);
        $this->assertSame(0, $trend['samples']);
    }
}
