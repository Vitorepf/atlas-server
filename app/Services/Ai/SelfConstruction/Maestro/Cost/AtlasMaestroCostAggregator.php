<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Cost;

/**
 * Pure, deterministic, FACTS-only aggregator over {@see AtlasMaestroCostLedger} rows.
 *
 * Three frozen-schema views:
 *   - aggregateByTaskClass(?cycleId)
 *   - aggregateByProvider(?cycleId)   — grouped by `provider:model`
 *   - aggregateByCycle(?cycleId)
 *
 * Each group row carries:
 *   { sum_cost_cents, sum_tokens_in, sum_tokens_out, count_records, first_recorded_at,
 *     last_recorded_at }.
 *
 * NEVER emits a single scalar quality / score / rating. Fail-OPEN: empty ledger ⇒ [].
 */
final class AtlasMaestroCostAggregator
{
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
        // Compound group key: provider:model.
        $tagged = [];
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $r['__provider_model__'] = (string) ($r['provider'] ?? '').':'.(string) ($r['model'] ?? '');
            $tagged[] = $r;
        }

        return self::fromRowsByGroup($tagged, '__provider_model__');
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    public function aggregateByCycle(?string $cycleId = null): array
    {
        return self::fromRowsByGroup($this->loadRows($cycleId), 'cycle_id');
    }

    /**
     * Pure aggregation primitive. Takes row arrays and groups them by `$groupKey`.
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
                    'first_recorded_at' => null,
                    'last_recorded_at' => null,
                ];
            }
            // A row counts ONLY if every cost field is numeric — malformed rows skipped silently.
            if (! is_numeric($row['cost_cents'] ?? null) || ! is_numeric($row['tokens_in'] ?? null) || ! is_numeric($row['tokens_out'] ?? null)) {
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
}
