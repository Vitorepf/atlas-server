<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use App\Services\Ai\Reality\AtlasRealityGraphQueryService;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Throwable;

/**
 * AOBG N2.F1 — the ACTIVE brain's PostToolUse surface: context that FOLLOWS the
 * task instead of waiting for the prompt.
 *
 * N1 gave the brain a FRONT DOOR ({@see AtlasOpenBrainContextPackService}: the
 * unified prompt-time pack). N2 makes the brain INTERVENE DURING the session:
 * when the engine opens/edits a file, this service answers "what does the brain
 * already KNOW about THIS file?" — the file's brain-delta — so the model is told
 * "this file is governed by decision X; a prior mission here failed by Y; it is
 * consumed by Z" the moment it touches the file, not only when it remembers to ask.
 *
 * It builds NO parallel engine. It ASSEMBLES the same three already-proven,
 * already-provider-safe brains the N1 pack uses, but SEEDED FROM A FILE PATH
 * rather than a free-text prompt:
 *
 *   1. code-graph neighbors — {@see EngineeringCodeIntelligenceService::symbols()}
 *      (the W-1 workspace-scoped read-model): the symbols DEFINED IN this file
 *      (file_path match) + who CONSUMES it (symbols whose name/signature reference
 *      the file's class/basename). The "consumed by Z".
 *   2. reality graph (AURG) — {@see AtlasRealityGraphQueryService::query()} with
 *      provider_bound=TRUE (non-negotiable: hook output crosses to an external
 *      AI), seeded from the file's module path + basename tokens, returning the
 *      cross-layer paths that touch the file's module: missions / decisions /
 *      evidence / domains. The "decision X / mission Y".
 *   3. semantic memory — {@see AtlasHybridMemoryRetrievalService::recall()} over
 *      the provider-safe REDACTED projection only, keyed on the file's class /
 *      basename, surfacing the decisions/memories that REFERENCE the file.
 *
 * PROVIDER-SAFETY (non-negotiable): every byte returned can be injected into the
 * engine's context, so the brain-delta is provider-bound end to end — exactly the
 * N1 floor. AURG is queried with provider_bound=true (sensitive domains and
 * anything reachable only through them are structurally excluded, not
 * post-filtered); memory is the recall's already-redacted projection; code
 * symbols are signature/name strings from the local read-model only. No raw
 * memory bodies, no sensitive/secret content, no Atlas-internal ids/traces.
 *
 * PERF + COST (the create-path-perf memory): this runs on the operator's
 * interactive PostToolUse path. It is read-only / local DB only (ZERO provider
 * spend), every query is HARD-CAPPED (rows + char budget), and it FAILS OPEN to
 * an honest-empty delta on ANY fault — a slow or broken brain degrades the hint,
 * never stalls or blocks the session. This service NEVER throws.
 *
 * HONEST EMPTY (anti-over-claim): an unknown / out-of-workspace / brain-less file
 * yields an empty-but-valid delta (`has_context=false`), never a fabricated one.
 * The delta is a CURATED slice the brain happens to hold about this file, not a
 * claim of completeness — it labels itself so.
 */
class AtlasOpenBrainFileContextService
{
    use AtlasOptionStringHelper;

    public const SCHEMA = 'atlas.aobg.file_context.v1';

    public const HONESTY_LABEL = 'curated top-K (not exhaustive)';

    public function __construct(
        private readonly EngineeringCodeIntelligenceService $code,
        private readonly AtlasRealityGraphQueryService $realityGraph,
        private readonly AtlasHybridMemoryRetrievalService $memory,
        private readonly CodeGraphWorkspaceIdentity $workspaceIdentity,
    ) {}

    /**
     * Assemble the provider-bound brain-delta for a file the engine just touched.
     *
     * @param  string  $path  the file path (absolute or workspace-relative).
     * @param  array<string,mixed>  $opts  optional:
     *                                     - workspace: explicit workspace path OR id (wins over cwd / default).
     *                                     - cwd: caller's working directory, resolved to a workspace id.
     *                                     - budget: total char ceiling (default config aobg.file_context.budget_chars).
     * @return array<string,mixed> the structured delta (see SCHEMA), with a rendered
     *                             markdown brief under `markdown` (the one block the hook injects).
     */
    public function contextFor(string $path, array $opts = []): array
    {
        $startedAt = microtime(true);
        $rawPath = trim($path);

        $workspaceId = $this->resolveWorkspaceId($opts);
        $relPath = $this->relativePath($rawPath, $opts);
        $basename = $this->basename($relPath);
        // The class/symbol name a consumer would reference (Foo.php -> "Foo").
        $stem = $this->stem($basename);

        $totalBudget = $this->intOpt($opts, 'budget', (int) config('atlas.aobg.file_context.budget_chars', 3500));
        $maxNeighbors = max(1, (int) config('atlas.aobg.file_context.max_neighbors', 12));
        $maxMemory = max(1, (int) config('atlas.aobg.file_context.max_memory', 6));
        $maxPaths = max(1, (int) config('atlas.aobg.file_context.max_paths', 8));

        // Each section is built INDEPENDENTLY and fail-safe: any one degrading to
        // empty never blocks the others (honest empty, never fabricated).
        $neighbors = $this->codeNeighborsSection($relPath, $stem, $workspaceId, $maxNeighbors);
        $reality = $this->realitySection($relPath, $stem, $maxPaths);
        $memorySection = $this->memorySection($relPath, $stem, $workspaceId, $maxMemory);

        // FINAL TOTAL-BUDGET CEILING (anti-over-claim): trim trailing (lowest-ranked)
        // items — neighbors first (the largest section), then memory, then paths —
        // until the measured chars fit the total. Each non-empty section keeps AT
        // LEAST its top hit (never starves). Mirrors the N1 pack's enforceTotalCeiling.
        [$neighbors, $memorySection, $reality] = $this->enforceTotalCeiling(
            $totalBudget,
            $neighbors,
            $memorySection,
            $reality,
        );

        $sourcesPresent = [];
        if ($neighbors['present']) {
            $sourcesPresent[] = 'code_neighbors';
        }
        if ($reality['present']) {
            $sourcesPresent[] = 'reality_graph';
        }
        if ($memorySection['present']) {
            $sourcesPresent[] = 'memory';
        }

        $delta = [
            'schema' => self::SCHEMA,
            'path' => $relPath,
            'workspace' => $workspaceId,
            'provider_bound' => true,
            'honesty' => self::HONESTY_LABEL,
            'has_context' => $sourcesPresent !== [],
            // "consumed by Z" — symbols defined here + who references the file.
            'defined_symbols' => $neighbors['defined'],
            'consumers' => $neighbors['consumers'],
            // "decision X / mission Y" — provider-safe AURG cross-layer paths.
            'reality_graph_paths' => $reality['paths'],
            // decisions/memories that reference the file (redacted projection).
            'memory' => $memorySection['items'],
            'provenance' => [
                'sources_present' => $sourcesPresent,
                'code_neighbors' => $neighbors['provenance'],
                'reality_graph' => $reality['provenance'],
                'memory' => $memorySection['provenance'],
            ],
            'budget' => [
                'total_chars' => $totalBudget,
                'estimated_chars' => $neighbors['chars'] + $reality['chars'] + $memorySection['chars'],
            ],
            'counts' => [
                'defined_symbols' => count($neighbors['defined']),
                'consumers' => count($neighbors['consumers']),
                'reality_graph_paths' => count($reality['paths']),
                'memory' => count($memorySection['items']),
            ],
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'generated_at' => now()->toJSON(),
        ];

        $delta['markdown'] = $this->renderMarkdown($delta);

        return $delta;
    }

    // ------------------------------------------------------------------
    // Sections — each independent + fail-safe (honest empty on any fault).
    // ------------------------------------------------------------------

    /**
     * Code-graph neighbors via the W-1 workspace-scoped read-model. Two queries:
     *  - DEFINED: symbols whose file_path matches this file (what lives here).
     *  - CONSUMERS: symbols (in OTHER files) whose name/signature reference the
     *    file's stem — a cheap, fail-open "who consumes Z" without loading a graph.
     * Both are bounded by the row cap; consumers exclude the file's own symbols.
     *
     * @return array{present:bool, defined:list<array<string,mixed>>, consumers:list<array<string,mixed>>, chars:int, provenance:array<string,mixed>}
     */
    private function codeNeighborsSection(string $relPath, string $stem, string $workspaceId, int $cap): array
    {
        $empty = [
            'present' => false,
            'defined' => [],
            'consumers' => [],
            'chars' => 0,
            'provenance' => ['workspace_id' => $workspaceId, 'note' => self::HONESTY_LABEL],
        ];

        if ($relPath === '') {
            return $empty;
        }

        try {
            $definedRaw = $this->code->symbols(
                ['q' => $relPath, 'workspace_id' => $workspaceId],
                $cap,
            );
            // Consumers: search the stem (class/basename) but DROP rows in this very
            // file (those are "defined", not "consumers"). Only run when there is a
            // meaningful stem (avoids a 1-2 char wildcard scan on the hot path).
            $consumersRaw = $stem !== '' && mb_strlen($stem) >= 3
                ? $this->code->symbols(['q' => $stem, 'workspace_id' => $workspaceId], $cap)
                : ['symbols' => []];
        } catch (Throwable) {
            return $empty; // best-effort recall, never a gate
        }

        $defined = [];
        $definedFiles = [];
        $chars = 0;
        foreach ((array) ($definedRaw['symbols'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $filePath = (string) ($row['file_path'] ?? '');
            // file_path LIKE is a substring match; keep only EXACT-file rows here.
            if ($filePath !== $relPath) {
                continue;
            }
            $definedFiles[$filePath] = true;
            $item = $this->neighborItem($row);
            $chars += $this->neighborItemChars($item);
            $defined[] = $item;
            if (count($defined) >= $cap) {
                break;
            }
        }

        $consumers = [];
        foreach ((array) ($consumersRaw['symbols'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $filePath = (string) ($row['file_path'] ?? '');
            if ($filePath === '' || $filePath === $relPath) {
                continue; // a symbol in the file itself is not a consumer of it
            }
            // Require the stem to actually appear in the symbol name OR signature
            // (cite-or-omit: drop SQL wildcard / file_path-only over-matches).
            $name = (string) ($row['symbol_name'] ?? '');
            $sig = (string) ($row['signature'] ?? '');
            $hay = mb_strtolower($name.' '.$sig);
            if (! str_contains($hay, mb_strtolower($stem))) {
                continue;
            }
            $item = $this->neighborItem($row);
            $chars += $this->neighborItemChars($item);
            $consumers[] = $item;
            if (count($consumers) >= $cap) {
                break;
            }
        }

        return [
            'present' => $defined !== [] || $consumers !== [],
            'defined' => $defined,
            'consumers' => $consumers,
            'chars' => $chars,
            'provenance' => [
                'workspace_id' => $workspaceId,
                'stem' => $stem,
                'note' => self::HONESTY_LABEL,
            ],
        ];
    }

    /**
     * Reality-graph (AURG) section — provider_bound is FORCED true (hook output is
     * external). Seeded from the file's module path + basename so the BFS surfaces
     * the missions / decisions / evidence / modules that touch this file's module.
     *
     * @return array{present:bool, paths:list<array<string,mixed>>, chars:int, provenance:array<string,mixed>}
     */
    private function realitySection(string $relPath, string $stem, int $cap): array
    {
        $empty = [
            'present' => false,
            'paths' => [],
            'chars' => 0,
            'provenance' => ['provider_bound' => true, 'note' => self::HONESTY_LABEL],
        ];

        if ($relPath === '' || ! (bool) config('atlas.aurg.enabled', true)) {
            return $empty;
        }

        // The directory carries the module signal; the stem the symbol signal.
        $dir = trim((string) (str_contains($relPath, '/') ? dirname($relPath) : ''));
        $query = trim($dir.' '.$stem);
        if ($query === '') {
            $query = $relPath;
        }

        try {
            // provider_bound is non-relaxable here: gateway output is external.
            $result = $this->realityGraph->query($query, ['provider_bound' => true]);
        } catch (Throwable) {
            return $empty;
        }

        $labelById = [];
        foreach ((array) ($result['nodes'] ?? []) as $node) {
            if (is_array($node) && isset($node['id'])) {
                $labelById[(string) $node['id']] = [
                    'label' => (string) ($node['label'] ?? ''),
                    'source_kind' => (string) ($node['source_kind'] ?? ''),
                    'kind' => (string) ($node['kind'] ?? ''),
                    'origin' => (string) data_get($node, 'meta.origin', ''),
                ];
            }
        }

        $paths = [];
        $chars = 0;
        foreach ((array) ($result['paths'] ?? []) as $rawPath) {
            if (! is_array($rawPath)) {
                continue;
            }
            $nodeIds = array_map('strval', (array) ($rawPath['nodes'] ?? []));
            $chain = [];
            $sessionEcho = false;
            foreach ($nodeIds as $nodeId) {
                // Same untrusted-label hygiene as the context pack: neutralize a node label (it can
                // be a raw past prompt) and drop paths that run into a session-capture echo artifact.
                $label = AtlasOpenBrainContextPackService::sanitizeGraphLabel((string) ($labelById[$nodeId]['label'] ?? ''));
                // Provenance beats heuristics: an AOBG session-capture mission's label is
                // raw session text — never render it as a decision touching this file.
                if (($labelById[$nodeId]['origin'] ?? '') === AtlasOpenBrainWriteBackService::MISSION_ORIGIN_SESSION_CAPTURE
                    || AtlasOpenBrainContextPackService::isSessionArtifactLabel($label)) {
                    $sessionEcho = true;
                }
                $chain[] = [
                    'id' => $nodeId,
                    'label' => $label,
                    'source_kind' => $labelById[$nodeId]['source_kind'] ?? '',
                    'kind' => $labelById[$nodeId]['kind'] ?? '',
                ];
            }
            if ($sessionEcho) {
                continue;
            }
            $entry = [
                'target' => (string) ($rawPath['target'] ?? ''),
                'seed' => (string) ($rawPath['seed'] ?? ''),
                'depth' => (int) ($rawPath['depth'] ?? 0),
                'cross_layer' => (bool) ($rawPath['cross_layer'] ?? false),
                'chain' => $chain,
            ];
            $chars += strlen((string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $paths[] = $entry;
            if (count($paths) >= $cap) {
                break;
            }
        }

        return [
            'present' => $paths !== [],
            'paths' => $paths,
            'chars' => $chars,
            'provenance' => [
                'provider_bound' => (bool) ($result['provider_bound'] ?? true),
                'ranking' => (string) ($result['ranking'] ?? ''),
                'seeds' => count((array) ($result['seeds'] ?? [])),
                'cross_layer_paths' => (int) data_get($result, 'counts.cross_layer_paths', 0),
                'note' => self::HONESTY_LABEL,
            ],
        ];
    }

    /**
     * Semantic memory section — the hybrid recall returns provider-safe REDACTED
     * projections only (filtered by AtlasMemoryPrivacyService + external_ai_allowed),
     * keyed on the file's stem/path so decisions/memories that reference the file
     * surface. Never raw bodies; bounded by the row cap.
     *
     * @return array{present:bool, items:list<array<string,mixed>>, chars:int, provenance:array<string,mixed>}
     */
    private function memorySection(string $relPath, string $stem, string $workspaceId, int $cap): array
    {
        $empty = [
            'present' => false,
            'items' => [],
            'chars' => 0,
            'provenance' => ['policy' => 'provider_safe_only', 'note' => self::HONESTY_LABEL],
        ];

        $query = trim($stem !== '' ? $stem : $relPath);
        if ($query === '') {
            return $empty;
        }

        try {
            $recall = $this->memory->recall(
                $query,
                ['workspace' => $workspaceId],
                [],
                [
                    'limit' => $cap,
                    'requester' => 'atlas_file_context',
                ],
            );
        } catch (Throwable) {
            return $empty;
        }

        // RELEVANCE FLOOR (cite-or-omit) — the hybrid recall ranks by importance and
        // will return generic top memories even when the query matched NOTHING
        // literally. The N1 prompt-pack tolerates that (the prompt is real intent),
        // but this is the ACTIVE hook firing on EVERY file open — injecting the same
        // generic principles on every unknown file is noise, not "about this file".
        // So a recalled row is kept ONLY if the file's stem/basename/path literally
        // appears in its text (mirrors the AURG lexical seed's str_contains gate).
        $needles = $this->relevanceNeedles($stem, $relPath);

        $items = [];
        $chars = 0;
        foreach ((array) ($recall['recall'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = (string) ($row['title'] ?? '');
            $summary = (string) ($row['summary'] ?? '');
            $body = (string) ($row['body'] ?? ($row['snippet'] ?? ($row['excerpt'] ?? '')));
            // Cite-or-omit on the interactive hot path: a contentless row (title +
            // summary + body shorter than a couple of words combined) is capture
            // noise — never inject "t — t" stubs into the engine's context. This is
            // a low-confidence-CONTENT drop, not a privacy filter (privacy is the
            // recall's job upstream); it just keeps the brain-delta signal honest.
            if (mb_strlen(trim($title.$summary.$body)) < 8) {
                continue;
            }
            // Drop a row that does not literally reference this file (over-recall).
            if ($needles !== [] && ! $this->mentionsAny(mb_strtolower($title.' '.$summary.' '.$body), $needles)) {
                continue;
            }
            $item = [
                'type' => (string) ($row['type'] ?? ''),
                'scope' => (string) ($row['scope'] ?? ''),
                'title' => $title,
                'summary' => $summary,
                'body' => $body,
                'privacy_class' => (string) ($row['privacy_class'] ?? ''),
                // ids/hashes only — auditable provenance, no raw content.
                'source_type' => (string) ($row['source_type'] ?? ''),
                'content_hash' => (string) ($row['content_hash'] ?? ''),
            ];
            $chars += strlen($title.$summary.$body);
            $items[] = $item;
            if (count($items) >= $cap) {
                break;
            }
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
    // Budget ceiling
    // ------------------------------------------------------------------

    /**
     * Enforce the total char ceiling on the MEASURED, assembled delta. Trims
     * trailing items — consumers first, then defined, then memory, then reality
     * paths — keeping at least the top hit of each non-empty section. When
     * $totalBudget <= 0 (uncapped) returns the sections unchanged.
     *
     * @param  array{present:bool, defined:list<array<string,mixed>>, consumers:list<array<string,mixed>>, chars:int, provenance:array<string,mixed>}  $neighbors
     * @param  array{present:bool, items:list<array<string,mixed>>, chars:int, provenance:array<string,mixed>}  $memory
     * @param  array{present:bool, paths:list<array<string,mixed>>, chars:int, provenance:array<string,mixed>}  $reality
     * @return array{0:array{present:bool, defined:list<array<string,mixed>>, consumers:list<array<string,mixed>>, chars:int, provenance:array<string,mixed>}, 1:array{present:bool, items:list<array<string,mixed>>, chars:int, provenance:array<string,mixed>}, 2:array{present:bool, paths:list<array<string,mixed>>, chars:int, provenance:array<string,mixed>}}
     */
    private function enforceTotalCeiling(int $totalBudget, array $neighbors, array $memory, array $reality): array
    {
        if ($totalBudget <= 0) {
            return [$neighbors, $memory, $reality];
        }

        $total = fn (): int => (int) $neighbors['chars'] + (int) $memory['chars'] + (int) $reality['chars'];

        // 1) Drop trailing CONSUMERS (keep >= 1 if any).
        while ($total() > $totalBudget && count($neighbors['consumers']) > 1) {
            array_pop($neighbors['consumers']);
            $neighbors['consumers'] = array_values($neighbors['consumers']);
            $neighbors['chars'] = $this->neighborsChars($neighbors);
        }
        // 2) Drop trailing DEFINED (keep >= 1 if any).
        while ($total() > $totalBudget && count($neighbors['defined']) > 1) {
            array_pop($neighbors['defined']);
            $neighbors['defined'] = array_values($neighbors['defined']);
            $neighbors['chars'] = $this->neighborsChars($neighbors);
        }
        // 3) Drop trailing MEMORY (keep >= 1 if any).
        while ($total() > $totalBudget && count($memory['items']) > 1) {
            array_pop($memory['items']);
            $memory['items'] = array_values($memory['items']);
            $memory['chars'] = $this->memoryItemsChars($memory['items']);
        }
        // 4) Drop trailing PATHS (keep >= 1 if any).
        while ($total() > $totalBudget && count($reality['paths']) > 1) {
            array_pop($reality['paths']);
            $reality['paths'] = array_values($reality['paths']);
            $reality['chars'] = $this->pathsChars($reality['paths']);
        }

        $neighbors['present'] = $neighbors['defined'] !== [] || $neighbors['consumers'] !== [];
        $memory['present'] = $memory['items'] !== [];
        $reality['present'] = $reality['paths'] !== [];

        return [$neighbors, $memory, $reality];
    }

    /**
     * @param  array{defined:list<array<string,mixed>>, consumers:list<array<string,mixed>>}  $neighbors
     */
    private function neighborsChars(array $neighbors): int
    {
        $chars = 0;
        foreach (array_merge($neighbors['defined'], $neighbors['consumers']) as $item) {
            $chars += $this->neighborItemChars($item);
        }

        return $chars;
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function neighborItemChars(array $item): int
    {
        return strlen(
            (string) ($item['symbol_name'] ?? '')
            .(string) ($item['file_path'] ?? '')
            .(string) ($item['signature'] ?? ''),
        );
    }

    /**
     * @param  list<array<string,mixed>>  $items
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
     * @param  list<array<string,mixed>>  $paths
     */
    private function pathsChars(array $paths): int
    {
        $chars = 0;
        foreach ($paths as $path) {
            $chars += strlen((string) json_encode($path, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return $chars;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function neighborItem(array $row): array
    {
        return [
            'symbol_name' => (string) ($row['symbol_name'] ?? ''),
            'symbol_type' => (string) ($row['symbol_type'] ?? ''),
            'file_path' => (string) ($row['file_path'] ?? ''),
            'module_slug' => (string) ($row['module_slug'] ?? ''),
            'signature' => (string) ($row['signature'] ?? ''),
        ];
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    /**
     * Render the brain-delta as the compact markdown block the hook injects.
     * Empty sections are labelled honestly (never silently dropped) so the
     * consumer can tell "the brain has nothing here" from "not consulted".
     *
     * @param  array<string,mixed>  $delta
     */
    private function renderMarkdown(array $delta): string
    {
        $lines = [];
        $lines[] = '# Atlas brain — what is known about this file (AOBG)';
        $lines[] = sprintf(
            'file=%s  workspace=%s  provider-bound=yes  %s',
            (string) ($delta['path'] ?? ''),
            (string) ($delta['workspace'] ?? ''),
            self::HONESTY_LABEL,
        );
        $lines[] = '';

        // 1) decisions / missions (AURG paths) — the "this file is governed by".
        $lines[] = '## Decisions / missions touching this file (reality graph)';
        $paths = (array) ($delta['reality_graph_paths'] ?? []);
        if ($paths === []) {
            $lines[] = '_no provider-safe paths from the brain for this file_';
        } else {
            foreach ($paths as $path) {
                $chain = array_map(
                    static fn (array $n): string => (string) (($n['label'] ?? '') !== '' ? $n['label'] : ($n['id'] ?? '')),
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

        // 2) memory / decisions referencing the file (redacted).
        $lines[] = '## Memory referencing this file (provider-safe, redacted)';
        $memory = (array) ($delta['memory'] ?? []);
        if ($memory === []) {
            $lines[] = '_no provider-safe memory recalled for this file_';
        } else {
            foreach ($memory as $item) {
                $title = (string) ($item['title'] ?? '');
                $summary = trim((string) ($item['summary'] ?? ($item['body'] ?? '')));
                $lines[] = sprintf(
                    '- [%s] %s%s',
                    ($item['type'] ?? '') !== '' ? (string) $item['type'] : 'memory',
                    $title !== '' ? $title : '(untitled)',
                    $summary !== '' ? ' — '.mb_substr($summary, 0, 180) : '',
                );
            }
        }
        $lines[] = '';

        // 3) consumed by Z (code neighbors).
        $lines[] = '## Consumed by (code-graph neighbors)';
        $consumers = (array) ($delta['consumers'] ?? []);
        if ($consumers === []) {
            $lines[] = '_no known consumers in this workspace index_';
        } else {
            foreach ($consumers as $item) {
                $lines[] = sprintf(
                    '- %s [%s]%s',
                    (string) ($item['symbol_name'] ?? ''),
                    (string) ($item['file_path'] ?? 'n/a'),
                    ($item['symbol_type'] ?? '') !== '' ? ' type='.(string) $item['symbol_type'] : '',
                );
            }
        }
        $lines[] = '';

        // 4) symbols defined here (orientation).
        $lines[] = '## Symbols defined in this file';
        $defined = (array) ($delta['defined_symbols'] ?? []);
        if ($defined === []) {
            $lines[] = '_file not in the workspace code index_';
        } else {
            foreach ($defined as $item) {
                $lines[] = sprintf(
                    '- %s (%s)',
                    (string) ($item['symbol_name'] ?? ''),
                    ($item['symbol_type'] ?? '') !== '' ? (string) $item['symbol_type'] : 'symbol',
                );
            }
        }

        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------
    // Path + workspace helpers (fail-safe — never throw)
    // ------------------------------------------------------------------

    /**
     * Resolve the workspace id ONCE: explicit `workspace` (path or id) wins, else
     * `cwd`, else the primary default. Fail-safe — never throws.
     *
     * @param  array<string,mixed>  $opts
     */
    private function resolveWorkspaceId(array $opts): string
    {
        try {
            $explicit = $this->stringOpt($opts, 'workspace');
            if ($explicit !== null) {
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
     * Reduce an absolute path to a workspace-relative one when it sits under the
     * workspace root (so the read-model's relative file_path keys match). An
     * already-relative path is normalised (leading "./" + slashes) and returned.
     *
     * @param  array<string,mixed>  $opts
     */
    private function relativePath(string $path, array $opts): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '') {
            return '';
        }
        $path = preg_replace('#^\./#', '', $path) ?? $path;

        // Try to strip a known root prefix: explicit workspace path, cwd, or base_path.
        $roots = [];
        foreach (['workspace', 'cwd'] as $key) {
            $candidate = $this->stringOpt($opts, $key);
            if ($candidate !== null) {
                $roots[] = str_replace('\\', '/', $candidate);
            }
        }
        try {
            $roots[] = str_replace('\\', '/', base_path());
        } catch (Throwable) {
            // base_path unavailable — relative-path normalisation still applies.
        }

        foreach ($roots as $root) {
            $root = rtrim($root, '/');
            if ($root !== '' && str_starts_with($path, $root.'/')) {
                return ltrim(substr($path, strlen($root) + 1), '/');
            }
        }

        return ltrim($path, '/');
    }

    /**
     * The literal needles a memory must mention to count as "about this file":
     * the class/stem, the basename, and the relative path. Lowercased + deduped;
     * sub-3-char tokens dropped (too generic to gate on). Empty list disables the
     * floor (so a path-less / pathological input never starves the recall to zero).
     *
     * @return list<string>
     */
    private function relevanceNeedles(string $stem, string $relPath): array
    {
        $needles = [];
        foreach ([$stem, $this->basename($relPath), $relPath] as $candidate) {
            $candidate = mb_strtolower(trim((string) $candidate));
            if ($candidate !== '' && mb_strlen($candidate) >= 3 && ! in_array($candidate, $needles, true)) {
                $needles[] = $candidate;
            }
        }

        return $needles;
    }

    /**
     * @param  list<string>  $needles
     */
    private function mentionsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function basename(string $relPath): string
    {
        if ($relPath === '') {
            return '';
        }
        $parts = explode('/', $relPath);

        return (string) end($parts);
    }

    /**
     * The class/symbol stem a consumer references: strip the final extension only
     * ("Foo.php" -> "Foo", "schema.test.ts" -> "schema.test"). Empty when none.
     */
    private function stem(string $basename): string
    {
        $basename = trim($basename);
        if ($basename === '') {
            return '';
        }
        $dot = strrpos($basename, '.');
        if ($dot === false || $dot === 0) {
            return $basename;
        }

        return substr($basename, 0, $dot);
    }

    /**
     * @param  array<string,mixed>  $opts
     */

    /**
     * @param  array<string,mixed>  $opts
     */
}
