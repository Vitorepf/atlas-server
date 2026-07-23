<?php

namespace App\Services\Ai\OpenBrainContextInjection;

use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use App\Services\Ai\Reality\AtlasRealityGraphQueryService;
use App\Services\Engineering\CodeGraph\CodeGraphContextRetriever;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use App\Services\Engineering\EngineeringKnowledgeBaseService;
use Throwable;

/**
 * Godfile split (GOD-DEBULK, 2026-07-22): the knowledge/code/code-graph/memory-recall/
 * reality-graph ref builders + their stable-hash projections, moved VERBATIM out of
 * {@see \App\Services\Ai\AtlasOpenBrainContextInjectionService}. Same injected services,
 * same flag-gated / fail-open behavior. Not scanner-pinned.
 */
class RefsSection
{
    public function __construct(
        private readonly EngineeringKnowledgeBaseService $knowledge,
        private readonly EngineeringCodeIntelligenceService $code,
        private readonly ?CodeGraphContextRetriever $codeGraph = null,
        private readonly ?AtlasHybridMemoryRetrievalService $memoryRecall = null,
        private readonly ?AtlasRealityGraphQueryService $realityGraph = null,
    ) {}

    /**
     * @param  array<string,mixed>  $context
     * @return array<int,array<string,mixed>>
     */
    public function knowledgeRefs(array $context): array
    {
        try {
            return $this->knowledge->contextRefs($context, (int) config('atlas.open_brain.injection.knowledge_ref_limit', 6));
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<int,array<string,mixed>>
     */
    public function codeRefs(array $context): array
    {
        try {
            return $this->code->contextRefs($context, (int) config('atlas.open_brain.injection.code_ref_limit', 8));
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * AP-815 I-4 (Stage 2) — pull the precise, BM25-ranked code-graph context pack for the
     * task through the proven {@see CodeGraphContextRetriever} ("free-text query + changed
     * files → workspace-scoped, budgeted E-3 pack", the same path as `atlas:ctx`).
     *
     * FLAG-GATED, default-OFF: when `config('atlas.code_graph.auto_context')` is false this
     * returns [] WITHOUT resolving the retriever, touching the DB, or reading the clock, so
     * the surrounding injection (hash, refs, prompt) stays byte-identical to before. The
     * retriever itself never throws (best-effort recall), but the call is still wrapped so
     * any unexpected fault degrades to [] rather than failing the injection.
     *
     * The query is the operator's free-text input; the changed-file set is the SAME
     * programming `selected_files` already gathered for {@see programmingContextSummary()}
     * (so a task that names the files it touches biases retrieval toward them). The
     * workspace id is resolved from the injection's workspace path via the canonical
     * {@see CodeGraphWorkspaceIdentity}.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $pack
     * @return array<int,array<string,mixed>> the pack's included symbols (E-3 shape), or []
     */
    public function precomputedCodeGraphRefs(array $fusedPack): array
    {
        return collect((array) ($fusedPack['code_graph'] ?? []))
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->map(static fn (array $item): array => [
                'type' => 'atlas_code_graph_symbol',
                'id' => (string) ($item['id'] ?? ''),
                'symbol_type' => (string) ($item['symbol_type'] ?? ''),
                'file_path' => (string) ($item['file_path'] ?? ''),
                'signature' => (string) ($item['signature'] ?? ''),
                'tokens' => (int) ($item['tokens'] ?? 0),
                'provider_safe' => true,
            ])
            ->filter(static fn (array $item): bool => $item['id'] !== '')
            ->values()
            ->all();
    }

    public function precomputedMemoryRecallRefs(array $fusedPack): array
    {
        return collect((array) ($fusedPack['memory'] ?? []))
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->map(static function (array $item): array {
                $sourceId = trim((string) ($item['id'] ?? ''));
                $title = (string) ($item['title'] ?? '');
                $summary = (string) ($item['summary'] ?? ($item['body'] ?? ''));

                return [
                    'type' => 'atlas_memory_recall',
                    'id' => $sourceId !== ''
                        ? (string) ($item['source_type'] ?? 'memory').':'.$sourceId
                        : 'memory:'.hash('sha256', $title.'|'.$summary),
                    'memory_type' => (string) ($item['type'] ?? 'memory'),
                    'scope' => (string) ($item['scope'] ?? ''),
                    'title' => $title,
                    'summary' => $summary,
                    'reason' => 'precomputed AOBG provider-safe recall',
                    'provider_safe' => true,
                ];
            })
            ->values()
            ->all();
    }

    public function precomputedRealityGraphRefs(array $fusedPack): array
    {
        return collect((array) ($fusedPack['reality_graph_paths'] ?? []))
            ->filter(static fn (mixed $path): bool => is_array($path))
            ->map(fn (array $path): ?array => $this->precomputedRealityGraphRef($path))
            ->filter()
            ->values()
            ->all();
    }

    private function precomputedRealityGraphRef(array $path): ?array
    {
        $chain = array_values(array_filter((array) ($path['chain'] ?? []), 'is_array'));
        $hops = array_values(array_filter((array) ($path['hops'] ?? []), 'is_array'));
        if (count($chain) < 2 || count($hops) !== count($chain) - 1) {
            return null;
        }

        $nodes = [];
        $nodeIds = [];
        foreach ($chain as $node) {
            $id = trim((string) ($node['id'] ?? ''));
            if ($id === '') {
                return null;
            }
            $nodeIds[] = $id;
            $nodes[] = [
                'kind' => (string) ($node['kind'] ?? $node['source_kind'] ?? ''),
                'label' => (string) ($node['label'] ?? ''),
                'source_kind' => (string) ($node['source_kind'] ?? ''),
                'source_id' => (string) ($node['source_id'] ?? ''),
            ];
        }

        $parts = [$nodes[0]['kind']];
        $confidences = [];
        foreach ($hops as $index => $hop) {
            $edgeKind = trim((string) ($hop['edge_kind'] ?? $hop['kind'] ?? ''));
            if ($edgeKind === '') {
                return null;
            }
            $parts[] = $edgeKind;
            $parts[] = $nodes[$index + 1]['kind'];
            if (is_numeric($hop['confidence'] ?? null)) {
                $confidences[] = (float) $hop['confidence'];
            }
        }

        return [
            'type' => 'atlas_reality_path',
            'id' => implode('>', $nodeIds),
            'chain_label' => implode('→', $parts),
            'nodes' => $nodes,
            'confidence_min' => $confidences === [] ? null : round(min($confidences), 4),
            'cross_layer' => (bool) ($path['cross_layer'] ?? false),
            'provider_safe' => true,
        ];
    }

    public function codeGraphRefs(string $input, array $payload, array $pack, ?string $workspace): array
    {
        if (! (bool) config('atlas.code_graph.auto_context', false)) {
            return [];
        }

        try {
            $retriever = $this->codeGraph ?? app(CodeGraphContextRetriever::class);
            $workspaceId = app(CodeGraphWorkspaceIdentity::class)->resolve($workspace);
            $budget = (int) config('atlas.code_graph.auto_context_budget', CodeGraphContextRetriever::DEFAULT_BUDGET);

            $result = $retriever->packFor(
                $input,
                $workspaceId,
                $budget,
                $this->codeGraphChangedFiles($payload, $pack),
            );

            $included = $result['included'] ?? [];

            return is_array($included)
                ? array_values(array_filter($included, static fn (mixed $ref): bool => is_array($ref)))
                : [];
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * The changed/selected file set for code-graph retrieval, mirroring the sources
     * {@see programmingContextSummary()} draws `selected_files` from (the context pack's
     * `selected_files`, the engineering contract's `likely_files`, and the dev plan's
     * `selected_files`). Deduped, blank-stripped, capped — used purely to bias the BM25
     * retrieval toward the files the task touches.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $pack
     * @return array<int,string>
     */
    private function codeGraphChangedFiles(array $payload, array $pack): array
    {
        $devPlan = (array) data_get($payload, 'dev_execution_plan', []);
        $messagePlan = (array) data_get($payload, 'programming_message_plan', []);
        $contract = (array) (data_get($devPlan, 'engineering_contract')
            ?: data_get($messagePlan, 'engineering_contract')
            ?: data_get($pack, 'engineering.contract')
            ?: []);

        return collect([
            ...(array) data_get($pack, 'selected_files', []),
            ...(array) data_get($contract, 'likely_files', []),
            ...(array) data_get($devPlan, 'selected_files', []),
        ])
            ->filter(fn (mixed $file): bool => is_string($file) && trim($file) !== '')
            ->map(fn (mixed $file): string => trim((string) $file))
            ->unique()
            ->values()
            ->take(20)
            ->all();
    }

    /**
     * R4 (PART A) — pull the operator's accrued, provider-safe SEMANTIC memory recall
     * (decisions/learnings) for this task through the SHARED, now-pgvector
     * {@see AtlasHybridMemoryRetrievalService::recall} (the same engine behind
     * `atlas_memory_recall` / `atlas:memory:recall`). This is the live-prompt wiring of
     * that retrieval path — NOT a second retrieval engine.
     *
     * FLAG-GATED, default-OFF: when `config('atlas.open_brain.injection.include_memory_recall')`
     * is false this returns [] WITHOUT resolving the service, touching the DB, or reading
     * the clock, so the surrounding injection (hash, refs, prompt) stays byte-identical to
     * before. recall() is best-effort but the call is still wrapped so any fault degrades
     * to [] rather than failing the injection (fail-open).
     *
     * Provider-safety is enforced INSIDE recall() (registry rows pass
     * AtlasMemoryPrivacyService::providerAllowed + provider title/summary/body; verbatim
     * rows require external_ai_allowed===true + redacted_text). Here we expose only the
     * already-redacted title/summary/reason — never the raw `text`/`body` — so no PII or
     * non-provider-safe content can leak into the prompt.
     *
     * @param  array<string,mixed>  $context  the engineering context (scope/tags) for recall
     * @param  array<string,mixed>  $pack
     * @return array<int,array<string,mixed>> provider-safe recall refs, or []
     */
    public function memoryRecallRefs(string $input, array $context, array $pack): array
    {
        if (! (bool) config('atlas.open_brain.injection.include_memory_recall', false)) {
            return [];
        }

        try {
            $service = $this->memoryRecall ?? app(AtlasHybridMemoryRetrievalService::class);
            $limit = max(1, (int) config('atlas.open_brain.injection.memory_recall_limit', 6));

            $result = $service->recall(
                trim($input),
                $this->memoryRecallContext($context, $pack),
                [],
                [
                    'limit' => $limit,
                    'requester' => 'atlas_open_brain_context_injection',
                ],
            );

            $recall = $result['recall'] ?? [];

            return collect(is_array($recall) ? $recall : [])
                ->filter(fn (mixed $item): bool => is_array($item))
                ->map(fn (array $item): array => [
                    'type' => 'atlas_memory_recall',
                    'id' => is_scalar($item['source_ref_id'] ?? null) && trim((string) $item['source_ref_id']) !== ''
                        ? (string) $item['source_ref_type'].':'.(string) $item['source_ref_id']
                        : (string) ($item['type'] ?? 'memory').':'.hash('sha256', (string) ($item['title'] ?? '').'|'.(string) ($item['summary'] ?? '')),
                    'memory_type' => (string) ($item['type'] ?? 'memory'),
                    'scope' => (string) ($item['scope'] ?? ''),
                    'title' => (string) ($item['title'] ?? ''),
                    'summary' => (string) ($item['summary'] ?? ''),
                    'reason' => (string) ($item['reason'] ?? ''),
                    // The source recall row carries the unsafe-marker verdict; propagate it
                    // verbatim instead of hard-coding true, so the hygiene gate downstream can
                    // still see/drop quarantined or otherwise unsafe recalled memory.
                    'provider_safe' => ($item['provider_safe'] ?? true) !== false,
                    'quarantine' => (bool) ($item['quarantine'] ?? false),
                    'require_sanitization' => (bool) ($item['require_sanitization'] ?? false),
                    'non_instructional_context' => (bool) ($item['non_instructional_context'] ?? false),
                    'hostile_memory' => (bool) ($item['hostile_memory'] ?? false),
                    'raw_prompt_leakage' => (bool) ($item['raw_prompt_leakage'] ?? false),
                    'raw_prompt_detected' => (bool) ($item['raw_prompt_detected'] ?? false),
                ])
                ->values()
                ->all();
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * The scope/tags handed to recall() so the operator's memory is biased to this task's
     * project/workspace, mirroring the same context {@see engineeringContext()} builds.
     *
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $pack
     * @return array<string,mixed>
     */
    private function memoryRecallContext(array $context, array $pack): array
    {
        return array_filter([
            'project_id' => $context['project_id'] ?? null,
            'task_id' => $context['task_id'] ?? null,
            'engineering_run_id' => $context['engineering_run_id'] ?? null,
            'workspace' => $context['workspace'] ?? null,
            'domain' => data_get($pack, 'task.domain'),
            'tags' => array_values(array_filter((array) ($context['tags'] ?? []), 'is_string')),
        ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * Stable, order-independent projection of the recall refs for the deterministic context
     * hash — keyed on id only (drops the redacted prose so two runs over the same recalled
     * memory rows hash identically regardless of summary phrasing/order).
     *
     * @param  array<int,array<string,mixed>>  $memoryRecallRefs
     * @return array<int,string>
     */
    public function stableMemoryRecallForHash(array $memoryRecallRefs): array
    {
        return collect($memoryRecallRefs)
            ->map(fn (array $ref): string => (string) ($ref['id'] ?? ''))
            ->filter(fn (string $id): bool => $id !== '')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * F3 (Salto 1 — AURG vivo) — read-back: the fused Unified Reality Graph
     * (atlas_aurg_nodes/atlas_aurg_edges, built by atlas:aurg:ingest) answers the task
     * query through the SHARED {@see AtlasRealityGraphQueryService} (the same engine
     * behind `atlas:aurg:query`) and its TOP cross-layer chains become compact,
     * provenance-tagged prompt refs. This is the live-prompt wiring of the existing
     * brain query — NOT a second graph engine.
     *
     * FLAG-GATED, default-OFF: when `config('atlas.open_brain.injection.include_reality_graph')`
     * is false this returns [] WITHOUT resolving the service, touching the DB, or
     * reading the clock, so the surrounding injection (hash, refs, prompt) stays
     * byte-identical to before. Any fault degrades to [] rather than failing the
     * injection (fail-open), same contract as code_graph/memory_recall above.
     *
     * PROVIDER-BOUND ALWAYS: `provider_bound` is HARD-CODED true on this path — the
     * assembled section is a provider prompt by definition, so seeds AND every BFS
     * step are restricted to provider_safe && !sensitive nodes inside the query
     * service (structural exclusion, never post-filtering). Node labels are
     * provider-safe by F1 construction (redacted memory titles, ids/hashes for
     * evidence, module paths for code) — payloads never live in the brain.
     *
     * "Top" paths = ranked target order: the query's `nodes` array is already ranked
     * (Python networkx via GraphRankRuntimeClient when it ran, HONEST insertion order
     * otherwise), so paths are ordered by their target's rank position — no PHP
     * re-scoring stand-in. Mapping is deterministic cite-or-omit: a chain is kept ONLY
     * when every node id on it resolves against the query result and its hops line up.
     *
     * @return array<int,array<string,mixed>> compact provider-safe path refs, or []
     */
    public function realityGraphRefs(string $input): array
    {
        if (! (bool) config('atlas.open_brain.injection.include_reality_graph', false)) {
            return [];
        }

        try {
            $service = $this->realityGraph ?? app(AtlasRealityGraphQueryService::class);
            $limit = max(1, (int) config('atlas.open_brain.injection.reality_graph_limit', 6));

            $result = $service->query(trim($input), [
                // The prompt path is provider-bound by definition — never optional here.
                'provider_bound' => true,
            ]);

            $nodesById = [];
            foreach ((array) ($result['nodes'] ?? []) as $node) {
                if (is_array($node) && is_scalar($node['id'] ?? null) && (string) $node['id'] !== '') {
                    $nodesById[(string) $node['id']] = $node;
                }
            }
            $rankPosition = array_flip(array_keys($nodesById));

            $paths = collect((array) ($result['paths'] ?? []))
                ->filter(fn (mixed $path): bool => is_array($path))
                ->sortBy(fn (array $path): int => $rankPosition[(string) ($path['target'] ?? '')] ?? PHP_INT_MAX)
                ->values();

            $refs = [];
            foreach ($paths as $path) {
                if (count($refs) >= $limit) {
                    break;
                }
                $ref = $this->realityGraphPathRef($path, $nodesById);
                if ($ref !== null) {
                    $refs[] = $ref;
                }
            }

            return $refs;
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * One compact, provider-safe ref per cross-layer chain: the REAL node kinds and
     * stored edge kinds joined as a chain label (e.g. 'memory_entry→references→module'),
     * the resolved nodes as compact refs (kind/label/source_kind/source_id — never
     * payloads), and the weakest hop confidence. Cite-or-omit: returns null when any
     * chain node is missing from the result, a hop carries no stored edge kind, or the
     * hop count does not line up with the chain — partial chains are dropped, never
     * patched or invented.
     *
     * @param  array<string,mixed>  $path  one F2 path ({target, seed, nodes, hops, cross_layer})
     * @param  array<string,array<string,mixed>>  $nodesById  the query's nodes keyed by id
     * @return array<string,mixed>|null
     */
    private function realityGraphPathRef(array $path, array $nodesById): ?array
    {
        $chainIds = [];
        foreach ((array) ($path['nodes'] ?? []) as $nodeId) {
            if (! is_scalar($nodeId) || trim((string) $nodeId) === '') {
                return null;
            }
            $chainIds[] = (string) $nodeId;
        }

        $hops = array_values(array_filter((array) ($path['hops'] ?? []), 'is_array'));
        if (count($chainIds) < 2 || count($hops) !== count($chainIds) - 1) {
            return null;
        }

        $nodes = [];
        foreach ($chainIds as $nodeId) {
            $node = $nodesById[$nodeId] ?? null;
            if ($node === null) {
                return null;
            }
            $nodes[] = [
                'kind' => (string) ($node['kind'] ?? ''),
                'label' => (string) ($node['label'] ?? ''),
                'source_kind' => (string) ($node['source_kind'] ?? ''),
                'source_id' => (string) ($node['source_id'] ?? ''),
            ];
        }

        $chainParts = [$nodes[0]['kind']];
        $confidences = [];
        foreach ($hops as $index => $hop) {
            $edgeKind = is_scalar($hop['edge_kind'] ?? null) ? trim((string) $hop['edge_kind']) : '';
            if ($edgeKind === '') {
                return null;
            }
            $chainParts[] = $edgeKind;
            $chainParts[] = $nodes[$index + 1]['kind'];
            if (is_numeric($hop['confidence'] ?? null)) {
                $confidences[] = (float) $hop['confidence'];
            }
        }

        return [
            'type' => 'atlas_reality_path',
            'id' => implode('>', $chainIds),
            'chain_label' => implode('→', $chainParts),
            'nodes' => $nodes,
            'confidence_min' => $confidences === [] ? null : round(min($confidences), 4),
            'cross_layer' => (bool) ($path['cross_layer'] ?? false),
            'provider_safe' => true,
        ];
    }

    /**
     * Stable, order-independent projection of the reality-graph refs for the
     * deterministic context hash — keyed on the chain id only (the joined deterministic
     * node ids), sorted, so two runs over the same reached chains hash identically
     * regardless of path order.
     *
     * @param  array<int,array<string,mixed>>  $realityGraphRefs
     * @return array<int,string>
     */
    public function stableRealityGraphForHash(array $realityGraphRefs): array
    {
        return collect($realityGraphRefs)
            ->map(fn (array $ref): string => (string) ($ref['id'] ?? ''))
            ->filter(fn (string $id): bool => $id !== '')
            ->sort()
            ->values()
            ->all();
    }
}
