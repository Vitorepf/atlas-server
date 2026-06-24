<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ClosedLoop;

final class AtlasMaestroOutcomePatternMiner
{
    public const MIN_SUPPORT = 8;

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
                    ? ((int) $entry['delivered']) / $total
                    : null;
                $buckets[$bucket] = $entry;
            }
            $facts[$dimension] = $buckets;
        }

        return $facts;
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
