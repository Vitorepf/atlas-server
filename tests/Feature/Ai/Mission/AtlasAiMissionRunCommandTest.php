<?php

namespace Tests\Feature\Ai\Mission;

use App\Models\AiMission;
use App\Services\Ai\Mission\MissionControlPlaneService;
use App\Services\Ai\Mission\MissionFactoryService;
use App\Services\Ai\Mission\MissionFollowThroughResult;
use App\Services\Ai\Mission\MissionFollowThroughService;
use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Mission\ObjectiveDecomposerService;
use App\Services\Ai\Mission\WorkOrderFactoryService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

class AtlasAiMissionRunCommandTest extends TestCase
{
    use CreatesMissionFoundationTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMissionFoundationTables();
    }

    protected function tearDown(): void
    {
        $this->dropMissionFoundationTables();
        parent::tearDown();
    }

    /**
     * @return array{exit:int, json:array<string,mixed>|null}
     */
    private function runCmd(array $params): array
    {
        $exit = Artisan::call('atlas:ai:mission', array_merge($params, ['--json' => true]));
        $output = Artisan::output();
        $json = json_decode($output, true);

        return ['exit' => $exit, 'json' => is_array($json) ? $json : null];
    }

    private function freshPlannedMission(string $prompt, string $domain = 'research'): AiMission
    {
        $factory = app(MissionFactoryService::class);
        $decomposer = app(ObjectiveDecomposerService::class);
        $workOrders = app(WorkOrderFactoryService::class);
        $lifecycle = app(MissionLifecycleService::class);

        $mission = $factory->create($prompt, [
            'mission_type' => MissionFactoryService::TYPE_MISSION,
            'primary_domain' => $domain,
        ]);
        $decomposer->decompose($mission);
        $workOrders->plan($mission);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_PLANNED, ['actor_type' => 'test']);

        return $mission->refresh();
    }

    public function test_run_sem_mission_uuid_devolve_usage_error(): void
    {
        $result = $this->runCmd(['action' => 'run']);
        $this->assertSame(2, $result['exit']);
        $this->assertSame('usage_error', $result['json']['error'] ?? null);
    }

    public function test_run_mission_inexistente_exit_1(): void
    {
        $result = $this->runCmd([
            'action' => 'run',
            '--mission' => 'uuid-inexistente',
        ]);
        $this->assertSame(1, $result['exit']);
        $this->assertSame('mission_not_found', $result['json']['error'] ?? null);
    }

    public function test_run_single_cycle_avanca_mission_para_running(): void
    {
        $mission = $this->freshPlannedMission('Missão: pesquisar A até concluir');

        $result = $this->runCmd([
            'action' => 'run',
            '--mission' => $mission->uuid,
        ]);

        $this->assertSame(0, $result['exit']);
        $this->assertSame('run_next', $result['json']['mode'] ?? null);
        $this->assertSame(1, $result['json']['max_cycles'] ?? null);
        $this->assertSame(MissionLifecycleService::STATUS_RUNNING, $result['json']['mission_status'] ?? null);
        $this->assertSame(
            MissionFollowThroughResult::OUTCOME_SIMULATED_SAFE,
            $result['json']['cycle']['outcome'] ?? null,
        );
        $this->assertNotEmpty($result['json']['cycle']['receipt_hash'] ?? null);
        $this->assertSame(64, strlen($result['json']['cycle']['receipt_hash']));
    }

    public function test_run_until_blocked_respeita_max_cycles(): void
    {
        $mission = $this->freshPlannedMission('Missão: pesquisar B até concluir');

        $result = $this->runCmd([
            'action' => 'run',
            '--mission' => $mission->uuid,
            '--until-blocked' => true,
            '--max-cycles' => 2,
        ]);

        $this->assertSame(0, $result['exit']);
        $this->assertSame('run_until_blocked_or_complete', $result['json']['mode'] ?? null);
        $this->assertSame(2, $result['json']['max_cycles'] ?? null);
    }

    public function test_run_until_blocked_max_cycles_clamped_no_hard_cap(): void
    {
        $mission = $this->freshPlannedMission('Missão: pesquisar C até concluir');
        $bigCap = MissionFollowThroughService::MAX_CYCLES_HARD_CAP + 100;

        $result = $this->runCmd([
            'action' => 'run',
            '--mission' => $mission->uuid,
            '--until-blocked' => true,
            '--max-cycles' => $bigCap,
        ]);

        $this->assertSame(0, $result['exit']);
        $this->assertLessThanOrEqual(
            MissionFollowThroughService::MAX_CYCLES_HARD_CAP,
            (int) ($result['json']['max_cycles'] ?? 0),
            'CLI deve clampar maxCycles ao hard cap',
        );
    }

    public function test_run_command_expoe_follow_through_snapshot(): void
    {
        $mission = $this->freshPlannedMission('Missão: pesquisar D até concluir');

        $result = $this->runCmd([
            'action' => 'run',
            '--mission' => $mission->uuid,
        ]);

        $snapshot = $result['json']['follow_through_snapshot'] ?? null;
        $this->assertNotNull($snapshot);
        $this->assertSame('atlas.ai.control_plane.follow_through.v1', $snapshot['schema_version'] ?? null);
        $this->assertGreaterThanOrEqual(1, $snapshot['cycles_count'] ?? 0);
        $this->assertNotNull($snapshot['last_cycle'] ?? null);
    }

    public function test_mission_show_inclui_follow_through_no_snapshot(): void
    {
        $mission = $this->freshPlannedMission('Missão: pesquisar E até concluir');
        $followThrough = app(MissionFollowThroughService::class);
        $followThrough->runNext($mission);

        $result = $this->runCmd([
            'action' => 'show',
            '--mission' => $mission->uuid,
        ]);

        $this->assertSame(0, $result['exit']);
        // MissionControlPlaneService.snapshot() agora inclui follow_through.
        $missionControlPlane = app(MissionControlPlaneService::class);
        $cpSnapshot = $missionControlPlane->snapshot($mission->refresh());
        $this->assertArrayHasKey('follow_through', $cpSnapshot);
        $this->assertNotNull($cpSnapshot['follow_through']);
        $this->assertSame(
            'atlas.ai.control_plane.follow_through.v1',
            $cpSnapshot['follow_through']['schema_version'] ?? null,
        );
    }
}
