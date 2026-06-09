<?php

namespace App\Services\Ai\Programming;

use App\Models\AtlasProgrammingContextPack;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProgrammingContextPackStore
{
    /**
     * @param  array<string,mixed>  $contextPack
     * @return array<string,mixed>
     */
    public function persist(string $planId, ?string $parentPlanId, array $contextPack): array
    {
        if (! $this->storageAvailable()) {
            return array_merge($contextPack, [
                'storage' => [
                    'persisted' => false,
                    'reason' => 'atlas_programming_context_packs_table_missing',
                ],
            ]);
        }

        $hash = (string) ($contextPack['context_pack_hash'] ?? $contextPack['hash'] ?? hash('sha256', json_encode($contextPack) ?: ''));
        $model = AtlasProgrammingContextPack::query()->updateOrCreate(
            ['context_pack_hash' => $hash],
            [
                'plan_id' => $planId,
                'parent_plan_id' => $parentPlanId,
                'schema_version' => (string) ($contextPack['schema_version'] ?? 'atlas.programming.context_pack.professional.v1'),
                'status' => (string) ($contextPack['status'] ?? 'unknown'),
                'provider_safe' => (bool) ($contextPack['provider_safe'] ?? true),
                'retrieval_strategy' => (string) ($contextPack['retrieval_strategy'] ?? 'hybrid_graph_lexical'),
                'ranked_refs_json' => $contextPack['ranked_refs'] ?? [],
                'excluded_refs_json' => $contextPack['excluded_refs'] ?? [],
                'source_counts_json' => $contextPack['source_counts'] ?? [],
                'metrics_json' => $contextPack['metrics'] ?? [],
                'budget_json' => $contextPack['budget'] ?? [],
                'payload_json' => $contextPack,
            ],
        );

        return array_merge($contextPack, [
            'storage' => [
                'persisted' => true,
                'model_id' => $model->id,
                'table' => 'atlas_programming_context_packs',
            ],
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByHash(string $contextPackHash): ?array
    {
        if (! $this->storageAvailable()) {
            return null;
        }

        $model = AtlasProgrammingContextPack::query()
            ->where('context_pack_hash', $contextPackHash)
            ->first();

        if (! $model instanceof AtlasProgrammingContextPack) {
            return null;
        }

        return array_merge($model->payload_json ?? [], [
            'storage' => [
                'persisted' => true,
                'model_id' => $model->id,
                'table' => 'atlas_programming_context_packs',
                'replayed' => true,
            ],
        ]);
    }

    private function storageAvailable(): bool
    {
        try {
            return Schema::hasTable('atlas_programming_context_packs');
        } catch (Throwable) {
            return false;
        }
    }
}
