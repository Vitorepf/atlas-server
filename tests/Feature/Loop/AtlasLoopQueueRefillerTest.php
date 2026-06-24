<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTask;
use App\Models\AtlasLoopTarget;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetDiscoveryService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWorkShapeRouter;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasLoopQueueRefillerTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_targets')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        AtlasLoopTask::query()->delete();
        AtlasLoopTarget::query()->delete();
        AtlasLoopCampaign::query()->delete();
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    public function test_refill_reports_zero_per_lane_minted_counts_on_default_signal_less_campaign(): void
    {
        config([
            'atlas.loop.campaign.discovery_roots' => ['app'],
            'atlas.loop.objective_producer_enabled' => false,
            'atlas.loop.decompose_supply_enabled' => false,
            'atlas.loop.dedup_supply_enabled' => false,
            'atlas.loop.orphan_wiring_supply_enabled' => false,
            'atlas.loop.doc_gap_supply_enabled' => false,
            'atlas.loop.generic_provider_fallback_enabled' => false,
            'atlas.loop.refactor_objectives_enabled' => false,
            'atlas.loop.framework_refactor_enabled' => false,
        ]);

        $result = $this->refiller()->refill($this->campaign($this->repo()), 2);

        foreach (['dedup_minted', 'orphan_wiring_minted', 'doc_gap_minted', 'bug_reproduction_minted'] as $key) {
            $this->assertArrayHasKey($key, $result);
            $this->assertSame(0, $result[$key]);
        }
        $this->assertSame(0, $result['enqueued']);
    }

    private function refiller(): AtlasLoopQueueRefiller
    {
        $this->app->bind(AtlasEvolutionTaskGenerator::class, fn () => new AtlasEvolutionTaskGenerator(new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                return ['status' => 'noop'];
            }
        }));

        return new AtlasLoopQueueRefiller(
            app(AtlasLoopTargetDiscoveryService::class),
            app(AtlasLoopTargetRepository::class),
            app(AtlasEvolutionTaskGenerator::class),
            app(AtlasLoopBackService::class),
            app(AtlasLoopStore::class),
            null,
            new AtlasLoopHarnessGuard,
            null,
            null,
            new AtlasLoopWorkShapeRouter,
        );
    }

    private function campaign(string $repo): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::query()->create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'queue refiller telemetry shape',
            'base_workspace' => $repo,
            'provider' => '',
            'config' => ['decision_priority_enabled' => false],
            'max_seconds' => 60,
        ]);
    }

    private function repo(): string
    {
        $dir = sys_get_temp_dir().'/atlas-queue-refiller-telemetry-'.bin2hex(random_bytes(5));
        $this->dirs[] = $dir;
        File::ensureDirectoryExists($dir.'/app');

        return $dir;
    }
}
