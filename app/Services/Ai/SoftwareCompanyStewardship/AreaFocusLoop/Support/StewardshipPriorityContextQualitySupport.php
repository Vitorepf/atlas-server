<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Support;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusLoopPayloadNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ContextQualityScoreContract;

/**
 * Pure context-quality + rank-input helpers for AP-785 Stewardship Priority Engine.
 *
 * Extracted from StewardshipPriorityEngineService private pure residual:
 * context_quality_score input validation, finding-kind classification, priority
 * boost when degraded context meets a context_memory_retrieval_gap candidate,
 * candidate list extraction, and hash identity (drop generated fields).
 *
 * No I/O, no DI, no provider calls, no clock, no filesystem.
 */
final class StewardshipPriorityContextQualitySupport
{
    private function __construct()
    {
    }

    /**
     * Bounded keys accepted by {@see ContextQualityScoreContract::fromArray()}.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public static function validateScoreInput(array $input): array
    {
        $validated = [];
        foreach ([
            'area_id',
            'focus',
            'certification_quality_score',
            'certification_target_score',
            'certification_status',
            'finding_kind',
        ] as $key) {
            if (array_key_exists($key, $input)) {
                $validated[$key] = $input[$key];
            }
        }

        return $validated;
    }

    /**
     * Materialize the context quality score contract from rank/engine input.
     * Empty input returns the default contract (no boost).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public static function score(array $input = []): array
    {
        if ($input === []) {
            return ContextQualityScoreContract::defaults()->toArray();
        }

        return ContextQualityScoreContract::fromArray(
            self::validateScoreInput($input)
        )->toArray();
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    public static function candidateFindingKind(array $candidate): string
    {
        foreach (['finding_kind', 'kind', 'type', 'classification'] as $key) {
            $value = strtolower(trim((string) ($candidate[$key] ?? '')));
            if ($value === ContextQualityScoreContract::FINDING_KIND_CONTEXT_MEMORY_RETRIEVAL_GAP) {
                return ContextQualityScoreContract::FINDING_KIND_CONTEXT_MEMORY_RETRIEVAL_GAP;
            }
        }

        return '';
    }

    /**
     * First ranking rule residual: when rank input carries degraded context
     * certification, boost candidates tagged context_memory_retrieval_gap.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>  $rankedItem
     * @return array<string,mixed>
     */
    public static function applyPriorityBoost(array $input, array $candidate, array $rankedItem): array
    {
        $contextInput = $input['context_quality_score'] ?? null;
        if (! is_array($contextInput) || $contextInput === []) {
            return $rankedItem;
        }

        if (self::candidateFindingKind($candidate) !== ContextQualityScoreContract::FINDING_KIND_CONTEXT_MEMORY_RETRIEVAL_GAP) {
            return $rankedItem;
        }

        $contextQuality = self::score(array_merge(
            $contextInput,
            ['finding_kind' => ContextQualityScoreContract::FINDING_KIND_CONTEXT_MEMORY_RETRIEVAL_GAP],
        ));
        $boostPoints = (int) ($contextQuality['outputs']['priority_boost_points'] ?? 0);
        if ($boostPoints <= 0) {
            return $rankedItem;
        }

        $boostedScore = round(min(100.0, (float) ($rankedItem['final_priority_score'] ?? 0.0) + $boostPoints), 2);
        $rankedItem['final_priority_score'] = $boostedScore;
        $rankedItem['priority_score'] = $boostedScore;
        $rankedItem['context_quality_priority_boost_points'] = $boostPoints;
        $rankedItem['reason_machine'] = AreaFocusStringListNormalizer::uniqueMergedStringValues(
            (array) ($rankedItem['reason_machine'] ?? []),
            ['context_quality_priority_boost'],
        );
        if (is_array($rankedItem['score_breakdown'] ?? null)) {
            $rankedItem['score_breakdown']['context_quality_priority_boost_points'] = $boostPoints;
            $rankedItem['score_breakdown']['final_priority_score'] = $boostedScore;
        }

        return $rankedItem;
    }

    /**
     * Drop generated fields before deterministic priority_hash.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public static function identity(array $payload): array
    {
        $copy = $payload;
        unset($copy['priority_hash'], $copy['generated_at']);

        return $copy;
    }

    /**
     * Extract candidate list from rank input (candidates / findings / branches / …).
     *
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    public static function candidatesFromInput(array $input): array
    {
        if (is_array($input['candidates'] ?? null)) {
            return AreaFocusLoopPayloadNormalizer::listOfArrays($input['candidates']);
        }

        if (is_array($input['findings'] ?? null)) {
            return AreaFocusLoopPayloadNormalizer::listOfArrays($input['findings']);
        }

        if (is_array($input['deep_scan_report'] ?? null)) {
            return AreaFocusLoopPayloadNormalizer::listOfArrays($input['deep_scan_report']['findings'] ?? []);
        }

        if (is_array($input['branches'] ?? null)) {
            return AreaFocusLoopPayloadNormalizer::listOfArrays($input['branches']);
        }

        if (is_array($input['specs'] ?? null)) {
            return AreaFocusLoopPayloadNormalizer::listOfArrays($input['specs']);
        }

        if (is_array($input['work_orders'] ?? null)) {
            return AreaFocusLoopPayloadNormalizer::listOfArrays($input['work_orders']);
        }

        if (is_array($input['queue_items'] ?? null)) {
            return AreaFocusLoopPayloadNormalizer::listOfArrays($input['queue_items']);
        }

        return [];
    }
}
