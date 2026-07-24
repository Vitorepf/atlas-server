<?php

declare(strict_types=1);

namespace App\Services\Ai\Support;

use App\Models\AiJob;

/**
 * Shared CLI provider model-identity resolution for Claude/Codex/Jarvis drivers.
 *
 * Full-pass reuse: de-duplicates private invocationModel() copies.
 */
final class CliInvocationModel
{
    /**
     * @param  array<string, mixed>  $provider
     */
    public static function resolve(AiJob $job, array $provider): ?string
    {
        $source = data_get($job->payload, 'model_identity_source') ?? data_get($job->metadata, 'model_identity_source');
        if (in_array($source, ['provider_default_identity', 'configured_model_identity'], true)) {
            return null;
        }

        $model = $job->model ?: ($provider['model'] ?? null);
        if (! is_string($model) && ! is_numeric($model)) {
            return null;
        }

        $model = trim((string) $model);
        if ($model === '' || str_ends_with($model, '_default')) {
            return null;
        }

        return $model;
    }
}
