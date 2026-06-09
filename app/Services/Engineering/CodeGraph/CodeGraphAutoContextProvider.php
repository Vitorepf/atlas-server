<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

use Throwable;

/**
 * AP-815 · I-4 — auto-pull a graph context pack into the agent flow (FLAG-GATED, default-OFF).
 *
 * Dev/Forge/the loop already gather a task's context by hand; this provider is the
 * single, composable seam that turns a TASK DESCRIPTOR (a free-text query and/or the
 * files/symbols the task touches) into a ready-to-inject context pack, by composing the
 * two already-green AP-815 blocks:
 *
 *   - I-3 {@see CodeGraphReviewContextAssembler}: changed symbols + their reverse-
 *     reachability BLAST-RADIUS (who depends on the change) → a budgeted pack. Used when
 *     the descriptor names changed files / changed node ids (the "what does this task
 *     touch, and what could it break" path).
 *   - E-3 {@see CodeGraphContextPackAssembler}: the frugal token-budget packer. Used in
 *     query-only mode after this provider applies a crude keyword relevance ordering over
 *     the supplied node set (the deterministic floor the heavy [py] semantic ranker will
 *     later sit on top of — same pack contract, only the candidate ORDER changes).
 *
 * WHY A PROVIDER, NOT A LOOP EDIT (the I-4 contract):
 *   This block is ADDITIVE + DEFAULT-SAFE. It is a standalone service so wiring it into
 *   ANY existing context-gathering seam is a pure no-op until the operator flips the flag:
 *
 *     config('atlas.code_graph.auto_context', false)   // default OFF
 *
 *   When the flag is OFF, {@see provide()} returns a DISABLED, EMPTY pack WITHOUT touching
 *   either assembler, the DB, the clock, or any provider — so an existing call-site that
 *   does `$provider->provide(...)` and merges the result keeps byte-identical behaviour
 *   (it merges nothing). Only an explicit flip to ON activates the composition. Existing
 *   loop/Dev/Forge code is therefore never edited to land this capability.
 *
 * Determinism & fail-safety (house contract): pure-ish — the only impurity is reading the
 * config flag (and the assemblers' own inline config defaults). No DB, no clock, no
 * random, no provider call. Same descriptor + node set always yields byte-identical
 * output. NEVER throws: any unexpected error degrades to the same disabled/empty pack as
 * the flag-OFF path (context auto-pull is best-effort recall, never a gate).
 *
 * [php] by the runtime-language boundary: it GOVERNS what context enters the agent window
 * (an orchestration + budgeting decision over already-extracted graph data); it does not
 * compute the heavy relevance ranking or read the graph itself.
 */
class CodeGraphAutoContextProvider
{
    public const SCHEMA = 'atlas.code_graph.auto_context.v1';

    /** The two retrieval modes the descriptor can resolve to. */
    public const MODE_DISABLED = 'disabled';

    public const MODE_REVIEW = 'review';   // changed files / node ids → I-3 blast-radius pack

    public const MODE_QUERY = 'query';     // free-text query → E-3 keyword-ranked pack

    /** Default token budget when the descriptor does not carry one (mirrors atlas:ctx). */
    private const DEFAULT_BUDGET = 4000;

    public function __construct(
        private readonly CodeGraphContextPackAssembler $packAssembler,
        private readonly CodeGraphReviewContextAssembler $reviewAssembler,
    ) {}

    /**
     * Build a ready context pack for a task descriptor — or a no-op when the flag is OFF.
     *
     * @param  array<string,mixed>  $descriptor  the task descriptor. Recognised keys (all
     *                                           optional; anything absent degrades gracefully):
     *                                           - `query` (string): free-text task description; matched as keywords against the
     *                                           supplied node set in QUERY mode.
     *                                           - `changed_files` (array<int,string>): file paths the task touches; resolved to
     *                                           node ids via each node's `file_path` (REVIEW mode).
     *                                           - `changed_node_ids` (array<int,string>): symbol node ids the task touches
     *                                           directly (REVIEW mode); merged with any resolved from `changed_files`.
     *                                           - `budget` (int): token budget for the pack (default {@see DEFAULT_BUDGET};
     *                                           clamped >= 0).
     *                                           - `opts` (array<string,mixed>): forwarded verbatim to the underlying assembler
     *                                           (e.g. `fill_gaps`, `blast_depth`).
     *                                           REVIEW mode wins when a non-empty changed set is present; otherwise QUERY mode is
     *                                           used when there is a query; otherwise the result is an empty (but ENABLED) pack.
     * @param  array<int,array<string,mixed>>  $nodes  the candidate node set this provider
     *                                                 may draw from (each SHOULD carry `id`; for REVIEW a `file_path` to resolve changed
     *                                                 files, and a `tokens` cost for budgeting — the assemblers default a missing cost).
     *                                                 This is the ALREADY-RETRIEVED graph slice; the provider never reads the graph.
     * @param  array<int,array<string,mixed>>  $edges  graph edges (from_node_id/from,
     *                                                 to_node_id/to), used only in REVIEW mode for the blast-radius walk. Ignored in
     *                                                 QUERY mode.
     * @return array{
     *   schema_version: string,
     *   enabled: bool,
     *   mode: string,
     *   pack: array<string,mixed>,
     *   stats: array<string,int>
     * }
     *   When disabled (flag OFF or a fatal degrade): `enabled` is false, `mode` is
     *   {@see MODE_DISABLED}, and `pack` is an empty E-3-shaped pack. When enabled: `mode`
     *   is the resolved retrieval mode and `pack` is the assembled pack (E-3 shape in
     *   QUERY mode; the I-3 result's inner `pack` in REVIEW mode — always E-3-shaped).
     */
    public function provide(array $descriptor, array $nodes = [], array $edges = []): array
    {
        // Flag gate FIRST — when OFF, do not touch the assemblers at all (true no-op).
        if (! $this->enabled()) {
            return $this->disabledResult();
        }

        try {
            $budget = $this->resolveBudget($descriptor);
            $opts = $this->resolveOpts($descriptor);

            $changed = $this->resolveChangedNodeIds($descriptor, $nodes);

            if ($changed !== []) {
                return $this->reviewMode($changed, $edges, $nodes, $budget, $opts);
            }

            // QUERY mode only when the query yields at least one usable keyword term.
            // A blank query, or one of only symbols / too-short tokens, has nothing to
            // rank by → it is the same "nothing to pull" case as no descriptor at all
            // (mirrors atlas:ctx, which returns an empty pack for a zero-term query).
            $terms = $this->extractTerms($this->resolveQuery($descriptor));
            if ($terms !== []) {
                return $this->queryMode($terms, $nodes, $budget, $opts);
            }

            // Enabled but the descriptor named nothing to retrieve → an empty (enabled)
            // pack: honest "nothing to pull", not a failure.
            return $this->emptyEnabledResult();
        } catch (Throwable) {
            // Any unexpected error degrades to the disabled/empty pack — auto-pull is
            // best-effort recall and must never break the flow it is wired into.
            return $this->disabledResult();
        }
    }

    /** Whether the auto-context flag is enabled (default OFF / fail-safe). */
    public function enabled(): bool
    {
        return (bool) config('atlas.code_graph.auto_context', false);
    }

    /**
     * REVIEW mode: changed symbols + blast-radius → budgeted pack (composes I-3).
     *
     * @param  array<int,string>  $changed
     * @param  array<int,array<string,mixed>>  $edges
     * @param  array<int,array<string,mixed>>  $nodes
     * @param  array<string,mixed>  $opts
     * @return array{schema_version:string,enabled:bool,mode:string,pack:array<string,mixed>,stats:array<string,int>}
     */
    private function reviewMode(array $changed, array $edges, array $nodes, int $budget, array $opts): array
    {
        $nodeMeta = $this->nodeMetaById($nodes);

        $review = $this->reviewAssembler->assemble($changed, $edges, $nodeMeta, $budget, $opts);

        $pack = is_array($review['pack'] ?? null) ? $review['pack'] : $this->emptyPack($budget);

        return [
            'schema_version' => self::SCHEMA,
            'enabled' => true,
            'mode' => self::MODE_REVIEW,
            'pack' => $pack,
            'stats' => [
                'changed' => $this->statInt($review['stats'] ?? [], 'changed'),
                'blast_radius' => $this->statInt($review['stats'] ?? [], 'blast_radius'),
                'candidates' => $this->statInt($review['stats'] ?? [], 'candidates'),
                'included' => $this->packIncludedCount($pack),
            ],
        ];
    }

    /**
     * QUERY mode: crude keyword-rank the supplied node set, then E-3-pack it.
     *
     * The ranking is the same deterministic "crude relevance" the atlas:ctx command uses
     * (a node whose id/name/signature matches MORE query terms, longer, ranks higher),
     * applied PURELY over the passed node set so the provider never touches the DB. The
     * heavy [py] semantic ranker will later replace ONLY this ordering.
     *
     * @param  array<int,string>  $terms  pre-extracted, non-empty usable keyword terms
     * @param  array<int,array<string,mixed>>  $nodes
     * @param  array<string,mixed>  $opts
     * @return array{schema_version:string,enabled:bool,mode:string,pack:array<string,mixed>,stats:array<string,int>}
     */
    private function queryMode(array $terms, array $nodes, int $budget, array $opts): array
    {
        $ranked = $this->rankNodesByQuery($nodes, $terms);

        $pack = $this->packAssembler->assemble($ranked, $budget, $opts);

        return [
            'schema_version' => self::SCHEMA,
            'enabled' => true,
            'mode' => self::MODE_QUERY,
            'pack' => $pack,
            'stats' => [
                'changed' => 0,
                'blast_radius' => 0,
                'candidates' => count($ranked),
                'included' => $this->packIncludedCount($pack),
            ],
        ];
    }

    /**
     * Resolve the descriptor's changed set: explicit `changed_node_ids` PLUS any node id
     * resolved from `changed_files` via node `file_path`. Deduped, blank-stripped, first-
     * seen order preserved.
     *
     * @param  array<string,mixed>  $descriptor
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<int,string>
     */
    private function resolveChangedNodeIds(array $descriptor, array $nodes): array
    {
        $ids = [];

        foreach ($this->stringList($descriptor['changed_node_ids'] ?? null) as $id) {
            $ids[$id] = true;
        }

        $changedFiles = $this->stringList($descriptor['changed_files'] ?? null);
        if ($changedFiles !== []) {
            $changedFileSet = array_fill_keys($changedFiles, true);
            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }
                $filePath = $this->scalarString($node['file_path'] ?? null);
                if ($filePath === '' || ! isset($changedFileSet[$filePath])) {
                    continue;
                }
                $id = $this->scalarString($node['id'] ?? null);
                if ($id !== '') {
                    $ids[$id] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * Crude keyword relevance ranking over the supplied node set, deterministic and
     * driver-independent. A node scores by how many distinct query terms appear in its
     * searchable text (id + symbol_name + signature, lower-cased); ties break by longer
     * matched-text length (a more specific node), then by id ASC for a stable total
     * order. Nodes that match NO term are kept (rank last) so an over-narrow query never
     * starves the pack — the E-3 budget then trims the tail.
     *
     * @param  array<int,array<string,mixed>>  $nodes
     * @param  array<int,string>  $terms
     * @return array<int,array<string,mixed>> nodes in ranked order (highest relevance first)
     */
    private function rankNodesByQuery(array $nodes, array $terms): array
    {
        $scored = [];
        $ordinal = 0;
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $id = $this->scalarString($node['id'] ?? null);
            if ($id === '') {
                // A pack node must be addressable/injectable; an id-less node is dropped
                // rather than leaked into the window with a blank id.
                continue;
            }
            $haystack = $this->nodeHaystack($node);
            $matchCount = 0;
            foreach ($terms as $term) {
                if ($term !== '' && str_contains($haystack, $term)) {
                    $matchCount++;
                }
            }
            $scored[] = [
                'node' => $node,
                'matches' => $matchCount,
                'length' => mb_strlen($haystack),
                'id' => $id,
                'ord' => $ordinal++, // stable fallback when everything else ties
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            // More matched terms first.
            $byMatches = $b['matches'] <=> $a['matches'];
            if ($byMatches !== 0) {
                return $byMatches;
            }
            // Then a longer (more specific) node first.
            $byLength = $b['length'] <=> $a['length'];
            if ($byLength !== 0) {
                return $byLength;
            }
            // Then id ASC for a deterministic total order.
            $byId = strcmp($a['id'], $b['id']);
            if ($byId !== 0) {
                return $byId;
            }

            // Final tie-break: original input order (keeps the sort total + stable).
            return $a['ord'] <=> $b['ord'];
        });

        return array_map(static fn (array $s): array => $s['node'], $scored);
    }

    /**
     * A node's lower-cased searchable text: id + symbol_name + signature, joined. Used by
     * the QUERY-mode keyword ranker.
     *
     * @param  array<string,mixed>  $node
     */
    private function nodeHaystack(array $node): string
    {
        $parts = [
            $this->scalarString($node['id'] ?? null),
            $this->scalarString($node['symbol_name'] ?? null),
            $this->scalarString($node['signature'] ?? null),
        ];

        return strtolower(trim(implode(' ', array_filter($parts, static fn (string $p): bool => $p !== ''))));
    }

    /**
     * Split free text into distinct, usable lower-case keyword terms (runs of
     * [A-Za-z0-9._-], deduped, length >= 2 so a stray single char cannot match
     * everything). Mirrors the atlas:ctx term extraction so query behaviour is identical.
     *
     * @return array<int,string>
     */
    private function extractTerms(string $query): array
    {
        $matches = [];
        if (preg_match_all('/[A-Za-z0-9._-]+/', $query, $matches) === false) {
            return [];
        }

        $terms = [];
        foreach ($matches[0] as $token) {
            $normalized = strtolower(trim((string) $token, " \t\n\r\0\x0B._-"));
            if (mb_strlen($normalized) < 2) {
                continue;
            }
            $terms[$normalized] = true;
        }

        // array_keys() casts a purely-numeric string key (e.g. "12345") back to an int;
        // cast every term to string so a numeric query term stays a string for the strict
        // str_contains() needle in the ranker.
        return array_map(static fn ($t): string => (string) $t, array_keys($terms));
    }

    /**
     * Build nodeId => meta map for the I-3 assembler from the supplied node set. Each
     * node's own array is its meta (the assembler reads `tokens`/`signature`/… off it and
     * stamps `id`); a node without a usable id is skipped.
     *
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<string,array<string,mixed>>
     */
    private function nodeMetaById(array $nodes): array
    {
        $meta = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $id = $this->scalarString($node['id'] ?? null);
            if ($id === '') {
                continue;
            }
            $meta[$id] = $node;
        }

        return $meta;
    }

    /**
     * Token budget from the descriptor: a positive finite int, else the default. Clamped
     * to >= 0 (the assemblers honour a 0 budget by admitting nothing).
     *
     * @param  array<string,mixed>  $descriptor
     */
    private function resolveBudget(array $descriptor): int
    {
        $raw = $descriptor['budget'] ?? null;

        if (is_int($raw)) {
            return max(0, $raw);
        }
        if (is_float($raw) && ! is_nan($raw) && ! is_infinite($raw)) {
            return (int) max(0, (int) floor($raw));
        }
        if (is_string($raw) && is_numeric(trim($raw))) {
            $value = (float) trim($raw);
            if (! is_nan($value) && ! is_infinite($value)) {
                return (int) max(0, (int) floor($value));
            }
        }

        return self::DEFAULT_BUDGET;
    }

    /**
     * Per-call assembler overrides forwarded verbatim (e.g. fill_gaps, blast_depth). A
     * non-array `opts` degrades to no overrides.
     *
     * @param  array<string,mixed>  $descriptor
     * @return array<string,mixed>
     */
    private function resolveOpts(array $descriptor): array
    {
        $opts = $descriptor['opts'] ?? null;

        return is_array($opts) ? $opts : [];
    }

    /**
     * @param  array<string,mixed>  $descriptor
     */
    private function resolveQuery(array $descriptor): string
    {
        return $this->scalarString($descriptor['query'] ?? null);
    }

    /**
     * Coerce a value to a list of non-empty trimmed strings. Non-array → empty list; non-
     * scalar / blank entries dropped. Used for changed_files / changed_node_ids.
     *
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $s = $this->scalarString($item);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return $out;
    }

    /** Coerce a value to a trimmed string ('' when not a non-empty scalar). */
    private function scalarString(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * @param  array<string,mixed>  $pack
     */
    private function packIncludedCount(array $pack): int
    {
        return is_array($pack['included'] ?? null) ? count($pack['included']) : 0;
    }

    /**
     * @param  array<string,mixed>  $stats
     */
    private function statInt(mixed $stats, string $key): int
    {
        if (! is_array($stats)) {
            return 0;
        }
        $value = $stats[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * The disabled / fatal-degrade result: not enabled, no mode, an empty E-3-shaped pack.
     * Identical for flag-OFF and any caught error so a caller's merge is always a no-op.
     *
     * @return array{schema_version:string,enabled:bool,mode:string,pack:array<string,mixed>,stats:array<string,int>}
     */
    private function disabledResult(): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'enabled' => false,
            'mode' => self::MODE_DISABLED,
            'pack' => $this->emptyPack(0),
            'stats' => $this->zeroStats(),
        ];
    }

    /**
     * Enabled-but-nothing-to-retrieve result (no changed set, no query). Distinguished
     * from disabled by `enabled === true` so a caller can tell "off" from "on, found
     * nothing".
     *
     * @return array{schema_version:string,enabled:bool,mode:string,pack:array<string,mixed>,stats:array<string,int>}
     */
    private function emptyEnabledResult(): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'enabled' => true,
            'mode' => self::MODE_QUERY,
            'pack' => $this->emptyPack($this->resolveBudget([])),
            'stats' => $this->zeroStats(),
        ];
    }

    /**
     * An empty pack in the exact E-3 output shape, so consumers can treat enabled/disabled
     * results uniformly.
     *
     * @return array{included:array<int,mixed>,excluded:array<int,mixed>,estimated_tokens:int,budget:int,truncated:bool,count:int}
     */
    private function emptyPack(int $budget): array
    {
        return [
            'included' => [],
            'excluded' => [],
            'estimated_tokens' => 0,
            'budget' => max(0, $budget),
            'truncated' => false,
            'count' => 0,
        ];
    }

    /**
     * @return array<string,int>
     */
    private function zeroStats(): array
    {
        return [
            'changed' => 0,
            'blast_radius' => 0,
            'candidates' => 0,
            'included' => 0,
        ];
    }
}
