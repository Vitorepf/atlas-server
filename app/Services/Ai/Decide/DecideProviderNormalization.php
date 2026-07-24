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

    private function confidenceBand(int $score, ?string $manualProvider): string
    {
        if ($manualProvider !== null) {
            return 'manual';
        }

        return match (true) {
            $score >= 85 => 'high',
            $score >= 70 => 'medium',
            default => 'low',
        };
    }

    private function qualityGateForTask(string $taskType, bool $programming, bool $hasVisual): string
    {
        if ($programming) {
            return 'tests_or_static_review';
        }

        if ($hasVisual) {
            return 'visual_consistency_review';
        }

        if (in_array($taskType, ['research', 'analysis', 'memory'], true)) {
            return 'source_grounding_review';
        }

        return 'response_sanity_check';
    }

    private function cleanDecisionMode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return in_array($value, ['atlas_decide', 'manual_override'], true) ? $value : null;
    }

    private function isResearchSignal(?string $workflowMode, ?string $routingTask, string $inputLower): bool
    {
        return in_array($workflowMode, ['research', 'analysis'], true)
            || in_array($routingTask, ['research', 'analysis'], true)
            || str_contains($inputLower, 'pesquisa')
            || str_contains($inputLower, 'pesquise')
            || str_contains($inputLower, 'research');
    }

    private function declaredTaskTypeIsGenericOrProgramming(?string $taskType): bool
    {
        return $taskType === null || in_array($taskType, [
            'chat',
            'general',
            'conversation',
            'completion',
            'assistant',
            'default',
            'unknown',
            'dev',
            'debug',
            'code',
            'coding',
            'programming',
            'quality_repair',
            'implementation',
            'implementacao',
            'implementação',
            'refactor',
            'refactoring',
            'refatoracao',
            'refatoração',
        ], true);
    }
}
