<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiRagFeedbackEvent;
use App\Services\Ai\Reality\AtlasRealityGraphQueryService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Engineering\CodeGraph\CodeGraphContextRetriever;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
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
 * independently and degrades to EMPTY on its own — no brain table, a blank
 * query, or a transient fault yields an honest empty section, never a fabricated
 * one. The pack is a CURATED TOP-K assembly, not omniscience, and labels itself
 * so. This service NEVER throws: context recall is best-effort, not a gate.
 */
class AtlasOpenBrainContextPackService
{
    public const SCHEMA = 'atlas.aobg.context_pack.v1';

    public const CONTEXT_DELIVERY_POLICY_SCHEMA = 'atlas.aobg.context_delivery_policy.v1';

    public const CONTEXT_FEEDBACK_REQUEST_SCHEMA = 'atlas.aobg.context_feedback_request.v1';

    public const RUNTIME_SCHEMA = 'atlas.aobg.context_pack.runtime.v1';

    public const RUNTIME_VERSION = 'aobg-context-pack-runtime-v3';

    /**
     * Provider-visible flags that let external MCP clients detect whether the
     * context-pack process is recent enough for the current AOBG behavior.
     */
    public const RUNTIME_FEATURE_FLAGS = [
        'code_graph_fill_gaps',
        'context_delivery_policy',
        'context_feedback_request',
        'initial_auxiliary_code_symbol_deferral',
        'initial_reality_cross_layer_only',
        'provider_bound_mission_seed_filter',
        'same_layer_path_omission_provenance',
        'separator_term_expansion',
        'test_symbol_on_demand_expansion',
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
    ];

    /**
     * Honest self-label carried in the pack so a consumer never reads it as an
     * exhaustive dump of the brain — it is the smallest useful curated slice.
     */
    public const HONESTY_LABEL = 'curated top-K (not exhaustive)';

    public function __construct(
        private readonly CodeGraphContextRetriever $codeGraph,
        private readonly AtlasRealityGraphQueryService $realityGraph,
        private readonly AtlasHybridMemoryRetrievalService $memory,
        private readonly CodeGraphWorkspaceIdentity $workspaceIdentity,
        private readonly \App\Services\Ai\Context\SemanticContextRetrievalService $semanticContext,
    ) {}

    /**
     * Assemble the one provider-bound pack fusing the three brains for a task.
     *
     * @param  string  $task  the free-text task / question driving recall.
     * @param  array<string,mixed>  $opts  optional:
     *   - workspace: explicit workspace path OR id (wins over cwd).
     *   - cwd: caller's working directory, resolved to a workspace id.
     *   - budget: total char budget for the pack (default config aobg.budget_chars).
     *   - code_budget / memory_budget: per-source sub-budgets (default config).
     *   - changed_files: array<string> of paths the task touches (biases code recall).
     * @return array<string,mixed> the structured pack (see SCHEMA) including a
     *   rendered markdown string under `markdown`.
     */
    public function packFor(string $task, array $opts = []): array
    {
        $task = trim($task);

        $workspaceId = $this->resolveWorkspaceId($opts);
        $requestedTotalBudget = $this->intOpt($opts, 'budget', (int) config('atlas.aobg.budget_chars', 6000));
        $totalBudget = $requestedTotalBudget;
        $codeBudget = $this->intOpt($opts, 'code_budget', (int) config('atlas.aobg.code_budget_chars', 2500));
        $memoryBudget = $this->intOpt($opts, 'memory_budget', (int) config('atlas.aobg.memory_budget_chars', 2000));
        $changedFiles = $this->stringList($opts['changed_files'] ?? []);
        $contextDeliveryPolicy = $this->contextDeliveryPolicy($opts);
        $budgetMultiplier = (float) ($contextDeliveryPolicy['initial_context_budget_multiplier'] ?? 1.0);
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
        $code = $this->codeSection($task, $workspaceId, $codeBudget, $changedFiles, $opts);
        $reality = $this->realitySection($task);
        $memorySection = $this->memorySection($task, $workspaceId, $memoryBudget);
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
        ];

        $pack['context_pack_hash'] = $this->contextPackHash($pack);
        $pack['context_feedback_request'] = $this->contextFeedbackRequest($pack, $opts);
        $pack['generated_at'] = now()->toJSON();
        $pack['markdown'] = $this->renderMarkdown($pack);

        return $pack;
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
                $code['items'] = array_values($code['items']);
                $code['chars'] = $this->codeItemsChars($code['items']);
                continue;
            }
            if ($section === 'reality') {
                array_pop($reality['paths']);
                $reality['paths'] = array_values($reality['paths']);
                $reality['chars'] = $this->realityPathsChars($reality['paths']);
                continue;
            }

            array_pop($memory['items']);
            $memory['items'] = array_values($memory['items']);
            $memory['chars'] = $this->memoryItemsChars($memory['items']);
        }

        $code['present'] = $code['items'] !== [];
        $reality['present'] = $reality['paths'] !== [];
        $memory['present'] = $memory['items'] !== [];

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
        $value = $values[$key] ?? null;
        if (! is_numeric($value)) {
            return $default;
        }

        return max(0.1, min(1.0, (float) $value));
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

            /** @var \Illuminate\Support\Collection<int,AiRagFeedbackEvent> $events */
            $events = $query->get();
        } catch (Throwable) {
            return $base + [
                'status' => 'unavailable',
                'reason' => 'feedback_read_failed',
            ];
        }

        if ($events->isEmpty()) {
            return $base + [
                'status' => 'no_data',
                'source' => $flowId !== null ? 'no_flow_feedback' : 'no_recent_feedback',
                'reason' => 'no_context_feedback_events',
                'quality_gate_hint' => 'record_atlas_context_feedback_after_provider_runs',
            ];
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
            $roi = (array) (data_get($payload, 'context_roi') ?: data_get($payload, 'payload.context_roi', []));
            $attribution = (array) (data_get($payload, 'context_ref_attribution') ?: data_get($payload, 'payload.context_ref_attribution', []));
            $nextPolicy = (array) (data_get($payload, 'next_context_policy') ?: data_get($payload, 'payload.next_context_policy', []));
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
            $demoteContextRefs = array_merge($demoteContextRefs, $this->stringList(data_get($nextPolicy, 'demote_context_refs', [])));
            $sourceTypeStats = $this->mergeSourceTypeStats($sourceTypeStats, $attribution);
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
        $sourceSelectionPolicy = $this->sourceSelectionPolicy($sourceTypeStats, $actionableFeedbackCount);

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
        $multiplier = match (true) {
            $shouldShrink && $shouldExpand => 0.85,
            $shouldShrink => 0.75,
            default => 1.0,
        };
        $applied = $multiplier < 1.0 && $observedCount >= 2;

        return [
            'schema_version' => self::CONTEXT_DELIVERY_POLICY_SCHEMA,
            'status' => $actions === ['keep_current_pack'] ? 'observed' : 'active',
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
            'demote_context_refs' => array_slice($demoteContextRefs, 0, 12),
            'on_demand_handles' => $this->expansionHandles($expandSourceTypes),
            'source_selection_policy' => $sourceSelectionPolicy,
            'evidence' => [
                'feedback_event_count' => $observedCount,
                'latest_feedback_hashes' => array_slice($feedbackHashes, 0, 5),
                'low_roi_count' => $lowRoiCount,
                'waste_count' => $wasteCount,
                'noise_count' => $noiseCount,
                'missed_required_source_count' => $missedCount,
                'non_passing_count' => $nonPassingCount,
                'actionable_feedback_count' => $actionableFeedbackCount,
                'non_actionable_feedback_count' => $nonActionableFeedbackCount,
                'missing_roi_signal_count' => $missingRoiSignalCount,
                'averages' => [
                    'roi_score' => round($avgRoi, 4),
                    'use_ratio' => round($avgUseRatio, 4),
                    'waste_ratio' => round($avgWasteRatio, 4),
                    'context_sufficiency' => round($this->average($sufficiencyScores), 2),
                    'post_execution_utility' => round($this->average($utilityScores), 2),
                ],
            ],
            'quality_gate_hint' => $shouldExpand
                ? 'expand_missing_source_types_before_implementation'
                : ($applied ? 'budget_shrunk_by_feedback_keep_expansion_available' : ($actionableFeedbackCount === 0 ? 'feedback_observed_but_not_actionable_for_budget' : 'feedback_review_before_context_expansion')),
            'policy' => $this->contextDeliveryPolicySafety($applied),
        ];
    }

    /**
     * @param  array<string,array<string,int>>  $stats
     * @return array<string,mixed>
     */
    private function sourceSelectionPolicy(array $stats, int $actionableFeedbackCount): array
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
            $waste = $unused + $noise;
            $useRatio = $delivered > 0 ? round($used / $delivered, 4) : 0.0;
            $wasteRatio = $delivered > 0 ? round($waste / $delivered, 4) : 0.0;

            $multiplier = 1.0;
            $action = 'keep';
            if ($actionableFeedbackCount > 0 && $delivered >= 2 && ($noise > 0 || ($used === 0 && $wasteRatio >= 0.50))) {
                $multiplier = 0.70;
                $action = 'reduce_initial_share';
            } elseif ($actionableFeedbackCount > 0 && $delivered >= 2 && $wasteRatio >= 0.40 && $useRatio < 0.50) {
                $multiplier = 0.85;
                $action = 'trim_initial_share';
            } elseif ($actionableFeedbackCount > 0 && $delivered >= 2 && $useRatio >= 0.50 && $wasteRatio < 0.40) {
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
            'code', 'symbol', 'route', 'migration', 'test' => 'code',
            'graph', 'reality_graph', 'aurg' => 'graph',
            'memory', 'semantic', 'decision', 'technical_context' => 'memory',
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
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
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
                'note' => self::HONESTY_LABEL,
            ], $delivery['provenance']),
        ];
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

        $explicitAuxiliaryIntent = $this->shouldIncludeAuxiliaryCodeSymbolsInitially($task, $opts);
        if ($explicitAuxiliaryIntent) {
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
            if ($this->auxiliaryCodeSourceType($item) !== null) {
                $deferred[] = $item;

                continue;
            }
            $retained[] = $item;
        }

        if ($deferred === []) {
            return ['items' => $items, 'provenance' => ['initial_delivery_policy' => $base]];
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
     */
    private function shouldIncludeAuxiliaryCodeSymbolsInitially(string $task, array $opts): bool
    {
        if ($this->boolOpt($opts, 'include_auxiliary_code_symbols', false)) {
            return true;
        }

        $taskType = strtolower((string) ($this->stringOpt($opts, 'task_type') ?? ''));
        if (in_array($taskType, ['test', 'tests', 'qa', 'coverage'], true)) {
            return true;
        }

        foreach ($this->stringList($opts['changed_files'] ?? []) as $path) {
            $path = strtolower($path);
            if (str_starts_with($path, 'tests/') || str_contains($path, '/tests/')) {
                return true;
            }
            if (str_starts_with($path, 'docs/') || str_contains($path, '/docs/')) {
                return true;
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
                return false;
            }
        }

        foreach ([
            '/\b(find|list|listar|quais|which|mapear|impact|impacto|rodar|run|corrigir|fix|failing|falhando)\b.*\b(test|tests|teste|testes|spec|coverage|cobertura)\b/',
            '/\b(test|tests|teste|testes|coverage|cobertura)\b.*\b(impact|impacto|falhando|failing|rodar|run|corrigir|fix|listar|list)\b/',
            '/\b(find|list|listar|ler|read|quais|which|auditar|review|revisar|mapear)\b.*\b(doc|docs|documentacao|canonical doc)\b/',
        ] as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
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
    private function realitySection(string $task): array
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
            $result = $this->realityGraph->query($task, ['provider_bound' => true]);
        } catch (Throwable) {
            return $empty;
        }

        $labelById = [];
        foreach ((array) ($result['nodes'] ?? []) as $node) {
            if (is_array($node) && isset($node['id'])) {
                $labelById[(string) $node['id']] = [
                    'label' => (string) ($node['label'] ?? ''),
                    'source_kind' => (string) ($node['source_kind'] ?? ''),
                ];
            }
        }

        $paths = [];
        $chars = 0;
        $rawPathCount = 0;
        $sameLayerPathCount = 0;
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
                $chain[] = [
                    'id' => $nodeId,
                    'label' => $labelById[$nodeId]['label'] ?? '',
                    'source_kind' => $labelById[$nodeId]['source_kind'] ?? '',
                ];
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
                'ranking' => (string) ($result['ranking'] ?? ''),
                'seeds' => count((array) ($result['seeds'] ?? [])),
                'nodes' => count((array) ($result['nodes'] ?? [])),
                'raw_paths' => $rawPathCount,
                'same_layer_paths_omitted' => $sameLayerPathCount,
                'cross_layer_paths' => (int) data_get($result, 'counts.cross_layer_paths', 0),
                'note' => self::HONESTY_LABEL,
            ],
        ];
    }

    /**
     * Semantic memory section — the hybrid recall returns provider-safe REDACTED
     * projections only (it filters by AtlasMemoryPrivacyService and the verbatim
     * external_ai_allowed flag). We surface the redacted titles/summaries/bodies
     * + ids/hashes, never raw bodies, and trim to the char sub-budget.
     *
     * @return array{present:bool, items:array<int,array<string,mixed>>, chars:int, provenance:array<string,mixed>}
     */
    private function memorySection(string $task, string $workspaceId, int $budgetChars): array
    {
        $empty = [
            'present' => false,
            'items' => [],
            'chars' => 0,
            'provenance' => ['policy' => 'provider_safe_only', 'note' => self::HONESTY_LABEL],
        ];

        if ($task === '' || $budgetChars <= 0) {
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
                ],
            );
        } catch (Throwable) {
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
            $candidates[] = [
                'type' => (string) ($row['type'] ?? ''),
                'scope' => (string) ($row['scope'] ?? ''),
                'title' => $title,
                'summary' => $summary,
                'body' => $body,
                'privacy_class' => (string) ($row['privacy_class'] ?? ''),
                // ids/hashes only — provenance the consumer can audit, no raw content.
                'source_type' => (string) ($row['source_type'] ?? ''),
                'content_hash' => (string) ($row['content_hash'] ?? ''),
            ];
        }

        // L3-6: optional semantic re-rank over the recalled items (symbols+docs) via
        // the REAL local embedding engine. Flag-gated (atlas.aobg.semantic_retrieval,
        // default OFF) and fail-open — on any miss the lexical recall order stands.
        [$candidates, $memoryMode] = $this->semanticallyReorderMemory($task, $candidates);

        $items = [];
        $chars = 0;
        foreach ($candidates as $item) {
            $entryChars = strlen($item['title'].$item['summary'].$item['body']);
            if ($chars + $entryChars > $budgetChars && $items !== []) {
                break; // respect the sub-budget; keep at least the top hit
            }
            $chars += $entryChars;
            $items[] = $item;
        }

        return [
            'present' => $items !== [],
            'items' => $items,
            'chars' => $chars,
            'provenance' => [
                'policy' => (string) data_get($recall, 'summary.policy', 'provider_safe_only'),
                'recall_count' => (int) data_get($recall, 'summary.recall_count', count($items)),
                'redacted_ref_count' => (int) data_get($recall, 'summary.redacted_ref_count', 0),
                'retrieval_mode' => $memoryMode,
                'note' => self::HONESTY_LABEL,
            ],
        ];
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
        } catch (\Throwable) {
            return [$candidates, 'lexical'];
        }

        if (($ranked['mode'] ?? 'lexical') !== 'semantic' || ($ranked['ranked'] ?? []) === []) {
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
        // Append anything the ranker omitted, preserving the original recall order.
        foreach ($byId as $candidate) {
            $reordered[] = $candidate;
        }

        return [$reordered, 'semantic'];
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
        $refs = [];

        foreach ((array) ($pack['code_graph'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $refs[] = $this->hashedContextRef('code', [
                'id' => (string) ($item['id'] ?? ''),
                'file_path' => (string) ($item['file_path'] ?? ''),
                'symbol_type' => (string) ($item['symbol_type'] ?? ''),
            ]);
        }

        foreach ((array) ($pack['reality_graph_paths'] ?? []) as $path) {
            if (! is_array($path)) {
                continue;
            }
            $refs[] = $this->hashedContextRef('graph', [
                'source' => (string) ($path['source'] ?? ''),
                'target' => (string) ($path['target'] ?? ''),
                'hops' => $this->normalizeHops($path['hops'] ?? []),
            ]);
        }

        foreach ((array) ($pack['memory'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $contentHash = (string) ($item['content_hash'] ?? '');
            $refs[] = $contentHash !== ''
                ? 'memory:'.substr(hash('sha256', $contentHash), 0, 32)
                : $this->hashedContextRef('memory', [
                    'type' => (string) ($item['type'] ?? ''),
                    'title' => (string) ($item['title'] ?? ''),
                ]);
        }

        return array_slice($this->uniqueStrings($refs), 0, 32);
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

    private function hashedContextRef(string $type, mixed $payload): string
    {
        return $type.':'.substr(hash('sha256', (string) json_encode(
            $this->canonicalize($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )), 0, 32);
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

        $policy = (array) ($pack['context_delivery_policy'] ?? []);
        $lines[] = '## Context delivery policy';
        $lines[] = sprintf(
            '- mode=%s status=%s actions=%s budget_multiplier=%.2f applied=%s',
            (string) ($policy['delivery_mode'] ?? 'standard_minimal_top_k'),
            (string) ($policy['status'] ?? 'inactive'),
            implode(',', $this->stringList($policy['actions'] ?? [])) ?: 'keep_current_pack',
            (float) ($policy['initial_context_budget_multiplier'] ?? 1.0),
            (bool) ($policy['applied_to_initial_budget'] ?? false) ? 'yes' : 'no',
        );
        $handles = $this->stringList($policy['on_demand_handles'] ?? []);
        if ($handles !== []) {
            $lines[] = '- expand_on_demand: '.implode(', ', array_slice($handles, 0, 8));
        }
        $sourceSelection = (array) ($policy['source_selection_policy'] ?? []);
        if ((bool) ($sourceSelection['applied_to_initial_pack'] ?? false)) {
            $multipliers = (array) ($sourceSelection['budget_multipliers'] ?? []);
            $lines[] = sprintf(
                '- source_mix: code=%.2f graph=%.2f memory=%.2f',
                (float) ($multipliers['code'] ?? 1.0),
                (float) ($multipliers['graph'] ?? 1.0),
                (float) ($multipliers['memory'] ?? 1.0),
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
                    '- %s [%s] type=%s%s',
                    (string) ($item['id'] ?? ''),
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
                    '- %s%s',
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
            $lines[] = '_no provider-safe memory recalled for this task_';
        } else {
            foreach ($memory as $item) {
                $title = (string) ($item['title'] ?? '');
                $summary = trim((string) ($item['summary'] ?? ($item['body'] ?? '')));
                $lines[] = sprintf(
                    '- [%s] %s%s',
                    ($item['type'] ?? '') !== '' ? (string) $item['type'] : 'memory',
                    $title !== '' ? $title : '(untitled)',
                    $summary !== '' ? ' — '.mb_substr($summary, 0, 200) : '',
                );
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
            return app(\App\Services\AtlasCode\WorkspaceFolderIntelligenceService::class)
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
     * @param  mixed  $hops
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
                'confidence' => round((float) ($hop['confidence'] ?? 0.0), 4),
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
        if (is_string($raw) && is_numeric(trim($raw))) {
            return (int) max(0, (int) floor((float) trim($raw)));
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
