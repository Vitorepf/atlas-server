<?php

declare(strict_types=1);

namespace App\Services\Ai\OperatorIntelligence\Support;

/**
 * Pure candidate key/effect helpers for operator learning (full-pass peel).
 */
final class OperatorLearningCandidateSupport
{
    public static function profileKey(string $taxonomyItemId, string $signalKind): string
    {
        return strtolower(str_replace('-', '_', $taxonomyItemId)).'.'.preg_replace('/[^a-z0-9_]+/', '_', strtolower($signalKind));
    }

    public static function effectForTaxonomy(string $taxonomy, string $kind): string
    {
        if ($kind === 'operator_boundary') {
            return 'do_not_do';
        }
        if (str_starts_with($taxonomy, 'COL-')) {
            return 'response_style';
        }

        return 'context_hint';
    }
}
