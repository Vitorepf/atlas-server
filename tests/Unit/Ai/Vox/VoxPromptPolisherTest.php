<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\VoxPromptPolisher;
use PHPUnit\Framework\TestCase;

final class VoxPromptPolisherTest extends TestCase
{
    public function test_template_id_and_basic_shape(): void
    {
        $polish = (new VoxPromptPolisher())->polish('teste simples');
        $this->assertSame(VoxPromptPolisher::TEMPLATE_ID, $polish['compiled_prompt_template']);
        $this->assertIsString($polish['compiled_prompt']);
        $this->assertNotSame('', $polish['compiled_prompt']);
        $this->assertIsArray($polish['constraints']);
        $this->assertIsArray($polish['transformations_applied']);
    }

    public function test_strips_inline_fillers_tipo_assim_ai_ne(): void
    {
        $result = (new VoxPromptPolisher())
            ->polish('quero meio que fazer isso assim agora aí, tipo, com calma né.');

        $this->assertStringNotContainsString(' tipo ', $result['compiled_prompt']);
        $this->assertStringNotContainsString(' tipo,', $result['compiled_prompt']);
        $this->assertStringNotContainsString(' assim ', $result['compiled_prompt']);
        $this->assertStringNotContainsString(' aí,', $result['compiled_prompt']);
        $this->assertStringNotContainsString(' né.', $result['compiled_prompt']);
        $this->assertStringNotContainsString(' meio que ', $result['compiled_prompt']);
        $this->assertContains('fillers_removed', $result['transformations_applied']);
    }

    public function test_preserves_negation_clauses_verbatim(): void
    {
        $result = (new VoxPromptPolisher())
            ->polish('investiga esse módulo, sem editar arquivos e não tocar no banco.');

        $this->assertStringContainsString('sem editar arquivos', $result['compiled_prompt']);
        $this->assertStringContainsString('não tocar no banco', $result['compiled_prompt']);

        $this->assertContains('sem editar arquivos', $result['constraints']);
        $this->assertContains('não tocar no banco', $result['constraints']);
    }

    public function test_preserves_antes_de_constraint(): void
    {
        $result = (new VoxPromptPolisher())
            ->polish('faz o plano antes de implementar qualquer coisa.');

        $this->assertContains('antes de implementar qualquer coisa', $result['constraints']);
    }

    public function test_does_not_invent_provider_when_none_mentioned(): void
    {
        $result = (new VoxPromptPolisher())->polish('limpa esse texto pra colar no inbox.');
        $this->assertSame('local', $result['provider_hint']);
    }

    public function test_detects_codex_provider_hint(): void
    {
        $result = (new VoxPromptPolisher())->polish('manda o codex investigar isso.');
        $this->assertSame('codex_cli', $result['provider_hint']);
        $this->assertStringContainsString('Codex', $result['compiled_prompt']);
    }

    public function test_detects_claude_provider_hint(): void
    {
        $result = (new VoxPromptPolisher())->polish('quero o claude analisando isso.');
        $this->assertSame('claude_cli', $result['provider_hint']);
        $this->assertStringContainsString('Claude', $result['compiled_prompt']);
    }

    public function test_detects_both_providers_as_auto(): void
    {
        $result = (new VoxPromptPolisher())
            ->polish('chama o codex e o claude pra discutir.');
        $this->assertSame('auto', $result['provider_hint']);
    }

    public function test_normalises_atlas_term_casing(): void
    {
        $result = (new VoxPromptPolisher())->polish(
            'roda no tauri e no live kit, mas o macbook não, ok inbox e workbench.'
        );
        $this->assertStringContainsString('Tauri', $result['compiled_prompt']);
        $this->assertStringContainsString('LiveKit', $result['compiled_prompt']);
        $this->assertStringContainsString('MacBook', $result['compiled_prompt']);
        $this->assertStringContainsString('Inbox', $result['compiled_prompt']);
        $this->assertStringContainsString('Workbench', $result['compiled_prompt']);
    }

    public function test_punctuates_capitalises_first_and_terminal_period(): void
    {
        $result = (new VoxPromptPolisher())->polish('codex deve analisar o módulo voice');
        $compiled = $result['compiled_prompt'];
        $this->assertSame('C', mb_substr($compiled, 0, 1));
        $this->assertContains(mb_substr($compiled, -1), ['.', '!', '?']);
    }

    public function test_minimal_polish_on_already_clean_text(): void
    {
        $clean = 'Documente o fluxo de captura sem editar arquivos.';
        $result = (new VoxPromptPolisher())->polish($clean);
        // Should remain identical (already capitalised, terminal period present,
        // no fillers, no extra spaces).
        $this->assertSame($clean, $result['compiled_prompt']);
    }

    public function test_collapses_runs_of_whitespace(): void
    {
        $result = (new VoxPromptPolisher())
            ->polish("  manda    o   codex     olhar  isso  ");
        $this->assertStringNotContainsString('  ', $result['compiled_prompt']);
        $this->assertContains('whitespace_normalised', $result['transformations_applied']);
    }

    public function test_extracts_short_goal_from_first_sentence(): void
    {
        $result = (new VoxPromptPolisher())
            ->polish('investiga o módulo voice. depois faz o plano.');
        $this->assertNotSame('', $result['goal']);
        $this->assertStringContainsString('investiga', mb_strtolower($result['goal']));
        // Goal carries no trailing punctuation.
        $this->assertNotSame('.', mb_substr($result['goal'], -1));
    }
}
