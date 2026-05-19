<?php

namespace Tests\Feature\Ai\Mission;

use App\Models\AiMission;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Mission\MissionCertificationService;
use App\Services\Ai\Mission\MissionEvidenceService;
use App\Services\Ai\Mission\MissionFactoryService;
use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Mission\MissionModeResult;
use App\Services\Ai\Mission\MissionModeService;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

class MissionModeServiceTest extends TestCase
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

    private function service(): MissionModeService
    {
        return app(MissionModeService::class);
    }

    public function test_process_intent_skipped_para_pergunta_simples(): void
    {
        $result = $this->service()->processIntent('O que é Hyperflow?');

        $this->assertSame(MissionModeResult::OUTCOME_SKIPPED, $result->outcome);
        $this->assertFalse($result->activated());
        $this->assertNull($result->mission);
        $this->assertSame(0, AiMission::query()->count(), 'pergunta simples NUNCA cria AiMission record');
    }

    public function test_process_intent_cria_mission_decompoe_objetivos_e_planeja_workorders(): void
    {
        $result = $this->service()->processIntent(
            'Minha missão: implementar pipeline de cobrança SaaS até concluir, não pare antes de testar tudo.',
            ['primary_domain' => 'programming'],
        );

        $this->assertTrue($result->activated());
        $this->assertNotNull($result->mission);
        $this->assertSame(MissionFactoryService::TYPE_MISSION, $result->mission->mission_type);
        $this->assertSame(
            MissionLifecycleService::STATUS_PLANNED,
            $result->mission->status,
            'após processIntent a mission deve estar PLANNED (DRAFT → PLANNED)',
        );
        $this->assertGreaterThan(0, $result->objectives?->count() ?? 0);
        $this->assertGreaterThan(0, $result->workOrders?->count() ?? 0);
        $this->assertSame('programming', $result->mission->primary_domain);
        $this->assertSame(1, AiMission::query()->count());
    }

    public function test_obra_keyword_cria_mission_tipo_obra(): void
    {
        $result = $this->service()->processIntent('Vou construir uma obra completa: rewrite all do core de pagamentos');

        $this->assertTrue($result->activated());
        $this->assertSame(MissionFactoryService::TYPE_OBRA, $result->mission->mission_type);
        $this->assertSame(MissionFactoryService::RISK_HIGH, $result->mission->risk_level);
    }

    public function test_associate_trace_cria_evidence_ref_tipo_receipt(): void
    {
        $service = $this->service();
        $result = $service->processIntent('Esta é minha meta: organizar finanças até concluir');
        $this->assertTrue($result->activated());

        $service->associateTrace($result->mission, 'trace-uuid-fixture-1', 'sha256:deadbeef');

        $refs = $result->mission->evidenceRefs()->get();
        $this->assertCount(1, $refs);
        $this->assertSame(MissionEvidenceService::TYPE_RECEIPT, $refs->first()->evidence_type);
        $this->assertSame('ai_trace:trace-uuid-fixture-1', $refs->first()->evidence_ref);
    }

    public function test_certify_falha_sem_evidence_e_na_o_marca_completed(): void
    {
        $service = $this->service();
        $result = $service->processIntent('Missão: build feature ABC até concluir totalmente');

        // Mission está PLANNED. Precisa estar RUNNING ou CERTIFYING para certify().
        // Vamos transitar para RUNNING e tentar certify sem evidence.
        $lifecycle = app(MissionLifecycleService::class);
        $lifecycle->transition($result->mission, MissionLifecycleService::STATUS_RUNNING, ['actor_type' => 'test']);
        $result->mission->refresh();

        $certification = $service->certify($result->mission);

        $this->assertSame(MissionCertificationService::STATUS_FAILED, $certification->status);
        $result->mission->refresh();
        $this->assertNotSame(
            MissionLifecycleService::STATUS_COMPLETED,
            $result->mission->status,
            'completed NÃO pode ser alcançado sem evidence + cert passed',
        );
    }

    public function test_certify_passa_e_marca_completed_quando_evidence_completa(): void
    {
        $service = $this->service();
        $result = $service->processIntent(
            'Missão: implementar /health endpoint até passar nos testes e ficar pronto.',
        );
        $mission = $result->mission;
        $lifecycle = app(MissionLifecycleService::class);
        $evidence = app(MissionEvidenceService::class);

        // Move PLANNED → RUNNING.
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING, ['actor_type' => 'test']);
        $mission->refresh();

        // Cobre todos os DoD criteria do mission com um evidence_ref (factory cria 1 criterion default).
        $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'tests/HealthEndpointTest.php',
            'actor_type' => 'test',
        ]);

        $certification = $service->certify($mission);

        $this->assertSame(
            MissionCertificationService::STATUS_PASSED,
            $certification->status,
            'cert.checks: '.json_encode($certification->checked_requirements),
        );
        $mission->refresh();
        $this->assertSame(MissionLifecycleService::STATUS_COMPLETED, $mission->status);
        $this->assertNotEmpty($mission->certification_hash);
        $this->assertSame(64, strlen($mission->certification_hash), 'certification_hash deve ser SHA-256 hex (64 chars)');
    }

    public function test_certification_hash_é_determinístico_sobre_mesma_entrada(): void
    {
        // Determinismo do hashing canon: mesmo input JSON canônico → mesmo SHA-256.
        // Esse é o invariante que `AiMission.certification_hash` herda.
        $input = [
            'mission_id' => 'abc-123',
            'mission_uuid' => 'uuid-fixture',
            'status' => 'passed',
            'evidence_refs' => [1, 2, 3],
            'checked_requirements' => [
                ['requirement' => 'evidence_refs_exist', 'status' => 'passed'],
                ['requirement' => 'dod_has_criteria', 'status' => 'passed'],
            ],
        ];

        $h1 = MissionCanonicalHash::sha256($input);
        $h2 = MissionCanonicalHash::sha256($input);

        $this->assertSame($h1, $h2, 'mesmo input → mesmo hash');
        $this->assertSame(64, strlen($h1), 'SHA-256 hex = 64 chars');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $h1);

        // Reordenar keys NÃO muda o hash (canonical JSON com ksort).
        $reordered = [
            'evidence_refs' => [1, 2, 3],
            'mission_uuid' => 'uuid-fixture',
            'status' => 'passed',
            'mission_id' => 'abc-123',
            'checked_requirements' => [
                ['requirement' => 'evidence_refs_exist', 'status' => 'passed'],
                ['requirement' => 'dod_has_criteria', 'status' => 'passed'],
            ],
        ];
        $hReordered = MissionCanonicalHash::sha256($reordered);
        $this->assertSame($h1, $hReordered, 'ordem das keys não afeta hash (canonical JSON)');
    }

    public function test_run_checks_é_determinístico_para_mesma_mission(): void
    {
        // Garante que rodar runChecks 2x consecutivas em mission no MESMO
        // estado retorna hash idêntico (necessary para audit trail).
        $service = $this->service();
        $result = $service->processIntent('Missão: estabilizar API v2 até passar todos os testes');
        $mission = $result->mission;
        $lifecycle = app(MissionLifecycleService::class);
        $evidence = app(MissionEvidenceService::class);

        $lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING, ['actor_type' => 'test']);
        $mission->refresh();
        $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'tests/ApiV2Test.php',
            'actor_type' => 'test',
        ]);
        $mission->refresh();

        $cert = app(MissionCertificationService::class);
        $checks1 = $cert->runChecks($mission);
        $checks2 = $cert->runChecks($mission);
        $h1 = MissionCanonicalHash::sha256($checks1);
        $h2 = MissionCanonicalHash::sha256($checks2);

        $this->assertSame($h1, $h2, 'mission no mesmo estado → checks idênticos → mesmo hash');
    }

    public function test_snapshot_expoe_dados_minimos_para_control_plane(): void
    {
        $service = $this->service();
        $result = $service->processIntent('Missão: integrar webhook Stripe até concluir');
        $snapshot = $service->snapshot($result->mission);

        $this->assertSame('atlas.ai.mission_mode_snapshot.v1', $snapshot['schema_version']);
        $this->assertSame($result->mission->uuid, $snapshot['mission']['uuid']);
        $this->assertSame(MissionLifecycleService::STATUS_PLANNED, $snapshot['mission']['status']);
        $this->assertIsArray($snapshot['objectives']);
        $this->assertIsArray($snapshot['work_orders']);
        $this->assertIsArray($snapshot['next_actions']);
        $this->assertSame('start_execution', $snapshot['next_actions']['action']);
        $this->assertContains(MissionLifecycleService::STATUS_RUNNING, $snapshot['allowed_transitions']);
    }
}
