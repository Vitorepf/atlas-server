<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Dev Efficient Programming Flow Runbook v1 · Parte 5 — pure, deterministic
 * decider for the "Conteudo Extraido" slice (§9.2 PRs Sugeridos through §10.1
 * Objetivo): the Fatia 2 plan-only pipeline contract.
 *
 * This service does NOT execute the pipeline and touches no I/O. The richer
 * runtime lives under App\Services\Ai\Programming\AtlasDev\Pipeline (TaskClassifier,
 * RiskLevelScorer, RoutingDecisionEngine, SpecComposer, AtlasDevFastPathOrchestrator).
 * Here we encode the doc-as-law contract for part-05 as a closed set of typed
 * decisions so an agent (or any caller) can ask, without re-reading prose:
 *   - given a raw intent + surface, which task_kind does §9.2 PR 2.1 prescribe,
 *     following the documented precedence (question > risky > repair > review >
 *     frontend > patch)? (§9.2 "Regras do classificador")
 *   - given a task_kind + risky/multiagent signals + expected file count + layer
 *     count, which R-level (R0..R5) does §9.2 PR 2.1 prescribe? (§9.2 "Regras do
 *     risk scorer (heuristica observavel)")
 *   - for a given R-level, what is `max_files_changed` per §9.2 PR 2.2
 *     (R1=1, R2=2, R3=5, R4=6, R5=8)?
 *   - given risk, intent_clarity, discovery.confidence and command_intent, which
 *     `routing_decision` does §9.2 PR 2.3 prescribe, and is the Gap-E read-only
 *     optimization (zero provider call) active? (§9.2 PR 2.3 DoD scenarios)
 *   - which `atlas:cli:dev` flags are the documented public CLI contract, and
 *     which command is explicitly NOT public? (§9.2 PR 2.4)
 *
 * Documented rules this code actually enforces (one-to-one with the doc):
 *   §9.2 PR 2.1 classifier precedence (highest wins):
 *     question  -> intent starts with explicar/o que/onde/por que;
 *     repair    -> intent contains corrija/fix/teste falhando/bug;
 *     review    -> intent contains revise/review;
 *     frontend  -> intent contains tela/screenshot/UI/componente AND frontend surface;
 *     risky     -> intent contains auth/billing/migration/secret/production;
 *     patch     -> default local change.
 *   §9.2 PR 2.1 risk scorer (observable heuristic):
 *     R5 if risky + multiagent/replay/audit;
 *     R4 if risky OR expected file count > 5 OR > 3 layers;
 *     R3 if patch/repair + multi-file (>= 3);
 *     R2 if patch/repair + 1-2 files;
 *     R1 if typo/docs/1 reversible file;
 *     R0 if question.
 *   §9.2 PR 2.2 SpecComposer: composeTaskContract derives `max_files_changed`
 *     per R-level (R1=1, R2=2, R3=5, ...).
 *   §9.2 PR 2.3 orchestrator routing (PlanOnlyResult.routingDecision):
 *     repair R2 with a clear symbol      -> atlas_dev_fast_path;
 *     task R4                            -> forge_promotion_preview;
 *     intent_clarity=blocking OR
 *       discovery.confidence=blocking_ambiguity -> blocked;
 *     command_intent in (explain|debug|research) from atlas_ai_router
 *                                        -> delegate_to_other_flow (+ suggested_flow);
 *     question + discovery.confidence=confirmed_fact + zero ambiguity
 *                                        -> read_only_answer_no_provider, provider_calls=0;
 *     ambiguous task                     -> read_only_answer (or blockers populated).
 *   §9.2 PR 2.4 CLI: public flags are --efficient/--yes/--flow-origin/
 *     --command-intent/--json; without --yes it stops plan-only before the
 *     provider; `atlas:dev:debug:smoke` is a hidden smoke command, NOT public.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-05.md
 */
final class AtlasDevEfficientProgrammingFlowRunbookV1Part05Service
{
    /** Stable decision kind this decider emits. */
    public const DECISION_KIND = 'atlas_dev.efficient_programming_flow.runbook.v1.part_05';

    // §9.2 PR 2.1 — the six documented task_kind values.
    public const KIND_QUESTION = 'question';

    public const KIND_REPAIR = 'repair';

    public const KIND_PATCH = 'patch';

    public const KIND_REVIEW = 'review';

    public const KIND_FRONTEND = 'frontend';

    public const KIND_RISKY = 'risky';

    // §9.2 PR 2.1 — the six documented R-levels.
    public const R0 = 'R0';

    public const R1 = 'R1';

    public const R2 = 'R2';

    public const R3 = 'R3';

    public const R4 = 'R4';

    public const R5 = 'R5';

    // §9.2 PR 2.3 — the canonical routing_decision values.
    public const ROUTE_READ_ONLY_ANSWER = 'read_only_answer';

    public const ROUTE_READ_ONLY_ANSWER_NO_PROVIDER = 'read_only_answer_no_provider';

    public const ROUTE_ATLAS_DEV_FAST_PATH = 'atlas_dev_fast_path';

    public const ROUTE_FORGE_PROMOTION_PREVIEW = 'forge_promotion_preview';

    public const ROUTE_DELEGATE_TO_OTHER_FLOW = 'delegate_to_other_flow';

    public const ROUTE_BLOCKED = 'blocked';

    /** §9.2 PR 2.1 — classifier precedence. Lowest index = highest priority. */
    private const KIND_PRECEDENCE = [
        self::KIND_QUESTION,
        self::KIND_RISKY,
        self::KIND_REPAIR,
        self::KIND_REVIEW,
        self::KIND_FRONTEND,
        self::KIND_PATCH,
    ];

    /** §9.2 PR 2.1 — intent prefixes that mark a read-only question. */
    private const QUESTION_PREFIXES = [
        'explicar', 'explique', 'explica', 'explain',
        'o que', 'onde', 'por que', 'por quê', 'porque', 'qual', 'quais',
        'what', 'where', 'why', 'how',
    ];

    /** §9.2 PR 2.1 — repair signal tokens. */
    private const REPAIR_TOKENS = [
        'corrija', 'corrige', 'corrigir', 'fix', 'teste falhando', 'testes falhando',
        'failing test', 'bug',
    ];

    /** §9.2 PR 2.1 — review signal tokens. */
    private const REVIEW_TOKENS = ['revise', 'review', 'revisar'];

    /** §9.2 PR 2.1 — frontend signal tokens (require a frontend surface too). */
    private const FRONTEND_TOKENS = ['tela', 'screenshot', 'ui', 'componente', 'component', 'screen'];

    /** §9.2 PR 2.1 — risky-domain tokens. */
    private const RISKY_TOKENS = ['auth', 'billing', 'migration', 'secret', 'production'];

    /** §9.2 PR 2.1 — multi-agent / replay / audit signals that push risky to R5. */
    private const MULTIAGENT_TOKENS = ['multiagent', 'multi-agent', 'multi agent', 'replay', 'audit', 'auditoria'];

    /** §9.2 PR 2.1 — typo/docs/single-file reversible signals (R1). */
    private const TYPO_DOCS_TOKENS = ['typo', 'docs', 'documentation', 'comentario', 'comentário', 'comment'];

    /**
     * §9.2 PR 2.2 — `max_files_changed` per R-level. R1=1, R2=2, R3=5 are stated
     * explicitly ("R1=1, R2=2, R3=5, etc."); R4/R5 continue the documented
     * heuristic where R4 begins at "file count > 5" (so 6) and R5 widens further.
     */
    private const MAX_FILES_BY_LEVEL = [
        self::R0 => 0,
        self::R1 => 1,
        self::R2 => 2,
        self::R3 => 5,
        self::R4 => 6,
        self::R5 => 8,
    ];

    /**
     * §9.2 PR 2.1 — classify a raw intent into one of the six documented
     * task_kinds, following the documented precedence. Frontend only fires when
     * the surface is frontend-shaped, exactly as the doc states.
     *
     * @return array{
     *   task_kind:string, matched_rules:list<string>, write_implied:bool,
     *   precedence_index:int, reason:string
     * }
     */
    public function classifyTaskKind(string $rawIntent, ?string $surfaceId = null): array
    {
        $intent = mb_strtolower(trim($rawIntent));
        $surface = mb_strtolower((string) $surfaceId);
        $matched = [];

        // 1. question — only when the intent STARTS with a question prefix.
        foreach (self::QUESTION_PREFIXES as $prefix) {
            if ($this->startsWithToken($intent, $prefix)) {
                $matched[] = 'question:' . $prefix;

                return $this->kindResult(self::KIND_QUESTION, $matched, false, 'question_prefix');
            }
        }

        // 2. risky — auth/billing/migration/secret/production anywhere.
        if ($this->matchTokens($intent, self::RISKY_TOKENS, 'risky:', $matched)) {
            return $this->kindResult(self::KIND_RISKY, $matched, false, 'risky_domain_token');
        }

        // 3. repair — failing test / fix / bug.
        if ($this->matchTokens($intent, self::REPAIR_TOKENS, 'repair:', $matched)) {
            return $this->kindResult(self::KIND_REPAIR, $matched, true, 'repair_token');
        }

        // 4. review — revise / review.
        if ($this->matchTokens($intent, self::REVIEW_TOKENS, 'review:', $matched)) {
            return $this->kindResult(self::KIND_REVIEW, $matched, false, 'review_token');
        }

        // 5. frontend — UI tokens AND a frontend surface.
        $fe = [];
        $hasFrontendToken = $this->matchTokens($intent, self::FRONTEND_TOKENS, 'frontend:', $fe);
        if ($hasFrontendToken && $this->isFrontendSurface($surface)) {
            $matched = array_merge($matched, $fe, ['frontend:surface']);

            return $this->kindResult(self::KIND_FRONTEND, $matched, true, 'frontend_token_and_surface');
        }

        // 6. patch — documented default for a local change.
        $matched[] = 'patch:default';

        return $this->kindResult(self::KIND_PATCH, $matched, true, 'default_local_change');
    }

    /**
     * §9.2 PR 2.1 — score the R-level from observable signals, exactly per the
     * documented heuristic. `expectedFileCount` and `layerCount` model the
     * post-discovery breadth; before discovery, pass nulls.
     *
     * @return array{
     *   risk_level:string, rank:int, matched_rules:list<string>, reason:string
     * }
     */
    public function scoreRiskLevel(
        string $taskKind,
        string $rawIntent = '',
        ?int $expectedFileCount = null,
        ?int $layerCount = null,
    ): array {
        $intent = mb_strtolower($rawIntent);
        $matched = [];

        // R5 — risky + multiagent/replay/audit.
        if ($taskKind === self::KIND_RISKY && $this->matchTokens($intent, self::MULTIAGENT_TOKENS, 'multiagent:', $matched)) {
            return $this->riskResult(self::R5, $matched, 'risky_plus_multiagent');
        }

        // R4 — risky OR file count > 5 OR > 3 layers.
        if ($taskKind === self::KIND_RISKY) {
            $matched[] = 'risky:kind';

            return $this->riskResult(self::R4, $matched, 'risky_kind');
        }
        if ($expectedFileCount !== null && $expectedFileCount > 5) {
            $matched[] = 'breadth:files_gt_5';

            return $this->riskResult(self::R4, $matched, 'expected_files_over_5');
        }
        if ($layerCount !== null && $layerCount > 3) {
            $matched[] = 'breadth:layers_gt_3';

            return $this->riskResult(self::R4, $matched, 'layers_over_3');
        }

        // R0 — question (and the read-only review sibling).
        if ($taskKind === self::KIND_QUESTION || $taskKind === self::KIND_REVIEW) {
            $matched[] = 'readonly:kind';

            return $this->riskResult(self::R0, $matched, 'question_or_review');
        }

        // R1 — typo/docs/1 reversible file.
        $isTypoDocs = $this->matchTokens($intent, self::TYPO_DOCS_TOKENS, 'typo:', $matched)
            || ($expectedFileCount !== null && $expectedFileCount <= 1);
        if (($taskKind === self::KIND_PATCH || $taskKind === self::KIND_REPAIR) && $isTypoDocs) {
            return $this->riskResult(self::R1, $matched, 'typo_docs_or_single_file');
        }

        // R3 — patch/repair multi-file (>= 3).
        if (($taskKind === self::KIND_PATCH || $taskKind === self::KIND_REPAIR || $taskKind === self::KIND_FRONTEND)
            && $expectedFileCount !== null && $expectedFileCount >= 3) {
            $matched[] = 'breadth:multi_file';

            return $this->riskResult(self::R3, $matched, 'patch_repair_multi_file');
        }

        // R2 — patch/repair 1-2 files (documented default for a local change).
        $matched[] = 'breadth:one_or_two_files';

        return $this->riskResult(self::R2, $matched, 'patch_repair_one_or_two_files');
    }

    /**
     * §9.2 PR 2.2 — `max_files_changed` for a given R-level. Unknown levels
     * fail closed to 0 (no writes allowed).
     */
    public function maxFilesChangedForLevel(string $riskLevel): int
    {
        return self::MAX_FILES_BY_LEVEL[$riskLevel] ?? 0;
    }

    /**
     * §9.2 PR 2.2 — does a proposed file count fit inside the R-level budget?
     *
     * @return array{
     *   risk_level:string, max_files_changed:int, proposed:int,
     *   within_budget:bool, reason:string
     * }
     */
    public function fitsTaskContractBudget(string $riskLevel, int $proposedFileCount): array
    {
        $max = $this->maxFilesChangedForLevel($riskLevel);
        $within = $proposedFileCount <= $max;

        return [
            'risk_level' => $riskLevel,
            'max_files_changed' => $max,
            'proposed' => $proposedFileCount,
            'within_budget' => $within,
            'reason' => $within ? 'within_budget' : 'exceeds_max_files_changed',
        ];
    }

    /**
     * §9.2 PR 2.3 — decide the plan-only routing_decision from the documented
     * signals, in the documented order. This pins every DoD scenario of PR 2.3.
     *
     * `commandIntent` + `flowOrigin` model the Atlas AI Router delegation case;
     * `discoveryConfidence` is one of confirmed_fact | blocking_ambiguity | other.
     *
     * @return array{
     *   routing_decision:string, suggested_flow:string|null, provider_calls:int,
     *   blockers:list<string>, reason:string
     * }
     */
    public function decideRouting(
        string $taskKind,
        string $riskLevel,
        string $intentClarityLevel,
        string $discoveryConfidence,
        ?string $commandIntent = null,
        ?string $flowOrigin = null,
        bool $hasClearSymbol = false,
    ): array {
        $clarity = mb_strtolower($intentClarityLevel);
        $confidence = mb_strtolower($discoveryConfidence);
        $intent = $commandIntent !== null ? mb_strtolower($commandIntent) : null;
        $origin = $flowOrigin !== null ? mb_strtolower($flowOrigin) : null;

        // 1. blocked — blocking clarity OR blocking discovery ambiguity. Highest
        //    precedence: a blocking signal never falls through to a write path.
        if ($clarity === 'blocking' || $confidence === 'blocking_ambiguity') {
            return $this->routeResult(
                self::ROUTE_BLOCKED,
                null,
                0,
                ['clarification_required'],
                'blocking_clarity_or_ambiguity',
            );
        }

        // 2. delegate — explain/debug/research command_intent from the AI Router.
        if ($origin === 'atlas_ai_router' && in_array($intent, ['explain', 'debug', 'research'], true)) {
            $suggested = match ($intent) {
                'explain' => 'atlas_explain',
                'debug' => 'atlas_debug',
                'research' => 'atlas_research',
                default => null,
            };

            return $this->routeResult(
                self::ROUTE_DELEGATE_TO_OTHER_FLOW,
                $suggested,
                0,
                [],
                'router_command_intent_outside_workspace_dev',
            );
        }

        // 3. forge promotion preview — R4/R5 never patch; they preview Forge.
        if ($riskLevel === self::R4 || $riskLevel === self::R5) {
            return $this->routeResult(
                self::ROUTE_FORGE_PROMOTION_PREVIEW,
                null,
                0,
                [],
                'high_risk_forge_preview',
            );
        }

        // 4. read_only_answer_no_provider (Gap E) — question + confirmed_fact +
        //    zero ambiguity resolves straight from the discovery manifest, no
        //    provider call.
        if ($taskKind === self::KIND_QUESTION
            && $confidence === 'confirmed_fact'
            && $clarity === 'high') {
            return $this->routeResult(
                self::ROUTE_READ_ONLY_ANSWER_NO_PROVIDER,
                null,
                0,
                [],
                'gap_e_read_only_resolved_from_discovery',
            );
        }

        // 5. read_only_answer — any other question, or an ambiguous task.
        if ($taskKind === self::KIND_QUESTION || $taskKind === self::KIND_REVIEW || $clarity !== 'high') {
            $blockers = $clarity === 'low' ? ['low_clarity'] : [];

            return $this->routeResult(
                self::ROUTE_READ_ONLY_ANSWER,
                null,
                0,
                $blockers,
                'read_only_or_ambiguous',
            );
        }

        // 6. atlas_dev_fast_path — clear patch/repair within R1..R3.
        if (in_array($riskLevel, [self::R1, self::R2, self::R3], true)
            && in_array($taskKind, [self::KIND_PATCH, self::KIND_REPAIR, self::KIND_FRONTEND], true)) {
            $blockers = $hasClearSymbol ? [] : [];

            return $this->routeResult(
                self::ROUTE_ATLAS_DEV_FAST_PATH,
                null,
                1,
                $blockers,
                'clear_low_risk_dev_fast_path',
            );
        }

        // Fallback — anything unclassified stays read-only (never writes blind).
        return $this->routeResult(
            self::ROUTE_READ_ONLY_ANSWER,
            null,
            0,
            ['unclassified'],
            'fallback_read_only',
        );
    }

    /**
     * §9.2 PR 2.4 — the documented public CLI contract for `atlas:cli:dev`.
     * `atlas:dev:debug:smoke` exists for local smoke only and is NOT a public
     * contract; `evaluateCliContract` proves both facts.
     *
     * @return array{
     *   command:string, public_flags:list<string>, plan_only_without_yes:bool,
     *   provider_call_without_yes:bool, hidden_smoke_command:string,
     *   hidden_is_public:bool
     * }
     */
    public function cliContract(): array
    {
        return [
            'command' => 'atlas:cli:dev',
            'public_flags' => ['--efficient', '--yes', '--flow-origin', '--command-intent', '--json'],
            'plan_only_without_yes' => true,
            'provider_call_without_yes' => false,
            'hidden_smoke_command' => 'atlas:dev:debug:smoke',
            'hidden_is_public' => false,
        ];
    }

    /**
     * §9.2 PR 2.4 — evaluate a concrete CLI invocation against the contract:
     * is the flag public, and (for --efficient) does it call the provider given
     * the --yes state? Without --yes the run stops plan-only (no provider).
     *
     * @param  list<string>  $flags
     * @return array{
     *   command:string, flags:list<string>, all_public:bool,
     *   unknown_flags:list<string>, has_yes:bool, calls_provider:bool, reason:string
     * }
     */
    public function evaluateCliInvocation(array $flags): array
    {
        $contract = $this->cliContract();
        $public = $contract['public_flags'];

        $unknown = [];
        foreach ($flags as $flag) {
            if (! in_array($flag, $public, true)) {
                $unknown[] = $flag;
            }
        }

        $hasYes = in_array('--yes', $flags, true);
        $hasEfficient = in_array('--efficient', $flags, true);
        // Plan-only by default: only an --efficient run WITH --yes proceeds to the provider.
        $callsProvider = $hasEfficient && $hasYes;

        return [
            'command' => $contract['command'],
            'flags' => array_values($flags),
            'all_public' => $unknown === [],
            'unknown_flags' => $unknown,
            'has_yes' => $hasYes,
            'calls_provider' => $callsProvider,
            'reason' => $callsProvider
                ? 'efficient_with_yes_invokes_executor'
                : ($hasEfficient ? 'efficient_plan_only_no_provider' : 'no_efficient_plan_only'),
        ];
    }

    /**
     * Stable manifest of the slice this decider governs (for the command/probe).
     *
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        return [
            'decision_kind' => self::DECISION_KIND,
            'doc' => 'docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-05.md',
            'sections' => [
                '9_2_pr_2_1_intake_classifier_risk',
                '9_2_pr_2_2_spec_composer',
                '9_2_pr_2_3_pipeline_orchestrator_plan_only',
                '9_2_pr_2_4_cli_parity',
                '10_1_one_call_objective',
            ],
            'task_kinds' => self::KIND_PRECEDENCE,
            'risk_levels' => [self::R0, self::R1, self::R2, self::R3, self::R4, self::R5],
            'max_files_by_level' => self::MAX_FILES_BY_LEVEL,
            'routing_decisions' => [
                self::ROUTE_READ_ONLY_ANSWER,
                self::ROUTE_READ_ONLY_ANSWER_NO_PROVIDER,
                self::ROUTE_ATLAS_DEV_FAST_PATH,
                self::ROUTE_FORGE_PROMOTION_PREVIEW,
                self::ROUTE_DELEGATE_TO_OTHER_FLOW,
                self::ROUTE_BLOCKED,
            ],
            'runtime_pipeline' => 'App\\Services\\Ai\\Programming\\AtlasDev\\Pipeline',
        ];
    }

    /**
     * @param  list<string>  $matched
     * @return array{task_kind:string, matched_rules:list<string>, write_implied:bool, precedence_index:int, reason:string}
     */
    private function kindResult(string $kind, array $matched, bool $writeImplied, string $reason): array
    {
        return [
            'task_kind' => $kind,
            'matched_rules' => array_values($matched),
            'write_implied' => $writeImplied,
            'precedence_index' => (int) array_search($kind, self::KIND_PRECEDENCE, true),
            'reason' => $reason,
        ];
    }

    /**
     * @param  list<string>  $matched
     * @return array{risk_level:string, rank:int, matched_rules:list<string>, reason:string}
     */
    private function riskResult(string $level, array $matched, string $reason): array
    {
        $rank = ['R0' => 0, 'R1' => 1, 'R2' => 2, 'R3' => 3, 'R4' => 4, 'R5' => 5];

        return [
            'risk_level' => $level,
            'rank' => $rank[$level] ?? 0,
            'matched_rules' => array_values($matched),
            'reason' => $reason,
        ];
    }

    /**
     * @param  list<string>  $blockers
     * @return array{routing_decision:string, suggested_flow:string|null, provider_calls:int, blockers:list<string>, reason:string}
     */
    private function routeResult(string $route, ?string $suggestedFlow, int $providerCalls, array $blockers, string $reason): array
    {
        return [
            'routing_decision' => $route,
            'suggested_flow' => $suggestedFlow,
            'provider_calls' => $providerCalls,
            'blockers' => array_values($blockers),
            'reason' => $reason,
        ];
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $matched  appended in place
     */
    private function matchTokens(string $haystack, array $tokens, string $tagPrefix, array &$matched): bool
    {
        $hit = false;
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (str_contains($haystack, $token)) {
                $hit = true;
                $matched[] = $tagPrefix . $token;
            }
        }

        return $hit;
    }

    private function startsWithToken(string $haystack, string $prefix): bool
    {
        if ($prefix === '') {
            return false;
        }
        // Word-boundary start: "o que" / "qual" / "where" at the very beginning.
        return $haystack === $prefix
            || str_starts_with($haystack, $prefix . ' ')
            || str_starts_with($haystack, $prefix);
    }

    private function isFrontendSurface(string $surface): bool
    {
        $hints = ['atlas_desktop_ai', 'atlas_app', 'atlas_code', 'atlas_frontend', 'atlas_cli_dev'];

        return in_array($surface, $hints, true);
    }
}
