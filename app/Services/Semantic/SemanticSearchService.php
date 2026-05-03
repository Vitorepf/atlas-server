<?php

namespace App\Services\Semantic;

use App\Models\SemanticNote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SemanticSearchService
{
    public function __construct(private readonly EmbeddingService $embeddings) {}

    /**
     * @return Collection<int, SemanticNote>
     */
    public function search(string $query, array $filters = [], int $limit = 10): Collection
    {
        $query = trim($query);
        if ($query === '') {
            return $this->metadataSearch($filters, $limit);
        }

        $rows = $this->vectorSearch($query, $filters, $limit);
        $lexical = $this->lexicalSearch($query, $filters, $limit);

        return $rows
            ->merge($lexical)
            ->unique('id')
            ->sortByDesc(fn (SemanticNote $note): float => (float) ($note->score ?? 0))
            ->take($limit)
            ->values();
    }

    /**
     * @return Collection<int, SemanticNote>
     */
    private function vectorSearch(string $query, array $filters, int $limit): Collection
    {
        if (! Schema::hasTable('semantic_notes') || DB::getDriverName() !== 'pgsql') {
            return collect();
        }

        $vector = $this->embeddings->vectorLiteral($this->embeddings->embedText($query));
        $builder = SemanticNote::query()
            ->whereNull('deleted_at')
            ->whereNot('status', 'invalid');

        $this->applyFilters($builder, $filters);

        return $builder
            ->select('semantic_notes.*')
            ->selectRaw('(1 - (embedding <=> ?::vector)) AS score', [$vector])
            ->whereNotNull('embedding')
            ->orderByRaw('embedding <=> ?::vector', [$vector])
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, SemanticNote>
     */
    public function metadataSearch(array $filters = [], int $limit = 50): Collection
    {
        if (! Schema::hasTable('semantic_notes')) {
            return collect();
        }

        $builder = SemanticNote::query()
            ->whereNull('deleted_at')
            ->orderByDesc('updated_at')
            ->limit($limit);
        $this->applyFilters($builder, $filters);

        return $builder->get();
    }

    /**
     * @return Collection<int, SemanticNote>
     */
    public function lexicalSearch(string $query, array $filters = [], int $limit = 10): Collection
    {
        if (! Schema::hasTable('semantic_notes')) {
            return collect();
        }

        $operator = DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $builder = SemanticNote::query()
            ->whereNull('deleted_at')
            ->where(function ($builder) use ($query, $operator): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $query).'%';
                $builder
                    ->where('title', $operator, $like)
                    ->orWhere('summary', $operator, $like)
                    ->orWhere('body_excerpt', $operator, $like);
            })
            ->select('semantic_notes.*')
            ->selectRaw('0.62 AS score')
            ->limit($limit);
        $this->applyFilters($builder, $filters);

        return $builder->get();
    }

    private function applyFilters($builder, array $filters): void
    {
        if (! empty($filters['type'])) {
            $builder->whereIn('type', (array) $filters['type']);
        }
        if (! empty($filters['status'])) {
            $builder->whereIn('status', (array) $filters['status']);
        }
        if (! empty($filters['domains'])) {
            foreach ((array) $filters['domains'] as $domain) {
                $builder->whereRaw('domains @> ?::jsonb', [json_encode([$domain])]);
            }
        }
        if (! empty($filters['trigger_signal'])) {
            $builder->whereRaw('trigger_signals @> ?::jsonb', [json_encode([(string) $filters['trigger_signal']])]);
        }
    }
}
