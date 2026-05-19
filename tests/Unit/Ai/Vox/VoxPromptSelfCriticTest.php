<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\VoxIntentExtractor;
use App\Services\Ai\Vox\VoxPromptCompiler;
use App\Services\Ai\Vox\VoxPromptPolisher;
use App\Services\Ai\Vox\VoxPromptSelfCritic;
use App\Services\Ai\Vox\VoxSchema;
use PHPUnit\Framework\TestCase;

/**
 * Atlas Vox V6.5 · Prompt Self-Critic — testes determinísticos.
 *
 * Cobre os 13 critérios da muralha + reparo + needs_review + envelope
 * canônico. Cada teste é puro: chama `compile()` ou injeta um shape
 * sintético direto no `review()`. Nenhuma chamada de rede, nenhuma LLM.
 */
final class VoxPromptSelfCriticTest extends TestCase
{
    /**
     * @param  array<string,mixed>  $overrides
     * @return array{compiled:array<string,mixed>, extracted:array<string,mixed>, voice:string}
     */
    private function compileFromVoice(string $voice, array $overrides = []): array
    {
        $extractor = new VoxIntentExtractor(new VoxPromptPolisher());
        $extracted = $extractor->extract(
            ['text' => $voice, 'session_id' => 's', 'transcript_id' => 't'],
            $overrides,
        );
        $compiler = new VoxPromptCompiler();
        $compiled = $compiler->compile($voice, [
            'goal' => $extracted['goal'],
            'constraints' => $extracted['constraints'],
            'provider_hint' => $extracted['provider_hint'],
            'executor_hint' => $extracted['executor_hint'],
            'output_format' => $extracted['output_format'],
            'context_refs' => $extracted['context_refs'],
            'risk_class' => $extracted['risk_class'],
            'risk_markers' => $extracted['risk_markers'],
            'normalised_text' => $extracted['normalised_text'],
        ]);

        return [
            'compiled' => $compiled,
            'extracted' => $extracted,
            'voice' => $voice,
        ];
    }

    /**
     * @param  array<string,mixed>  $extractedOverrides
     * @return array<string,mixed>
     */
    private function syntheticCompiled(string $template, string $body): array
    {
        return [
            'compiled_prompt' => $body,
            'compiled_prompt_template' => $template,
            'sections' => [],
        ];
    }

    // ── Envelope · contrato canônico ──────────────────────────────────

    public function test_envelope_uses_canonical_schema_and_version(): void
    {
        $out = $this->compileFromVoice('Codex investiga o overlay sem mexer no kernel');
        $q = $out['compiled']['prompt_quality'];

        $this->assertSame(VoxPromptSelfCritic::SCHEMA, $q['schema']);
        $this->assertSame(VoxPromptSelfCritic::VERSION, $q['version']);
        $this->assertIsFloat($q['score']);
        $this->assertGreaterThanOrEqual(0.0, $q['score']);
        $this->assertLessThanOrEqual(1.0, $q['score']);
        $this->assertContains($q['status'], [
            VoxPromptSelfCritic::STATUS_PASS,
            VoxPromptSelfCritic::STATUS_WARN,
            VoxPromptSelfCritic::STATUS_FAIL,
        ]);
        $this->assertIsArray($q['issues']);
        $this->assertIsBool($q['needs_review']);
        $this->assertIsBool($q['repaired']);
        $this->assertIsArray($q['checks']);
        $this->assertIsArray($q['repair_log']);
    }

    public function test_envelope_has_all_13_checks(): void
    {
        $out = $this->compileFromVoice('analisa o estado do canon V6 sem editar');
        $q = $out['compiled']['prompt_quality'];

        foreach ([
            'has_canonical_sections', 'has_goal', 'has_expected_output',
            'has_voz_original', 'negations_preserved', 'universal_vetoes_present',
            'no_boilerplate', 'risk_not_softened', 'no_unauthorized_action',
            'provider_not_invented', 'provider_template_match',
            'no_read_only_contradiction', 'minimum_useful_prompt',
        ] as $name) {
            $this->assertArrayHasKey($name, $q['checks'], "check '$name' ausente");
        }
    }

    // ── #1 has_canonical_sections ─────────────────────────────────────

    public function test_canonical_sections_pass_when_all_present(): void
    {
        $out = $this->compileFromVoice('Codex investiga o overlay sem mexer no kernel');
        $q = $out['compiled']['prompt_quality'];
        $this->assertTrue($q['checks']['has_canonical_sections']);
    }

    public function test_canonical_sections_fail_when_block_missing(): void
    {
        $critic = new VoxPromptSelfCritic();
        $synthetic = $this->syntheticCompiled(
            'builtin.intent_compile.local.text.pt-br@0.2.0',
            "## Contexto\nFoo\n## Objetivo\nX",
        );
        $envelope = $critic->review('voz', $synthetic, [
            'goal' => 'X', 'constraints' => [], 'output_format' => 'text',
            'risk_class' => VoxSchema::RISK_R0, 'provider_hint' => 'local',
        ]);
        $this->assertFalse($envelope['checks']['has_canonical_sections']);
        $this->assertContains('canonical_sections_missing', $envelope['issues']);
    }

    // ── #2 has_goal ───────────────────────────────────────────────────

    public function test_has_goal_pass_with_real_objective(): void
    {
        $out = $this->compileFromVoice('Codex investiga por que o teste de microfone está quebrando no overlay');
        $this->assertTrue($out['compiled']['prompt_quality']['checks']['has_goal']);
    }

    public function test_has_goal_fail_with_placeholder(): void
    {
        $critic = new VoxPromptSelfCritic();
        $synthetic = $this->syntheticCompiled(
            'builtin.intent_compile.local.text.pt-br@0.2.0',
            "## Contexto\n## Objetivo\n(objetivo não declarado explicitamente)\n## Saída esperada\n- X",
        );
        $envelope = $critic->review('a', $synthetic, [
            'goal' => '', 'constraints' => [], 'output_format' => 'text',
            'risk_class' => VoxSchema::RISK_R0, 'provider_hint' => 'local',
        ]);
        $this->assertFalse($envelope['checks']['has_goal']);
        $this->assertContains('goal_missing_or_placeholder', $envelope['issues']);
    }

    // ── #3 has_expected_output ───────────────────────────────────────

    public function test_has_expected_output_pass(): void
    {
        $out = $this->compileFromVoice('Codex investiga o overlay sem mexer no kernel');
        $this->assertTrue($out['compiled']['prompt_quality']['checks']['has_expected_output']);
    }

    public function test_has_expected_output_fail_when_block_has_no_bullet(): void
    {
        $critic = new VoxPromptSelfCritic();
        $synthetic = $this->syntheticCompiled(
            'builtin.intent_compile.local.text.pt-br@0.2.0',
            "## Contexto\nA\n## Objetivo\nB\n## Saída esperada\nsem bullet\n## Voz original\n> oi",
        );
        $envelope = $critic->review('oi', $synthetic, [
            'goal' => 'B', 'constraints' => [], 'output_format' => 'text',
            'risk_class' => VoxSchema::RISK_R0, 'provider_hint' => 'local',
        ]);
        $this->assertFalse($envelope['checks']['has_expected_output']);
    }

    // ── #4 has_voz_original + reparo ─────────────────────────────────

    public function test_has_voz_original_repaired_when_missing(): void
    {
        $critic = new VoxPromptSelfCritic();
        // Prompt esqueleto sem "## Voz original" mas com transcript não-vazio.
        $synthetic = $this->syntheticCompiled(
            'builtin.intent_compile.local.text.pt-br@0.2.0',
            "## Contexto\nA\n## Objetivo\nB\n## Saída esperada\n- algo",
        );
        $envelope = $critic->review('analisa o canon do Atlas', $synthetic, [
            'goal' => 'B', 'constraints' => [], 'output_format' => 'text',
            'risk_class' => VoxSchema::RISK_R0, 'provider_hint' => 'local',
        ]);
        // Reparo restaura a seção; check passa na avaliação final.
        $this->assertTrue($envelope['repaired']);
        $this->assertTrue($envelope['checks']['has_voz_original']);
        $this->assertStringContainsString('## Voz original', $envelope['compiled_prompt']);
        $this->assertContains('voz_original_restored', $envelope['repair_log']);
    }

    public function test_voz_original_not_required_when_transcript_empty(): void
    {
        $critic = new VoxPromptSelfCritic();
        $synthetic = $this->syntheticCompiled(
            'builtin.intent_compile.local.text.pt-br@0.2.0',
            "## Contexto\nA\n## Objetivo\nB\n## Saída esperada\n- algo",
        );
        $envelope = $critic->review('', $synthetic, [
            'goal' => 'B', 'constraints' => [], 'output_format' => 'text',
            'risk_class' => VoxSchema::RISK_R0, 'provider_hint' => 'local',
        ]);
        $this->assertTrue($envelope['checks']['has_voz_original']);
    }

    // ── #5 negations_preserved ───────────────────────────────────────

    public function test_negation_preserved_pass(): void
    {
        $out = $this->compileFromVoice('refatora o controller mas não toca banco');
        $q = $out['compiled']['prompt_quality'];
        $this->assertTrue($q['checks']['negations_preserved']);
    }

    public function test_negation_lost_flags_issue_and_needs_review(): void
    {
        $critic = new VoxPromptSelfCritic();
        // Prompt sintético "completo" mas SEM o veto literal da voz.
        $body = "## Contexto\nA\n## Objetivo\nB\n## Modo de trabalho\n1. X\n"
            ."## Contexto disponível\n- vazio\n## Saída esperada\n- algo\n"
            ."## Critérios de qualidade\n- A\n## O que NÃO fazer\n"
            .'- Não execute comandos de terminal sozinho. Toda execução exige confirmação humana.'."\n"
            .'- Não use API paga, não chame provider remoto que cobre por uso.'."\n"
            .'- Não invente arquivo, função ou dependência que não exista no repo.'."\n"
            ."## Voz original\n> não toca banco do projeto X";
        $synthetic = $this->syntheticCompiled(
            'builtin.intent_compile.local.text.pt-br@0.2.0',
            $body,
        );
        $envelope = $critic->review('não toca banco', $synthetic, [
            'goal' => 'B',
            'constraints' => ['não toca banco do projeto X'],
            'output_format' => 'text',
            'risk_class' => VoxSchema::RISK_R0,
            'provider_hint' => 'local',
        ]);
        $this->assertFalse($envelope['checks']['negations_preserved']);
        $this->assertContains('negations_lost', $envelope['issues']);
        $this->assertTrue($envelope['needs_review']);
    }

    // ── #6 universal_vetoes_present + reparo ─────────────────────────

    public function test_universal_vetoes_pass_on_canonical_compile(): void
    {
        $out = $this->compileFromVoice('me responde só "ok"');
        $this->assertTrue($out['compiled']['prompt_quality']['checks']['universal_vetoes_present']);
    }

    public function test_missing_universal_veto_is_repaired(): void
    {
        $critic = new VoxPromptSelfCritic();
        // Bloco "O que NÃO fazer" sem nenhum veto universal — devem ser restaurados.
        $body = "## Contexto\nA\n## Objetivo\nB\n## Modo de trabalho\n1. X\n"
            ."## Contexto disponível\n- vazio\n## Saída esperada\n- algo\n"
            ."## Critérios de qualidade\n- A\n## O que NÃO fazer\n- algo custom\n"
            ."## Voz original\n> analisa o canon";
        $synthetic = $this->syntheticCompiled(
            'builtin.intent_compile.local.text.pt-br@0.2.0',
            $body,
        );
        $envelope = $critic->review('analisa o canon', $synthetic, [
            'goal' => 'B', 'constraints' => [], 'output_format' => 'text',
            'risk_class' => VoxSchema::RISK_R0, 'provider_hint' => 'local',
        ]);
        $this->assertTrue($envelope['repaired']);
        $this->assertTrue($envelope['checks']['universal_vetoes_present']);
        $this->assertStringContainsString(
            'Não execute comandos de terminal sozinho.',
            $envelope['compiled_prompt'],
        );
        $this->assertStringContainsString(
            'Não use API paga, não chame provider remoto que cobre por uso.',
            $envelope['compiled_prompt'],
        );
        $this->assertStringContainsString(
            'Não invente arquivo, função ou dependência que não exista no repo.',
            $envelope['compiled_prompt'],
        );
    }

    // ── #7 no_boilerplate + reparo ───────────────────────────────────

    public function test_no_boilerplate_pass_on_canonical_compile(): void
    {
        $out = $this->compileFromVoice('Codex investiga o overlay');
        $this->assertTrue($out['compiled']['prompt_quality']['checks']['no_boilerplate']);
    }

    public function test_boilerplate_is_repaired_line_by_line(): void
    {
        $critic = new VoxPromptSelfCritic();
        $body = "## Contexto\nVocê é um assistente útil que ajuda Atlas.\n"
            ."## Objetivo\nB\n## Modo de trabalho\nPor favor analise.\n"
            ."## Contexto disponível\n- vazio\n## Saída esperada\n- algo\n"
            ."## Critérios de qualidade\n- A\n## O que NÃO fazer\n"
            .'- Não execute comandos de terminal sozinho. Toda execução exige confirmação humana.'."\n"
            .'- Não use API paga, não chame provider remoto que cobre por uso.'."\n"
            .'- Não invente arquivo, função ou dependência que não exista no repo.'."\n"
            ."## Voz original\n> analisa";
        $synthetic = $this->syntheticCompiled(
            'builtin.intent_compile.local.text.pt-br@0.2.0',
            $body,
        );
        $envelope = $critic->review('analisa', $synthetic, [
            'goal' => 'B', 'constraints' => [], 'output_format' => 'text',
            'risk_class' => VoxSchema::RISK_R0, 'provider_hint' => 'local',
        ]);
        $this->assertTrue($envelope['repaired']);
        $this->assertTrue($envelope['checks']['no_boilerplate']);
        $this->assertStringNotContainsString(
            'assistente útil',
            mb_strtolower($envelope['compiled_prompt']),
        );
        $this->assertStringNotContainsString(
            'por favor',
            mb_strtolower($envelope['compiled_prompt']),
        );
        $hasBoilerplateRemoval = false;
        foreach ($envelope['repair_log'] as $entry) {
            if (str_starts_with($entry, 'boilerplate_removed:')) {
                $hasBoilerplateRemoval = true;
                break;
            }
        }
        $this->assertTrue($hasBoilerplateRemoval, 'repair_log devia citar boilerplate_removed');
    }

    // ── #8 risk_not_softened ─────────────────────────────────────────

    public function test_risk_not_softened_pass_for_r4_voice(): void
    {
        $out = $this->compileFromVoice('roda rm -rf node_modules pra limpar tudo');
        $q = $out['compiled']['prompt_quality'];
        $this->assertSame(VoxSchema::RISK_R4, $out['extracted']['risk_class']);
        $this->assertTrue($q['checks']['risk_not_softened']);
    }

    public function test_risk_softened_when_r4_lacks_safety_block(): void
    {
        $critic = new VoxPromptSelfCritic();
        // Sintético: extractor marcou R4 mas o prompt não tem ## Segurança.
        $body = "## Contexto\nA\n## Objetivo\nB\n## Modo de trabalho\nX\n"
            ."## Contexto disponível\n- vazio\n## Saída esperada\n- algo\n"
            ."## Critérios de qualidade\n- A\n## O que NÃO fazer\n"
            .'- Não execute comandos de terminal sozinho. Toda execução exige confirmação humana.'."\n"
            .'- Não use API paga, não chame provider remoto que cobre por uso.'."\n"
            .'- Não invente arquivo, função ou dependência que não exista no repo.'."\n"
            ."## Voz original\n> apaga tudo";
        $synthetic = $this->syntheticCompiled(
            'builtin.intent_compile.local.text.pt-br@0.2.0',
            $body,
        );
        $envelope = $critic->review('apaga tudo', $synthetic, [
            'goal' => 'B', 'constraints' => [], 'output_format' => 'text',
            'risk_class' => VoxSchema::RISK_R4, 'provider_hint' => 'local',
        ]);
        $this->assertFalse($envelope['checks']['risk_not_softened']);
        $this->assertContains('risk_softened', $envelope['issues']);
        $this->assertTrue($envelope['needs_review']);
    }

    // ── #9 no_unauthorized_action ────────────────────────────────────

    public function test_unauthorized_action_pass_when_voice_is_not_read_only(): void
    {
        $out = $this->compileFromVoice('Codex refatora o controller');
        $this->assertTrue($out['compiled']['prompt_quality']['checks']['no_unauthorized_action']);
    }

    public function test_unauthorized_action_flagged_when_read_only_voice_meets_edit_imperative(): void
    {
        $critic = new VoxPromptSelfCritic();
        // Voz "só analisa" + prompt manda "Edite o arquivo X.".
        $body = "## Contexto\nA\n## Objetivo\nB\n## Modo de trabalho\nEdite o arquivo X imediatamente.\n"
            ."## Contexto disponível\n- vazio\n## Saída esperada\n- algo\n"
            ."## Critérios de qualidade\n- A\n## O que NÃO fazer\n"
            .'- Não execute comandos de terminal sozinho. Toda execução exige confirmação humana.'."\n"
            .'- Não use API paga, não chame provider remoto que cobre por uso.'."\n"
            .'- Não invente arquivo, função ou dependência que não exista no repo.'."\n"
            ."## Voz original\n> só analisa";
        $synthetic = $this->syntheticCompiled(
            'builtin.intent_compile.local.text.pt-br@0.2.0',
            $body,
        );
        $envelope = $critic->review('só analisa', $synthetic, [
            'goal' => 'B',
            'constraints' => ['só analisa'],
            'output_format' => 'text',
            'risk_class' => VoxSchema::RISK_R1,
            'provider_hint' => 'local',
        ]);
        $this->assertFalse($envelope['checks']['no_unauthorized_action']);
        $this->assertContains('unauthorized_action_in_prompt', $envelope['issues']);
        $this->assertTrue($envelope['needs_review']);
    }

    public function test_conditional_edit_in_read_only_voice_is_allowed(): void
    {
        // "Se editar" / "ao editar" são instruções CONDICIONAIS — não viola.
        $critic = new VoxPromptSelfCritic();
        $body = "## Contexto\nA\n## Objetivo\nB\n## Modo de trabalho\nSe editar, mantenha escopo mínimo.\n"
            ."## Contexto disponível\n- vazio\n## Saída esperada\n- algo\n"
            ."## Critérios de qualidade\n- A\n## O que NÃO fazer\n"
            .'- Não execute comandos de terminal sozinho. Toda execução exige confirmação humana.'."\n"
            .'- Não use API paga, não chame provider remoto que cobre por uso.'."\n"
            .'- Não invente arquivo, função ou dependência que não exista no repo.'."\n"
            ."## Voz original\n> só analisa";
        $synthetic = $this->syntheticCompiled(
            'builtin.intent_compile.local.text.pt-br@0.2.0',
            $body,
        );
        $envelope = $critic->review('só analisa', $synthetic, [
            'goal' => 'B', 'constraints' => ['só analisa'], 'output_format' => 'text',
            'risk_class' => VoxSchema::RISK_R1, 'provider_hint' => 'local',
        ]);
        $this->assertTrue($envelope['checks']['no_unauthorized_action']);
    }

    public function test_veto_block_with_edit_word_is_not_flagged_as_unauthorized(): void
    {
        // "Não edite" dentro de "## O que NÃO fazer" é veto, não imperativo.
        $out = $this->compileFromVoice('só analisa o VoxOverlay sem editar nada');
        $this->assertTrue(
            $out['compiled']['prompt_quality']['checks']['no_unauthorized_action'],
            'vetos em "O que NÃO fazer" não devem disparar ação não autorizada',
        );
    }

    // ── #10 provider_not_invented ────────────────────────────────────

    public function test_provider_not_invented_for_auto_voice(): void
    {
        $out = $this->compileFromVoice('Codex ou Claude, qualquer um, investiga o overlay');
        $this->assertSame('auto', $out['extracted']['provider_hint']);
        $this->assertTrue($out['compiled']['prompt_quality']['checks']['provider_not_invented']);
    }

    public function test_provider_invented_when_auto_template_emits_codex_exclusive(): void
    {
        $critic = new VoxPromptSelfCritic();
        $body = "## Contexto\nVocê é o Codex investigando algo.\n## Objetivo\nB\n"
            ."## Modo de trabalho\nX\n## Contexto disponível\n- vazio\n"
            ."## Saída esperada\n- algo\n## Critérios de qualidade\n- A\n"
            ."## O que NÃO fazer\n"
            .'- Não execute comandos de terminal sozinho. Toda execução exige confirmação humana.'."\n"
            .'- Não use API paga, não chame provider remoto que cobre por uso.'."\n"
            .'- Não invente arquivo, função ou dependência que não exista no repo.'."\n"
            ."## Voz original\n> codex ou claude";
        $synthetic = $this->syntheticCompiled(
            'builtin.intent_compile.auto.text.pt-br@0.2.0',
            $body,
        );
        $envelope = $critic->review('codex ou claude', $synthetic, [
            'goal' => 'B', 'constraints' => [], 'output_format' => 'text',
            'risk_class' => VoxSchema::RISK_R0, 'provider_hint' => 'auto',
        ]);
        $this->assertFalse($envelope['checks']['provider_not_invented']);
        $this->assertContains('provider_invented_for_auto', $envelope['issues']);
    }

    // ── #11 provider_template_match ──────────────────────────────────

    public function test_provider_template_match_pass(): void
    {
        $out = $this->compileFromVoice('Codex investiga o overlay');
        $this->assertTrue($out['compiled']['prompt_quality']['checks']['provider_template_match']);
    }

    public function test_provider_template_mismatch_flag(): void
    {
        $critic = new VoxPromptSelfCritic();
        $synthetic = $this->syntheticCompiled(
            'builtin.intent_compile.local.text.pt-br@0.2.0',
            "## Contexto\nA\n## Objetivo\nB\n## Saída esperada\n- algo",
        );
        $envelope = $critic->review('Codex investiga', $synthetic, [
            'goal' => 'B', 'constraints' => [], 'output_format' => 'text',
            'risk_class' => VoxSchema::RISK_R0, 'provider_hint' => 'codex_cli',
        ]);
        $this->assertFalse($envelope['checks']['provider_template_match']);
        $this->assertContains('provider_template_mismatch', $envelope['issues']);
        $this->assertTrue($envelope['needs_review']);
    }

    // ── #12 no_read_only_contradiction ───────────────────────────────

    public function test_read_only_with_diff_is_contradiction(): void
    {
        $critic = new VoxPromptSelfCritic();
        $synthetic = $this->syntheticCompiled(
            'builtin.intent_compile.codex_cli.diff.pt-br@0.2.0',
            "## Contexto\nA\n## Objetivo\nB\n## Saída esperada\n- y",
        );
        $envelope = $critic->review('só analisa', $synthetic, [
            'goal' => 'B', 'constraints' => ['só analisa'], 'output_format' => 'diff',
            'risk_class' => VoxSchema::RISK_R1, 'provider_hint' => 'codex_cli',
        ]);
        $this->assertFalse($envelope['checks']['no_read_only_contradiction']);
        $this->assertContains('contradiction_read_only_but_edit_format', $envelope['issues']);
        $this->assertTrue($envelope['needs_review']);
    }

    public function test_read_only_with_diagnostic_is_consistent(): void
    {
        $out = $this->compileFromVoice('só analisa o VoxOverlay sem editar');
        $this->assertTrue($out['compiled']['prompt_quality']['checks']['no_read_only_contradiction']);
    }

    // ── #13 minimum_useful_prompt ────────────────────────────────────

    public function test_minimum_useful_prompt_pass_on_substantial_voice(): void
    {
        $out = $this->compileFromVoice('Codex investiga por que o build desktop está quebrando sem mexer no kernel');
        $this->assertTrue($out['compiled']['prompt_quality']['checks']['minimum_useful_prompt']);
    }

    public function test_minimum_useful_prompt_fails_on_skeleton(): void
    {
        $critic = new VoxPromptSelfCritic();
        $synthetic = $this->syntheticCompiled(
            'builtin.intent_compile.local.text.pt-br@0.2.0',
            "## Contexto\nA\n## Objetivo\nB\n## Saída esperada\n- y\n## Voz original\n> oi",
        );
        $envelope = $critic->review('oi', $synthetic, [
            'goal' => 'B', 'constraints' => [], 'output_format' => 'text',
            'risk_class' => VoxSchema::RISK_R0, 'provider_hint' => 'local',
        ]);
        $this->assertFalse($envelope['checks']['minimum_useful_prompt']);
        $this->assertContains('prompt_below_minimum_useful', $envelope['issues']);
    }

    // ── Score + status thresholds ────────────────────────────────────

    public function test_canonical_voice_yields_pass_status_and_high_score(): void
    {
        $out = $this->compileFromVoice('Codex investiga por que o teste de microfone está quebrando no VoxOverlay, mas não toque no VoxEvidenceService.');
        $q = $out['compiled']['prompt_quality'];
        $this->assertSame(VoxPromptSelfCritic::STATUS_PASS, $q['status']);
        $this->assertGreaterThanOrEqual(VoxPromptSelfCritic::PASS_THRESHOLD, $q['score']);
    }

    public function test_status_warn_when_score_between_thresholds(): void
    {
        // Sintético com várias falhas mas dentro do range warn (~0.60-0.85).
        $critic = new VoxPromptSelfCritic();
        $synthetic = $this->syntheticCompiled(
            'builtin.intent_compile.local.text.pt-br@0.2.0',
            "## Contexto\nA\n## Objetivo\n(objetivo não declarado explicitamente)\n## Saída esperada\nsem bullet",
        );
        $envelope = $critic->review('oi', $synthetic, [
            'goal' => '', 'constraints' => [], 'output_format' => 'text',
            'risk_class' => VoxSchema::RISK_R0, 'provider_hint' => 'local',
        ]);
        $this->assertGreaterThanOrEqual(VoxPromptSelfCritic::WARN_THRESHOLD, $envelope['score']);
        $this->assertLessThan(VoxPromptSelfCritic::PASS_THRESHOLD, $envelope['score']);
        $this->assertSame(VoxPromptSelfCritic::STATUS_WARN, $envelope['status']);
    }

    public function test_status_fail_when_score_below_warn_threshold(): void
    {
        // Sintético com falhas suficientes para cair em fail (<0.60).
        // Acumula: sem seções (#1), sem goal (#2), sem expected output (#3),
        // negação perdida (#5), sem vetos universais (#6), risk softened (#8),
        // unauthorized action (#9), provider mismatch (#11), prompt curto (#13).
        // Total: ~4/13 ≈ 0.31, abaixo do warn threshold (0.60).
        $critic = new VoxPromptSelfCritic();
        $synthetic = $this->syntheticCompiled(
            'builtin.intent_compile.local.text.pt-br@0.2.0',
            "## Contexto\nA\n## Objetivo\n(objetivo não declarado explicitamente)\n## Saída esperada\nsem bullet\nEdite o arquivo X imediatamente.",
        );
        $envelope = $critic->review('só analisa', $synthetic, [
            'goal' => '',
            'constraints' => ['não toca banco', 'só analisa'],
            'output_format' => 'text',
            'risk_class' => VoxSchema::RISK_R4,
            'provider_hint' => 'codex_cli',
        ]);
        $this->assertLessThan(VoxPromptSelfCritic::WARN_THRESHOLD, $envelope['score']);
        $this->assertSame(VoxPromptSelfCritic::STATUS_FAIL, $envelope['status']);
        $this->assertTrue($envelope['needs_review']);
    }

    // ── Wire-up · resultado do compiler carrega envelope ─────────────

    public function test_compiler_attaches_prompt_quality_envelope(): void
    {
        $out = $this->compileFromVoice('Codex investiga o overlay');
        $this->assertArrayHasKey('prompt_quality', $out['compiled']);
        $this->assertSame(VoxPromptSelfCritic::SCHEMA, $out['compiled']['prompt_quality']['schema']);
    }

    public function test_compiler_keeps_back_compat_quality_self_check(): void
    {
        // V6-FPG-B já expunha `quality_self_check` em `compile()`. V6.5 não
        // pode regredir esse contrato.
        $out = $this->compileFromVoice('Codex investiga o overlay');
        $this->assertArrayHasKey('quality_self_check', $out['compiled']);
        $this->assertArrayHasKey('score', $out['compiled']['quality_self_check']);
    }

    // ── Reparo · idempotência ────────────────────────────────────────

    public function test_repair_is_idempotent_on_already_clean_prompt(): void
    {
        $out = $this->compileFromVoice('Codex investiga o overlay sem mexer no kernel');
        $q = $out['compiled']['prompt_quality'];
        $this->assertFalse($q['repaired']);
        $this->assertSame([], $q['repair_log']);
    }

    public function test_review_is_deterministic_for_same_input(): void
    {
        $critic = new VoxPromptSelfCritic();
        $synthetic = $this->syntheticCompiled(
            'builtin.intent_compile.codex_cli.text.pt-br@0.2.0',
            "## Contexto\nA\n## Objetivo\nB\n## Modo de trabalho\nX\n"
            ."## Contexto disponível\n- vazio\n## Saída esperada\n- y\n"
            ."## Critérios de qualidade\n- A\n## O que NÃO fazer\n"
            .'- Não execute comandos de terminal sozinho. Toda execução exige confirmação humana.'."\n"
            .'- Não use API paga, não chame provider remoto que cobre por uso.'."\n"
            .'- Não invente arquivo, função ou dependência que não exista no repo.'."\n"
            ."## Voz original\n> investiga",
        );
        $a = $critic->review('investiga', $synthetic, [
            'goal' => 'B', 'constraints' => [], 'output_format' => 'text',
            'risk_class' => VoxSchema::RISK_R0, 'provider_hint' => 'codex_cli',
        ]);
        $b = $critic->review('investiga', $synthetic, [
            'goal' => 'B', 'constraints' => [], 'output_format' => 'text',
            'risk_class' => VoxSchema::RISK_R0, 'provider_hint' => 'codex_cli',
        ]);
        $this->assertSame($a['score'], $b['score']);
        $this->assertSame($a['status'], $b['status']);
        $this->assertSame($a['issues'], $b['issues']);
        $this->assertSame($a['needs_review'], $b['needs_review']);
    }

    // ── needs_review · semântica ─────────────────────────────────────

    public function test_needs_review_false_when_only_soft_issue_repaired(): void
    {
        // Boilerplate é "soft": reparado, não exige operador.
        $critic = new VoxPromptSelfCritic();
        $body = "## Contexto\nVocê é um assistente útil que ajuda Atlas.\n"
            ."## Objetivo\nInvestigar overlay específico\n## Modo de trabalho\nX\n"
            ."## Contexto disponível\n- vazio\n## Saída esperada\n- algo\n"
            ."## Critérios de qualidade\n- A\n## O que NÃO fazer\n"
            .'- Não execute comandos de terminal sozinho. Toda execução exige confirmação humana.'."\n"
            .'- Não use API paga, não chame provider remoto que cobre por uso.'."\n"
            .'- Não invente arquivo, função ou dependência que não exista no repo.'."\n"
            ."## Voz original\n> investiga o overlay especifico";
        // Pad para passar minimum_useful_prompt (>600 chars).
        $body .= "\n\n".str_repeat('linha de contexto extra para atingir tamanho mínimo. ', 12);
        $synthetic = $this->syntheticCompiled(
            'builtin.intent_compile.local.text.pt-br@0.2.0',
            $body,
        );
        $envelope = $critic->review('investiga o overlay especifico', $synthetic, [
            'goal' => 'Investigar overlay específico',
            'constraints' => [], 'output_format' => 'text',
            'risk_class' => VoxSchema::RISK_R0, 'provider_hint' => 'local',
        ]);
        $this->assertTrue($envelope['repaired']);
        $this->assertFalse($envelope['needs_review']);
    }

    public function test_needs_review_true_when_hard_signal_fails(): void
    {
        // Provider mismatch é hard — não dá pra reparar deterministicamente.
        $critic = new VoxPromptSelfCritic();
        $synthetic = $this->syntheticCompiled(
            'builtin.intent_compile.local.text.pt-br@0.2.0',
            "## Contexto\nA\n## Objetivo\nB\n## Saída esperada\n- y",
        );
        $envelope = $critic->review('voz', $synthetic, [
            'goal' => 'B', 'constraints' => [], 'output_format' => 'text',
            'risk_class' => VoxSchema::RISK_R0, 'provider_hint' => 'codex_cli',
        ]);
        $this->assertTrue($envelope['needs_review']);
    }

    // ── Negativos · sem V7 / sem mobile / sem Voice RT ──────────────

    public function test_envelope_does_not_unlock_v7_or_mention_long_memory(): void
    {
        $out = $this->compileFromVoice('Codex investiga o overlay');
        $q = $out['compiled']['prompt_quality'];
        $jsonEnvelope = json_encode($q, JSON_THROW_ON_ERROR);
        // O critic NUNCA cria memória entre dias nem cita V7 destravando.
        $this->assertStringNotContainsString('v7_unlock', $jsonEnvelope);
        $this->assertStringNotContainsString('longitudinal_memory', $jsonEnvelope);
    }

    public function test_critic_class_has_no_paid_api_dependency(): void
    {
        // Defesa estática: o arquivo do service não pode importar nem mencionar
        // Anthropic/OpenAI/Codex SDK paga.
        $path = __DIR__.'/../../../../app/Services/Ai/Vox/VoxPromptSelfCritic.php';
        $this->assertFileExists($path);
        $source = (string) file_get_contents($path);
        foreach ([
            'Anthropic\\', 'OpenAI\\', 'api.anthropic.com', 'api.openai.com',
            'sk-ant-', 'OPENAI_API_KEY', 'ANTHROPIC_API_KEY',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                "VoxPromptSelfCritic não pode citar $forbidden",
            );
        }
    }

    public function test_critic_does_not_touch_voice_realtime_or_mobile(): void
    {
        $path = __DIR__.'/../../../../app/Services/Ai/Vox/VoxPromptSelfCritic.php';
        $source = (string) file_get_contents($path);
        $this->assertStringNotContainsString('App\\Services\\Ai\\Voice\\', $source);
        $this->assertStringNotContainsString('atlas-app/', $source);
        $this->assertStringNotContainsString('App\\Mobile', $source);
    }
}
