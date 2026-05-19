<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox;

/**
 * Deterministic intent extractor for Atlas Vox V2 (mode = intent_compile).
 *
 * Sibling of VoxPromptPolisher:
 *   - VoxPromptPolisher (V1): cleans a transcript so it reads well.
 *   - VoxIntentExtractor (V2): reads the cleaned transcript and answers
 *     "what does the operator actually want, and how risky is it?"
 *
 * Hard contract (Lei 0 / Lei 0.5 / Lei 0.75):
 *   - No network. No LLM. No provider call. No randomness.
 *   - Honest defaults: when the transcript is ambiguous we say `local` and
 *     `text`, never invent a provider or an output format.
 *   - Constraints from the operator's voice ("não mexer", "sem editar",
 *     "antes de implementar") are preserved verbatim. Losing one is a bug.
 *   - Hard-veto markers (rm -rf, sudo, git push --force, drop database,
 *     curl|sh, ...) are flagged so V3 confirmation policies can fire
 *     later; they do NOT block this V2 surface because nothing runs.
 *
 * The extractor is composed deliberately on top of VoxPromptPolisher so
 * V1 and V2 share filler/term/constraint heuristics — drift is impossible.
 */
final class VoxIntentExtractor
{
    /** Strong indicators that the operator wants a structured plan. */
    private const PLAN_TOKENS = [
        'plano', 'planeja', 'planejamento', 'planeje', 'estrutura', 'estruture',
        'arquitetura', 'roadmap', 'roteiro',
    ];

    /** Indicators that the operator wants code changes / a patch. */
    private const DIFF_TOKENS = [
        'diff', 'patch', 'implementa', 'implementar', 'implemente',
        'corrige no código', 'corrige no codigo', 'aplica', 'aplique',
        'codifica', 'codifique', 'escreve o código', 'escreva o código',
    ];

    /** Indicators that the operator wants an analysis, not changes. */
    private const DIAGNOSTIC_TOKENS = [
        'diagnóstico', 'diagnostico', 'diagnostica', 'analisa', 'analise',
        'investiga', 'investigue', 'investigar', 'review', 'revisar',
        'audita', 'auditar', 'entender por que', 'descobrir por que',
        'descobrir o porquê', 'identificar por que', 'identificar o porquê',
        'descobre por que', 'descobre o porquê',
        // V6-ES-C · pedidos read-only/só-olhar caem direto em diagnóstico
        // mesmo quando o operador também menciona um verbo de edição
        // (ex.: "olha o arquivo X mas só analisa, não edita").
        'só analisa', 'so analisa', 'só analise', 'so analise',
        'só leia', 'so leia', 'só ler', 'so ler', 'só lê', 'so le',
        'somente analisa', 'somente analise', 'somente leia',
        'apenas analisa', 'apenas analise', 'apenas leia',
        'só olha', 'so olha', 'só olhar', 'só dá uma olhada',
        'read-only', 'read only', 'modo leitura',
        'sem alterar', 'sem editar', 'sem mexer', 'não execute', 'nao execute',
    ];

    /** Indicators that the operator wants notes / inbox capture. */
    private const NOTES_TOKENS = [
        'nota', 'notas', 'resumo', 'inbox', 'captura', 'capturar',
        'salva em nota', 'salve em nota',
    ];

    /** Verbs that imply local edits on a file. */
    private const EDIT_VERBS = [
        'edita', 'editar', 'edite', 'altera', 'altere', 'alterar',
        'modifica', 'modifique', 'modificar', 'refatora', 'refatore',
        'refatorar', 'renomeia', 'renomeie', 'renomear', 'troca', 'troque',
        'substitui', 'substitua', 'substituir',
    ];

    /** Verbs that imply running a non-destructive command. */
    private const SHELL_VERBS = [
        'roda', 'rode', 'rodar', 'executa o teste', 'executar o teste',
        'executa os testes', 'executar os testes', 'roda os testes',
        'rode os testes', 'comando terminal', 'comando no terminal',
        'no terminal',
    ];

    /**
     * Hard-veto markers (literal substrings or regex tokens). Their mere
     * presence elevates the request to R4 so V3 can apply
     * stricter-than-default confirmation policies. V2 never executes;
     * these markers are surfaced as classification + telemetry.
     *
     * Order = priority for `risk_reasoning` (first match wins on tie).
     *
     * @var list<array{label: string, pattern: string}>
     */
    private const R4_HARD_VETO = [
        ['label' => 'rm_rf',              'pattern' => '/\brm\s+-rf\b/iu'],
        ['label' => 'sudo',               'pattern' => '/\bsudo\b/iu'],
        ['label' => 'dd_if',              'pattern' => '/\bdd\s+if=/iu'],
        ['label' => 'mkfs',               'pattern' => '/\bmkfs\b/iu'],
        ['label' => 'git_push_force',     'pattern' => '/\bgit\s+push\s+(?:--force|-f)\b/iu'],
        ['label' => 'git_reset_hard',     'pattern' => '/\bgit\s+reset\s+--hard\b/iu'],
        ['label' => 'drop_database',      'pattern' => '/\bdrop\s+database\b/iu'],
        ['label' => 'truncate_table',     'pattern' => '/\btruncate(?:\s+table)?\b/iu'],
        ['label' => 'curl_pipe_shell',    'pattern' => '/\bcurl\b[^\n]*\|\s*(?:sh|bash|zsh)\b/iu'],
        ['label' => 'wget_pipe_shell',    'pattern' => '/\bwget\b[^\n]*\|\s*(?:sh|bash|zsh)\b/iu'],
        ['label' => 'apagar_tudo',        'pattern' => '/\bapag(?:a|ar)\s+tudo\b/iu'],
        ['label' => 'deletar_projeto',    'pattern' => '/\bdelet(?:a|ar)\s+(?:o\s+)?projeto\b/iu'],
        ['label' => 'deploy',             'pattern' => '/\b(?:fazer\s+)?deploy\b/iu'],
        ['label' => 'force_push',         'pattern' => '/\bforce[\s\-]push\b/iu'],
    ];

    public function __construct(
        private readonly VoxPromptPolisher $polisher,
    ) {}

    /**
     * @param  array<string,mixed>  $transcript  Validated VoxTranscript payload.
     * @param  array<string,mixed>  $hints       Optional caller-supplied hints
     *                                           (provider_hint, output_format,
     *                                           context_refs).
     * @return array{
     *   goal: string,
     *   constraints: list<string>,
     *   provider_hint: string,
     *   provider_hint_source: string,
     *   executor_hint: string,
     *   output_format: string,
     *   output_format_source: string,
     *   context_refs: list<array{kind: string, ref: ?string, resolved: bool}>,
     *   risk_class: string,
     *   risk_reasoning: string,
     *   risk_markers: list<string>,
     *   normalised_text: string,
     *   polisher_transformations: list<string>
     * }
     */
    public function extract(array $transcript, array $hints = []): array
    {
        $rawText = (string) ($transcript['text'] ?? '');
        $polish = $this->polisher->polish($rawText);

        $normalised = $polish['compiled_prompt'];
        $polisherProvider = $polish['provider_hint'];
        $constraints = $polish['constraints'];
        $goal = $this->refineGoal($polish['goal'], $rawText, $polisherProvider);

        [$providerHint, $providerSource] = $this->resolveProvider(
            polisherHint: $polisherProvider,
            hintFromRequest: $hints['provider_hint'] ?? null,
        );

        [$outputFormat, $outputSource] = $this->resolveOutputFormat(
            text: $normalised,
            hintFromRequest: $hints['output_format'] ?? null,
        );

        $executorHint = $this->detectExecutorHint($normalised, $outputFormat);

        $contextRefs = $this->normaliseContextRefs($hints['context_refs'] ?? null);

        $risk = $this->classifyRisk(
            text: $normalised,
            rawText: $rawText,
            outputFormat: $outputFormat,
            constraints: $constraints,
            executorHint: $executorHint,
        );

        return [
            'goal' => $goal,
            'constraints' => $constraints,
            'provider_hint' => $providerHint,
            'provider_hint_source' => $providerSource,
            'executor_hint' => $executorHint,
            'output_format' => $outputFormat,
            'output_format_source' => $outputSource,
            'context_refs' => $contextRefs,
            'risk_class' => $risk['risk_class'],
            'risk_reasoning' => $risk['risk_reasoning'],
            'risk_markers' => $risk['risk_markers'],
            'normalised_text' => $normalised,
            'polisher_transformations' => $polish['transformations_applied'],
        ];
    }

    /**
     * If the polisher couldn't extract a goal (long sentence, etc.), try
     * a shorter synthesised one. Never invents intent — only restates what
     * the operator said in a punchier form.
     */
    private function refineGoal(string $polisherGoal, string $rawText, string $providerHint): string
    {
        $goal = trim($polisherGoal);
        if ($goal !== '') {
            return $goal;
        }

        $first = trim((string) preg_split('/[\.!?]/u', $rawText, 2)[0]);
        $first = (string) preg_replace('/\s+/u', ' ', $first);
        if ($first === '') {
            return '';
        }
        $first = rtrim($first, " .!?,");
        if (mb_strlen($first) > 200) {
            $first = mb_substr($first, 0, 200);
        }
        if (mb_strlen($first) === 0) {
            return '';
        }

        return mb_strtoupper(mb_substr($first, 0, 1)).mb_substr($first, 1);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveProvider(string $polisherHint, mixed $hintFromRequest): array
    {
        $valid = ['local', 'codex_cli', 'claude_cli', 'auto', 'atlas'];
        $requested = is_string($hintFromRequest) ? $hintFromRequest : '';

        if ($requested !== '' && in_array($requested, $valid, true)) {
            if ($requested === 'auto') {
                // 'auto' from the request is honoured directly.
                return ['auto', 'request_auto'];
            }
            if ($polisherHint === 'local' || $polisherHint === $requested) {
                return [$requested, 'request_explicit'];
            }
            // Polisher detected a specific provider that contradicts the
            // request. Don't silently override — escalate to auto and tell
            // the caller (via *_source) that the inputs disagreed.
            return ['auto', 'request_overruled_by_voice'];
        }

        return [$polisherHint, 'voice_only'];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveOutputFormat(string $text, mixed $hintFromRequest): array
    {
        $valid = ['plan', 'diff', 'text', 'notes', 'diagnostic'];
        $requested = is_string($hintFromRequest) ? $hintFromRequest : '';
        if ($requested !== '' && in_array($requested, $valid, true)) {
            return [$requested, 'request_explicit'];
        }

        $lower = mb_strtolower($text);

        // V6-FPG-B · ordem de precedência (mais específico vence o mais
        // genérico). "faz um plano" é instrução explícita do operador e
        // deve vencer "sem mexer" (que é só uma restrição negativa,
        // catalogada também em DIAGNOSTIC_TOKENS para casos puros de
        // read-only).
        $hits = [
            'plan' => $this->matchesAny($lower, self::PLAN_TOKENS),
            'diff' => $this->matchesAny($lower, self::DIFF_TOKENS),
            'diagnostic' => $this->matchesAny($lower, self::DIAGNOSTIC_TOKENS),
            'notes' => $this->matchesAny($lower, self::NOTES_TOKENS),
        ];
        // Precedência canônica:
        //   1. plan      — "faz um plano", "roteiro", "estrutura"
        //   2. diff      — "aplica", "patch", "diff"
        //   3. diagnostic— "investiga", "analisa", "só leia"
        //   4. notes     — "salva em nota"
        foreach (['plan', 'diff', 'diagnostic', 'notes'] as $kind) {
            if ($hits[$kind]) {
                return [$kind, 'voice'];
            }
        }

        return ['text', 'default'];
    }

    /**
     * @param  list<string>  $tokens
     */
    private function matchesAny(string $lower, array $tokens): bool
    {
        foreach ($tokens as $tok) {
            if (mb_strpos($lower, $tok) !== false) {
                return true;
            }
        }
        return false;
    }

    private function detectExecutorHint(string $text, string $outputFormat): string
    {
        $lower = mb_strtolower($text);

        foreach (self::SHELL_VERBS as $tok) {
            if (mb_strpos($lower, $tok) !== false) {
                return 'terminal_propose';
            }
        }
        if ($outputFormat === 'notes') {
            return 'note';
        }
        if ($outputFormat === 'diff') {
            return 'edit';
        }

        return 'none';
    }

    /**
     * @param  mixed  $contextRefs  raw input from request
     * @return list<array{kind: string, ref: ?string, resolved: bool}>
     */
    private function normaliseContextRefs(mixed $contextRefs): array
    {
        if (! is_array($contextRefs) || $contextRefs === []) {
            return [['kind' => 'none', 'ref' => null, 'resolved' => true]];
        }

        $allowedKinds = ['file', 'selection', 'active_window', 'terminal_recent', 'none'];
        $out = [];
        foreach ($contextRefs as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $kind = (string) ($entry['kind'] ?? 'none');
            if (! in_array($kind, $allowedKinds, true)) {
                continue;
            }
            $ref = $entry['ref'] ?? null;
            $ref = is_string($ref) && $ref !== '' ? $ref : null;
            $resolved = array_key_exists('resolved', $entry)
                ? (bool) $entry['resolved']
                : ($ref !== null);
            $out[] = ['kind' => $kind, 'ref' => $ref, 'resolved' => $resolved];
        }
        if ($out === []) {
            return [['kind' => 'none', 'ref' => null, 'resolved' => true]];
        }

        return $out;
    }

    /**
     * @param  list<string>  $constraints
     * @return array{risk_class: string, risk_reasoning: string, risk_markers: list<string>}
     */
    private function classifyRisk(
        string $text,
        string $rawText,
        string $outputFormat,
        array $constraints,
        string $executorHint,
    ): array {
        $markers = [];
        $haystack = $text."\n".$rawText;
        foreach (self::R4_HARD_VETO as $entry) {
            if (preg_match($entry['pattern'], $haystack) === 1) {
                $markers[] = $entry['label'];
            }
        }
        if ($markers !== []) {
            return [
                'risk_class' => VoxSchema::RISK_R4,
                'risk_reasoning' => 'destrutivo/irreversível: marcadores detectados ['.implode(', ', $markers).']',
                'risk_markers' => array_values(array_unique($markers)),
            ];
        }

        if ($executorHint === 'terminal_propose') {
            return [
                'risk_class' => VoxSchema::RISK_R3,
                'risk_reasoning' => 'comando externo proposto (terminal/teste); execução real exigirá confirmação V3',
                'risk_markers' => [],
            ];
        }

        $lower = mb_strtolower($text);

        // R2 should only fire on a *free-standing* edit verb — one not
        // sitting inside a negation the operator just stated. Otherwise
        // "investiga isso, mas não edita nada" would jump to R2 because
        // the verb "edita" appears literally, even though it's forbidden.
        $hasEditVerb = false;
        foreach (self::EDIT_VERBS as $verb) {
            $negatedPattern = '/(?:n[ãa]o|sem)\s+'.preg_quote($verb, '/').'(?![\p{L}\p{N}_])/iu';
            $freePattern = '/(?<![\p{L}\p{N}_])'.preg_quote($verb, '/').'(?![\p{L}\p{N}_])/iu';
            $total = preg_match_all($freePattern, $lower) ?: 0;
            $negated = preg_match_all($negatedPattern, $lower) ?: 0;
            if ($total > $negated) {
                $hasEditVerb = true;
                break;
            }
        }
        if ($hasEditVerb || $outputFormat === 'diff') {
            return [
                'risk_class' => VoxSchema::RISK_R2,
                'risk_reasoning' => 'edição local reversível: voz pede alterar/refatorar/aplicar diff',
                'risk_markers' => [],
            ];
        }

        if ($outputFormat === 'diagnostic' || $outputFormat === 'plan'
            || preg_match('/\b(investiga|analisa|entend(?:e|er)|descobr(?:e|ir)|le(?:r|ia))\b/iu', $lower) === 1
        ) {
            return [
                'risk_class' => VoxSchema::RISK_R1,
                'risk_reasoning' => 'leitura/análise/investigação: sem efeito externo, sem edição',
                'risk_markers' => [],
            ];
        }

        return [
            'risk_class' => VoxSchema::RISK_R0,
            'risk_reasoning' => 'intent_compile somente texto/prompt: sem efeito externo',
            'risk_markers' => [],
        ];
    }
}
