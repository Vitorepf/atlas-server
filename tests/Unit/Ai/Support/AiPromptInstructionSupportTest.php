<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Support;

use App\Services\Ai\Support\AiPromptInstructionSupport;
use PHPUnit\Framework\TestCase;

/**
 * Pure prompt instruction sections — no AiPromptBuilder, no skills, no I/O.
 */
final class AiPromptInstructionSupportTest extends TestCase
{
    public function test_workflow_instructions_maps_known_modes_and_defaults_empty(): void
    {
        $dev = AiPromptInstructionSupport::workflowInstructions([
            'payload' => ['atlas_workflow_mode' => 'dev'],
        ]);
        $this->assertStringContainsString('# Modo de trabalho: Desenvolvimento pesado no Mac', $dev);
        $this->assertStringContainsString('executor técnico do Atlas', $dev);

        $review = AiPromptInstructionSupport::workflowInstructions([
            'payload' => ['atlas_workflow_mode' => 'review'],
        ]);
        $this->assertStringContainsString('# Modo de trabalho: Revisar', $review);

        $this->assertSame('', AiPromptInstructionSupport::workflowInstructions([
            'payload' => ['atlas_workflow_mode' => 'unknown_mode'],
        ]));
        $this->assertSame('', AiPromptInstructionSupport::workflowInstructions([]));
    }

    public function test_atlas_mode_instructions_requires_contract_and_projects_programming_rules(): void
    {
        $this->assertSame('', AiPromptInstructionSupport::atlasModeInstructions([]));
        $this->assertSame('', AiPromptInstructionSupport::atlasModeInstructions([
            'payload' => ['atlas_mode_contract' => []],
        ]));

        $section = AiPromptInstructionSupport::atlasModeInstructions([
            'payload' => [
                'atlas_mode_contract' => [
                    'mode' => 'programming',
                    'objective' => 'entregar patch seguro',
                    'expected_output' => ['diff', 'testes'],
                ],
                'quality_policy' => [
                    'tests_required' => true,
                    'scope' => 'minimal',
                ],
            ],
        ]);

        $this->assertStringContainsString('# Modo Atlas AI', $section);
        $this->assertStringContainsString('Modo: programming', $section);
        $this->assertStringContainsString('Objetivo: entregar patch seguro', $section);
        $this->assertStringContainsString('- diff', $section);
        $this->assertStringContainsString('- testes', $section);
        $this->assertStringContainsString('trabalho de engenharia', $section);
        $this->assertStringContainsString('tests_required: true', $section);
        $this->assertStringContainsString('scope: minimal', $section);
    }

    public function test_permission_instructions_danger_scope_and_empty_guard(): void
    {
        $this->assertSame('', AiPromptInstructionSupport::permissionInstructions([]));

        $read = AiPromptInstructionSupport::permissionInstructions([
            'payload' => [
                'tool_permissions' => [
                    'mode' => 'write',
                    'workspace' => '/tmp/ws',
                    'capabilities' => ['read_files', 'write_files'],
                    'allowed_roots' => ['/tmp/ws'],
                    'codex_sandbox' => 'workspace-write',
                ],
            ],
        ]);
        $this->assertStringContainsString('# Runtime de ferramentas Atlas', $read);
        $this->assertStringContainsString('Modo autorizado: write', $read);
        $this->assertStringContainsString('Workspace autorizado: /tmp/ws', $read);
        $this->assertStringContainsString('read_files, write_files', $read);
        $this->assertStringContainsString('Em modo read/write, mantenha leitura', $read);

        $danger = AiPromptInstructionSupport::permissionInstructions([
            'payload' => [
                'tool_permissions' => [
                    'mode' => 'danger',
                    'workspace' => '/tmp/ws',
                    'allowed_roots' => ['/tmp/ws', '/tmp/other'],
                ],
            ],
        ]);
        $this->assertStringContainsString('Em modo danger, voce pode ler, escrever e executar', $danger);
        $this->assertStringContainsString('/tmp/ws, /tmp/other', $danger);
    }

    public function test_output_contract_appends_dev_rule(): void
    {
        $base = AiPromptInstructionSupport::outputContract([]);
        $this->assertStringContainsString('# Contrato de saida Atlas', $base);
        $this->assertStringNotContainsString('prefira resumo de mudanças', $base);

        $dev = AiPromptInstructionSupport::outputContract([
            'payload' => ['atlas_workflow_mode' => 'dev'],
        ]);
        $this->assertStringContainsString('prefira resumo de mudanças e validação', $dev);
    }

    public function test_specialist_flow_empty_and_delegated_defaults(): void
    {
        $this->assertSame('', AiPromptInstructionSupport::specialistFlowInstructions([]));

        $section = AiPromptInstructionSupport::specialistFlowInstructions([
            'payload' => [
                'specialist_flow_execution' => [
                    'flow_id' => 'f1',
                    'handler_id' => 'h1',
                    'provider_prompt_contract' => ['Do X'],
                ],
            ],
        ]);
        $this->assertStringContainsString('Flow: f1', $section);
        $this->assertStringContainsString('Handler: h1', $section);
        $this->assertStringContainsString('- Do X', $section);
        $this->assertStringContainsString('- status: not_delegated', $section);
    }

    public function test_voice_clamps_sentence_and_char_bounds(): void
    {
        $section = AiPromptInstructionSupport::voiceResponseInstructions([
            'payload' => [
                'voice_response_contract' => [
                    'mode' => 'spoken_concise',
                    'max_sentences' => 99,
                    'target_chars' => 10,
                    'hard_max_chars' => 5000,
                ],
            ],
        ]);

        $this->assertStringContainsString('Use no maximo 5 frases curtas', $section);
        // target clamped to min 160; hard max min of 1200 vs max(target, hard)
        $this->assertStringContainsString('Mira de tamanho: ate 160 caracteres; limite duro: 1200 caracteres.', $section);
    }

    public function test_persistent_context_rejects_wrong_schema(): void
    {
        $this->assertSame('', AiPromptInstructionSupport::persistentContextPromptSection([
            'payload' => [
                'persistent_context' => [
                    'schema_version' => 'wrong',
                    'status' => 'ready',
                ],
            ],
        ]));
    }

    public function test_awis_runtime_rejects_wrong_schema(): void
    {
        $this->assertSame('', AiPromptInstructionSupport::awisRuntimeContextPromptSection([
            'payload' => [
                'awis_runtime_context' => [
                    'schema_version' => 'wrong',
                    'workspace' => ['name' => 'x'],
                ],
            ],
        ]));
    }
}
