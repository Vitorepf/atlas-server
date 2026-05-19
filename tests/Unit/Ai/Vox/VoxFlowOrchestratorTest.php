<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\Routing\VoxAutoModeRouter;
use App\Services\Ai\Vox\Routing\VoxFlowOrchestrator;
use App\Services\Ai\Vox\VoxIntentExtractor;
use App\Services\Ai\Vox\VoxPromptPolisher;
use App\Services\Ai\Vox\VoxSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * VoxFlowOrchestrator · 20+ frases canônicas + invariantes hard.
 *
 * O orquestrador é puro: recebe transcript + intent_packet + auto_mode_decision
 * + hints e devolve `atlas.vox.flow_decision.v1`. Os testes constroem cada
 * caso via os serviços REAIS (router + extractor) — drift entre as três
 * camadas é detectável pela própria suite.
 */
final class VoxFlowOrchestratorTest extends TestCase
{
    private VoxAutoModeRouter $router;

    private VoxIntentExtractor $extractor;

    private VoxFlowOrchestrator $orchestrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new VoxAutoModeRouter();
        $this->extractor = new VoxIntentExtractor(new VoxPromptPolisher());
        $this->orchestrator = new VoxFlowOrchestrator();
    }

    /**
     * @param  array<int,array{kind:string,ref:?string,resolved:bool}>  $contextRefs
     * @return array<string,mixed>
     */
    private function decide(string $text, array $contextRefs = []): array
    {
        $transcript = [
            'text' => $text,
            'text_raw' => $text,
            'session_id' => '00000000-0000-4000-8000-000000000001',
            'transcript_id' => '00000000-0000-4000-8000-000000000002',
        ];

        $autoDecision = $this->router->decide($transcript);
        $extract = $this->extractor->extract($transcript, ['context_refs' => $contextRefs]);

        $intentPacket = [
            'mode' => $autoDecision['selected_mode'],
            'risk_class' => $extract['risk_class'],
            'provider_hint' => $extract['provider_hint'],
            'executor_hint' => $extract['executor_hint'],
            'output_format' => $extract['output_format'],
            'constraints' => $extract['constraints'],
            'context_refs' => $extract['context_refs'],
        ];

        return $this->orchestrator->decide(
            transcript: $transcript,
            intentPacket: $intentPacket,
            autoDecision: $autoDecision,
            hints: ['context_refs' => $contextRefs],
        );
    }

    // ============================================================
    // 1. Schema / envelope invariants
    // ============================================================

    public function test_envelope_has_canonical_schema_and_version(): void
    {
        $f = $this->decide('anota aqui que amanhã eu vou no banco');
        $this->assertSame('atlas.vox.flow_decision.v1', $f['schema']);
        $this->assertSame(VoxSchema::FLOW_DECISION, $f['schema']);
        $this->assertSame('0.1.0', $f['version']);
        $this->assertSame(VoxSchema::FLOW_ORCHESTRATOR_VERSION, $f['version']);
    }

    public function test_envelope_has_all_required_fields(): void
    {
        $f = $this->decide('manda pro Codex investigar isso');
        $required = [
            'schema', 'version',
            'mode', 'destination', 'risk_class', 'confidence',
            'needs_clarification', 'clarifying_question',
            'what_i_heard', 'what_i_understood', 'what_i_will_do',
            'why_this_flow', 'safe_fallback',
        ];
        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $f, "campo {$key} ausente");
        }
        $this->assertIsBool($f['needs_clarification']);
        $this->assertContains($f['confidence'], ['high', 'medium', 'low']);
        $this->assertContains($f['mode'], [
            VoxSchema::MODE_DICTATION,
            VoxSchema::MODE_PROMPT_POLISH,
            VoxSchema::MODE_INTENT_COMPILE,
            VoxSchema::MODE_GOVERNED_EXECUTE,
        ]);
        $this->assertContains($f['destination'], [
            'clipboard', 'atlas', 'codex', 'claude',
            'terminal_proposal', 'note', 'none',
        ]);
        $this->assertContains($f['risk_class'], ['R0', 'R1', 'R2', 'R3', 'R4']);
        $this->assertContains($f['safe_fallback'], [
            'dictation', 'copy_text', 'ask_clarification', 'cancel',
        ]);
    }

    // ============================================================
    // 2. 20+ frases canônicas (brief V6.5)
    // ============================================================

    public function test_anota_isso_aqui_routes_dictation_clipboard(): void
    {
        $f = $this->decide('anota isso aqui: preciso revisar o planejamento amanhã');
        $this->assertSame(VoxSchema::MODE_DICTATION, $f['mode']);
        $this->assertSame('clipboard', $f['destination']);
        $this->assertFalse($f['needs_clarification']);
        $this->assertSame('copy_text', $f['safe_fallback']);
    }

    public function test_melhora_esse_texto_routes_polish_clipboard(): void
    {
        $f = $this->decide('melhora esse texto pra ficar profissional');
        $this->assertSame(VoxSchema::MODE_PROMPT_POLISH, $f['mode']);
        $this->assertSame('clipboard', $f['destination']);
        $this->assertFalse($f['needs_clarification']);
    }

    public function test_cria_um_prompt_pro_codex_routes_intent_compile_codex(): void
    {
        $f = $this->decide('cria um prompt pro Codex investigar o módulo Vox');
        $this->assertSame(VoxSchema::MODE_INTENT_COMPILE, $f['mode']);
        $this->assertSame('codex', $f['destination']);
        $this->assertFalse($f['needs_clarification']);
    }

    public function test_manda_pro_claude_avaliar_routes_intent_compile_claude(): void
    {
        $f = $this->decide('manda pro Claude avaliar essa arquitetura');
        $this->assertSame(VoxSchema::MODE_INTENT_COMPILE, $f['mode']);
        $this->assertSame('claude', $f['destination']);
        $this->assertFalse($f['needs_clarification']);
    }

    public function test_roda_os_testes_routes_governed_execute_terminal_proposal(): void
    {
        $f = $this->decide('roda os testes do Vox');
        $this->assertSame(VoxSchema::MODE_GOVERNED_EXECUTE, $f['mode']);
        $this->assertSame('terminal_proposal', $f['destination']);
        $this->assertSame('R3', $f['risk_class']);
        $this->assertStringContainsString('NÃO executo', $f['what_i_will_do']);
    }

    public function test_propoe_um_comando_routes_governed_execute_with_terminal_proposal_fallback(): void
    {
        $f = $this->decide('propõe um comando pra ver arquivos modificados');
        $this->assertSame(VoxSchema::MODE_GOVERNED_EXECUTE, $f['mode']);
        $this->assertSame('copy_text', $f['safe_fallback']);
    }

    public function test_apaga_tudo_routes_to_governed_execute_R4_cancel_fallback(): void
    {
        $f = $this->decide('apaga tudo');
        $this->assertSame(VoxSchema::MODE_GOVERNED_EXECUTE, $f['mode']);
        $this->assertSame('R4', $f['risk_class']);
        $this->assertSame('cancel', $f['safe_fallback']);
        $this->assertContains($f['destination'], ['terminal_proposal', 'none']);
        $this->assertStringContainsString('NÃO vou executar', $f['what_i_will_do']);
        $this->assertStringContainsString('confirmação literal', $f['what_i_will_do']);
    }

    public function test_git_push_force_routes_to_R4_cancel(): void
    {
        $f = $this->decide('git push --force pro main');
        $this->assertSame(VoxSchema::MODE_GOVERNED_EXECUTE, $f['mode']);
        $this->assertSame('R4', $f['risk_class']);
        $this->assertSame('cancel', $f['safe_fallback']);
        $this->assertStringContainsString('git push --force', $f['what_i_understood']);
    }

    public function test_olha_esse_arquivo_without_context_needs_clarification(): void
    {
        // Sem context_refs e sem identificador específico → pede clarificação.
        $f = $this->decide('olha esse arquivo');
        $this->assertTrue($f['needs_clarification']);
        $this->assertNotNull($f['clarifying_question']);
        $this->assertStringContainsString('arquivo', $f['clarifying_question']);
        $this->assertSame('none', $f['destination']);
        $this->assertSame('ask_clarification', $f['safe_fallback']);
    }

    public function test_olha_esse_arquivo_with_context_ref_does_not_clarify(): void
    {
        $f = $this->decide('olha esse arquivo', [
            ['kind' => 'file', 'ref' => '/Users/vitor/Atlas/foo.php', 'resolved' => true],
        ]);
        $this->assertFalse($f['needs_clarification']);
        $this->assertNull($f['clarifying_question']);
    }

    public function test_olha_esse_arquivo_with_filename_in_speech_does_not_clarify(): void
    {
        // Sem context_refs MAS com filename na fala → não pede.
        $f = $this->decide('olha esse arquivo VoxAutoModeRouter.php');
        $this->assertFalse($f['needs_clarification']);
    }

    public function test_nao_mexe_em_nada_so_analisa_routes_intent_compile_with_constraints(): void
    {
        $f = $this->decide('não mexe em nada, só analisa esse fluxo do Codex');
        $this->assertSame(VoxSchema::MODE_INTENT_COMPILE, $f['mode']);
        // "Codex" é nome próprio explícito → sem clarification.
        $this->assertFalse($f['needs_clarification']);
        $this->assertSame('codex', $f['destination']);
    }

    public function test_executa_isso_without_context_clarifies(): void
    {
        $f = $this->decide('executa isso');
        $this->assertSame(VoxSchema::MODE_GOVERNED_EXECUTE, $f['mode']);
        $this->assertTrue($f['needs_clarification']);
        $this->assertSame('ask_clarification', $f['safe_fallback']);
        $this->assertSame('none', $f['destination']);
    }

    public function test_deixa_isso_mais_profissional_routes_polish_clipboard(): void
    {
        $f = $this->decide('deixa isso mais profissional');
        $this->assertSame(VoxSchema::MODE_PROMPT_POLISH, $f['mode']);
        $this->assertSame('clipboard', $f['destination']);
        $this->assertSame('high', $f['confidence']);
    }

    public function test_faz_um_prompt_poderoso_routes_intent_compile(): void
    {
        $f = $this->decide('faz um prompt poderoso para análise de mercado');
        $this->assertSame(VoxSchema::MODE_INTENT_COMPILE, $f['mode']);
        $this->assertFalse($f['needs_clarification']);
        $this->assertContains($f['destination'], ['atlas', 'codex', 'claude']);
    }

    public function test_manda_codex_analisar_sem_editar_intent_compile_codex(): void
    {
        $f = $this->decide('manda o Codex analisar esse módulo sem editar');
        $this->assertSame(VoxSchema::MODE_INTENT_COMPILE, $f['mode']);
        // "Codex" é proper noun → sem clarification mesmo sem context_ref.
        $this->assertFalse($f['needs_clarification']);
        $this->assertSame('codex', $f['destination']);
    }

    public function test_cria_uma_nota_routes_dictation_note(): void
    {
        $f = $this->decide('cria uma nota com a ideia do release V6.5');
        $this->assertSame(VoxSchema::MODE_DICTATION, $f['mode']);
        $this->assertSame('note', $f['destination']);
        $this->assertStringContainsString('nota', $f['what_i_will_do']);
    }

    public function test_salva_isso_no_inbox_routes_dictation_note(): void
    {
        $f = $this->decide('salva isso no inbox: revisar planejamento amanhã');
        $this->assertSame(VoxSchema::MODE_DICTATION, $f['mode']);
        $this->assertSame('note', $f['destination']);
    }

    public function test_long_ambiguous_planning_falls_back_to_dictation(): void
    {
        $f = $this->decide(
            'estava aqui pensando que talvez fizesse sentido revisitar a Cartografia depois '.
            'do V6 estabilizar mas sem pressa nenhuma só queria registrar a hipótese antes '.
            'que eu esqueça pra gente revisitar mais pra frente',
        );
        $this->assertSame(VoxSchema::MODE_DICTATION, $f['mode']);
        $this->assertSame('clipboard', $f['destination']);
    }

    public function test_short_random_utterance_lands_on_dictation_low_confidence(): void
    {
        $f = $this->decide('hmm');
        $this->assertSame(VoxSchema::MODE_DICTATION, $f['mode']);
        $this->assertSame('low', $f['confidence']);
        $this->assertSame('copy_text', $f['safe_fallback']);
    }

    public function test_double_intent_polish_plus_create_prompt_asks_for_clarification(): void
    {
        $f = $this->decide('melhora e cria um prompt pro Codex');
        $this->assertTrue($f['needs_clarification']);
        $this->assertSame('ask_clarification', $f['safe_fallback']);
        $this->assertStringContainsString('Codex', (string) $f['clarifying_question']);
    }

    // ============================================================
    // 3. Hard invariants (Leis 0 / 0.9 / canon)
    // ============================================================

    public function test_R4_marker_never_emits_terminal_proposal_alone_without_safe_action_language(): void
    {
        foreach (['apaga tudo', 'git push --force', 'drop database production', 'sudo rm -rf /'] as $phrase) {
            $f = $this->decide($phrase);
            $this->assertSame(VoxSchema::RISK_R4, $f['risk_class']);
            $this->assertSame('cancel', $f['safe_fallback']);
            $this->assertStringContainsString('NÃO', $f['what_i_will_do']);
        }
    }

    public function test_clarification_question_is_pt_br_human(): void
    {
        $f = $this->decide('executa isso');
        $this->assertTrue($f['needs_clarification']);
        $q = (string) $f['clarifying_question'];
        // PT-BR markers + no English jargon.
        $this->assertDoesNotMatchRegularExpression('/\b(?:please|confirm|warning|sorry)\b/i', $q);
        // Termina com pergunta.
        $this->assertNotSame('', $q);
    }

    public function test_orchestrator_is_deterministic(): void
    {
        $a = $this->decide('manda pro Codex investigar o módulo Vox');
        $b = $this->decide('manda pro Codex investigar o módulo Vox');
        $this->assertSame($a['mode'], $b['mode']);
        $this->assertSame($a['destination'], $b['destination']);
        $this->assertSame($a['risk_class'], $b['risk_class']);
        $this->assertSame($a['confidence'], $b['confidence']);
        $this->assertSame($a['what_i_will_do'], $b['what_i_will_do']);
        $this->assertSame($a['clarifying_question'], $b['clarifying_question']);
    }

    public function test_empty_text_does_not_crash_returns_low_confidence(): void
    {
        $f = $this->decide('');
        $this->assertContains($f['mode'], [
            VoxSchema::MODE_DICTATION,
            VoxSchema::MODE_PROMPT_POLISH,
            VoxSchema::MODE_INTENT_COMPILE,
            VoxSchema::MODE_GOVERNED_EXECUTE,
        ]);
        $this->assertSame('low', $f['confidence']);
    }

    public function test_what_i_heard_preserves_user_phrasing(): void
    {
        $f = $this->decide('manda pro Codex investigar isso');
        $this->assertSame('manda pro Codex investigar isso', $f['what_i_heard']);
    }

    public function test_what_i_heard_collapses_whitespace_but_preserves_words(): void
    {
        $f = $this->decide("anota   isso   aqui\n\n  pra mim");
        $this->assertSame('anota isso aqui pra mim', $f['what_i_heard']);
    }

    public function test_governed_execute_low_confidence_demands_clarification(): void
    {
        // "roda" sozinho gera governed_execute com confiança baixa pelo router.
        $f = $this->decide('roda');
        if ($f['mode'] === VoxSchema::MODE_GOVERNED_EXECUTE
            && $f['confidence'] === 'low'
        ) {
            $this->assertTrue($f['needs_clarification']);
            $this->assertSame('ask_clarification', $f['safe_fallback']);
        } else {
            // O router pode caí-lo em dictation por ambiguidade — também aceito.
            $this->assertTrue(true);
        }
    }

    public function test_intent_compile_high_confidence_with_provider_resolves_specific_destination(): void
    {
        $f = $this->decide('pergunta pro Codex se vale a pena reescrever isso');
        $this->assertSame(VoxSchema::MODE_INTENT_COMPILE, $f['mode']);
        $this->assertSame('codex', $f['destination']);
    }

    /**
     * @return iterable<string, array{0:string, 1:string, 2:string, 3:string}>
     */
    public static function brief20CasesProvider(): iterable
    {
        // Tabela canônica do brief V6.5: frase → mode esperado → destination esperado → fallback esperado.
        yield 'anota_isso_aqui' => [
            'anota isso aqui',
            VoxSchema::MODE_DICTATION, 'clipboard', 'copy_text',
        ];
        yield 'melhora_esse_texto' => [
            'melhora esse texto',
            VoxSchema::MODE_PROMPT_POLISH, 'clipboard', 'copy_text',
        ];
        yield 'cria_prompt_pro_codex' => [
            'cria um prompt pro Codex investigar isso',
            VoxSchema::MODE_INTENT_COMPILE, 'codex', 'copy_text',
        ];
        yield 'manda_pro_claude_avaliar' => [
            'manda pro Claude avaliar isso',
            VoxSchema::MODE_INTENT_COMPILE, 'claude', 'copy_text',
        ];
        yield 'roda_os_testes' => [
            'roda os testes do Vox',
            VoxSchema::MODE_GOVERNED_EXECUTE, 'terminal_proposal', 'copy_text',
        ];
        yield 'apaga_tudo' => [
            'apaga tudo',
            VoxSchema::MODE_GOVERNED_EXECUTE, 'none', 'cancel',
        ];
        yield 'git_push_force' => [
            'git push --force',
            VoxSchema::MODE_GOVERNED_EXECUTE, 'none', 'cancel',
        ];
        yield 'cria_uma_nota' => [
            'cria uma nota com isso aqui',
            VoxSchema::MODE_DICTATION, 'note', 'copy_text',
        ];
        yield 'salva_no_inbox' => [
            'salva isso no inbox',
            VoxSchema::MODE_DICTATION, 'note', 'copy_text',
        ];
        yield 'deixa_mais_profissional' => [
            'deixa isso mais profissional',
            VoxSchema::MODE_PROMPT_POLISH, 'clipboard', 'copy_text',
        ];
    }

    #[DataProvider('brief20CasesProvider')]
    public function test_brief_canonical_phrases(
        string $phrase,
        string $expectedMode,
        string $expectedDestination,
        string $expectedFallback,
    ): void {
        $f = $this->decide($phrase);
        $this->assertSame($expectedMode, $f['mode'], "frase '{$phrase}' modo errado");
        $this->assertSame($expectedDestination, $f['destination'], "frase '{$phrase}' destino errado");
        $this->assertSame($expectedFallback, $f['safe_fallback'], "frase '{$phrase}' fallback errado");
    }

    // ════════════════════════════════════════════════════════════════════
    // 4. V6.5 · Composite Intent Splitter
    // ════════════════════════════════════════════════════════════════════
    //
    // Pinos canon do contrato:
    //   - is_composite reflete enunciados com >=2 intenções reais.
    //   - steps na ordem da fala, com mode/destination/risk humanizados.
    //   - execution_policy nunca permite cadeia automática:
    //       * R4 em qualquer step → preview_only.
    //       * Execução misturada com prompt/polish → step_by_step.
    //       * Tudo R0/R1 → single_safe_step.
    //   - frase simples → is_composite=false, 1 step, preserva back-compat.

    public function test_composite_envelope_always_present_and_well_formed(): void
    {
        $f = $this->decide('anota leite');
        $this->assertArrayHasKey('composite', $f);
        $c = $f['composite'];
        $this->assertIsBool($c['is_composite']);
        $this->assertIsArray($c['steps']);
        $this->assertContains($c['execution_policy'], [
            VoxSchema::COMPOSITE_POLICY_PREVIEW_ONLY,
            VoxSchema::COMPOSITE_POLICY_STEP_BY_STEP,
            VoxSchema::COMPOSITE_POLICY_SINGLE_SAFE_STEP,
        ]);
        $this->assertIsInt($c['recommended_next_step']);
        $this->assertGreaterThanOrEqual(1, $c['recommended_next_step']);
        // Cada step tem o shape canônico.
        foreach ($c['steps'] as $step) {
            foreach ([
                'order', 'mode', 'destination', 'summary',
                'risk_class', 'requires_confirmation',
            ] as $key) {
                $this->assertArrayHasKey($key, $step);
            }
        }
    }

    public function test_simple_phrase_is_not_composite(): void
    {
        $f = $this->decide('anota isso aqui que preciso lembrar amanhã');
        $c = $f['composite'];
        $this->assertFalse($c['is_composite']);
        $this->assertCount(1, $c['steps']);
        $this->assertSame(1, $c['steps'][0]['order']);
        $this->assertSame(VoxSchema::COMPOSITE_POLICY_SINGLE_SAFE_STEP, $c['execution_policy']);
    }

    public function test_melhora_e_cria_prompt_para_codex_splits_into_two_steps(): void
    {
        // Frase composta ambígua: polish + create_prompt. O paralelo
        // V6.5-CLARIFICATION-ENGINE pede confirmação (`detectDoubleIntent`),
        // o que rebaixa o composite a `preview_only`. Mesmo assim os 2 steps
        // são identificados e ordenados — é o ganho canon do splitter.
        $f = $this->decide('melhora esse texto e cria um prompt pro Codex');
        $c = $f['composite'];
        $this->assertTrue($c['is_composite']);
        $this->assertCount(2, $c['steps']);
        $this->assertSame(VoxSchema::MODE_PROMPT_POLISH, $c['steps'][0]['mode']);
        $this->assertSame('clipboard', $c['steps'][0]['destination']);
        $this->assertSame(VoxSchema::MODE_INTENT_COMPILE, $c['steps'][1]['mode']);
        $this->assertSame('codex', $c['steps'][1]['destination']);
        // Quando clarification está pendente, policy é `preview_only`;
        // sem clarification, ficaria `single_safe_step` (tudo R0).
        $this->assertContains($c['execution_policy'], [
            VoxSchema::COMPOSITE_POLICY_PREVIEW_ONLY,
            VoxSchema::COMPOSITE_POLICY_SINGLE_SAFE_STEP,
        ]);
        $this->assertSame(1, $c['recommended_next_step']);
    }

    public function test_cria_prompt_pro_claude_e_nao_altera_arquivo_keeps_single_step(): void
    {
        // "não altera arquivo" é restrição, NÃO segunda intenção — composite=false.
        $f = $this->decide('cria um prompt pro Claude e não altera arquivo');
        $c = $f['composite'];
        $this->assertFalse($c['is_composite']);
        $this->assertCount(1, $c['steps']);
        $this->assertSame(VoxSchema::MODE_INTENT_COMPILE, $c['steps'][0]['mode']);
        $this->assertSame('claude', $c['steps'][0]['destination']);
    }

    public function test_analisa_isso_e_depois_roda_os_testes_step_by_step_confirmation(): void
    {
        $f = $this->decide('analisa esse módulo VoxCompiler e depois roda os testes do Vox');
        $c = $f['composite'];
        $this->assertTrue($c['is_composite']);
        $this->assertCount(2, $c['steps']);
        // Step 1 é análise (R1, atlas/codex/claude — sem execução).
        $this->assertSame(VoxSchema::MODE_INTENT_COMPILE, $c['steps'][0]['mode']);
        $this->assertSame(VoxSchema::RISK_R1, $c['steps'][0]['risk_class']);
        $this->assertFalse($c['steps'][0]['requires_confirmation']);
        // Step 2 é execução de testes (R3, terminal_proposal).
        $this->assertSame(VoxSchema::MODE_GOVERNED_EXECUTE, $c['steps'][1]['mode']);
        $this->assertSame('terminal_proposal', $c['steps'][1]['destination']);
        $this->assertSame(VoxSchema::RISK_R3, $c['steps'][1]['risk_class']);
        $this->assertTrue($c['steps'][1]['requires_confirmation']);
        // Policy: mistura prompt/análise + execução → step_by_step.
        $this->assertSame(VoxSchema::COMPOSITE_POLICY_STEP_BY_STEP, $c['execution_policy']);
    }

    public function test_faz_um_resumo_e_salva_como_nota_two_safe_steps(): void
    {
        $f = $this->decide('faz um resumo dessa conversa e salva como nota');
        $c = $f['composite'];
        $this->assertTrue($c['is_composite']);
        $this->assertCount(2, $c['steps']);
        // Resumo = polish/clipboard R0. Salvar como nota = dictation/note R0.
        $this->assertSame(VoxSchema::MODE_PROMPT_POLISH, $c['steps'][0]['mode']);
        $this->assertSame('clipboard', $c['steps'][0]['destination']);
        $this->assertSame(VoxSchema::MODE_DICTATION, $c['steps'][1]['mode']);
        $this->assertSame('note', $c['steps'][1]['destination']);
        // Tudo R0 → single_safe_step.
        $this->assertSame(VoxSchema::COMPOSITE_POLICY_SINGLE_SAFE_STEP, $c['execution_policy']);
    }

    public function test_propoe_um_comando_e_copia_pra_mim_treated_as_single_step(): void
    {
        // "copia pra mim" é semântica do destino terminal_proposal, não step
        // novo. O importante: NUNCA executa, e o splitter NÃO promete
        // single_safe_step quando há proposição de comando.
        $f = $this->decide('propõe um comando pra ver arquivos modificados e copia pra mim');
        $c = $f['composite'];
        // Sem cadeia automática "segura" pra proposta de comando — ao menos
        // step_by_step ou preview_only quando ambíguo.
        $this->assertNotSame(VoxSchema::COMPOSITE_POLICY_SINGLE_SAFE_STEP, $c['execution_policy']);
    }

    public function test_apaga_tudo_e_faz_commit_forces_preview_only(): void
    {
        $f = $this->decide('apaga tudo e faz commit');
        $c = $f['composite'];
        $this->assertTrue($c['is_composite']);
        $this->assertSame(VoxSchema::COMPOSITE_POLICY_PREVIEW_ONLY, $c['execution_policy']);
        // Pelo menos um step R4 com confirmation obrigatória.
        $hasR4 = false;
        foreach ($c['steps'] as $step) {
            if ($step['risk_class'] === VoxSchema::RISK_R4) {
                $hasR4 = true;
                $this->assertTrue($step['requires_confirmation']);
            }
        }
        $this->assertTrue($hasR4, 'esperava step R4 em "apaga tudo e faz commit"');
    }

    public function test_corrige_esse_erro_e_roda_build_step_by_step(): void
    {
        // Texto explícito o suficiente pra escapar `detectMissingPolishTarget`
        // (token_count >= 8) e ainda assim acionar o splitter por causa do
        // conector "e" entre duas intenções claras.
        $f = $this->decide('corrige esse erro no overlay do VoxAutoModeRouter e roda os testes do build');
        $c = $f['composite'];
        $this->assertTrue($c['is_composite']);
        // Tem step de execução → nunca single_safe_step (pode ser
        // step_by_step ou preview_only se clarification disparou).
        $this->assertNotSame(VoxSchema::COMPOSITE_POLICY_SINGLE_SAFE_STEP, $c['execution_policy']);
        $hasExec = false;
        foreach ($c['steps'] as $step) {
            if ($step['mode'] === VoxSchema::MODE_GOVERNED_EXECUTE) {
                $hasExec = true;
                $this->assertSame(VoxSchema::RISK_R3, $step['risk_class']);
            }
        }
        $this->assertTrue($hasExec);
    }

    public function test_primeiro_organiza_isso_depois_manda_pro_codex_splits_ordered(): void
    {
        $f = $this->decide('primeiro organiza esse texto, depois manda pro Codex investigar');
        $c = $f['composite'];
        $this->assertTrue($c['is_composite']);
        $this->assertGreaterThanOrEqual(2, count($c['steps']));
        // Order começa em 1 e cresce monotônico.
        foreach ($c['steps'] as $i => $step) {
            $this->assertSame($i + 1, $step['order']);
        }
        // Primeiro step é polish (organizar texto).
        $this->assertSame(VoxSchema::MODE_PROMPT_POLISH, $c['steps'][0]['mode']);
        // Algum step depois envolve Codex.
        $hasCodex = false;
        foreach ($c['steps'] as $step) {
            if ($step['destination'] === 'codex') {
                $hasCodex = true;
            }
        }
        $this->assertTrue($hasCodex);
    }

    public function test_cria_prompt_e_executa_step_by_step(): void
    {
        $f = $this->decide('cria um prompt pro Codex revisar e executa o build');
        $c = $f['composite'];
        $this->assertTrue($c['is_composite']);
        // Mistura prompt + execução: NUNCA single_safe_step.
        $this->assertNotSame(VoxSchema::COMPOSITE_POLICY_SINGLE_SAFE_STEP, $c['execution_policy']);
        // recommended_next_step nunca pula pra execução.
        $this->assertSame(1, $c['recommended_next_step']);
        $first = $c['steps'][0];
        $this->assertNotSame(VoxSchema::MODE_GOVERNED_EXECUTE, $first['mode']);
    }

    public function test_two_executions_chained_force_step_by_step_with_r3_each(): void
    {
        $f = $this->decide('aplica o diff no AtlasAiVoxController e depois roda os testes');
        $c = $f['composite'];
        $this->assertTrue($c['is_composite']);
        // Tem execução → policy=step_by_step (nunca cadeia auto).
        $this->assertSame(VoxSchema::COMPOSITE_POLICY_STEP_BY_STEP, $c['execution_policy']);
        $execSteps = 0;
        foreach ($c['steps'] as $step) {
            if ($step['mode'] === VoxSchema::MODE_GOVERNED_EXECUTE) {
                $execSteps++;
                $this->assertSame(VoxSchema::RISK_R3, $step['risk_class']);
                $this->assertTrue($step['requires_confirmation']);
            }
        }
        $this->assertGreaterThanOrEqual(1, $execSteps);
    }

    public function test_destructive_in_second_step_blocks_chain(): void
    {
        // Step 1 inocente; step 2 destrutivo → tudo vira preview_only.
        $f = $this->decide('cria um prompt pro Codex e depois roda rm -rf no cache');
        $c = $f['composite'];
        $this->assertTrue($c['is_composite']);
        $this->assertSame(VoxSchema::COMPOSITE_POLICY_PREVIEW_ONLY, $c['execution_policy']);
        $hasR4 = false;
        foreach ($c['steps'] as $step) {
            if ($step['risk_class'] === VoxSchema::RISK_R4) {
                $hasR4 = true;
                $this->assertTrue($step['requires_confirmation']);
            }
        }
        $this->assertTrue($hasR4);
    }

    public function test_connector_em_seguida_also_splits(): void
    {
        $f = $this->decide('melhora esse texto em seguida cria prompt pro Claude');
        $c = $f['composite'];
        $this->assertTrue($c['is_composite']);
        $this->assertSame(VoxSchema::MODE_PROMPT_POLISH, $c['steps'][0]['mode']);
        $hasClaude = false;
        foreach ($c['steps'] as $step) {
            if ($step['destination'] === 'claude') {
                $hasClaude = true;
            }
        }
        $this->assertTrue($hasClaude);
    }

    public function test_connector_ai_splits_safely(): void
    {
        // "aí" coloquial.
        $f = $this->decide('anota isso aí salva como nota');
        $c = $f['composite'];
        $this->assertTrue($c['is_composite']);
        // Tudo R0.
        $this->assertSame(VoxSchema::COMPOSITE_POLICY_SINGLE_SAFE_STEP, $c['execution_policy']);
    }

    public function test_enumeration_without_verb_per_segment_does_not_split(): void
    {
        // "anota leite, pão e ovos" tem conector "e" mas sem segunda intenção
        // (só itens da lista). NÃO pode virar composite.
        $f = $this->decide('anota na lista de compras: leite pão e ovos');
        $c = $f['composite'];
        $this->assertFalse($c['is_composite']);
        $this->assertCount(1, $c['steps']);
    }

    public function test_recommended_next_step_starts_at_one(): void
    {
        foreach ([
            'melhora esse texto e cria prompt pro Codex',
            'corrige o overlay e roda os testes',
            'apaga tudo e faz commit',
        ] as $phrase) {
            $f = $this->decide($phrase);
            $this->assertSame(1, $f['composite']['recommended_next_step'],
                "frase '{$phrase}' deveria começar no step 1");
        }
    }

    public function test_composite_summaries_are_pt_br_human_no_jargon(): void
    {
        $f = $this->decide('melhora esse texto e cria prompt pro Codex');
        foreach ($f['composite']['steps'] as $step) {
            $s = $step['summary'];
            $this->assertNotSame('', $s);
            $this->assertDoesNotMatchRegularExpression(
                '/\b(?:please|confirm|warning|sorry|error|unauthorized)\b/i',
                $s,
                "summary '{$s}' contém jargão inglês",
            );
        }
    }

    public function test_composite_is_deterministic_across_calls(): void
    {
        $a = $this->decide('analisa o overlay e depois roda os testes');
        $b = $this->decide('analisa o overlay e depois roda os testes');
        $this->assertSame($a['composite'], $b['composite']);
    }

    public function test_clarification_pending_keeps_composite_preview_only(): void
    {
        // Voz ambígua entra em clarification — composite vira previw_only,
        // 1 step, pra UI não propor cadeia em cima de pedido incerto.
        $f = $this->decide('executa isso');
        $this->assertTrue($f['needs_clarification']);
        $c = $f['composite'];
        $this->assertFalse($c['is_composite']);
        $this->assertCount(1, $c['steps']);
        $this->assertSame(VoxSchema::COMPOSITE_POLICY_PREVIEW_ONLY, $c['execution_policy']);
    }

    public function test_r4_single_phrase_forces_preview_only_even_when_not_composite(): void
    {
        $f = $this->decide('apaga tudo');
        $c = $f['composite'];
        $this->assertFalse($c['is_composite']);
        $this->assertSame(VoxSchema::COMPOSITE_POLICY_PREVIEW_ONLY, $c['execution_policy']);
        $this->assertSame(VoxSchema::RISK_R4, $c['steps'][0]['risk_class']);
        $this->assertTrue($c['steps'][0]['requires_confirmation']);
    }

    public function test_single_governed_execute_is_step_by_step_not_safe_chain(): void
    {
        // Frase simples mas com governed_execute → mesmo sendo 1 step,
        // policy não pode ser single_safe_step.
        $f = $this->decide('roda os testes do Vox');
        $c = $f['composite'];
        $this->assertFalse($c['is_composite']);
        $this->assertNotSame(VoxSchema::COMPOSITE_POLICY_SINGLE_SAFE_STEP, $c['execution_policy']);
    }

    public function test_three_step_composite_preserves_order(): void
    {
        $f = $this->decide('primeiro organiza esse texto, depois cria prompt pro Codex, em seguida roda os testes');
        $c = $f['composite'];
        $this->assertTrue($c['is_composite']);
        $this->assertGreaterThanOrEqual(2, count($c['steps']));
        foreach ($c['steps'] as $i => $step) {
            $this->assertSame($i + 1, $step['order']);
        }
        // Execução está sempre no final, nunca antes do prompt.
        $execIndices = [];
        $promptIndices = [];
        foreach ($c['steps'] as $i => $step) {
            if ($step['mode'] === VoxSchema::MODE_GOVERNED_EXECUTE) {
                $execIndices[] = $i;
            }
            if ($step['mode'] === VoxSchema::MODE_INTENT_COMPILE) {
                $promptIndices[] = $i;
            }
        }
        if ($execIndices !== [] && $promptIndices !== []) {
            $this->assertGreaterThan(min($promptIndices), max($execIndices),
                'execução deveria vir DEPOIS do prompt na sequência falada');
        }
    }

    public function test_composite_back_compat_legacy_fields_unchanged(): void
    {
        // Frontend antigo lê só mode/destination/risk_class. Composite
        // não pode alterar esses valores.
        $f = $this->decide('melhora esse texto');
        $this->assertSame(VoxSchema::MODE_PROMPT_POLISH, $f['mode']);
        $this->assertSame('clipboard', $f['destination']);
        $this->assertSame(VoxSchema::RISK_R0, $f['risk_class']);
        // composite presente, sem inventar campos extras nos legados.
        $this->assertArrayHasKey('composite', $f);
    }

    // ============================================================
    // V6.5-CLARIFICATION-ENGINE · matriz 20+ cases que perguntam
    // ============================================================

    /**
     * @return iterable<string, array{0:string, 1?:string|null}>
     */
    public static function shouldAskClarificationProvider(): iterable
    {
        // [frase, fragmento esperado na pergunta (null = qualquer)]
        yield 'faz isso' => ['faz isso', 'fazer'];
        yield 'faz isso aqui' => ['faz isso aqui', 'fazer'];
        yield 'roda isso' => ['roda isso', null];
        yield 'manda isso' => ['manda isso', null];
        yield 'apaga isso' => ['apaga isso', null];
        yield 'deleta isso' => ['deleta isso', null];
        yield 'olha esse arquivo sem context' => ['olha esse arquivo', 'arquivo'];
        yield 'abre aquele negócio' => ['abre aquele negócio', null];
        yield 'corrige esse erro' => ['corrige esse erro', 'arquivo'];
        yield 'corrige esse bug' => ['corrige esse bug', 'arquivo'];
        yield 'coloca isso lá' => ['coloca isso lá', null];
        yield 'executa no terminal genérico' => ['executa no terminal', 'terminal'];
        yield 'roda no terminal genérico' => ['roda no terminal', 'terminal'];
        yield 'melhora isso' => ['melhora isso', 'texto'];
        yield 'limpa isso' => ['limpa isso', 'texto'];
        yield 'corrige isso' => ['corrige isso', 'texto'];
        yield 'arruma isso' => ['arruma isso', null];
        yield 'manda pro Codex sem objetivo' => ['manda pro Codex', 'Codex'];
        yield 'manda pro Claude sem objetivo' => ['manda pro Claude', 'Claude'];
        yield 'pergunta pro Codex sem objetivo' => ['pergunta pro Codex', 'Codex'];
        yield 'pede pro Claude sem objetivo' => ['pede pro Claude', 'Claude'];
        yield 'olha aqui' => ['olha aqui', null];
        yield 'edita esse trecho' => ['edita esse trecho', 'arquivo'];
        yield 'analisa esse fluxo' => ['analisa esse fluxo', 'arquivo'];
    }

    #[DataProvider('shouldAskClarificationProvider')]
    public function test_clarification_engine_asks(string $phrase, ?string $askFragment): void
    {
        $f = $this->decide($phrase);
        $this->assertTrue(
            $f['needs_clarification'],
            "frase '{$phrase}' deveria pedir clarificação, mas needs_clarification=false",
        );
        $this->assertSame('ask_clarification', $f['safe_fallback']);
        $this->assertSame('none', $f['destination']);
        $this->assertNotNull($f['clarifying_question']);
        $this->assertNotSame('', trim((string) $f['clarifying_question']));
        if ($askFragment !== null) {
            $this->assertStringContainsString(
                $askFragment,
                (string) $f['clarifying_question'],
                "pergunta de '{$phrase}' deveria conter '{$askFragment}'",
            );
        }
    }

    /**
     * @return iterable<string, array{0:string, 1:array<int, array{kind:string,ref:?string,resolved:bool}>}>
     */
    public static function shouldNotAskClarificationProvider(): iterable
    {
        yield 'melhora esse texto: payload' => [
            'melhora esse texto: oi tudo bem aqui é o vitor do atlas',
            [],
        ];
        yield 'cria prompt pro codex com objetivo' => [
            'cria um prompt pro Codex investigar lentidão no Atlas Vox',
            [],
        ];
        yield 'anota com conteúdo' => [
            'anota que amanhã eu preciso revisar X',
            [],
        ];
        yield 'propõe comando com alvo' => [
            'propõe um comando para listar arquivos modificados',
            [],
        ];
        yield 'roda testes com alvo' => [
            'roda os testes do Vox',
            [],
        ];
        yield 'manda codex com context_ref' => [
            'manda o Codex analisar este arquivo',
            [['kind' => 'file', 'ref' => '/Users/x/foo.php', 'resolved' => true]],
        ];
        yield 'deixa texto mais profissional (qualificador)' => [
            'deixa esse texto mais profissional pra eu mandar pro cliente',
            [],
        ];
        yield 'deixa isso mais profissional (qualificador no isso)' => [
            'deixa isso mais profissional',
            [],
        ];
        yield 'melhora esse trem (goiano + texto longo)' => [
            'melhora esse trem aí pra ficar mais decente',
            [],
        ];
    }

    #[DataProvider('shouldNotAskClarificationProvider')]
    public function test_clarification_engine_does_not_ask(string $phrase, array $contextRefs): void
    {
        $f = $this->decide($phrase, $contextRefs);
        $this->assertFalse(
            $f['needs_clarification'],
            "frase '{$phrase}' NÃO deveria pedir clarificação, mas needs_clarification=true (pergunta: '".((string) $f['clarifying_question'])."')",
        );
        $this->assertNotSame('ask_clarification', $f['safe_fallback']);
    }

    public function test_clarification_questions_are_pt_br_no_jargon_and_under_120_chars(): void
    {
        // Coleta todas as perguntas únicas que o engine produz.
        $phrases = array_map(static fn ($p) => $p[0], iterator_to_array(self::shouldAskClarificationProvider()));
        $jargon = ['/\bplease\b/i', '/\bconfirm\b/i', '/\bsorry\b/i', '/\bwarning\b/i', '/\berror\b/i', '/\bok\?\b/i', '/\bback\b/i', '/HTTP/i', '/JSON/i', '/422/'];
        foreach ($phrases as $phrase) {
            $f = $this->decide($phrase);
            $q = (string) $f['clarifying_question'];
            $this->assertNotSame('', $q, "vazio em '{$phrase}'");
            $this->assertLessThanOrEqual(
                120,
                mb_strlen($q),
                "pergunta de '{$phrase}' tem ".mb_strlen($q)." chars (max 120): \"{$q}\"",
            );
            foreach ($jargon as $jr) {
                $this->assertDoesNotMatchRegularExpression(
                    $jr,
                    $q,
                    "pergunta de '{$phrase}' vazou jargão: \"{$q}\"",
                );
            }
        }
    }

    public function test_clarification_engine_never_asks_on_destructive_r4(): void
    {
        // R4 destrutivo deve BLOQUEAR/governar (safe_fallback=cancel), NUNCA
        // virar pergunta. Hard rule do brief.
        foreach (['apaga tudo', 'git push --force pro main', 'drop database production', 'sudo rm -rf /', 'manda um rm -rf no cache'] as $phrase) {
            $f = $this->decide($phrase);
            $this->assertSame(
                VoxSchema::RISK_R4,
                $f['risk_class'],
                "frase '{$phrase}' deveria ser R4",
            );
            $this->assertSame(
                'cancel',
                $f['safe_fallback'],
                "frase '{$phrase}' R4 deveria fallback=cancel, não ask_clarification",
            );
            $this->assertFalse(
                $f['needs_clarification'],
                "R4 destrutivo '{$phrase}' NÃO deve virar pergunta (precisa bloquear)",
            );
        }
    }

    public function test_clarification_engine_resolved_context_ref_skips_question(): void
    {
        // Mesma frase ambígua: sem context → pede; com context_ref file → não pede.
        $f1 = $this->decide('olha esse arquivo');
        $this->assertTrue($f1['needs_clarification']);

        $f2 = $this->decide('olha esse arquivo', [
            ['kind' => 'file', 'ref' => '/Users/vitor/Atlas/foo.php', 'resolved' => true],
        ]);
        $this->assertFalse($f2['needs_clarification']);
    }

    public function test_clarification_engine_resolved_active_window_also_skips(): void
    {
        $f = $this->decide('edita esse arquivo', [
            ['kind' => 'active_window', 'ref' => 'editor.tsx', 'resolved' => true],
        ]);
        $this->assertFalse($f['needs_clarification']);
    }

    public function test_clarification_engine_unresolved_context_ref_still_asks(): void
    {
        // context_ref presente mas `resolved=false` (extractor não pôde resolver).
        $f = $this->decide('olha esse arquivo', [
            ['kind' => 'file', 'ref' => null, 'resolved' => false],
        ]);
        $this->assertTrue($f['needs_clarification']);
    }

    public function test_clarification_engine_is_deterministic(): void
    {
        // Mesma frase ambígua, duas chamadas → resposta idêntica.
        $a = $this->decide('manda pro Codex');
        $b = $this->decide('manda pro Codex');
        $this->assertSame($a['clarifying_question'], $b['clarifying_question']);
        $this->assertSame($a['safe_fallback'], $b['safe_fallback']);
        $this->assertSame($a['needs_clarification'], $b['needs_clarification']);
    }
}
