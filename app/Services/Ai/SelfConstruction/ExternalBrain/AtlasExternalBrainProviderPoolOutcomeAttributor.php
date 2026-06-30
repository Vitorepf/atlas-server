<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure outcome attributor. Learns which optional subscription/local provider
 * pools actually produce green commits, give_backs, poison detections,
 * retries, or wasted tokens — grouped by provider_id, model_id, task_family,
 * complexity_tier, and role.
 *
 * A "success" only counts if it carries runnable evidence (non-empty
 * tests_reported AND evidence_refs, or an explicit verified_success=true);
 * a self-reported success without evidence is discounted to NOT count
 * toward success_rate (AC2).
 *
 * confidence is derived from BOTH sample size and evidence strength:
 *   low    — sample_size < 3
 *   medium — sample_size in [3, 10) OR evidence_quality < 0.5
 *   high   — sample_size >= 10 AND evidence_quality >= 0.5
 *
 * routing_lessons / do_not_route_reasons (AC3) are plain strings describing
 * what was learned; this attributor NEVER mutates a queue, claims a task, or
 * calls a provider — it only reports facts for a separate routing layer to
 * consume.
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasExternalBrainProviderPoolOutcomeAttributor
{
    public const SCHEMA = 'atlas.external_brain.provider_pool_outcome_attributor.v1';

    private const GROUP_KEYS = ['provider_id', 'model_id', 'task_family', 'complexity_tier', 'role'];

    private const ROUTING_LESSON_SUCCESS_FLOOR = 0.70;
    private const DO_NOT_ROUTE_SUCCESS_CEILING = 0.40;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function attribute(array $facts): array
    {
        $outcomes = is_array($facts['outcomes'] ?? null) ? $facts['outcomes'] : [];

        $groups = [];
        foreach ($outcomes as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = $this->groupKey($row);
            $groups[$key] ??= [
                'dims' => array_combine(self::GROUP_KEYS, array_map(static fn (string $k): string => (string) ($row[$k] ?? ''), self::GROUP_KEYS)),
                'total' => 0,
                'success' => 0,
                'give_back' => 0,
                'retry' => 0,
                'poison' => 0,
                'evidence_backed' => 0,
                'costs' => [],
            ];

            $result = (string) ($row['result'] ?? '');
            $testsReported = (array) ($row['tests_reported'] ?? []);
            $evidenceRefs = (array) ($row['evidence_refs'] ?? []);
            $hasEvidence = $testsReported !== [] && $evidenceRefs !== [];
            $verifiedSuccess = array_key_exists('verified_success', $row) ? (bool) $row['verified_success'] : $hasEvidence;

            $groups[$key]['total']++;
            if ($hasEvidence) {
                $groups[$key]['evidence_backed']++;
            }
            if ($result === 'success' && $verifiedSuccess) {
                $groups[$key]['success']++;
            } elseif ($result === 'give_back') {
                $groups[$key]['give_back']++;
            } elseif ($result === 'retry') {
                $groups[$key]['retry']++;
            } elseif ($result === 'poison_detected') {
                $groups[$key]['poison']++;
            }
            if (isset($row['cost_usd']) && is_numeric($row['cost_usd'])) {
                $groups[$key]['costs'][] = (float) $row['cost_usd'];
            }
        }

        ksort($groups);

        $outputGroups = [];
        $routingLessons = [];
        $doNotRouteReasons = [];

        foreach ($groups as $acc) {
            $total = $acc['total'];
            $rate = static fn (int $n): float => $total > 0 ? round($n / $total, 4) : 0.0;

            $successRate = $rate($acc['success']);
            $giveBackRate = $rate($acc['give_back']);
            $retryRate = $rate($acc['retry']);
            $poisonRate = $rate($acc['poison']);
            $evidenceQuality = $rate($acc['evidence_backed']);
            $medianCost = $this->median($acc['costs']);

            $confidence = match (true) {
                $total < 3 => 'low',
                $total >= 10 && $evidenceQuality >= 0.5 => 'high',
                default => 'medium',
            };

            $row = array_merge($acc['dims'], [
                'sample_size' => $total,
                'success_rate' => $successRate,
                'give_back_rate' => $giveBackRate,
                'retry_rate' => $retryRate,
                'poison_detection_rate' => $poisonRate,
                'median_cost' => $medianCost,
                'evidence_quality' => $evidenceQuality,
                'confidence' => $confidence,
            ]);
            $outputGroups[] = $row;

            $label = sprintf(
                'provider=%s model=%s task_family=%s complexity_tier=%s role=%s',
                $row['provider_id'],
                $row['model_id'],
                $row['task_family'],
                $row['complexity_tier'],
                $row['role'],
            );

            if ($confidence !== 'low' && $successRate >= self::ROUTING_LESSON_SUCCESS_FLOOR) {
                $routingLessons[] = sprintf('%s: prefer (success_rate=%.2f, confidence=%s, n=%d)', $label, $successRate, $confidence, $total);
            }

            if ($poisonRate > 0.0) {
                $doNotRouteReasons[] = sprintf('%s: poison_detected_present (poison_rate=%.2f, n=%d)', $label, $poisonRate, $total);
            } elseif ($confidence !== 'low' && $successRate < self::DO_NOT_ROUTE_SUCCESS_CEILING) {
                $doNotRouteReasons[] = sprintf('%s: low_success_rate (success_rate=%.2f, confidence=%s, n=%d)', $label, $successRate, $confidence, $total);
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'groups' => $outputGroups,
            'group_count' => count($outputGroups),
            'routing_lessons' => $routingLessons,
            'do_not_route_reasons' => $doNotRouteReasons,
            'mutates_queues' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function groupKey(array $row): string
    {
        return implode('|', array_map(static fn (string $k): string => (string) ($row[$k] ?? ''), self::GROUP_KEYS));
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);
        if ($count % 2 === 0) {
            return round(($values[$mid - 1] + $values[$mid]) / 2, 4);
        }

        return round($values[$mid], 4);
    }
}
