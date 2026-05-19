<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\LongHorizon\Causal;

use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\Mission\MissionFactoryService;
use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Mission\ObjectiveDecomposerService;
use App\Services\Ai\Mission\WorkOrderFactoryService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

class AtlasLongHorizonCausalGraphCommandTest extends TestCase
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
        $exit = Artisan::call('atlas:long-horizon:causal-graph', array_merge($params, ['--json' => true]));
        $output = Artisan::output();
        $json = json_decode($output, true);

        return ['exit' => $exit, 'json' => is_array($json) ? $json : null];
    }

    public function test_sem_scope_type_devolve_usage_error_exit_2(): void
    {
        $result = $this->runCmd([]);
        $this->assertSame(2, $result['exit']);
        $this->assertSame('usage_error', $result['json']['error'] ?? null);
    }

    public function test_scope_type_não_canonico_devolve_invalid_argument(): void
    {
        $result = $this->runCmd([
            '--scope-type' => 'martian-xyz',
            '--scope-id' => 'fake',
        ]);
        $this->assertSame(2, $result['exit']);
        $this->assertSame('invalid_argument', $result['json']['error'] ?? null);
    }

    public function test_mission_não_encontrada_emite_gap_e_exit_0(): void
    {
        $result = $this->runCmd([
            '--scope-type' => AtlasLongHorizonCanon::SCOPE_TYPE_MISSION,
            '--scope-id' => 'uuid-inexistente-xyz',
        ]);
        $this->assertSame(0, $result['exit'], 'gap é parte do payload, NÃO erro HTTP');
        $this->assertTrue($result['json']['ok'] ?? null);
        $graph = $result['json']['graph'] ?? [];
        $this->assertSame(AtlasLongHorizonCanon::CAUSAL_GRAPH_LITE_SCHEMA_VERSION, $graph['schema_version'] ?? null);
        $this->assertNotEmpty($graph['gaps'] ?? []);
    }

    public function test_mission_existente_emite_grafo_com_hash(): void
    {
        $factory = app(MissionFactoryService::class);
        $decomposer = app(ObjectiveDecomposerService::class);
        $workOrders = app(WorkOrderFactoryService::class);
        $lifecycle = app(MissionLifecycleService::class);

        $mission = $factory->create('Missão: cobrir feature até concluir', [
            'mission_type' => MissionFactoryService::TYPE_MISSION,
            'primary_domain' => 'research',
        ]);
        $decomposer->decompose($mission);
        $workOrders->plan($mission);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_PLANNED, ['actor_type' => 'test']);

        $result = $this->runCmd([
            '--scope-type' => AtlasLongHorizonCanon::SCOPE_TYPE_MISSION,
            '--scope-id' => $mission->refresh()->uuid,
        ]);

        $this->assertSame(0, $result['exit']);
        $graph = $result['json']['graph'] ?? [];
        $this->assertSame(AtlasLongHorizonCanon::CAUSAL_GRAPH_LITE_SCHEMA_VERSION, $graph['schema_version']);
        $this->assertSame($mission->uuid, $graph['scope_id']);
        $this->assertSame(64, strlen((string) ($graph['graph_hash'] ?? '')));
        $this->assertGreaterThan(0, $graph['summary']['nodes_count'] ?? 0);
    }

    public function test_command_não_vaza_raw_prompt_no_payload(): void
    {
        $factory = app(MissionFactoryService::class);
        $decomposer = app(ObjectiveDecomposerService::class);
        $workOrders = app(WorkOrderFactoryService::class);

        $mission = $factory->create('Missão: este texto SECRETO_X9X9 não pode aparecer no grafo até concluir', [
            'mission_type' => MissionFactoryService::TYPE_MISSION,
            'primary_domain' => 'research',
        ]);
        $decomposer->decompose($mission);
        $workOrders->plan($mission);
        app(MissionLifecycleService::class)->transition($mission, MissionLifecycleService::STATUS_PLANNED, ['actor_type' => 'test']);

        $result = $this->runCmd([
            '--scope-type' => AtlasLongHorizonCanon::SCOPE_TYPE_MISSION,
            '--scope-id' => $mission->refresh()->uuid,
        ]);

        $serialized = json_encode($result['json']);
        $this->assertStringNotContainsString('SECRETO_X9X9', (string) $serialized);
    }
}
