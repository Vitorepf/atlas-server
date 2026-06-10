<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
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

    private const CANDIDATE_LIMIT_PER_TERM = 80;

    /**
     * Minimum term length. A 1-char term ('a') would LIKE-match almost every symbol,
     * drowning real relevance and blowing the candidate cap with noise, so single
     * characters are dropped from the term set.
     */
    private const MIN_TERM_LENGTH = 2;

    /**
     * Natural-language glue that should not drive code retrieval. Keeping this
     * small avoids hiding real domain words while filtering broad PT/EN prompts.
     *
     * @var array<string,true>
     */
    private const STOP_TERMS = [
        'a' => true, 'as' => true, 'o' => true, 'os' => true,
        'de' => true, 'da' => true, 'das' => true, 'do' => true, 'dos' => true,
        'e' => true, 'ou' => true, 'em' => true, 'no' => true, 'na' => true,
        'nos' => true, 'nas' => true, 'por' => true, 'para' => true, 'com' => true,
        'sem' => true, 'que' => true, 'se' => true, 'ao' => true, 'aos' => true,
        'the' => true, 'and' => true, 'or' => true, 'of' => true, 'to' => true,
        'for' => true, 'with' => true, 'without' => true, 'before' => true, 'after' => true,
    ];

    /**
     * Human-facing Atlas terms often come from Portuguese prompts or acronyms,
     * while the indexed code is mostly English/PHP identifiers.
     *
     * @var array<string,array<int,string>>
     */
    private const TERM_EXPANSIONS = [
        'aobg' => ['open', 'brain', 'context', 'pack', 'gateway'],
        'memoria' => ['memory'],
        'memorias' => ['memory'],
        'governanca' => ['governance'],
        'alteracao' => ['change'],
        'alteracoes' => ['change'],
        'mudanca' => ['change'],
        'mudancas' => ['change'],
        'impacto' => ['impact'],
        'codigo' => ['code'],
        'documentacao' => ['docs', 'documentation'],
        'compreensao' => ['context', 'intelligence'],
    ];

    /**
     * Test symbols are useful for explicit test-impact work, but noisy for architecture
     * recall ("AOBG", "Open Brain", etc.) because test names often repeat every owner term.
     *
     * @var array<string,true>
     */
    private const TEST_TERMS = [
        'test' => true,
        'tests' => true,
        'teste' => true,
        'testes' => true,
        'testing' => true,
        'spec' => true,
        'coverage' => true,
        'validacao' => true,
        'verificacao' => true,
    ];

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

        return $this->expandTerms($this->extractTerms($augmented));
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
        $query = $this->asciiFold($query);
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
            if (isset(self::STOP_TERMS[$normalized])) {
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
     * @param  array<int,string>  $terms
     * @return array<int,string>
     */
    private function expandTerms(array $terms): array
    {
        $expanded = [];
        foreach ($terms as $term) {
            $expanded[$term] = true;
            foreach (self::TERM_EXPANSIONS[$term] ?? [] as $extra) {
                if (! isset(self::STOP_TERMS[$extra])) {
                    $expanded[$extra] = true;
                }
            }
        }

        return array_keys($expanded);
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
            if (! DatabaseTableAvailability::has('atlas_engineering_code_symbols')) {
                return [];
            }

            $rows = collect();
            $seenRows = [];
            $hasWorkspaceId = DatabaseTableAvailability::hasColumn('atlas_engineering_code_symbols', 'workspace_id');
            $includeTests = $this->shouldIncludeTests($terms);

            foreach ($terms as $term) {
                // Escape LIKE wildcards in the term so a literal '%'/'_' in a
                // query token is matched literally, not as a wildcard.
                $like = '%'.$this->escapeLike($term).'%';
                $query = DB::table('atlas_engineering_code_symbols')
                    ->where('status', 'active')
                    ->where('symbol_type', '!=', 'doc_heading')
                    ->where('file_path', 'not like', 'docs/%')
                    ->where(function ($q) use ($like): void {
                        $q->where('symbol_name', 'like', $like)
                            ->orWhere('file_path', 'like', $like)
                            ->orWhere('signature', 'like', $like);
                    });
                if (! $includeTests) {
                    $query
                        ->where('symbol_type', '!=', 'test_method')
                        ->where('file_path', 'not like', 'tests/%');
                }

                // Scope to the workspace only when the read-model is W-1-keyed; on a pre-W-1
                // table (no column) every row is implicitly the primary workspace.
                if ($hasWorkspaceId) {
                    $query->where('workspace_id', $workspaceId);
                }

                foreach ($query
                    ->orderByRaw($this->symbolTypePrioritySql())
                    ->orderByRaw('length(symbol_name) asc')
                    ->orderBy('symbol_name')
                    ->limit(self::CANDIDATE_LIMIT_PER_TERM)
                    ->get(['symbol_name', 'symbol_type', 'file_path', 'signature']) as $row) {
                    $key = (string) ($row->symbol_type ?? '').'|'.(string) ($row->symbol_name ?? '').'|'.(string) ($row->file_path ?? '');
                    if (isset($seenRows[$key])) {
                        continue;
                    }
                    $seenRows[$key] = true;
                    $rows->push($row);
                    if ($rows->count() >= self::CANDIDATE_LIMIT) {
                        break 2;
                    }
                }
            }
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
            $symbolType = (string) ($row->symbol_type ?? '');
            $filePath = (string) ($row->file_path ?? '');
            $candidates[] = [
                'id' => 'sym:'.$symbolName,
                'tokens' => $this->estimateTokens($signature !== '' ? $signature : $symbolName),
                'signature' => $signature,
                'symbol_type' => $symbolType,
                'file_path' => $filePath,
                // Split CamelCase / snake_case / path separators so BM25 matches query
                // terms ("secret","scanner") against identifiers ("CodeGraphSecretScanner").
                'rank_text' => trim($this->tokenizeIdentifier($symbolName).' '.$this->tokenizeIdentifier($filePath).' '.$signature),
            ];
            $lastKey = array_key_last($candidates);
            $candidates[$lastKey]['fallback_score'] = $this->fallbackScore(
                $terms,
                (string) $candidates[$lastKey]['rank_text'],
                $symbolType,
                $filePath,
            );
        }

        // AP-815 A3/E-6: re-rank by the python hybrid (BM25) ranker — true relevance,
        // not the crude longest-name heuristic — when the runtime is enabled. Falls back
        // deterministically to length/name order if the runtime is blocked/unavailable.
        $reranked = $this->hybridRerank($terms, $candidates);
        if ($reranked !== null) {
            $candidates = $this->sortByFallbackScore($reranked);
        } else {
            $candidates = $this->sortByFallbackScore($candidates);
        }

        foreach ($candidates as &$candidate) {
            unset($candidate['rank_text']);
            unset($candidate['fallback_score']);
        }
        unset($candidate);

        return $candidates;
    }

    /**
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array<int,array<string,mixed>>
     */
    private function sortByFallbackScore(array $candidates): array
    {
        usort($candidates, static function (array $a, array $b): int {
            $byScore = ((int) ($b['fallback_score'] ?? 0)) <=> ((int) ($a['fallback_score'] ?? 0));
            if ($byScore !== 0) {
                return $byScore;
            }

            $byLength = mb_strlen((string) $b['id']) <=> mb_strlen((string) $a['id']);
            if ($byLength !== 0) {
                return $byLength;
            }

            return strcmp((string) $a['id'], (string) $b['id']);
        });

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
     * Deterministic lexical score for the no-runtime fallback. Rewards distinct query
     * term coverage and lightly rewards repeated mentions, so broad Atlas overview
     * queries prefer symbols/paths that actually mention memory + architecture + code
     * graph over merely long names.
     *
     * @param  array<int,string>  $terms
     */
    private function fallbackScore(array $terms, string $text, string $symbolType, string $filePath): int
    {
        $haystack = mb_strtolower($text);
        $tokens = $this->tokensFor($text);
        $tokenCounts = array_count_values($tokens);
        $score = 0;
        $covered = 0;

        foreach ($terms as $term) {
            $termTokens = $this->tokensFor($this->tokenizeIdentifier($term));
            if ($termTokens === []) {
                continue;
            }

            $allPresent = true;
            $repetitions = 0;
            foreach ($termTokens as $needle) {
                if (! isset($tokenCounts[$needle])) {
                    $allPresent = false;
                    break;
                }
                $repetitions += (int) $tokenCounts[$needle];
            }

            if ($allPresent) {
                $covered++;
                $score += $this->termWeight(implode(' ', $termTokens)) * count($termTokens);
                $score += min(6, $repetitions);
                continue;
            }

            $needle = implode(' ', $termTokens);
            if (mb_strlen($needle) >= 5 && str_contains($haystack, $needle)) {
                $covered++;
                $score += max(8, (int) floor($this->termWeight($needle) / 2));
            }
        }

        if ($covered === 0) {
            return 0;
        }

        $score += $covered * 12;
        $score += $this->symbolTypeScore($symbolType);
        $score += $this->filePathScore($filePath);
        $score -= min(60, intdiv(strlen($text), 500) * 8);

        return $score;
    }

    private function symbolTypePrioritySql(): string
    {
        return "case symbol_type
            when 'class' then 0
            when 'interface' then 1
            when 'trait' then 2
            when 'enum' then 3
            when 'cli_command' then 4
            when 'route' then 5
            when 'file' then 6
            when 'migration' then 8
            when 'method' then 10
            when 'function' then 11
            when 'test_method' then 20
            when 'doc_heading' then 30
            else 40
        end";
    }

    /**
     * @param  array<int,string>  $terms
     */
    private function shouldIncludeTests(array $terms): bool
    {
        foreach ($terms as $term) {
            foreach ($this->tokensFor($this->tokenizeIdentifier($term)) as $token) {
                if (isset(self::TEST_TERMS[$token])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function symbolTypeScore(string $symbolType): int
    {
        return match ($symbolType) {
            'class', 'interface', 'trait', 'enum' => 10,
            'cli_command' => 8,
            'file' => 8,
            'route' => 6,
            'method', 'function' => 2,
            'test_method' => -40,
            'doc_heading' => -4,
            default => 0,
        };
    }

    private function termWeight(string $term): int
    {
        return match ($term) {
            'aobg' => 56,
            'mcp' => 34,
            'open' => 30,
            'brain' => 28,
            'gateway' => 24,
            'memory', 'memoria' => 22,
            'context', 'pack' => 20,
            'bootstrap', 'session' => 16,
            'impact', 'impacto', 'change' => 16,
            'code', 'intelligence' => 10,
            default => 14,
        };
    }

    private function filePathScore(string $filePath): int
    {
        $path = mb_strtolower($filePath);
        $compact = str_replace(['/', '\\', '_', '-', '.'], '', $path);
        $score = match (true) {
            str_contains($compact, 'openbrain') => 46,
            str_contains($compact, 'aobg') => 46,
            str_contains($compact, 'contextpack') => 36,
            default => 0,
        };

        $score += match (true) {
            str_starts_with($filePath, 'app/Services/') => 8,
            str_starts_with($filePath, 'app/Console/Commands/') => 6,
            str_starts_with($filePath, 'app/') => 4,
            str_starts_with($filePath, 'routes/') => 2,
            str_starts_with($filePath, 'database/migrations/') => -6,
            str_starts_with($filePath, 'tests/') => -24,
            str_starts_with($filePath, 'docs/') => -2,
            default => 0,
        };

        return $score;
    }

    /**
     * @return array<int,string>
     */
    private function tokensFor(string $text): array
    {
        $matches = [];
        if (preg_match_all('/[a-z0-9]+/i', mb_strtolower($this->asciiFold($text)), $matches) === false) {
            return [];
        }

        return array_values(array_filter(
            $matches[0],
            static fn (string $token): bool => mb_strlen($token) >= self::MIN_TERM_LENGTH,
        ));
    }

    private function asciiFold(string $text): string
    {
        return strtr($text, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A', 'Ä' => 'A',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'Ó' => 'O', 'Ò' => 'O', 'Õ' => 'O', 'Ô' => 'O', 'Ö' => 'O',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'ç' => 'c', 'Ç' => 'C',
        ]);
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
