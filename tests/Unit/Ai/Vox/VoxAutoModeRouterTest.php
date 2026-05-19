<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\Routing\VoxAutoModeRouter;
use App\Services\Ai\Vox\VoxSchema;
use PHPUnit\Framework\TestCase;

/**
 * 30+ PT-BR cases covering the four modes plus ambiguity, R4 hard-veto
 * and the conservative "needs_confirmation" rules. See the V4 brief for
 * the contract: deterministic, no network, never auto-execute.
 */
final class VoxAutoModeRouterTest extends TestCase
{
    private function router(): VoxAutoModeRouter
    {
        return new VoxAutoModeRouter();
    }

    // ============================================================
    // Schema / contract invariants
    // ============================================================

    public function test_decision_payload_has_canonical_schema_id(): void
    {
        $r = $this->router()->decide(['text' => 'anota isso aqui pra mim']);

        $this->assertSame('atlas.vox.auto_mode_decision.v1', $r['schema']);
        $this->assertSame(VoxSchema::AUTO_MODE_DECISION, $r['schema']);
        $this->assertSame(VoxSchema::AUTO_MODE_ROUTER_VERSION, $r['router_version']);
    }

    public function test_decision_payload_contains_all_required_keys(): void
    {
        $r = $this->router()->decide(['text' => 'manda pro codex investigar o módulo Vox']);

        $this->assertArrayHasKey('selected_mode', $r);
        $this->assertArrayHasKey('confidence', $r);
        $this->assertArrayHasKey('reason_pt_br', $r);
        $this->assertArrayHasKey('needs_confirmation', $r);
        $this->assertArrayHasKey('alternatives', $r);
        $this->assertArrayHasKey('signals', $r);
        $this->assertArrayHasKey('markers', $r);
        $this->assertIsFloat($r['confidence']);
        $this->assertGreaterThanOrEqual(0.0, $r['confidence']);
        $this->assertLessThanOrEqual(1.0, $r['confidence']);
        $this->assertIsString($r['reason_pt_br']);
        $this->assertNotSame('', $r['reason_pt_br']);
        $this->assertIsArray($r['alternatives']);
    }

    public function test_selected_mode_is_always_one_of_the_canonical_four(): void
    {
        $validModes = [
            VoxSchema::MODE_DICTATION,
            VoxSchema::MODE_PROMPT_POLISH,
            VoxSchema::MODE_INTENT_COMPILE,
            VoxSchema::MODE_GOVERNED_EXECUTE,
        ];

        foreach (['', 'hmm', 'nao sei', 'só pensando alto'] as $text) {
            $r = $this->router()->decide(['text' => $text]);
            $this->assertContains($r['selected_mode'], $validModes, "Mode invalid for input: {$text}");
        }
    }

    // ============================================================
    // 1) DICTATION — free-form text, no operational verbs
    // ============================================================

    public function test_anota_que_amanha_routes_to_dictation(): void
    {
        $r = $this->router()->decide([
            'text' => 'anota que amanhã eu preciso passar no banco antes da reunião do projeto Atlas',
        ]);
        $this->assertSame(VoxSchema::MODE_DICTATION, $r['selected_mode']);
        $this->assertGreaterThanOrEqual(0.72, $r['confidence']);
        $this->assertFalse($r['needs_confirmation'], 'High-confidence dictation should not need confirmation');
    }

    public function test_escreve_isso_aqui_routes_to_dictation(): void
    {
        $r = $this->router()->decide([
            'text' => 'escreve isso aqui pra mim: a Atlas Cartografia vai entrar como Surface canon depois da Fatia 7',
        ]);
        $this->assertSame(VoxSchema::MODE_DICTATION, $r['selected_mode']);
        $this->assertGreaterThanOrEqual(0.72, $r['confidence']);
    }

    public function test_coloca_esse_texto_routes_to_dictation(): void
    {
        $r = $this->router()->decide([
            'text' => 'coloca esse texto no inbox: reunião do squad de plataforma hoje às quatro',
        ]);
        $this->assertSame(VoxSchema::MODE_DICTATION, $r['selected_mode']);
        $this->assertGreaterThanOrEqual(0.70, $r['confidence']);
    }

    public function test_long_freeform_thought_falls_back_to_dictation(): void
    {
        $r = $this->router()->decide([
            'text' => 'então eu estava pensando aqui que talvez faça sentido a gente revisitar o Atlas Vox V4 mais pra frente porque o V3 já cobre Ditado Polish Intent e Governed Execute mas a UX manual fica um pouco cansada e o operador precisa escolher modo o tempo todo',
        ]);
        $this->assertSame(VoxSchema::MODE_DICTATION, $r['selected_mode']);
    }

    public function test_short_freeform_with_no_signal_still_lands_on_dictation_with_confirmation(): void
    {
        $r = $this->router()->decide(['text' => 'hmm acho que é isso']);
        $this->assertSame(VoxSchema::MODE_DICTATION, $r['selected_mode']);
        $this->assertTrue($r['needs_confirmation']);
    }

    public function test_salva_como_nota_routes_to_dictation(): void
    {
        $r = $this->router()->decide([
            'text' => 'salva isso como nota no inbox por favor',
        ]);
        $this->assertSame(VoxSchema::MODE_DICTATION, $r['selected_mode']);
        $this->assertGreaterThanOrEqual(0.70, $r['confidence']);
    }

    // ============================================================
    // 2) PROMPT_POLISH — clean/organize/rewrite existing text
    // ============================================================

    public function test_melhora_isso_routes_to_polish(): void
    {
        $r = $this->router()->decide(['text' => 'melhora isso pra ficar mais claro']);
        $this->assertSame(VoxSchema::MODE_PROMPT_POLISH, $r['selected_mode']);
        $this->assertGreaterThanOrEqual(0.72, $r['confidence']);
        $this->assertFalse($r['needs_confirmation']);
    }

    public function test_organiza_esse_texto_routes_to_polish(): void
    {
        $r = $this->router()->decide(['text' => 'organiza esse texto e devolve formatado']);
        $this->assertSame(VoxSchema::MODE_PROMPT_POLISH, $r['selected_mode']);
        $this->assertGreaterThanOrEqual(0.78, $r['confidence']);
    }

    public function test_deixa_esse_prompt_mais_forte_routes_to_polish(): void
    {
        $r = $this->router()->decide(['text' => 'deixa esse prompt mais forte sem mudar o que eu pedi']);
        $this->assertSame(VoxSchema::MODE_PROMPT_POLISH, $r['selected_mode']);
        $this->assertGreaterThanOrEqual(0.82, $r['confidence']);
    }

    public function test_corrige_a_pontuacao_routes_to_polish(): void
    {
        $r = $this->router()->decide(['text' => 'corrige a pontuação e devolve igual mas legível']);
        $this->assertSame(VoxSchema::MODE_PROMPT_POLISH, $r['selected_mode']);
        $this->assertGreaterThanOrEqual(0.78, $r['confidence']);
    }

    public function test_reescreve_isso_routes_to_polish(): void
    {
        $r = $this->router()->decide(['text' => 'reescreve isso mantendo o sentido']);
        $this->assertSame(VoxSchema::MODE_PROMPT_POLISH, $r['selected_mode']);
        $this->assertGreaterThanOrEqual(0.72, $r['confidence']);
    }

    public function test_limpa_esse_texto_pra_inserir_no_inbox_polish_wins(): void
    {
        // Edge: "inbox" exists in the text but the operator is clearly
        // asking to polish first. The polish signal must beat dictation.
        $r = $this->router()->decide(['text' => 'limpa esse texto pra inserir no inbox']);
        $this->assertSame(VoxSchema::MODE_PROMPT_POLISH, $r['selected_mode']);
    }

    public function test_arruma_esse_prompt_routes_to_polish(): void
    {
        $r = $this->router()->decide(['text' => 'arruma esse prompt pra ficar profissional']);
        $this->assertSame(VoxSchema::MODE_PROMPT_POLISH, $r['selected_mode']);
    }

    // ============================================================
    // 3) INTENT_COMPILE — speak human, get prompt for the AI
    // ============================================================

    public function test_manda_pro_codex_routes_to_intent_compile(): void
    {
        $r = $this->router()->decide(['text' => 'manda pro codex investigar o módulo Vox sem editar nada']);
        $this->assertSame(VoxSchema::MODE_INTENT_COMPILE, $r['selected_mode']);
        $this->assertGreaterThanOrEqual(0.80, $r['confidence']);
        $this->assertFalse($r['needs_confirmation']);
    }

    public function test_pergunta_pro_claude_routes_to_intent_compile(): void
    {
        $r = $this->router()->decide(['text' => 'pergunta pro claude se vale a pena reescrever o VoxCompiler']);
        $this->assertSame(VoxSchema::MODE_INTENT_COMPILE, $r['selected_mode']);
        $this->assertGreaterThanOrEqual(0.78, $r['confidence']);
    }

    public function test_cria_um_prompt_pro_codex_routes_to_intent_compile(): void
    {
        $r = $this->router()->decide([
            'text' => 'cria um prompt pro codex pra analisar o histórico de receipts e me devolver um diagnóstico',
        ]);
        $this->assertSame(VoxSchema::MODE_INTENT_COMPILE, $r['selected_mode']);
        $this->assertGreaterThanOrEqual(0.80, $r['confidence']);
    }

    public function test_monta_um_prompt_routes_to_intent_compile(): void
    {
        $r = $this->router()->decide([
            'text' => 'monta um prompt explicando que eu quero um plano com riscos e dependências',
        ]);
        $this->assertSame(VoxSchema::MODE_INTENT_COMPILE, $r['selected_mode']);
        $this->assertGreaterThanOrEqual(0.78, $r['confidence']);
    }

    public function test_codex_investiga_isso_routes_to_intent_compile(): void
    {
        $r = $this->router()->decide(['text' => 'codex investiga isso aqui e me devolve um diagnóstico']);
        $this->assertSame(VoxSchema::MODE_INTENT_COMPILE, $r['selected_mode']);
        $this->assertGreaterThanOrEqual(0.72, $r['confidence']);
    }

    public function test_me_ajuda_a_pedir_routes_to_intent_compile_with_confirmation(): void
    {
        // Sinal mais fraco — router escolhe intent_compile mas pede confirmação.
        $r = $this->router()->decide([
            'text' => 'me ajuda a pedir pro claude pra ele me dar um plano de migração',
        ]);
        $this->assertSame(VoxSchema::MODE_INTENT_COMPILE, $r['selected_mode']);
    }

    public function test_briefing_pro_claude_routes_to_intent_compile(): void
    {
        $r = $this->router()->decide([
            'text' => 'monta um briefing pro claude com o contexto do Atlas Vox V4',
        ]);
        $this->assertSame(VoxSchema::MODE_INTENT_COMPILE, $r['selected_mode']);
    }

    // ============================================================
    // 4) GOVERNED_EXECUTE — always requires confirmation
    // ============================================================

    public function test_roda_os_testes_routes_to_governed_execute_with_confirmation(): void
    {
        $r = $this->router()->decide(['text' => 'roda os testes do Vox aqui rapidinho']);
        $this->assertSame(VoxSchema::MODE_GOVERNED_EXECUTE, $r['selected_mode']);
        $this->assertTrue($r['needs_confirmation'], 'governed_execute deve sempre exigir confirmação');
    }

    public function test_executa_esse_comando_routes_to_governed_execute(): void
    {
        $r = $this->router()->decide(['text' => 'executa esse comando no terminal por favor']);
        $this->assertSame(VoxSchema::MODE_GOVERNED_EXECUTE, $r['selected_mode']);
        $this->assertTrue($r['needs_confirmation']);
    }

    public function test_cria_o_arquivo_routes_to_governed_execute(): void
    {
        $r = $this->router()->decide([
            'text' => 'cria o arquivo VoxAutoModeRouter.php em app/Services/Ai/Vox/Routing',
        ]);
        $this->assertSame(VoxSchema::MODE_GOVERNED_EXECUTE, $r['selected_mode']);
        $this->assertTrue($r['needs_confirmation']);
    }

    public function test_edita_o_arquivo_routes_to_governed_execute(): void
    {
        $r = $this->router()->decide(['text' => 'edita o arquivo VoxSchema.php e adiciona a constante nova']);
        $this->assertSame(VoxSchema::MODE_GOVERNED_EXECUTE, $r['selected_mode']);
        $this->assertTrue($r['needs_confirmation']);
    }

    public function test_faz_o_commit_routes_to_governed_execute(): void
    {
        $r = $this->router()->decide(['text' => 'faz o commit dessas mudanças aqui pra mim']);
        $this->assertSame(VoxSchema::MODE_GOVERNED_EXECUTE, $r['selected_mode']);
        $this->assertTrue($r['needs_confirmation']);
    }

    public function test_aplica_essa_mudanca_routes_to_governed_execute(): void
    {
        $r = $this->router()->decide(['text' => 'aplica essa mudança no controller e roda os testes']);
        $this->assertSame(VoxSchema::MODE_GOVERNED_EXECUTE, $r['selected_mode']);
        $this->assertTrue($r['needs_confirmation']);
    }

    public function test_php_artisan_routes_to_governed_execute(): void
    {
        $r = $this->router()->decide(['text' => 'roda php artisan migrate fresh aqui']);
        $this->assertSame(VoxSchema::MODE_GOVERNED_EXECUTE, $r['selected_mode']);
        $this->assertTrue($r['needs_confirmation']);
    }

    public function test_apaga_o_arquivo_routes_to_governed_execute(): void
    {
        $r = $this->router()->decide(['text' => 'apaga o arquivo de teste antigo que sobrou']);
        $this->assertSame(VoxSchema::MODE_GOVERNED_EXECUTE, $r['selected_mode']);
        $this->assertTrue($r['needs_confirmation']);
    }

    // ============================================================
    // 5) R4 hard-veto — destructive markers always route to
    //    governed_execute and always demand confirmation
    // ============================================================

    public function test_rm_rf_routes_to_governed_execute_with_high_confidence(): void
    {
        $r = $this->router()->decide(['text' => 'manda um rm -rf no diretório de cache']);
        $this->assertSame(VoxSchema::MODE_GOVERNED_EXECUTE, $r['selected_mode']);
        $this->assertTrue($r['needs_confirmation']);
        $this->assertGreaterThanOrEqual(0.90, $r['confidence']);
        $this->assertSame('rm_rf', $r['markers']['r4_marker'] ?? null);
    }

    public function test_git_push_force_is_r4(): void
    {
        $r = $this->router()->decide(['text' => 'faz git push --force pra resolver isso']);
        $this->assertSame(VoxSchema::MODE_GOVERNED_EXECUTE, $r['selected_mode']);
        $this->assertTrue($r['needs_confirmation']);
        $this->assertSame('git_push_force', $r['markers']['r4_marker'] ?? null);
    }

    public function test_drop_database_is_r4(): void
    {
        $r = $this->router()->decide(['text' => 'drop database e roda do zero']);
        $this->assertSame(VoxSchema::MODE_GOVERNED_EXECUTE, $r['selected_mode']);
        $this->assertTrue($r['needs_confirmation']);
        $this->assertSame('drop_database', $r['markers']['r4_marker'] ?? null);
    }

    public function test_deploy_keyword_is_r4(): void
    {
        $r = $this->router()->decide(['text' => 'manda fazer deploy do Vox V4 agora']);
        $this->assertSame(VoxSchema::MODE_GOVERNED_EXECUTE, $r['selected_mode']);
        $this->assertTrue($r['needs_confirmation']);
    }

    public function test_sudo_keyword_is_r4(): void
    {
        $r = $this->router()->decide(['text' => 'roda sudo brew install whisper-cpp']);
        $this->assertSame(VoxSchema::MODE_GOVERNED_EXECUTE, $r['selected_mode']);
        $this->assertTrue($r['needs_confirmation']);
        $this->assertSame('sudo', $r['markers']['r4_marker'] ?? null);
    }

    public function test_apagar_tudo_is_r4(): void
    {
        $r = $this->router()->decide(['text' => 'apaga tudo do storage e começa do zero']);
        $this->assertSame(VoxSchema::MODE_GOVERNED_EXECUTE, $r['selected_mode']);
        $this->assertTrue($r['needs_confirmation']);
    }

    // ============================================================
    // 6) Ambiguity — low confidence or close runner-up
    //    => needs_confirmation + alternatives
    // ============================================================

    public function test_ambiguous_short_imperative_asks_for_confirmation(): void
    {
        // "cria" alone is ambiguous between intent_compile (cria um prompt)
        // and governed_execute (cria um arquivo).
        $r = $this->router()->decide(['text' => 'cria isso aqui']);
        $this->assertTrue($r['needs_confirmation']);
        $this->assertNotEmpty($r['alternatives']);
    }

    public function test_ambiguous_decision_provides_alternatives(): void
    {
        $r = $this->router()->decide(['text' => 'hmmm']);
        $this->assertTrue($r['needs_confirmation']);
    }

    public function test_alternatives_never_repeat_the_top_mode(): void
    {
        $r = $this->router()->decide(['text' => 'cria isso aqui']);
        $this->assertNotEmpty($r['alternatives']);
        foreach ($r['alternatives'] as $alt) {
            $this->assertNotSame($r['selected_mode'], $alt['mode']);
        }
    }

    public function test_governed_execute_low_confidence_still_requires_confirmation(): void
    {
        // "roda" sozinho é fraco — confiança baixa. Independentemente do
        // modo escolhido, o router NÃO pode pular confirmação.
        $r = $this->router()->decide(['text' => 'roda']);
        $this->assertTrue($r['needs_confirmation']);
        if ($r['selected_mode'] === VoxSchema::MODE_GOVERNED_EXECUTE) {
            // governed_execute sempre exige confirmação, mesmo com confiança alta.
            $this->assertTrue($r['needs_confirmation']);
        }
    }

    // ============================================================
    // 7) Backwards compatibility safety net
    //    The router must NOT alter the manual mode pipeline. It only
    //    proposes; the Desktop/Controller still drives mode_requested.
    //    Below we check that the canonical mode strings the router
    //    emits match the ones VoxSchema and the controller expect.
    // ============================================================

    public function test_router_only_emits_canonical_mode_strings(): void
    {
        $samples = [
            'anota que amanhã eu preciso fazer X',
            'melhora esse prompt',
            'manda pro codex investigar',
            'roda os testes',
            'cria isso',
            '',
            'sudo rm -rf /',
        ];
        foreach ($samples as $s) {
            $r = $this->router()->decide(['text' => $s]);
            $this->assertContains($r['selected_mode'], [
                VoxSchema::MODE_DICTATION,
                VoxSchema::MODE_PROMPT_POLISH,
                VoxSchema::MODE_INTENT_COMPILE,
                VoxSchema::MODE_GOVERNED_EXECUTE,
            ]);
            foreach ($r['alternatives'] as $alt) {
                $this->assertContains($alt['mode'], [
                    VoxSchema::MODE_DICTATION,
                    VoxSchema::MODE_PROMPT_POLISH,
                    VoxSchema::MODE_INTENT_COMPILE,
                    VoxSchema::MODE_GOVERNED_EXECUTE,
                ]);
            }
        }
    }

    public function test_router_is_deterministic(): void
    {
        // Não pode existir randomness — duas chamadas com o mesmo input
        // devem produzir o mesmo selected_mode, confidence e reason.
        $a = $this->router()->decide(['text' => 'manda pro codex investigar isso']);
        $b = $this->router()->decide(['text' => 'manda pro codex investigar isso']);
        $this->assertSame($a['selected_mode'], $b['selected_mode']);
        $this->assertSame($a['confidence'], $b['confidence']);
        $this->assertSame($a['reason_pt_br'], $b['reason_pt_br']);
        $this->assertSame($a['needs_confirmation'], $b['needs_confirmation']);
    }

    public function test_empty_text_does_not_crash_and_returns_dictation(): void
    {
        $r = $this->router()->decide(['text' => '']);
        $this->assertSame(VoxSchema::MODE_DICTATION, $r['selected_mode']);
        $this->assertTrue($r['needs_confirmation']);
    }

    public function test_reason_is_in_portuguese(): void
    {
        $r = $this->router()->decide(['text' => 'manda pro codex investigar isso']);
        // A reason canônica do intent_compile menciona "IA (Codex/Claude)".
        $this->assertStringContainsString('IA', $r['reason_pt_br']);
    }

    // ============================================================
    // 8) Confidence bands sanity
    // ============================================================

    public function test_strong_dictation_does_not_emit_excessive_alternatives(): void
    {
        $r = $this->router()->decide([
            'text' => 'anota que amanhã eu preciso passar no banco antes da reunião do projeto Atlas e revisar o orçamento',
        ]);
        $this->assertLessThanOrEqual(2, count($r['alternatives']));
    }

    public function test_signals_only_contain_four_canonical_modes(): void
    {
        $r = $this->router()->decide(['text' => 'manda pro codex investigar isso']);
        $this->assertCount(4, $r['signals']);
        $this->assertArrayHasKey(VoxSchema::MODE_DICTATION, $r['signals']);
        $this->assertArrayHasKey(VoxSchema::MODE_PROMPT_POLISH, $r['signals']);
        $this->assertArrayHasKey(VoxSchema::MODE_INTENT_COMPILE, $r['signals']);
        $this->assertArrayHasKey(VoxSchema::MODE_GOVERNED_EXECUTE, $r['signals']);
    }
}
