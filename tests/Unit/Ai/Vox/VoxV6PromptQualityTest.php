<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\VoxIntentExtractor;
use App\Services\Ai\Vox\VoxPromptCompiler;
use App\Services\Ai\Vox\VoxPromptPolisher;
use App\Services\Ai\Vox\VoxSchema;
use PHPUnit\Framework\TestCase;

/**
 * V6-ES-C · Atlas Vox Enterprise Stabilization · Prompt Quality.
 *
 * Endurece a barra de qualidade do que `prompt_polish` e `intent_compile`
 * entregam pra Vitor. Nenhum teste aqui chama LLM, nenhum executa nada:
 * só lê o output determinístico dos compilers e verifica invariantes.
 *
 * Cenários cobertos (canon V6 enterprise):
 *   1. fala bagunçada → prompt estruturado com seções canônicas.
 *   2. "não mexa em X" vira restrição literal + veto em "## O que NÃO fazer".
 *   3. "só analisa" / "só leia" → output_format=diagnostic, risk=R1, sem edição.
 *   4. pedido destrutivo (rm -rf, drop database, deploy) → R4 + bloco Segurança.
 *   5. ditado simples / texto curto → polish minimalista, NÃO vira mega-prompt.
 *   6. terminal_propose nunca executa direto; bloco de segurança aparece.
 *   7. critérios de qualidade e seção "O que NÃO fazer" SEMPRE presentes.
 */
final class VoxV6PromptQualityTest extends TestCase
{
    private function extractor(): VoxIntentExtractor
    {
        return new VoxIntentExtractor(new VoxPromptPolisher());
    }

    private function compileFromVoice(string $voice, array $hints = []): array
    {
        $extracted = $this->extractor()->extract(
            ['text' => $voice, 'session_id' => 's', 'transcript_id' => 't'],
            $hints,
        );
        $compiled = (new VoxPromptCompiler())->compile($voice, [
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
        return ['extracted' => $extracted, 'compiled' => $compiled];
    }

    // ── 1. Fala bagunçada → estrutura ─────────────────────────────────

    public function test_messy_voice_compiles_into_canonical_sections(): void
    {
        $voice = 'eh tipo assim, queria que o Codex investigasse aí no AtlasAiVoxController, '
            .'tipo entender por que o teste de microfone tá quebrando, mas não mexa '
            .'no arquivo VoxEvidenceService, beleza';

        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        foreach (VoxPromptCompiler::CANONICAL_SECTIONS as $section) {
            $this->assertStringContainsString(
                $section,
                $prompt,
                "Faltou seção canônica '$section' no compiled_prompt",
            );
        }
        // Provider hint detectado.
        $this->assertSame('codex_cli', $out['extracted']['provider_hint']);
        // Output format = diagnostic ("entender por que ...").
        $this->assertSame('diagnostic', $out['extracted']['output_format']);
        // Risk = R1 (leitura/análise).
        $this->assertSame(VoxSchema::RISK_R1, $out['extracted']['risk_class']);
        // Restrição "não mexa no arquivo VoxEvidenceService" preservada.
        $hasNotMexa = false;
        foreach ($out['extracted']['constraints'] as $c) {
            if (mb_stripos($c, 'voxevidenceservice') !== false || mb_stripos($c, 'não mexa') !== false) {
                $hasNotMexa = true;
                break;
            }
        }
        $this->assertTrue($hasNotMexa, 'constraint "não mexa..." deveria ter sido capturada');
    }

    // ── 2. Restrições "não X" → vetos hard ────────────────────────────

    public function test_constraint_appears_literally_in_do_not_block(): void
    {
        $voice = 'refatora o controller mas não toque na tabela atlas_vox_dogfood_sessions';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        $this->assertStringContainsString('## Restrições', $prompt);
        $this->assertStringContainsString('## O que NÃO fazer', $prompt);
        // O bloco "O que NÃO fazer" deve repetir a restrição literalmente.
        $this->assertStringContainsString('Veto literal da voz:', $prompt);
        $this->assertStringContainsString('não toque', $prompt);
    }

    public function test_constraint_is_not_reinterpreted_into_softer_text(): void
    {
        $voice = 'aplica o fix no AtlasAiVoxController mas não execute teste';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];
        // Restrição literal preservada — sem "talvez não execute" / "evite".
        $this->assertStringContainsString('Restrições acima são literais; não as reinterprete nem amplie.', $prompt);
    }

    // ── 3. "Só analisa / só leia / read-only" → diagnostic + R1 ───────

    public function test_so_analisa_forces_diagnostic_and_r1(): void
    {
        $voice = 'olha o módulo VoxCompiler e só analisa, não edita nada';
        $out = $this->compileFromVoice($voice);
        $this->assertSame('diagnostic', $out['extracted']['output_format']);
        $this->assertSame(VoxSchema::RISK_R1, $out['extracted']['risk_class']);
        $this->assertStringContainsString(
            'Não edite arquivo, não rode comando.',
            $out['compiled']['compiled_prompt'],
        );
    }

    public function test_read_only_english_forces_diagnostic(): void
    {
        $voice = 'investiga o problema do hotkey, read-only, sem alterar nada';
        $out = $this->compileFromVoice($voice);
        $this->assertSame('diagnostic', $out['extracted']['output_format']);
        $this->assertSame(VoxSchema::RISK_R1, $out['extracted']['risk_class']);
    }

    // ── 4. Destrutivo → R4 + bloco Segurança ─────────────────────────

    public function test_destructive_command_becomes_r4_with_safety_block(): void
    {
        $voice = 'roda rm -rf node_modules pra limpar tudo';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        $this->assertSame(VoxSchema::RISK_R4, $out['extracted']['risk_class']);
        $this->assertNotEmpty($out['extracted']['risk_markers']);
        $this->assertStringContainsString('## Segurança', $prompt);
        $this->assertStringContainsString('NÃO execute', $prompt);
        $this->assertStringContainsString('marcadores de operação **destrutiva**', $prompt);
    }

    public function test_drop_database_becomes_r4(): void
    {
        $voice = 'roda drop database atlas no postgres';
        $out = $this->compileFromVoice($voice);
        $this->assertSame(VoxSchema::RISK_R4, $out['extracted']['risk_class']);
    }

    // ── 5. Ditado simples → polish minimalista ───────────────────────

    public function test_dictation_simple_does_not_become_huge_prompt(): void
    {
        $voice = 'lembrete: comprar café amanhã';
        $polish = (new VoxPromptPolisher())->polish($voice);
        // Não infla — apenas pontua/capitaliza.
        $this->assertSame('Lembrete: comprar café amanhã.', $polish['compiled_prompt']);
        // Sem seções markdown — polish ≠ intent_compile.
        $this->assertStringNotContainsString('## ', $polish['compiled_prompt']);
        $this->assertStringNotContainsString('Objetivo', $polish['compiled_prompt']);
        $this->assertStringNotContainsString('Saída esperada', $polish['compiled_prompt']);
    }

    public function test_dictation_short_does_not_attach_sections(): void
    {
        $voice = 'adiciona ao inbox: revisar pull request 42';
        $polish = (new VoxPromptPolisher())->polish($voice);
        // Tamanho máximo razoável para nota simples — guard contra inflação.
        $this->assertLessThan(120, mb_strlen($polish['compiled_prompt']));
        $this->assertStringNotContainsString('## ', $polish['compiled_prompt']);
    }

    // ── 6. terminal_propose nunca executa direto ─────────────────────

    public function test_terminal_propose_emits_safety_warning(): void
    {
        $voice = 'roda os testes do AtlasAiVoxController no terminal';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        $this->assertSame('terminal_propose', $out['extracted']['executor_hint']);
        $this->assertSame(VoxSchema::RISK_R3, $out['extracted']['risk_class']);
        $this->assertStringContainsString('## Segurança', $prompt);
        $this->assertStringContainsString('exija confirmação humana', $prompt);
        $this->assertStringContainsString(
            'Não execute o comando proposto — apenas sugira e aguarde aprovação.',
            $prompt,
        );
    }

    // ── 7. Seções universais sempre presentes ────────────────────────

    public function test_canonical_sections_present_for_every_format(): void
    {
        $cases = [
            'analise rápida da função X' => 'diagnostic',
            'faz um plano pra refatorar a surface Y' => 'plan',
            'aplica diff em X reduzindo escopo' => 'diff',
            'só uma nota: a build subiu' => 'notes',
            'me diz se está ok rodar npm install' => 'text',
        ];
        foreach ($cases as $voice => $expectedFormat) {
            $out = $this->compileFromVoice($voice);
            $this->assertSame(
                $expectedFormat,
                $out['extracted']['output_format'],
                "voice '$voice' deveria virar $expectedFormat",
            );
            $prompt = $out['compiled']['compiled_prompt'];
            foreach (['## Contexto', '## Objetivo', '## Modo de trabalho',
                      '## Saída esperada', '## Critérios de qualidade',
                      '## O que NÃO fazer', '## Voz original'] as $sec) {
                $this->assertStringContainsString(
                    $sec,
                    $prompt,
                    "voice '$voice' (format=$expectedFormat) faltou seção '$sec'",
                );
            }
        }
    }

    // ── Veto universal sempre presente ──────────────────────────────

    public function test_universal_safety_vetoes_present_in_every_compiled_prompt(): void
    {
        $voice = 'me responde só "ok"';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];
        $this->assertStringContainsString(
            'Não execute comandos de terminal sozinho.',
            $prompt,
        );
        $this->assertStringContainsString(
            'Não use API paga, não chame provider remoto que cobre por uso.',
            $prompt,
        );
        $this->assertStringContainsString(
            'Não invente arquivo, função ou dependência que não exista no repo.',
            $prompt,
        );
    }

    // ── Voz com contexto extraído ───────────────────────────────────

    public function test_voice_context_clues_appear_in_context_block(): void
    {
        $voice = 'no arquivo VoxCompiler.php tem um bug porque o switch não cobre auto';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];
        // O bloco "Contexto disponível" pegou pelo menos uma pista da voz.
        $this->assertMatchesRegularExpression(
            '/\[voz\]\s+(no arquivo|porque)/i',
            $prompt,
        );
    }

    // ────────────────────────────────────────────────────────────────────
    // V6-PCF · Prompt Compiler Final · novos casos enterprise (≥ 20)
    // ────────────────────────────────────────────────────────────────────

    // ── 8. Estilo Codex (engenharia/código) ──────────────────────────

    public function test_codex_header_orients_to_investigate_before_edit(): void
    {
        $voice = 'Codex, investiga o bug do hotkey antes de editar qualquer coisa';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        $this->assertSame('codex_cli', $out['extracted']['provider_hint']);
        // Header Codex deve orientar engenharia/código: rg, leia antes,
        // não invente arquivo.
        $this->assertStringContainsString('Você é o Codex', $prompt);
        $this->assertStringContainsString('Leia antes de propor', $prompt);
        $this->assertStringContainsString('`rg`', $prompt);
        $this->assertStringContainsString('Não invente arquivos', $prompt);
        // Working mode Codex: investigar antes, escopo mínimo, sumário final.
        $this->assertStringContainsString('leia o código relevante', $prompt);
        $this->assertStringContainsString('Não edite arquivos se o pedido for análise', $prompt);
        $this->assertStringContainsString('escopo mínimo', $prompt);
    }

    // ── 9. Estilo Claude (plano/arquitetura/decisão) ─────────────────

    public function test_claude_header_orients_to_plan_and_risks(): void
    {
        $voice = 'Claude, faz um plano pra refatorar o overlay';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        $this->assertSame('claude_cli', $out['extracted']['provider_hint']);
        $this->assertSame('plan', $out['extracted']['output_format']);
        // Header Claude: plan mode + escopo mínimo + teste.
        $this->assertStringContainsString('Você é o Claude Code', $prompt);
        $this->assertStringContainsString('plano decision-complete', $prompt);
        // Working mode: diff conceitual + riscos + próximo passo.
        $this->assertStringContainsString('riscos + próximo passo', $prompt);
        // Plan-format expected output traz critério de pronto.
        $this->assertStringContainsString('critério de pronto', $prompt);
        $this->assertStringContainsString('Critério final de aceitação', $prompt);
    }

    // ── 10. Estilo Atlas interno (pipeline / receipt / governança) ───

    public function test_atlas_internal_header_references_pipeline_and_governance(): void
    {
        $voice = 'Atlas Dev, me explica como o VoxEvidenceService trabalha com hard gates';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        $this->assertSame('atlas', $out['extracted']['provider_hint']);
        // Header Atlas: pipeline → receipt → confirmation → evidence ledger.
        $this->assertStringContainsString('Você é o próprio Atlas', $prompt);
        $this->assertStringContainsString('pipeline', $prompt);
        $this->assertStringContainsString('receipt', $prompt);
        $this->assertStringContainsString('confirmation', $prompt);
        $this->assertStringContainsString('evidence ledger', $prompt);
        // Working mode Atlas: respeita pipeline + cita arquivo concreto.
        $this->assertStringContainsString('Respeite o pipeline Atlas', $prompt);
        $this->assertStringContainsString('Cite arquivo/serviço concreto', $prompt);
        // Atlas NÃO destrava V7.
        $this->assertStringContainsString('V7/memória longitudinal segue bloqueada', $prompt);
    }

    public function test_atlas_provider_does_not_leak_external_provider_names(): void
    {
        $voice = 'Atlas Dev, me explica como o VoxEvidenceService trabalha';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        // Header atlas NÃO deve se confundir com Codex/Claude.
        $this->assertStringContainsString('Você é o próprio Atlas', $prompt);
        $this->assertStringNotContainsString('Você é o Codex', $prompt);
        $this->assertStringNotContainsString('Você é o Claude Code', $prompt);
        // E o template id deve usar o slug atlas, não local.
        $this->assertStringContainsString(
            '.atlas.',
            $out['compiled']['compiled_prompt_template'],
        );
    }

    // ── 11. Restrições canônicas preservadas literalmente ────────────

    public function test_nao_mexe_em_nada_preserved_as_literal_veto(): void
    {
        $voice = 'investiga o erro mas não mexe em nada';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        // Restrição literal aparece como veto.
        $this->assertStringContainsString('Veto literal da voz:', $prompt);
        $this->assertStringContainsString('não mexe em nada', $prompt);
        // E a self-check NÃO marca lost_negations.
        $sc = $out['compiled']['quality_self_check'];
        $this->assertSame([], $sc['lost_negations']);
    }

    public function test_nao_toca_banco_preserved(): void
    {
        $voice = 'refatora o controller mas não toca banco';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        $this->assertStringContainsString('não toca banco', $prompt);
        $this->assertStringContainsString('Veto literal da voz:', $prompt);
    }

    public function test_nao_faz_build_preserved(): void
    {
        $voice = 'aplica o fix curto, não faz build agora';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        $this->assertStringContainsString('não faz build', $prompt);
        $this->assertStringContainsString('Veto literal da voz:', $prompt);
    }

    public function test_nao_usa_api_paga_preserved_and_universal_veto_present(): void
    {
        $voice = 'me ajuda a debugar isso, mas não usa API paga';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        // Veto universal continua.
        $this->assertStringContainsString(
            'Não use API paga, não chame provider remoto que cobre por uso.',
            $prompt,
        );
        // Veto literal da voz também aparece, em paralelo.
        $this->assertStringContainsString('Veto literal da voz:', $prompt);
        $this->assertStringContainsString('não usa API paga', $prompt);
    }

    public function test_sem_mobile_preserved(): void
    {
        $voice = 'refatora o overlay, sem mobile e sem voice realtime';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        $this->assertStringContainsString('sem mobile', $prompt);
        // Pelo menos uma das duas restrições captadas como veto.
        $this->assertMatchesRegularExpression('/Veto literal da voz:/', $prompt);
    }

    public function test_multiple_constraints_all_appear_as_vetoes(): void
    {
        $voice = 'investiga o bug no AtlasAiVoxController mas não toque no VoxEvidenceService, '
            .'sem dependência nova, sem mobile, antes de tudo confirma o schema';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        $this->assertGreaterThanOrEqual(
            3,
            substr_count($prompt, 'Veto literal da voz:'),
            'esperava ≥3 vetos literais; recebi: '.substr_count($prompt, 'Veto literal da voz:'),
        );
        // Todas as restrições captadas pelo extractor têm clause não-vazia.
        $this->assertNotEmpty($out['extracted']['constraints']);
        foreach ($out['extracted']['constraints'] as $clause) {
            $this->assertNotSame('', trim($clause));
        }
    }

    // ── 12. Boilerplate fraco proibido ───────────────────────────────

    public function test_no_polite_filler_leaks_into_prompt(): void
    {
        $voice = 'me explica como funciona o VoxOverlay';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        $banned = [
            'por favor',
            'vamos juntos',
            'assistente útil',
            'considere os seguintes pontos',
            'espero que isso ajude',
            'obrigado',
        ];
        foreach ($banned as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                mb_strtolower($prompt),
                "boilerplate '$needle' não pode vazar no prompt",
            );
        }
    }

    public function test_prompt_does_not_open_with_assistant_persona(): void
    {
        $voice = 'analisa rapidinho o estado da Cartografia';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        // Prompt começa com "## Contexto" não com "Você é um assistente útil".
        $this->assertStringStartsWith('## Contexto', $prompt);
        $this->assertStringNotContainsString('Você é um assistente útil', $prompt);
    }

    // ── 13. Voz original verbatim ────────────────────────────────────

    public function test_voz_original_is_preserved_verbatim_at_end(): void
    {
        $voice = 'olha aí, eh, queria que o Codex desse uma olhada no VoxOverlay sem editar nada';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        // A seção "Voz original" deve guardar a voz limpa de whitespace,
        // mas com o conteúdo intacto.
        $this->assertStringContainsString('## Voz original', $prompt);
        $this->assertStringContainsString('> '.$voice, $prompt);
    }

    public function test_voz_original_normalises_whitespace_but_keeps_content(): void
    {
        $voice = "investiga    o   bug\n\n  no  hotkey";
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        // Whitespace colapsado para single space, sem perder palavra.
        $this->assertStringContainsString('> investiga o bug no hotkey', $prompt);
        $this->assertStringNotContainsString("\n\n  no", $prompt);
    }

    // ── 14. Self-check determinístico (provider + contradiction) ─────

    public function test_self_check_score_is_high_on_canonical_voice(): void
    {
        $voice = 'Codex, investiga por que o teste de microfone está quebrando '
            .'no VoxOverlay, mas não toque no VoxEvidenceService.';
        $out = $this->compileFromVoice($voice);
        $sc = $out['compiled']['quality_self_check'];

        $this->assertGreaterThanOrEqual(0.85, (float) $sc['score']);
        $this->assertTrue($sc['has_goal']);
        $this->assertTrue($sc['has_expected_output']);
        $this->assertTrue($sc['provider_consistent']);
        $this->assertFalse($sc['contradiction_detected']);
        $this->assertSame([], $sc['lost_negations']);
    }

    public function test_self_check_provider_consistent_flag_for_codex(): void
    {
        $voice = 'Codex, refatora o controller mantendo testes verdes';
        $out = $this->compileFromVoice($voice);
        $sc = $out['compiled']['quality_self_check'];

        $this->assertTrue($sc['provider_consistent']);
        $this->assertStringContainsString(
            '.codex_cli.',
            $out['compiled']['compiled_prompt_template'],
        );
    }

    public function test_self_check_flags_provider_mismatch_when_template_diverges(): void
    {
        // Cenário sintético: extractor diz codex_cli, mas compiler caiu
        // num template "local". Isso seria bug — vamos forçar passando um
        // shape manual para selfCheck() e ver que o flag dispara.
        $compiler = new VoxPromptCompiler();
        $synthetic = [
            'compiled_prompt' => "## Contexto\n## Objetivo\nFoo\n## Saída esperada\n- bar",
            'compiled_prompt_template' => 'builtin.intent_compile.local.text.pt-br@0.2.0',
            'sections' => [],
        ];
        $sc = $compiler->selfCheck('Codex faça X', $synthetic, [
            'goal' => 'Foo',
            'constraints' => [],
            'output_format' => 'text',
            'risk_class' => VoxSchema::RISK_R0,
            'provider_hint' => 'codex_cli',
        ]);
        $this->assertFalse($sc['provider_consistent']);
        $this->assertContains('provider_template_mismatch:codex_cli', $sc['issues']);
    }

    public function test_self_check_contradiction_detected_when_read_only_meets_diff(): void
    {
        // Sintético: voz "só analisa" + format=diff = contradição. Em
        // produção o extractor evita isso, mas o flag protege contra
        // regressão futura.
        $compiler = new VoxPromptCompiler();
        $synthetic = [
            'compiled_prompt' => "## Contexto\n## Objetivo\nX\n## Saída esperada\n- y",
            'compiled_prompt_template' => 'builtin.intent_compile.codex_cli.diff.pt-br@0.2.0',
            'sections' => [],
        ];
        $sc = $compiler->selfCheck('só analisa', $synthetic, [
            'goal' => 'X',
            'constraints' => ['só analisa'],
            'output_format' => 'diff',
            'risk_class' => VoxSchema::RISK_R1,
            'provider_hint' => 'codex_cli',
        ]);
        $this->assertTrue($sc['contradiction_detected']);
        $this->assertContains('contradiction_read_only_but_edit_format', $sc['issues']);
    }

    public function test_self_check_keys_complete_v6_pcf(): void
    {
        $out = $this->compileFromVoice('analisa o estado do VoxOverlay');
        $sc = $out['compiled']['quality_self_check'];

        foreach ([
            'score', 'issues',
            'has_goal', 'has_constraints', 'has_expected_output',
            'lost_negations', 'looks_generic',
            'provider_consistent', 'contradiction_detected',
        ] as $key) {
            $this->assertArrayHasKey($key, $sc, "self-check sem chave '$key'");
        }
    }

    // ── 15. Determinismo + template id ──────────────────────────────

    public function test_compile_is_deterministic_for_same_voice(): void
    {
        $voice = 'Codex investiga por que o overlay não fecha sem editar nada';
        $a = $this->compileFromVoice($voice);
        $b = $this->compileFromVoice($voice);

        $this->assertSame(
            $a['compiled']['compiled_prompt'],
            $b['compiled']['compiled_prompt'],
        );
        $this->assertSame(
            $a['compiled']['compiled_prompt_template'],
            $b['compiled']['compiled_prompt_template'],
        );
    }

    public function test_template_id_encodes_provider_and_format(): void
    {
        $voice = 'Claude faz um plano pra refatorar';
        $out = $this->compileFromVoice($voice);

        $this->assertSame(
            'builtin.intent_compile.claude_cli.plan.pt-br@'.VoxPromptCompiler::TEMPLATE_VERSION,
            $out['compiled']['compiled_prompt_template'],
        );
    }

    // ── 16. Output formats: critérios canônicos ─────────────────────

    public function test_diff_output_demands_file_function_reason(): void
    {
        $voice = 'aplica diff curto pra corrigir o erro do hotkey';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        $this->assertSame('diff', $out['extracted']['output_format']);
        $this->assertStringContainsString('arquivo, função e razão', $prompt);
        $this->assertStringContainsString('Comandos de teste recomendados', $prompt);
    }

    public function test_diagnostic_explicitly_forbids_edits_in_do_not_block(): void
    {
        $voice = 'analisa o módulo VoxCompiler';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        $this->assertSame('diagnostic', $out['extracted']['output_format']);
        $this->assertStringContainsString('## O que NÃO fazer', $prompt);
        $this->assertStringContainsString('Não edite arquivo, não rode comando.', $prompt);
    }

    public function test_plan_format_forbids_implementation_before_acceptance(): void
    {
        $voice = 'faz um plano pra refatorar o controller';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        $this->assertSame('plan', $out['extracted']['output_format']);
        $this->assertStringContainsString(
            'Não comece a implementar antes que o plano seja aceito.',
            $prompt,
        );
    }

    public function test_notes_format_forbids_extra_commentary(): void
    {
        // Voz sem token de diagnostic/plan/diff — só notes vence.
        $voice = 'salva em nota: comprar café amanhã cedo';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        $this->assertSame('notes', $out['extracted']['output_format']);
        $this->assertStringContainsString(
            'Não adicione comentário além do conteúdo da nota.',
            $prompt,
        );
    }

    // ── 17. Risco / vetos universais ────────────────────────────────

    public function test_destructive_output_in_diff_format_still_emits_safety(): void
    {
        // Mesmo se o operador pedir diff, R4 destrutivo precisa do bloco
        // de segurança e do "NÃO execute".
        $voice = 'aplica um diff que faz rm -rf no diretório de cache';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        $this->assertSame(VoxSchema::RISK_R4, $out['extracted']['risk_class']);
        $this->assertStringContainsString('## Segurança', $prompt);
        $this->assertStringContainsString('NÃO execute', $prompt);
        $this->assertStringContainsString(
            'Não simule "como ficaria depois"',
            $prompt,
        );
    }

    public function test_universal_vetoes_are_three_and_always_present(): void
    {
        $voice = 'me diz oi'; // input mínimo
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        // Três vetos universais aparecem sempre, independente do conteúdo.
        $this->assertStringContainsString(
            'Não execute comandos de terminal sozinho.',
            $prompt,
        );
        $this->assertStringContainsString(
            'Não use API paga, não chame provider remoto que cobre por uso.',
            $prompt,
        );
        $this->assertStringContainsString(
            'Não invente arquivo, função ou dependência que não exista no repo.',
            $prompt,
        );
    }

    // ── 18. Long messy transcript ainda fica estruturado ────────────

    public function test_long_messy_transcript_still_produces_canonical_sections(): void
    {
        $voice = 'eh tipo assim, sabe quando o overlay dá aquele pop, '
            .'aí o Codex às vezes começa a editar e não devia, '
            .'queria entender por que o teste do VoxOverlay está pegando '
            .'no momento do hotkey, ah, e antes de tudo confirma o schema '
            .'do AtlasAiVoxController, e sem mexer no kernel beleza';

        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        foreach (VoxPromptCompiler::CANONICAL_SECTIONS as $sec) {
            $this->assertStringContainsString($sec, $prompt);
        }
        // Ainda capturou ao menos uma restrição "antes de" ou "sem".
        $this->assertNotEmpty($out['extracted']['constraints']);
        // Self-check continua ≥ 0.85 mesmo com voz bagunçada.
        $this->assertGreaterThanOrEqual(
            0.85,
            (float) $out['compiled']['quality_self_check']['score'],
        );
    }

    // ── 19. Sem invenção de provider ────────────────────────────────

    public function test_voice_without_provider_mention_defaults_to_local(): void
    {
        $voice = 'analisa o estado do canon V6';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        $this->assertSame('local', $out['extracted']['provider_hint']);
        $this->assertStringContainsString('texto local', $prompt);
        $this->assertStringNotContainsString('Você é o Codex', $prompt);
        $this->assertStringNotContainsString('Você é o Claude Code', $prompt);
    }

    public function test_codex_and_claude_together_yields_auto_provider(): void
    {
        $voice = 'Codex ou Claude, qualquer um, investiga o overlay';
        $out = $this->compileFromVoice($voice);
        $prompt = $out['compiled']['compiled_prompt'];

        $this->assertSame('auto', $out['extracted']['provider_hint']);
        $this->assertStringContainsString('pode ser Codex ou Claude', $prompt);
        $this->assertStringContainsString('local-first, sem provider pago', $prompt);
    }

    // ── 20. "Voz original" + sanidade final ─────────────────────────

    public function test_compiled_prompt_is_substantial_not_short_blurb(): void
    {
        // Garante que qualquer voz não-trivial gera prompt acima do
        // baseline de "looks_generic" (600 chars).
        $voice = 'Codex, investiga por que o build do desktop tá quebrando '
            .'sem mexer no kernel';
        $out = $this->compileFromVoice($voice);
        $this->assertGreaterThan(600, mb_strlen($out['compiled']['compiled_prompt']));
        $this->assertFalse($out['compiled']['quality_self_check']['looks_generic']);
    }
}
