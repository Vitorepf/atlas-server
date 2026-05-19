<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\Interlocutor\VoxInterlocutorPolicy;
use App\Services\Ai\Vox\VoxSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * V5-C · Atlas Vox Symbiotic Interlocutor — testes de calibração.
 *
 * Cobertura:
 *   - 8 casos canônicos do brief V5-C
 *   - fixtures reais PT-BR (data providers)
 *   - non-intervention edges (segurança contra "chatear")
 *   - HARD vs SOFT policy markers por risk_class
 *   - clarify só sem contexto resolvido
 *   - weak prompt suprimido por identifier ou clear-action-intent
 *   - tom PT-BR sem leak inglês
 *   - determinismo
 *
 * Honestidade: policy é puro/determinístico (sem rede, sem LLM, sem random).
 */
final class VoxInterlocutorPolicyTest extends TestCase
{
    private function policy(): VoxInterlocutorPolicy
    {
        return new VoxInterlocutorPolicy();
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function intentPacket(array $overrides = []): array
    {
        return array_merge([
            'schema' => VoxSchema::INTENT_PACKET,
            'session_id' => 'sess-fake-0001',
            'intent_id' => 'intent-fake-0001',
            'transcript_ref' => 'tr-fake-0001',
            'mode' => VoxSchema::MODE_INTENT_COMPILE,
            'context_refs' => [['kind' => 'none', 'ref' => null, 'resolved' => true]],
            'executor_hint' => 'none',
            'output_format' => 'text',
            'human_input_text' => 'manda o codex investigar esse módulo',
            'goal' => 'Investigar módulo Voice em modo leitura',
            'constraints' => ['não editar arquivos'],
            'provider_hint' => 'codex_cli',
            'compiled_prompt' => str_repeat('linha do prompt compilado real. ', 8),
            'compiled_prompt_template' => 'codex.md@v3',
            'risk_class' => VoxSchema::RISK_R0,
            'risk_reasoning' => 'leitura sem efeito externo',
            'compiler_version' => VoxSchema::COMPILER_VERSION,
        ], $overrides);
    }

    // ============================================================
    // Schema / contract invariants
    // ============================================================

    public function test_schema_and_version_are_canonical(): void
    {
        $decision = $this->policy()->evaluate(
            transcript: ['text' => 'manda o codex investigar o módulo Voice'],
            intentPacket: $this->intentPacket(),
        );

        $this->assertSame('atlas.vox.interlocutor_decision.v1', $decision['schema']);
        $this->assertSame('0.2.0', $decision['policy_version']);
        $this->assertSame(VoxSchema::INTERLOCUTOR_VERSION, $decision['policy_version']);
    }

    public function test_policy_is_deterministic_for_same_input(): void
    {
        $transcript = ['text' => 'manda um git push --force pro main agora'];
        $packet = $this->intentPacket([
            'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
            'risk_class' => VoxSchema::RISK_R4,
            'goal' => '',
            'constraints' => [],
        ]);

        $a = $this->policy()->evaluate(transcript: $transcript, intentPacket: $packet);
        $b = $this->policy()->evaluate(transcript: $transcript, intentPacket: $packet);
        $this->assertSame($a, $b);
    }

    // ============================================================
    // 8 casos canônicos do brief V5-C
    // ============================================================

    public function test_canonical_case_1a_manda_codex_olhar_isso_without_context_returns_clarify(): void
    {
        // Sem context_refs resolvidos: pede clarify.
        $decision = $this->policy()->evaluate(
            transcript: ['text' => 'manda o Codex olhar isso'],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_INTENT_COMPILE,
                'goal' => '',
                'constraints' => [],
                'compiled_prompt' => '',
                'context_refs' => [['kind' => 'none', 'ref' => null, 'resolved' => true]],
            ]),
        );

        $this->assertSame(VoxInterlocutorPolicy::INTERVENTION_CLARIFY, $decision['intervention']);
        $this->assertFalse($decision['blocking']);
        $this->assertSame(VoxInterlocutorPolicy::REASON_AMBIGUOUS_REFERENCE, $decision['reason_code']);
    }

    public function test_canonical_case_1b_manda_codex_olhar_isso_with_context_does_not_clarify(): void
    {
        // Com workspace resolvido: NÃO chateia.
        $decision = $this->policy()->evaluate(
            transcript: ['text' => 'manda o Codex olhar isso'],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_INTENT_COMPILE,
                'goal' => 'olhar isso',
                'constraints' => [],
                'compiled_prompt' => str_repeat('prompt compilado completo do Atlas. ', 5),
                'context_refs' => [
                    ['kind' => 'workspace', 'ref' => 'atlas-server', 'resolved' => true],
                ],
            ]),
        );

        $this->assertNotSame(VoxInterlocutorPolicy::INTERVENTION_CLARIFY, $decision['intervention']);
    }

    public function test_canonical_case_2_roda_isso_returns_clarify(): void
    {
        $decision = $this->policy()->evaluate(
            transcript: ['text' => 'roda isso pra mim'],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'risk_class' => VoxSchema::RISK_R1,
                'goal' => '',
                'constraints' => [],
            ]),
        );

        $this->assertSame(VoxInterlocutorPolicy::INTERVENTION_CLARIFY, $decision['intervention']);
        $this->assertFalse($decision['blocking']);
    }

    public function test_canonical_case_3_apaga_tudo_returns_disagree_blocking(): void
    {
        $decision = $this->policy()->evaluate(
            transcript: ['text' => 'apaga tudo dessa pasta'],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'risk_class' => VoxSchema::RISK_R3, // mesmo abaixo de R4 BLOQUEIA
                'goal' => '',
                'constraints' => [],
            ]),
        );

        $this->assertSame(VoxInterlocutorPolicy::INTERVENTION_DISAGREE, $decision['intervention']);
        $this->assertTrue($decision['blocking']);
        $this->assertSame(VoxInterlocutorPolicy::REASON_DESTRUCTIVE_RISK, $decision['reason_code']);
    }

    public function test_canonical_case_4a_git_reset_hard_r4_returns_disagree_blocking(): void
    {
        $decision = $this->policy()->evaluate(
            transcript: ['text' => 'faz git reset --hard no main'],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'risk_class' => VoxSchema::RISK_R4,
                'goal' => '',
                'constraints' => [],
            ]),
        );

        $this->assertSame(VoxInterlocutorPolicy::INTERVENTION_DISAGREE, $decision['intervention']);
        $this->assertTrue($decision['blocking']);
    }

    public function test_canonical_case_4b_git_reset_hard_r3_returns_disagree_non_blocking(): void
    {
        $decision = $this->policy()->evaluate(
            transcript: ['text' => 'faz git reset --hard pra voltar pro último commit'],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'risk_class' => VoxSchema::RISK_R3,
                'goal' => '',
                'constraints' => [],
            ]),
        );

        $this->assertSame(VoxInterlocutorPolicy::INTERVENTION_DISAGREE, $decision['intervention']);
        $this->assertFalse($decision['blocking']);
        $this->assertSame(VoxInterlocutorPolicy::REASON_SAFER_PATH_AVAILABLE, $decision['reason_code']);
    }

    public function test_canonical_case_5_melhora_esse_texto_returns_none(): void
    {
        // prompt_polish: V4 segue sem intervenção.
        $decision = $this->policy()->evaluate(
            transcript: ['text' => 'melhora esse texto que eu vou mandar pro time'],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_PROMPT_POLISH,
                'goal' => 'texto profissional pro time',
                'constraints' => [],
                'risk_class' => VoxSchema::RISK_R0,
            ]),
        );

        $this->assertSame(VoxInterlocutorPolicy::INTERVENTION_NONE, $decision['intervention']);
    }

    public function test_canonical_case_6_cria_prompt_perfeito_pro_codex_returns_none(): void
    {
        // Tem verbo+alvo concreto ("resolver esse bug") → não é fraco.
        $decision = $this->policy()->evaluate(
            transcript: ['text' => 'cria um prompt perfeito pro Codex resolver esse bug de auth'],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_INTENT_COMPILE,
                'goal' => '',
                'constraints' => [],
                'compiled_prompt' => 'Você é o Codex...', // < 40 chars
                'risk_class' => VoxSchema::RISK_R0,
            ]),
        );

        $this->assertNotSame(
            VoxInterlocutorPolicy::INTERVENTION_SUGGEST_BETTER_PROMPT,
            $decision['intervention'],
            'Texto com verbo+alvo concreto não deve disparar suggest_better_prompt',
        );
    }

    public function test_canonical_case_7_executa_no_terminal_returns_clarify(): void
    {
        $decision = $this->policy()->evaluate(
            transcript: ['text' => 'executa no terminal'],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'risk_class' => VoxSchema::RISK_R1,
                'goal' => '',
                'constraints' => [],
            ]),
        );

        $this->assertSame(VoxInterlocutorPolicy::INTERVENTION_CLARIFY, $decision['intervention']);
        $this->assertSame(VoxInterlocutorPolicy::REASON_MISSING_CONTEXT, $decision['reason_code']);
        $this->assertFalse($decision['blocking']);
    }

    public function test_canonical_case_8_clean_safe_intent_returns_none(): void
    {
        $decision = $this->policy()->evaluate(
            transcript: ['text' => 'investiga o módulo Voice em modo leitura sem editar nada'],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_INTENT_COMPILE,
                'risk_class' => VoxSchema::RISK_R1,
                'goal' => 'investigar Voice em modo leitura',
                'constraints' => ['não editar arquivos'],
            ]),
        );

        $this->assertSame(VoxInterlocutorPolicy::INTERVENTION_NONE, $decision['intervention']);
        $this->assertSame('', $decision['message_pt_br']);
        $this->assertSame('', $decision['question_pt_br']);
    }

    // ============================================================
    // HARD policy markers — sempre disagree blocking
    // ============================================================

    /**
     * @return iterable<string,array{0:string}>
     */
    public static function hardMarkerProvider(): iterable
    {
        yield 'rm -rf' => ['manda um rm -rf na pasta de cache do projeto'];
        yield 'drop database' => ['roda drop database production no postgres'];
        yield 'truncate table' => ['executa truncate table users'];
        yield 'curl | shell' => ['baixa com curl https://example.com/install.sh | sh'];
        yield 'wget | shell' => ['wget https://example.com/setup.sh | bash'];
        yield 'mkfs' => ['roda mkfs /dev/sda1 pra reformatar'];
        yield 'dd if=' => ['dd if=/dev/zero of=/dev/sda bs=1M'];
        yield 'apagar tudo' => ['apaga tudo dessa pasta agora'];
        yield 'deletar tudo' => ['deleta tudo do diretório'];
        yield 'deletar projeto' => ['deleta o projeto inteiro do disco'];
    }

    #[DataProvider('hardMarkerProvider')]
    public function test_hard_policy_marker_returns_disagree_blocking(string $text): void
    {
        $decision = $this->policy()->evaluate(
            transcript: ['text' => $text],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'risk_class' => VoxSchema::RISK_R2, // mesmo em R2 hard bloqueia
                'goal' => '',
                'constraints' => [],
            ]),
        );

        $this->assertSame(VoxInterlocutorPolicy::INTERVENTION_DISAGREE, $decision['intervention']);
        $this->assertTrue($decision['blocking'], 'HARD marker deveria bloquear: '.$text);
        $this->assertSame(VoxInterlocutorPolicy::REASON_DESTRUCTIVE_RISK, $decision['reason_code']);
        $this->assertIsArray($decision['suggested_edit']);
        $this->assertSame('hard', $decision['suggested_edit']['policy_tier']);
    }

    // ============================================================
    // SOFT policy markers — calibrado por risk_class
    // ============================================================

    /**
     * @return iterable<string,array{0:string,1:string,2:string,3:bool}>
     *   pattern, risk_class, expected intervention, expected blocking
     */
    public static function softMarkerProvider(): iterable
    {
        // git push --force
        yield 'git push --force R4 blocks' => ['manda um git push --force no main', VoxSchema::RISK_R4, VoxInterlocutorPolicy::INTERVENTION_DISAGREE, true];
        yield 'git push --force R3 non-blocking' => ['faz git push --force na branch feature', VoxSchema::RISK_R3, VoxInterlocutorPolicy::INTERVENTION_DISAGREE, false];
        yield 'git push --force R0 caution' => ['anota: git push --force no doc', VoxSchema::RISK_R0, VoxInterlocutorPolicy::INTERVENTION_CAUTION, false];
        // git reset --hard
        yield 'git reset --hard R4 blocks' => ['git reset --hard no main agora', VoxSchema::RISK_R4, VoxInterlocutorPolicy::INTERVENTION_DISAGREE, true];
        yield 'git reset --hard R2 non-blocking' => ['git reset --hard local pra voltar', VoxSchema::RISK_R2, VoxInterlocutorPolicy::INTERVENTION_DISAGREE, false];
        // deploy
        yield 'deploy R4 blocks' => ['fazer deploy em produção agora', VoxSchema::RISK_R4, VoxInterlocutorPolicy::INTERVENTION_DISAGREE, true];
        yield 'deploy R3 non-blocking' => ['fazer deploy de staging', VoxSchema::RISK_R3, VoxInterlocutorPolicy::INTERVENTION_DISAGREE, false];
        // sudo (lista restrita: rm/chmod/dd/mkfs/kill/reboot/systemctl/etc)
        yield 'sudo rm R4 blocks' => ['sudo rm /var/log/algumacoisa', VoxSchema::RISK_R4, VoxInterlocutorPolicy::INTERVENTION_DISAGREE, true];
        yield 'sudo chmod R2 non-blocking' => ['sudo chmod +x script.sh', VoxSchema::RISK_R2, VoxInterlocutorPolicy::INTERVENTION_DISAGREE, false];
    }

    #[DataProvider('softMarkerProvider')]
    public function test_soft_policy_marker_is_calibrated_per_risk_class(
        string $text,
        string $risk,
        string $expectedIntervention,
        bool $expectedBlocking,
    ): void {
        $decision = $this->policy()->evaluate(
            transcript: ['text' => $text],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'risk_class' => $risk,
                'goal' => '',
                'constraints' => [],
            ]),
        );

        $this->assertSame($expectedIntervention, $decision['intervention'], "input: {$text} @ {$risk}");
        $this->assertSame($expectedBlocking, $decision['blocking'], "input: {$text} @ {$risk}");
    }

    public function test_sudo_alone_without_operational_verb_does_not_fire(): void
    {
        // "rodar sem sudo primeiro" não deve disparar SOFT — o pattern é
        // restrito a sudo+verb operacional. Documenta + ativa precision.
        $decision = $this->policy()->evaluate(
            transcript: ['text' => 'lembrar de rodar sem sudo primeiro pra entender o erro'],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_DICTATION,
                'risk_class' => VoxSchema::RISK_R0,
                'goal' => '',
                'constraints' => [],
                'compiled_prompt' => '',
            ]),
        );

        $this->assertSame(VoxInterlocutorPolicy::INTERVENTION_NONE, $decision['intervention']);
    }

    // ============================================================
    // Ambiguous references (sem contexto)
    // ============================================================

    /**
     * @return iterable<string,array{0:string}>
     */
    public static function ambiguousReferenceProvider(): iterable
    {
        yield 'olha isso' => ['olha isso pra mim'];
        yield 'manda isso' => ['manda isso pro codex'];
        yield 'roda isso' => ['roda isso aqui'];
        yield 'vê isso' => ['ve isso'];
        yield 'pega isso' => ['pega isso e me devolve'];
        yield 'abre essa' => ['abre essa daqui pra eu ler'];
        yield 'aquele arquivo' => ['abre aquele arquivo de novo'];
        yield 'aquela pasta' => ['lista aquela pasta lá'];
        yield 'olha aqui' => ['olha aqui um segundo'];
        yield 'essa coisa' => ['manda essa coisa pro Codex'];
        yield 'nosso projeto' => ['roda no nosso projeto'];
        yield 'esse bug' => ['vê esse bug'];
        yield 'codex olhar isso' => ['Codex olhar isso por favor'];
    }

    #[DataProvider('ambiguousReferenceProvider')]
    public function test_ambiguous_reference_without_context_returns_clarify(string $text): void
    {
        $decision = $this->policy()->evaluate(
            transcript: ['text' => $text],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'risk_class' => VoxSchema::RISK_R1,
                'goal' => '',
                'constraints' => [],
                'compiled_prompt' => '',
                'context_refs' => [['kind' => 'none', 'ref' => null, 'resolved' => true]],
            ]),
        );

        $this->assertSame(VoxInterlocutorPolicy::INTERVENTION_CLARIFY, $decision['intervention'], "input: {$text}");
        $this->assertFalse($decision['blocking']);
        $this->assertSame(VoxInterlocutorPolicy::REASON_AMBIGUOUS_REFERENCE, $decision['reason_code']);
        // uma pergunta por vez — não pode ter nem ; nem ? duplo.
        $this->assertGreaterThan(0, mb_strlen($decision['question_pt_br']));
        $this->assertSame(1, substr_count($decision['question_pt_br'], '?'));
    }

    public function test_ambiguous_reference_with_resolved_context_is_silent(): void
    {
        $decision = $this->policy()->evaluate(
            transcript: ['text' => 'manda isso pro codex sem mexer em nada'],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_INTENT_COMPILE,
                'goal' => 'olhar trecho selecionado',
                'constraints' => ['não mexer em nada'],
                'compiled_prompt' => str_repeat('prompt completo. ', 6),
                'context_refs' => [
                    ['kind' => 'composer_selection', 'ref' => 'sha256:abc', 'resolved' => true],
                ],
            ]),
        );

        $this->assertNotSame(VoxInterlocutorPolicy::INTERVENTION_CLARIFY, $decision['intervention']);
    }

    // ============================================================
    // Terminal sem comando
    // ============================================================

    /**
     * @return iterable<string,array{0:string}>
     */
    public static function terminalBareProvider(): iterable
    {
        yield 'executa no terminal' => ['executa no terminal'];
        yield 'roda no terminal' => ['roda no terminal'];
        yield 'executa esse terminal' => ['executa esse terminal'];
    }

    #[DataProvider('terminalBareProvider')]
    public function test_terminal_without_command_returns_clarify(string $text): void
    {
        $decision = $this->policy()->evaluate(
            transcript: ['text' => $text],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'risk_class' => VoxSchema::RISK_R1,
                'goal' => '',
                'constraints' => [],
                'compiled_prompt' => '',
            ]),
        );

        $this->assertSame(VoxInterlocutorPolicy::INTERVENTION_CLARIFY, $decision['intervention'], "input: {$text}");
        $this->assertSame(VoxInterlocutorPolicy::REASON_MISSING_CONTEXT, $decision['reason_code']);
    }

    public function test_terminal_with_explicit_command_does_not_clarify(): void
    {
        $decision = $this->policy()->evaluate(
            transcript: ['text' => 'roda no terminal os testes do phpunit'],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'risk_class' => VoxSchema::RISK_R2,
                'goal' => 'rodar testes phpunit',
                'constraints' => [],
            ]),
        );

        $this->assertNotSame(VoxInterlocutorPolicy::INTERVENTION_CLARIFY, $decision['intervention']);
    }

    // ============================================================
    // Weak prompt — suprimido por sinais de intenção concreta
    // ============================================================

    /**
     * @return iterable<string,array{0:string}>
     */
    public static function weakPromptProvider(): iterable
    {
        yield 'manda pro codex' => ['manda pro codex'];
        yield 'pergunta pro claude' => ['pergunta pro claude'];
        yield 'pede pro codex' => ['pede pro codex'];
        yield 'fala pro claude' => ['fala pro claude'];
    }

    #[DataProvider('weakPromptProvider')]
    public function test_short_bare_request_for_intent_compile_returns_suggest_better_prompt(string $text): void
    {
        $decision = $this->policy()->evaluate(
            transcript: ['text' => $text],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_INTENT_COMPILE,
                'goal' => '',
                'constraints' => [],
                'compiled_prompt' => '',
                'risk_class' => VoxSchema::RISK_R0,
            ]),
        );

        $this->assertSame(
            VoxInterlocutorPolicy::INTERVENTION_SUGGEST_BETTER_PROMPT,
            $decision['intervention'],
            "input: {$text}",
        );
        $this->assertFalse($decision['blocking']);
    }

    /**
     * @return iterable<string,array{0:string}>
     */
    public static function strongIntentSuppressorsProvider(): iterable
    {
        // Verbo + objeto direto concreto.
        yield 'resolver bug auth' => ['pede pro codex resolver o bug de auth'];
        yield 'investigar AuthController' => ['manda pro codex investigar o AuthController'];
        yield 'implementar paginação' => ['cria um prompt pro claude implementar a paginação no endpoint'];
        yield 'refatorar service' => ['pergunta pro claude refatorar o service de auth'];
        // Identifier concreto (CamelCase / arquivo).
        yield 'AuthController.php' => ['olha AuthController.php pra mim'];
        yield 'snake_case_helper' => ['manda investigar o helper run_tests'];
        yield 'path /api/users' => ['investiga o endpoint /api/users'];
    }

    #[DataProvider('strongIntentSuppressorsProvider')]
    public function test_strong_intent_suppresses_weak_prompt(string $text): void
    {
        $decision = $this->policy()->evaluate(
            transcript: ['text' => $text],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_INTENT_COMPILE,
                'goal' => '',
                'constraints' => [],
                'compiled_prompt' => 'curto',
                'risk_class' => VoxSchema::RISK_R0,
            ]),
        );

        $this->assertNotSame(
            VoxInterlocutorPolicy::INTERVENTION_SUGGEST_BETTER_PROMPT,
            $decision['intervention'],
            "Suggest deveria ser suprimido por sinal forte em: {$text}",
        );
    }

    public function test_weak_prompt_not_triggered_when_goal_filled(): void
    {
        $decision = $this->policy()->evaluate(
            transcript: ['text' => 'manda pro codex'],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_INTENT_COMPILE,
                'goal' => 'Codex investiga AuthController',
                'constraints' => ['não editar testes'],
                'compiled_prompt' => str_repeat('prompt estruturado. ', 5),
                'risk_class' => VoxSchema::RISK_R0,
            ]),
        );

        $this->assertNotSame(VoxInterlocutorPolicy::INTERVENTION_SUGGEST_BETTER_PROMPT, $decision['intervention']);
    }

    // ============================================================
    // Caution R2/R3 — sem chatear quando não há marker destrutivo
    // ============================================================

    public function test_r2_without_destructive_marker_returns_caution(): void
    {
        $decision = $this->policy()->evaluate(
            transcript: ['text' => 'edita a config do ambiente local pra ativar a flag'],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'risk_class' => VoxSchema::RISK_R2,
                'goal' => 'ativar flag em config local',
                'constraints' => [],
            ]),
        );

        $this->assertSame(VoxInterlocutorPolicy::INTERVENTION_CAUTION, $decision['intervention']);
        $this->assertFalse($decision['blocking']);
        $this->assertSame('', $decision['question_pt_br']); // sem pergunta — só notinha
    }

    public function test_r3_without_destructive_marker_returns_caution(): void
    {
        $decision = $this->policy()->evaluate(
            transcript: ['text' => 'faz commit e push pro repo de staging'],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'risk_class' => VoxSchema::RISK_R3,
                'goal' => 'commit + push em staging',
                'constraints' => [],
            ]),
        );

        $this->assertSame(VoxInterlocutorPolicy::INTERVENTION_CAUTION, $decision['intervention']);
        $this->assertFalse($decision['blocking']);
    }

    // ============================================================
    // None — casos seguros e claros, jamais intervém
    // ============================================================

    /**
     * @return iterable<string,array{0:string,1:string,2:string}>
     */
    public static function silentCasesProvider(): iterable
    {
        yield 'investigação clara R0' => [
            'investiga o módulo Voice em modo leitura sem mexer em nada',
            VoxSchema::MODE_INTENT_COMPILE,
            VoxSchema::RISK_R0,
        ];
        yield 'ditado simples' => [
            'anota lembrete: ligar pro Vitor amanhã às dez',
            VoxSchema::MODE_DICTATION,
            VoxSchema::RISK_R0,
        ];
        yield 'polish texto direto' => [
            'melhora esse texto pra ficar mais profissional',
            VoxSchema::MODE_PROMPT_POLISH,
            VoxSchema::RISK_R0,
        ];
        yield 'git status' => [
            'roda git status pra ver o que mudou',
            VoxSchema::MODE_GOVERNED_EXECUTE,
            VoxSchema::RISK_R1,
        ];
        yield 'pergunta clara ao codex' => [
            'pede pro Codex investigar o AuthController e listar dependências',
            VoxSchema::MODE_INTENT_COMPILE,
            VoxSchema::RISK_R0,
        ];
    }

    #[DataProvider('silentCasesProvider')]
    public function test_silent_cases_return_none(string $text, string $mode, string $risk): void
    {
        $decision = $this->policy()->evaluate(
            transcript: ['text' => $text],
            intentPacket: $this->intentPacket([
                'mode' => $mode,
                'risk_class' => $risk,
                'goal' => 'objetivo descrito acima',
                'constraints' => [],
                'compiled_prompt' => str_repeat('prompt forte estruturado. ', 6),
            ]),
        );

        $this->assertSame(
            VoxInterlocutorPolicy::INTERVENTION_NONE,
            $decision['intervention'],
            "Não deveria intervir em: {$text}",
        );
        $this->assertSame('', $decision['message_pt_br']);
        $this->assertSame('', $decision['question_pt_br']);
        $this->assertFalse($decision['blocking']);
    }

    // ============================================================
    // Regras de tom e bloqueio
    // ============================================================

    public function test_messages_are_short(): void
    {
        // Cabine de mensagens curtas: cada message ou question ≤ 120 chars.
        $cases = [
            ['text' => 'apaga tudo', 'risk' => VoxSchema::RISK_R3],
            ['text' => 'roda git push --force', 'risk' => VoxSchema::RISK_R4],
            ['text' => 'olha isso', 'risk' => VoxSchema::RISK_R1],
            ['text' => 'manda pro codex', 'risk' => VoxSchema::RISK_R0],
            ['text' => 'edita o config', 'risk' => VoxSchema::RISK_R2],
        ];
        foreach ($cases as $c) {
            $decision = $this->policy()->evaluate(
                transcript: ['text' => $c['text']],
                intentPacket: $this->intentPacket([
                    'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                    'risk_class' => $c['risk'],
                    'goal' => '',
                    'constraints' => [],
                    'compiled_prompt' => '',
                ]),
            );
            $this->assertLessThanOrEqual(120, mb_strlen($decision['message_pt_br']), "msg longa em '{$c['text']}'");
            $this->assertLessThanOrEqual(120, mb_strlen($decision['question_pt_br']), "question longa em '{$c['text']}'");
        }
    }

    public function test_one_question_per_decision(): void
    {
        $cases = [
            ['text' => 'olha isso pra mim', 'risk' => VoxSchema::RISK_R1],
            ['text' => 'rm -rf cache', 'risk' => VoxSchema::RISK_R4],
            ['text' => 'manda pro codex', 'risk' => VoxSchema::RISK_R0],
            ['text' => 'executa no terminal', 'risk' => VoxSchema::RISK_R1],
            ['text' => 'git reset --hard', 'risk' => VoxSchema::RISK_R3],
        ];
        foreach ($cases as $c) {
            $decision = $this->policy()->evaluate(
                transcript: ['text' => $c['text']],
                intentPacket: $this->intentPacket([
                    'mode' => VoxSchema::MODE_INTENT_COMPILE,
                    'risk_class' => $c['risk'],
                    'goal' => '',
                    'constraints' => [],
                    'compiled_prompt' => '',
                ]),
            );
            $marks = substr_count($decision['question_pt_br'], '?');
            $this->assertLessThanOrEqual(1, $marks, "multi-pergunta em '{$c['text']}'");
        }
    }

    public function test_no_paternalistic_phrases(): void
    {
        // Evitar palavras/frases paternalistas explícitas. Caso o policy
        // mude texto no futuro, o teste pega regressão de tom.
        $forbidden = [
            'você está errado',
            'tem certeza absoluta',
            'isso é perigoso',
            'não faça isso',
            'pare',
            'cuidado!',
            'atenção!!',
        ];
        $cases = [
            'manda um rm -rf no cache',
            'roda git push --force main',
            'olha isso pra mim',
            'manda pro codex',
            'edita o config local',
        ];
        foreach ($cases as $text) {
            $decision = $this->policy()->evaluate(
                transcript: ['text' => $text],
                intentPacket: $this->intentPacket([
                    'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                    'risk_class' => VoxSchema::RISK_R3,
                    'goal' => '',
                    'constraints' => [],
                ]),
            );
            $haystack = mb_strtolower(' '.$decision['message_pt_br'].' '.$decision['question_pt_br'].' ');
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    mb_strtolower($needle),
                    $haystack,
                    "tom paternalista vazou: '{$needle}' em '{$text}'",
                );
            }
        }
    }

    public function test_blocking_only_for_destructive_never_for_opinion(): void
    {
        // SUGGEST, CLARIFY e CAUTION jamais bloqueiam.
        $cases = [
            ['intervention' => VoxInterlocutorPolicy::INTERVENTION_SUGGEST_BETTER_PROMPT, 'text' => 'manda pro codex', 'mode' => VoxSchema::MODE_INTENT_COMPILE, 'risk' => VoxSchema::RISK_R0],
            ['intervention' => VoxInterlocutorPolicy::INTERVENTION_CLARIFY, 'text' => 'usa aquela pasta lá', 'mode' => VoxSchema::MODE_GOVERNED_EXECUTE, 'risk' => VoxSchema::RISK_R1],
            ['intervention' => VoxInterlocutorPolicy::INTERVENTION_CAUTION, 'text' => 'edita o config local', 'mode' => VoxSchema::MODE_GOVERNED_EXECUTE, 'risk' => VoxSchema::RISK_R2],
        ];
        foreach ($cases as $c) {
            $decision = $this->policy()->evaluate(
                transcript: ['text' => $c['text']],
                intentPacket: $this->intentPacket([
                    'mode' => $c['mode'],
                    'risk_class' => $c['risk'],
                    'goal' => '',
                    'constraints' => [],
                    'compiled_prompt' => '',
                ]),
            );
            $this->assertSame($c['intervention'], $decision['intervention'], "input: {$c['text']}");
            $this->assertFalse($decision['blocking'], "input: {$c['text']}");
        }
    }

    public function test_no_english_leak_in_messages(): void
    {
        $englishLeaks = [
            ' are you ',
            ' you should ',
            ' please ',
            ' execute ',
            ' destructive ',
            ' force ',
            ' clarify ',
            ' caution ',
            ' warning ',
            ' confirm action ',
            ' sorry ',
        ];
        $cases = [
            ['text' => 'roda isso aqui', 'risk' => VoxSchema::RISK_R1, 'mode' => VoxSchema::MODE_GOVERNED_EXECUTE],
            ['text' => 'roda git push --force no main', 'risk' => VoxSchema::RISK_R4, 'mode' => VoxSchema::MODE_GOVERNED_EXECUTE],
            ['text' => 'faz git reset --hard', 'risk' => VoxSchema::RISK_R3, 'mode' => VoxSchema::MODE_GOVERNED_EXECUTE],
            ['text' => 'manda pro codex', 'risk' => VoxSchema::RISK_R0, 'mode' => VoxSchema::MODE_INTENT_COMPILE],
            ['text' => 'edita o config', 'risk' => VoxSchema::RISK_R2, 'mode' => VoxSchema::MODE_GOVERNED_EXECUTE],
            ['text' => 'apaga tudo', 'risk' => VoxSchema::RISK_R3, 'mode' => VoxSchema::MODE_GOVERNED_EXECUTE],
            ['text' => 'rm -rf da pasta', 'risk' => VoxSchema::RISK_R4, 'mode' => VoxSchema::MODE_GOVERNED_EXECUTE],
            ['text' => 'executa no terminal', 'risk' => VoxSchema::RISK_R1, 'mode' => VoxSchema::MODE_GOVERNED_EXECUTE],
        ];

        foreach ($cases as $c) {
            $decision = $this->policy()->evaluate(
                transcript: ['text' => $c['text']],
                intentPacket: $this->intentPacket([
                    'mode' => $c['mode'],
                    'risk_class' => $c['risk'],
                    'goal' => '',
                    'constraints' => [],
                    'compiled_prompt' => '',
                ]),
            );
            $haystack = mb_strtolower(' '.$decision['message_pt_br'].' '.$decision['question_pt_br'].' ');
            foreach ($englishLeaks as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $haystack,
                    "inglês vazou em '{$c['text']}': '{$needle}'",
                );
            }
        }
    }

    public function test_question_for_clarify_offers_concrete_choices(): void
    {
        // A pergunta de clarify precisa virar uma escolha concreta, não
        // pergunta abstrata. Esse teste pega regressão de "?" sozinho.
        $decision = $this->policy()->evaluate(
            transcript: ['text' => 'manda o Codex olhar aquele arquivo'],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_INTENT_COMPILE,
                'risk_class' => VoxSchema::RISK_R1,
                'goal' => '',
                'constraints' => [],
                'compiled_prompt' => '',
                'context_refs' => [['kind' => 'none', 'ref' => null, 'resolved' => true]],
            ]),
        );

        $this->assertSame(VoxInterlocutorPolicy::INTERVENTION_CLARIFY, $decision['intervention']);
        $this->assertStringContainsString('arquivo', $decision['question_pt_br']);
        $this->assertStringContainsString(',', $decision['question_pt_br'], 'pergunta deveria oferecer ≥2 opções');
    }

    public function test_disagree_message_uses_safer_path_phrasing(): void
    {
        $decision = $this->policy()->evaluate(
            transcript: ['text' => 'roda rm -rf no diretório'],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_GOVERNED_EXECUTE,
                'risk_class' => VoxSchema::RISK_R3,
                'goal' => '',
                'constraints' => [],
            ]),
        );

        $this->assertSame(VoxInterlocutorPolicy::INTERVENTION_DISAGREE, $decision['intervention']);
        $this->assertTrue($decision['blocking']);
        $this->assertStringContainsString('eu faria diferente', mb_strtolower($decision['message_pt_br']));
        $this->assertIsArray($decision['suggested_edit']);
        $this->assertArrayHasKey('safer_path', $decision['suggested_edit']);
    }

    // ============================================================
    // Regressões V4 — non-regression
    // ============================================================

    public function test_v4_dictation_with_simple_text_returns_none(): void
    {
        // Dictation simples nunca deve disparar nada (V4 segue intacto).
        $decision = $this->policy()->evaluate(
            transcript: ['text' => 'anota aqui: comprar pão amanhã'],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_DICTATION,
                'risk_class' => VoxSchema::RISK_R0,
                'goal' => '',
                'constraints' => [],
                'compiled_prompt' => null,
            ]),
        );

        $this->assertSame(VoxInterlocutorPolicy::INTERVENTION_NONE, $decision['intervention']);
    }

    public function test_v4_prompt_polish_does_not_trigger_suggest_better_prompt(): void
    {
        // prompt_polish é V1, NÃO faz sentido sugerir prompt melhor — só
        // intent_compile recebe esse tratamento. Garante separação de
        // modos preservada V4.
        $decision = $this->policy()->evaluate(
            transcript: ['text' => 'melhora isso'],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_PROMPT_POLISH,
                'goal' => '',
                'constraints' => [],
                'compiled_prompt' => 'curto',
                'risk_class' => VoxSchema::RISK_R0,
            ]),
        );

        $this->assertNotSame(VoxInterlocutorPolicy::INTERVENTION_SUGGEST_BETTER_PROMPT, $decision['intervention']);
    }

    public function test_v4_unknown_mode_returns_none(): void
    {
        // Modo desconhecido (compat futura): nunca intervém.
        $decision = $this->policy()->evaluate(
            transcript: ['text' => 'qualquer texto seguro'],
            intentPacket: $this->intentPacket([
                'mode' => 'future_unknown_mode',
                'risk_class' => VoxSchema::RISK_R0,
                'goal' => '',
                'constraints' => [],
                'compiled_prompt' => str_repeat('seguro. ', 6),
            ]),
        );

        $this->assertSame(VoxInterlocutorPolicy::INTERVENTION_NONE, $decision['intervention']);
    }

    public function test_v4_empty_transcript_returns_none(): void
    {
        $decision = $this->policy()->evaluate(
            transcript: ['text' => ''],
            intentPacket: $this->intentPacket([
                'mode' => VoxSchema::MODE_DICTATION,
                'risk_class' => VoxSchema::RISK_R0,
                'goal' => '',
                'constraints' => [],
                'compiled_prompt' => null,
            ]),
        );

        $this->assertSame(VoxInterlocutorPolicy::INTERVENTION_NONE, $decision['intervention']);
    }
}
