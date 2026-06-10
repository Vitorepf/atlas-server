<?php

declare(strict_types=1);

namespace App\Services\Ai\Obra;

use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * AOBG N3.F1 — the DECOMPOSITION spine: intent → plan-DAG → steps.
 *
 * THE INVERSION. N1 had the engine consult the brain; N2 had the brain watch the
 * engine. N3 makes the brain DRIVE: the operator declares an INTENT in natural
 * language and Atlas decomposes it into an OBRA — a multi-step plan-DAG it can later
 * execute governed onto ONE ready-to-merge branch. This RAISES THE UNIT OF WORK from
 * edit → obra.
 *
 * This service owns ONLY the planning half (decompose + validate + brain-anchor +
 * persist) — it never spends on its own beyond what the injected
 * {@see ObraDecomposer} spends (the deterministic one spends NOTHING; the provider
 * one spends and is itself stubbable). It builds NO parallel engine: each persisted
 * node is a natural-language step the proven per-node executor
 * ({@see \App\Services\Ai\RealExecution\AtlasMissionService}) delivers onto the
 * single accumulating obra branch — this service just produces the proven-acyclic,
 * brain-anchored plan it walks.
 *
 * GUARANTEES:
 *  - VALID DAG OR REFUSE: deps must resolve to nodes in the same plan and the graph
 *    must be acyclic (Kahn topological sort); a cycle or a dangling dep throws
 *    BEFORE anything is persisted — the table only ever holds a runnable plan.
 *  - BRAIN-ANCHORED: each node carries `brain_refs` cited from the N1 context pack
 *    ({@see AtlasOpenBrainContextPackService}) for THAT step (provider-safe ids /
 *    labels / source-kinds / paths only — never source). A brain outage degrades a
 *    node to empty refs, never breaks planning (fail-open).
 *  - BOUNDED: `atlas.obra.max_nodes` (default 12) caps the plan; an over-cap
 *    decomposition is REFUSED (never silently truncated into an invalid partial DAG).
 *  - COST-FREE PLANNING: the deterministic decomposer (default) spends nothing; the
 *    persist + anchor are local DB only. The only spend in the whole obra is the
 *    operator's single run command's per-node delivery — branch-only, never main.
 *  - PROVIDER-SAFE PERSIST: the stored `intent` is a redacted, bounded summary; node
 *    fields are labels.
 */
class AtlasObraPlanService
{
    public const SCHEMA = 'atlas.obra.plan.v1';

    public const STATUS_PLANNED = 'planned';

    public function __construct(
        private readonly ObraDecomposer $decomposer,
        private readonly CodeGraphWorkspaceIdentity $workspaceIdentity,
        private readonly ?AtlasOpenBrainContextPackService $brain = null,
    ) {}

    /**
     * Decompose an intent into a persisted, validated, brain-anchored plan-DAG.
     *
     * @param  array<string,mixed>  $opts  {workspace?:string, max_nodes?:int, model?:string, ...}
     * @return array<string,mixed> {schema, plan_id, workspace_id, status, node_count,
     *                             decomposer, nodes:list<{id,seq,title,request,target_area,
     *                             depends_on,status,brain_refs}>, topological_order:list<string>}
     *
     * @throws InvalidArgumentException on empty intent, an empty decomposition, an
     *                                  over-cap plan, a dangling dep, or a cycle.
     */
    public function decompose(string $intent, array $opts = []): array
    {
        if (! (bool) config('atlas.obra.enabled', true)) {
            throw new InvalidArgumentException('obra planning is disabled (atlas.obra.enabled=false)');
        }

        $intent = trim($intent);
        if ($intent === '') {
            throw new InvalidArgumentException('intent cannot be empty');
        }

        $workspaceId = $this->workspaceIdentity->resolveWorkspaceOrId(
            isset($opts['workspace']) ? (string) $opts['workspace'] : null,
        );
        $maxNodes = max(1, (int) ($opts['max_nodes'] ?? config('atlas.obra.max_nodes', 12)));

        // --- 1) DECOMPOSE (the injected decomposer; deterministic = cost-free). ---
        $drafts = $this->decomposer->decompose($intent, array_merge($opts, [
            'workspace' => $workspaceId,
            'max_nodes' => $maxNodes,
        ]));
        if ($drafts === []) {
            throw new InvalidArgumentException('decomposition produced no steps');
        }
        if (count($drafts) > $maxNodes) {
            throw new InvalidArgumentException(
                'decomposition exceeds max_nodes ('.count($drafts).' > '.$maxNodes.')',
            );
        }

        // --- 2) VALIDATE + TOPOLOGICALLY ORDER the DAG (refuse cycles / dangling). ---
        $ordered = $this->topologicallyOrder($drafts);

        // --- 3) Mint the stable obra id + assign final node ids by topo seq. ---
        $planId = $this->planId($intent, $workspaceId, $opts);
        $keyToId = [];
        foreach ($ordered as $seq => $draft) {
            $keyToId[$draft->key] = $planId.':n'.$seq;
        }

        // --- 4) BRAIN-ANCHOR each node (N1 pack for the step; fail-open). ---
        $nodeRows = [];
        foreach ($ordered as $seq => $draft) {
            $brainRefs = $this->brainRefsFor($draft, $workspaceId);
            $dependsOnIds = array_values(array_map(
                static fn (string $depKey): string => $keyToId[$depKey],
                $draft->dependsOn,
            ));

            $nodeRows[] = [
                'id' => $keyToId[$draft->key],
                'plan_id' => $planId,
                'seq' => $seq,
                'title' => mb_substr($draft->title, 0, 300),
                'request' => $draft->request,
                'target_area' => $draft->targetArea !== null ? mb_substr($draft->targetArea, 0, 500) : null,
                'depends_on' => json_encode($dependsOnIds, JSON_UNESCAPED_SLASHES),
                'status' => 'pending',
                'brain_refs' => json_encode($brainRefs, JSON_UNESCAPED_SLASHES),
                'result' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // --- 5) PERSIST atomically (plan header + all nodes). ---
        $meta = [
            'schema' => self::SCHEMA,
            'node_count' => count($nodeRows),
            'max_nodes' => $maxNodes,
            'decomposer' => $this->decomposer->label(),
            'honesty' => 'plan only — no execution; cost-free unless the provider decomposer ran',
        ];

        DB::transaction(function () use ($planId, $intent, $workspaceId, $meta, $nodeRows): void {
            DB::table('atlas_obra_plans')->updateOrInsert(
                ['id' => $planId],
                [
                    'intent' => $this->redactIntent($intent),
                    'workspace_id' => $workspaceId,
                    'status' => self::STATUS_PLANNED,
                    'meta' => json_encode($meta, JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            // Idempotent re-plan: clear prior nodes for this plan, re-insert the fresh DAG.
            DB::table('atlas_obra_nodes')->where('plan_id', $planId)->delete();
            DB::table('atlas_obra_nodes')->insert($nodeRows);
        });

        return [
            'schema' => self::SCHEMA,
            'plan_id' => $planId,
            'workspace_id' => $workspaceId,
            'status' => self::STATUS_PLANNED,
            'node_count' => count($nodeRows),
            'decomposer' => $this->decomposer->label(),
            'nodes' => array_map(static fn (array $r): array => [
                'id' => $r['id'],
                'seq' => $r['seq'],
                'title' => $r['title'],
                'request' => $r['request'],
                'target_area' => $r['target_area'],
                'depends_on' => json_decode((string) $r['depends_on'], true) ?: [],
                'status' => $r['status'],
                'brain_refs' => json_decode((string) $r['brain_refs'], true) ?: [],
            ], $nodeRows),
            'topological_order' => array_values(array_map(
                static fn (array $r): string => $r['id'],
                $nodeRows,
            )),
        ];
    }

    /**
     * Validate the draft DAG and return the drafts in a topological order (Kahn's
     * algorithm). Refuses (throws) a dangling dependency or any cycle — the table
     * only ever stores a proven-acyclic, runnable plan. Deterministic: ties broken by
     * the decomposer's original draft order, so the same drafts always order the same.
     *
     * @param  list<ObraNodeDraft>  $drafts
     * @return list<ObraNodeDraft>  the drafts in dependency-respecting order
     *
     * @throws InvalidArgumentException on a duplicate key, a dangling dep, or a cycle
     */
    private function topologicallyOrder(array $drafts): array
    {
        // Index by key + assign a stable original-order rank (deterministic tie-break).
        $byKey = [];
        $rank = [];
        foreach (array_values($drafts) as $i => $draft) {
            if ($draft->key === '') {
                throw new InvalidArgumentException('a step is missing its key');
            }
            if (isset($byKey[$draft->key])) {
                throw new InvalidArgumentException('duplicate step key: '.$draft->key);
            }
            $byKey[$draft->key] = $draft;
            $rank[$draft->key] = $i;
        }

        // Build in-degree + adjacency; refuse a dep that names no step in this plan.
        $indegree = [];
        $adj = [];
        foreach ($byKey as $key => $draft) {
            $indegree[$key] ??= 0;
            $adj[$key] ??= [];
        }
        foreach ($byKey as $key => $draft) {
            foreach ($draft->dependsOn as $dep) {
                if (! isset($byKey[$dep])) {
                    throw new InvalidArgumentException(
                        'step "'.$key.'" depends on unknown step "'.$dep.'"',
                    );
                }
                if ($dep === $key) {
                    throw new InvalidArgumentException('step "'.$key.'" depends on itself (cycle)');
                }
                // edge dep → key (dep must run first); key gains an in-edge.
                $adj[$dep][] = $key;
                $indegree[$key]++;
            }
        }

        // Kahn: repeatedly take a zero-in-degree node (lowest original rank first).
        $ready = [];
        foreach ($indegree as $key => $deg) {
            if ($deg === 0) {
                $ready[] = $key;
            }
        }
        $this->sortByRank($ready, $rank);

        $orderKeys = [];
        while ($ready !== []) {
            $key = array_shift($ready);
            $orderKeys[] = $key;
            foreach ($adj[$key] as $next) {
                $indegree[$next]--;
                if ($indegree[$next] === 0) {
                    $ready[] = $next;
                    $this->sortByRank($ready, $rank);
                }
            }
        }

        if (count($orderKeys) !== count($byKey)) {
            // Some node never reached in-degree 0 ⇒ a cycle exists.
            throw new InvalidArgumentException('the plan contains a dependency cycle');
        }

        return array_values(array_map(
            static fn (string $key): ObraNodeDraft => $byKey[$key],
            $orderKeys,
        ));
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string,int>  $rank
     */
    private function sortByRank(array &$keys, array $rank): void
    {
        usort($keys, static fn (string $a, string $b): int => ($rank[$a] ?? 0) <=> ($rank[$b] ?? 0));
    }

    /**
     * Brain-anchor a node: cite the N1 context pack's provider-safe refs for THIS
     * step (its request, biased by its target_area). FAIL-OPEN: a missing brain / a
     * fault yields empty refs — never breaks planning. Stores ids / labels /
     * source-kinds / paths only (the pack is provider-bound by construction).
     *
     * @return array<string,mixed> {sources_present, code, reality, memory} — each a
     *                             bounded list of provider-safe refs (or empty)
     */
    private function brainRefsFor(ObraNodeDraft $draft, string $workspaceId): array
    {
        $empty = ['sources_present' => [], 'code' => [], 'reality' => [], 'memory' => []];
        if (! $this->brain instanceof AtlasOpenBrainContextPackService) {
            return $empty;
        }

        try {
            $opts = ['workspace' => $workspaceId];
            if ($draft->targetArea !== null && $draft->targetArea !== '') {
                $opts['changed_files'] = [$draft->targetArea];
            }
            $pack = $this->brain->packFor($draft->request, $opts);

            return [
                'sources_present' => array_values((array) ($pack['provenance']['sources_present'] ?? [])),
                // code-graph: symbol id + file path (already provider-safe labels).
                'code' => array_values(array_map(
                    static fn (array $it): array => [
                        'id' => (string) ($it['id'] ?? ''),
                        'file' => (string) ($it['file_path'] ?? ''),
                    ],
                    array_slice((array) ($pack['code_graph'] ?? []), 0, 6),
                )),
                // reality-graph: the cross-layer path node-id chains.
                'reality' => array_values(array_map(
                    static fn (array $p): array => [
                        'nodes' => array_values(array_filter((array) ($p['nodes'] ?? []), 'is_string')),
                    ],
                    array_slice((array) ($pack['reality_graph_paths'] ?? []), 0, 6),
                )),
                // memory: redacted title + id (the pack already returns the redacted projection).
                'memory' => array_values(array_map(
                    static fn (array $m): array => [
                        'id' => (string) ($m['id'] ?? ''),
                        'title' => (string) ($m['title'] ?? ''),
                    ],
                    array_slice((array) ($pack['memory'] ?? []), 0, 6),
                )),
            ];
        } catch (Throwable) {
            // Honest degrade — no anchor, never a broken plan.
            return $empty;
        }
    }

    /**
     * The stable obra id: an explicit caller id wins; otherwise derived
     * deterministically from intent + workspace, so re-decomposing the same intent in
     * the same workspace upserts the same plan (idempotent end to end).
     *
     * @param  array<string,mixed>  $opts
     */
    private function planId(string $intent, string $workspaceId, array $opts): string
    {
        $explicit = isset($opts['id']) && is_string($opts['id']) ? trim($opts['id']) : '';
        if ($explicit !== '') {
            return 'obra-'.preg_replace('/[^a-z0-9._-]+/', '-', strtolower($explicit));
        }

        return 'obra-'.substr(hash('sha256', $intent.'|'.$workspaceId), 0, 12);
    }

    /**
     * Provider-safe, bounded summary of the operator's intent for storage. Conservative
     * scrub of obvious secret-looking tokens (keys / bearer tokens) + a hard length
     * bound. The column is a LABEL, never the verbatim raw ask.
     */
    private function redactIntent(string $intent): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $intent) ?? $intent);
        // Scrub long opaque tokens / obvious key=value secrets (deterministic, conservative).
        $s = preg_replace('/\b(?:sk|pk|ghp|gho|xox[baprs])[-_][A-Za-z0-9]{12,}\b/', '[redacted]', $s) ?? $s;
        $s = preg_replace('/\b[A-Za-z0-9]{32,}\b/', '[redacted]', $s) ?? $s;
        $s = preg_replace('/\b(?:password|secret|token|api[_-]?key)\s*[:=]\s*\S+/i', '$0=[redacted]', $s) ?? $s;

        return mb_substr($s, 0, 1000);
    }
}
