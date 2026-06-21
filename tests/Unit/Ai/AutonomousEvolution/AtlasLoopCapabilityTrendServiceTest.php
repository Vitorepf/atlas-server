<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopCapabilityTrendService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AtlasLoopCapabilityTrendServiceTest extends TestCase
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

    public function test_trend_treats_non_boolean_ran_as_not_run_instead_of_red(): void
    {
        Carbon::setTestNow('2026-06-21 12:00:00');

        $this->insertMergedProposal(['_canary' => ['ran' => 1, 'passed' => false]], Carbon::now()->subMinutes(90));
        $this->insertMergedProposal(['_canary' => ['ran' => true, 'passed' => true]], Carbon::now()->subMinutes(30));

        $trend = (new AtlasLoopCapabilityTrendService)->trend(1, 2);

        $this->assertTrue($trend['enabled']);
        $this->assertSame(2, $trend['samples']);
        $this->assertSame(1, $trend['buckets'][0]['total']);
        $this->assertSame(1, $trend['buckets'][0]['clean']);
        $this->assertSame(1.0, $trend['buckets'][0]['rate']);
        $this->assertSame(1, $trend['buckets'][1]['clean']);
        $this->assertSame(0.0, $trend['slope']);
        $this->assertFalse($trend['bending']);
    }

    public function test_trend_treats_non_boolean_passed_as_red_once_canary_ran(): void
    {
        Carbon::setTestNow('2026-06-21 12:00:00');

        $this->insertMergedProposal(['_canary' => ['ran' => true, 'passed' => 1]], Carbon::now()->subMinutes(90));
        $this->insertMergedProposal(['_canary' => ['ran' => true, 'passed' => true]], Carbon::now()->subMinutes(30));

        $trend = (new AtlasLoopCapabilityTrendService)->trend(1, 2);

        $this->assertTrue($trend['enabled']);
        $this->assertSame(2, $trend['samples']);
        $this->assertSame(0, $trend['buckets'][0]['clean']);
        $this->assertSame(0.0, $trend['buckets'][0]['rate']);
        $this->assertSame(1, $trend['buckets'][1]['clean']);
        $this->assertSame(1.0, $trend['buckets'][1]['rate']);
        $this->assertGreaterThan(0.0, $trend['slope']);
        $this->assertTrue($trend['bending']);
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

    /**
     * @param  array<string,mixed>  $quality
     */
    private function insertMergedProposal(array $quality, Carbon $updatedAt): void
    {
        $payload = [
            'id' => (string) Str::uuid(),
            'campaign_id' => (string) Str::uuid(),
            'task_id' => null,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => 'certified_for_review',
            'objective' => 'Characterize AtlasLoopCapabilityTrendService canary handling',
            'provider' => 'phpunit',
            'target_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopCapabilityTrendService.php',
            'diff_text' => 'n/a',
            'proposal_hash' => hash('sha256', json_encode([$quality, $updatedAt->toIso8601String(), Str::uuid()->toString()], JSON_THROW_ON_ERROR)),
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
}
