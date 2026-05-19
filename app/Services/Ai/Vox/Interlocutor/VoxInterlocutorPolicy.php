<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Interlocutor;

use App\Services\Ai\Vox\VoxSchema;

/**
 * Atlas Vox V5 — Symbiotic Interlocutor (deterministic conversational layer).
 *
 * Recebe transcript + intent packet parcial + (opcional) decisão do
 * AutoModeRouter e devolve UMA decisão conversacional curta em PT-BR. NÃO
 * executa nada. NÃO chama provider. NÃO substitui o Kernel — apenas pergunta,
 * adverte, discorda ou sugere melhor estrutura antes da execução.
 *
 * V5-C · calibração de tom e thresholds (2026-05-19):
 *
 *   - Markers destrutivos divididos em HARD vs SOFT:
 *       HARD  → disagree blocking SEMPRE (rm -rf, drop database, curl|sh,
 *               apagar tudo, mkfs, dd if=, deletar projeto).
 *       SOFT  → R4 disagree blocking · R2/R3 disagree não bloqueante ·
 *               R0/R1 caution (não chateia em risco baixo).
 *               (sudo, git push --force, git reset --hard, deploy)
 *   - Ambiguous reference ampliado: pega "manda/roda/olha/vê/abre/usa
 *     isso/essa/esse/aquilo/aqui" sem alvo concreto, "esse bug" sozinho,
 *     "executa no terminal" sem comando, "olha aqui", "veja aqui".
 *   - Weak prompt mais conservador: só dispara em fala MUITO curta sem
 *     objetivo extraído E sem identificador concreto na fala (CamelCase,
 *     snake_case, paths, módulos). "cria um prompt perfeito pro Codex
 *     resolver o bug" NÃO é fraco — tem verbo+alvo+contexto.
 *   - Mensagens curtas: ≤ 80 chars cada. Uma pergunta por vez. Sem
 *     "tem certeza absoluta?" (paternalista); preferir "Confirma seguir?".
 *
 * Hard contract (Leis 0 / 0.5 / 0.75 / 0.9):
 *   - Determinístico. Sem rede, sem LLM, sem random, sem clock state.
 *   - PT-BR estrito. Sem mistura inglês/português.
 *   - Tom: útil, direto, sem julgamento emocional ("Eu faria diferente por
 *     segurança…", nunca "você está errado").
 *   - blocking=true SOMENTE em risco/política dura (HARD markers OU SOFT
 *     marker + risk_class=R4). Bloqueio nunca é opinião.
 *   - Nunca substitui a confirmação humana do Kernel V3 (R2/R3/R4 continuam
 *     exigindo /ai/vox/execute com confirmation_token).
 *
 * Output schema canônico: {@see VoxSchema::INTERLOCUTOR_DECISION}.
 */
final class VoxInterlocutorPolicy
{
    public const SCHEMA = VoxSchema::INTERLOCUTOR_DECISION;
    public const VERSION = VoxSchema::INTERLOCUTOR_VERSION;

    public const INTERVENTION_NONE = 'none';
    public const INTERVENTION_CLARIFY = 'clarify';
    public const INTERVENTION_CAUTION = 'caution';
    public const INTERVENTION_DISAGREE = 'disagree';
    public const INTERVENTION_SUGGEST_BETTER_PROMPT = 'suggest_better_prompt';

    /** reason_code canônicos. Tudo fora dessa lista é normalizado para 'none'. */
    public const REASON_AMBIGUOUS_REFERENCE = 'ambiguous_reference';
    public const REASON_DESTRUCTIVE_RISK = 'destructive_risk';
    public const REASON_MISSING_CONTEXT = 'missing_context';
    public const REASON_WEAK_PROMPT = 'weak_prompt';
    public const REASON_SAFER_PATH_AVAILABLE = 'safer_path_available';
    public const REASON_NONE = 'none';

    /**
     * HARD policy markers — disagree blocking SEMPRE, independente de
     * risk_class. São ações irreversíveis ou de altíssimo impacto: a UI
     * sempre desabilita Confirmar até o operador trocar de modo ou cancelar.
     *
     * @var list<array{key: string, label: string, pattern: string, safer_path: string}>
     */
    private const HARD_POLICY_MARKERS = [
        ['key' => 'rm_rf',           'label' => 'rm -rf',           'pattern' => '/\brm\s+-rf\b/iu',           'safer_path' => 'mover para pasta de quarentena antes de apagar'],
        // V6-FPG-C · pattern tolera transcrição falada sem o sinal "=":
        // "dd if=/dev/zero" e "dd if dev zero" caem ambos no marker.
        ['key' => 'dd_if',           'label' => 'dd if',            'pattern' => '/\bdd\s+if(?:=|\s)/iu',          'safer_path' => 'confirmar o destino em "of=" — dd é irreversível'],
        ['key' => 'mkfs',            'label' => 'mkfs',             'pattern' => '/\bmkfs\b/iu',                'safer_path' => 'verificar partição montada antes — mkfs apaga'],
        ['key' => 'drop_database',   'label' => 'drop database',    'pattern' => '/\bdrop\s+database\b/iu',     'safer_path' => 'dump completo e confirmar ambiente antes do drop'],
        ['key' => 'truncate_table',  'label' => 'truncate',         'pattern' => '/\btruncate(?:\s+table)?\b/iu','safer_path' => 'SELECT count(*) antes; em prod, soft-delete'],
        ['key' => 'curl_pipe_shell', 'label' => 'curl | shell',     'pattern' => '/\bcurl\b[^\n]*\|\s*(?:sh|bash|zsh)\b/iu', 'safer_path' => 'baixar o script e inspecionar antes de rodar'],
        ['key' => 'wget_pipe_shell', 'label' => 'wget | shell',     'pattern' => '/\bwget\b[^\n]*\|\s*(?:sh|bash|zsh)\b/iu', 'safer_path' => 'baixar o script e inspecionar antes de rodar'],
        ['key' => 'apagar_tudo',     'label' => 'apagar tudo',      'pattern' => '/\bapag(?:a|ar)\s+tudo\b/iu', 'safer_path' => 'apagar por categoria, revisando a lista'],
        ['key' => 'deletar_tudo',    'label' => 'deletar tudo',     'pattern' => '/\bdelet(?:a|ar)\s+tudo\b/iu','safer_path' => 'deletar por categoria, revisando a lista'],
        ['key' => 'deletar_projeto', 'label' => 'deletar projeto',  'pattern' => '/\bdelet(?:a|ar)\s+(?:o\s+)?projeto\b/iu', 'safer_path' => 'arquivar primeiro; deletar só após backup'],
    ];

    /**
     * SOFT policy markers — calibrado por risk_class:
     *   R4         → disagree blocking
     *   R2/R3      → disagree NÃO bloqueante (operador decide)
     *   R0/R1      → caution leve (não chateia em risco baixo)
     *
     * @var list<array{key: string, label: string, pattern: string, safer_path: string}>
     */
    private const SOFT_POLICY_MARKERS = [
        ['key' => 'git_push_force',  'label' => 'git push --force', 'pattern' => '/\bgit\s+push\s+(?:--force|-f)\b/iu', 'safer_path' => 'usar --force-with-lease ou abrir nova branch'],
        ['key' => 'force_push',      'label' => 'force push',       'pattern' => '/\bforce[\s\-]push\b/iu',     'safer_path' => 'usar --force-with-lease pra não sobrescrever trabalho'],
        ['key' => 'git_reset_hard',  'label' => 'git reset --hard', 'pattern' => '/\bgit\s+reset\s+--hard\b/iu','safer_path' => 'stash ou branch de backup antes do reset'],
        ['key' => 'sudo',            'label' => 'sudo',             'pattern' => '/\bsudo\s+(?:rm|chmod|chown|mv|cp|dd|mkfs|kill|kill-9|killall|reboot|shutdown|systemctl)\b/iu', 'safer_path' => 'rodar sem sudo primeiro pra entender o erro real'],
        ['key' => 'deploy',          'label' => 'deploy',           'pattern' => '/\b(?:fazer\s+)?deploy\b/iu', 'safer_path' => 'subir staging primeiro e rodar smoke tests'],
    ];

    /**
     * Marcadores de referência ambígua. Em PT-BR conversacional o operador
     * solta "manda isso", "olha isso", "roda isso" o tempo todo — quando
     * NÃO há contexto resolvido no intent packet, isso vira clarify ("qual
     * alvo?"). A label devolvida vira a frase citada na pergunta.
     *
     * @var list<array{pattern: string, label: string}>
     */
    private const AMBIGUOUS_REFERENCE_MARKERS = [
        // demonstrativos puros logo após verbo de ação
        ['pattern' => '/\b(?:manda|mande|olha|olhe|ve|veja|ver|olhar|roda|rode|rodar|executa|execute|executar|pega|pegue|usa|use|abre|abra|fecha|feche|aplica|aplique|investiga|investigue|investigar|analisa|analise|analisar)\s+(?:isso|essa|esse|aquilo|aquele|aquela)(?:\s|$|[.,;])/u', 'label' => 'isso'],

        // IA externa + verbo + demonstrativo: "manda o Codex olhar isso"
        ['pattern' => '/\b(?:codex|claude|gpt|chatgpt|ia)\b[^\n]{0,30}\b(?:olhar|olha|olhe|ver|veja|investigar|investigue|investiga|analisar|analise|analisa|resolver|resolva|resolve)\s+(?:isso|essa|esse|aquilo|aquele|aquela)(?:\s|$|[.,;])/u', 'label' => 'isso'],

        // demonstrativo + "aqui/aí"
        ['pattern' => '/\b(?:isso|essa|esse|olha|olhe|veja|ve|ver)\s+aqui(?:\s|$|[.,;])/u', 'label' => 'aqui'],
        ['pattern' => '/\b(?:isso|essa|esse|olha|olhe|veja|ve|ver)\s+a[ií](?:\s|$|[.,;])/u', 'label' => 'aí'],

        // "aquele arquivo / aquela pasta / aquele lugar"
        ['pattern' => '/\baquele\s+arquivo\b/u',                                   'label' => 'aquele arquivo'],
        ['pattern' => '/\baquela\s+pasta\b/u',                                     'label' => 'aquela pasta'],
        ['pattern' => '/\b(?:naquele|naquela)\s+(?:projeto|arquivo|repo|repositorio|repositório|teste|módulo|modulo|lugar|sitio|sítio)\b/u', 'label' => 'naquele'],

        // verbo + lá (sem ponto de referência)
        ['pattern' => '/\b(?:roda|rode|rodar|executa|execute|executar|usa|use|aplica|aplique)\s+l[áa](?:\s|$|[.,;])/u', 'label' => 'lá'],
        ['pattern' => '/\bl[áa]\s+(?:no|na|nesse|nessa|naquele|naquela)\b/u',      'label' => 'lá'],

        // "essa coisa" / "nosso projeto" (sem identificar)
        ['pattern' => '/\b(?:essa|esse)\s+coisa\b/u',                              'label' => 'essa coisa'],
        ['pattern' => '/\b(?:nosso|nossa)\s+(?:projeto|repo|repositorio|repositório|teste)\b/u', 'label' => 'nosso projeto'],

        // "esse bug" / "essa issue" sem ID/path
        ['pattern' => '/\b(?:esse|essa)\s+(?:bug|issue|erro|problema|ticket|chamado)(?:\s|$|[.,;])/u', 'label' => 'esse bug'],
    ];

    /**
     * "Executa no terminal" / "roda no terminal" sem comando concreto após.
     * Disparado quando o operador diz só "executa/roda no terminal" sem
     * verbo seguinte de ação (ex.: "os testes", "esse comando", "phpunit").
     */
    private const TERMINAL_BARE_PATTERN =
        '/\b(?:executa|execute|executar|roda|rode|rodar)\s+(?:no|do|esse|esse\s+meu)?\s*terminal\b(?:[\s.,;]|$)(?!\s*(?:os?\s+(?:teste|comando|script|build)|um\s+|uma\s+|esse\s+comando|aquilo|com\s+|seguinte|isso\s+aqui))/u';

    /**
     * Identifier-like tokens que sinalizam intenção concreta. Quando a fala
     * contém um destes, NÃO chamamos suggest_better_prompt — Vitor já deu
     * um alvo concreto pro Codex/Claude.
     */
    private const IDENTIFIER_PATTERNS = [
        '/[A-Z][a-z]+[A-Z][a-zA-Z]+/u',   // CamelCase (AuthController, UserModel)
        '/\b[a-z]+_[a-z]+/u',              // snake_case (auth_service, run_tests)
        '/\b[\/a-z]+\.(?:php|ts|tsx|js|jsx|rs|sql|json|yaml|yml|md|css)\b/iu', // arquivos
        '/\b\/[a-z][\w\/-]+/iu',           // paths
        '/\b(?:endpoint|rota|controller|service|model|component|hook|migration|seed|test|spec)\s+[\w\/.-]+/iu', // tipo + nome
    ];

    /**
     * Verbos de ação concreta + objeto direto identificável. Tudo isto vira
     * sinal de "prompt forte o suficiente" — bloqueia suggest_better_prompt
     * mesmo quando o extractor não preencheu goal/constraints.
     */
    private const CLEAR_ACTION_INTENT_PATTERNS = [
        '/\b(?:resolver|resolva|resolve|consertar|conserta|corrige|corrigir|arrumar|arruma)\s+(?:o|a|os|as|um|uma|esse|essa)\s+\w{3,}/iu',
        '/\b(?:implementar|implementa|implemente|criar|cria|crie|adicionar|adiciona|adicione)\s+(?:o|a|os|as|um|uma)\s+\w{3,}\s+\w{2,}/iu',
        '/\b(?:investigar|investiga|investigue|analisar|analisa|analise|estudar|estuda|estude)\s+(?:o|a|os|as|esse|essa)\s+\w{3,}/iu',
        '/\b(?:refatorar|refatora|refatore|otimizar|otimiza|otimize|migrar|migra|migre)\s+(?:o|a|os|as|esse|essa)\s+\w{3,}/iu',
        '/\b(?:escrever|escreve|escreva|gerar|gera|gere|documentar|documenta|documente)\s+(?:o|a|os|as|um|uma)\s+\w{3,}/iu',
    ];

    /**
     * Entry point.
     *
     * @param  array<string,mixed>  $transcript        VoxTranscript-like (precisa de `text`).
     * @param  array<string,mixed>  $intentPacket      Parcial OK; só leitura.
     * @param  array<string,mixed>|null  $autoModeDecision atlas.vox.auto_mode_decision.v1 (opcional).
     * @param  array<string,mixed>  $context           Reservado p/ contexto (active surface etc).
     * @return array{
     *   schema: string,
     *   intervention: string,
     *   message_pt_br: string,
     *   question_pt_br: string,
     *   blocking: bool,
     *   reason_code: string,
     *   suggested_edit: array<string,mixed>|null,
     *   policy_version: string,
     *   markers: array<string,mixed>
     * }
     */
    public function evaluate(
        array $transcript,
        array $intentPacket,
        ?array $autoModeDecision = null,
        array $context = [],
    ): array {
        $rawText = (string) ($transcript['text'] ?? '');
        $normalised = $this->normalise($rawText);
        $mode = (string) ($intentPacket['mode'] ?? VoxSchema::MODE_DICTATION);
        $risk = (string) ($intentPacket['risk_class'] ?? VoxSchema::RISK_R0);
        $goal = (string) ($intentPacket['goal'] ?? '');
        $constraints = (array) ($intentPacket['constraints'] ?? []);
        $compiledPrompt = (string) ($intentPacket['compiled_prompt'] ?? '');
        $contextRefs = (array) ($intentPacket['context_refs'] ?? []);
        $hasResolvedContext = $this->hasResolvedContext($contextRefs);
        $tokenCount = $this->countTokens($normalised);
        unset($autoModeDecision, $context); // reservados para V5-B; não afetam decisão hoje

        // 1) HARD policy markers → disagree blocking SEMPRE.
        //    Política dura: nunca pendurar bloqueio em opinião, mas estes
        //    são marcadores de ação irreversível catalogados — bloqueio é
        //    o caminho seguro até o operador escolher trocar de modo.
        $hard = $this->detectMarker($normalised, $rawText, self::HARD_POLICY_MARKERS);
        if ($hard !== null) {
            return $this->makeDecision(
                intervention: self::INTERVENTION_DISAGREE,
                message: 'Eu faria diferente por segurança: '.$hard['safer_path'].'.',
                question: 'Confirma seguir mesmo assim?',
                blocking: true,
                reasonCode: self::REASON_DESTRUCTIVE_RISK,
                suggestedEdit: [
                    'safer_path' => $hard['safer_path'],
                    'destructive_marker' => $hard['key'],
                    'policy_tier' => 'hard',
                ],
                markers: [
                    'destructive_marker' => $hard['key'],
                    'policy_tier' => 'hard',
                ],
            );
        }

        // 2) SOFT policy markers → calibrado por risco.
        //    R4 ⇒ disagree blocking; R2/R3 ⇒ disagree NÃO bloqueante;
        //    R0/R1 ⇒ caution leve (não chateia em risco baixo).
        $soft = $this->detectMarker($normalised, $rawText, self::SOFT_POLICY_MARKERS);
        if ($soft !== null) {
            if ($risk === VoxSchema::RISK_R4) {
                return $this->makeDecision(
                    intervention: self::INTERVENTION_DISAGREE,
                    message: 'Eu faria diferente por segurança: '.$soft['safer_path'].'.',
                    question: 'Confirma seguir mesmo assim?',
                    blocking: true,
                    reasonCode: self::REASON_DESTRUCTIVE_RISK,
                    suggestedEdit: [
                        'safer_path' => $soft['safer_path'],
                        'destructive_marker' => $soft['key'],
                        'policy_tier' => 'soft_r4',
                    ],
                    markers: [
                        'destructive_marker' => $soft['key'],
                        'policy_tier' => 'soft_r4',
                    ],
                );
            }
            if ($risk === VoxSchema::RISK_R2 || $risk === VoxSchema::RISK_R3) {
                return $this->makeDecision(
                    intervention: self::INTERVENTION_DISAGREE,
                    message: 'Eu faria diferente: '.$soft['safer_path'].'.',
                    question: 'Sigo assim ou prefere a versão mais segura?',
                    blocking: false,
                    reasonCode: self::REASON_SAFER_PATH_AVAILABLE,
                    suggestedEdit: [
                        'safer_path' => $soft['safer_path'],
                        'destructive_marker' => $soft['key'],
                        'policy_tier' => 'soft_mid',
                    ],
                    markers: [
                        'destructive_marker' => $soft['key'],
                        'policy_tier' => 'soft_mid',
                    ],
                );
            }
            // R0/R1: caution leve em vez de discordância — evita chatear quando
            // a fala só menciona o termo sem efeito real (ex.: descrevendo
            // histórico, anotando referência).
            return $this->makeDecision(
                intervention: self::INTERVENTION_CAUTION,
                message: 'Vi "'.$soft['label'].'" na fala. Vale revisar antes de seguir.',
                question: '',
                blocking: false,
                reasonCode: self::REASON_DESTRUCTIVE_RISK,
                suggestedEdit: null,
                markers: [
                    'destructive_marker' => $soft['key'],
                    'policy_tier' => 'soft_low',
                ],
            );
        }

        // 3) Terminal sem comando → clarify (uma pergunta concreta).
        if ($this->isTerminalBare($normalised)) {
            return $this->makeDecision(
                intervention: self::INTERVENTION_CLARIFY,
                message: 'Preciso saber o comando antes de seguir.',
                question: 'Qual comando devo rodar?',
                blocking: false,
                reasonCode: self::REASON_MISSING_CONTEXT,
                suggestedEdit: null,
                markers: ['missing_target' => 'terminal_command'],
            );
        }

        // 4) Referência ambígua sem contexto resolvido → clarify.
        //    Quando o overlay já anexou contexto resolvido (workspace,
        //    selection, surface), NÃO chateia.
        $ambiguous = $this->detectAmbiguousReference($normalised);
        if ($ambiguous !== null && ! $hasResolvedContext) {
            return $this->makeDecision(
                intervention: self::INTERVENTION_CLARIFY,
                message: 'Preciso de um detalhe antes de seguir.',
                question: 'Você quis dizer '.$this->clarifyChoices($ambiguous).'?',
                blocking: false,
                reasonCode: self::REASON_AMBIGUOUS_REFERENCE,
                suggestedEdit: null,
                markers: ['ambiguous_marker' => $ambiguous],
            );
        }

        // 5) intent_compile com prompt fraco E sem sinal de intenção clara
        //    → sugerir estrutura melhor (não bloqueante).
        if ($mode === VoxSchema::MODE_INTENT_COMPILE
            && $this->isWeakPrompt($normalised, $tokenCount, $goal, $constraints, $compiledPrompt)
        ) {
            return $this->makeDecision(
                intervention: self::INTERVENTION_SUGGEST_BETTER_PROMPT,
                message: 'Posso transformar isso num prompt mais forte antes de enviar.',
                question: 'Estruturo melhor ou sigo com a versão atual?',
                blocking: false,
                reasonCode: self::REASON_WEAK_PROMPT,
                suggestedEdit: [
                    'add_sections' => ['objetivo', 'contexto', 'restrições', 'critério de aceite'],
                    'goal_hint' => 'Verbo + alvo (ex.: investigar AuthController).',
                    'constraints_hint' => 'O que NÃO mexer e o que DEVE preservar.',
                    'acceptance_hint' => 'Como validar (build verde, teste passando).',
                ],
                markers: [
                    'token_count' => $tokenCount,
                    'has_goal' => $goal !== '',
                    'constraints_count' => count(array_filter($constraints)),
                ],
            );
        }

        // 6) R2/R3 sozinhos (sem marker destrutivo, sem ambíguo) → caution
        //    leve. Curto, sem pergunta — só uma notinha pra revisar.
        if (in_array($risk, [VoxSchema::RISK_R2, VoxSchema::RISK_R3], true)) {
            $message = $risk === VoxSchema::RISK_R3
                ? 'Risco moderado — ação externa contida. Revisa o alvo antes de seguir.'
                : 'Risco médio — alteração local reversível. Confere o caminho.';

            return $this->makeDecision(
                intervention: self::INTERVENTION_CAUTION,
                message: $message,
                question: '',
                blocking: false,
                reasonCode: self::REASON_DESTRUCTIVE_RISK,
                suggestedEdit: null,
                markers: ['risk_class' => $risk],
            );
        }

        // 7) Default: nada a dizer, segue o fluxo automático.
        return $this->makeDecision(
            intervention: self::INTERVENTION_NONE,
            message: '',
            question: '',
            blocking: false,
            reasonCode: self::REASON_NONE,
            suggestedEdit: null,
            markers: [],
        );
    }

    private function normalise(string $text): string
    {
        $t = mb_strtolower($text);
        $t = (string) preg_replace('/\s+/u', ' ', $t);

        return trim($t);
    }

    /**
     * @param  list<array{key: string, label: string, pattern: string, safer_path: string}>  $markers
     * @return array{key: string, label: string, safer_path: string}|null
     */
    private function detectMarker(string $normalised, string $raw, array $markers): ?array
    {
        $haystack = $normalised."\n".$raw;
        foreach ($markers as $entry) {
            if (preg_match($entry['pattern'], $haystack) === 1) {
                return [
                    'key' => $entry['key'],
                    'label' => $entry['label'],
                    'safer_path' => $entry['safer_path'],
                ];
            }
        }

        return null;
    }

    private function detectAmbiguousReference(string $normalised): ?string
    {
        foreach (self::AMBIGUOUS_REFERENCE_MARKERS as $entry) {
            if (preg_match($entry['pattern'], $normalised) === 1) {
                return $entry['label'];
            }
        }

        return null;
    }

    private function isTerminalBare(string $normalised): bool
    {
        return preg_match(self::TERMINAL_BARE_PATTERN, $normalised) === 1;
    }

    /**
     * Pergunta concreta humanizada por tipo de ambiguidade.
     */
    private function clarifyChoices(string $marker): string
    {
        return match ($marker) {
            'aquele arquivo' => 'o arquivo aberto, um arquivo específico, ou outro caminho',
            'aquela pasta' => 'a pasta atual, uma pasta específica, ou outra',
            'naquele' => 'o projeto atual ou outro repositório',
            'lá' => 'o terminal atual, outra janela ou um caminho específico',
            'essa coisa' => 'o trecho selecionado ou outro contexto',
            'nosso projeto' => 'o repositório atual ou outro',
            'esse bug' => 'um ticket específico, um erro recente, ou um arquivo',
            'aqui' => 'o arquivo aberto, a obra atual ou outro caminho',
            'aí' => 'o arquivo aberto, a obra atual ou outro caminho',
            'isso' => 'a obra atual, o arquivo aberto, ou outro caminho',
            default => 'a obra atual ou um caminho específico',
        };
    }

    /**
     * @param  array<int,array<string,mixed>>  $contextRefs
     */
    private function hasResolvedContext(array $contextRefs): bool
    {
        foreach ($contextRefs as $ref) {
            if (! is_array($ref)) {
                continue;
            }
            $kind = (string) ($ref['kind'] ?? 'none');
            $resolved = $ref['resolved'] ?? null;
            if ($kind !== 'none' && $resolved === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detecta um identificador concreto (CamelCase, snake_case, path, arquivo)
     * na fala original — sinal que o operador deu alvo real ao Codex/Claude.
     */
    private function hasConcreteIdentifier(string $rawText): bool
    {
        foreach (self::IDENTIFIER_PATTERNS as $pattern) {
            if (preg_match($pattern, $rawText) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detecta verbo de ação concreta + objeto direto identificável. Quando
     * a fala tem essa estrutura, NÃO chamamos suggest_better_prompt mesmo
     * que goal/constraints venham vazios do extractor.
     */
    private function hasClearActionIntent(string $normalised): bool
    {
        foreach (self::CLEAR_ACTION_INTENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalised) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * V5-C threshold calibrado · só vira "prompt fraco" se TUDO bate:
     *   - extractor não preencheu goal nem constraints
     *   - compiled_prompt é curto OU fala muito curta
     *   - fala NÃO contém identificador concreto (AuthController, /path/file.php)
     *   - fala NÃO contém verbo+objeto concreto ("resolver o bug")
     *
     * Em outras palavras: só intervém quando o Vitor falou "manda pro
     * codex" SEM contexto suficiente. "cria um prompt perfeito pro Codex
     * resolver esse bug" NÃO é fraco — tem verbo+alvo.
     *
     * @param  list<string>|array<int,mixed>  $constraints
     */
    private function isWeakPrompt(
        string $normalised,
        int $tokens,
        string $goal,
        array $constraints,
        string $compiledPrompt,
    ): bool {
        $trimmedGoal = trim($goal);
        $constraintsCount = count(array_filter(
            $constraints,
            static fn ($c) => is_string($c) && trim($c) !== '',
        ));
        if ($trimmedGoal !== '' || $constraintsCount > 0) {
            return false;
        }
        if ($this->hasConcreteIdentifier($normalised)) {
            return false;
        }
        if ($this->hasClearActionIntent($normalised)) {
            return false;
        }
        $compiledLength = mb_strlen(trim($compiledPrompt));
        // Fala MUITO curta sem nada concreto E compiled raso → fraco.
        if ($tokens <= 5 && $compiledLength < 60) {
            return true;
        }
        // Fala um pouco maior mas ainda sem nada concreto E compiled muito
        // raso (< 40 chars) → fraco. Threshold conservador a propósito —
        // melhor errar pra menos do que chatear o operador.
        if ($tokens <= 8 && $compiledLength < 40) {
            return true;
        }

        return false;
    }

    private function countTokens(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        $parts = preg_split('/\s+/u', $text) ?: [];

        return count(array_filter($parts, static fn ($p) => $p !== ''));
    }

    /**
     * @param  array<string,mixed>|null  $suggestedEdit
     * @param  array<string,mixed>  $markers
     * @return array{
     *   schema: string,
     *   intervention: string,
     *   message_pt_br: string,
     *   question_pt_br: string,
     *   blocking: bool,
     *   reason_code: string,
     *   suggested_edit: array<string,mixed>|null,
     *   policy_version: string,
     *   markers: array<string,mixed>
     * }
     */
    private function makeDecision(
        string $intervention,
        string $message,
        string $question,
        bool $blocking,
        string $reasonCode,
        ?array $suggestedEdit,
        array $markers,
    ): array {
        return [
            'schema' => self::SCHEMA,
            'intervention' => $intervention,
            'message_pt_br' => $message,
            'question_pt_br' => $question,
            'blocking' => $blocking,
            'reason_code' => $reasonCode,
            'suggested_edit' => $suggestedEdit,
            'policy_version' => self::VERSION,
            'markers' => $markers,
        ];
    }
}
