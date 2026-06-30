<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Retry;

/**
 * Pure router: given a packet's failure history, routes it to the right handling lane
 * before any muscle wastes further cycles on a known-broken spec.
 *
 * Priority order (first match wins):
 *   1. operator_only=true            → operator_only_lane
 *   2. give_back >= RETIRE threshold → retire_lane
 *   3. give_back >= REPEAT + dep stale|missing → unblock_lane
 *   4. give_back >= REPEAT + scope signal      → rescope_lane
 *   5. poison_risk=high                        → retire_lane
 *   6. gate_failure_count >= GATE threshold    → rescope_lane
 *   7. default                                 → normal_serve
 *
 * First-time or low-count failures are always normal_serve.
 */
final class AtlasMaestroRepeatedFailureFastPathRouter
{
    public const SCHEMA = 'atlas.maestro.retry.repeated_failure_fast_path_router.v1';

    public const LANE_NORMAL = 'normal_serve';

    public const LANE_RESCOPE = 'rescope_lane';

    public const LANE_UNBLOCK = 'unblock_lane';

    public const LANE_RETIRE = 'retire_lane';

    public const LANE_OPERATOR_ONLY = 'operator_only_lane';

    public const GIVE_BACK_REPEAT_THRESHOLD = 3;

    public const GIVE_BACK_RETIRE_THRESHOLD = 8;

    public const GATE_REPEAT_THRESHOLD = 3;

    /**
     * @param  array<string,mixed>  $packet  give_back_count, give_back_reasons, poison_risk,
     *                                        scope_repair_done, dependency_state,
     *                                        gate_failure_count, operator_only
     * @return array{schema_version:string, lane:string, confidence:float, reason:string}
     */
    public function route(array $packet): array
    {
        $operatorOnly = (bool) ($packet['operator_only'] ?? false);
        $giveBackCount = max(0, (int) ($packet['give_back_count'] ?? 0));
        $giveBackReasons = array_values(array_filter(
            array_map('strval', (array) ($packet['give_back_reasons'] ?? [])),
            static fn (string $r): bool => $r !== '',
        ));
        $poisonRisk = trim((string) ($packet['poison_risk'] ?? 'low'));
        $scopeRepairDone = (bool) ($packet['scope_repair_done'] ?? false);
        $dependencyState = trim((string) ($packet['dependency_state'] ?? 'ok'));
        $gateFailureCount = max(0, (int) ($packet['gate_failure_count'] ?? 0));

        if ($operatorOnly) {
            return $this->out(self::LANE_OPERATOR_ONLY, 0.98, 'operator_only=true');
        }

        if ($giveBackCount >= self::GIVE_BACK_RETIRE_THRESHOLD) {
            return $this->out(self::LANE_RETIRE, 0.95, 'give_back_count_high:'.$giveBackCount);
        }

        if ($giveBackCount >= self::GIVE_BACK_REPEAT_THRESHOLD && in_array($dependencyState, ['stale', 'missing'], true)) {
            return $this->out(self::LANE_UNBLOCK, 0.90, 'repeated_give_back+dependency_'.$dependencyState);
        }

        $hasScopeSignal = $scopeRepairDone || array_filter(
            $giveBackReasons,
            static fn (string $r): bool => str_contains($r, 'spec') || str_contains($r, 'scope') || str_contains($r, 'accept'),
        ) !== [];
        if ($giveBackCount >= self::GIVE_BACK_REPEAT_THRESHOLD && $hasScopeSignal) {
            return $this->out(self::LANE_RESCOPE, 0.88, 'repeated_give_back+scope_signal');
        }

        if ($poisonRisk === 'high') {
            return $this->out(self::LANE_RETIRE, 0.85, 'poison_risk=high');
        }

        if ($gateFailureCount >= self::GATE_REPEAT_THRESHOLD) {
            return $this->out(self::LANE_RESCOPE, 0.80, 'repeated_gate_failures:'.$gateFailureCount);
        }

        $confidence = ($giveBackCount === 0 && $gateFailureCount === 0) ? 0.95 : 0.70;

        return $this->out(self::LANE_NORMAL, $confidence, 'first_time_or_recoverable');
    }

    /**
     * @return array{schema_version:string, lane:string, confidence:float, reason:string}
     */
    private function out(string $lane, float $confidence, string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'lane' => $lane,
            'confidence' => $confidence,
            'reason' => $reason,
        ];
    }
}
