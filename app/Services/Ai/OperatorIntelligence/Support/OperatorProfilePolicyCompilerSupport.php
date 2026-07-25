<?php

declare(strict_types=1);

namespace App\Services\Ai\OperatorIntelligence\Support;

use App\Models\OperatorProfilePolicyRule;

/**
 * Pure effect/priority mapping for operator profile policy compilation (full-pass peel).
 */
final class OperatorProfilePolicyCompilerSupport
{
    /**
     * Resolve policy effect from explicit value, taxonomy, profile key, or summary heuristics.
     *
     * @param  array<string,mixed>  $value
     */
    public static function effect(
        array $value,
        string $taxonomyItemId,
        string $profileKey,
        string $summary,
    ): string {
        $effect = is_string($value['effect'] ?? null) ? $value['effect'] : null;
        if ($effect !== null && in_array($effect, OperatorProfilePolicyRule::EFFECTS, true)) {
            return $effect;
        }

        if (str_starts_with($taxonomyItemId, 'COL-')) {
            return 'response_style';
        }
        if (str_contains($profileKey, 'boundary') || str_contains(strtolower($summary), 'nao mexa')) {
            return 'do_not_do';
        }

        return 'context_hint';
    }

    public static function priority(string $effect, float $confidence): int
    {
        $base = match ($effect) {
            'do_not_do' => 95,
            'approval_gate', 'autonomy_limit' => 90,
            'workflow_preference', 'tool_preference', 'handoff_preference' => 70,
            'response_style' => 60,
            default => 50,
        };

        return min(100, $base + (int) round($confidence * 5));
    }
}
