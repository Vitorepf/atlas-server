<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\RuntimeBoundary\SemanticRetrievalRuntime;
use Illuminate\Support\Str;
use Throwable;

/**
 * L3-6 — semantic retrieval over arbitrary context items (symbols + docs) for the
 * context-pack front door.
 *
 * The context-pack memory section already rides the real pgvector/semantic_rag
 * path ({@see \App\Services\Ai\AtlasHybridMemoryRetrievalService}); but that path
 * needs embeddings pre-stored in `semantic_notes`. This service is the complementary
 * AD-HOC ranker: given a query and a set of {id,text} candidates assembled at
 * request time (e.g. code-graph symbol cards, doc chunks), it ranks them by REAL
 * local-embedding cosine via {@see SemanticRetrievalRuntime::retrieve()} — the same
 * engine, embedding the candidates on the fly, no external store, no provider spend.
 *
 * Honesty contract (runtime_language_boundary canon):
 *   - Gated by config `atlas.aobg.semantic_retrieval` (default OFF). When OFF the
 *     caller gets the lexical ranking unchanged.
 *   - Fail-open: if the venv/engine is unavailable OR raises, it returns the lexical
 *     ranking — never a fabricated vector, never a crash.
 *   - The `mode` field on the result tells the caller honestly which path ran
 *     ('semantic' = real embeddings used, 'lexical' = degraded/off/unavailable).
 *   - {@see ::relevanceLift()} measures semantic vs lexical ordering so the lift is
 *     observed, not asserted.
 */
final class SemanticContextRetrievalService
{
    public function __construct(
        private readonly SemanticRetrievalRuntime $semanticRuntime,
    ) {}

    public const SCHEMA = 'atlas.aobg.semantic_context_retrieval.v1';

    /**
     * Rank candidate items for a query.
     *
     * @param  array<int,array{id?:string,text:string,metadata?:array<string,mixed>}>  $items
     * @param  positive-int  $limit
     * @return array{
     *   schema:string,
     *   mode:'semantic'|'lexical',
     *   query_hash:string,
     *   ranked:array<int,array{id:string,score:float,score_origin:string,rank:int}>,
     *   candidate_count:int
     * }
     */
    public function rank(string $query, array $items, int $limit = 8): array
    {
        $normalized = $this->normalizeItems($items);
        $query = trim($query);

        $lexical = $this->lexicalRanking($query, $normalized);

        if ($query === '' || $normalized === [] || ! $this->semanticEnabled() || ! $this->semanticRuntime->available()) {
            return $this->result('lexical', $query, $lexical, $limit);
        }

        try {
            $result = $this->semanticRuntime->retrieve(
                documents: array_values($normalized),
                query: $query,
                k: count($normalized),
            );
        } catch (Throwable) {
            return $this->result('lexical', $query, $lexical, $limit);
        }

        $semantic = $this->semanticRanking($result, $normalized);
        if ($semantic === []) {
            return $this->result('lexical', $query, $lexical, $limit);
        }

        return $this->result('semantic', $query, $semantic, $limit);
    }

    /**
     * Observed relevance lift of the semantic ordering over the lexical baseline,
     * given a set of ids known to be relevant. Returns null when the engine is
     * unavailable (no fabricated lift). Lift = (semantic relevant@k) - (lexical
     * relevant@k) as a fraction of k — a positive number means semantic surfaced
     * more relevant items in the top-k than lexical did.
     *
     * @param  array<int,array{id?:string,text:string}>  $items
     * @param  array<int,string>  $relevantIds
     * @param  positive-int  $k
     * @return array{semantic_recall_at_k:float, lexical_recall_at_k:float, lift:float, mode:string}|null
     */
    public function relevanceLift(string $query, array $items, array $relevantIds, int $k = 3): ?array
    {
        $normalized = $this->normalizeItems($items);
        if ($query === '' || $normalized === [] || $relevantIds === []) {
            return null;
        }
        if (! $this->semanticEnabled() || ! $this->semanticRuntime->available()) {
            return null;
        }

        $semantic = $this->rank($query, $items, $k);
        if ($semantic['mode'] !== 'semantic') {
            return null;
        }

        $lexicalRanked = $this->lexicalRanking($query, $normalized);
        $relevant = array_flip(array_values(array_map('strval', $relevantIds)));
        $denominator = max(1, min($k, count($relevant)));

        $semHit = $this->recallAtK(array_column($semantic['ranked'], 'id'), $relevant, $k);
        $lexHit = $this->recallAtK(array_column($lexicalRanked, 'id'), $relevant, $k);

        return [
            'semantic_recall_at_k' => round($semHit / $denominator, 4),
            'lexical_recall_at_k' => round($lexHit / $denominator, 4),
            'lift' => round(($semHit - $lexHit) / $denominator, 4),
            'mode' => 'semantic',
        ];
    }

    private function semanticEnabled(): bool
    {
        return (bool) config('atlas.aobg.semantic_retrieval', false);
    }

    /**
     * @param  array<int,array{id:string,text:string}>  $normalized
     * @param  array<int,string>  $ids
     * @param  array<string,int>  $relevant
     */
    private function recallAtK(array $ids, array $relevant, int $k): int
    {
        $hits = 0;
        foreach (array_slice($ids, 0, $k) as $id) {
            if (isset($relevant[(string) $id])) {
                $hits++;
            }
        }

        return $hits;
    }

    /**
     * @param  array<int,array{id?:string,text:string,metadata?:array<string,mixed>}>  $items
     * @return array<int,array{id:string,text:string}>
     */
    private function normalizeItems(array $items): array
    {
        $out = [];
        $seen = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $text = trim((string) ($item['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '') {
                $id = 'item_'.$index;
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = ['id' => $id, 'text' => $text];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  array<int,array{id:string,text:string}>  $normalized
     * @return array<int,array{id:string,score:float,score_origin:string}>
     */
    private function semanticRanking(array $result, array $normalized): array
    {
        $boundary = is_array($result['boundary'] ?? null) ? $result['boundary'] : [];
        // Anti-fake: only trust a receipt that proves real in-Python embeddings.
        if (($boundary['real_embeddings'] ?? false) !== true || ($boundary['fabricated_vectors'] ?? true) !== false) {
            return [];
        }

        $known = [];
        foreach ($normalized as $item) {
            $known[$item['id']] = true;
        }

        $ranked = [];
        foreach ((array) ($result['matches'] ?? []) as $match) {
            if (! is_array($match)) {
                continue;
            }
            $id = (string) ($match['id'] ?? '');
            if ($id === '' || ! isset($known[$id]) || ! is_numeric($match['score'] ?? null)) {
                continue;
            }
            $ranked[] = [
                'id' => $id,
                'score' => round(max(0.0, min(1.0, (float) $match['score'])), 4),
                'score_origin' => 'local_semantic_vector',
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['id'], $b['id']));

        return $ranked;
    }

    /**
     * Deterministic token-overlap baseline. Labelled `lexical_token_overlap` so it
     * is never mistaken for a semantic vector score.
     *
     * @param  array<int,array{id:string,text:string}>  $normalized
     * @return array<int,array{id:string,score:float,score_origin:string}>
     */
    private function lexicalRanking(string $query, array $normalized): array
    {
        $tokens = $this->tokens($query);
        $ranked = [];
        foreach ($normalized as $item) {
            $ranked[] = [
                'id' => $item['id'],
                'score' => $this->lexicalScore($tokens, $item['text']),
                'score_origin' => 'lexical_token_overlap',
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['id'], $b['id']));

        return $ranked;
    }

    /**
     * @param  array<int,string>  $tokens
     */
    private function lexicalScore(array $tokens, string $text): float
    {
        if ($tokens === []) {
            return 0.0;
        }
        $haystack = Str::lower($text);
        $matches = 0;
        foreach ($tokens as $token) {
            if (str_contains($haystack, $token)) {
                $matches++;
            }
        }

        return round($matches / count($tokens), 4);
    }

    /**
     * @return array<int,string>
     */
    private function tokens(string $query): array
    {
        preg_match_all('/[\pL\pN]{3,}/u', Str::lower($query), $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    /**
     * @param  array<int,array{id:string,score:float,score_origin:string}>  $ranked
     * @return array{schema:string, mode:'semantic'|'lexical', query_hash:string, ranked:array<int,array{id:string,score:float,score_origin:string,rank:int}>, candidate_count:int}
     */
    private function result(string $mode, string $query, array $ranked, int $limit): array
    {
        $limit = max(1, $limit);
        $sliced = array_slice($ranked, 0, $limit);
        $withRank = [];
        foreach ($sliced as $i => $row) {
            $row['rank'] = $i + 1;
            $withRank[] = $row;
        }

        return [
            'schema' => self::SCHEMA,
            'mode' => $mode === 'semantic' ? 'semantic' : 'lexical',
            'query_hash' => hash('sha256', $query),
            'ranked' => $withRank,
            'candidate_count' => count($ranked),
        ];
    }
}
