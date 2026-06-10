<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Reality\AtlasRealityGraphQueryService;
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
        $totalBudget = $this->intOpt($opts, 'budget', (int) config('atlas.aobg.budget_chars', 6000));
        $codeBudget = $this->intOpt($opts, 'code_budget', (int) config('atlas.aobg.code_budget_chars', 2500));
        $memoryBudget = $this->intOpt($opts, 'memory_budget', (int) config('atlas.aobg.memory_budget_chars', 2000));
        $changedFiles = $this->stringList($opts['changed_files'] ?? []);

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
        $code = $this->codeSection($task, $workspaceId, $codeBudget, $changedFiles);
        $reality = $this->realitySection($task);
        $memorySection = $this->memorySection($task, $workspaceId, $memoryBudget);

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
            'provenance' => [
                'sources_present' => $sourcesPresent,
                'code_graph' => $code['provenance'],
                'reality_graph' => $reality['provenance'],
                'memory' => $memorySection['provenance'],
            ],
            'budget' => [
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
            'generated_at' => now()->toJSON(),
        ];

        $pack['markdown'] = $this->renderMarkdown($pack);

        return $pack;
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
     * Code-graph section via the proven BM25 + E-3 retriever (workspace-scoped).
     * The retriever's token budget is char-budget / ~4 (its ~4-chars-per-token
     * convention) so the section respects the supplied char sub-budget.
     *
     * @param  array<int,string>  $changedFiles
     * @return array{present:bool, items:array<int,array<string,mixed>>, chars:int, provenance:array<string,mixed>}
     */
    private function codeSection(string $task, string $workspaceId, int $budgetChars, array $changedFiles): array
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
            $pack = $this->codeGraph->packFor($task, $workspaceId, $tokenBudget, $changedFiles);
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

        return [
            'present' => $items !== [],
            'items' => $items,
            'chars' => $chars,
            'provenance' => [
                'retriever' => CodeGraphContextRetriever::SCHEMA,
                'workspace_id' => $workspaceId,
                'token_budget' => (int) ($pack['budget'] ?? 0),
                'estimated_tokens' => (int) ($pack['estimated_tokens'] ?? 0),
                'truncated' => (bool) ($pack['truncated'] ?? false),
                'note' => self::HONESTY_LABEL,
            ],
        ];
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
        foreach ((array) ($result['paths'] ?? []) as $path) {
            if (! is_array($path)) {
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

        $items = [];
        $chars = 0;
        foreach ((array) ($recall['recall'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = (string) ($row['title'] ?? '');
            $summary = (string) ($row['summary'] ?? '');
            $body = (string) ($row['body'] ?? ($row['snippet'] ?? ($row['excerpt'] ?? '')));
            $item = [
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
            $entryChars = strlen($title.$summary.$body);
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
                'note' => self::HONESTY_LABEL,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Rendering + helpers
    // ------------------------------------------------------------------

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
