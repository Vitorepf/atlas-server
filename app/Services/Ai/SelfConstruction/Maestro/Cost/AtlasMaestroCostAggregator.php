<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Cost;

/**
 * Pure, deterministic, FACTS-only aggregator over {@see AtlasMaestroCostLedger} rows.
 *
 * Seven frozen-schema views:
 *   - aggregateByTaskClass(?cycleId)
 *   - aggregateByProvider(?cycleId)   — grouped by `provider:model`; atlas_native flagged as zero-cost
 *   - aggregateByCycle(?cycleId)
 *   - aggregateByWindow($windowSize, ?cycleId) — 'day' or 'hour'; prevents cross-window cost mixing
 *   - aggregateByQualityOutcome($qualityOutcomeByTaskPacketId, ?cycleId) (new) — cost rows joined,
 *     by the already-stored `task_packet_id`, against a caller-supplied
 *     task_packet_id => quality_outcome map (e.g. success, weak_green, give_back). The ledger's
 *     REQUIRED_FIELDS never stores quality_outcome, so this is a join-at-read-time, not a stored
 *     column — a caller-supplied categorical FACT, not a computed scalar score.
 *   - aggregateByProviderClass(?cycleId) (new) — provider-NEUTRAL bucket (zero_cost / paid_provider)
 *     derived purely from the already-stored `provider` field — NEVER the raw provider/model name.
 *   - costQualityHotspots($qualityOutcomeByTaskPacketId, ?cycleId) (new) — task classes whose
 *     low_quality_outcome_rate (give_back + weak_green share of records, joined the same way as
 *     aggregateByQualityOutcome) meets LOW_QUALITY_RATE_FLOOR AND carry positive cost.
 *
 * Each group row carries:
 *   { sum_cost_cents, sum_tokens_in, sum_tokens_out, count_records, rejected_count,
 *     first_recorded_at, last_recorded_at }.
 *
 * NEVER emits a single scalar quality / score / rating computed BY this aggregator — a
 * quality_outcome bucket key is a caller-supplied categorical fact (identical in kind to
 * task_class or cycle_id), and low_quality_outcome_rate is a falsifiable ratio of raw counts, not
 * an opaque quality judgment. Fail-OPEN: empty ledger ⇒ [].
 *
 * Malformed rows (non-numeric cost/token fields) are counted in `rejected_count`, never summed.
 */
final class AtlasMaestroCostAggregator
{
    public const ZERO_COST_PROVIDER = 'atlas_native';

    // Matches AtlasMaestroBudgetGate::providerClass()'s vocabulary for consistency.
    public const PROVIDER_CLASS_ZERO_COST = 'zero_cost';

    public const PROVIDER_CLASS_PAID = 'paid_provider';

    /** cost_quality_hotspots: minimum share of give_back/weak_green records to qualify. */
    public const LOW_QUALITY_RATE_FLOOR = 0.5;

    private const LOW_QUALITY_OUTCOMES = ['give_back', 'weak_green'];

    public function __construct(private readonly ?AtlasMaestroCostLedger $ledger = null) {}

    /**
     * @return array<string, array<string,mixed>>
     */
    public function aggregateByTaskClass(?string $cycleId = null): array
    {
        return self::fromRowsByGroup($this->loadRows($cycleId), 'task_class');
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    public function aggregateByProvider(?string $cycleId = null): array
    {
        $rows = $this->loadRows($cycleId);
        $tagged = [];
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $r['__provider_model__'] = json_encode([(string) ($r['provider'] ?? ''), (string) ($r['model'] ?? '')], JSON_THROW_ON_ERROR);
            $tagged[] = $r;
        }

        $groups = self::fromRowsByGroup($tagged, '__provider_model__');

        // Annotate atlas_native buckets as zero-cost providers (no provider spend).
        foreach ($groups as $key => $group) {
            $decoded = json_decode($key, true);
            $provider = is_array($decoded) ? ($decoded[0] ?? '') : '';
            $groups[$key]['is_zero_cost_provider'] = $provider === self::ZERO_COST_PROVIDER;
        }

        return $groups;
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    public function aggregateByCycle(?string $cycleId = null): array
    {
        return self::fromRowsByGroup($this->loadRows($cycleId), 'cycle_id');
    }

    /**
     * Aggregates costs bucketed by time window to prevent cross-day/cross-hour mixing.
     *
     * @param  string  $windowSize  'day' (default) or 'hour'
     * @return array<string, array<string,mixed>>
     */
    public function aggregateByWindow(string $windowSize = 'day', ?string $cycleId = null): array
    {
        $rows = $this->loadRows($cycleId);
        $prefixLen = $windowSize === 'hour' ? 13 : 10; // 'YYYY-MM-DDTHH' or 'YYYY-MM-DD'
        $tagged = [];
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $recordedAt = (string) ($r['recorded_at'] ?? '');
            $r['__window__'] = $recordedAt !== '' ? substr($recordedAt, 0, $prefixLen) : 'unknown';
            $tagged[] = $r;
        }

        return self::fromRowsByGroup($tagged, '__window__');
    }

    /**
     * Cost rows joined by `task_packet_id` against a caller-supplied quality-outcome map. Rows
     * whose task_packet_id has no entry in the map are grouped under '' (unknown outcome).
     *
     * @param  array<string,string>  $qualityOutcomeByTaskPacketId
     * @return array<string, array<string,mixed>>
     */
    public function aggregateByQualityOutcome(array $qualityOutcomeByTaskPacketId, ?string $cycleId = null): array
    {
        return self::fromRowsByGroup(
            self::joinQualityOutcome($this->loadRows($cycleId), $qualityOutcomeByTaskPacketId),
            'quality_outcome'
        );
    }

    /**
     * Provider-NEUTRAL bucket: zero_cost or paid_provider, derived purely from the already-stored
     * `provider` field — the raw provider/model name is never used as a group key (matches
     * AtlasMaestroBudgetGate::providerClass()'s vocabulary).
     *
     * @return array<string, array<string,mixed>>
     */
    public function aggregateByProviderClass(?string $cycleId = null): array
    {
        $rows = $this->loadRows($cycleId);
        $tagged = [];
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $r['__provider_class__'] = ((string) ($r['provider'] ?? '')) === self::ZERO_COST_PROVIDER
                ? self::PROVIDER_CLASS_ZERO_COST
                : self::PROVIDER_CLASS_PAID;
            $tagged[] = $r;
        }

        return self::fromRowsByGroup($tagged, '__provider_class__');
    }

    /**
     * Task classes that are BOTH expensive (positive sum_cost_cents) AND low-quality
     * (give_back + weak_green share of records >= LOW_QUALITY_RATE_FLOOR) — a cost/quality
     * cross-reference the plain per-dimension aggregations above cannot surface on their own.
     * Quality outcome is joined by `task_packet_id`, same as aggregateByQualityOutcome().
     *
     * @param  array<string,string>  $qualityOutcomeByTaskPacketId
     * @return array<string, array{sum_cost_cents:int, total_records:int, low_quality_outcome_count:int, low_quality_outcome_rate:float}>
     */
    public function costQualityHotspots(array $qualityOutcomeByTaskPacketId, ?string $cycleId = null): array
    {
        $rows = self::joinQualityOutcome($this->loadRows($cycleId), $qualityOutcomeByTaskPacketId);

        $byClass = [];
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $class = (string) ($r['task_class'] ?? '');
            if (! isset($byClass[$class])) {
                $byClass[$class] = ['sum_cost_cents' => 0, 'total' => 0, 'low_quality' => 0];
            }
            $byClass[$class]['sum_cost_cents'] += is_numeric($r['cost_cents'] ?? null) ? (int) $r['cost_cents'] : 0;
            $byClass[$class]['total']++;
            if (in_array((string) ($r['quality_outcome'] ?? ''), self::LOW_QUALITY_OUTCOMES, true)) {
                $byClass[$class]['low_quality']++;
            }
        }

        $hotspots = [];
        foreach ($byClass as $class => $data) {
            $rate = $data['total'] > 0 ? $data['low_quality'] / $data['total'] : 0.0;
            if ($data['sum_cost_cents'] > 0 && $rate >= self::LOW_QUALITY_RATE_FLOOR) {
                $hotspots[$class] = [
                    'sum_cost_cents' => $data['sum_cost_cents'],
                    'total_records' => $data['total'],
                    'low_quality_outcome_count' => $data['low_quality'],
                    'low_quality_outcome_rate' => round($rate, 4),
                ];
            }
        }

        ksort($hotspots, SORT_STRING);

        return $hotspots;
    }

    /**
     * Pure aggregation primitive. Takes row arrays and groups them by `$groupKey`.
     * Malformed rows (non-numeric cost/token fields) are tallied in `rejected_count`.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return array<string, array<string,mixed>>
     */
    public static function fromRowsByGroup(array $rows, string $groupKey): array
    {
        $groups = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = (string) ($row[$groupKey] ?? '');
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'sum_cost_cents' => 0,
                    'sum_tokens_in' => 0,
                    'sum_tokens_out' => 0,
                    'count_records' => 0,
                    'rejected_count' => 0,
                    'rejected_reason_counts' => [],
                    'first_recorded_at' => null,
                    'last_recorded_at' => null,
                ];
            }
            // Malformed row: count as rejected (per-field reason too), do not sum.
            $malformedReasons = [];
            if (! is_numeric($row['cost_cents'] ?? null)) {
                $malformedReasons[] = 'non_numeric_cost_cents';
            }
            if (! is_numeric($row['tokens_in'] ?? null)) {
                $malformedReasons[] = 'non_numeric_tokens_in';
            }
            if (! is_numeric($row['tokens_out'] ?? null)) {
                $malformedReasons[] = 'non_numeric_tokens_out';
            }
            if ($malformedReasons !== []) {
                $groups[$key]['rejected_count']++;
                foreach ($malformedReasons as $reason) {
                    $groups[$key]['rejected_reason_counts'][$reason] = ($groups[$key]['rejected_reason_counts'][$reason] ?? 0) + 1;
                }
                continue;
            }
            $groups[$key]['sum_cost_cents'] += (int) $row['cost_cents'];
            $groups[$key]['sum_tokens_in'] += (int) $row['tokens_in'];
            $groups[$key]['sum_tokens_out'] += (int) $row['tokens_out'];
            $groups[$key]['count_records']++;
            $recordedAt = (string) ($row['recorded_at'] ?? '');
            if ($recordedAt !== '') {
                if ($groups[$key]['first_recorded_at'] === null || strcmp($recordedAt, $groups[$key]['first_recorded_at']) < 0) {
                    $groups[$key]['first_recorded_at'] = $recordedAt;
                }
                if ($groups[$key]['last_recorded_at'] === null || strcmp($recordedAt, $groups[$key]['last_recorded_at']) > 0) {
                    $groups[$key]['last_recorded_at'] = $recordedAt;
                }
            }
        }

        ksort($groups, SORT_STRING);

        return $groups;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadRows(?string $cycleId): array
    {
        if ($this->ledger === null) {
            return [];
        }

        return $cycleId === null ? $this->ledger->all() : $this->ledger->queryForCycle($cycleId);
    }

    /**
     * Tags each row with `quality_outcome` from a caller-supplied task_packet_id map — the
     * ledger's REQUIRED_FIELDS schema never stores this, so it is joined at read time.
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,string>  $qualityOutcomeByTaskPacketId
     * @return list<array<string,mixed>>
     */
    private static function joinQualityOutcome(array $rows, array $qualityOutcomeByTaskPacketId): array
    {
        $tagged = [];
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $taskPacketId = (string) ($r['task_packet_id'] ?? '');
            $r['quality_outcome'] = $qualityOutcomeByTaskPacketId[$taskPacketId] ?? '';
            $tagged[] = $r;
        }

        return $tagged;
    }
}
