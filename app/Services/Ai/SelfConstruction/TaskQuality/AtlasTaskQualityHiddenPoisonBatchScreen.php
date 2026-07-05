<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Pure batch screen that detects hidden poison patterns invisible in
 * single-packet inspection, including same-shape farms and fake diversity.
 *
 * Detection patterns:
 *   - repeated_shape: same allowed_files shape across multiple packets
 *   - same_acceptance_filter_family: same test filter pattern across packets
 *   - cosmetic_class_name_churn: same objective with different class names
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasTaskQualityHiddenPoisonBatchScreen
{
    public const SCHEMA = 'atlas.self_construction.task_quality_hidden_poison_batch_screen.v1';

    public const FLAG_REPEATED_SHAPE = 'repeated_shape';
    public const FLAG_SAME_ACCEPTANCE_FILTER_FAMILY = 'same_acceptance_filter_family';
    public const FLAG_COSMETIC_CLASS_NAME_CHURN = 'cosmetic_class_name_churn';

    private const SHAPE_THRESHOLD = 3;
    private const FILTER_THRESHOLD = 3;
    private const CHURN_THRESHOLD = 3;

    /**
     * @param  array<int, array<string, mixed>>  $packets
     * @return array<string, mixed>
     */
    public function screen(array $packets): array
    {
        $flags = [];

        // Check for repeated shape (same allowed_files set across multiple packets).
        $shapeCounts = [];
        foreach ($packets as $packet) {
            if (! is_array($packet)) {
                continue;
            }
            $files = (array) ($packet['allowed_files'] ?? []);
            sort($files, SORT_STRING);
            $shape = implode('|', $files);
            $shapeCounts[$shape] = ($shapeCounts[$shape] ?? 0) + 1;
        }
        foreach ($shapeCounts as $shape => $count) {
            if ($count >= self::SHAPE_THRESHOLD) {
                $flags[] = [
                    'type' => self::FLAG_REPEATED_SHAPE,
                    'count' => $count,
                    'shape' => $shape,
                ];
            }
        }

        // Check for same acceptance filter family.
        $filterCounts = [];
        foreach ($packets as $packet) {
            if (! is_array($packet)) {
                continue;
            }
            $acceptance = (array) ($packet['acceptance_criteria'] ?? []);
            foreach ($acceptance as $criterion) {
                $text = strtolower(trim((string) $criterion));
                // Extract filter pattern.
                if (preg_match('/--filter=([^\s]+)/', $text, $m)) {
                    $filter = $m[1];
                    $filterCounts[$filter] = ($filterCounts[$filter] ?? 0) + 1;
                }
            }
        }
        foreach ($filterCounts as $filter => $count) {
            if ($count >= self::FILTER_THRESHOLD) {
                $flags[] = [
                    'type' => self::FLAG_SAME_ACCEPTANCE_FILTER_FAMILY,
                    'count' => $count,
                    'filter' => $filter,
                ];
            }
        }

        // Check for cosmetic class name churn (same objective pattern with different class names).
        $objectivePatterns = [];
        foreach ($packets as $packet) {
            if (! is_array($packet)) {
                continue;
            }
            $objective = strtolower(trim((string) ($packet['objective'] ?? '')));
            // Normalize class names to a placeholder.
            $pattern = preg_replace('/\b[a-z][a-z0-9_]*\b/i', 'X', $objective);
            if ($pattern !== null && $pattern !== '') {
                $objectivePatterns[$pattern] = ($objectivePatterns[$pattern] ?? 0) + 1;
            }
        }
        foreach ($objectivePatterns as $pattern => $count) {
            if ($count >= self::CHURN_THRESHOLD) {
                $flags[] = [
                    'type' => self::FLAG_COSMETIC_CLASS_NAME_CHURN,
                    'count' => $count,
                    'pattern' => $pattern,
                ];
            }
        }

        $flagged = $flags !== [];

        return [
            'schema_version' => self::SCHEMA,
            'flagged' => $flagged,
            'flags' => $flags,
            'total_packets' => count($packets),
            'flag_count' => count($flags),
        ];
    }
}
