<?php

namespace Tests\Feature\Ai\Mission;

use App\Services\Ai\Mission\MissionDetectionService;
use App\Services\Ai\Mission\MissionFactoryService;
use App\Services\Ai\Mission\MissionSignal;
use Tests\TestCase;

class MissionDetectionServiceTest extends TestCase
{
    private function service(): MissionDetectionService
    {
        return app(MissionDetectionService::class);
    }

    public function test_pergunta_simples_nao_vira_mission_pesada(): void
    {
        $signal = $this->service()->detect('Como funciona o Hyperflow?');

        $this->assertInstanceOf(MissionSignal::class, $signal);
        $this->assertFalse($signal->shouldActivateMissionMode, 'pergunta simples não pode ativar Mission Mode');
        $this->assertSame(MissionFactoryService::TYPE_TRIVIAL, $signal->suggestedMissionType);
        $this->assertSame([], $signal->persistenceKeywords);
        $this->assertSame([], $signal->obraKeywords);
        $this->assertLessThanOrEqual(0.40, $signal->confidence);
    }

    public function test_prompt_com_meta_ate_concluir_vira_mission(): void
    {
        $signal = $this->service()->detect('Esta é minha meta: organizar finanças até concluir o ano fiscal, não pare até resolver.');

        $this->assertTrue($signal->shouldActivateMissionMode);
        $this->assertSame(MissionFactoryService::TYPE_MISSION, $signal->suggestedMissionType);
        $this->assertNotEmpty($signal->persistenceKeywords);
        $this->assertGreaterThanOrEqual(0.84, $signal->confidence);
        $this->assertStringStartsWith('persistence_keyword_match', $signal->reason);
    }

    public function test_prompt_com_obra_promove_para_obra(): void
    {
        $signal = $this->service()->detect('Quero construir uma obra completa: rewrite all do sistema de pagamentos.');

        $this->assertTrue($signal->shouldActivateMissionMode);
        $this->assertSame(MissionFactoryService::TYPE_OBRA, $signal->suggestedMissionType);
        $this->assertNotEmpty($signal->obraKeywords);
        $this->assertGreaterThanOrEqual(0.90, $signal->confidence);
        $this->assertStringStartsWith('obra_keyword_match', $signal->reason);
    }

    public function test_task_curta_sem_persistence_nao_ativa_mission_mode(): void
    {
        // factory.classify deve retornar TASK (action verb + ≤200 chars)
        $signal = $this->service()->detect('Implementar feature de login com Google');

        $this->assertSame(MissionFactoryService::TYPE_TASK, $signal->factoryType);
        $this->assertFalse(
            $signal->shouldActivateMissionMode,
            'task curta sem persistence signal NUNCA pode ativar Mission Mode (evita poluir DB)',
        );
    }

    public function test_factory_classified_mission_via_semicolons_ativa_mission_mode(): void
    {
        // factory.classify conta `;` como bullet boundary; 3 semicolons → 3 bullets ≥ 2 → MISSION.
        $signal = $this->service()->detect('Implementar checkout; adicionar form; adicionar gateway; adicionar testes');

        $this->assertSame(MissionFactoryService::TYPE_MISSION, $signal->suggestedMissionType);
        $this->assertTrue($signal->shouldActivateMissionMode);
    }

    public function test_keywords_em_ingles_sao_detectadas(): void
    {
        $signal = $this->service()->detect('My mission: refactor the auth service, do not stop until completed.');

        $this->assertTrue($signal->shouldActivateMissionMode);
        $this->assertContains('mission', $signal->persistenceKeywords);
        // "do not stop" matches.
        $this->assertContains('do not stop', $signal->persistenceKeywords);
    }

    public function test_signal_serializa_para_array_canonico(): void
    {
        $signal = $this->service()->detect('Implementar feature simples');
        $array = $signal->toArray();

        $this->assertSame(MissionSignal::SCHEMA_VERSION, $array['schema_version']);
        $this->assertArrayHasKey('should_activate_mission_mode', $array);
        $this->assertArrayHasKey('suggested_mission_type', $array);
        $this->assertArrayHasKey('persistence_keywords', $array);
        $this->assertArrayHasKey('obra_keywords', $array);
        $this->assertArrayHasKey('factory_type', $array);
        $this->assertArrayHasKey('confidence', $array);
        $this->assertArrayHasKey('reason', $array);
        $this->assertArrayHasKey('normalized_intent', $array);
    }

    public function test_normalizacao_remove_acentos_e_case(): void
    {
        // Mesmo prompt com acentos deve ser equivalente ao sem acentos.
        $signalAccent = $this->service()->detect('Esta é minha missão: completar');
        $signalNoAccent = $this->service()->detect('Esta e minha missao: completar');

        $this->assertSame($signalAccent->suggestedMissionType, $signalNoAccent->suggestedMissionType);
        $this->assertSame($signalAccent->persistenceKeywords, $signalNoAccent->persistenceKeywords);
    }
}
