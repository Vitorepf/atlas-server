<?php

declare(strict_types=1);

namespace App\Services\Semantic;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class EmbeddingProvenance
{
    public const LEGACY_NULL_MODEL_ALLOWED = true;

    public static function contentHash(string $embeddedText): string
    {
        return hash('sha256', $embeddedText);
    }

    /**
     * @param  array<string,mixed>  $info
     */
    public static function modelId(array $info): string
    {
        $provider = trim((string) ($info['provider'] ?? 'unknown'));
        $model = trim((string) ($info['model'] ?? 'unknown'));

        if ($provider === 'openai_api') {
            $provider = 'openai';
        }

        if ($provider === '') {
            $provider = 'unknown';
        }
        if ($model === '') {
            $model = 'unknown';
        }

        return $provider.':'.$model;
    }

    public static function scopeCurrentModel(
        EloquentBuilder|QueryBuilder $builder,
        string $table,
        string $currentModel,
        bool $includeLegacyNullModel = self::LEGACY_NULL_MODEL_ALLOWED,
    ): void {
        $column = $table.'.embedding_model';

        $builder->where(function (EloquentBuilder|QueryBuilder $query) use ($column, $currentModel, $includeLegacyNullModel): void {
            $query->where($column, $currentModel);
            if ($includeLegacyNullModel) {
                $query->orWhereNull($column);
            }
        });
    }
}
