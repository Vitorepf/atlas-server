<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasVerbatimMemory;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Semantic\EmbeddingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * R1 — REAL vector similarity over the Atlas Memory Core tables. Given a query
 * and a candidate id set, returns a map of `id => cosine_similarity` computed by
 * pgvector (`1 - (embedding <=> ?::vector)`), exactly like
 * SemanticSearchService::vectorSearch over semantic_notes.
 *
 * The query embedding comes from the REAL EmbeddingService (Python semantic_rag
 * runtime / pgvector). There is NO fake path: if the engine or pgsql is
 * unavailable, this returns an EMPTY map and the caller HONESTLY falls back to
 * the existing lexical score — it never pretends a lexical result is semantic.
 *
 * Scoped to a candidate id set (the rows the scope/context filter already
 * selected) so the vector search stays cheap and never widens recall beyond the
 * provider-safe, scope-correct candidates.
 *
 * @see app/Services/Semantic/SemanticSearchService.php
 */
class AtlasMemoryVectorSearchService
{
    public function __construct(private readonly EmbeddingService $embeddings) {}

    public function available(): bool
    {
        return (bool) config('atlas.semantic_memory.memory_vector_recall_enabled', true)
            && DB::getDriverName() === 'pgsql';
    }

    /**
     * Cosine similarity of the query against the given memory-entry ids.
     *
     * @param  array<int,string>  $ids
     * @return array<string,float>  id => similarity (0..1), only rows with a vector
     */
    public function scoreEntries(string $query, array $ids): array
    {
        return $this->score(AtlasMemoryEntry::query(), 'atlas_memory_entries', $query, $ids);
    }

    /**
     * Cosine similarity of the query against the given verbatim-memory ids.
     *
     * @param  array<int,string>  $ids
     * @return array<string,float>  id => similarity (0..1), only rows with a vector
     */
    public function scoreVerbatims(string $query, array $ids): array
    {
        return $this->score(AtlasVerbatimMemory::query(), 'atlas_verbatim_memories', $query, $ids);
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $builder
     * @param  array<int,string>  $ids
     * @return array<string,float>
     */
    private function score(Builder $builder, string $table, string $query, array $ids): array
    {
        $query = trim($query);
        $ids = array_values(array_unique(array_filter($ids, static fn (mixed $id): bool => is_string($id) && $id !== '')));

        if ($query === '' || $ids === [] || ! $this->available()) {
            return [];
        }

        if (! DatabaseTableAvailability::hasColumn($table, 'embedding')) {
            return [];
        }

        try {
            $vector = $this->embeddings->vectorLiteral($this->embeddings->embedText($query));
        } catch (Throwable $throwable) {
            // No real query embedding -> no semantic signal. Honest lexical fallback.
            report($throwable);

            return [];
        }

        try {
            $rows = $builder
                ->whereIn($table.'.id', $ids)
                ->whereNotNull('embedding')
                ->select($table.'.id')
                ->selectRaw('(1 - (embedding <=> ?::vector)) AS similarity', [$vector])
                ->get();
        } catch (Throwable $throwable) {
            report($throwable);

            return [];
        }

        $scores = [];
        foreach ($rows as $row) {
            $scores[(string) $row->getAttribute('id')] = max(0.0, min(1.0, (float) $row->getAttribute('similarity')));
        }

        return $scores;
    }
}
