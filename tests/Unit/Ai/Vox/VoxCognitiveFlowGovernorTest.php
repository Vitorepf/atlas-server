<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\Governor\VoxCognitiveFlowGovernor;
use App\Services\Ai\Vox\VoxSchema;
use PHPUnit\Framework\TestCase;

final class VoxCognitiveFlowGovernorTest extends TestCase
{
    private function governor(): VoxCognitiveFlowGovernor
    {
        return new VoxCognitiveFlowGovernor();
    }

    public function test_dictation_safe_flow_allows_copy_insert_send_without_review(): void
    {
        $g = $this->governor()->govern(
            transcript: ['text' => 'anota esse texto para mim'],
            intentPacket: [
                'mode' => VoxSchema::MODE_DICTATION,
                'risk_class' => VoxSchema::RISK_R0,
                'goal' => '',
                'context_refs' => [['kind' => 'none', 'resolved' => true]],
            ],
            flowDecision: [
                'mode' => VoxSchema::MODE_DICTATION,
                'destination' => 'clipboard',
                'risk_class' => VoxSchema::RISK_R0,
                'confidence' => 'high',
                'needs_clarification' => false,
                'what_i_heard' => 'anota esse texto para mim',
                'what_i_understood' => 'Ditado local.',
                'what_i_will_do' => 'Devolver texto.',
                'why_this_flow' => 'Fala simples sem ação externa.',
            ],
        );

        $this->assertSame(VoxSchema::COGNITIVE_FLOW_GOVERNOR, $g['schema']);
        $this->assertSame('single_safe_action', $g['execution']['policy']);
        $this->assertSame(['copy', 'insert', 'send_to_atlas', 'cancel'], $g['execution']['allowed_actions']);
        $this->assertFalse($g['context']['missing']);
        $this->assertFalse($g['risk']['requires_confirmation']);
        $this->assertFalse($g['quality']['needs_review']);
        $this->assertFalse($g['guards']['raw_audio_accepted']);
        $this->assertFalse($g['guards']['paid_api_required']);
        $this->assertFalse($g['v7_unlock_allowed']);
    }

    public function test_ambiguous_reference_never_allows_action_before_clarification(): void
    {
        $g = $this->governor()->govern(
            transcript: ['text' => 'executa isso'],
            intentPacket: [
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'risk_class' => VoxSchema::RISK_R1,
                'context_refs' => [],
            ],
            flowDecision: [
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'destination' => 'none',
                'risk_class' => VoxSchema::RISK_R1,
                'confidence' => 'medium',
                'needs_clarification' => true,
                'clarifying_question' => 'O que exatamente você quer executar?',
            ],
        );

        $this->assertTrue($g['context']['missing']);
        $this->assertContains('intenção_clara', $g['context']['missing_items']);
        $this->assertContains('referência', $g['context']['missing_items']);
        $this->assertSame('no_action', $g['execution']['policy']);
        $this->assertSame(['ask', 'cancel'], $g['execution']['allowed_actions']);
        $this->assertSame('O que exatamente você quer executar?', $g['context']['clarifying_question']);
    }

    public function test_r4_destructive_request_is_blocked_even_when_flow_has_destination(): void
    {
        $g = $this->governor()->govern(
            transcript: ['text' => 'manda rm -rf no projeto'],
            intentPacket: [
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'risk_class' => VoxSchema::RISK_R4,
                'risk_reasoning' => 'Comando destrutivo detectado.',
            ],
            flowDecision: [
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'destination' => 'terminal_proposal',
                'risk_class' => VoxSchema::RISK_R4,
                'confidence' => 'high',
                'needs_clarification' => false,
            ],
        );

        $this->assertSame('blocked', $g['execution']['policy']);
        $this->assertSame(['cancel'], $g['execution']['allowed_actions']);
        $this->assertTrue($g['risk']['blocked']);
        $this->assertTrue($g['risk']['requires_confirmation']);
        $this->assertSame('Ação bloqueada por segurança.', $g['human_preview']['warning']);
        $this->assertContains('rm -rf', $g['intent']['user_words_preserved']);
        $this->assertFalse($g['guards']['terminal_execute']);
    }

    public function test_prompt_compile_requires_quality_but_stays_previewable(): void
    {
        $g = $this->governor()->govern(
            transcript: ['text' => 'cria um prompt pro Codex investigar o Atlas Vox sem editar nada'],
            intentPacket: [
                'mode' => VoxSchema::MODE_INTENT_COMPILE,
                'risk_class' => VoxSchema::RISK_R1,
                'goal' => 'Investigar o Atlas Vox sem editar nada',
                'provider_hint' => 'codex_cli',
            ],
            flowDecision: [
                'mode' => VoxSchema::MODE_INTENT_COMPILE,
                'destination' => 'codex',
                'risk_class' => VoxSchema::RISK_R1,
                'confidence' => 'high',
                'needs_clarification' => false,
            ],
            promptQuality: [
                'schema' => 'atlas.vox.prompt_quality.v1',
                'status' => 'pass',
                'issues' => [],
            ],
        );

        $this->assertSame('single_safe_action', $g['execution']['policy']);
        $this->assertTrue($g['quality']['prompt_quality_required']);
        $this->assertFalse($g['quality']['needs_review']);
        $this->assertSame('codex', $g['flow']['destination']);
        $this->assertContains('codex', $g['intent']['user_words_preserved']);
        $this->assertContains('não mexer', $g['intent']['user_words_preserved']);
    }

    public function test_composite_risky_flow_forces_step_by_step_confirmation(): void
    {
        $g = $this->governor()->govern(
            transcript: ['text' => 'cria um prompt e depois roda os testes'],
            intentPacket: [
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'risk_class' => VoxSchema::RISK_R3,
                'goal' => 'Criar prompt e rodar testes',
            ],
            flowDecision: [
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'destination' => 'codex',
                'risk_class' => VoxSchema::RISK_R3,
                'confidence' => 'high',
                'needs_clarification' => false,
                'composite' => [
                    'execution_policy' => VoxSchema::COMPOSITE_POLICY_STEP_BY_STEP,
                    'steps' => [
                        ['summary' => 'Criar prompt para Codex'],
                        ['summary' => 'Rodar testes depois da confirmação'],
                    ],
                ],
            ],
        );

        $this->assertSame('step_by_step_confirmation', $g['execution']['policy']);
        $this->assertSame(['confirm', 'cancel'], $g['execution']['allowed_actions']);
        $this->assertSame(['Rodar testes depois da confirmação'], $g['intent']['secondary']);
        $this->assertTrue($g['risk']['requires_confirmation']);
    }
}
