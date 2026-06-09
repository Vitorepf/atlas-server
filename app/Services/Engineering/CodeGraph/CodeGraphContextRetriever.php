<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * AP-815 · I-4 — the SINGLE, shared code-graph context retriever.
 *
 * This is the one implementation of "free-text query (+ optional changed files) ->
 * workspace-scoped, BM25-ranked, budgeted E-3 context pack". It was EXTRACTED verbatim
 * from {@see \App\Console\Commands\AtlasCodeGraphContextCommand} (the proven `atlas:ctx`
 * pipeline) so that the CLI command and every programmatic consumer (Dev/Forge/the loop
 * via {@see CodeGraphAutoContextProvider}) share ONE retrieval path instead of drifting
 * copies. The command now delegates to {@see packFor()}; the heavy logic lives here.
 *
 * Pipeline (all [php] — identity is the caller's, this is a read query + a budgeting
 * decision over an already-extracted symbol read-model, no heavy data):
 *
 *   1. Split the free-text query into distinct keyword terms (alnum/._- runs, deduped,
 *      length >= 2 so a stray single char cannot match the whole table). Any supplied
 *      changed-file paths are tokenised into additional terms (so a task that names the
 *      files it touches biases retrieval toward those files' identifiers) — this keeps
 *      the retriever ONE keyword-pack path; the I-3 reverse-reachability blast-radius
 *      path stays in {@see CodeGraphAutoContextProvider} and is out of scope here.
 *   2. Query atlas_engineering_code_symbols WHERE workspace_id = <given> AND
 *      status = 'active' AND symbol_name LIKE any term, capped at {@see CANDIDATE_LIMIT},
 *      ordered (in the fallback) longest-name-first then name ASC for a stable total.
 *   3. A3/E-6: re-rank the candidate pool by the python hybrid (BM25) ranker when the
 *      runtime is enabled (config real_edges + hybrid_rank); otherwise the deterministic
 *      keyword fallback order is used.
 *   4. Hand the ranked candidates to {@see CodeGraphContextPackAssembler::assemble()}
 *      under the budget and return the resulting E-3 pack.
 *
 * Fail-safe (house contract): {@see packFor()} NEVER throws. An empty query, a query of
 * only too-short terms, no matching symbols, a missing/old read-model table (pre-W-1, no
 * workspace_id), or a transient DB fault all resolve to an EMPTY pack — context retrieval
 * is best-effort recall, not a gate. The pack is always the assembler's real output (so
 * estimated_tokens/budget/truncated stay honest), even when the candidate list is empty.
 *
 * Determinism: identical inputs yield byte-identical output. The only impurities are the
 * symbol read query (a read model) and, when enabled, the hybrid-rank runtime call — both
 * mirrored exactly from the proven `atlas:ctx` command so behaviour is unchanged.
 */
class CodeGraphContextRetriever
{
    public const SCHEMA = 'atlas.code_graph.context_retriever.v1';

    /**
     * Hard ceiling on candidate rows pulled from the read-model before budgeting.
     * Kept well ABOVE the pack budget so the E-6 ranker SELECTS the most relevant
     * symbols from a wide pool instead of merely reordering a pack-sized slice of
     * arbitrary DB-order rows.
     */
    private const CANDIDATE_LIMIT = 400;

    /**
     * Minimum term length. A 1-char term ('a') would LIKE-match almost every symbol,
     * drowning real relevance and blowing the candidate cap with noise, so single
     * characters are dropped from the term set.
     */
    private const MIN_TERM_LENGTH = 2;

    /** Default token budget when a caller does not supply one (mirrors atlas:ctx). */
    public const DEFAULT_BUDGET = 4000;

    public function __construct(
        private readonly CodeGraphContextPackAssembler $assembler,
    ) {}

    /**
     * Build the budgeted E-3 context pack for a free-text query in a workspace.
     *
     * @param  string  $query  free-text task description; matched as keywords against
     *   indexed symbol names.
     * @param  string  $workspaceId  the ALREADY-RESOLVED workspace id (callers resolve a
     *   path/id via {@see CodeGraphWorkspaceIdentity}); rows are scoped to it when the
     *   read-model is W-1-keyed.
     * @param  int  $budget  token budget for the pack (default {@see DEFAULT_BUDGET};
     *   clamped >= 0; a 0 budget admits nothing). Garbage is the caller's concern — this
     *   takes an int and clamps the negative tail only.
     * @param  array<int,string>  $changedFiles  optional file paths the task touches;
     *   tokenised into additional keyword terms to bias retrieval toward those files.
     * @return array{
     *   included: array<int,mixed>,
     *   excluded: array<int,mixed>,
     *   estimated_tokens: int,
     *   budget: int,
     *   truncated: bool,
     *   count: int
     * }
     *   the same shape `atlas:ctx` returns (the E-3 assembler output).
     */
    public function packFor(string $query, string $workspaceId, int $budget = self::DEFAULT_BUDGET, array $changedFiles = []): array
    {
        $budget = max(0, $budget);

        $terms = $this->extractTermsForQuery($query, $changedFiles);
        $ranked = $terms === [] ? [] : $this->rankedCandidates($workspaceId, $terms);

        return $this->assembler->assemble($ranked, $budget);
    }

    /**
     * Extract the keyword term set from the query plus any supplied changed-file paths.
     * The changed-file paths are tokenised (CamelCase / snake_case / path separators)
     * before term extraction so e.g. `app/Services/SecretScanner.php` contributes
     * `secret`/`scanner` terms. The query terms come first (first-seen order preserved).
     *
     * @param  array<int,string>  $changedFiles
     * @return array<int,string> deduped lower-case terms
     */
    private function extractTermsForQuery(string $query, array $changedFiles): array
    {
        $augmented = $query;
        foreach ($changedFiles as $path) {
            if (is_string($path) && trim($path) !== '') {
                // Tokenise the path so its identifiers become keyword terms (the regex in
                // extractTerms would otherwise keep the path as one long dotted token).
                $augmented .= ' '.$this->tokenizeIdentifier($path);
            }
        }

        return $this->extractTerms($augmented);
    }

    /**
     * Split free text into distinct, usable keyword terms. Tokens are runs of
     * [A-Za-z0-9._-] (so namespaced/qualified symbol fragments survive), lower-cased
     * for case-insensitive matching, deduped (preserving first-seen order), and pruned
     * of any term shorter than {@see MIN_TERM_LENGTH}.
     *
     * @return array<int,string> deduped lower-case terms (empty when the query is blank
     *   or contains only too-short tokens)
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
            if (mb_strlen($normalized) < self::MIN_TERM_LENGTH) {
                continue;
            }
            // Dedup, first-seen order preserved (the term set drives an OR LIKE; order
            // is irrelevant to the SQL but a stable set keeps the reported meta stable).
            $terms[$normalized] = true;
        }

        // array_keys() casts a purely-numeric string key (e.g. "12345") back to an int;
        // cast every term to string so a numeric query term stays a string downstream.
        return array_map(static fn ($t): string => (string) $t, array_keys($terms));
    }

    /**
     * Pull and rank candidate symbols for the given workspace.
     *
     * Ordering is "crude relevance": longest symbol_name first (a longer, more specific
     * name is a stronger keyword hit than a short generic one), then symbol_name ASC as
     * a deterministic tie-break so the candidate list — and therefore the pack — is
     * byte-stable for identical inputs. Mapping each row to the pack-candidate shape the
     * E-3 assembler expects.
     *
     * Fail-safe: a missing/old table (no workspace_id column → pre-W-1) or any query
     * fault yields an empty list rather than an exception (recall is best-effort).
     *
     * @param  array<int,string>  $terms
     * @return array<int,array<string,mixed>> ranked pack candidates
     */
    private function rankedCandidates(string $workspaceId, array $terms): array
    {
        try {
            if (! Schema::hasTable('atlas_engineering_code_symbols')) {
                return [];
            }

            $query = DB::table('atlas_engineering_code_symbols')
                ->where('status', 'active')
                ->where(function ($q) use ($terms): void {
                    foreach ($terms as $term) {
                        // Escape LIKE wildcards in the term so a literal '%'/'_' in a
                        // query token is matched literally, not as a wildcard.
                        $q->orWhere('symbol_name', 'like', '%'.$this->escapeLike($term).'%');
                    }
                });

            // Scope to the workspace only when the read-model is W-1-keyed; on a pre-W-1
            // table (no column) every row is implicitly the primary workspace.
            if (Schema::hasColumn('atlas_engineering_code_symbols', 'workspace_id')) {
                $query->where('workspace_id', $workspaceId);
            }

            $rows = $query
                ->limit(self::CANDIDATE_LIMIT)
                ->get(['symbol_name', 'symbol_type', 'file_path', 'signature']);
        } catch (Throwable) {
            // Transient DB fault / unexpected driver error: degrade to no candidates.
            return [];
        }

        $candidates = [];
        foreach ($rows as $row) {
            $symbolName = (string) ($row->symbol_name ?? '');
            if ($symbolName === '') {
                continue;
            }
            $signature = $row->signature !== null ? (string) $row->signature : '';
            $candidates[] = [
                'id' => 'sym:'.$symbolName,
                'tokens' => $this->estimateTokens($signature !== '' ? $signature : $symbolName),
                'signature' => $signature,
                'symbol_type' => (string) ($row->symbol_type ?? ''),
                'file_path' => (string) ($row->file_path ?? ''),
                // Split CamelCase / snake_case / path separators so BM25 matches query
                // terms ("secret","scanner") against identifiers ("CodeGraphSecretScanner").
                'rank_text' => trim($this->tokenizeIdentifier($symbolName).' '.$this->tokenizeIdentifier((string) ($row->file_path ?? '')).' '.$signature),
            ];
        }

        // AP-815 A3/E-6: re-rank by the python hybrid (BM25) ranker — true relevance,
        // not the crude longest-name heuristic — when the runtime is enabled. Falls back
        // deterministically to length/name order if the runtime is blocked/unavailable.
        $reranked = $this->hybridRerank($terms, $candidates);
        if ($reranked !== null) {
            $candidates = $reranked;
        } else {
            usort($candidates, static function (array $a, array $b): int {
                $byLength = mb_strlen((string) $b['id']) <=> mb_strlen((string) $a['id']);
                if ($byLength !== 0) {
                    return $byLength;
                }

                return strcmp((string) $a['id'], (string) $b['id']);
            });
        }

        foreach ($candidates as &$candidate) {
            unset($candidate['rank_text']);
        }
        unset($candidate);

        return $candidates;
    }

    /**
     * AP-815 A3/E-6: re-order candidates by the python hybrid (BM25) ranker. Returns the
     * reordered list, or null when disabled/blocked so the caller keeps its fallback order.
     *
     * @param  array<int,string>  $terms
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array<int,array<string,mixed>>|null
     */
    private function hybridRerank(array $terms, array $candidates): ?array
    {
        if ($candidates === [] || $terms === [] || ! $this->hybridRankEnabled()) {
            return null;
        }

        $input = [];
        foreach ($candidates as $candidate) {
            $input[] = [
                'id' => (string) $candidate['id'],
                'text' => (string) ($candidate['rank_text'] ?? ''),
            ];
        }

        $query = implode(' ', $terms);
        $receipt = CodeGraphRuntimeInvoker::mintReceipt('hybrid_rank', ['n' => count($input)], 'atlas:ctx');
        $result = app(CodeGraphRuntimeInvoker::class)->invoke(
            'hybrid_rank',
            ['query' => $query, 'candidates' => $input, 'weights' => ['lexical' => 1.0]],
            ['timeout_seconds' => 30],
            $receipt,
        );

        if (($result['status'] ?? '') !== CodeGraphRuntimeInvoker::STATUS_SUCCEEDED) {
            return null;
        }

        $ranked = $result['artifacts'][0]['result'] ?? [];
        if (! is_array($ranked) || $ranked === []) {
            return null;
        }

        $byId = [];
        foreach ($candidates as $candidate) {
            $byId[(string) $candidate['id']] = $candidate;
        }

        $ordered = [];
        foreach ($ranked as $entry) {
            $id = is_array($entry) ? (string) ($entry['id'] ?? '') : '';
            if ($id !== '' && isset($byId[$id])) {
                $ordered[] = $byId[$id];
                unset($byId[$id]);
            }
        }
        foreach ($byId as $candidate) {
            $ordered[] = $candidate; // any unranked remainder (defensive) keeps recall
        }

        return $ordered;
    }

    private function hybridRankEnabled(): bool
    {
        return (bool) config('atlas.code_graph.real_edges', false)
            && (bool) config('atlas.code_graph.hybrid_rank', true);
    }

    /**
     * AP-815 A3: split an identifier/path into space-delimited words (CamelCase,
     * snake_case, and path separators) so the BM25 ranker matches query terms against
     * code identifiers (e.g. "CodeGraphSecretScanner" -> "Code Graph Secret Scanner").
     */
    private function tokenizeIdentifier(string $text): string
    {
        $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $text);
        $spaced = preg_replace('#[\\\\/_.:>\-]+#', ' ', (string) $spaced);

        return trim((string) preg_replace('/\s+/', ' ', (string) $spaced));
    }

    /**
     * Crude token estimate: ceil(strlen / 4) — the conventional ~4-chars-per-token
     * heuristic. Floored at 1 so a non-empty fragment is never costed as free against
     * the budget (the E-3 assembler also defends this, but keeping candidates honest at
     * the source avoids relying on its fallback).
     */
    private function estimateTokens(string $text): int
    {
        $length = strlen($text); // byte length intentionally (token cost ~ raw bytes)
        if ($length <= 0) {
            return 1;
        }

        return (int) max(1, (int) ceil($length / 4));
    }

    /**
     * Escape LIKE metacharacters (`\`, `%`, `_`) so a query term is matched literally.
     * Backslash first to avoid double-escaping the escapes we add. The default LIKE
     * escape character `\` is used (no custom ESCAPE clause needed across pgsql/sqlite).
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
