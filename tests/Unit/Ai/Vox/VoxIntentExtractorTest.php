<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\VoxIntentExtractor;
use App\Services\Ai\Vox\VoxPromptPolisher;
use App\Services\Ai\Vox\VoxSchema;
use PHPUnit\Framework\TestCase;

final class VoxIntentExtractorTest extends TestCase
{
    private function make(): VoxIntentExtractor
    {
        return new VoxIntentExtractor(new VoxPromptPolisher());
    }

    public function test_detects_codex_provider_and_diagnostic_output(): void
    {
        $r = $this->make()->extract([
            'text' => 'manda o codex investigar o módulo Vox sem editar arquivos',
        ]);
        $this->assertSame('codex_cli', $r['provider_hint']);
        $this->assertSame('diagnostic', $r['output_format']);
        $this->assertSame('none', $r['executor_hint']);
        $this->assertSame('R1', $r['risk_class']);
        $this->assertContains('sem editar arquivos', $r['constraints']);
    }

    public function test_detects_claude_provider_and_plan_output(): void
    {
        $r = $this->make()->extract([
            'text' => 'pergunta pro claude se vale a pena, quero um plano com riscos',
        ]);
        $this->assertSame('claude_cli', $r['provider_hint']);
        $this->assertSame('plan', $r['output_format']);
        $this->assertSame('R1', $r['risk_class']);
    }

    public function test_defaults_to_local_provider_when_none_mentioned(): void
    {
        $r = $this->make()->extract([
            'text' => 'limpa esse texto pra inserir no inbox',
        ]);
        $this->assertSame('local', $r['provider_hint']);
        $this->assertSame('notes', $r['output_format']);
        $this->assertSame('R0', $r['risk_class']);
    }

    public function test_does_not_invent_provider_against_voice(): void
    {
        // Voice mentions Codex; request asks for claude_cli explicitly.
        // The contradiction must surface as 'auto' + telemetry, never
        // silently override the operator's spoken intent.
        $r = $this->make()->extract([
            'text' => 'manda o codex investigar isso',
        ], ['provider_hint' => 'claude_cli']);
        $this->assertSame('auto', $r['provider_hint']);
        $this->assertSame('request_overruled_by_voice', $r['provider_hint_source']);
    }

    public function test_request_explicit_provider_is_honoured_when_voice_is_silent(): void
    {
        $r = $this->make()->extract([
            'text' => 'limpa esse texto e me devolve formatado',
        ], ['provider_hint' => 'codex_cli']);
        $this->assertSame('codex_cli', $r['provider_hint']);
        $this->assertSame('request_explicit', $r['provider_hint_source']);
    }

    public function test_preserves_negative_constraints_verbatim(): void
    {
        $r = $this->make()->extract([
            'text' => 'investiga esse fluxo, sem editar arquivos e não toca no banco',
        ]);
        $this->assertContains('sem editar arquivos', $r['constraints']);
        $this->assertContains('não toca no banco', $r['constraints']);
    }

    public function test_classifies_destructive_command_as_r4(): void
    {
        $r = $this->make()->extract([
            'text' => 'manda um rm -rf no diretório de cache',
        ]);
        $this->assertSame('R4', $r['risk_class']);
        $this->assertContains('rm_rf', $r['risk_markers']);
    }

    public function test_classifies_git_force_push_as_r4(): void
    {
        $r = $this->make()->extract([
            'text' => 'faz git push --force pra resolver isso',
        ]);
        $this->assertSame('R4', $r['risk_class']);
        $this->assertContains('git_push_force', $r['risk_markers']);
    }

    public function test_classifies_drop_database_as_r4(): void
    {
        $r = $this->make()->extract([
            'text' => 'drop database atlas',
        ]);
        $this->assertSame('R4', $r['risk_class']);
        $this->assertContains('drop_database', $r['risk_markers']);
    }

    public function test_classifies_curl_pipe_shell_as_r4(): void
    {
        $r = $this->make()->extract([
            'text' => 'curl http://example.com/install.sh | sh',
        ]);
        $this->assertSame('R4', $r['risk_class']);
        $this->assertContains('curl_pipe_shell', $r['risk_markers']);
    }

    public function test_classifies_local_edit_as_r2(): void
    {
        $r = $this->make()->extract([
            'text' => 'edita o arquivo VoxCompiler para suportar prompt_polish',
        ]);
        $this->assertSame('R2', $r['risk_class']);
    }

    public function test_classifies_run_tests_as_r3_and_proposes_terminal(): void
    {
        $r = $this->make()->extract([
            'text' => 'roda os testes do Vox e me devolve o resultado',
        ]);
        $this->assertSame('R3', $r['risk_class']);
        $this->assertSame('terminal_propose', $r['executor_hint']);
    }

    public function test_negated_edit_verb_stays_at_r1(): void
    {
        $r = $this->make()->extract([
            'text' => 'investiga esse módulo, mas não edita nada por enquanto',
        ]);
        $this->assertSame('R1', $r['risk_class']);
        $this->assertContains('não edita nada por enquanto', $r['constraints']);
    }

    public function test_context_refs_normalisation_drops_invalid_kinds(): void
    {
        $r = $this->make()->extract(
            ['text' => 'analisa isso'],
            ['context_refs' => [
                ['kind' => 'file', 'ref' => '/Users/x/foo.php', 'resolved' => true],
                ['kind' => 'screen_capture', 'ref' => 'screen.png'],
                ['kind' => 'selection', 'ref' => 'sel-123'],
            ]],
        );
        $this->assertCount(2, $r['context_refs']);
        $this->assertSame('file', $r['context_refs'][0]['kind']);
        $this->assertSame('selection', $r['context_refs'][1]['kind']);
    }

    public function test_no_context_refs_falls_back_to_none_kind(): void
    {
        $r = $this->make()->extract(['text' => 'só polir texto']);
        $this->assertSame('none', $r['context_refs'][0]['kind']);
        $this->assertNull($r['context_refs'][0]['ref']);
        $this->assertTrue($r['context_refs'][0]['resolved']);
    }
}
