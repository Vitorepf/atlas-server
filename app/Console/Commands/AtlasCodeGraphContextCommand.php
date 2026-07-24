<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Engineering\CodeGraph\CodeGraphContextRetriever;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * AP-815 · I-2 — `atlas:ctx`: natural-language-ish context retrieval from the CLI.
 *
 * Ships a WORKING keyword reader over the code-intelligence symbol read-model plus
 * the E-3 budgeted pack, so an operator (or a provider) can ask
 *
 *     atlas:ctx "workspace identity resolver" --budget=4000
 *
 * and get back the smallest useful set of symbols that fit the token budget, ordered
 * by a crude relevance signal (longest symbol-name match first). The heavy [py]
 * semantic ranker is a deliberate follow-up; this block is the deterministic keyword
 * floor it will later sit on top of — the pack contract (and CLI surface) is identical,
 * so swapping the ranker in changes only the ORDER of candidates, never this command.
 *
 * Pipeline (all [php] — identity + a read query + a budgeting decision, no heavy data):
 *
 *   1. Split the free-text query into distinct terms (alnum/._- runs, deduped,
 *      length >= 2 so a stray "a"/"x" cannot match the whole table).
 *   2. Resolve the workspace via {@see CodeGraphWorkspaceIdentity} (the --workspace
 *      option is a path OR an id; empty → the primary 'atlas-server' default), so a
 *      second project's symbols never leak into this pack.
 *   3. Query atlas_engineering_code_symbols WHERE workspace_id = <resolved>
 *      AND status = 'active' AND symbol_name LIKE any term, capped at a sane limit,
 *      ordered longest-name-first (crude relevance: a more specific name is a stronger
 *      hit than a short generic one), then by name for a STABLE total order.
 *   4. Map each row to a pack candidate
 *      ['id' => 'sym:'.symbol_name, 'tokens' => ceil(strlen(signature||symbol_name)/4),
 *       'signature' => …, 'symbol_type' => …, 'file_path' => …].
 *   5. Hand the resolved (query, workspace, budget) to the shared
 *      {@see CodeGraphContextRetriever::packFor()} — the ONE implementation of the
 *      ranked+budgeted retrieval, shared with the auto-context provider — and print the
 *      resulting pack (table by default, or --json). This command is now a thin CLI
 *      surface over that retriever; the heavy term-extraction + A3 BM25 re-rank + E-3
 *      assembly logic lives in the retriever so every consumer shares one path.
 *
 * Fail-safe (house contract): this command NEVER throws and NEVER errors out for a
 * caller. An empty query, a query of only too-short terms, no matching symbols, a
 * missing/old read-model table (pre-W-1, no workspace_id), or a transient DB fault all
 * resolve to an EMPTY pack with exit code 0 — context retrieval is best-effort recall,
 * not a gate, so "nothing relevant found" is a normal answer, not a failure. The pack
 * is always the assembler's real output (so estimated_tokens/budget/truncated stay
 * honest), even when the candidate list is empty.
 */
class AtlasCodeGraphContextCommand extends Command
{
    use EmitsCanonicalJson;

    public const SCHEMA = 'atlas.code_graph.ctx_command.v1';

    /**
     * Minimum term length. A 1-char term ('a') would LIKE-match almost every symbol,
     * drowning real relevance and blowing the candidate cap with noise, so single
     * characters are dropped from the term set. Kept here purely for the REPORTED `terms`
     * meta in the command envelope; the retrieval itself re-derives terms identically
     * inside {@see CodeGraphContextRetriever}.
     */
    private const MIN_TERM_LENGTH = 2;

    protected $signature = 'atlas:ctx
        {query : Free-text query; matched as keywords against indexed symbol names}
        {--workspace= : Workspace path or id to search (AP-815; defaults to the primary atlas-server)}
        {--budget=4000 : Token budget for the assembled context pack}
        {--json : Output the pack as JSON instead of a table}';

    protected $description = 'AP-815 I-2: keyword context retrieval over the code-graph symbol read-model → a budgeted E-3 context pack. Fail-safe (empty pack, exit 0).';

    public function handle(
        CodeGraphWorkspaceIdentity $identity,
        CodeGraphContextRetriever $retriever,
    ): int {
        $query = (string) ($this->argument('query') ?? '');
        $budget = $this->resolveBudget();
        $workspaceId = $this->resolveWorkspaceId($identity);

        // Terms are reported in the command envelope; the retriever re-derives them
        // identically internally to build the pack (one shared retrieval path).
        $terms = $this->extractTerms($query);
        $pack = $retriever->packFor($query, $workspaceId, $budget);

        if ($this->boolOption('json')) {
            $this->emitJson($pack, $query, $workspaceId, $terms);

            return self::SUCCESS;
        }

        $this->emitTable($pack, $query, $workspaceId, $terms);

        return self::SUCCESS;
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

        return array_keys($terms);
    }

    /**
     * Resolve the --workspace option (path OR id) to a stable workspace_id. Empty /
     * non-string → the primary 'atlas-server' default. Resolution is itself fail-safe
     * (the identity service never throws), but we guard anyway and fall back to the
     * default on any unexpected error so the command can always run.
     */
    private function resolveWorkspaceId(CodeGraphWorkspaceIdentity $identity): string
    {
        try {
            $ws = $this->option('workspace');
            if (! is_string($ws) || trim($ws) === '') {
                return $identity->default();
            }

            return $identity->resolve($ws);
        } catch (Throwable) {
            try {
                return $identity->default();
            } catch (Throwable) {
                return 'atlas-server';
            }
        }
    }

    /**
     * Parse --budget to a non-negative int. Garbage / negative / non-numeric values
     * fall back to the documented 4000 default; a budget <= 0 is honoured by the
     * assembler (admits nothing) but we never produce a negative budget.
     */
    private function resolveBudget(): int
    {
        $raw = $this->option('budget');

        if (is_int($raw)) {
            return max(0, $raw);
        }
        if (is_string($raw) && is_numeric(trim($raw))) {
            $value = (float) trim($raw);
            if (! is_nan($value) && ! is_infinite($value)) {
                return (int) max(0, (int) floor($value));
            }
        }

        return 4000;
    }

    /**
     * Robust boolean option read (--json present → true), tolerant of how options are
     * passed in tests vs the CLI.
     */
    private function boolOption(string $name): bool
    {
        return (bool) $this->option($name);
    }

    /**
     * @param  array<string,mixed>  $pack
     * @param  array<int,string>  $terms
     */
    private function emitJson(array $pack, string $query, string $workspaceId, array $terms): void
    {
        $payload = [
            'schema' => self::SCHEMA,
            'query' => $query,
            'workspace_id' => $workspaceId,
            'terms' => array_values($terms),
            'budget' => $pack['budget'] ?? 0,
            'estimated_tokens' => $pack['estimated_tokens'] ?? 0,
            'truncated' => $pack['truncated'] ?? false,
            'included_count' => is_array($pack['included'] ?? null) ? count($pack['included']) : 0,
            'excluded_count' => is_array($pack['excluded'] ?? null) ? count($pack['excluded']) : 0,
            'pack' => $pack,
        ];

        $this->line($this->encode($payload));
    }

    /**
     * @param  array<string,mixed>  $pack
     * @param  array<int,string>  $terms
     */
    private function emitTable(array $pack, string $query, string $workspaceId, array $terms): void
    {
        $included = is_array($pack['included'] ?? null) ? $pack['included'] : [];
        $count = count($included);

        $this->info(sprintf(
            'atlas:ctx  query="%s"  workspace=%s  terms=%d  included=%d  ~%d/%d tokens%s',
            $query,
            $workspaceId,
            count($terms),
            $count,
            (int) ($pack['estimated_tokens'] ?? 0),
            (int) ($pack['budget'] ?? 0),
            ($pack['truncated'] ?? false) ? '  (truncated)' : '',
        ));

        if ($count === 0) {
            $this->warn('No matching symbols — empty context pack.');

            return;
        }

        $rows = [];
        foreach ($included as $node) {
            if (! is_array($node)) {
                continue;
            }
            $rows[] = [
                (string) ($node['id'] ?? ''),
                (string) ($node['symbol_type'] ?? ''),
                (int) ($node['tokens'] ?? 0),
                $this->truncate((string) ($node['signature'] ?? ($node['file_path'] ?? '')), 80),
            ];
        }

        $this->table(['id', 'type', 'tokens', 'signature / file'], $rows);
    }

    /** Truncate a display string so the table stays readable. */
    private function truncate(string $value, int $max): string
    {
        $value = trim($value);
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, max(1, $max - 1)).'…';
    }
}
