<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopKeepaliveCommand;
use App\Services\Ai\AgentGovernance\AtlasAgentDesiredStateStore;
use App\Services\Ai\AgentGovernance\AtlasAgentEventLedger;
use App\Services\Ai\AgentGovernance\AtlasFleetCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AtlasLoopKeepaliveFleetGovernorWiringTest extends TestCase
{
    use ArmsAtlasLoopMaster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->armLoopMasterOn();
        $this->beforeApplicationDestroyed(fn () => $this->disarmLoopMaster());
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        if (! Schema::hasTable('atlas_agent_desired_state')) {
            (require base_path('database/migrations/2026_06_22_000100_create_atlas_agent_governance_tables.php'))->up();
        }
        config([
            'atlas.loop.keepalive_revive_starved' => false,
            'atlas.loop.fleet_global_worker_cap' => 0,
        ]);
        DB::table('atlas_loop_campaigns')->delete();
        DB::table('atlas_loop_tasks')->delete();
        DB::table('atlas_agent_desired_state')->delete();
    }

    private function seedRunningCampaign(array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::table('atlas_loop_campaigns')->insert(array_merge([
            'id' => $id,
            'schema_version' => 'atlas.loop.campaign.v1',
            'goal' => 'fleet-cap-test',
            'status' => 'running',
            'stop_reason' => null,
            'max_seconds' => 3600,
            'elapsed_seconds' => 60,
            'kill_switch' => false,
            'config' => '{}',
            'heartbeat_at' => now()->subMinutes(15),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subMinutes(15),
        ], $overrides));

        return $id;
    }

    private function seedRunningTask(string $campaignId, int $index): void
    {
        DB::table('atlas_loop_tasks')->insert([
            'id' => (string) Str::uuid(),
            'campaign_id' => $campaignId,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => 'running',
            'source' => 'discovery',
            'self_contained' => true,
            'target_path' => "app/Fleet{$index}.php",
            'objective' => "fleet task {$index}",
            'payload' => json_encode([]),
            'priority' => 0,
            'attempts' => 0,
            'max_attempts' => 1,
            'dedupe_key' => hash('sha256', $campaignId.'-'.$index),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(1),
        ]);
    }

    /**
     * @return array{0:object,1:array<string,mixed>}
     */
    private function runKeepalive(): array
    {
        $cmd = new class extends AtlasLoopKeepaliveCommand
        {
            public array $respawned = [];

            protected function respawn(string $campaignId): void
            {
                $this->respawned[] = $campaignId;
            }

            protected function supervisorAlive(string $campaignId): bool
            {
                return false;
            }
        };
        $cmd->setLaravel(app());
        $captured = [];
        $cmd->run(new \Symfony\Component\Console\Input\ArrayInput(['--json' => true]), new class($captured) extends \Symfony\Component\Console\Output\Output
        {
            public function __construct(private array &$captured)
            {
                parent::__construct();
            }

            protected function doWrite(string $message, bool $newline): void
            {
                $this->captured[] = $message;
            }
        });

        return [$cmd, json_decode(implode("\n", $captured), true, flags: JSON_THROW_ON_ERROR)];
    }

    public function test_cap_zero_preserves_the_existing_respawn_path(): void
    {
        $id = $this->seedRunningCampaign();
        (new AtlasAgentDesiredStateStore(new AtlasAgentEventLedger()))
            ->setOn(AtlasFleetCatalog::LOOP, by: 'operator', targetRef: $id);

        [$cmd, $json] = $this->runKeepalive();

        $this->assertContains($id, $cmd->respawned);
        $this->assertArrayNotHasKey('skipped_by_fleet_cap', $json);
        $this->assertSame($id, data_get($json, 'respawned.0.campaign_id'));
    }

    public function test_cap_exceeded_skips_respawn_and_records_the_lane(): void
    {
        $id = $this->seedRunningCampaign();
        (new AtlasAgentDesiredStateStore(new AtlasAgentEventLedger()))
            ->setOn(AtlasFleetCatalog::LOOP, by: 'operator', targetRef: $id);
        $this->seedRunningTask($id, 1);
        $this->seedRunningTask($id, 2);
        config(['atlas.loop.fleet_global_worker_cap' => 2]);

        [$cmd, $json] = $this->runKeepalive();

        $this->assertSame([], $cmd->respawned, 'fleet cap hit => no respawn shell-out');
        $this->assertSame($id, data_get($json, 'skipped_by_fleet_cap.0.campaign_id'));
        $this->assertSame(2, data_get($json, 'skipped_by_fleet_cap.0.fleet_in_flight'));
        $this->assertSame(2, data_get($json, 'skipped_by_fleet_cap.0.cap'));
        $this->assertSame('dead_respawn', data_get($json, 'skipped_by_fleet_cap.0.lane'));
    }
}
