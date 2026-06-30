<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Health;

/**
 * Pure joiner. Combines packet age with value, priority, poison/give-back risk, and blocked-family
 * signals per packet so an old task is never blindly treated as important, and a fresh high-value
 * task is never starved behind it.
 *
 * Input shape (list of packet rows): {task_packet_id:string,
 *   packet_age_facts:{age_seconds?:int},
 *   packet_value_facts:{value_score?:float (0..1)},
 *   give_back_risk_facts:{give_back_count?:int, malformed_count?:int},
 *   blocked_family_facts:{is_blocked_family?:bool},
 *   current_priority:{value?:int}}
 *
 * Deterministic, local-only, read-only — no queue mutation, no provider calls.
 */
final class AtlasMaestroQueueAgeValueDecayJoiner
{
    public const SCHEMA = 'atlas.self_construction.maestro.queue_age_value_decay_joiner.v1';

    public const AGE_BUCKET_FRESH = 'fresh';

    public const AGE_BUCKET_AGING = 'aging';

    public const AGE_BUCKET_OLD = 'old';

    public const VALUE_BUCKET_LOW = 'low';

    public const VALUE_BUCKET_MEDIUM = 'medium';

    public const VALUE_BUCKET_HIGH = 'high';

    public const ACTION_DRAIN_FIRST = 'drain_first';

    public const ACTION_DECAY_OR_REVIEW = 'decay_or_review';

    public const ACTION_PROTECT_PRIORITY = 'protect_priority';

    public const ACTION_KEEP_WAITING = 'keep_waiting';

    private const AGE_OLD_THRESHOLD_SECONDS = 86400;

    private const AGE_AGING_THRESHOLD_SECONDS = 3600;

    private const VALUE_HIGH_THRESHOLD = 0.7;

    private const VALUE_MEDIUM_THRESHOLD = 0.3;

    private const HIGH_RISK_SCORE_THRESHOLD = 3;

    /**
     * @param  list<array<string,mixed>>  $packets
     * @return array{schema:string, recommendations:list<array{task_packet_id:string, age_bucket:string, value_bucket:string, decay_score:float, action:string, reason_codes:list<string>}>}
     */
    public function join(array $packets): array
    {
        $recommendations = [];

        foreach ($packets as $packet) {
            if (! is_array($packet)) {
                continue;
            }

            $taskId = (string) ($packet['task_packet_id'] ?? '');
            $age = max(0, (int) ($packet['packet_age_facts']['age_seconds'] ?? 0));
            $value = max(0.0, min(1.0, (float) ($packet['packet_value_facts']['value_score'] ?? 0.0)));
            $giveBackCount = max(0, (int) ($packet['give_back_risk_facts']['give_back_count'] ?? 0));
            $malformedCount = max(0, (int) ($packet['give_back_risk_facts']['malformed_count'] ?? 0));
            $isBlockedFamily = (bool) ($packet['blocked_family_facts']['is_blocked_family'] ?? false);

            $ageBucket = match (true) {
                $age >= self::AGE_OLD_THRESHOLD_SECONDS => self::AGE_BUCKET_OLD,
                $age >= self::AGE_AGING_THRESHOLD_SECONDS => self::AGE_BUCKET_AGING,
                default => self::AGE_BUCKET_FRESH,
            };

            $valueBucket = match (true) {
                $value >= self::VALUE_HIGH_THRESHOLD => self::VALUE_BUCKET_HIGH,
                $value >= self::VALUE_MEDIUM_THRESHOLD => self::VALUE_BUCKET_MEDIUM,
                default => self::VALUE_BUCKET_LOW,
            };

            $riskScore = $giveBackCount + $malformedCount;
            $highRisk = $riskScore >= self::HIGH_RISK_SCORE_THRESHOLD || $isBlockedFamily;

            $reasonCodes = ['age_bucket:'.$ageBucket, 'value_bucket:'.$valueBucket];
            if ($isBlockedFamily) {
                $reasonCodes[] = 'blocked_family';
            }
            if ($riskScore > 0) {
                $reasonCodes[] = 'give_back_risk_score:'.$riskScore;
            }

            if ($ageBucket === self::AGE_BUCKET_OLD && $valueBucket === self::VALUE_BUCKET_HIGH && ! $highRisk) {
                $action = self::ACTION_DRAIN_FIRST;
                $reasonCodes[] = 'old_high_value_low_risk';
            } elseif ($ageBucket === self::AGE_BUCKET_OLD && ($valueBucket === self::VALUE_BUCKET_LOW || $highRisk)) {
                $action = self::ACTION_DECAY_OR_REVIEW;
                $reasonCodes[] = 'old_low_value_or_high_risk';
            } elseif ($ageBucket === self::AGE_BUCKET_FRESH && $valueBucket === self::VALUE_BUCKET_HIGH) {
                $action = self::ACTION_PROTECT_PRIORITY;
                $reasonCodes[] = 'fresh_high_value';
            } else {
                $action = self::ACTION_KEEP_WAITING;
            }

            $decayScore = round(($age / self::AGE_OLD_THRESHOLD_SECONDS) * (1 - $value) * (1 + $riskScore * 0.1), 4);

            $recommendations[] = [
                'task_packet_id' => $taskId,
                'age_bucket' => $ageBucket,
                'value_bucket' => $valueBucket,
                'decay_score' => $decayScore,
                'action' => $action,
                'reason_codes' => array_values(array_unique($reasonCodes)),
            ];
        }

        usort($recommendations, static fn (array $a, array $b): int => strcmp($a['task_packet_id'], $b['task_packet_id']));

        return [
            'schema' => self::SCHEMA,
            'recommendations' => $recommendations,
        ];
    }
}
