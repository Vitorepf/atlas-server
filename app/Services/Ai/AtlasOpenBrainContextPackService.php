<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiRagFeedbackEvent;
use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\CognitiveMemory\AtlasCognitiveWorkingSetMemoryService;
use App\Services\Ai\Context\AtlasCanonicalContextRef;
use App\Services\Ai\Context\AtlasContextFeedbackSignalPolicy;
use App\Services\Ai\Context\AtlasContextRuntime;
use App\Services\Ai\Context\AtlasDialecticTensionService;
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
use App\Services\Ai\Mcp\AtlasMcpTierService;
use App\Services\Ai\Memory\AtlasMemoryRecallConcentrationDemotion;
use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use App\Services\Ai\Memory\AtlasMemoryUsageService;
use App\Services\Ai\Obra\AtlasDeterministicBriefService;
use App\Services\Ai\Obra\AtlasObraStateService;
use App\Services\Ai\OpenBrain\AtlasAobgLatencyLedger;
use App\Services\Ai\Reality\AtlasRealityGraphQueryService;
use App\Services\Ai\SelfConstruction\Lineage\AtlasDecisionLineageLedger;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\ValueObjects\AiTaskRequest;
use App\Services\AtlasCode\WorkspaceFolderIntelligenceService;
use App\Services\Engineering\CodeGraph\CodeGraphContextRetriever;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

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
     * Code-graph symbol types that are useful, but usually too noisy for the
     * first implementation brief. They remain available through explicit pulls.
     *
     * @var array<string,string>
     */
    private const AUXILIARY_CODE_SOURCE_TYPES = [
        'test_method' => 'test_symbols',
        'doc_heading' => 'canonical_doc',
        'file' => 'code_files',
        'cli_command' => 'runtime_surfaces',
        'route' => 'runtime_surfaces',
    ];

    /**
     * Glue terms that should not make a global memory look task-relevant.
     *
     * @var array<string,true>
     */
    private const RELEVANCE_STOP_TERMS = [
        'aobg' => true, 'atlas' => true, 'context' => true, 'contexto' => true,
        'quality' => true, 'qualidade' => true, 'melhorar' => true, 'arrumar' => true,
        'implementar' => true, 'debug' => true, 'loop' => true, 'service' => true,
        'para' => true, 'com' => true, 'sem' => true, 'que' => true, 'uma' => true,
        'the' => true, 'and' => true, 'for' => true, 'with' => true,
    ];

    /**
     * Honest self-label carried in the pack so a consumer never reads it as an
     * exhaustive dump of the brain — it is the smallest useful curated slice.
     */
    public const HONESTY_LABEL = 'curated top-K (not exhaustive)';

    /** RAG-04 — per-item floor when splitting the memory sub-budget across recalls. */
    private const MEMORY_ITEM_MIN_BUDGET_CHARS = 400;

    private const MEMORY_BODY_TRUNCATION_MARKER = '… [truncated]';

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
    ) {}

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
        $requestedTotalBudget = $this->intOpt($opts, 'budget', (int) config('atlas.aobg.budget_chars', 6000));
        $totalBudget = $requestedTotalBudget;
        $codeBudget = $this->intOpt(
            $opts,
            'code_budget',
            (int) config('atlas.aobg.e3_symbol_budget_chars', (int) config('atlas.aobg.code_budget_chars', 2500)),
        );
        $memoryBudget = $this->intOpt($opts, 'memory_budget', (int) config('atlas.aobg.memory_budget_chars', 2000));
        $changedFiles = $this->stringList($opts['changed_files'] ?? []);
        $sessionWorkingSetScope = $this->sessionWorkingSetScope($opts);
        $packCache = $this->packCacheContext($task, $workspaceId, $changedFiles);
        if ($sessionWorkingSetScope !== null) {
            $packCache['enabled'] = false;
        }
        if (($packCache['enabled'] ?? false) === true) {
            $cached = $this->cachedPackResponse($packCache, $latencyStartedAt);
            if ($cached !== null) {
                return $cached;
            }
        }
        $contextDeliveryPolicy = $this->contextDeliveryPolicy($opts);
        $sessionDemoteRefs = $this->sessionWorkingSetDemoteRefs($sessionWorkingSetScope);
        $opts['_demote_context_refs'] = array_values(array_unique(array_merge(
            $this->stringList($contextDeliveryPolicy['demote_context_refs'] ?? []),
            $sessionDemoteRefs,
            $this->concentrationDemoteContextRefs(),
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
            $totalBudget = $this->scaledBudget($totalBudget, $budgetMultiplier);
            $codeBudget = $this->scaledBudget($codeBudget, $budgetMultiplier);
            $memoryBudget = $this->scaledBudget($memoryBudget, $budgetMultiplier);
        }
        $sourceSelectionPolicy = (array) ($contextDeliveryPolicy['source_selection_policy'] ?? []);
        if ((bool) ($sourceSelectionPolicy['applied_to_initial_pack'] ?? false)) {
            $sourceMultipliers = (array) ($sourceSelectionPolicy['budget_multipliers'] ?? []);
            $codeBudget = $this->scaledBudget($codeBudget, $this->floatMapValue($sourceMultipliers, 'code', 1.0));
            $memoryBudget = $this->scaledBudget($memoryBudget, $this->floatMapValue($sourceMultipliers, 'memory', 1.0));
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
        $code = $this->codeSection($task, $workspaceId, $codeBudget, $changedFiles, $opts);
        $timingsMs['code_graph'] = $this->elapsedMs($sectionStartedAt);

        $sectionStartedAt = hrtime(true);
        $reality = $this->realitySection($task, $workspaceId);
        $timingsMs['reality_graph'] = $this->elapsedMs($sectionStartedAt);

        $sectionStartedAt = hrtime(true);
        $memorySection = $this->memorySection($task, $workspaceId, $memoryBudget, $opts);
        $timingsMs['memory'] = $this->elapsedMs($sectionStartedAt);
        $reality = $this->applyRealitySourceSelection($reality, $sourceSelectionPolicy);
        $contextDeliveryPolicy = $this->mergeInitialCodeGraphDeliveryPolicy(
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
        [$code, $reality, $memorySection] = $this->enforceTotalCeiling($totalBudget, $code, $reality, $memorySection);

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
            'context_hygiene' => $this->contextHygieneSummary($code, $reality, $memorySection),
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
        $pack['retomada'] = $this->retomadaSection($workspaceId, $task);
        $sessionWorkingSetLineage = $this->sessionWorkingSetLineage($opts, $pack);
        if ($sessionWorkingSetScope !== null && ($sessionWorkingSetLineage['obra_id'] ?? null) !== null) {
            $pack['context_delivery_policy']['session_working_set']['lineage'] = array_filter(
                $sessionWorkingSetLineage,
                static fn ($value): bool => $value !== null && $value !== '',
            );
            $pack['obra_working_set'] = $this->obraWorkingSetSection($sessionWorkingSetScope, $sessionWorkingSetLineage);
        }

        // WO-17-T2 — the deterministic brief ("lembra por quê e avisa antes"): present
        // only when a brief exists; STALE the moment HEAD moves past it (never silent).
        $pack['brief'] = $this->briefSection();
        $compacted = $this->compactedSection();
        if ($compacted !== null) {
            $pack['compacted'] = $compacted;
        }
        if ((bool) config('atlas.aobg.facet_retrieval', false)) {
            $pack['sufficiency'] = $this->sufficiencySection($task, $pack);
            $retrievalAgenda = $this->retrievalAgendaSection($task, $pack);
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

        $pack['context_pack_hash'] = $this->contextPackHash($pack);
        $pack['context_feedback_request'] = $this->contextFeedbackRequest($pack, $opts);
        $pack['generated_at'] = now()->toJSON();
        $pack['markdown'] = $this->renderMarkdown($pack);

        // Obra 2 / OB-01: optional AtlasContextRuntime::compose sidecar (fail-open).
        // Default OFF so AOBG CLI/MCP stay byte-identical; elite callers may opt in.
        $runtimeComposeOption = $opts['include_runtime_compose'] ?? null;
        if ($runtimeComposeOption === true
            || ($runtimeComposeOption === null && (bool) config('atlas.aobg.include_runtime_compose', false))) {
            $pack = $this->attachRuntimeCompose($pack, $task, $opts, $workspaceId);
        }

        if ((bool) config('atlas.aobg.progressive_disclosure_enabled', true)) {
            $pack['progressive_disclosure'] = $this->progressiveDisclosureManifest();
        }

        $timingsMs['total'] = $this->elapsedMs($latencyStartedAt);
        $pack['timings_ms'] = $timingsMs;
        $pack['cache'] = $this->packCacheTelemetry($packCache, 'miss');
        $this->writePackCache($packCache, $pack);

        if ((bool) config('atlas.aobg.delivered_pack_ledger.enabled', true)) {
            AtlasDeliveredPackLedger::fromConfig()->record($pack);
        }
        $this->recordSessionWorkingSetDelivery($sessionWorkingSetScope, $pack, $sessionWorkingSetLineage ?? []);

        $this->recordLatencySample($latencyStartedAt, $pack);

        return $pack;
    }

    /** @param array<string,mixed> $opts */
    private function sessionWorkingSetScope(array $opts): ?string
    {
        $sessionId = trim((string) ($opts['session_id'] ?? data_get($opts, 'context.session_id', '')));

        return $sessionId !== '' ? 'session:'.$sessionId : null;
    }

    /**
     * @param  array<string,mixed>  $opts
     * @param  array<string,mixed>  $pack
     * @return array{schema_version:string,obra_id:?string,decision_id:?string,lineage_origin:string}
     */
    private function sessionWorkingSetLineage(array $opts, array $pack): array
    {
        $decisionId = $this->firstLineageId([
            $opts['decision_id'] ?? null,
            data_get($opts, 'context.decision_id'),
            data_get($opts, 'composed_arc.decision_id'),
            data_get($opts, 'composed_arc.source.decision_id'),
            data_get($opts, 'arc.decision_id'),
        ]);

        foreach ([
            'caller' => [$opts['obra_id'] ?? null, data_get($opts, 'context.obra_id')],
            'composed_obra_arc' => [data_get($opts, 'composed_arc.obra_id'), data_get($opts, 'arc.obra_id')],
        ] as $origin => $values) {
            $obraId = $this->firstLineageId($values);
            if ($obraId !== null) {
                return [
                    'schema_version' => 'atlas.aobg.session_working_set_lineage.v1',
                    'obra_id' => $obraId,
                    'decision_id' => $decisionId,
                    'lineage_origin' => $origin,
                ];
            }
        }

        $obraId = $this->obraIdFromDecisionLineage($decisionId);
        if ($obraId === null) {
            $obraId = $this->firstLineageId([data_get($pack, 'retomada.obra_id')]);

            return [
                'schema_version' => 'atlas.aobg.session_working_set_lineage.v1',
                'obra_id' => $obraId,
                'decision_id' => $decisionId,
                'lineage_origin' => $obraId !== null ? 'active_obra_state' : 'absent',
            ];
        }

        return [
            'schema_version' => 'atlas.aobg.session_working_set_lineage.v1',
            'obra_id' => $obraId,
            'decision_id' => $decisionId,
            'lineage_origin' => 'asi11_decision_lineage',
        ];
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstLineageId(array $values): ?string
    {
        foreach ($values as $value) {
            $id = $this->safeLineageId($value);
            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    private function safeLineageId(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $id = trim((string) $value);
        if ($id === '') {
            return null;
        }

        $id = preg_replace('/[^A-Za-z0-9._:-]/', '-', $id) ?? '';
        $id = trim($id, '-');

        return $id !== '' ? mb_substr($id, 0, 120) : null;
    }

    private function obraIdFromDecisionLineage(?string $decisionId): ?string
    {
        if ($decisionId === null) {
            return null;
        }

        try {
            $closure = app(AtlasDecisionLineageLedger::class)->closure($decisionId);

            return $this->safeLineageId($closure['obra_id'] ?? null);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $lineage
     * @return array<string,mixed>
     */
    private function obraWorkingSetSection(string $scope, array $lineage): array
    {
        $obraId = $this->safeLineageId($lineage['obra_id'] ?? null);
        if ($obraId === null) {
            return [
                'schema_version' => 'atlas.aobg.obra_working_set.v1',
                'present' => false,
                'reason' => 'obra_id_absent',
                'items' => [],
            ];
        }

        $soak = [
            'status' => 'pending_window',
            'basis' => 'real_retomadas_only',
            'synthetic_retomada_used' => false,
        ];

        try {
            $state = (new AtlasCognitiveWorkingSetMemoryService(AtlasCognitiveWorkingSetMemoryService::sharedPath()))
                ->obraWorkingSet($obraId, $scope, AtlasCognitiveWorkingSetMemoryService::MODE_PERFORMANCE, 32);

            return [
                'schema_version' => 'atlas.aobg.obra_working_set.v1',
                'present' => (bool) ($state['present'] ?? false),
                'obra_id' => $obraId,
                'session_scope' => $scope,
                'items' => (array) ($state['items'] ?? []),
                'count' => (int) ($state['count'] ?? 0),
                'source_scope_count' => (int) ($state['source_scope_count'] ?? 0),
                'lineage' => array_filter($lineage, static fn ($value): bool => $value !== null && $value !== ''),
                'soak' => $soak,
            ];
        } catch (Throwable) {
            return [
                'schema_version' => 'atlas.aobg.obra_working_set.v1',
                'present' => false,
                'obra_id' => $obraId,
                'session_scope' => $scope,
                'items' => [],
                'count' => 0,
                'source_scope_count' => 0,
                'reason' => 'working_set_unavailable',
                'soak' => $soak,
            ];
        }
    }

    /**
     * MAXC-04 — attach the honest sufficiency block to the provider pack only
     * when deterministic facet retrieval is explicitly enabled.
     *
     * @param  array<string,mixed>  $pack
     * @return array<string,mixed>
     */
    private function sufficiencySection(string $task, array $pack): array
    {
        $facetExtraction = $this->taskFacetExtractor->extract($task);

        return $this->packSufficiencyBlockBuilder->build(
            (array) ($facetExtraction['facets'] ?? []),
            (array) ($pack['code_graph'] ?? []),
            (array) ($pack['memory'] ?? []),
            (array) ($pack['reality_graph_paths'] ?? []),
        ) + [
            'schema_version' => 'atlas.aobg.pack_sufficiency.v1',
        ];
    }

    /**
     * ESP-11 — attach the epistemic retrieval agenda only when claims or unknowns
     * are present. Tasks without claims keep the pack byte-identical to MAXC-04.
     *
     * @param  array<string,mixed>  $pack
     * @return array<string,mixed>
     */
    private function retrievalAgendaSection(string $task, array $pack): array
    {
        $facetExtraction = $this->taskFacetExtractor->extract($task);

        return $this->retrievalAgendaComposer->compose($task, [
            'facets' => (array) ($facetExtraction['facets'] ?? []),
            'facet_coverage' => (array) data_get($pack, 'sufficiency.facets', []),
            'wired_into_packfor' => true,
        ]);
    }

    /** @return list<string> */
    private function sessionWorkingSetDemoteRefs(?string $scope): array
    {
        if ($scope === null) {
            return [];
        }

        try {
            $state = (new AtlasCognitiveWorkingSetMemoryService(AtlasCognitiveWorkingSetMemoryService::sharedPath()))
                ->workingSet($scope, AtlasCognitiveWorkingSetMemoryService::MODE_PERFORMANCE);

            return AtlasCanonicalContextRef::uniqueStrings(array_map(
                static fn (array $item): string => (string) ($item['content_hash'] ?? ''),
                (array) ($state['items'] ?? []),
            ));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string,mixed>  $pack
     * @param  array<string,mixed>  $lineage
     */
    private function recordSessionWorkingSetDelivery(?string $scope, array $pack, array $lineage = []): void
    {
        if ($scope === null) {
            return;
        }

        try {
            $obraId = $this->safeLineageId($lineage['obra_id'] ?? data_get($pack, 'context_delivery_policy.session_working_set.lineage.obra_id'));
            $decisionId = $this->safeLineageId($lineage['decision_id'] ?? data_get($pack, 'context_delivery_policy.session_working_set.lineage.decision_id'));
            $workingSet = new AtlasCognitiveWorkingSetMemoryService(AtlasCognitiveWorkingSetMemoryService::sharedPath());
            foreach (AtlasCanonicalContextRef::deliveredFromPack($pack) as $ref) {
                $item = [
                    'content_hash' => $ref,
                    'content' => $ref,
                    'type' => 'context_ref',
                    'scope_ref' => $scope,
                    'origin' => 'context_pack_delivery',
                    'must_keep' => false,
                    'recorded_at' => now()->toJSON(),
                    'last_used_at' => now()->toJSON(),
                ];
                if ($obraId !== null) {
                    $item['obra_id'] = $obraId;
                }
                if ($decisionId !== null) {
                    $item['decision_id'] = $decisionId;
                }

                $workingSet->track($scope, $item);
            }
        } catch (Throwable) {
            // MAXE-06 is an optimization: context delivery must fail open.
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function compactedSection(): ?array
    {
        if (! DatabaseTableAvailability::has('atlas_long_horizon_compaction_receipts')) {
            return null;
        }

        try {
            $receipt = AtlasLongHorizonCompactionReceipt::query()->latest('created_at')->first();
        } catch (Throwable) {
            return null;
        }
        if (! $receipt instanceof AtlasLongHorizonCompactionReceipt) {
            return null;
        }

        $recoveryQueries = $this->stringList($receipt->recovery_queries);

        return [
            'schema_version' => 'atlas.aobg.compaction_context.v1',
            'present' => true,
            'scope_type' => (string) $receipt->scope_type,
            'scope_id' => (string) $receipt->scope_id,
            'receipt_hash' => (string) $receipt->receipt_hash,
            'must_keep_coverage' => AiValueNormalizer::finiteFloatOrNull($receipt->must_keep_coverage),
            'context_retention_score' => AiValueNormalizer::finiteFloatOrNull($receipt->context_retention_score),
            'loss_risk' => (string) $receipt->loss_risk,
            'unresolved_loss_count' => count((array) $receipt->unresolved_loss),
            'recovery_queries' => array_slice($recoveryQueries, 0, 8),
            'workflow' => $recoveryQueries === []
                ? 'no_recovery_query_available'
                : 'run_recovery_queries_before_claiming_compacted_context_absent',
        ];
    }

    /** @param array<string,mixed> $pack */
    private function recordLatencySample(int $startedAt, array $pack): void
    {
        try {
            app(AtlasAobgLatencyLedger::class)->recordPack($this->elapsedMs($startedAt), $pack);
        } catch (Throwable) {
            // Measurement is fail-open; context delivery is the product path.
        }
    }

    private function elapsedMs(int $startedAt): float
    {
        return round(max(0, hrtime(true) - $startedAt) / 1_000_000, 3);
    }

    /**
     * @param  list<string>  $changedFiles
     * @return array<string,mixed>
     */
    private function packCacheContext(string $task, string $workspaceId, array $changedFiles): array
    {
        $queryHash = hash('sha256', $this->normalizePackCacheQuery($task));
        $corpusFingerprint = $this->corpusFingerprint($workspaceId);
        $ttl = max(1, (int) config('atlas.aobg.pack_cache.ttl_seconds', 300));
        $enabled = (bool) config('atlas.aobg.pack_cache.enabled', true) && $changedFiles === [];
        $reason = $enabled ? null : ($changedFiles === [] ? 'disabled_by_config' : 'changed_files_bypass');

        return [
            'schema_version' => 'atlas.aobg.pack_cache.v1',
            'enabled' => $enabled,
            'workspace' => $workspaceId,
            'query_hash' => $queryHash,
            'corpus_fingerprint' => $corpusFingerprint,
            'cache_key' => 'atlas:aobg:pack:v1:'.hash('sha256', $workspaceId."\n".$queryHash."\n".$corpusFingerprint),
            'ttl_seconds' => $ttl,
            'bypass_reason' => $reason,
        ];
    }

    /** @param array<string,mixed> $cache */
    private function cachedPackResponse(array $cache, int $startedAt): ?array
    {
        try {
            $cached = Cache::get((string) ($cache['cache_key'] ?? ''));
            if (! is_array($cached) || ! is_array($cached['pack'] ?? null)) {
                return null;
            }

            /** @var array<string,mixed> $pack */
            $pack = $cached['pack'];
            if (($pack['schema'] ?? null) !== self::SCHEMA || trim((string) ($pack['context_pack_hash'] ?? '')) === '') {
                return null;
            }

            $pack['generated_at'] = now()->toJSON();
            $pack['timings_ms'] = [
                'code_graph' => 0.0,
                'reality_graph' => 0.0,
                'memory' => 0.0,
                'total' => $this->elapsedMs($startedAt),
            ];
            $pack['cache'] = $this->packCacheTelemetry($cache, 'hit');

            if ((bool) config('atlas.aobg.delivered_pack_ledger.enabled', true)) {
                AtlasDeliveredPackLedger::fromConfig()->record($pack);
            }
            $this->recordLatencySample($startedAt, $pack);

            return $pack;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $cache */
    private function writePackCache(array $cache, array $pack): void
    {
        if (($cache['enabled'] ?? false) !== true) {
            return;
        }

        try {
            Cache::put(
                (string) $cache['cache_key'],
                [
                    'schema_version' => 'atlas.aobg.pack_cache_entry.v1',
                    'workspace' => (string) ($cache['workspace'] ?? ''),
                    'query_hash' => (string) ($cache['query_hash'] ?? ''),
                    'corpus_fingerprint' => (string) ($cache['corpus_fingerprint'] ?? ''),
                    'context_pack_hash' => (string) ($pack['context_pack_hash'] ?? ''),
                    'stored_at' => now()->toJSON(),
                    'pack' => $pack,
                ],
                now()->addSeconds(max(1, (int) ($cache['ttl_seconds'] ?? 300))),
            );
        } catch (Throwable) {
            // Cache is an optimization only; retrieval remains fail-open.
        }
    }

    /** @param array<string,mixed> $cache */
    private function packCacheTelemetry(array $cache, string $status): array
    {
        return [
            'schema_version' => 'atlas.aobg.pack_cache.v1',
            'status' => $status,
            'workspace' => (string) ($cache['workspace'] ?? ''),
            'query_hash' => (string) ($cache['query_hash'] ?? ''),
            'corpus_fingerprint' => (string) ($cache['corpus_fingerprint'] ?? ''),
            'cache_key_hash' => hash('sha256', (string) ($cache['cache_key'] ?? '')),
            'ttl_seconds' => (int) ($cache['ttl_seconds'] ?? 0),
            'bypass_reason' => $cache['bypass_reason'] ?? null,
        ];
    }

    private function normalizePackCacheQuery(string $task): string
    {
        $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(trim($task))) ?? trim($task);

        return $normalized;
    }

    private function corpusFingerprint(string $workspaceId): string
    {
        $payload = [
            'schema_version' => 'atlas.aobg.pack_cache_corpus_fingerprint.v1',
            'workspace' => $workspaceId,
            'code_symbols' => $this->tableFreshness('atlas_engineering_code_symbols', $workspaceId),
            'code_modules' => $this->tableFreshness('atlas_engineering_code_modules', $workspaceId),
            'memory_entries' => $this->tableFreshness('atlas_memory_entries'),
        ];

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string,mixed>
     */
    private function tableFreshness(string $table, ?string $workspaceId = null): array
    {
        if (! DatabaseTableAvailability::has($table)) {
            return ['status' => 'missing'];
        }

        try {
            $query = DB::table($table);
            if ($workspaceId !== null && DatabaseTableAvailability::hasColumn($table, 'workspace_id')) {
                $query->where('workspace_id', $workspaceId);
            }
            if (DatabaseTableAvailability::hasColumn($table, 'archived_at')) {
                $query->whereNull('archived_at');
            }

            $stats = [
                'status' => 'ok',
                'rows' => (clone $query)->count(),
            ];
            foreach (['updated_at', 'indexed_at', 'source_hash', 'content_hash'] as $column) {
                if (DatabaseTableAvailability::hasColumn($table, $column)) {
                    $stats['max_'.$column] = (string) ((clone $query)->max($column) ?? '');
                }
            }

            return $stats;
        } catch (Throwable) {
            return ['status' => 'unavailable'];
        }
    }

    /**
     * Thin adapter: attach executor-bound compose without replacing AOBG fused pack.
     *
     * @param  array<string,mixed>  $pack
     * @param  array<string,mixed>  $opts
     * @return array<string,mixed>
     */
    private function attachRuntimeCompose(array $pack, string $task, array $opts, string $workspaceId): array
    {
        try {
            $runtime = app(AtlasContextRuntime::class);
            $taskRequest = AiTaskRequest::fromInput($task, [
                'agent_slug' => 'aobg',
                'provider' => 'local',
                'source_type' => 'aobg_context_pack',
                'payload' => [
                    'workspace' => $workspaceId,
                    'changed_files' => $this->stringList($opts['changed_files'] ?? []),
                ],
            ], ['agent' => 'aobg', 'intent' => 'context_pack']);
            $contract = $runtime->compose($task, $taskRequest, [
                'workspace' => (string) ($opts['workspace'] ?? $opts['cwd'] ?? base_path()),
                'flow_id' => (string) ($opts['flow_id'] ?? 'atlas_aobg'),
                'changed_files' => $this->stringList($opts['changed_files'] ?? []),
            ]);
            $pack['runtime_compose'] = method_exists($contract, 'toArray') ? $contract->toArray() : ['schema' => 'composed'];
            $pack['runtime_compose_status'] = 'ok';
        } catch (Throwable $e) {
            $pack['runtime_compose'] = null;
            $pack['runtime_compose_status'] = 'degraded';
            $pack['runtime_compose_error'] = $e->getMessage();
        }

        return $pack;
    }

    /**
     * WO-17-T1 — the ACTIVE obra's resumption facts, or {present:false} when no obra
     * is active. Fuses two brains: the on-disk obra state (last session + drift) and
     * refutations relevant to the task (admission-time matcher, query-aware since T0.2).
     * (B2e removed a decorative long-horizon-continuity arm that had no producer and so
     * was always null.) Fail-open on every arm — resumption must never break the pack.
     *
     * @return array<string,mixed>
     */
    private function retomadaSection(string $workspaceId, string $task): array
    {
        try {
            $state = app(AtlasObraStateService::class);
            $id = $state->currentId();
            if ($id === null) {
                return ['present' => false];
            }

            $obra = $state->read($id) ?? ['obra_id' => $id];
            $sessions = array_values((array) ($obra['sessions'] ?? []));
            $last = $sessions === [] ? null : (array) end($sessions);

            $lastHead = trim((string) ($obra['head'] ?? ($last['head'] ?? '')));
            $nowHead = $state->gitHead();
            $drift = ($lastHead !== '' && $nowHead !== '' && $lastHead !== $nowHead)
                ? "main avançou desde sua última sessão (era {$lastHead}, agora {$nowHead})"
                : 'sem drift de main desde a última sessão';

            // B2e (fechamento ACOS): the obra-scoped long-horizon continuity arm was
            // DECORATIVE — no producer emits an 'obra' continuation pack, so
            // latestContinuityFor('obra', …) was always null. Removed to kill the nominal
            // lie; the on-disk obra state below already carries phase, session, drift,
            // pendencies and decisions — everything the retomada section renders.
            return [
                'present' => true,
                'obra_id' => $id,
                'phase' => $obra['phase'] ?? null,
                'last_session' => $last,
                'drift' => $drift,
                'pendencies' => array_values((array) ($obra['pendencies'] ?? [])),
                'decisions' => array_values((array) ($obra['decisions'] ?? [])),
                'refutacoes' => $this->refutationMatches($task, $workspaceId),
            ];
        } catch (Throwable) {
            return ['present' => false];
        }
    }

    /**
     * Admission-time refutation matcher: refutations relevant to the task the session
     * is about to work on ("isto já foi refutado antes"). Query-aware recall (T0.2
     * forwarded the question), refutation_memory only, capped + provider-safe.
     *
     * @return list<array<string,mixed>>
     */
    private function refutationMatches(string $task, string $workspaceId): array
    {
        if (trim($task) === '') {
            return [];
        }
        try {
            $recall = $this->memory->recall(
                $task,
                ['workspace' => $workspaceId],
                ['memory_type' => 'refutation_memory'],
                ['limit' => 3, 'requester' => 'obra_retomada', 'include_verbatim' => false, 'include_semantic' => false, 'include_compounding' => false],
            );

            $matches = [];
            foreach ((array) ($recall['recall'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $title = trim((string) ($row['title'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $strength = $this->refutationStrengthForRow($row);
                $matches[] = [
                    'title' => $title,
                    'forbidden_context' => true,
                    'refutation_strength' => $strength,
                ];
            }

            return $matches;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>|null
     */
    private function refutationStrengthForRow(array $row): ?array
    {
        if (is_array($row['refutation_strength'] ?? null)) {
            return $row['refutation_strength'];
        }

        $id = trim((string) ($row['id'] ?? ($row['source_ref_id'] ?? '')));
        if ($id === '') {
            return null;
        }

        try {
            $entry = AtlasMemoryEntry::query()->find($id);
            $strength = $entry instanceof AtlasMemoryEntry
                ? data_get($entry->metadata, 'refutation_strength')
                : null;
            if (! is_array($strength)) {
                $metadata = DB::table('atlas_memory_entries')->where('id', $id)->value('metadata');
                $decoded = is_string($metadata) ? json_decode($metadata, true) : null;
                $strength = is_array($decoded) ? data_get($decoded, 'refutation_strength') : null;
            }
        } catch (Throwable) {
            return null;
        }

        return is_array($strength) ? $strength : null;
    }

    /**
     * WO-17-T2 — the deterministic brief surface. {present:false} when none exists;
     * else a compact projection + staleness (STALE the moment HEAD moved past it, so
     * the pack shows "BRIEF STALE desde X" — never a silent stale brief). Fail-open.
     *
     * @return array<string,mixed>
     */
    private function briefSection(): array
    {
        try {
            $svc = app(AtlasDeterministicBriefService::class);
            $brief = $svc->read();
            if ($brief === null) {
                return ['present' => false];
            }
            $st = $svc->staleness($brief);

            return [
                'present' => true,
                'stale' => (bool) $st['stale'],
                'generated_at' => (string) $st['generated_at'],
                'invariants' => array_slice((array) ($brief['invariants'] ?? []), 0, 3),
                'refutations' => array_slice((array) ($brief['refutations'] ?? []), 0, 3),
                'modules' => array_slice((array) ($brief['modules'] ?? []), 0, 3),
            ];
        } catch (Throwable) {
            return ['present' => false];
        }
    }

    /**
     * @param  array<string,mixed>  $code
     * @param  array<string,mixed>  $reality
     * @param  array<string,mixed>  $memory
     * @return array<string,mixed>
     */
    private function contextHygieneSummary(array $code, array $reality, array $memory): array
    {
        $pathFiltered = (int) data_get($code, 'provenance.path_filtered_count', 0);
        $feedbackDemoted = (int) data_get($code, 'provenance.feedback_demoted_count', 0)
            + (int) data_get($memory, 'provenance.feedback_demoted_count', 0);
        $memoryFiltered = (int) data_get($memory, 'provenance.relevance_filtered_count', 0);
        $sessionEchoFiltered = (int) data_get($reality, 'provenance.session_echo_paths_omitted', 0);
        $docMissionFiltered = (int) data_get($reality, 'provenance.doc_mission_paths_omitted', 0);
        $totalCeilingTrimmed = (int) data_get($code, 'provenance.total_ceiling_trimmed_count', 0)
            + (int) data_get($reality, 'provenance.total_ceiling_trimmed_count', 0)
            + (int) data_get($memory, 'provenance.total_ceiling_trimmed_count', 0);

        return [
            'path_filtered' => $pathFiltered,
            'feedback_demoted' => $feedbackDemoted,
            'memory_relevance_filtered' => $memoryFiltered,
            'session_echo_filtered' => $sessionEchoFiltered,
            'doc_mission_filtered' => $docMissionFiltered,
            'total_ceiling_trimmed' => $totalCeilingTrimmed,
            'total_filtered' => $pathFiltered + $feedbackDemoted + $memoryFiltered + $sessionEchoFiltered + $docMissionFiltered + $totalCeilingTrimmed,
        ];
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
     * Obra 7 / OB-03: Absorcao 4 phase-1 tier manifest (discovery → context → detail).
     *
     * @return array<string,mixed>
     */
    private function progressiveDisclosureManifest(): array
    {
        try {
            $tier = app(AtlasMcpTierService::class);

            return [
                'schema_version' => 'atlas.mcp.tier.v1',
                'workflow' => 'search_brief → timeline → get_full',
                'manifest' => $tier->tierManifest(),
                'savings_estimate' => $tier->estimateSavings(5),
            ];
        } catch (Throwable) {
            return [
                'schema_version' => 'atlas.mcp.tier.v1',
                'status' => 'unavailable',
            ];
        }
    }

    /**
     * @param  array<int,string>  $features
     */
    private static function runtimeFingerprint(array $features): string
    {
        sort($features);

        return hash('sha256', (string) json_encode([
            'schema_version' => self::RUNTIME_SCHEMA,
            'runtime_version' => self::RUNTIME_VERSION,
            'feature_flags' => $features,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Enforce the total char ceiling on the MEASURED, assembled pack (not just the
     * reported metadata). Trims trailing (lowest-ranked) entries from whichever section
     * currently contributes the largest char footprint, until the summed section chars
     * fit $totalBudget. Each non-empty section retains at least its top hit (never
     * starves). When $totalBudget <= 0 (uncapped), returns the sections
     * unchanged. The trimmed sections' `chars`, `present`, and item lists are kept
     * consistent so `budget.estimated_chars` and `counts` reflect the real output.
     *
     * @param  array{present:bool, items:array<int,array<string,mixed>>, chars:int, provenance:array<string,mixed>}  $code
     * @param  array{present:bool, paths:array<int,array<string,mixed>>, chars:int, provenance:array<string,mixed>}  $reality
     * @param  array{present:bool, items:array<int,array<string,mixed>>, chars:int, provenance:array<string,mixed>}  $memory
     * @return array{0:array{present:bool, items:array<int,array<string,mixed>>, chars:int, provenance:array<string,mixed>}, 1:array{present:bool, paths:array<int,array<string,mixed>>, chars:int, provenance:array<string,mixed>}, 2:array{present:bool, items:array<int,array<string,mixed>>, chars:int, provenance:array<string,mixed>}}
     */
    private function enforceTotalCeiling(int $totalBudget, array $code, array $reality, array $memory): array
    {
        if ($totalBudget <= 0) {
            return [$code, $reality, $memory];
        }

        $trimmed = ['code' => 0, 'reality' => 0, 'memory' => 0];
        while (((int) $code['chars'] + (int) $reality['chars'] + (int) $memory['chars']) > $totalBudget) {
            $candidates = [];
            if (count($code['items']) > 1) {
                $candidates['code'] = (int) $code['chars'];
            }
            if (count($reality['paths']) > 1) {
                $candidates['reality'] = (int) $reality['chars'];
            }
            if (count($memory['items']) > 1) {
                $candidates['memory'] = (int) $memory['chars'];
            }
            if ($candidates === []) {
                break;
            }

            arsort($candidates);
            $section = (string) array_key_first($candidates);
            if ($section === 'code') {
                array_pop($code['items']);
                $trimmed['code']++;
                $code['items'] = array_values($code['items']);
                $code['chars'] = $this->codeItemsChars($code['items']);

                continue;
            }
            if ($section === 'reality') {
                array_pop($reality['paths']);
                $trimmed['reality']++;
                $reality['paths'] = array_values($reality['paths']);
                $reality['chars'] = $this->realityPathsChars($reality['paths']);

                continue;
            }

            array_pop($memory['items']);
            $trimmed['memory']++;
            $memory['items'] = array_values($memory['items']);
            $memory['chars'] = $this->memoryItemsChars($memory['items']);
        }

        $code['present'] = $code['items'] !== [];
        $reality['present'] = $reality['paths'] !== [];
        $memory['present'] = $memory['items'] !== [];
        if ($trimmed['code'] > 0) {
            $code['provenance']['total_ceiling_trimmed_count'] = $trimmed['code'];
        }
        if ($trimmed['reality'] > 0) {
            $reality['provenance']['total_ceiling_trimmed_count'] = $trimmed['reality'];
        }
        if ($trimmed['memory'] > 0) {
            $memory['provenance']['total_ceiling_trimmed_count'] = $trimmed['memory'];
        }

        return [$code, $reality, $memory];
    }

    private function scaledBudget(int $budget, float $multiplier): int
    {
        if ($budget <= 0) {
            return $budget;
        }

        return max(1, min($budget, (int) floor($budget * $multiplier)));
    }

    /**
     * @param  array<string,mixed>  $values
     */
    private function floatMapValue(array $values, string $key, float $default): float
    {
        $value = AiValueNormalizer::finiteFloatOrNull($values[$key] ?? null);
        if ($value === null) {
            return $default;
        }

        return max(0.1, min(1.0, $value));
    }

    /**
     * Build the compact delivery policy that tells external providers how much
     * context was loaded now and which source types should be expanded later.
     *
     * The only automatic effect here is a bounded initial budget shrink when
     * repeated provider-safe feedback shows low ROI or waste. Ref demotion and
     * source expansion remain explicit, provider-safe handles.
     *
     * @param  array<string,mixed>  $opts
     * @return array<string,mixed>
     */
    private function contextDeliveryPolicy(array $opts): array
    {
        $windowHours = max(1, min(720, $this->intOpt($opts, 'feedback_window_hours', 168)));
        $flowId = $this->contextFeedbackFlowId($opts);
        $base = [
            'schema_version' => self::CONTEXT_DELIVERY_POLICY_SCHEMA,
            'status' => 'inactive',
            'delivery_mode' => 'standard_minimal_top_k',
            'source' => 'none',
            'flow_id' => $flowId,
            'window_hours' => $windowHours,
            'initial_context_budget_multiplier' => 1.0,
            'applied_to_initial_budget' => false,
            'actions' => ['keep_current_pack'],
            'expand_source_types' => [],
            'deferred_source_types' => [],
            'demote_context_refs' => [],
            'on_demand_handles' => $this->expansionHandles([]),
            'source_selection_policy' => $this->sourceSelectionPolicy([], 0),
            'evidence' => [
                'feedback_event_count' => 0,
                'measured_event_count' => 0,
                'measured_count' => 0,
                'total_event_count' => 0,
                'synthetic_share' => 0.0,
                'latest_feedback_hashes' => [],
            ],
            'quality_gate_hint' => 'feedback_not_available_for_initial_pack',
            'policy' => $this->contextDeliveryPolicySafety(false),
        ];

        if (! DatabaseTableAvailability::has('ai_rag_feedback_events')) {
            return $base + [
                'status' => 'unavailable',
                'reason' => 'feedback_table_missing',
            ];
        }

        try {
            $query = AiRagFeedbackEvent::query()
                ->where('created_at', '>=', now()->subHours($windowHours))
                ->latest('created_at')
                ->limit(20);

            if ($flowId !== null) {
                $query->where('flow_id', $flowId);
            }

            /** @var Collection<int,AiRagFeedbackEvent> $events */
            $events = $query->get();
        } catch (Throwable) {
            return $base + [
                'status' => 'unavailable',
                'reason' => 'feedback_read_failed',
            ];
        }

        $totalEventCount = $events->count();
        if ($events->isEmpty()) {
            return $base + [
                'status' => 'no_data',
                'source' => $flowId !== null ? 'no_flow_feedback' : 'no_recent_feedback',
                'reason' => 'no_context_feedback_events',
                'quality_gate_hint' => 'record_atlas_context_feedback_after_provider_runs',
            ];
        }

        $rawMeasuredCount = $events
            ->filter(fn (AiRagFeedbackEvent $event): bool => $this->feedbackSignalPolicy->isMeasured($event))
            ->count();
        $events = $events
            ->filter(fn (AiRagFeedbackEvent $event): bool => $this->feedbackSignalPolicy->isMeasuredAggregateEligible($event))
            ->values();
        $eligibleCount = $events->count();
        $syntheticShare = $totalEventCount === 0 ? 0.0 : round(($totalEventCount - $eligibleCount) / $totalEventCount, 4);

        if ($events->isEmpty()) {
            $evidence = [
                'feedback_event_count' => 0,
                'measured_event_count' => 0,
                'measured_count' => 0,
                'raw_measured_event_count' => $rawMeasuredCount,
                'total_event_count' => $totalEventCount,
                'synthetic_share' => $syntheticShare,
                'latest_feedback_hashes' => [],
            ];
            if ($totalEventCount >= AtlasContextFeedbackSignalPolicy::TOTAL_EVENT_FLOOR) {
                $evidence['measured_share'] = 0.0;
            }

            return array_replace_recursive($base, [
                'status' => 'insufficient_signal',
                'source' => $flowId !== null ? 'unmeasured_flow_feedback' : 'unmeasured_recent_context_feedback',
                'reason' => 'context_feedback_events_unmeasured',
                'evidence' => $evidence,
                'quality_gate_hint' => 'record_explicit_used_refs_and_post_execution_utility_after_provider_runs',
            ]);
        }

        $roiScores = [];
        $useRatios = [];
        $wasteRatios = [];
        $sufficiencyScores = [];
        $utilityScores = [];
        $lowRoiCount = 0;
        $wasteCount = 0;
        $noiseCount = 0;
        $missedCount = 0;
        $nonPassingCount = 0;
        $actionableFeedbackCount = 0;
        $nonActionableFeedbackCount = 0;
        $missingRoiSignalCount = 0;
        $actions = [];
        $expandSourceTypes = [];
        $deferSections = [];
        $demoteContextRefs = [];
        $feedbackHashes = [];
        $sourceTypeStats = [];

        foreach ($events as $event) {
            $payload = is_array($event->payload) ? $event->payload : [];
            $roi = $this->feedbackPayloadArray($payload, 'context_roi');
            $attribution = $this->feedbackPayloadArray($payload, 'context_ref_attribution');
            $nextPolicy = $this->feedbackPayloadArray($payload, 'next_context_policy');
            $missed = $this->stringList($event->missed_required_sources ?? []);
            $hasRoiSignal = $roi !== [] || $attribution !== [];
            $hasPolicySignal = $nextPolicy !== [];
            if ($hasRoiSignal || $hasPolicySignal || $missed !== [] || (int) $event->noise_sources > 0) {
                $actionableFeedbackCount++;
            } else {
                $nonActionableFeedbackCount++;
            }
            if (! $hasRoiSignal) {
                $missingRoiSignalCount++;
            }

            $roiScore = $this->nullableFloat(data_get($roi, 'roi_score'));
            if ($roiScore !== null) {
                $roiScores[] = $roiScore;
                if ($roiScore < 0.50) {
                    $lowRoiCount++;
                }
            }

            $useRatio = $this->nullableFloat(data_get($attribution, 'use_ratio', data_get($roi, 'use_ratio')));
            if ($useRatio !== null) {
                $useRatios[] = $useRatio;
            }

            $wasteRatio = $this->nullableFloat(data_get($attribution, 'waste_ratio'));
            if ($wasteRatio !== null) {
                $wasteRatios[] = $wasteRatio;
                if ($wasteRatio >= 0.40) {
                    $wasteCount++;
                }
            }

            $sufficiency = $this->nullableFloat(data_get($roi, 'context_sufficiency', $event->context_sufficiency));
            if ($sufficiency !== null) {
                $sufficiencyScores[] = $sufficiency;
            }

            $utility = $this->nullableFloat(data_get($roi, 'post_execution_utility', $event->post_execution_utility));
            if ($utility !== null) {
                $utilityScores[] = $utility;
            }

            $noiseCount += max((int) $event->noise_sources, (int) data_get($attribution, 'noise_count', 0));
            $missedCount += count($missed);
            $expandSourceTypes = array_merge(
                $expandSourceTypes,
                $missed,
                $this->stringList(data_get($attribution, 'missing_source_types', [])),
                $this->stringList(data_get($nextPolicy, 'expand_source_types', [])),
            );

            if ($this->isNonPassingContextOutcome((string) $event->outcome_status)) {
                $nonPassingCount++;
            }

            $actions = array_merge($actions, $this->stringList(data_get($nextPolicy, 'actions', [])));
            $deferSections = array_merge($deferSections, $this->stringList(data_get($nextPolicy, 'defer_sections', [])));
            if ($this->feedbackEventAllowsDemotion($event, $payload, $attribution, $roi)) {
                $demoteContextRefs = array_merge($demoteContextRefs, $this->stringList(data_get($nextPolicy, 'demote_context_refs', [])));
            }
            $sourceTypeStats = $this->mergeSourceTypeStats($sourceTypeStats, $attribution);
            if ($utility !== null) {
                $sourceTypeStats = $this->mergeSourceUtilityStats($sourceTypeStats, $attribution, $utility);
            }
            if ((string) $event->feedback_hash !== '') {
                $feedbackHashes[] = (string) $event->feedback_hash;
            }
        }

        $observedCount = $events->count();
        $avgRoi = $this->average($roiScores);
        $avgUseRatio = $this->average($useRatios);
        $avgWasteRatio = $this->average($wasteRatios);
        $expandSourceTypes = $this->uniqueStrings($expandSourceTypes);
        $deferSections = $this->uniqueStrings($deferSections);
        $demoteContextRefs = $this->uniqueStrings($demoteContextRefs);
        $sourceSelectionPolicy = $this->sourceSelectionPolicy(
            $totalEventCount >= AtlasContextFeedbackSignalPolicy::TOTAL_EVENT_FLOOR ? $sourceTypeStats : [],
            $totalEventCount >= AtlasContextFeedbackSignalPolicy::TOTAL_EVENT_FLOOR ? $actionableFeedbackCount : 0,
        );

        if ($expandSourceTypes !== []) {
            $actions[] = 'expand_missing_source_types';
        }
        if ($demoteContextRefs !== [] || $noiseCount > 0) {
            $actions[] = 'demote_noise_context_refs';
        }
        if ((bool) ($sourceSelectionPolicy['applied_to_initial_pack'] ?? false)) {
            $actions[] = 'adjust_initial_source_mix';
        }
        if ($wasteCount > 0 || (count($useRatios) >= 2 && $avgUseRatio < 0.50) || $lowRoiCount >= 2) {
            $actions[] = 'shrink_initial_context';
        }
        if ($lowRoiCount > 0 || $nonPassingCount > 0) {
            $actions[] = 'review_context_pack';
        }

        $actions = $this->uniqueStrings($actions);
        if ($actions === []) {
            $actions = ['keep_current_pack'];
        }

        $shouldShrink = in_array('shrink_initial_context', $actions, true);
        $shouldExpand = in_array('expand_missing_source_types', $actions, true);
        $canApplyBudget = $totalEventCount >= AtlasContextFeedbackSignalPolicy::TOTAL_EVENT_FLOOR && $observedCount >= 2;
        $multiplier = $canApplyBudget
            ? match (true) {
                $shouldShrink && $shouldExpand => 0.85,
                $shouldShrink => 0.75,
                default => 1.0,
            }
        : 1.0;
        $applied = $multiplier < 1.0 && $canApplyBudget;

        $evidence = [
            'feedback_event_count' => $observedCount,
            'measured_event_count' => $observedCount,
            'measured_count' => $observedCount,
            'raw_measured_event_count' => $rawMeasuredCount,
            'total_event_count' => $totalEventCount,
            'synthetic_share' => $syntheticShare,
            'latest_feedback_hashes' => array_slice($feedbackHashes, 0, 5),
            'low_roi_count' => $lowRoiCount,
            'waste_count' => $wasteCount,
            'noise_count' => $noiseCount,
            'missed_required_source_count' => $missedCount,
            'unresolved_missed_count' => $this->unresolvedMissedCount($events),
            'non_passing_count' => $nonPassingCount,
            'actionable_feedback_count' => $actionableFeedbackCount,
            'non_actionable_feedback_count' => $nonActionableFeedbackCount,
            'missing_roi_signal_count' => $missingRoiSignalCount,
            'source_buckets' => $sourceTypeStats,
            'auto_apply_scope' => 'bounded_source_mix_only',
            'ref_repromotion_enabled' => (bool) config('atlas.aobg.repromote_specific_refs_enabled', false),
            'averages' => [
                'roi_score' => round($avgRoi, 4),
                'use_ratio' => round($avgUseRatio, 4),
                'waste_ratio' => round($avgWasteRatio, 4),
                'context_sufficiency' => round($this->average($sufficiencyScores), 2),
                'post_execution_utility' => round($this->average($utilityScores), 2),
            ],
        ];
        if ($totalEventCount >= AtlasContextFeedbackSignalPolicy::TOTAL_EVENT_FLOOR) {
            $evidence['measured_share'] = round($observedCount / max(1, $totalEventCount), 4);
        }

        return [
            'schema_version' => self::CONTEXT_DELIVERY_POLICY_SCHEMA,
            'status' => $totalEventCount < AtlasContextFeedbackSignalPolicy::TOTAL_EVENT_FLOOR
                ? 'insufficient_signal'
                : ($actions === ['keep_current_pack'] ? 'observed' : 'active'),
            'delivery_mode' => match (true) {
                $applied => 'feedback_shrunk_initial_expand_on_demand',
                $shouldExpand => 'feedback_targeted_expansion_handles',
                $actions !== ['keep_current_pack'] => 'feedback_advisory_review',
                default => 'standard_minimal_top_k',
            },
            'source' => $flowId !== null ? 'latest_flow_feedback' : 'recent_context_feedback',
            'flow_id' => $flowId,
            'window_hours' => $windowHours,
            'initial_context_budget_multiplier' => $multiplier,
            'applied_to_initial_budget' => $applied,
            'actions' => $actions,
            'expand_source_types' => $expandSourceTypes,
            'deferred_source_types' => $this->uniqueStrings(array_merge($expandSourceTypes, $deferSections)),
            'demote_context_refs' => array_slice(array_values(array_unique(array_merge(
                $demoteContextRefs,
                $this->concentrationDemoteContextRefs(),
            ))), 0, 12),
            'on_demand_handles' => $this->expansionHandles($expandSourceTypes),
            'source_selection_policy' => $sourceSelectionPolicy,
            'evidence' => $evidence,
            'quality_gate_hint' => $shouldExpand
                ? 'expand_missing_source_types_before_implementation'
                : ($applied ? 'budget_shrunk_by_feedback_keep_expansion_available' : ($actionableFeedbackCount === 0 ? 'feedback_observed_but_not_actionable_for_budget' : 'feedback_review_before_context_expansion')),
            'policy' => $this->contextDeliveryPolicySafety($applied),
        ];
    }

    private function feedbackEventMeasured(AiRagFeedbackEvent $event): bool
    {
        return $this->feedbackSignalPolicy->isMeasuredAggregateEligible($event);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function feedbackPayloadArray(array $payload, string $key): array
    {
        foreach ([$key, 'payload.'.$key, 'payload.payload.'.$key] as $path) {
            $value = data_get($payload, $path);
            if (is_array($value)) {
                return $value;
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function feedbackPayloadString(array $payload, string $key): string
    {
        foreach ([$key, 'payload.'.$key, 'payload.payload.'.$key] as $path) {
            $value = data_get($payload, $path);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $attribution
     * @param  array<string,mixed>  $roi
     */
    private function feedbackEventAllowsDemotion(AiRagFeedbackEvent $event, array $payload, array $attribution, array $roi): bool
    {
        if (! $this->feedbackEventMeasured($event)) {
            return false;
        }

        $usageBasis = strtolower(trim($this->feedbackPayloadString($payload, 'usage_basis')));
        if ($usageBasis === '') {
            $usageBasis = strtolower(trim((string) data_get($attribution, 'usage_basis', data_get($roi, 'usage_basis', ''))));
        }

        return ! $this->usageBasisIsInferred($usageBasis);
    }

    private function usageBasisIsInferred(string $usageBasis): bool
    {
        $usageBasis = strtolower(trim($usageBasis));
        if ($usageBasis === '') {
            return false;
        }

        return str_contains($usageBasis, 'inferred')
            || str_starts_with($usageBasis, 'synthetic')
            || str_starts_with($usageBasis, 'unmeasured');
    }

    /**
     * @param  array<string,array<string,int>>  $stats
     * @return array<string,mixed>
     */
    private function sourceSelectionPolicy(array $stats, int $actionableFeedbackCount): array
    {
        if ((bool) config('atlas.aobg.source_selection_ev_weighted', false)) {
            return $this->evWeightedSourceSelectionPolicy($stats, $actionableFeedbackCount);
        }

        $multipliers = [
            'code' => 1.0,
            'graph' => 1.0,
            'memory' => 1.0,
        ];
        $sourceTypes = [];
        $actions = [];

        foreach (['code', 'graph', 'memory'] as $type) {
            $row = $stats[$type] ?? ['delivered' => 0, 'used' => 0, 'unused' => 0, 'noise' => 0];
            $delivered = max(0, (int) ($row['delivered'] ?? 0));
            $used = max(0, (int) ($row['used'] ?? 0));
            $unused = max(0, (int) ($row['unused'] ?? 0));
            $noise = max(0, (int) ($row['noise'] ?? 0));
            $waste = $unused + $noise;
            $useRatio = $delivered > 0 ? round($used / $delivered, 4) : 0.0;
            $wasteRatio = $delivered > 0 ? round($waste / $delivered, 4) : 0.0;

            $multiplier = 1.0;
            $action = 'keep';
            if ($actionableFeedbackCount > 0 && $delivered >= AtlasContextFeedbackSignalPolicy::SOURCE_BUCKET_MIN_EVENTS && ($noise > 0 || ($used === 0 && $wasteRatio >= 0.50))) {
                $multiplier = 0.70;
                $action = 'reduce_initial_share';
            } elseif ($actionableFeedbackCount > 0 && $delivered >= AtlasContextFeedbackSignalPolicy::SOURCE_BUCKET_MIN_EVENTS && $wasteRatio >= 0.40 && $useRatio < 0.50) {
                $multiplier = 0.85;
                $action = 'trim_initial_share';
            } elseif ($actionableFeedbackCount > 0 && $delivered >= AtlasContextFeedbackSignalPolicy::SOURCE_BUCKET_MIN_EVENTS && $useRatio >= 0.50 && $wasteRatio < 0.40) {
                $action = 'preserve_initial_share';
            }

            $multipliers[$type] = $multiplier;
            if ($action !== 'keep') {
                $actions[] = $action.':'.$type;
            }
            $sourceTypes[$type] = [
                'delivered' => $delivered,
                'used' => $used,
                'unused' => $unused,
                'noise' => $noise,
                'use_ratio' => $useRatio,
                'waste_ratio' => $wasteRatio,
                'action' => $action,
                'budget_multiplier' => $multiplier,
                'minimum_measured_events' => AtlasContextFeedbackSignalPolicy::SOURCE_BUCKET_MIN_EVENTS,
            ];
        }

        $applied = min($multipliers) < 1.0;

        return [
            'schema_version' => 'atlas.aobg.source_selection_policy.v1',
            'status' => $applied ? 'active' : ($actionableFeedbackCount > 0 ? 'observed' : 'inactive'),
            'applied_to_initial_pack' => $applied,
            'actions' => $actions !== [] ? $actions : ['keep_source_mix'],
            'budget_multipliers' => $multipliers,
            'source_types' => $sourceTypes,
            'guardrails' => [
                'min_top_item_per_present_source' => true,
                'expansion_handles_remain_available' => true,
                'raw_text_exposed' => false,
                'auto_apply_scope' => $applied ? 'bounded_source_mix_only' : 'none',
            ],
        ];
    }

    /**
     * MAXE-07 — formula v2. Continuous source multipliers from measured expected
     * value: used_ratio * average post_execution_utility for refs actually used
     * in that source bucket. Buckets without measured signal stay neutral.
     *
     * @param  array<string,array<string,int|float>>  $stats
     * @return array<string,mixed>
     */
    private function evWeightedSourceSelectionPolicy(array $stats, int $actionableFeedbackCount): array
    {
        $multipliers = [
            'code' => 1.0,
            'graph' => 1.0,
            'memory' => 1.0,
        ];
        $sourceTypes = [];
        $actions = [];

        foreach (['code', 'graph', 'memory'] as $type) {
            $row = $stats[$type] ?? ['delivered' => 0, 'used' => 0, 'unused' => 0, 'noise' => 0];
            $delivered = max(0, (int) ($row['delivered'] ?? 0));
            $used = max(0, (int) ($row['used'] ?? 0));
            $unused = max(0, (int) ($row['unused'] ?? 0));
            $noise = max(0, (int) ($row['noise'] ?? 0));
            $utilityCount = max(0, (int) ($row['utility_count'] ?? 0));
            $utilitySum = max(0.0, AiValueNormalizer::finiteFloatOrNull($row['utility_sum'] ?? null) ?? 0.0);
            $useRatio = $delivered > 0 ? round($used / $delivered, 4) : 0.0;
            $wasteRatio = $delivered > 0 ? round(($unused + $noise) / $delivered, 4) : 0.0;
            $avgUtility = $utilityCount > 0 ? round($utilitySum / $utilityCount, 4) : null;
            $expectedValue = $avgUtility === null ? null : round($useRatio * ($avgUtility / 100), 4);

            $multiplier = 1.0;
            $action = 'keep';
            if ($actionableFeedbackCount > 0
                && $delivered >= AtlasContextFeedbackSignalPolicy::SOURCE_BUCKET_MIN_EVENTS
                && $utilityCount >= AtlasContextFeedbackSignalPolicy::SOURCE_BUCKET_MIN_EVENTS
                && $expectedValue !== null) {
                $multiplier = round(max(0.5, min(1.0, 0.5 + $expectedValue)), 4);
                $action = $multiplier < 1.0 ? 'ev_weighted_adjust_initial_share' : 'preserve_initial_share';
            } elseif ($delivered >= AtlasContextFeedbackSignalPolicy::SOURCE_BUCKET_MIN_EVENTS) {
                $action = 'insufficient_ev_signal';
            }

            $multipliers[$type] = $multiplier;
            if ($action !== 'keep') {
                $actions[] = $action.':'.$type;
            }
            $sourceTypes[$type] = [
                'delivered' => $delivered,
                'used' => $used,
                'unused' => $unused,
                'noise' => $noise,
                'use_ratio' => $useRatio,
                'waste_ratio' => $wasteRatio,
                'utility_count' => $utilityCount,
                'average_utility' => $avgUtility,
                'expected_value' => $expectedValue,
                'action' => $action,
                'budget_multiplier' => $multiplier,
                'minimum_measured_events' => AtlasContextFeedbackSignalPolicy::SOURCE_BUCKET_MIN_EVENTS,
            ];
        }

        $applied = min($multipliers) < 1.0;

        return [
            'schema_version' => 'atlas.aobg.source_selection_policy.v2',
            'formula_version' => 'atlas.aobg.source_selection_ev_weighted.v1',
            'mode' => 'ev_weighted',
            'status' => $applied ? 'active' : ($actionableFeedbackCount > 0 ? 'observed' : 'inactive'),
            'applied_to_initial_pack' => $applied,
            'actions' => $actions !== [] ? $actions : ['keep_source_mix'],
            'budget_multipliers' => $multipliers,
            'source_types' => $sourceTypes,
            'guardrails' => [
                'min_top_item_per_present_source' => true,
                'expansion_handles_remain_available' => true,
                'raw_text_exposed' => false,
                'auto_apply_scope' => $applied ? 'bounded_source_mix_only' : 'none',
                'multiplier_floor' => 0.5,
                'multiplier_ceiling' => 1.0,
            ],
        ];
    }

    /**
     * @param  array<string,array<string,int>>  $stats
     * @param  array<string,mixed>  $attribution
     * @return array<string,array<string,int>>
     */
    private function mergeSourceTypeStats(array $stats, array $attribution): array
    {
        foreach ([
            'delivered_refs' => 'delivered',
            'used_refs' => 'used',
            'unused_refs' => 'unused',
            'noise_refs' => 'noise',
        ] as $key => $bucket) {
            foreach ((array) ($attribution[$key] ?? []) as $ref) {
                if (! is_array($ref)) {
                    continue;
                }
                $type = $this->normalizedInitialSourceType((string) ($ref['source_type'] ?? $ref['ref'] ?? ''));
                if ($type === null) {
                    continue;
                }
                $stats[$type] ??= ['delivered' => 0, 'used' => 0, 'unused' => 0, 'noise' => 0];
                $stats[$type][$bucket] = ($stats[$type][$bucket] ?? 0) + 1;
            }
        }

        return $stats;
    }

    /**
     * @param  array<string,array<string,int|float>>  $stats
     * @param  array<string,mixed>  $attribution
     * @return array<string,array<string,int|float>>
     */
    private function mergeSourceUtilityStats(array $stats, array $attribution, float $utility): array
    {
        foreach ((array) ($attribution['used_refs'] ?? []) as $ref) {
            if (! is_array($ref)) {
                continue;
            }
            $type = $this->normalizedInitialSourceType((string) ($ref['source_type'] ?? $ref['ref'] ?? ''));
            if ($type === null) {
                continue;
            }
            $stats[$type] ??= ['delivered' => 0, 'used' => 0, 'unused' => 0, 'noise' => 0];
            $stats[$type]['utility_sum'] = (AiValueNormalizer::finiteFloatOrNull($stats[$type]['utility_sum'] ?? null) ?? 0.0) + $utility;
            $stats[$type]['utility_count'] = (int) ($stats[$type]['utility_count'] ?? 0) + 1;
        }

        return $stats;
    }

    /**
     * @param  Collection<int,AiRagFeedbackEvent>  $events
     */
    private function unresolvedMissedCount(Collection $events): int
    {
        $count = 0;
        foreach ($events as $event) {
            $missed = $this->stringList($event->missed_required_sources ?? []);
            if ($missed === []) {
                continue;
            }

            $resolved = $this->stringList(data_get($event->payload, 'payload.missed_resolution.resolved_source_types', []));
            foreach ($missed as $sourceType) {
                if (! in_array($sourceType, $resolved, true)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function normalizedInitialSourceType(string $value): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }
        if (str_contains($value, ':')) {
            $value = strtok($value, ':') ?: $value;
        }

        return match ($value) {
            'code', 'code_intelligence', 'context_ref', 'symbol', 'route', 'migration', 'test' => 'code',
            'graph', 'graph_retrieval', 'reality_graph', 'aurg' => 'graph',
            'memory', 'memory_signals', 'semantic', 'semantic_candidate', 'vector_retrieval', 'decision', 'technical_context' => 'memory',
            default => null,
        };
    }

    private function isNonPassingContextOutcome(string $status): bool
    {
        $status = strtolower(trim($status));
        if ($status === '' || in_array($status, ['passed', 'success', 'succeeded', 'ok', 'ready', 'completed'], true)) {
            return false;
        }

        if (in_array($status, ['ready_for_provider', 'unknown', 'observed', 'no_data'], true)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    private function contextFeedbackFlowId(array $opts): ?string
    {
        $explicit = $this->stringOpt($opts, 'flow_id');
        if ($explicit !== null) {
            return $explicit;
        }

        // Obra 7 / OPT-04: programming surfaces pass flow_id for ARFL→ACRS repromote.
        if ((bool) config('atlas.context.programming_flow_repromote_enabled', true)) {
            $programmingFlow = $this->stringOpt($opts, 'programming_flow')
                ?? $this->stringOpt($opts, 'programming_profile');
            if ($programmingFlow !== null) {
                return match ($programmingFlow) {
                    'forge' => 'atlas_forge',
                    'repair', 'debug' => 'atlas_debug',
                    'review' => 'atlas_review',
                    default => 'atlas_dev',
                };
            }
        }

        $domain = $this->stringOpt($opts, 'domain');
        $taskType = $this->stringOpt($opts, 'task_type');
        if ($domain !== null && $taskType !== null) {
            return $domain.'.'.$taskType;
        }

        return null;
    }

    /**
     * @param  array<int,string>  $sourceTypes
     * @return array<int,string>
     */
    private function expansionHandles(array $sourceTypes): array
    {
        $types = $this->uniqueStrings(array_merge($sourceTypes, [
            'code_intelligence',
            'memory_signals',
            'evidence_replay',
            'canonical_doc',
        ]));

        return array_values(array_map(
            static fn (string $sourceType): string => $sourceType === 'canonical_doc'
                ? 'recheck:canonical_doc'
                : 'expand:'.$sourceType,
            $types,
        ));
    }

    /**
     * @return array<string,mixed>
     */
    private function contextDeliveryPolicySafety(bool $budgetApplied): array
    {
        return [
            'provider_safe_only' => true,
            'raw_text_exposed' => false,
            'providers_invoked' => false,
            'writes' => false,
            'auto_apply_scope' => $budgetApplied ? 'bounded_initial_budget_only' : 'none',
            'ref_demotion_auto_applied' => false,
            'source_expansion_auto_applied' => false,
            'requires_provider_pull_for_expansion' => true,
        ];
    }

    /**
     * @param  array<int,float>  $values
     */
    private function average(array $values): float
    {
        $values = array_values(array_filter($values, static fn (float $value): bool => is_finite($value)));

        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    private function nullableFloat(mixed $value): ?float
    {
        return AiValueNormalizer::finiteFloatOrNull($value);
    }

    /**
     * @param  array<int,string>  $values
     * @return array<int,string>
     */
    private function uniqueStrings(array $values): array
    {
        return array_values(array_unique(array_values(array_filter(array_map(
            static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            $values,
        ), static fn (string $value): bool => $value !== ''))));
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     */
    private function codeItemsChars(array $items): int
    {
        $chars = 0;
        foreach ($items as $item) {
            $chars += strlen(
                (string) ($item['id'] ?? '')
                .(string) ($item['file_path'] ?? '')
                .(string) ($item['signature'] ?? ''),
            );
        }

        return $chars;
    }

    /**
     * RAG-04 — deliver N compact memory items instead of one oversized first hit.
     *
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array{0:array<int,array<string,mixed>>,1:int}
     */
    private function packMemoryItemsWithinBudget(array $candidates, int $budgetChars): array
    {
        if ($candidates === [] || $budgetChars <= 0) {
            return [[], 0];
        }

        $count = count($candidates);
        $perItemCap = max(self::MEMORY_ITEM_MIN_BUDGET_CHARS, intdiv($budgetChars, $count));
        $items = [];
        $chars = 0;

        foreach ($candidates as $candidate) {
            unset($candidate['_recall_score']); // interno ao floor — nunca servido
            unset($candidate['_incident_scope']); // internal stable-concept guard marker
            $remaining = $budgetChars - $chars;
            if ($remaining <= 0 && $items !== []) {
                break;
            }

            $cap = min($perItemCap, max($remaining, 0));
            if ($items !== [] && $cap < self::MEMORY_ITEM_MIN_BUDGET_CHARS) {
                break;
            }

            $compact = $this->compactMemoryItemForPack($candidate, $cap);
            $entryChars = $this->memoryItemRenderedChars($compact);
            if ($items !== [] && $chars + $entryChars > $budgetChars) {
                break;
            }

            $items[] = $compact;
            $chars += $entryChars;
        }

        return [$items, $chars];
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function compactMemoryItemForPack(array $item, int $maxChars): array
    {
        $title = (string) ($item['title'] ?? '');
        $summary = (string) ($item['summary'] ?? '');
        $body = (string) ($item['body'] ?? '');
        $fixedChars = strlen($title.$summary);
        $maxBodyChars = max(0, $maxChars - $fixedChars);

        if (strlen($body) <= $maxBodyChars) {
            return $item;
        }

        // MAXE-08 degradation ladder: body-inteiro → summary-inteiro (body omitted)
        // → truncated body with marker (last resort). Title+summary are only
        // sacrificed when the item cannot fit at all with body omitted.
        if ($body !== '' && $fixedChars <= $maxChars) {
            $item['body'] = '';
            $item['body_omitted'] = true;

            return $item;
        }

        $marker = self::MEMORY_BODY_TRUNCATION_MARKER;
        $markerLen = strlen($marker);
        if ($maxBodyChars <= $markerLen) {
            $item['body'] = mb_substr($body, 0, $maxBodyChars);
        } else {
            $item['body'] = mb_substr($body, 0, $maxBodyChars - $markerLen).$marker;
        }

        return $item;
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function memoryItemRenderedChars(array $item): int
    {
        return strlen(
            (string) ($item['title'] ?? '')
            .(string) ($item['summary'] ?? '')
            .(string) ($item['body'] ?? ''),
        );
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     */
    private function memoryItemsChars(array $items): int
    {
        $chars = 0;
        foreach ($items as $item) {
            $chars += strlen(
                (string) ($item['title'] ?? '')
                .(string) ($item['summary'] ?? '')
                .(string) ($item['body'] ?? ''),
            );
        }

        return $chars;
    }

    private function formatRefutationMatch(mixed $ref): string
    {
        if (! is_array($ref)) {
            return (string) $ref;
        }

        $title = trim((string) ($ref['title'] ?? ''));
        $strength = data_get($ref, 'refutation_strength.strength');
        $denominator = data_get($ref, 'refutation_strength.denominator');
        $strengthNumeric = AiValueNormalizer::finiteFloatOrNull($strength);
        $denominatorNumeric = AiValueNormalizer::finiteFloatOrNull($denominator);
        if ($strengthNumeric !== null && $denominatorNumeric !== null) {
            return sprintf('%s (refutation_strength=%.4f, denominator=%d)', $title, $strengthNumeric, (int) $denominatorNumeric);
        }

        return $title;
    }

    /**
     * @param  array<int,array<string,mixed>>  $paths
     */
    private function realityPathsChars(array $paths): int
    {
        $chars = 0;
        foreach ($paths as $path) {
            $chars += strlen((string) json_encode($path, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return $chars;
    }

    // ------------------------------------------------------------------
    // Sections — each independent + fail-safe (honest empty on any fault).
    // ------------------------------------------------------------------

    /**
     * @param  array{present:bool, paths:array<int,array<string,mixed>>, chars:int, provenance:array<string,mixed>}  $reality
     * @param  array<string,mixed>  $sourceSelectionPolicy
     * @return array{present:bool, paths:array<int,array<string,mixed>>, chars:int, provenance:array<string,mixed>}
     */
    private function applyRealitySourceSelection(array $reality, array $sourceSelectionPolicy): array
    {
        if (! (bool) ($sourceSelectionPolicy['applied_to_initial_pack'] ?? false)) {
            return $reality;
        }

        $multiplier = $this->floatMapValue((array) ($sourceSelectionPolicy['budget_multipliers'] ?? []), 'graph', 1.0);
        $paths = (array) ($reality['paths'] ?? []);
        if ($multiplier >= 1.0 || count($paths) <= 1) {
            return $reality;
        }

        $originalCount = count($paths);
        $limit = max(1, (int) floor($originalCount * $multiplier));
        if ($limit >= $originalCount) {
            return $reality;
        }

        $reality['paths'] = array_slice($paths, 0, $limit);
        $reality['chars'] = $this->realityPathsChars($reality['paths']);
        $reality['present'] = $reality['paths'] !== [];
        $reality['provenance'] = array_merge((array) ($reality['provenance'] ?? []), [
            'source_selection_applied' => 'graph',
            'source_selection_multiplier' => $multiplier,
            'source_selection_original_count' => $originalCount,
        ]);

        return $reality;
    }

    /**
     * Code-graph section via the proven BM25 + E-3 retriever (workspace-scoped).
     * The retriever's token budget is char-budget / ~4 (its ~4-chars-per-token
     * convention) so the section respects the supplied char sub-budget.
     *
     * @param  array<int,string>  $changedFiles
     * @return array{present:bool, items:array<int,array<string,mixed>>, chars:int, provenance:array<string,mixed>}
     */
    private function codeSection(string $task, string $workspaceId, int $budgetChars, array $changedFiles, array $opts = []): array
    {
        $empty = [
            'present' => false,
            'items' => [],
            'chars' => 0,
            'provenance' => ['retriever' => CodeGraphContextRetriever::SCHEMA, 'note' => self::HONESTY_LABEL],
        ];

        if ($task === '' || $budgetChars <= 0) {
            return $empty;
        }

        try {
            $tokenBudget = (int) max(0, (int) floor($budgetChars / 4));
            // AP-818 F2.5 — workspace ativo = guarda-chuva (flag ON) → recall no
            // escopo agregado: grafo do umbrella + grafos próprios dos membros.
            // Flag OFF (default) → packFor single-workspace byte-idêntico.
            $workspaceScope = $this->umbrellaContextScope($workspaceId);
            $assemblyOptions = ['fill_gaps' => true];
            $pack = count($workspaceScope) > 1
                ? $this->codeGraph->packForWorkspaces($task, $workspaceScope, $tokenBudget, $changedFiles, $assemblyOptions)
                : $this->codeGraph->packFor($task, $workspaceId, $tokenBudget, $changedFiles, $assemblyOptions);
        } catch (Throwable) {
            return $empty; // best-effort recall, never a gate
        }

        $included = is_array($pack['included'] ?? null) ? $pack['included'] : [];
        $items = [];
        $chars = 0;
        foreach ($included as $node) {
            if (! is_array($node)) {
                continue;
            }
            $signature = (string) ($node['signature'] ?? '');
            $item = [
                'id' => (string) ($node['id'] ?? ''),
                'symbol_type' => (string) ($node['symbol_type'] ?? ''),
                'file_path' => (string) ($node['file_path'] ?? ''),
                'signature' => $signature,
                'tokens' => (int) ($node['tokens'] ?? 0),
            ];
            $chars += strlen($item['id'].$item['file_path'].$signature);
            $items[] = $item;
        }
        [$items, $pathFilteredCount] = $this->filterInitialCodePathNoise($task, $items, $opts);
        [$items, $demotedCount] = $this->filterDemotedCodeItems($items, $this->stringList($opts['_demote_context_refs'] ?? []));
        $delivery = $this->initialCodeGraphDeliveryPolicy($task, $items, $opts);
        $items = $delivery['items'];
        $chars = $this->codeItemsChars($items);

        return [
            'present' => $items !== [],
            'items' => $items,
            'chars' => $chars,
            'provenance' => array_merge([
                'retriever' => CodeGraphContextRetriever::SCHEMA,
                'workspace_id' => $workspaceId,
            ], count($workspaceScope) > 1 ? [
                // F2.5: só aparece quando o escopo umbrella expandiu — flag OFF
                // mantém a proveniência byte-idêntica ao formato provado.
                'workspace_scope' => $workspaceScope,
            ] : [], [
                'token_budget' => (int) ($pack['budget'] ?? 0),
                'estimated_tokens' => (int) ($pack['estimated_tokens'] ?? 0),
                'truncated' => (bool) ($pack['truncated'] ?? false),
                'assembly_fill_gaps' => true,
                'path_filtered_count' => $pathFilteredCount,
                'feedback_demoted_count' => $demotedCount,
                'note' => self::HONESTY_LABEL,
            ], $delivery['provenance']),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @param  array<string,mixed>  $opts
     * @return array{0:array<int,array<string,mixed>>,1:int}
     */
    private function filterInitialCodePathNoise(string $task, array $items, array $opts): array
    {
        if ($items === [] || $this->boolOpt($opts, 'include_noisy_code_context', false)) {
            return [$items, 0];
        }

        $changedFiles = $this->stringList($opts['changed_files'] ?? []);
        $filtered = [];
        $removed = 0;
        foreach ($items as $item) {
            $path = (string) ($item['file_path'] ?? '');
            $noiseType = $this->initialCodeNoiseType($path, $item);
            if (
                $noiseType !== null
                && ! $this->taskAllowsNoisyCodePath($task, $noiseType)
                && ! in_array($path, $changedFiles, true)
            ) {
                $removed++;

                continue;
            }
            $filtered[] = $item;
        }

        return [$filtered, $removed];
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function initialCodeNoiseType(string $path, array $item): ?string
    {
        $path = strtolower(trim($path));
        $path = ltrim($path, './');
        if ($path === '') {
            return null;
        }
        if (str_starts_with($path, 'tools/rivals/benchmarks/') || str_contains($path, '/tools/rivals/benchmarks/')) {
            return 'benchmark_fixture';
        }
        if (str_starts_with($path, 'vendor/') || str_contains($path, '/vendor/') || str_starts_with($path, 'node_modules/') || str_contains($path, '/node_modules/')) {
            return 'vendor_dependency';
        }
        if (str_starts_with($path, 'database/migrations/') || str_contains($path, '/database/migrations/')) {
            return 'migration';
        }
        if (str_contains($path, '/generated/') || str_contains($path, 'aaeos/generated/')) {
            return 'generated';
        }

        $symbolName = ltrim((string) ($item['symbol_name'] ?? ''), '\\');
        $id = ltrim((string) ($item['id'] ?? ''), '\\');
        if (str_starts_with($symbolName, 'phpDocumentor\\') || str_starts_with($id, 'sym:phpDocumentor\\')) {
            return 'vendor_namespace_stub';
        }

        return null;
    }

    private function taskAllowsNoisyCodePath(string $task, string $noiseType): bool
    {
        $text = $this->normalizedIntentText($task);

        return match ($noiseType) {
            'benchmark_fixture' => $this->containsAny($text, ['benchmark', 'rivals', 'swe-bench', 'inspect evals', 'tau2', 'eval fixture', 'evaluation fixture']),
            'vendor_dependency' => $this->containsAny($text, ['vendor', 'composer', 'dependency', 'dependencia', 'package', 'node_modules']),
            'migration' => $this->containsAny($text, ['migration', 'migrations', 'database', 'schema', 'tabela', 'table', 'column', 'coluna']),
            'generated' => $this->containsAny($text, ['generated', 'gerado', 'gerada', 'aaeos/generated']),
            'vendor_namespace_stub' => $this->containsAny($text, ['phpdocumentor', 'docblock', 'doc block', 'selfmod', 'invariant', 'invariante']),
            default => false,
        };
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @param  array<int,string>  $demoteRefs
     * @return array{0:array<int,array<string,mixed>>,1:int}
     */
    private function filterDemotedCodeItems(array $items, array $demoteRefs): array
    {
        if ($items === [] || $demoteRefs === []) {
            return [$items, 0];
        }

        $filtered = [];
        $demoted = 0;
        foreach ($items as $item) {
            if ($this->matchesDemotedRef($this->codeItemRefs($item), $demoteRefs)) {
                $demoted++;

                continue;
            }
            $filtered[] = $item;
        }

        return [$filtered, $demoted];
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<int,string>
     */
    private function codeItemRefs(array $item): array
    {
        $id = trim((string) ($item['id'] ?? ''));
        $filePath = trim((string) ($item['file_path'] ?? ''));
        $symbol = $id;
        if (str_contains($symbol, ':')) {
            $symbol = (string) str($symbol)->afterLast(':');
        }

        return $this->uniqueStrings([
            $id,
            $filePath,
            $filePath !== '' && $symbol !== '' ? $filePath.'::'.$symbol : '',
            AtlasCanonicalContextRef::fromCodeItem($item),
        ]);
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @param  array<string,mixed>  $opts
     * @return array{items:array<int,array<string,mixed>>, provenance:array<string,mixed>}
     */
    private function initialCodeGraphDeliveryPolicy(string $task, array $items, array $opts): array
    {
        $base = [
            'schema_version' => 'atlas.aobg.initial_code_graph_delivery_policy.v1',
            'mode' => 'implementation_symbols_first',
            'original_count' => count($items),
            'retained_count' => count($items),
            'deferred_count' => 0,
            'deferred_symbol_counts' => [],
            'deferred_source_types' => [],
            'deferred_on_demand_handles' => [],
            'explicit_auxiliary_intent' => false,
            'guardrails' => [
                'min_top_item_when_auxiliary_only' => true,
                'raw_test_bodies_exposed' => false,
                'raw_docs_dumped' => false,
                'provider_safe_only' => true,
            ],
        ];

        if ($items === []) {
            return ['items' => [], 'provenance' => ['initial_delivery_policy' => array_merge($base, ['mode' => 'empty'])]];
        }

        $initialAuxiliarySourceTypes = $this->initialAuxiliarySourceTypes($task, $opts);
        if (in_array('*', $initialAuxiliarySourceTypes, true)) {
            return [
                'items' => $items,
                'provenance' => [
                    'initial_delivery_policy' => array_merge($base, [
                        'mode' => 'auxiliary_symbols_included_by_intent',
                        'explicit_auxiliary_intent' => true,
                    ]),
                ],
            ];
        }

        $retained = [];
        $deferred = [];
        foreach ($items as $item) {
            $sourceType = $this->auxiliaryCodeSourceType($item);
            if ($sourceType !== null && ! in_array($sourceType, $initialAuxiliarySourceTypes, true)) {
                $deferred[] = $item;

                continue;
            }
            $retained[] = $item;
        }

        if ($deferred === []) {
            return [
                'items' => $items,
                'provenance' => [
                    'initial_delivery_policy' => array_merge($base, $initialAuxiliarySourceTypes === [] ? [] : [
                        'mode' => 'auxiliary_symbols_included_by_intent',
                        'explicit_auxiliary_intent' => true,
                        'included_auxiliary_source_types' => $initialAuxiliarySourceTypes,
                    ]),
                ],
            ];
        }

        $keptAuxiliaryTopItem = null;
        if ($retained === []) {
            $keptAuxiliaryTopItem = array_shift($deferred);
            if (is_array($keptAuxiliaryTopItem)) {
                $retained[] = $keptAuxiliaryTopItem;
            }
        }

        $counts = [];
        $sourceTypes = [];
        $handles = [];
        foreach ($deferred as $item) {
            $symbolType = (string) ($item['symbol_type'] ?? '');
            $sourceType = $this->auxiliaryCodeSourceType($item);
            if ($sourceType === null) {
                continue;
            }
            $countKey = $symbolType !== '' ? $symbolType : $sourceType;
            $counts[$countKey] = ($counts[$countKey] ?? 0) + 1;
            $sourceTypes[] = $sourceType;
            $handles[] = $this->auxiliaryCodeHandle($sourceType);
        }

        return [
            'items' => array_values($retained),
            'provenance' => [
                'initial_delivery_policy' => array_merge($base, [
                    'mode' => $counts === [] ? 'auxiliary_only_min_top_item' : 'auxiliary_symbols_deferred',
                    'retained_count' => count($retained),
                    'deferred_count' => array_sum($counts),
                    'deferred_symbol_counts' => $counts,
                    'deferred_source_types' => $this->uniqueStrings($sourceTypes),
                    'deferred_on_demand_handles' => $this->uniqueStrings($handles),
                    'explicit_auxiliary_intent' => $initialAuxiliarySourceTypes !== [],
                    'included_auxiliary_source_types' => $initialAuxiliarySourceTypes,
                    'kept_auxiliary_top_item_type' => is_array($keptAuxiliaryTopItem)
                        ? (string) ($keptAuxiliaryTopItem['symbol_type'] ?? '')
                        : null,
                ]),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function auxiliaryCodeSourceType(array $item): ?string
    {
        $symbolType = (string) ($item['symbol_type'] ?? '');
        if (isset(self::AUXILIARY_CODE_SOURCE_TYPES[$symbolType])) {
            return self::AUXILIARY_CODE_SOURCE_TYPES[$symbolType];
        }

        $filePath = strtolower((string) ($item['file_path'] ?? ''));
        if ($filePath === '') {
            return null;
        }
        if (str_starts_with($filePath, 'tests/') || str_contains($filePath, '/tests/')) {
            return 'test_symbols';
        }
        if (str_starts_with($filePath, 'docs/') || str_contains($filePath, '/docs/')) {
            return 'canonical_doc';
        }
        if (
            str_starts_with($filePath, 'app/console/commands/')
            || str_starts_with($filePath, 'app/http/controllers/')
            || str_starts_with($filePath, 'app/http/requests/')
        ) {
            return 'runtime_surfaces';
        }

        return null;
    }

    private function auxiliaryCodeHandle(string $sourceType): string
    {
        return match ($sourceType) {
            'test_symbols' => 'expand:test_symbols',
            'canonical_doc' => 'recheck:canonical_doc',
            default => 'expand:code_intelligence',
        };
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $initialPolicy
     * @return array<string,mixed>
     */
    private function mergeInitialCodeGraphDeliveryPolicy(array $policy, array $initialPolicy): array
    {
        $deferredCount = (int) ($initialPolicy['deferred_count'] ?? 0);
        if ($deferredCount <= 0) {
            return $policy;
        }

        $handles = $this->stringList($initialPolicy['deferred_on_demand_handles'] ?? []);
        $sourceTypes = $this->stringList($initialPolicy['deferred_source_types'] ?? []);
        if ($handles === [] && $sourceTypes === []) {
            return $policy;
        }

        $actions = $this->uniqueStrings(array_merge(
            $this->stringList($policy['actions'] ?? []),
            ['defer_auxiliary_code_symbols'],
        ));
        $policy['actions'] = $actions !== [] ? $actions : ['defer_auxiliary_code_symbols'];
        $policy['status'] = 'active';

        $mode = (string) ($policy['delivery_mode'] ?? 'standard_minimal_top_k');
        if (! str_starts_with($mode, 'feedback_')) {
            $policy['delivery_mode'] = 'initial_code_symbols_first_expand_on_demand';
        }

        $source = (string) ($policy['source'] ?? 'none');
        $policy['source'] = in_array($source, ['none', 'no_recent_feedback', 'no_flow_feedback'], true)
            ? 'initial_code_graph_delivery_policy'
            : (str_contains($source, 'initial_code_graph_delivery_policy')
                ? $source
                : $source.'+initial_code_graph_delivery_policy');

        $policy['deferred_source_types'] = $this->uniqueStrings(array_merge(
            $this->stringList($policy['deferred_source_types'] ?? []),
            $sourceTypes,
        ));
        $policy['expand_source_types'] = $this->uniqueStrings(array_merge(
            $this->stringList($policy['expand_source_types'] ?? []),
            $sourceTypes,
        ));
        $policy['on_demand_handles'] = $this->uniqueStrings(array_merge(
            $this->stringList($policy['on_demand_handles'] ?? []),
            $handles,
        ));
        $policy['initial_code_graph_delivery_policy'] = [
            'schema_version' => (string) ($initialPolicy['schema_version'] ?? 'atlas.aobg.initial_code_graph_delivery_policy.v1'),
            'mode' => (string) ($initialPolicy['mode'] ?? 'auxiliary_symbols_deferred'),
            'original_count' => (int) ($initialPolicy['original_count'] ?? 0),
            'retained_count' => (int) ($initialPolicy['retained_count'] ?? 0),
            'deferred_count' => $deferredCount,
            'deferred_symbol_counts' => (array) ($initialPolicy['deferred_symbol_counts'] ?? []),
            'deferred_source_types' => $sourceTypes,
            'deferred_on_demand_handles' => $handles,
        ];
        $policy['quality_gate_hint'] = 'expand_deferred_auxiliary_code_symbols_when_task_requires_them';

        if (is_array($policy['policy'] ?? null)) {
            $policy['policy']['requires_provider_pull_for_expansion'] = true;
            $policy['policy']['source_expansion_auto_applied'] = false;
        }

        return $policy;
    }

    /**
     * @param  array<string,mixed>  $opts
     * @return array<int,string>
     */
    private function initialAuxiliarySourceTypes(string $task, array $opts): array
    {
        if ($this->boolOpt($opts, 'include_auxiliary_code_symbols', false)) {
            return ['*'];
        }

        $sourceTypes = [];
        $taskType = strtolower((string) ($this->stringOpt($opts, 'task_type') ?? ''));
        if (in_array($taskType, ['test', 'tests', 'qa', 'coverage'], true)) {
            $sourceTypes[] = 'test_symbols';
        }

        foreach ($this->stringList($opts['changed_files'] ?? []) as $path) {
            $path = strtolower($path);
            if (str_starts_with($path, 'tests/') || str_contains($path, '/tests/')) {
                $sourceTypes[] = 'test_symbols';
            }
            if (str_starts_with($path, 'docs/') || str_contains($path, '/docs/')) {
                $sourceTypes[] = 'canonical_doc';
            }
        }

        $text = $this->normalizedIntentText($task);
        foreach ([
            'defer',
            'deferir',
            'adiar',
            'sob demanda',
            'on demand',
            'expandir sob demanda',
            'nao trazer testes',
            'nao trazer docs',
            'sem testes no inicial',
            'sem docs no inicial',
        ] as $deferTerm) {
            if (str_contains($text, $deferTerm)) {
                return [];
            }
        }

        $patternsBySourceType = [
            'test_symbols' => [
                '/\b(find|list|listar|quais|which|mapear|impact|impacto|rodar|run|corrigir|fix|failing|falhando)\b.*\b(test|tests|teste|testes|spec|coverage|cobertura)\b/',
                '/\b(test|tests|teste|testes|coverage|cobertura)\b.*\b(impact|impacto|falhando|failing|rodar|run|corrigir|fix|listar|list)\b/',
            ],
            'canonical_doc' => [
                '/\b(find|list|listar|ler|read|quais|which|auditar|review|revisar|mapear)\b.*\b(doc|docs|documentacao|canonical doc)\b/',
            ],
            'runtime_surfaces' => [
                '/\b(cli|artisan|command|commands|comando|comandos|route|routes|rota|rotas|api|endpoint|endpoints)\b/',
            ],
        ];
        foreach ($patternsBySourceType as $sourceType => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    $sourceTypes[] = $sourceType;
                    break;
                }
            }
        }

        return $this->uniqueStrings($sourceTypes);
    }

    private function normalizedIntentText(string $value): string
    {
        $value = mb_strtolower($value);
        $value = strtr($value, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e',
            'í' => 'i',
            'ó' => 'o', 'õ' => 'o', 'ô' => 'o',
            'ú' => 'u',
            'ç' => 'c',
            'ñ' => 'n',
        ]);

        return preg_replace('/\s+/', ' ', trim($value)) ?: '';
    }

    /**
     * Reality-graph (AURG) section — provider_bound is FORCED true: this output
     * crosses to an external AI, so sensitive domains (and anything reachable
     * only through them) are structurally excluded by the query itself.
     *
     * Returns the cross-layer paths with provenance + a compact node label map
     * (so a path's node ids are readable) — never raw source payloads.
     *
     * @return array{present:bool, paths:array<int,array<string,mixed>>, chars:int, provenance:array<string,mixed>}
     */
    private function realitySection(string $task, string $workspaceId): array
    {
        $empty = [
            'present' => false,
            'paths' => [],
            'chars' => 0,
            'provenance' => ['provider_bound' => true, 'note' => self::HONESTY_LABEL],
        ];

        if ($task === '' || ! (bool) config('atlas.aurg.enabled', true)) {
            return $empty;
        }

        try {
            // provider_bound is non-relaxable here: gateway output is external.
            $result = $this->realityGraph->query($task, [
                'provider_bound' => true,
                'workspace_id' => $workspaceId,
            ]);
        } catch (Throwable) {
            return $empty;
        }

        $labelById = [];
        foreach ((array) ($result['nodes'] ?? []) as $node) {
            if (is_array($node) && isset($node['id'])) {
                $labelById[(string) $node['id']] = [
                    'label' => (string) ($node['label'] ?? ''),
                    'source_kind' => (string) ($node['source_kind'] ?? ''),
                    'origin' => (string) data_get($node, 'meta.origin', ''),
                ];
            }
        }

        $paths = [];
        $chars = 0;
        $rawPathCount = 0;
        $sameLayerPathCount = 0;
        $sessionEchoPathCount = 0;
        $docMissionPathCount = 0;
        foreach ((array) ($result['paths'] ?? []) as $path) {
            if (! is_array($path)) {
                continue;
            }
            $rawPathCount++;
            if (! (bool) ($path['cross_layer'] ?? false)) {
                $sameLayerPathCount++;

                continue;
            }
            $nodeIds = array_map('strval', (array) ($path['nodes'] ?? []));
            $chain = [];
            foreach ($nodeIds as $nodeId) {
                // Graph labels are UNTRUSTED display text — a node label can be a past operator
                // prompt. Neutralize before it ever reaches the model context.
                $label = self::sanitizeGraphLabel((string) ($labelById[$nodeId]['label'] ?? ''));
                $chain[] = [
                    'id' => $nodeId,
                    'label' => $label,
                    'source_kind' => $labelById[$nodeId]['source_kind'] ?? '',
                    'origin' => $labelById[$nodeId]['origin'] ?? '',
                ];
            }
            // A path that runs into a session-capture artifact (a raw past-prompt / interrupted
            // marker) is session ECHO, not an architectural cross-layer path. Dropping it stops the
            // brain from replaying old operator prompts — some instruction-shaped — back into context.
            if (self::isSessionArtifactPath($path, $chain)) {
                $sessionEchoPathCount++;

                continue;
            }
            if (self::isDocumentationMissionPath($task, $path, $chain)) {
                $docMissionPathCount++;

                continue;
            }
            $entry = [
                'target' => (string) ($path['target'] ?? ''),
                'seed' => (string) ($path['seed'] ?? ''),
                'depth' => (int) ($path['depth'] ?? 0),
                'cross_layer' => (bool) ($path['cross_layer'] ?? false),
                'chain' => $chain,
                'hops' => $this->normalizeHops($path['hops'] ?? []),
            ];
            $chars += strlen((string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $paths[] = $entry;
        }

        return [
            'present' => $paths !== [],
            'paths' => $paths,
            'chars' => $chars,
            'provenance' => [
                'provider_bound' => (bool) ($result['provider_bound'] ?? true),
                'workspace_id' => (string) ($result['workspace_id'] ?? $workspaceId),
                'ranking' => (string) ($result['ranking'] ?? ''),
                'seeds' => count((array) ($result['seeds'] ?? [])),
                'nodes' => count((array) ($result['nodes'] ?? [])),
                'raw_paths' => $rawPathCount,
                'same_layer_paths_omitted' => $sameLayerPathCount,
                'session_echo_paths_omitted' => $sessionEchoPathCount,
                'doc_mission_paths_omitted' => $docMissionPathCount,
                'cross_layer_paths' => (int) data_get($result, 'counts.cross_layer_paths', 0),
                'note' => self::HONESTY_LABEL,
            ],
        ];
    }

    /** Hard cap for an untrusted reality-graph node label rendered into the model context. */
    private const GRAPH_LABEL_MAX_CHARS = 160;

    /**
     * Reality-graph node labels are UNTRUSTED display text — a label can be a verbatim past operator
     * prompt (the session-capture mission node seeds its label from the first user prompt). Collapse
     * all whitespace to a single line and hard-cap, so no multi-line / oversized raw text is ever
     * replayed into the model context through the graph section.
     */
    public static function sanitizeGraphLabel(string $raw): string
    {
        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $raw));

        return mb_substr($collapsed, 0, self::GRAPH_LABEL_MAX_CHARS);
    }

    /**
     * Session ECHO labels are old operator prompts or control markers that have no architectural
     * value as cross-layer graph paths. Empty labels are not echo: real nodes may render by id.
     */
    public static function isSessionArtifactLabel(string $label): bool
    {
        $label = mb_strtolower(trim($label));
        if ($label === '') {
            return false;
        }
        if (in_array($label, ['session capture', '[request interrupted by user]', '[request interrupted by user for tool use]', 'continue from where you left off.'], true)) {
            return true;
        }

        foreach ([
            'você é um', 'voce e um', 'vc é um', 'vc e um',
            'que merda', 'xingando',
            'você não', 'voce nao', 'vc não', 'vc nao', 'não entendeu', 'nao entendeu',
            'me confirma', 'me fala mais', 'faça uma', 'faca uma', 'precisamos fazer',
            'vc pode', 'você pode', 'voce pode', 'preciso que',
            'o que eu quero', 'tem um codex rodando',
            'pelo o que entendi', 'basicamente pegar uma area', 'evoluir ela',
            'my request for codex', 'continue from where you left off',
        ] as $marker) {
            if (str_contains($label, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $path
     * @param  array<int,array<string,mixed>>  $chain
     */
    public static function isSessionArtifactPath(array $path, array $chain): bool
    {
        foreach (['target', 'seed'] as $field) {
            if (self::isSessionArtifactLabel((string) ($path[$field] ?? ''))) {
                return true;
            }
        }

        foreach ($chain as $node) {
            // PROVENANCE beats heuristics: a mission minted by the AOBG write-back
            // (session capture) carries meta.origin — its label is raw session text,
            // never an operator decision, regardless of what the text looks like.
            if (($node['origin'] ?? '') === AtlasOpenBrainWriteBackService::MISSION_ORIGIN_SESSION_CAPTURE) {
                return true;
            }
            if (self::isSessionArtifactLabel((string) ($node['label'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $path
     * @param  array<int,array<string,mixed>>  $chain
     */
    public static function isDocumentationMissionPath(string $task, array $path, array $chain): bool
    {
        if (preg_match('/\b(doc|docs|document|documentation|backlog|kb|knowledge|canonical|canonica|canônica)\b/iu', $task) === 1) {
            return false;
        }

        foreach ($chain as $node) {
            if (($node['source_kind'] ?? null) !== 'mission') {
                continue;
            }

            $text = mb_strtolower((string) ($node['label'] ?? '').' '.(string) ($path['target'] ?? ''));
            if (preg_match('/\b(doc|docs|document|documentation|backlog|knowledge|canonical|canonica|canônica)\b/iu', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Semantic memory section — the hybrid recall returns provider-safe REDACTED
     * projections only (it filters by AtlasMemoryPrivacyService and the verbatim
     * external_ai_allowed flag). We surface the redacted titles/summaries/bodies
     * + ids/hashes, never raw bodies, and trim to the char sub-budget.
     *
     * @return array{present:bool, items:array<int,array<string,mixed>>, chars:int, provenance:array<string,mixed>}
     */
    private function memorySection(string $task, string $workspaceId, int $budgetChars, array $opts = []): array
    {
        $empty = [
            'present' => false,
            'items' => [],
            'chars' => 0,
            'provenance' => [
                'policy' => 'provider_safe_only',
                'status' => 'empty',
                'status_reason' => 'no_candidates',
                'note' => self::HONESTY_LABEL,
            ],
        ];

        if ($task === '' || $budgetChars <= 0) {
            $empty['provenance']['status_reason'] = $task === '' ? 'blank_task' : 'budget_zero';

            return $empty;
        }

        try {
            $recall = $this->memory->recall(
                $task,
                ['workspace' => $workspaceId],
                [],
                [
                    'budget_chars' => $budgetChars,
                    'requester' => 'atlas_context_pack',
                    'record_usage' => false,
                ],
            );
        } catch (Throwable) {
            $empty['provenance']['status'] = 'retrieval_error';
            $empty['provenance']['status_reason'] = 'memory_recall_exception';

            return $empty;
        }

        $candidates = [];
        foreach ((array) ($recall['recall'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = (string) ($row['title'] ?? '');
            $summary = (string) ($row['summary'] ?? '');
            $body = (string) ($row['body'] ?? ($row['snippet'] ?? ($row['excerpt'] ?? '')));
            $lineage = is_array($row['lineage'] ?? null) ? $row['lineage'] : [];
            $freshness = is_array($row['freshness'] ?? null) ? $row['freshness'] : [];
            $candidates[] = [
                // Interno ao floor (removido antes de servir): score do ranker híbrido —
                // é o que autoriza o rank-escape do floor lexical (P0 do pack).
                '_recall_score' => AiValueNormalizer::finiteFloatOrNull($row['score'] ?? null) ?? 0.0,
                // RAG-03: stable concept guard input, never rendered into the pack.
                '_incident_scope' => (string) data_get($row, 'metadata.incident_scope', ''),
                // T4-S5: the recalled entry id (provider-safe provenance) so the dialectic
                // engine can look up OPEN conflict relations among the delivered memories.
                'id' => (string) ($row['source_ref_id'] ?? ($row['id'] ?? '')),
                'type' => (string) ($row['type'] ?? ''),
                'scope' => (string) ($row['scope'] ?? ''),
                'title' => $title,
                'summary' => $summary,
                'body' => $body,
                'privacy_class' => (string) ($row['privacy_class'] ?? ''),
                // ids/hashes only — provenance the consumer can audit, no raw content.
                'source_type' => (string) ($row['source_type'] ?? data_get($lineage, 'origin_type', $row['source'] ?? '')),
                'content_hash' => (string) ($row['content_hash'] ?? data_get($lineage, 'content_hash', data_get($row, 'audit_trail.content_hash', ''))),
                'recorded_at' => (string) ($row['recorded_at'] ?? data_get($freshness, 'recorded_at', '')),
                'provider_projection' => is_array($row['provider_projection'] ?? null) ? $row['provider_projection'] : [],
            ];
        }
        $this->recordPackMemoryPreFilterUsage($task, $workspaceId, $recall);
        [$candidates, $demotedCount] = $this->filterDemotedMemoryItems($candidates, $this->stringList($opts['_demote_context_refs'] ?? []));
        [$candidates, $relevanceFilteredCount] = $this->filterLowRelevanceMemoryItems($task, $candidates);
        [$candidates, $projectionSafetyBlockedCount] = $this->filterProviderSafeMemoryCandidates($candidates);

        // L3-6: optional semantic re-rank over the recalled items (symbols+docs) via
        // the REAL local embedding engine. Flag-gated (atlas.aobg.semantic_retrieval,
        // default OFF) and fail-open — on any miss the lexical recall order stands.
        [$candidates, $memoryMode] = $this->semanticallyReorderMemory($task, $candidates);

        [$items, $chars] = $this->packMemoryItemsWithinBudget($candidates, $budgetChars);
        $this->recordPackMemoryDeliveryUsage($task, $workspaceId, $recall, $items);
        $recalledCount = (int) data_get($recall, 'summary.recall_count', count($items));
        $status = $items !== []
            ? 'ready'
            : ($recalledCount > 0 ? 'filtered' : 'empty');
        $statusReason = match ($status) {
            'ready' => 'items_delivered',
            'filtered' => 'provider_safe_relevance_policy',
            default => 'no_candidates',
        };

        return [
            'present' => $items !== [],
            'items' => $items,
            'chars' => $chars,
            'provenance' => [
                'policy' => (string) data_get($recall, 'summary.policy', 'provider_safe_only'),
                'status' => $status,
                'status_reason' => $statusReason,
                'recall_count' => $recalledCount,
                'redacted_ref_count' => (int) data_get($recall, 'summary.redacted_ref_count', 0),
                'retrieval_mode' => $memoryMode,
                'feedback_demoted_count' => $demotedCount,
                'relevance_filtered_count' => $relevanceFilteredCount,
                'projection_safety_blocked_count' => $projectionSafetyBlockedCount,
                'note' => self::HONESTY_LABEL,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array{0:array<int,array<string,mixed>>,1:int}
     */
    private function filterProviderSafeMemoryCandidates(array $candidates): array
    {
        if ($candidates === []) {
            return [[], 0];
        }

        $kept = [];
        $blocked = 0;
        foreach ($candidates as $candidate) {
            $safeText = trim((string) data_get($candidate, 'provider_projection.safe_text', ''));
            $classification = data_get($candidate, 'provider_projection.classification');
            $gate = $this->memoryProjectionSafetyGate->evaluate([
                'summary' => (string) ($candidate['summary'] ?? ''),
                'excerpt' => (string) ($candidate['body'] ?? ''),
                'title' => (string) ($candidate['title'] ?? ''),
                'source' => (string) ($candidate['source_type'] ?? ($candidate['id'] ?? '')),
                'freshness' => (string) ($candidate['content_hash'] ?? ''),
                'recorded_at' => (string) ($candidate['recorded_at'] ?? ''),
                'safe_text' => $safeText,
                'classification' => $classification,
            ]);
            if (($gate['accepted'] ?? false) !== true) {
                $blocked++;

                continue;
            }
            if ($safeText !== '' && ! empty($classification)) {
                $candidate['title'] = 'sanitized:'.$this->classificationLabel($classification);
                $candidate['summary'] = $safeText;
                $candidate['body'] = '';
            }
            $kept[] = $candidate;
        }

        return [$kept, $blocked];
    }

    private function classificationLabel(mixed $classification): string
    {
        if (is_scalar($classification)) {
            $label = trim((string) $classification);

            return $label !== '' ? Str::limit($label, 80, '') : 'memory_projection';
        }

        return 'memory_projection';
    }

    /**
     * RAG-01 — persist ranking signal before pack filters (dominance sensor feed).
     *
     * @param  array<string,mixed>  $recall
     */
    private function recordPackMemoryPreFilterUsage(string $task, string $workspaceId, array $recall): void
    {
        $rows = $this->recallRowsForUsage($recall);
        if ($rows === []) {
            return;
        }

        $this->memoryUsage->recordRecallUsages($task, ['workspace' => $workspaceId], $rows, [
            'source' => 'atlas_context_pack',
            'usage_source_type' => AtlasMemoryUsageService::SOURCE_TYPE_RECALLED_PRE_FILTER,
            'delivery_surface' => AtlasMemoryUsageService::DELIVERY_SURFACE_CONTEXT_PACK,
            'created_by' => 'atlas_context_pack_pre_filter',
        ]);
    }

    /**
     * RAG-01 — record delivery-point usage only for memories actually served.
     *
     * @param  array<string,mixed>  $recall
     * @param  array<int,array<string,mixed>>  $deliveredItems
     */
    private function recordPackMemoryDeliveryUsage(string $task, string $workspaceId, array $recall, array $deliveredItems): void
    {
        if ($deliveredItems === []) {
            return;
        }

        $deliveredIds = [];
        foreach ($deliveredItems as $item) {
            $id = (string) ($item['id'] ?? '');
            if ($id !== '') {
                $deliveredIds[$id] = true;
            }
        }
        if ($deliveredIds === []) {
            return;
        }

        $rows = array_values(array_filter(
            $this->recallRowsForUsage($recall),
            static fn (array $row): bool => isset($deliveredIds[(string) ($row['source_ref_id'] ?? '')]),
        ));
        if ($rows === []) {
            return;
        }

        $this->memoryUsage->recordRecallUsages($task, ['workspace' => $workspaceId], $rows, [
            'source' => 'atlas_context_pack',
            'usage_source_type' => AtlasMemoryUsageService::SOURCE_TYPE_MEMORY_RECALL,
            'delivery_surface' => AtlasMemoryUsageService::DELIVERY_SURFACE_CONTEXT_PACK,
            'created_by' => 'atlas_context_pack_delivery',
        ]);
    }

    /**
     * @param  array<string,mixed>  $recall
     * @return array<int,array<string,mixed>>
     */
    private function recallRowsForUsage(array $recall): array
    {
        $rows = [];
        foreach ((array) ($recall['recall'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (($row['source_ref_type'] ?? null) !== 'atlas_memory_entry') {
                continue;
            }
            if (! is_string($row['source_ref_id'] ?? null) || $row['source_ref_id'] === '') {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array{0:array<int,array<string,mixed>>,1:int}
     */
    private function filterLowRelevanceMemoryItems(string $task, array $items): array
    {
        if ($items === []) {
            return [$items, 0];
        }

        $taskTokens = $this->relevanceTokens($task);
        if ($taskTokens === []) {
            return [$items, 0];
        }

        $filtered = [];
        $removed = 0;
        foreach ($items as $item) {
            if ($this->memoryItemRelevantToTask($task, $taskTokens, $item)) {
                $filtered[] = $item;

                continue;
            }
            $removed++;
        }

        return [$filtered, $removed];
    }

    /**
     * @param  array<string,true>  $taskTokens
     * @param  array<string,mixed>  $item
     */
    private function memoryItemRelevantToTask(string $task, array $taskTokens, array $item): bool
    {
        $title = (string) ($item['title'] ?? '');
        $summary = (string) ($item['summary'] ?? '');
        $body = (string) ($item['body'] ?? '');
        $text = $title.' '.$summary.' '.$body;

        if ($this->isStableWiperIncidentMemory($item)) {
            return $this->taskAllowsWiperMemory($task);
        }

        // P0 do pack (09/07): overlap lexical NÃO separa sinal de lixo neste corpus —
        // títulos EN vs queries PT dão 0 overlap no sinal real, e o floor zerava a seção
        // memory em TODA query natural. Autoridade de relevância = o SCORE do ranker
        // híbrido (lexical+semântico+recência): item que o ranker PONTUOU é entregue
        // (demotion por feedback e o wiper-guard acima continuam valendo); o floor
        // lexical >=2 fica como rede só pra itens que chegaram SEM pontuação.
        if ((AiValueNormalizer::finiteFloatOrNull($item['_recall_score'] ?? null) ?? 0.0) > 0) {
            return true;
        }

        $memoryTokens = $this->relevanceTokens($text);
        if ($memoryTokens === []) {
            return false;
        }

        $overlap = 0;
        foreach ($taskTokens as $token => $_) {
            if (isset($memoryTokens[$token])) {
                $overlap++;
            }
        }

        return $overlap >= 2;
    }

    /**
     * RAG-03: the incident guard is keyed by stable metadata/ref identity, not a
     * volatile text list that changes as the corpus evolves.
     *
     * @param  array<string,mixed>  $item
     */
    private function isStableWiperIncidentMemory(array $item): bool
    {
        if ((string) ($item['_incident_scope'] ?? '') === 'wiper') {
            return true;
        }

        $stableRefs = $this->stringList(config('atlas.semantic_memory.wiper_incident_context_refs', []));
        if ($stableRefs === []) {
            return false;
        }

        return $this->matchesDemotedRef($this->memoryItemRefs($item), $stableRefs);
    }

    private function taskAllowsWiperMemory(string $task): bool
    {
        $text = $this->normalizedIntentText($task);

        return $this->containsAny($text, ['wiper', 'drop table', 'refreshdatabase', 'vendor symlink', 'pgsql', 'postgres', 'test safety', 'suite frankenstein']);
    }

    /**
     * @return array<string,true>
     */
    private function relevanceTokens(string $text): array
    {
        $text = $this->normalizedIntentText($text);
        preg_match_all('/[a-z0-9][a-z0-9._-]{2,}/', $text, $matches);

        $tokens = [];
        foreach ($matches[0] ?? [] as $token) {
            $token = trim((string) $token, '._-');
            if ($token === '' || isset(self::RELEVANCE_STOP_TERMS[$token])) {
                continue;
            }
            $tokens[$token] = true;
        }

        return $tokens;
    }

    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @param  array<int,string>  $demoteRefs
     * @return array{0:array<int,array<string,mixed>>,1:int}
     */
    private function filterDemotedMemoryItems(array $items, array $demoteRefs): array
    {
        if ($items === [] || $demoteRefs === []) {
            return [$items, 0];
        }

        $filtered = [];
        $demoted = 0;
        foreach ($items as $item) {
            if ($this->matchesDemotedRef($this->memoryItemRefs($item), $demoteRefs)) {
                $demoted++;

                continue;
            }
            $filtered[] = $item;
        }

        return [$filtered, $demoted];
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<int,string>
     */
    private function memoryItemRefs(array $item): array
    {
        $refs = AtlasCanonicalContextRef::memoryItemForms($item);
        $id = trim((string) ($item['id'] ?? ''));
        if ($id !== '') {
            $refs[] = $id;
            $refs[] = 'atlas_memory_entry:'.$id;
        }

        return AtlasCanonicalContextRef::uniqueStrings($refs);
    }

    /**
     * @param  array<int,string>  $candidateRefs
     * @param  array<int,string>  $demoteRefs
     */
    private function matchesDemotedRef(array $candidateRefs, array $demoteRefs): bool
    {
        $candidateSet = array_fill_keys($this->contextRefMatchForms($candidateRefs), true);
        foreach ($this->contextRefMatchForms($demoteRefs) as $demoteRef) {
            if (isset($candidateSet[$demoteRef])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,string>  $refs
     * @return array<int,string>
     */
    private function contextRefMatchForms(array $refs): array
    {
        $forms = [];
        foreach (AtlasCanonicalContextRef::uniqueStrings($refs) as $ref) {
            $forms[] = $ref;

            if (! $this->isSha256Hex($ref)) {
                $forms[] = hash('sha256', $ref);
            }
        }

        return AtlasCanonicalContextRef::uniqueStrings($forms);
    }

    private function isSha256Hex(string $ref): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $ref) === 1;
    }

    /**
     * L3-6 semantic re-rank of recalled memory items via the real local embedding
     * engine. Returns [reordered items, mode] where mode is 'semantic' (real
     * embeddings reordered the set) or 'lexical' (off / unavailable / fail-open).
     * The item text NEVER leaves the local runtime; only the reordering is applied.
     *
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array{0:array<int,array<string,mixed>>,1:string}
     */
    private function semanticallyReorderMemory(string $task, array $candidates): array
    {
        if (count($candidates) < 2 || trim($task) === '') {
            return [$candidates, 'lexical'];
        }

        $byId = [];
        $items = [];
        foreach ($candidates as $index => $candidate) {
            $id = 'mem_'.$index;
            $byId[$id] = $candidate;
            $items[] = [
                'id' => $id,
                'text' => trim(implode(' ', array_filter([
                    (string) ($candidate['title'] ?? ''),
                    (string) ($candidate['summary'] ?? ''),
                    (string) ($candidate['body'] ?? ''),
                ]))),
            ];
        }

        try {
            $ranked = $this->semanticContext->rank($task, $items, count($items));
        } catch (Throwable) {
            return [$candidates, 'lexical'];
        }

        $mode = (string) ($ranked['mode'] ?? 'lexical');
        if (! in_array($mode, ['semantic', 'cross_encoder', 'late_interaction'], true) || ($ranked['ranked'] ?? []) === []) {
            return [$candidates, 'lexical'];
        }

        $reordered = [];
        foreach ((array) $ranked['ranked'] as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id !== '' && isset($byId[$id])) {
                $reordered[] = $byId[$id];
                unset($byId[$id]);
            }
        }
        if ($mode === 'semantic') {
            // Append anything the vector ranker omitted, preserving the original recall order.
            foreach ($byId as $candidate) {
                $reordered[] = $candidate;
            }
        }

        return [$reordered, $mode];
    }

    // ------------------------------------------------------------------
    // Feedback request, rendering + helpers
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $pack
     */
    private function contextPackHash(array $pack): string
    {
        return hash('sha256', (string) json_encode(
            $this->canonicalize($pack),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param  array<string,mixed>  $pack
     * @param  array<string,mixed>  $opts
     * @return array<string,mixed>
     */
    private function contextFeedbackRequest(array $pack, array $opts): array
    {
        $flow = $this->contextFeedbackRequestFlow($opts);
        $deliveredRefs = $this->deliveredContextRefs($pack);

        return [
            'schema_version' => self::CONTEXT_FEEDBACK_REQUEST_SCHEMA,
            'status' => 'requested',
            'mode' => 'post_execution_provider_safe_roi',
            'tool' => 'atlas_context_feedback',
            'timing' => 'after_execution',
            'context_pack_hash' => (string) ($pack['context_pack_hash'] ?? ''),
            'retrieval_receipt_id' => (string) ($pack['context_pack_hash'] ?? ''),
            'flow_id' => $flow['flow_id'],
            'domain' => $flow['domain'],
            'task_type' => $flow['task_type'],
            'delivered_context_refs' => $deliveredRefs,
            'delivered_ref_count' => count($deliveredRefs),
            'source_types' => $this->feedbackSourceTypes($deliveredRefs),
            'arguments_template' => [
                'objective' => (string) ($pack['task'] ?? ''),
                'workspace' => (string) ($pack['workspace'] ?? ''),
                'flow_id' => $flow['flow_id'],
                'domain' => $flow['domain'],
                'task_type' => $flow['task_type'],
                'outcome_status' => 'passed|partial|failed|blocked',
                'context_pack_hash' => (string) ($pack['context_pack_hash'] ?? ''),
                'retrieval_receipt_id' => (string) ($pack['context_pack_hash'] ?? ''),
                'delivered_context_refs' => $deliveredRefs,
                'used_context_refs' => [],
                'noise_context_refs' => [],
                'missed_required_sources' => [],
                'post_execution_utility' => '0-100',
                'record' => true,
            ],
            'required_after_execution' => [
                'outcome_status',
                'used_context_refs',
                'post_execution_utility',
            ],
            'optional_after_execution' => [
                'noise_context_refs',
                'missed_required_sources',
                'run_outcome_id',
            ],
            'policy' => [
                'provider_safe_only' => true,
                'raw_text_exposed' => false,
                'raw_logs_allowed' => false,
                'providers_invoked' => false,
                'writes_only_when_record_true' => true,
                'auto_promote_learning' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $opts
     * @return array{flow_id:string,domain:string,task_type:string}
     */
    private function contextFeedbackRequestFlow(array $opts): array
    {
        $explicitFlow = $this->stringOpt($opts, 'flow_id');
        $domain = $this->stringOpt($opts, 'domain');
        $taskType = $this->stringOpt($opts, 'task_type');

        if ($explicitFlow !== null && str_contains($explicitFlow, '.')) {
            [$flowDomain, $flowTaskType] = array_pad(explode('.', $explicitFlow, 2), 2, null);
            $domain ??= is_string($flowDomain) && trim($flowDomain) !== '' ? trim($flowDomain) : null;
            $taskType ??= is_string($flowTaskType) && trim($flowTaskType) !== '' ? trim($flowTaskType) : null;
        }

        $domain ??= 'atlas';
        $taskType ??= 'dev';

        return [
            'flow_id' => $explicitFlow ?? $domain.'.'.$taskType,
            'domain' => $domain,
            'task_type' => $taskType,
        ];
    }

    /**
     * @param  array<string,mixed>  $pack
     * @return array<int,string>
     */
    private function deliveredContextRefs(array $pack): array
    {
        return AtlasCanonicalContextRef::deliveredFromPack($pack);
    }

    /**
     * @param  array<int,string>  $refs
     * @return array<int,string>
     */
    private function feedbackSourceTypes(array $refs): array
    {
        return $this->uniqueStrings(array_map(
            static fn (string $ref): string => str_contains($ref, ':') ? strstr($ref, ':', true) ?: 'unknown' : 'unknown',
            $refs,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    /**
     * Render the pack as a compact, human/agent-readable markdown brief — the
     * one block a hook injects into a provider prompt. Empty sections are
     * labelled honestly (never silently dropped) so the consumer can tell
     * "the brain has nothing here" from "the brain was not consulted".
     *
     * @param  array<string,mixed>  $pack
     */
    private function renderMarkdown(array $pack): string
    {
        $lines = [];
        $lines[] = '# Atlas Open Brain Context Pack (AOBG)';
        $lines[] = sprintf(
            'task="%s"  workspace=%s  provider-bound=yes  %s',
            (string) ($pack['task'] ?? ''),
            (string) ($pack['workspace'] ?? ''),
            self::HONESTY_LABEL,
        );
        $lines[] = '';

        // WO-17-T1 — resumption FIRST: on resume, "você estava no slice N, provou X,
        // falta Y, cuidado com Z" is the most important thing the session can read.
        $retomada = (array) ($pack['retomada'] ?? []);
        if (($retomada['present'] ?? false) === true) {
            $last = (array) ($retomada['last_session'] ?? []);
            $lines[] = '## Retomada da obra ativa';
            $lines[] = sprintf('- obra=%s  fase=%s', (string) ($retomada['obra_id'] ?? ''), (string) ($retomada['phase'] ?? '—'));
            if ($last !== []) {
                $result = $last['result'] ?? null;
                $resultStr = is_array($result) ? (($result['delivered'] ?? false) ? 'delivered' : (string) ($result['status'] ?? 'registrado')) : (is_scalar($result) ? (string) $result : '—');
                $lines[] = sprintf(
                    '- última sessão: tocou %d arquivo(s); resultado=%s; em %s',
                    count((array) ($last['files'] ?? [])),
                    $resultStr,
                    (string) ($last['at'] ?? '—'),
                );
                $files = array_slice((array) ($last['files'] ?? []), 0, 6);
                if ($files !== []) {
                    $lines[] = '  arquivos: '.implode(', ', array_map('strval', $files));
                }
            }
            $lines[] = '- '.(string) ($retomada['drift'] ?? '');
            $pend = array_slice((array) ($retomada['pendencies'] ?? []), 0, 6);
            if ($pend !== []) {
                $lines[] = '- falta: '.implode('; ', array_map('strval', $pend));
            }
            foreach (array_slice((array) ($retomada['refutacoes'] ?? []), 0, 3) as $ref) {
                $lines[] = '- ⚠️ forbidden-context: '.$this->formatRefutationMatch($ref);
            }
            $lines[] = '';
        }

        // WO-17-T2 — the deterministic brief. Staleness is ALWAYS visible (never a
        // silent stale brief): "BRIEF STALE desde X" when HEAD moved past it.
        $brief = (array) ($pack['brief'] ?? []);
        if (($brief['present'] ?? false) === true) {
            $lines[] = '## Brief (determinístico)';
            if (($brief['stale'] ?? false) === true) {
                $lines[] = '- ⚠️ BRIEF STALE desde '.(string) ($brief['generated_at'] ?? '').' — o HEAD mudou; rode `atlas:brief --generate`';
            } else {
                $lines[] = '- fresh (gerado '.(string) ($brief['generated_at'] ?? '').')';
            }
            $invariants = array_slice((array) ($brief['invariants'] ?? []), 0, 3);
            if ($invariants !== []) {
                $lines[] = '- invariantes (pétreas): '.implode('; ', array_map('strval', $invariants));
            }
            foreach (array_slice((array) ($brief['refutations'] ?? []), 0, 3) as $ref) {
                $lines[] = '- ⚠️ refutação: '.(string) $ref;
            }
            $modules = array_map(
                static fn ($m): string => is_array($m) ? (string) ($m['module'] ?? '') : (string) $m,
                array_slice((array) ($brief['modules'] ?? []), 0, 3),
            );
            $modules = array_values(array_filter($modules, static fn (string $m): bool => $m !== ''));
            if ($modules !== []) {
                $lines[] = '- módulos quentes: '.implode(', ', $modules);
            }
            $lines[] = '';
        }

        $policy = (array) ($pack['context_delivery_policy'] ?? []);
        $lines[] = '## Context delivery policy';
        $lines[] = sprintf(
            '- mode=%s status=%s actions=%s budget_multiplier=%.2f applied=%s',
            (string) ($policy['delivery_mode'] ?? 'standard_minimal_top_k'),
            (string) ($policy['status'] ?? 'inactive'),
            implode(',', $this->stringList($policy['actions'] ?? [])) ?: 'keep_current_pack',
            AiValueNormalizer::finiteFloatOrNull($policy['initial_context_budget_multiplier'] ?? null) ?? 1.0,
            (bool) ($policy['applied_to_initial_budget'] ?? false) ? 'yes' : 'no',
        );
        $handles = $this->stringList($policy['on_demand_handles'] ?? []);
        if ($handles !== []) {
            $lines[] = '- expand_on_demand: '.implode(', ', array_slice($handles, 0, 8));
        }
        $pathFiltered = (int) data_get($pack, 'context_hygiene.path_filtered', 0);
        $feedbackDemoted = (int) data_get($pack, 'context_hygiene.feedback_demoted', 0);
        $memoryFiltered = (int) data_get($pack, 'context_hygiene.memory_relevance_filtered', 0);
        $sessionEchoFiltered = (int) data_get($pack, 'context_hygiene.session_echo_filtered', 0);
        $docMissionFiltered = (int) data_get($pack, 'context_hygiene.doc_mission_filtered', 0);
        $totalCeilingTrimmed = (int) data_get($pack, 'context_hygiene.total_ceiling_trimmed', 0);
        if ($pathFiltered > 0 || $feedbackDemoted > 0 || $memoryFiltered > 0 || $sessionEchoFiltered > 0 || $docMissionFiltered > 0 || $totalCeilingTrimmed > 0) {
            $lines[] = sprintf(
                '- context_hygiene: path_filtered=%d feedback_demoted=%d memory_relevance_filtered=%d session_echo_filtered=%d doc_mission_filtered=%d total_ceiling_trimmed=%d',
                $pathFiltered,
                $feedbackDemoted,
                $memoryFiltered,
                $sessionEchoFiltered,
                $docMissionFiltered,
                $totalCeilingTrimmed,
            );
        }
        $sourceSelection = (array) ($policy['source_selection_policy'] ?? []);
        if ((bool) ($sourceSelection['applied_to_initial_pack'] ?? false)) {
            $multipliers = (array) ($sourceSelection['budget_multipliers'] ?? []);
            $lines[] = sprintf(
                '- source_mix: code=%.2f graph=%.2f memory=%.2f',
                AiValueNormalizer::finiteFloatOrNull($multipliers['code'] ?? null) ?? 1.0,
                AiValueNormalizer::finiteFloatOrNull($multipliers['graph'] ?? null) ?? 1.0,
                AiValueNormalizer::finiteFloatOrNull($multipliers['memory'] ?? null) ?? 1.0,
            );
        }
        $initialCodePolicy = (array) ($policy['initial_code_graph_delivery_policy'] ?? []);
        if ((int) ($initialCodePolicy['deferred_count'] ?? 0) > 0) {
            $deferredTypes = $this->stringList($initialCodePolicy['deferred_source_types'] ?? []);
            $lines[] = sprintf(
                '- deferred_code_symbols: count=%d sources=%s',
                (int) ($initialCodePolicy['deferred_count'] ?? 0),
                $deferredTypes !== [] ? implode(',', $deferredTypes) : 'n/a',
            );
        }
        $lines[] = '';

        $fusion = (array) ($pack['retrieval_fusion'] ?? []);
        if ($fusion !== []) {
            $lines[] = '## Unified retrieval priority';
            $lines[] = sprintf(
                '- status=%s algorithm=%s candidates=%d',
                (string) ($fusion['status'] ?? 'unknown'),
                (string) ($fusion['algorithm'] ?? 'unknown'),
                count((array) ($fusion['candidates'] ?? [])),
            );
            foreach (array_slice((array) ($fusion['candidates'] ?? []), 0, 8) as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }
                $lines[] = sprintf(
                    '- [%s] %s (rank=%d score=%.8f)',
                    (string) ($candidate['source'] ?? 'unknown'),
                    mb_substr((string) ($candidate['label'] ?? $candidate['ref'] ?? ''), 0, 160),
                    (int) ($candidate['source_rank'] ?? 0),
                    AiValueNormalizer::finiteFloatOrNull($candidate['fused_score'] ?? null) ?? 0.0,
                );
            }
            $lines[] = '';
        }

        $feedback = (array) ($pack['context_feedback_request'] ?? []);
        if ($feedback !== []) {
            $lines[] = '## Context feedback request';
            $lines[] = sprintf(
                '- after_execution: call %s with context_pack_hash=%s flow=%s record=true',
                (string) ($feedback['tool'] ?? 'atlas_context_feedback'),
                substr((string) ($feedback['context_pack_hash'] ?? ''), 0, 16),
                (string) ($feedback['flow_id'] ?? ''),
            );
            $lines[] = sprintf(
                '- report used/noise/missed refs from %d delivered refs; no raw logs or source text',
                (int) ($feedback['delivered_ref_count'] ?? 0),
            );
            $lines[] = '- in the report, cite every used context item with its exact rendered ref= value';
            $lines[] = '';
        }

        $compacted = (array) ($pack['compacted'] ?? []);
        if (($compacted['present'] ?? false) === true) {
            $lines[] = '## Compactação';
            $lines[] = sprintf(
                '- scope=%s:%s receipt_hash=%s coverage=%s loss_risk=%s unresolved_loss=%d',
                (string) ($compacted['scope_type'] ?? 'unknown'),
                (string) ($compacted['scope_id'] ?? ''),
                substr((string) ($compacted['receipt_hash'] ?? ''), 0, 16),
                is_numeric($compacted['must_keep_coverage'] ?? null) ? (string) $compacted['must_keep_coverage'] : 'n/a',
                (string) ($compacted['loss_risk'] ?? 'unknown'),
                (int) ($compacted['unresolved_loss_count'] ?? 0),
            );
            foreach (array_slice($this->stringList($compacted['recovery_queries'] ?? []), 0, 8) as $query) {
                $lines[] = '- recovery_query: `'.$query.'`';
            }
            $lines[] = '- workflow: '.(string) ($compacted['workflow'] ?? 'review_compaction_receipt');
            $lines[] = '';
        }

        $obraWorkingSet = (array) ($pack['obra_working_set'] ?? []);
        if ($obraWorkingSet !== []) {
            $lines[] = '## Obra working set (pointers)';
            $lines[] = sprintf(
                '- obra_id=%s status=%s count=%d soak=%s/%s',
                (string) ($obraWorkingSet['obra_id'] ?? ''),
                ($obraWorkingSet['present'] ?? false) === true ? 'present' : 'empty_honest',
                (int) ($obraWorkingSet['count'] ?? 0),
                (string) data_get($obraWorkingSet, 'soak.status', 'pending_window'),
                (string) data_get($obraWorkingSet, 'soak.basis', 'real_retomadas_only'),
            );
            foreach (array_slice((array) ($obraWorkingSet['items'] ?? []), 0, 16) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $decision = trim((string) ($item['decision_id'] ?? ''));
                $lines[] = sprintf(
                    '- ref=%s origin=%s source_scope=%s%s',
                    (string) ($item['ref'] ?? ''),
                    (string) ($item['origin'] ?? 'obra_working_set'),
                    (string) ($item['source_scope'] ?? ''),
                    $decision !== '' ? ' decision_id='.$decision : '',
                );
            }
            $lines[] = '- invariant: refs are pointers to existing context entries; no content copies are stored here.';
            $lines[] = '';
        }

        // 1) code-graph
        $lines[] = '## Code graph (symbols)';
        $code = (array) ($pack['code_graph'] ?? []);
        if ($code === []) {
            $lines[] = '_no matching symbols in this workspace_';
        } else {
            foreach ($code as $item) {
                $sig = trim((string) ($item['signature'] ?? ''));
                $sig = $sig !== '' ? '; sig='.mb_substr($sig, 0, 160) : '';
                $lines[] = sprintf(
                    '- %s ref=%s [%s] type=%s%s',
                    (string) ($item['id'] ?? ''),
                    AtlasCanonicalContextRef::fromCodeItem((array) $item),
                    (string) ($item['file_path'] ?? 'n/a'),
                    ($item['symbol_type'] ?? '') !== '' ? (string) $item['symbol_type'] : 'n/a',
                    $sig,
                );
            }
        }
        $lines[] = '';

        // 2) reality graph paths
        $lines[] = '## Reality graph (cross-layer paths)';
        $paths = (array) ($pack['reality_graph_paths'] ?? []);
        if ($paths === []) {
            $lines[] = '_no provider-safe paths from the brain for this task_';
        } else {
            foreach ($paths as $path) {
                $chain = array_map(
                    static fn (array $n): string => (string) ($n['label'] !== '' ? $n['label'] : $n['id']),
                    (array) ($path['chain'] ?? []),
                );
                $lines[] = sprintf(
                    '- ref=%s %s%s',
                    AtlasCanonicalContextRef::fromGraphPath((array) $path),
                    implode(' -> ', $chain),
                    ($path['cross_layer'] ?? false) ? '  (cross-layer)' : '',
                );
            }
        }
        $lines[] = '';

        // 3) memory
        $lines[] = '## Memory (provider-safe, redacted)';
        $memory = (array) ($pack['memory'] ?? []);
        if ($memory === []) {
            $memoryStatus = (string) data_get($pack, 'provenance.memory.status', 'empty');
            $lines[] = match ($memoryStatus) {
                'retrieval_error' => '_memory retrieval unavailable (retrieval_error); no memory was fabricated_',
                'filtered' => '_memory candidates were filtered by provider-safe relevance policy_',
                default => '_no provider-safe memory recalled for this task_',
            };
        } else {
            foreach ($memory as $item) {
                $title = (string) ($item['title'] ?? '');
                $summary = trim((string) ($item['summary'] ?? ($item['body'] ?? '')));
                $lines[] = sprintf(
                    '- ref=%s [%s] %s%s',
                    AtlasCanonicalContextRef::fromMemoryItem((array) $item),
                    ($item['type'] ?? '') !== '' ? (string) $item['type'] : 'memory',
                    $title !== '' ? $title : '(untitled)',
                    $summary !== '' ? ' — '.mb_substr($summary, 0, 200) : '',
                );
            }

            // T4-S5 (Obra #17) — dialectic contradiction: MARK any OPEN conflict among the
            // recalled memories, so two sides of an unresolved tension are never delivered
            // as settled truth. Consumes the D3 conflict edges; fail-open (no relations /
            // any fault → no marks, pack byte-identical).
            foreach (app(AtlasDialecticTensionService::class)->tensionMarks($memory) as $mark) {
                $lines[] = $mark;
            }
        }

        $spanLevel = (array) ($pack['span_level_retrieval'] ?? []);
        if (($spanLevel['present'] ?? false) === true) {
            $lines[] = '';
            $lines[] = '## Span-level citations';
            $lines[] = '- claim/span refs are deterministic over delivered content only; cite `ref=...` exactly when using a span.';
            foreach (array_slice((array) ($spanLevel['claims'] ?? []), 0, 8) as $claim) {
                if (! is_array($claim)) {
                    continue;
                }
                $lines[] = sprintf(
                    '- claim=%s status=%s',
                    mb_substr((string) ($claim['claim'] ?? ''), 0, 160),
                    (string) ($claim['status'] ?? 'unknown'),
                );
                foreach (array_slice((array) ($claim['spans'] ?? []), 0, 3) as $span) {
                    if (! is_array($span)) {
                        continue;
                    }
                    $lines[] = sprintf(
                        '  - ref=%s parent=%s content_version=%s bytes=%d-%d — %s',
                        (string) ($span['span_ref'] ?? ''),
                        (string) ($span['parent_ref'] ?? ''),
                        substr((string) ($span['content_version'] ?? ''), 0, 16),
                        (int) ($span['start'] ?? 0),
                        (int) ($span['end'] ?? 0),
                        mb_substr((string) ($span['span_excerpt'] ?? ''), 0, 180),
                    );
                }
            }
        }

        $epistemicEvidence = (array) ($pack['epistemic_evidence_bundle'] ?? []);
        if (($epistemicEvidence['present'] ?? false) === true) {
            $lines[] = '';
            $lines[] = '## Epistemic evidence bundle';
            $mustCarryRefs = (array) data_get($epistemicEvidence, 'must_carry.refs', []);
            $lines[] = '- must_carry: '.($mustCarryRefs === [] ? '_empty_honest_' : implode(', ', array_slice($mustCarryRefs, 0, 3)));
            $noveltyRefs = (array) data_get($epistemicEvidence, 'novelty_pool.refs', []);
            $lines[] = '- novelty_pool: '.($noveltyRefs === [] ? '_empty_honest_' : implode(', ', array_slice($noveltyRefs, 0, 5)));
            $lines[] = sprintf(
                '- operator_policy: %s (separate_from_evidence=%s)',
                (string) data_get($epistemicEvidence, 'operator_policy.mode', 'report_only'),
                data_get($epistemicEvidence, 'operator_policy.separate_from_evidence', true) ? 'true' : 'false',
            );
            $lines[] = sprintf(
                '- CONTRAEVIDÊNCIA: %s',
                (string) data_get($epistemicEvidence, 'counter_evidence.status', 'unknown'),
            );
            foreach (array_slice((array) data_get($epistemicEvidence, 'counter_evidence.slots', []), 0, 3) as $slot) {
                if (! is_array($slot)) {
                    continue;
                }
                $lines[] = sprintf(
                    '  - claim=%s status=%s refs_against=%d',
                    mb_substr((string) ($slot['claim'] ?? ''), 0, 120),
                    (string) ($slot['status'] ?? 'unknown'),
                    count((array) ($slot['refs_against'] ?? [])),
                );
            }
            foreach (array_slice((array) data_get($epistemicEvidence, 'claim_citations.items', []), 0, 5) as $claim) {
                if (! is_array($claim)) {
                    continue;
                }
                foreach (array_slice((array) ($claim['citations'] ?? []), 0, 2) as $citation) {
                    if (! is_array($citation)) {
                        continue;
                    }
                    $lines[] = sprintf(
                        '  - claim=%s ref=%s content_version=%s bytes=%d-%d',
                        mb_substr((string) ($claim['claim'] ?? ''), 0, 120),
                        (string) ($citation['span_ref'] ?? ''),
                        substr((string) ($citation['content_version'] ?? ''), 0, 16),
                        (int) ($citation['start'] ?? 0),
                        (int) ($citation['end'] ?? 0),
                    );
                }
            }
        }

        // MAXC-05 — sufficiency block: named gaps + expand:* handles so the
        // external agent has ACTION for "brain didn't find X" instead of silence.
        // Only rendered when the pack actually carries a sufficiency block AND
        // it flags `not_enough_context=true` — otherwise the pack stays quiet
        // (progressive disclosure invariant of the hook: no chatter when covered).
        $sufficiency = (array) ($pack['sufficiency'] ?? []);
        if (($sufficiency['present'] ?? false) === true
            && ($sufficiency['not_enough_context'] ?? false) === true) {
            $missing = array_values(array_filter(
                (array) ($sufficiency['missing_essential'] ?? []),
                static fn ($m): bool => is_array($m) && ($m['value'] ?? '') !== '',
            ));
            $handles = array_values(array_filter(
                (array) ($sufficiency['handles'] ?? []),
                static fn ($h): bool => is_string($h) && $h !== '',
            ));

            if ($missing !== [] || $handles !== []) {
                $lines[] = '';
                $lines[] = '## Suficiência';
                $lines[] = '- not_enough_context=true — o cérebro entregou pack mas SEM cobertura para 1+ faceta essencial da tarefa (progressive disclosure).';
                foreach (array_slice($missing, 0, 8) as $item) {
                    $lines[] = sprintf(
                        '- falta: %s=%s → use handle `%s`',
                        (string) ($item['type'] ?? '?'),
                        (string) ($item['value'] ?? '?'),
                        (string) ($item['handle'] ?? ('expand:'.($item['type'] ?? '?').':'.($item['value'] ?? '?'))),
                    );
                }
                if ($handles !== []) {
                    $lines[] = '- expand_handles: '.implode(', ', array_slice($handles, 0, 12));
                }
                $lines[] = '- workflow: chame de novo o context pack passando os handles listados (progressive disclosure), NUNCA invente conteúdo.';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * AP-818 F2.5 — the retrieval scope for the active workspace. Flag
     * `atlas.code_folder_intelligence.umbrella_context` OFF (default) keeps the
     * proven single-workspace behaviour; ON expands an umbrella workspace to
     * [umbrella graph + every member with its own graph] via the folder
     * intelligence service. Fail-safe: any error degrades to single scope.
     *
     * @return array<int,string>
     */
    private function umbrellaContextScope(string $workspaceId): array
    {
        if (! (bool) config('atlas.code_folder_intelligence.umbrella_context', false)) {
            return [$workspaceId];
        }

        try {
            return app(WorkspaceFolderIntelligenceService::class)
                ->contextScopeIds($workspaceId);
        } catch (Throwable) {
            return [$workspaceId];
        }
    }

    /**
     * Resolve the workspace id ONCE: explicit `workspace` (path or id) wins,
     * else `cwd`, else the primary default. Fail-safe — never throws.
     *
     * @param  array<string,mixed>  $opts
     */
    private function resolveWorkspaceId(array $opts): string
    {
        try {
            $explicit = $this->stringOpt($opts, 'workspace');
            if ($explicit !== null) {
                // "path OR id": an existing path resolves to its id; a previously-resolved
                // id is passed through verbatim (so it scopes to the SAME graph, not an
                // empty derived one). `cwd` is always a path.
                return $this->workspaceIdentity->resolveWorkspaceOrId($explicit);
            }

            $cwd = $this->stringOpt($opts, 'cwd');
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

    /**
     * @return array<int,array<string,mixed>>
     */
    private function normalizeHops(mixed $hops): array
    {
        $out = [];
        foreach ((array) $hops as $hop) {
            if (! is_array($hop)) {
                continue;
            }
            $out[] = [
                'from' => (string) ($hop['from'] ?? ''),
                'to' => (string) ($hop['to'] ?? ''),
                'edge_kind' => (string) ($hop['edge_kind'] ?? ''),
                'confidence' => round(AiValueNormalizer::finiteFloatOrNull($hop['confidence'] ?? null) ?? 0.0, 4),
                'direction' => (string) ($hop['direction'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    private function intOpt(array $opts, string $key, int $default): int
    {
        $raw = $opts[$key] ?? null;
        if (is_int($raw)) {
            return max(0, $raw);
        }
        if (is_string($raw)) {
            $numeric = AiValueNormalizer::finiteFloatOrNull(trim($raw));
            if ($numeric !== null) {
                return (int) max(0, (int) floor($numeric));
            }
        }

        return max(0, $default);
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    private function boolOpt(array $opts, string $key, bool $default): bool
    {
        $raw = $opts[$key] ?? null;
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw)) {
            return $raw !== 0;
        }
        if (is_string($raw)) {
            $normalized = strtolower(trim($raw));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return $default;
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    private function stringOpt(array $opts, string $key): ?string
    {
        $raw = $opts[$key] ?? null;
        if (! is_scalar($raw)) {
            return null;
        }
        $raw = trim((string) $raw);

        return $raw !== '' ? $raw : null;
    }

    /**
     * Obra 5 / MEM-04 + OPT-01 — demote dominant-memory refs on the initial AOBG pack.
     *
     * @return array<int,string>
     */
    private function concentrationDemoteContextRefs(): array
    {
        try {
            $demotion = new AtlasMemoryRecallConcentrationDemotion;

            return $demotion->demoteContextRefsForEntries($demotion->dominantEntryIds());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        $clean = [];
        foreach ($values as $item) {
            if (is_string($item) && trim($item) !== '') {
                $clean[] = trim($item);
            }
        }

        return array_values(array_unique($clean));
    }
}
