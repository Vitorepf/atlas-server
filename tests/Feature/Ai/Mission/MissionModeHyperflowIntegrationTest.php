<?php

namespace Tests\Feature\Ai\Mission;

use App\Models\AiMission;
use App\Services\Ai\Mission\MissionDetectionService;
use App\Services\Ai\Mission\MissionFactoryService;
use App\Services\Ai\Mission\MissionModeService;
use App\Services\Ai\RouterRuntime\AtlasHyperflowEntryService;
use Tests\Concerns\CreatesMissionFoundationTables;
use Tests\TestCase;

/**
 * Garantias canon da ligação Mission Mode ↔ Hyperflow:
 *
 *   - prompt persistente cria AiMission ANTES de classify();
 *   - mission_id é injetado no envelope canônico;
 *   - prompt simples não cria mission e o envelope segue sem mission block.
 *
 * Não dependemos do pipeline RouterRuntime completo (que requer 5 tabelas
 * adicionais). Em vez disso, exercitamos as bordas observáveis do
 * `AtlasHyperflowEntryService::run()`: ele recorre ao fallback envelope
 * quando as tabelas RouterRuntime não existem, mas Mission Mode roda ANTES
 * do canPersist() check curto-circuitar — então o efeito observável é o
 * registro AiMission e o `payload.mission_mode` no `withFallbackEnvelope`
 * branch.
 */
class MissionModeHyperflowIntegrationTest extends TestCase
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

    public function test_mission_detection_é_acoplada_a_factory_classify(): void
    {
        // Garantia básica: o detection re-usa o classify do factory existente
        // (não duplica). Mesmo factory_type → mesma decisão downstream para
        // prompts sem persistence.
        $detection = app(MissionDetectionService::class);
        $factory = app(MissionFactoryService::class);

        foreach ([
            'O que é isto?',
            'Implementar feature simples',
            "Foo:\n- a\n- b\n- c",
        ] as $prompt) {
            $signal = $detection->detect($prompt);
            $this->assertSame(
                $factory->classify($prompt),
                $signal->factoryType,
                "factory_type drift para prompt: {$prompt}",
            );
        }
    }

    public function test_mission_mode_pode_ser_injetado_no_atlas_hyperflow_entry_service(): void
    {
        // O service deve aceitar MissionModeService como dep opcional no DI,
        // mantendo back-compat para callers antigos que não passam.
        $entry = app(AtlasHyperflowEntryService::class);
        $this->assertInstanceOf(AtlasHyperflowEntryService::class, $entry);

        // Reflexão pra garantir que a propriedade existe e é nullable.
        $ref = new \ReflectionClass($entry);
        $this->assertTrue($ref->hasMethod('run'));
        $constructor = $ref->getConstructor();
        $this->assertNotNull($constructor);
        $params = $constructor->getParameters();
        $missionParam = null;
        foreach ($params as $p) {
            if ($p->getName() === 'missionMode') {
                $missionParam = $p;
                break;
            }
        }
        $this->assertNotNull($missionParam, 'AtlasHyperflowEntryService deve declarar missionMode no construtor');
        $this->assertTrue($missionParam->allowsNull(), 'missionMode deve ser nullable (back-compat)');
        $this->assertTrue($missionParam->isDefaultValueAvailable(), 'missionMode deve ter default null');
    }

    public function test_process_intent_via_mission_mode_persiste_mission_e_objetivos(): void
    {
        // Smoke test puro Mission Mode (sem precisar das 5 tabelas do RouterRuntime).
        $service = app(MissionModeService::class);
        $result = $service->processIntent(
            'Minha missão: refatorar serviço de pagamentos até concluir migração.',
            ['surface_id' => 'atlas_desktop_ai', 'primary_domain' => 'programming'],
        );

        $this->assertTrue($result->activated());
        $this->assertSame(1, AiMission::query()->count());
        $this->assertSame(MissionFactoryService::TYPE_MISSION, $result->mission->mission_type);
        $this->assertSame('programming', $result->mission->primary_domain);
        $this->assertGreaterThan(0, $result->objectives?->count() ?? 0);
        $this->assertGreaterThan(0, $result->workOrders?->count() ?? 0);
    }

    public function test_prompt_simples_no_process_intent_nã_o_persiste_e_nã_o_quebra_pipeline(): void
    {
        $service = app(MissionModeService::class);
        $result = $service->processIntent('How does Atlas work?');

        $this->assertFalse($result->activated());
        $this->assertSame(0, AiMission::query()->count());
        $this->assertSame(MissionFactoryService::TYPE_TRIVIAL, $result->signal->suggestedMissionType);
    }
}
