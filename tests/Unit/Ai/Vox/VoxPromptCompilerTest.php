<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\VoxPromptCompiler;
use App\Services\Ai\Vox\VoxSchema;
use PHPUnit\Framework\TestCase;

final class VoxPromptCompilerTest extends TestCase
{
    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function extracted(array $overrides = []): array
    {
        return array_replace([
            'goal' => 'Investigar o módulo Vox',
            'constraints' => ['sem editar arquivos'],
            'provider_hint' => 'codex_cli',
            'executor_hint' => 'none',
            'output_format' => 'diagnostic',
            'context_refs' => [['kind' => 'none', 'ref' => null, 'resolved' => true]],
            'risk_class' => VoxSchema::RISK_R1,
            'risk_markers' => [],
            'normalised_text' => 'Investigar o módulo Vox sem editar arquivos.',
        ], $overrides);
    }

    public function test_template_id_encodes_provider_and_output_format(): void
    {
        $r = (new VoxPromptCompiler())->compile('voz original', $this->extracted());
        $this->assertSame('builtin.intent_compile.codex_cli.diagnostic.pt-br@0.2.0', $r['compiled_prompt_template']);
    }

    public function test_unknown_provider_falls_back_to_local_template(): void
    {
        $r = (new VoxPromptCompiler())->compile('voz', $this->extracted([
            'provider_hint' => 'totally_invented_provider',
            'output_format' => 'plan',
        ]));
        $this->assertSame('builtin.intent_compile.local.plan.pt-br@0.2.0', $r['compiled_prompt_template']);
    }

    public function test_codex_header_is_used_for_codex_cli(): void
    {
        $r = (new VoxPromptCompiler())->compile('voz', $this->extracted(['provider_hint' => 'codex_cli']));
        $this->assertStringContainsString('Você é o Codex', $r['compiled_prompt']);
        $this->assertStringContainsString('## Objetivo', $r['compiled_prompt']);
        $this->assertStringContainsString('## Modo de trabalho', $r['compiled_prompt']);
        $this->assertStringContainsString('## Restrições', $r['compiled_prompt']);
        $this->assertStringContainsString('## Saída esperada', $r['compiled_prompt']);
        $this->assertStringContainsString('## Voz original', $r['compiled_prompt']);
    }

    public function test_claude_header_is_used_for_claude_cli(): void
    {
        $r = (new VoxPromptCompiler())->compile('voz', $this->extracted(['provider_hint' => 'claude_cli']));
        $this->assertStringContainsString('Você é o Claude Code', $r['compiled_prompt']);
    }

    public function test_safety_block_appears_for_r4(): void
    {
        $r = (new VoxPromptCompiler())->compile(
            'rm -rf /',
            $this->extracted([
                'risk_class' => VoxSchema::RISK_R4,
                'risk_markers' => ['rm_rf'],
                'output_format' => 'diff',
            ])
        );
        $this->assertStringContainsString('## Segurança', $r['compiled_prompt']);
        $this->assertStringContainsString('destrutiva', $r['compiled_prompt']);
        $this->assertStringContainsString('rm_rf', $r['compiled_prompt']);
    }

    public function test_safety_block_appears_for_r3(): void
    {
        $r = (new VoxPromptCompiler())->compile(
            'roda os testes',
            $this->extracted([
                'risk_class' => VoxSchema::RISK_R3,
                'executor_hint' => 'terminal_propose',
                'output_format' => 'plan',
            ])
        );
        $this->assertStringContainsString('## Segurança', $r['compiled_prompt']);
        $this->assertStringContainsString('R3', $r['compiled_prompt']);
    }

    public function test_no_safety_block_for_r0_to_r2(): void
    {
        foreach ([VoxSchema::RISK_R0, VoxSchema::RISK_R1, VoxSchema::RISK_R2] as $risk) {
            $r = (new VoxPromptCompiler())->compile('voz', $this->extracted(['risk_class' => $risk]));
            $this->assertStringNotContainsString('## Segurança', $r['compiled_prompt'], "risk={$risk}");
        }
    }

    public function test_constraints_listed_verbatim_and_pinned_as_literal(): void
    {
        $r = (new VoxPromptCompiler())->compile('voz', $this->extracted([
            'constraints' => ['não editar arquivos', 'sem refatorar amplo'],
        ]));
        $this->assertStringContainsString('- não editar arquivos', $r['compiled_prompt']);
        $this->assertStringContainsString('- sem refatorar amplo', $r['compiled_prompt']);
        $this->assertStringContainsString('Restrições acima são literais', $r['compiled_prompt']);
    }

    public function test_resolved_context_refs_are_surfaced_with_kind(): void
    {
        $r = (new VoxPromptCompiler())->compile('voz', $this->extracted([
            'context_refs' => [
                ['kind' => 'file', 'ref' => '/path/to/voxCompiler.php', 'resolved' => true],
                ['kind' => 'selection', 'ref' => 'sel-123', 'resolved' => false],
            ],
        ]));
        $this->assertStringContainsString('[file] /path/to/voxCompiler.php', $r['compiled_prompt']);
        $this->assertStringContainsString('NÃO resolvido — confirmar com operador', $r['compiled_prompt']);
    }

    public function test_no_provider_or_llm_or_tool_words_leak_into_compiled_prompt(): void
    {
        $r = (new VoxPromptCompiler())->compile('voz', $this->extracted());
        $banned = [
            'Considere os seguintes pontos',
            'Vamos juntos',
            'Você é um assistente útil',
            'Por favor analise',
        ];
        foreach ($banned as $needle) {
            $this->assertStringNotContainsString($needle, $r['compiled_prompt']);
        }
    }

    public function test_output_format_text_uses_text_template_id(): void
    {
        $r = (new VoxPromptCompiler())->compile('voz', $this->extracted([
            'output_format' => 'text',
        ]));
        $this->assertSame('builtin.intent_compile.codex_cli.text.pt-br@0.2.0', $r['compiled_prompt_template']);
    }
}
