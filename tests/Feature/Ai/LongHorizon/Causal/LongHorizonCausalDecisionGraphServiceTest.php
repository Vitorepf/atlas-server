<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\LongHorizon\Causal;

use App\Models\AiMission;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\LongHorizon\Causal\CausalGraphBuilder;
use App\Services\Ai\LongHorizon\Causal\LongHorizonCausalDecisionGraphService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Mission\MissionEvidenceService;
use App\Services\Ai\Mission\MissionFactoryService;
use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Mission\MissionModeService;
use App\Services\Ai\Mission\ObjectiveDecomposerService;
use App\Services\Ai\Mission\WorkOrderFactoryService;
use InvalidArgumentException;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

class LongHorizonCausalDecisionGraphServiceTest extends TestCase
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

    private function service(): LongHorizonCausalDecisionGraphService
    {
        return app(LongHorizonCausalDecisionGraphService::class);
    }

    private function freshMissionPlanned(string $prompt = 'Missão: cobrir endpoint até concluir', string $domain = 'research'): AiMission
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

    /* ------------------------------------------------------------ */
    /* Shape / contrato canon */
    /* ------------------------------------------------------------ */

    public function test_build_emite_schema_canon_completo(): void
    {
        $mission = $this->freshMissionPlanned();
        $payload = $this->service()->build(AtlasLongHorizonCanon::SCOPE_TYPE_MISSION, $mission->uuid);

        $this->assertSame(AtlasLongHorizonCanon::CAUSAL_GRAPH_LITE_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AtlasLongHorizonCanon::SCOPE_TYPE_MISSION, $payload['scope_type']);
        $this->assertSame($mission->uuid, $payload['scope_id']);
        foreach (['nodes', 'edges', 'gaps', 'blockers', 'evidence_refs', 'summary', 'graph_hash'] as $key) {
            $this->assertArrayHasKey($key, $payload);
        }
        $this->assertSame(64, strlen($payload['graph_hash']));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['graph_hash']);
    }

    public function test_scope_type_não_canônico_é_rejeitado_com_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->build('martian_scope_xyz', 'fake-uuid');
    }

    public function test_scope_id_vazio_é_rejeitado(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->build(AtlasLongHorizonCanon::SCOPE_TYPE_MISSION, '   ');
    }

    /* ------------------------------------------------------------ */
    /* Empty graph stable */
    /* ------------------------------------------------------------ */

    public function test_mission_não_encontrada_registra_gap_sem_throw(): void
    {
        $payload = $this->service()->build(AtlasLongHorizonCanon::SCOPE_TYPE_MISSION, 'uuid-inexistente');
        $this->assertSame([], $payload['nodes']);
        $this->assertSame([], $payload['edges']);
        $this->assertNotEmpty($payload['gaps']);
        $this->assertSame('mission_not_found', $payload['gaps'][0]['code']);
    }

    public function test_empty_graph_hash_é_determinístico_e_estável(): void
    {
        $payload1 = $this->service()->build(AtlasLongHorizonCanon::SCOPE_TYPE_MISSION, 'uuid-inexistente');
        $payload2 = $this->service()->build(AtlasLongHorizonCanon::SCOPE_TYPE_MISSION, 'uuid-inexistente');

        $this->assertSame($payload1['graph_hash'], $payload2['graph_hash']);
    }

    /* ------------------------------------------------------------ */
    /* Mission graph */
    /* ------------------------------------------------------------ */

    public function test_mission_graph_inclui_objective_e_work_order_via_depends_on(): void
    {
        $mission = $this->freshMissionPlanned();
        $payload = $this->service()->build(AtlasLongHorizonCanon::SCOPE_TYPE_MISSION, $mission->uuid);

        $kinds = array_column($payload['nodes'], 'kind');
        $this->assertContains(AtlasLongHorizonCanon::CAUSAL_NODE_MISSION_STEP, $kinds, 'mission node must be present');
        $this->assertContains(AtlasLongHorizonCanon::CAUSAL_NODE_WORK_PACKET, $kinds, 'work_order node must be present');

        $edgeKinds = array_column($payload['edges'], 'kind');
        $this->assertContains(AtlasLongHorizonCanon::CAUSAL_EDGE_DEPENDS_ON, $edgeKinds);
    }

    public function test_mission_blocked_registra_blocker_e_blocked_by(): void
    {
        $mission = $this->freshMissionPlanned();
        $lifecycle = app(MissionLifecycleService::class);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING, ['actor_type' => 'test']);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_BLOCKED, [
            'actor_type' => 'test',
            'blocker_reason' => 'awaiting_human_decision',
        ]);
        $mission->refresh();

        $payload = $this->service()->build(AtlasLongHorizonCanon::SCOPE_TYPE_MISSION, $mission->uuid);

        $blockerKinds = array_column($payload['nodes'], 'kind');
        $this->assertContains(AtlasLongHorizonCanon::CAUSAL_NODE_BLOCKER, $blockerKinds);
        $this->assertNotEmpty($payload['blockers']);

        $edgeKinds = array_column($payload['edges'], 'kind');
        $this->assertContains(AtlasLongHorizonCanon::CAUSAL_EDGE_BLOCKED_BY, $edgeKinds);
    }

    public function test_evidence_test_attach_vira_node_test_com_verified_by(): void
    {
        $mission = $this->freshMissionPlanned();
        $lifecycle = app(MissionLifecycleService::class);
        $evidence = app(MissionEvidenceService::class);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING, ['actor_type' => 'test']);
        $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'tests/CausalGraphTest.php',
            'actor_type' => 'test',
        ]);

        $payload = $this->service()->build(AtlasLongHorizonCanon::SCOPE_TYPE_MISSION, $mission->refresh()->uuid);

        $kinds = array_column($payload['nodes'], 'kind');
        $this->assertContains(AtlasLongHorizonCanon::CAUSAL_NODE_TEST, $kinds, 'evidence_type=test deve virar node TEST');
        $this->assertContains('tests/CausalGraphTest.php', $payload['evidence_refs']);
    }

    public function test_mission_certified_inclui_certification_node_verified_by(): void
    {
        $missionMode = app(MissionModeService::class);
        $lifecycle = app(MissionLifecycleService::class);
        $evidence = app(MissionEvidenceService::class);

        $missionResult = $missionMode->processIntent('Missão: cobrir feature ABC até concluir tudo');
        $mission = $missionResult->mission;
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING, ['actor_type' => 'test']);
        $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'tests/FeatureABCTest.php',
            'actor_type' => 'test',
        ]);
        $missionMode->certify($mission);

        $payload = $this->service()->build(AtlasLongHorizonCanon::SCOPE_TYPE_MISSION, $mission->refresh()->uuid);

        $kinds = array_column($payload['nodes'], 'kind');
        $this->assertContains(AtlasLongHorizonCanon::CAUSAL_NODE_CERTIFICATION, $kinds);

        $verifiedEdges = array_filter($payload['edges'], static fn (array $e): bool => $e['kind'] === AtlasLongHorizonCanon::CAUSAL_EDGE_VERIFIED_BY);
        $this->assertNotEmpty($verifiedEdges, 'mission certified → verified_by certification edge esperado');
    }

    /* ------------------------------------------------------------ */
    /* Hash determinístico */
    /* ------------------------------------------------------------ */

    public function test_graph_hash_é_determinístico_sobre_mesmo_estado(): void
    {
        $mission = $this->freshMissionPlanned();
        $hash1 = $this->service()->build(AtlasLongHorizonCanon::SCOPE_TYPE_MISSION, $mission->uuid)['graph_hash'];
        $hash2 = $this->service()->build(AtlasLongHorizonCanon::SCOPE_TYPE_MISSION, $mission->uuid)['graph_hash'];

        $this->assertSame($hash1, $hash2);
    }

    public function test_graph_hash_muda_quando_mission_status_muda(): void
    {
        $mission = $this->freshMissionPlanned();
        $hashBefore = $this->service()->build(AtlasLongHorizonCanon::SCOPE_TYPE_MISSION, $mission->uuid)['graph_hash'];

        app(MissionLifecycleService::class)->transition($mission, MissionLifecycleService::STATUS_RUNNING, ['actor_type' => 'test']);
        $hashAfter = $this->service()->build(AtlasLongHorizonCanon::SCOPE_TYPE_MISSION, $mission->refresh()->uuid)['graph_hash'];

        $this->assertNotSame($hashBefore, $hashAfter, 'mudança de status deve mudar hash determinístico');
    }

    public function test_canonical_hash_é_o_mesmo_que_mission_canonical_hash(): void
    {
        $mission = $this->freshMissionPlanned();
        $payload = $this->service()->build(AtlasLongHorizonCanon::SCOPE_TYPE_MISSION, $mission->uuid);

        $hashFromPayload = $payload['graph_hash'];
        $stripped = $payload;
        unset($stripped['graph_hash']);
        $recomputed = MissionCanonicalHash::sha256($stripped);

        $this->assertSame($hashFromPayload, $recomputed, 'graph_hash = MissionCanonicalHash::sha256(payload sem graph_hash)');
    }

    /* ------------------------------------------------------------ */
    /* Anti-leak (segurança) */
    /* ------------------------------------------------------------ */

    public function test_grafo_nunc_a_inclui_raw_prompt_blocker_reason_payload(): void
    {
        $mission = $this->freshMissionPlanned('Missão: este prompt contém TEXTO_SENSÍVEL_A_NUNCA_VAZAR até concluir');
        $lifecycle = app(MissionLifecycleService::class);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING, ['actor_type' => 'test']);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_BLOCKED, [
            'actor_type' => 'test',
            'blocker_reason' => 'BLOCKER_TEXTO_SENSÍVEL_INTERNO',
        ]);

        $payload = $this->service()->build(AtlasLongHorizonCanon::SCOPE_TYPE_MISSION, $mission->refresh()->uuid);
        $serialized = json_encode($payload);

        $this->assertStringNotContainsString('TEXTO_SENSÍVEL_A_NUNCA_VAZAR', $serialized, 'raw_prompt NÃO pode vazar');
        $this->assertStringNotContainsString('BLOCKER_TEXTO_SENSÍVEL_INTERNO', $serialized, 'blocker_reason NÃO pode vazar como texto');
    }

    public function test_meta_node_rejeita_chave_raw_prompt(): void
    {
        // Garantia direta via builder: chaves proibidas lançam exception
        $builder = new CausalGraphBuilder(
            AtlasLongHorizonCanon::SCOPE_TYPE_MISSION,
            'scope-id-fixture',
        );
        $this->expectException(InvalidArgumentException::class);
        $builder->addNode(
            AtlasLongHorizonCanon::CAUSAL_NODE_MISSION_STEP,
            'fixture',
            ['raw_prompt' => 'NUNCA DEVE VAZAR'],
        );
    }

    /* ------------------------------------------------------------ */
    /* Scope work_order */
    /* ------------------------------------------------------------ */

    public function test_scope_work_order_focado_inclui_apenas_o_w_o_alvo(): void
    {
        $mission = $this->freshMissionPlanned();
        $workOrder = $mission->workOrders()->first();
        $this->assertNotNull($workOrder);

        $payload = $this->service()->build(AtlasLongHorizonCanon::SCOPE_TYPE_WORK_ORDER, $workOrder->uuid);

        $kinds = array_column($payload['nodes'], 'kind');
        $this->assertContains(AtlasLongHorizonCanon::CAUSAL_NODE_WORK_PACKET, $kinds);
        $this->assertSame(AtlasLongHorizonCanon::SCOPE_TYPE_WORK_ORDER, $payload['scope_type']);
        $this->assertSame($workOrder->uuid, $payload['scope_id']);
    }

    public function test_scope_work_order_não_encontrada_registra_gap_sem_throw(): void
    {
        $payload = $this->service()->build(AtlasLongHorizonCanon::SCOPE_TYPE_WORK_ORDER, 'uuid-inexistente');
        $this->assertSame('work_order_not_found', $payload['gaps'][0]['code']);
    }

    /* ------------------------------------------------------------ */
    /* Summary counts são consistentes */
    /* ------------------------------------------------------------ */

    public function test_summary_counts_são_consistentes_com_arrays(): void
    {
        $mission = $this->freshMissionPlanned();
        $payload = $this->service()->build(AtlasLongHorizonCanon::SCOPE_TYPE_MISSION, $mission->uuid);

        $this->assertSame(count($payload['nodes']), $payload['summary']['nodes_count']);
        $this->assertSame(count($payload['edges']), $payload['summary']['edges_count']);
        $this->assertSame(count($payload['gaps']), $payload['summary']['gaps_count']);
        $this->assertSame(count($payload['blockers']), $payload['summary']['blockers_count']);
        $this->assertSame(count($payload['evidence_refs']), $payload['summary']['evidence_refs_count']);
    }

    public function test_all_node_kinds_estão_dentro_do_canon(): void
    {
        $mission = $this->freshMissionPlanned();
        $lifecycle = app(MissionLifecycleService::class);
        $evidence = app(MissionEvidenceService::class);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING, ['actor_type' => 'test']);
        $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'tests/X.php',
            'actor_type' => 'test',
        ]);

        $payload = $this->service()->build(AtlasLongHorizonCanon::SCOPE_TYPE_MISSION, $mission->refresh()->uuid);
        foreach ($payload['nodes'] as $node) {
            $this->assertContains($node['kind'], AtlasLongHorizonCanon::ALLOWED_CAUSAL_NODE_KINDS);
        }
        foreach ($payload['edges'] as $edge) {
            $this->assertContains($edge['kind'], AtlasLongHorizonCanon::ALLOWED_CAUSAL_EDGE_KINDS);
        }
    }
}
