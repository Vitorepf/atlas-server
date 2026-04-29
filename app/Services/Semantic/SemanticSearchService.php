<?php

namespace App\Services\Semantic;

use App\Models\SemanticNote;
use Illuminate\Support\Collection;

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

        $vector = $this->embeddings->vectorLiteral($this->embeddings->embedText($query));
        $builder = SemanticNote::query()
            ->whereNull('deleted_at')
            ->whereNot('status', 'invalid');

        $this->applyFilters($builder, $filters);

        $rows = $builder
            ->select('semantic_notes.*')
            ->selectRaw('(1 - (embedding <=> ?::vector)) AS score', [$vector])
            ->whereNotNull('embedding')
            ->orderByRaw('embedding <=> ?::vector', [$vector])
            ->limit($limit)
            ->get();

        if ($rows->isNotEmpty()) {
            return $rows;
        }

        return $this->lexicalSearch($query, $filters, $limit);
    }

    /**
     * @return Collection<int, SemanticNote>
     */
    public function metadataSearch(array $filters = [], int $limit = 50): Collection
    {
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
        $builder = SemanticNote::query()
            ->whereNull('deleted_at')
            ->where(function ($builder) use ($query): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $query).'%';
                $builder
                    ->where('title', 'ILIKE', $like)
                    ->orWhere('summary', 'ILIKE', $like)
                    ->orWhere('body_excerpt', 'ILIKE', $like);
            })
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
