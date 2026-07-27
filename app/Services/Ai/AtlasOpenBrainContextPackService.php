<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\CognitiveMemory\AtlasCognitiveWorkingSetMemoryService;
use App\Services\Ai\Context\AtlasContextFeedbackSignalPolicy;
use App\Services\Ai\Context\AtlasContextRuntime;
use App\Services\Ai\Context\AtlasDeliveredPackLedger;
use App\Services\Ai\Context\AtlasFusionInjectionApplier;
use App\Services\Ai\Context\AtlasIntelligenceRolloutMode;
use App\Services\Ai\Context\AtlasRetrievalFusionService;
use App\Services\Ai\Context\EpistemicEvidenceBundleComposer;
use App\Services\Ai\Context\PackSufficiencyBlockBuilder;
use App\Services\Ai\Context\RetrievalAgendaComposer;
use App\Services\Ai\Context\SemanticContextRetrievalService;
use App\Services\Ai\Context\SpanLevelRetrievalResolver;
use App\Services\Ai\Context\TaskFacetExtractor;
use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use App\Services\Ai\Memory\AtlasMemoryUsageService;
use App\Services\Ai\OpenBrain\Support\GraphPathFilterSupport;
use App\Services\Ai\Reality\AtlasRealityGraphQueryService;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Engineering\CodeGraph\CodeGraphContextRetriever;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Illuminate\Support\Facades\DB;
use Throwable;
use App\Services\Ai\OpenBrainContextPack\Support;
use App\Services\Ai\OpenBrainContextPack\SessionWorkingSetSection;
use App\Services\Ai\OpenBrainContextPack\PackCacheSection;
use App\Services\Ai\OpenBrainContextPack\RuntimeComposeSection;
use App\Services\Ai\OpenBrainContextPack\BudgetCeilingSection;
use App\Services\Ai\OpenBrainContextPack\ContextDeliveryPolicySection;
use App\Services\Ai\OpenBrainContextPack\CodeSection;
use App\Services\Ai\OpenBrainContextPack\RealitySection;
use App\Services\Ai\OpenBrainContextPack\MemorySection;
use App\Services\Ai\OpenBrainContextPack\FeedbackRequestSection;
use App\Services\Ai\OpenBrainContextPack\RenderMarkdownSection;

/**
 * AOBG N1.F1 — the Atlas Open Brain Gateway unified context-pack front door.
 *
 * This is the SINGLE PUSH surface: the ONE provider-bound pack any external AI
 * (Claude Code / Codex / Cursor, in ANY project) calls first for "what does the
 * brain already know about this task?". It FUSES the three proven brains that
 * each already exist and are already provider-safe — it builds NO new context
 * engine, it ASSEMBLES the existing ones under one budget and one workspace:
 *
 *   1. code-graph   — {@see CodeGraphContextRetriever::packFor()} (the proven
 *      `atlas:ctx` BM25 + E-3 budgeted symbol pack, workspace-scoped).
 *   2. reality graph — {@see AtlasRealityGraphQueryService::query()} with
 *      provider_bound=true (the AURG fused graph: code+memory+domain+evidence,
 *      cross-layer paths with provenance; the surface that already enforces the
 *      structural privacy floor — sensitive domains and everything reachable
 *      only through them are excluded by construction, not post-filtered).
 *   3. semantic memory — {@see AtlasHybridMemoryRetrievalService::recall()}
 *      (pgvector recall over the provider-safe REDACTED projections only; the
 *      recall already filters by {@see AtlasMemoryPrivacyService} so nothing
 *      sensitive/secret rides out — only redacted bodies + ids/hashes).
 *
 * PROVIDER-SAFETY (non-negotiable): every byte this returns crosses to an
 * external AI, so the pack is provider-bound end to end. The AURG query is
 * called with provider_bound=true (sensitive excluded structurally); memory is
 * the recall's already-redacted projection; the code-graph pack carries symbol
 * names/signatures from the local read-model only. No raw memory bodies, no
 * sensitive/secret content, no Atlas-internal ids/traces/prompts.
 *
 * MULTI-PROJECT: the workspace is resolved ONCE — from an explicit
 * $opts['workspace'] (path or id) or the caller's $opts['cwd'] — via
 * {@see CodeGraphWorkspaceIdentity}, so a pack built for project B never leaks
 * project A's symbols. AURG nodes are not workspace-keyed the same way (the
 * brain is global by design), but its provider_bound floor still applies.
 *
 * COST: read-only, local DB only. No provider spend, no network. The AURG
 * Python graph-rank is its own opt-in runtime and degrades honestly when absent
 * (the pack never depends on it).
 *
 * HONEST DEGRADE (anti-over-claim): each of the three sections is built
 * independently and never fabricates context. Empty, filtered and retrieval
 * failures remain item-compatible (`[]`) but carry distinct provider-safe
 * provenance, so a consumer never mistakes a broken source for "nothing known".
 * The pack is a CURATED TOP-K assembly, not omniscience. This service NEVER
 * throws: context recall is best-effort, not a gate.
 */
class AtlasOpenBrainContextPackService
{
    public const SCHEMA = 'atlas.aobg.context_pack.v1';

    public const CONTEXT_DELIVERY_POLICY_SCHEMA = 'atlas.aobg.context_delivery_policy.v1';

    public const CONTEXT_FEEDBACK_REQUEST_SCHEMA = 'atlas.aobg.context_feedback_request.v1';

    public const RUNTIME_SCHEMA = 'atlas.aobg.context_pack.runtime.v1';

    public const RUNTIME_VERSION = 'aobg-context-pack-runtime-v6';

    /**
     * Provider-visible flags that let external MCP clients detect whether the
     * context-pack process is recent enough for the current AOBG behavior.
     */
    public const RUNTIME_FEATURE_FLAGS = [
        'code_graph_fill_gaps',
        'context_delivery_policy',
        'context_feedback_request',
        'context_hygiene_summary',
        'feedback_demotes_initial_context_refs',
        'initial_auxiliary_code_symbol_deferral',
        'initial_code_file_symbol_deferral',
        'initial_code_path_noise_filter',
        'initial_code_symbol_noise_filter',
        'initial_surface_symbol_deferral',
        'initial_reality_cross_layer_only',
        'memory_relevance_floor',
        'memory_section_status',
        'provider_bound_mission_seed_filter',
        'reality_doc_mission_filter',
        'retrieval_fusion_receipt',
        'same_layer_path_omission_provenance',
        'separator_term_expansion',
        'span_level_retrieval',
        'epistemic_evidence_bundle',
        'pack_section_timings',
        'test_symbol_on_demand_expansion',
        'obra_working_set_lineage',
    ];

    /**
     * Honest self-label carried in the pack so a consumer never reads it as an
     * exhaustive dump of the brain — it is the smallest useful curated slice.
     */
    public const HONESTY_LABEL = 'curated top-K (not exhaustive)';

    private readonly Support $support;

    private readonly SessionWorkingSetSection $session;

    private readonly PackCacheSection $cache;

    private readonly RuntimeComposeSection $runtime;

    private readonly BudgetCeilingSection $budget;

    private readonly ContextDeliveryPolicySection $policy;

    private readonly CodeSection $code;

    private readonly RealitySection $reality;

    private readonly MemorySection $memory_sec;

    private readonly FeedbackRequestSection $feedback;

    private readonly RenderMarkdownSection $render;

    public function __construct(
        private readonly CodeGraphContextRetriever $codeGraph,
        private readonly AtlasRealityGraphQueryService $realityGraph,
        private readonly AtlasHybridMemoryRetrievalService $memory,
        private readonly CodeGraphWorkspaceIdentity $workspaceIdentity,
        private readonly SemanticContextRetrievalService $semanticContext,
        private readonly AtlasRetrievalFusionService $fusion,
        private readonly AtlasFusionInjectionApplier $fusionApplier,
        private readonly AtlasMemoryUsageService $memoryUsage,
        private readonly AtlasContextFeedbackSignalPolicy $feedbackSignalPolicy,
        private readonly TaskFacetExtractor $taskFacetExtractor,
        private readonly PackSufficiencyBlockBuilder $packSufficiencyBlockBuilder,
        private readonly RetrievalAgendaComposer $retrievalAgendaComposer,
        private readonly SpanLevelRetrievalResolver $spanLevelRetrievalResolver,
        private readonly EpistemicEvidenceBundleComposer $epistemicEvidenceBundleComposer,
        private readonly AtlasOpenBrainMemoryProjectionSafetyGate $memoryProjectionSafetyGate,
    ) {
        $this->support = new Support;
        $this->session = new SessionWorkingSetSection($this->support, $this->packSufficiencyBlockBuilder, $this->retrievalAgendaComposer, $this->taskFacetExtractor);
        $this->cache = new PackCacheSection($this->support);
        $this->runtime = new RuntimeComposeSection($this->support, $this->memory);
        $this->budget = new BudgetCeilingSection($this->support);
        $this->policy = new ContextDeliveryPolicySection($this->support, $this->feedbackSignalPolicy);
        $this->code = new CodeSection($this->support, $this->codeGraph);
        $this->reality = new RealitySection($this->support, $this->realityGraph);
        $this->memory_sec = new MemorySection($this->support, $this->memory, $this->memoryProjectionSafetyGate, $this->memoryUsage, $this->semanticContext);
        $this->feedback = new FeedbackRequestSection($this->support);
        $this->render = new RenderMarkdownSection($this->support);
    }

    /**
     * Assemble the one provider-bound pack fusing the three brains for a task.
     *
     * @param  string  $task  the free-text task / question driving recall.
     * @param  array<string,mixed>  $opts  optional:
     *                                     - workspace: explicit workspace path OR id (wins over cwd).
     *                                     - cwd: caller's working directory, resolved to a workspace id.
     *                                     - budget: total char budget for the pack (default config aobg.budget_chars).
     *                                     - code_budget / memory_budget: per-source sub-budgets (default config).
     *                                     - changed_files: array<string> of paths the task touches (biases code recall).
     * @return array<string,mixed> the structured pack (see SCHEMA) including a
     *                             rendered markdown string under `markdown`.
     */
    public function packFor(string $task, array $opts = []): array
    {
        $latencyStartedAt = hrtime(true);
        $timingsMs = [];
        $task = trim($task);

        $workspaceId = $this->resolveWorkspaceId($opts);
        $requestedTotalBudget = $this->support->intOpt($opts, 'budget', (int) config('atlas.aobg.budget_chars', 6000));
        $totalBudget = $requestedTotalBudget;
        $codeBudget = $this->support->intOpt(
            $opts,
            'code_budget',
            (int) config('atlas.aobg.e3_symbol_budget_chars', (int) config('atlas.aobg.code_budget_chars', 2500)),
        );
        $memoryBudget = $this->support->intOpt($opts, 'memory_budget', (int) config('atlas.aobg.memory_budget_chars', 2000));
        $changedFiles = $this->support->stringList($opts['changed_files'] ?? []);
        $sessionWorkingSetScope = $this->session->sessionWorkingSetScope($opts);
        $packCache = $this->cache->packCacheContext($task, $workspaceId, $changedFiles);
        if ($sessionWorkingSetScope !== null) {
            $packCache['enabled'] = false;
        }
        if (($packCache['enabled'] ?? false) === true) {
            $cached = $this->cache->cachedPackResponse($packCache, $latencyStartedAt);
            if ($cached !== null) {
                return $cached;
            }
        }
        $contextDeliveryPolicy = $this->policy->contextDeliveryPolicy($opts);
        $sessionDemoteRefs = $this->session->sessionWorkingSetDemoteRefs($sessionWorkingSetScope);
        $opts['_demote_context_refs'] = array_values(array_unique(array_merge(
            $this->support->stringList($contextDeliveryPolicy['demote_context_refs'] ?? []),
            $sessionDemoteRefs,
            $this->support->concentrationDemoteContextRefs(),
        )));
        if ($sessionWorkingSetScope !== null) {
            $contextDeliveryPolicy['session_working_set'] = [
                'schema_version' => 'atlas.aobg.session_working_set.v1',
                'scope' => $sessionWorkingSetScope,
                'demote_ref_count' => count($sessionDemoteRefs),
                'state_path' => AtlasCognitiveWorkingSetMemoryService::sharedPath(),
            ];
        }
        $budgetMultiplier = AiValueNormalizer::finiteFloatOrNull($contextDeliveryPolicy['initial_context_budget_multiplier'] ?? null) ?? 1.0;
        if ((bool) ($contextDeliveryPolicy['applied_to_initial_budget'] ?? false) && $budgetMultiplier > 0 && $budgetMultiplier < 1.0) {
            $totalBudget = $this->budget->scaledBudget($totalBudget, $budgetMultiplier);
            $codeBudget = $this->budget->scaledBudget($codeBudget, $budgetMultiplier);
            $memoryBudget = $this->budget->scaledBudget($memoryBudget, $budgetMultiplier);
        }
        $sourceSelectionPolicy = (array) ($contextDeliveryPolicy['source_selection_policy'] ?? []);
        if ((bool) ($sourceSelectionPolicy['applied_to_initial_pack'] ?? false)) {
            $sourceMultipliers = (array) ($sourceSelectionPolicy['budget_multipliers'] ?? []);
            $codeBudget = $this->budget->scaledBudget($codeBudget, $this->support->floatMapValue($sourceMultipliers, 'code', 1.0));
            $memoryBudget = $this->budget->scaledBudget($memoryBudget, $this->support->floatMapValue($sourceMultipliers, 'memory', 1.0));
        }

        // The total budget is a real CEILING over the text sub-budgets (code +
        // memory; the reality graph is path-shaped, not char-budgeted at source).
        // When the caller's total is tighter than the sub-budget sum, scale the two
        // down proportionally so `--budget` actually bounds the pack, not just the
        // reported metadata. A generous total leaves the sub-budgets untouched.
        $subSum = $codeBudget + $memoryBudget;
        if ($totalBudget > 0 && $subSum > $totalBudget && $subSum > 0) {
            $scale = $totalBudget / $subSum;
            $codeBudget = (int) floor($codeBudget * $scale);
            $memoryBudget = (int) floor($memoryBudget * $scale);
        }

        // Each section is built INDEPENDENTLY and fail-safe: any one degrading to
        // empty never blocks the others (honest empty, never fabricated).
        $sectionStartedAt = hrtime(true);
        $code = $this->code->codeSection($task, $workspaceId, $codeBudget, $changedFiles, $opts);
        $timingsMs['code_graph'] = $this->support->elapsedMs($sectionStartedAt);

        $sectionStartedAt = hrtime(true);
        $reality = $this->reality->realitySection($task, $workspaceId);
        $timingsMs['reality_graph'] = $this->support->elapsedMs($sectionStartedAt);

        $sectionStartedAt = hrtime(true);
        $memorySection = $this->memory_sec->memorySection($task, $workspaceId, $memoryBudget, $opts);
        $timingsMs['memory'] = $this->support->elapsedMs($sectionStartedAt);
        $reality = $this->budget->applyRealitySourceSelection($reality, $sourceSelectionPolicy);
        $contextDeliveryPolicy = $this->code->mergeInitialCodeGraphDeliveryPolicy(
            $contextDeliveryPolicy,
            (array) data_get($code, 'provenance.initial_delivery_policy', []),
        );

        // FINAL TOTAL-BUDGET CEILING (anti-over-claim): the per-source sub-budgets
        // bound their OWN slices, but the code retriever budgets on signature tokens
        // while the pack also carries each symbol's id + file_path (NOT token-counted),
        // so the measured output can exceed the requested total. The total is promised
        // as a real ceiling over the assembled text — enforce it on the MEASURED pack:
        // trim trailing (lowest-ranked) entries from the largest contributing section
        // until the estimated chars fit. Each non-empty section keeps AT LEAST its top
        // hit (never starves — same contract the memory sub-budget already honours).
        [$code, $reality, $memorySection] = $this->budget->enforceTotalCeiling($totalBudget, $code, $reality, $memorySection);

        $sourcesPresent = [];
        if ($code['present']) {
            $sourcesPresent[] = 'code_graph';
        }
        if ($reality['present']) {
            $sourcesPresent[] = 'reality_graph';
        }
        if ($memorySection['present']) {
            $sourcesPresent[] = 'memory';
        }

        $pack = [
            'schema' => self::SCHEMA,
            'task' => $task,
            'workspace' => $workspaceId,
            'provider_bound' => true,
            'honesty' => self::HONESTY_LABEL,
            'code_graph' => $code['items'],
            'reality_graph_paths' => $reality['paths'],
            'memory' => $memorySection['items'],
            'context_delivery_policy' => $contextDeliveryPolicy,
            'provenance' => [
                'aobg_runtime' => self::runtimeProfile(),
                'sources_present' => $sourcesPresent,
                'code_graph' => $code['provenance'],
                'reality_graph' => $reality['provenance'],
                'memory' => $memorySection['provenance'],
            ],
            'budget' => [
                'requested_total_chars' => $requestedTotalBudget,
                'total_chars' => $totalBudget,
                'code_budget_chars' => $codeBudget,
                'memory_budget_chars' => $memoryBudget,
                'estimated_chars' => $code['chars'] + $reality['chars'] + $memorySection['chars'],
            ],
            'counts' => [
                'code_graph' => count($code['items']),
                'reality_graph_paths' => count($reality['paths']),
                'memory' => count($memorySection['items']),
            ],
            'context_hygiene' => $this->runtime->contextHygieneSummary($code, $reality, $memorySection),
        ];
        $fusionEnabled = (bool) config('atlas.aobg.fusion_enabled', false);
        $fusionMode = AtlasIntelligenceRolloutMode::resolve([
            'enabled' => $fusionEnabled,
            'mode' => (string) config('atlas.aobg.fusion_mode', AtlasIntelligenceRolloutMode::OFFLINE),
            'canary_percent' => (int) config('atlas.aobg.fusion_canary_percent', 0),
        ], [
            'workspace' => (string) ($pack['workspace'] ?? ''),
            'flow_id' => (string) ($opts['flow_id'] ?? ''),
            'actor' => 'aobg_fusion',
        ]);
        if (AtlasIntelligenceRolloutMode::shouldRecordShadow($fusionMode)) {
            $pack['retrieval_fusion'] = $this->fusion->fuse(
                $pack['code_graph'],
                $pack['memory'],
                $pack['reality_graph_paths'],
                [
                    'limit' => (int) config('atlas.aobg.fusion_limit', 12),
                    'rrf_k' => (int) config('atlas.aobg.fusion_rrf_k', 60),
                ],
            );
            $pack['retrieval_fusion']['rollout'] = AtlasIntelligenceRolloutMode::receipt($fusionMode, $fusionEnabled);
            $pack['counts']['fusion_candidates'] = count((array) data_get($pack, 'retrieval_fusion.candidates', []));
            if (AtlasIntelligenceRolloutMode::shouldExecuteLive($fusionMode)) {
                $pack = $this->fusionApplier->apply($pack);
            } else {
                $pack['retrieval_fusion']['applied_to_sections'] = false;
            }
        }

        // WO-17-T1 — "retomei e ele sabia": the resumption section for the ACTIVE obra
        // (explicit pointer, never inferred). Fail-open + only present when an obra is
        // active, so packs with no active obra are byte-identical to before.
        $pack['retomada'] = $this->runtime->retomadaSection($workspaceId, $task);
        $sessionWorkingSetLineage = $this->session->sessionWorkingSetLineage($opts, $pack);
        if ($sessionWorkingSetScope !== null && ($sessionWorkingSetLineage['obra_id'] ?? null) !== null) {
            $pack['context_delivery_policy']['session_working_set']['lineage'] = array_filter(
                $sessionWorkingSetLineage,
                static fn ($value): bool => $value !== null && $value !== '',
            );
            $pack['obra_working_set'] = $this->session->obraWorkingSetSection($sessionWorkingSetScope, $sessionWorkingSetLineage);
        }

        // WO-17-T2 — the deterministic brief ("lembra por quê e avisa antes"): present
        // only when a brief exists; STALE the moment HEAD moves past it (never silent).
        $pack['brief'] = $this->runtime->briefSection();
        $compacted = $this->session->compactedSection();
        if ($compacted !== null) {
            $pack['compacted'] = $compacted;
        }
        if ((bool) config('atlas.aobg.facet_retrieval', false)) {
            $pack['sufficiency'] = $this->session->sufficiencySection($task, $pack);
            $retrievalAgenda = $this->session->retrievalAgendaSection($task, $pack);
            if (($retrievalAgenda['present'] ?? false) === true) {
                $pack['retrieval_agenda'] = $retrievalAgenda;
                if ((bool) config('atlas.aobg.span_level_retrieval', false)) {
                    $spanLevelRetrieval = $this->spanLevelRetrievalResolver->resolve($pack);
                    if (($spanLevelRetrieval['present'] ?? false) === true) {
                        $pack['span_level_retrieval'] = $spanLevelRetrieval;
                    }
                }
                if ((bool) config('atlas.aobg.epistemic_evidence_bundle', false)) {
                    $epistemicEvidenceBundle = $this->epistemicEvidenceBundleComposer->compose($pack);
                    if (($epistemicEvidenceBundle['present'] ?? false) === true) {
                        $pack['epistemic_evidence_bundle'] = $epistemicEvidenceBundle;
                    }
                }
            }
        }

        $pack['context_pack_hash'] = $this->feedback->contextPackHash($pack);
        $pack['context_feedback_request'] = $this->feedback->contextFeedbackRequest($pack, $opts);
        $pack['generated_at'] = now()->toJSON();
        $pack['markdown'] = $this->render->renderMarkdown($pack);

        // Obra 2 / OB-01: optional AtlasContextRuntime::compose sidecar (fail-open).
        // Default OFF so AOBG CLI/MCP stay byte-identical; elite callers may opt in.
        $runtimeComposeOption = $opts['include_runtime_compose'] ?? null;
        if ($runtimeComposeOption === true
            || ($runtimeComposeOption === null && (bool) config('atlas.aobg.include_runtime_compose', false))) {
            $pack = $this->runtime->attachRuntimeCompose($pack, $task, $opts, $workspaceId);
        }

        if ((bool) config('atlas.aobg.progressive_disclosure_enabled', true)) {
            $pack['progressive_disclosure'] = $this->runtime->progressiveDisclosureManifest();
        }

        $timingsMs['total'] = $this->support->elapsedMs($latencyStartedAt);
        $pack['timings_ms'] = $timingsMs;
        $pack['cache'] = $this->cache->packCacheTelemetry($packCache, 'miss');
        $this->cache->writePackCache($packCache, $pack);

        if ((bool) config('atlas.aobg.delivered_pack_ledger.enabled', true)) {
            AtlasDeliveredPackLedger::fromConfig()->record($pack);
        }
        $this->session->recordSessionWorkingSetDelivery($sessionWorkingSetScope, $pack, $sessionWorkingSetLineage ?? []);

        $this->cache->recordLatencySample($latencyStartedAt, $pack);

        return $pack;
    }

    /** @param array<string,mixed> $opts */
    private function contextDeliveryPolicy(array $opts): array
    {
        return $this->policy->contextDeliveryPolicy($opts);
    }

    private function sourceSelectionPolicy(array $stats, int $actionableFeedbackCount): array
    {
        return $this->policy->sourceSelectionPolicy($stats, $actionableFeedbackCount);
    }

    private function refutationMatches(string $task, string $workspaceId): array
    {
        return $this->runtime->refutationMatches($task, $workspaceId);
    }

    private function formatRefutationMatch(mixed $ref): string
    {
        return $this->render->formatRefutationMatch($ref);
    }

    private function renderMarkdown(array $pack): string
    {
        return $this->render->renderMarkdown($pack);
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function compactMemoryItemForPack(array $item, int $maxChars): array
    {
        return $this->memory_sec->compactMemoryItemForPack($item, $maxChars);
    }

    /**
     * Provider-safe context-pack runtime identity for MCP stale-session checks.
     *
     * @return array<string,mixed>
     */
    public static function runtimeProfile(): array
    {
        $features = self::RUNTIME_FEATURE_FLAGS;
        sort($features);

        return [
            'schema_version' => self::RUNTIME_SCHEMA,
            'component' => self::class,
            'runtime_version' => self::RUNTIME_VERSION,
            'feature_flags' => $features,
            'runtime_fingerprint' => self::runtimeFingerprint($features),
            'stale_detection' => [
                'if_missing' => 'context_pack_runtime_missing_or_mcp_process_stale',
                'if_feature_missing' => 'restart_provider_client_or_use_cli_fallback',
                'required_probe' => 'atlas_mcp_self_check',
                'cli_fallback' => 'php artisan atlas:context-pack "<task>" --workspace="<path>" --json',
                'mcp_restart' => 'bin/atlas open-brain mcp --describe --json then restart Cursor/Claude MCP client',
            ],
            'policy' => [
                'provider_safe' => true,
                'raw_prompt_exposed' => false,
                'raw_conversation_exposed' => false,
            ],
        ];
    }


    /**
     * @param  array<int,string>  $features
     */
    private static function runtimeFingerprint(array $features): string
    {
        return GraphPathFilterSupport::runtimeFingerprint(
            $features,
            self::RUNTIME_SCHEMA,
            self::RUNTIME_VERSION,
        );
    }

    // ------------------------------------------------------------------
    // Sections — pure graph filters live on GraphPathFilterSupport (BC wrappers).
    // ------------------------------------------------------------------

    /**
     * Reality-graph node labels are UNTRUSTED display text — a label can be a verbatim past operator
     * prompt (the session-capture mission node seeds its label from the first user prompt). Collapse
     * all whitespace to a single line and hard-cap, so no multi-line / oversized raw text is ever
     * replayed into the model context through the graph section.
     */
    public static function sanitizeGraphLabel(string $raw): string
    {
        return GraphPathFilterSupport::sanitizeGraphLabel($raw);
    }

    /**
     * Session ECHO labels are old operator prompts or control markers that have no architectural
     * value as cross-layer graph paths. Empty labels are not echo: real nodes may render by id.
     */
    public static function isSessionArtifactLabel(string $label): bool
    {
        return GraphPathFilterSupport::isSessionArtifactLabel($label);
    }

    /**
     * @param  array<string,mixed>  $path
     * @param  array<int,array<string,mixed>>  $chain
     */
    public static function isSessionArtifactPath(array $path, array $chain): bool
    {
        return GraphPathFilterSupport::isSessionArtifactPath($path, $chain);
    }

    /**
     * @param  array<string,mixed>  $path
     * @param  array<int,array<string,mixed>>  $chain
     */
    public static function isDocumentationMissionPath(string $task, array $path, array $chain): bool
    {
        return GraphPathFilterSupport::isDocumentationMissionPath($task, $path, $chain);
    }

    // ------------------------------------------------------------------
    // Feedback request, rendering + helpers
    // ------------------------------------------------------------------

    /**
     * Resolve the workspace id ONCE: explicit `workspace` (path or id) wins,
     * else `cwd`, else the primary default. Fail-safe — never throws.
     *
     * @param  array<string,mixed>  $opts
     */
    private function resolveWorkspaceId(array $opts): string
    {
        try {
            $explicit = $this->support->stringOpt($opts, 'workspace');
            if ($explicit !== null) {
                // "path OR id": an existing path resolves to its id; a previously-resolved
                // id is passed through verbatim (so it scopes to the SAME graph, not an
                // empty derived one). `cwd` is always a path.
                return $this->workspaceIdentity->resolveWorkspaceOrId($explicit);
            }

            $cwd = $this->support->stringOpt($opts, 'cwd');
            if ($cwd !== null) {
                return $this->workspaceIdentity->resolve($cwd);
            }

            return $this->workspaceIdentity->default();
        } catch (Throwable) {
            try {
                return $this->workspaceIdentity->default();
            } catch (Throwable) {
                return 'atlas-server';
            }
        }
    }

}
