<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Runtime for the Intent Routing kernel step.
 *
 * Turns the doc's contract into pure, deterministic decision logic (no IO, no
 * clock, no DB). Same envelope -> same routing decision. The doc is the
 * authoring boundary; this code never picks a provider and never executes a
 * tool at this stage.
 *
 * Load-bearing contracts pinned here:
 *
 *   1. Contracts. Input = operation envelope. Output = intent, initial risk,
 *      task type and clarification need. Invariant: "provider ainda nao e
 *      escolhido" -> every decision asserts provider_selected = false.
 *      -> route()
 *
 *   2. Regras para IA. "IA nao deve pular clarificacao quando a intent bloquear
 *      seguranca, escopo ou autonomia." When the envelope flags a security,
 *      scope or autonomy block, clarification_needed is forced true even when
 *      confidence is high and ambiguity is low. Clarification can never be
 *      skipped under those blocks. -> route()
 *
 *   3. Fluxo. Classify request, identify ambiguity, hand off to business-context
 *      (graph flows_to: business-context). -> route() emits next_node.
 *
 *   4. Escopo de Implementacao / forbidden_changes. "Escolher modelo ou executar
 *      ferramenta nesta etapa" is forbidden. The decider only classifies; it
 *      exposes the forbidden actions and never returns a provider or a tool
 *      action. -> forbiddenAtThisStage()
 *
 *   5. Riscos. "Intents genericas demais reduzirem qualidade do contexto" and
 *      risk_level: high. An unknown / empty intent is the documented danger:
 *      it routes as conversation, takes a cautious initial risk and forces
 *      clarification rather than guessing.
 *
 * @see docs/engineering-knowledge-base/system-graph/intent-routing.md
 */
final class AtlasIntentRoutingService
{
    /** Stable evidence schema id this runtime emits. */
    public const SCHEMA_VERSION = 'atlas.system_graph.intent_routing.v1';

    /** Graph successor (doc front-matter: flows_to: business-context). */
    public const NEXT_NODE = 'business-context';

    /** Closed task-type vocabulary. The doc Example: "Refatora Decide" -> programming. */
    public const TASK_PROGRAMMING = 'programming';
    public const TASK_REVIEW = 'review';
    public const TASK_DEBUG = 'debug';
    public const TASK_RESEARCH = 'research';
    public const TASK_PLAN = 'plan';
    public const TASK_EXPLAIN = 'explain';
    public const TASK_CONVERSATION = 'conversation';

    /** Initial risk bands. The doc itself carries risk_level: high. */
    public const RISK_LOW = 'low';
    public const RISK_MEDIUM = 'medium';
    public const RISK_HIGH = 'high';

    /**
     * The three documented clarification blocks (Regras para IA). When any of
     * these is flagged on the envelope, clarification is mandatory and cannot be
     * skipped, regardless of confidence or ambiguity.
     *
     * @var array<string, string>
     */
    private const CLARIFICATION_BLOCKS = [
        'security' => 'intent blocks security: clarification cannot be skipped',
        'scope' => 'intent blocks scope: clarification cannot be skipped',
        'autonomy' => 'intent blocks autonomy: clarification cannot be skipped',
    ];

    /**
     * Keyword -> task type rules, ordered by precedence (review/debug before the
     * broad programming bucket so "review the diff" is not swallowed as a generic
     * code task). Lower-cased needles, substring match.
     *
     * @var array<int, array{task: string, needles: list<string>}>
     */
    private const TASK_RULES = [
        ['task' => self::TASK_REVIEW, 'needles' => ['code review', 'review do', 'revise o', 'revisar', 'pull request', 'diff ', ' diff', 'aprovar pr']],
        ['task' => self::TASK_DEBUG, 'needles' => ['stack trace', 'stacktrace', 'traceback', 'depurar', 'debugar', 'erro em producao', 'exception', 'porque falha', 'why does it fail']],
        ['task' => self::TASK_PROGRAMMING, 'needles' => ['refator', 'refactor', 'implementa', 'implemente', 'implement ', 'corrija', 'corrigir', 'crie classe', 'crie funcao', 'crie função', 'escreva teste', 'adicione metodo', 'fix ', 'codifique', 'migration', 'endpoint']],
        ['task' => self::TASK_PLAN, 'needles' => ['planeje', 'planejar', 'roadmap', 'estruture', 'arquitetura antes', 'antes de implementar', 'cronograma', 'fases do']],
        ['task' => self::TASK_RESEARCH, 'needles' => ['pesquise', 'pesquisar', 'pesquisa ', 'estado da arte', 'state of the art', 'fontes', 'levantamento', 'como funciona', 'how does']],
        ['task' => self::TASK_EXPLAIN, 'needles' => ['explique', 'explica ', 'explain', 'resuma', 'o que e ', 'o que é']],
    ];

    /**
     * Initial risk per task type. The intent step assigns an *initial* risk only;
     * it never selects a provider, so this is a coarse, cautious band that later
     * gates refine. Programming/debug touch the codebase -> medium; everything
     * unresolved leans high (the doc's documented danger of wrong routing).
     *
     * @var array<string, string>
     */
    private const TASK_INITIAL_RISK = [
        self::TASK_PROGRAMMING => self::RISK_MEDIUM,
        self::TASK_DEBUG => self::RISK_MEDIUM,
        self::TASK_REVIEW => self::RISK_MEDIUM,
        self::TASK_PLAN => self::RISK_LOW,
        self::TASK_RESEARCH => self::RISK_LOW,
        self::TASK_EXPLAIN => self::RISK_LOW,
        self::TASK_CONVERSATION => self::RISK_LOW,
    ];

    /** Actions the doc forbids at this stage (forbidden_changes / Escopo). */
    private const FORBIDDEN_AT_THIS_STAGE = [
        'select_provider_or_model',
        'execute_tool',
        'direct_execution',
    ];

    /**
     * Route an operation envelope: classify intent, assign initial risk and task
     * type, and decide whether clarification is required. Provider is never
     * chosen here (invariant: provider_selected = false).
     *
     * Accepted envelope keys:
     *   - text|raw_intent|input_text (string): the operator request
     *   - blocks_security|blocks_scope|blocks_autonomy (bool): explicit blocks
     *   - ambiguous (bool): upstream signalled multiple valid interpretations
     *
     * @param  array<string, mixed>  $envelope
     * @return array{
     *     schema_version: string,
     *     stage: string,
     *     task_type: string,
     *     intent: string,
     *     initial_risk: string,
     *     ambiguous: bool,
     *     clarification_needed: bool,
     *     clarification_blocks: list<string>,
     *     clarification_reasons: list<string>,
     *     provider_selected: bool,
     *     next_node: string,
     *     forbidden_at_this_stage: list<string>,
     *     matched_needle: string|null
     * }
     */
    public function route(array $envelope): array
    {
        $text = $this->normalize(
            $this->stringKey($envelope, 'text')
            ?? $this->stringKey($envelope, 'raw_intent')
            ?? $this->stringKey($envelope, 'input_text')
            ?? ''
        );

        [$task, $needle] = $this->classify($text);

        // Documented danger: an empty / generic intent must not be guessed at.
        // It routes as conversation and forces clarification.
        $unresolved = $task === self::TASK_CONVERSATION;

        // Ambiguity is either signalled upstream or implied by an unresolved task.
        $ambiguous = $this->boolKey($envelope, 'ambiguous') || $unresolved;

        // Regras para IA: any security/scope/autonomy block forces clarification.
        $blocks = $this->triggeredBlocks($envelope);

        $reasons = [];
        foreach ($blocks as $block) {
            $reasons[] = self::CLARIFICATION_BLOCKS[$block];
        }
        if ($ambiguous) {
            $reasons[] = $unresolved
                ? 'intent unresolved / too generic: clarify before routing'
                : 'envelope flagged ambiguous: clarify before routing';
        }

        // Clarification is required when blocked OR ambiguous. A high-confidence
        // intent never overrides a block.
        $clarificationNeeded = $blocks !== [] || $ambiguous;

        // Initial risk: take the task band, but any block lifts it to high
        // (the doc marks this whole step risk_level: high).
        $risk = self::TASK_INITIAL_RISK[$task] ?? self::RISK_HIGH;
        if ($blocks !== []) {
            $risk = self::RISK_HIGH;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'stage' => 'intent-routing',
            'task_type' => $task,
            'intent' => $task,
            'initial_risk' => $risk,
            'ambiguous' => $ambiguous,
            'clarification_needed' => $clarificationNeeded,
            'clarification_blocks' => $blocks,
            'clarification_reasons' => array_values($reasons),
            // Invariant from Contracts: provider is NOT chosen at this stage.
            'provider_selected' => false,
            // Flow: hand off to business-context (graph flows_to).
            'next_node' => self::NEXT_NODE,
            'forbidden_at_this_stage' => self::FORBIDDEN_AT_THIS_STAGE,
            'matched_needle' => $needle,
        ];
    }

    /**
     * The actions the doc forbids in the Intent Routing step. Exposed so callers
     * can assert the boundary instead of re-deriving it.
     *
     * @return array{schema_version: string, stage: string, forbidden: list<string>, invariant: string}
     */
    public function forbiddenAtThisStage(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'stage' => 'intent-routing',
            'forbidden' => self::FORBIDDEN_AT_THIS_STAGE,
            'invariant' => 'provider is not chosen at this stage',
        ];
    }

    /**
     * The closed task-type vocabulary this step can emit.
     *
     * @return list<string>
     */
    public function taskTypes(): array
    {
        return [
            self::TASK_PROGRAMMING,
            self::TASK_REVIEW,
            self::TASK_DEBUG,
            self::TASK_RESEARCH,
            self::TASK_PLAN,
            self::TASK_EXPLAIN,
            self::TASK_CONVERSATION,
        ];
    }

    /**
     * Classify normalized text into a task type by ordered keyword precedence.
     *
     * @return array{0: string, 1: string|null} [task, matched needle or null]
     */
    private function classify(string $text): array
    {
        if ($text === '') {
            return [self::TASK_CONVERSATION, null];
        }

        foreach (self::TASK_RULES as $rule) {
            foreach ($rule['needles'] as $needle) {
                if ($needle !== '' && str_contains($text, $needle)) {
                    return [$rule['task'], $needle];
                }
            }
        }

        return [self::TASK_CONVERSATION, null];
    }

    /**
     * The clarification blocks flagged on the envelope, in stable order.
     *
     * @param  array<string, mixed>  $envelope
     * @return list<string>
     */
    private function triggeredBlocks(array $envelope): array
    {
        $blocks = [];
        if ($this->boolKey($envelope, 'blocks_security')) {
            $blocks[] = 'security';
        }
        if ($this->boolKey($envelope, 'blocks_scope')) {
            $blocks[] = 'scope';
        }
        if ($this->boolKey($envelope, 'blocks_autonomy')) {
            $blocks[] = 'autonomy';
        }

        return $blocks;
    }

    private function normalize(string $value): string
    {
        $lower = mb_strtolower(trim($value));

        return preg_replace('/\s+/u', ' ', $lower) ?? $lower;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function stringKey(array $envelope, string $key): ?string
    {
        $value = $envelope[$key] ?? null;
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function boolKey(array $envelope, string $key): bool
    {
        return array_key_exists($key, $envelope) && $envelope[$key] === true;
    }
}
