<?php

namespace Tests\Feature\Ai\Mission;

use App\Models\AiMission;
use App\Services\Ai\Mission\MissionEvidenceService;
use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Mission\MissionModeService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

class AtlasAiMissionCommandTest extends TestCase
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
     * @param  array<string,mixed>  $params
     * @return array{exit:int, output:string, json:array<string,mixed>|null}
     */
    private function runCmd(array $params): array
    {
        $exit = Artisan::call('atlas:ai:mission', array_merge($params, ['--json' => true]));
        $output = Artisan::output();
        $json = json_decode($output, true);

        return [
            'exit' => $exit,
            'output' => $output,
            'json' => is_array($json) ? $json : null,
        ];
    }

    public function test_create_sem_goal_devolve_usage_error(): void
    {
        $result = $this->runCmd(['action' => 'create']);
        $this->assertSame(2, $result['exit']);
        $this->assertNotNull($result['json']);
        $this->assertSame('usage_error', $result['json']['error'] ?? null);
    }

    public function test_create_com_goal_simples_skipped(): void
    {
        $result = $this->runCmd([
            'action' => 'create',
            '--goal' => 'O que é Hyperflow?',
        ]);

        $this->assertSame(0, $result['exit']);
        $this->assertNotNull($result['json']);
        $this->assertSame('skipped', $result['json']['result']['outcome'] ?? null);
        $this->assertSame(0, AiMission::query()->count(), 'pergunta simples NÃO cria AiMission');
    }

    public function test_create_com_meta_persistente_ativa_mission_mode(): void
    {
        $result = $this->runCmd([
            'action' => 'create',
            '--goal' => 'Minha missão é integrar Stripe webhook até concluir, não pare antes de testar.',
        ]);

        $this->assertSame(0, $result['exit']);
        $this->assertNotNull($result['json']);
        $this->assertSame('created', $result['json']['result']['outcome'] ?? null);
        $this->assertSame('mission', $result['json']['result']['mission']['mission_type'] ?? null);
        $this->assertSame('planned', $result['json']['result']['mission']['status'] ?? null);
        $this->assertSame(1, AiMission::query()->count());
    }

    public function test_detect_é_dry_run_não_persiste_mission(): void
    {
        $result = $this->runCmd([
            'action' => 'detect',
            '--goal' => 'Minha missão: refatorar service X até concluir',
        ]);

        $this->assertSame(0, $result['exit']);
        $this->assertNotNull($result['json']);
        $this->assertFalse($result['json']['persisted'] ?? null);
        $this->assertTrue($result['json']['signal']['should_activate_mission_mode'] ?? null);
        $this->assertSame(0, AiMission::query()->count(), 'detect NUNCA persiste');
    }

    public function test_show_mission_not_found_exit_1(): void
    {
        $result = $this->runCmd([
            'action' => 'show',
            '--mission' => 'uuid-inexistente-xyz',
        ]);
        $this->assertSame(1, $result['exit']);
        $this->assertSame('mission_not_found', $result['json']['error'] ?? null);
    }

    public function test_show_mission_existente_devolve_snapshot(): void
    {
        $service = app(MissionModeService::class);
        $missionResult = $service->processIntent('Missão: implementar feature Y até concluir tudo');
        $uuid = $missionResult->mission->uuid;

        $result = $this->runCmd([
            'action' => 'show',
            '--mission' => $uuid,
        ]);

        $this->assertSame(0, $result['exit']);
        $this->assertSame($uuid, $result['json']['snapshot']['mission']['uuid'] ?? null);
        $this->assertSame('atlas.ai.mission_mode_snapshot.v1', $result['json']['snapshot']['schema_version'] ?? null);
    }

    public function test_list_filtra_por_status(): void
    {
        $service = app(MissionModeService::class);
        $service->processIntent('Missão A: tarefa longa até concluir o ciclo');

        $result = $this->runCmd([
            'action' => 'list',
            '--status' => MissionLifecycleService::STATUS_PLANNED,
        ]);

        $this->assertSame(0, $result['exit']);
        $this->assertSame(1, $result['json']['count'] ?? null);
        $this->assertSame(MissionLifecycleService::STATUS_PLANNED, $result['json']['missions'][0]['status'] ?? null);
    }

    public function test_certify_sem_evidence_status_failed_e_mission_não_completed(): void
    {
        $service = app(MissionModeService::class);
        $missionResult = $service->processIntent('Missão: estabilizar API até cobrir os testes');

        app(MissionLifecycleService::class)->transition(
            $missionResult->mission,
            MissionLifecycleService::STATUS_RUNNING,
            ['actor_type' => 'test'],
        );

        $result = $this->runCmd([
            'action' => 'certify',
            '--mission' => $missionResult->mission->uuid,
        ]);

        $this->assertSame(0, $result['exit'], 'comando executa, status PASSED/FAILED vai no payload');
        $missionResult->mission->refresh();
        $this->assertNotSame(
            MissionLifecycleService::STATUS_COMPLETED,
            $missionResult->mission->status,
            'completed bloqueado sem evidence',
        );
    }

    public function test_certify_com_evidence_completa_marca_completed_e_emite_hash(): void
    {
        $service = app(MissionModeService::class);
        $missionResult = $service->processIntent('Missão: cobrir endpoint /metrics até passar nos testes finais');
        $mission = $missionResult->mission;
        $lifecycle = app(MissionLifecycleService::class);
        $evidence = app(MissionEvidenceService::class);

        $lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING, ['actor_type' => 'test']);
        $mission->refresh();
        $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'tests/MetricsEndpointTest.php',
            'actor_type' => 'test',
        ]);

        $result = $this->runCmd([
            'action' => 'certify',
            '--mission' => $mission->uuid,
        ]);

        $this->assertSame(0, $result['exit']);
        $this->assertSame('passed', $result['json']['certification']['status'] ?? null);
        $this->assertSame('completed', $result['json']['mission_status'] ?? null);

        $mission->refresh();
        $this->assertSame(MissionLifecycleService::STATUS_COMPLETED, $mission->status);
        $this->assertNotEmpty($mission->certification_hash);
        $this->assertSame(64, strlen((string) $mission->certification_hash));
    }
}
