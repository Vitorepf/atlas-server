<?php

declare(strict_types=1);

namespace App\Services\Ai\Decide;

use App\Services\Ai\AtlasDecideService;
use Illuminate\Support\Str;

/**
 * Shared normalization helpers for the Atlas Decide meta-provider routing family.
 *
 * De-duplicates three byte-identical private helpers that were copied verbatim between
 * {@see AtlasDecideService} (the facade) and {@see ForgeTopologySection}
 * (a section it injects). Single source of truth — a fix here now propagates to both,
 * closing the "fix on the facade does not reach the copy" landmine.
 */
trait DecideProviderNormalization
{
    private function automaticModelSelectionMode(array $policy): string
    {
        return ($policy['default_model_policy'] ?? null) === 'best_quality'
            ? 'auto_best_available'
            : 'auto_best_allowed';
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $payload
     */
    private function obraId(array $options, array $payload): ?string
    {
        $value = data_get($payload, 'obra_id')
            ?: data_get($payload, 'forge_workspace.obra_id')
            ?: data_get($payload, 'work_id')
            ?: data_get($payload, 'project_id')
            ?: ($options['source_id'] ?? null);

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::lower(Str::limit($value, 120, ''));
    }
}
