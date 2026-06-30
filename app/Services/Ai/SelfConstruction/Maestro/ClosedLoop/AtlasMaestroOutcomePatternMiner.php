<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ClosedLoop;

final class AtlasMaestroOutcomePatternMiner
{
    public const MIN_SUPPORT = 8;

    /** Minimum delivered successes required for a dimension/bucket to qualify as a reusable strategy pattern. */
    public const MIN_STRATEGY_OCCURRENCES = 5;

    private const OUTCOMES = ['delivered', 'give_back', 'rejected', 'stale'];

    public function __construct(private readonly AtlasMaestroOutcomeShapeLedger $ledger)
    {
    }

    /**
     * @return array<string,array<string,array<string,mixed>>>
     */
    public function mine(): array
    {
        $facts = [
            'origin_kind' => [],
            'allowed_files_count_bucket' => [],
            'acceptance_criteria_count_bucket' => [],
            'has_tests_path' => [],
            'file_family' => [],
            'task_shape' => [],
            'worker_id' => [],
            'proof_command_class' => [],
        ];

        foreach ($this->ledger->stream() as $row) {
            $outcome = (string) ($row['outcome'] ?? '');
            if (! in_array($outcome, self::OUTCOMES, true)) {
                continue;
            }

            $buckets = [
                'origin_kind' => (string) ($row['origin_kind'] ?? 'unknown'),
                'allowed_files_count_bucket' => $this->countBucket((int) ($row['allowed_files_count'] ?? 0)),
                'acceptance_criteria_count_bucket' => $this->countBucket((int) ($row['acceptance_criteria_count'] ?? 0)),
                'has_tests_path' => (bool) ($row['has_tests_path'] ?? false) ? 'true' : 'false',
                'file_family' => (string) ($row['file_family'] ?? 'unknown'),
                'task_shape' => (string) ($row['task_shape'] ?? 'unknown'),
                'worker_id' => (string) ($row['worker_id'] ?? 'unknown'),
                'proof_command_class' => (string) ($row['proof_command_class'] ?? 'unknown'),
            ];

            foreach ($buckets as $dimension => $bucket) {
                $facts[$dimension][$bucket] ??= $this->emptyBucket($dimension, $bucket);
                $facts[$dimension][$bucket][$outcome]++;
                $facts[$dimension][$bucket]['total']++;
            }
        }

        foreach ($facts as $dimension => $buckets) {
            ksort($buckets);
            foreach ($buckets as $bucket => $entry) {
                $total = (int) $entry['total'];
                $entry['insufficient_support'] = $total < self::MIN_SUPPORT;
                $entry['delivery_rate'] = $total >= self::MIN_SUPPORT
                    ? (float) (((int) $entry['delivered']) / $total)
                    : null;
                $buckets[$bucket] = $entry;
            }
            $facts[$dimension] = $buckets;
        }

        return $facts;
    }

    /**
     * Return strategy signals — dimension/bucket pairs with proven delivery_rate >= 0.5 —
     * for final-brain lane selection. Sorted by delivery_rate DESC, then support DESC.
     *
     * @return list<array<string,mixed>>
     */
    public function strategySignals(): array
    {
        $signals = [];

        foreach ($this->mine() as $dimension => $buckets) {
            foreach ($buckets as $bucket => $entry) {
                if ($entry['delivery_rate'] === null || $entry['delivery_rate'] < 0.5) {
                    continue;
                }
                $signals[] = [
                    'dimension' => $dimension,
                    'bucket' => $bucket,
                    'delivery_rate' => $entry['delivery_rate'],
                    'support' => $entry['total'],
                ];
            }
        }

        usort($signals, static function (array $a, array $b): int {
            $r = $b['delivery_rate'] <=> $a['delivery_rate'];

            return $r !== 0 ? $r : $b['support'] <=> $a['support'];
        });

        return $signals;
    }

    /**
     * Return reusable strategy patterns — a subset of strategySignals() where delivered successes
     * are at or above MIN_STRATEGY_OCCURRENCES. One-off successes that merely satisfy
     * delivery_rate >= 0.5 at minimum total support remain observations only and are excluded.
     *
     * Sorted by delivery_rate DESC, then by delivered DESC (most evidence first).
     *
     * @return list<array<string,mixed>>
     */
    public function strategyPatterns(): array
    {
        $patterns = [];

        foreach ($this->mine() as $dimension => $buckets) {
            foreach ($buckets as $bucket => $entry) {
                if ($entry['delivery_rate'] === null || $entry['delivery_rate'] < 0.5) {
                    continue;
                }
                if ((int) $entry['delivered'] < self::MIN_STRATEGY_OCCURRENCES) {
                    continue; // one-off success: observation only, not a reusable pattern
                }
                $patterns[] = [
                    'dimension'     => $dimension,
                    'bucket'        => $bucket,
                    'delivery_rate' => $entry['delivery_rate'],
                    'support'       => $entry['total'],
                    'delivered'     => (int) $entry['delivered'],
                ];
            }
        }

        usort($patterns, static function (array $a, array $b): int {
            $r = $b['delivery_rate'] <=> $a['delivery_rate'];

            return $r !== 0 ? $r : $b['delivered'] <=> $a['delivered'];
        });

        return $patterns;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyBucket(string $dimension, string $bucket): array
    {
        return [
            'dimension' => $dimension,
            'bucket' => $bucket,
            'delivered' => 0,
            'give_back' => 0,
            'rejected' => 0,
            'stale' => 0,
            'total' => 0,
            'insufficient_support' => true,
            'delivery_rate' => null,
        ];
    }

    private function countBucket(int $count): string
    {
        return match (true) {
            $count <= 1 => '0-1',
            $count <= 3 => '2-3',
            default => '4+',
        };
    }
}
