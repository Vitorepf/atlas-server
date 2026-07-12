<?php

declare(strict_types=1);

namespace App\Services\Ai\LongHorizon;

use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class CompactionRecoverySampler
{
    public const SCHEMA_VERSION = 'atlas.compaction.recovery_sample.v1';

    public function __construct(private readonly CompactionRecoveryExecutor $recovery) {}

    /**
     * @return array<string,mixed>
     */
    public function sample(int $limit = 50, int $days = 14, int $minimumSamples = 20, bool $recordEvidence = true): array
    {
        $limit = max(1, min(500, $limit));
        $days = max(1, min(365, $days));
        $minimumSamples = max(1, $minimumSamples);
        $since = CarbonImmutable::now('UTC')->subDays($days);

        if (! DatabaseTableAvailability::has('atlas_long_horizon_compaction_receipts')) {
            return $this->payload(
                status: 'unavailable',
                passed: false,
                sampledReceipts: 0,
                minimumSamples: $minimumSamples,
                since: $since,
                reason: 'atlas_long_horizon_compaction_receipts_table_missing',
            );
        }

        $receipts = AtlasLongHorizonCompactionReceipt::query()
            ->where('created_at', '>=', $since)
            ->latest('created_at')
            ->limit($limit)
            ->get();

        if ($receipts->count() < $minimumSamples) {
            return $this->payload(
                status: 'insufficient_sample',
                passed: false,
                sampledReceipts: $receipts->count(),
                minimumSamples: $minimumSamples,
                since: $since,
                reason: 'receipt_count_below_floor',
            );
        }

        $byKind = [];
        $byScope = [];
        $sampledQueries = 0;
        $recoveredQueries = 0;
        $missingQueries = 0;

        foreach ($receipts as $receipt) {
            $recovery = $this->recovery->recover($receipt);
            $queries = $this->stringList($receipt->recovery_queries);
            $scope = (string) $receipt->scope_type;
            $byScope[$scope] ??= ['sampled' => 0, 'recovered' => 0, 'missing' => 0];
            $byScope[$scope]['sampled'] += count($queries);

            foreach ($queries as $query) {
                $kind = $this->kindFromQuery($query);
                $byKind[$kind] ??= ['sampled' => 0, 'recovered' => 0, 'missing' => 0];
                $byKind[$kind]['sampled']++;
                $sampledQueries++;
            }

            foreach ((array) ($recovery['items'] ?? []) as $key => $item) {
                $kind = is_array($item) ? (string) ($item['kind'] ?? $this->kindFromKey((string) $key)) : $this->kindFromKey((string) $key);
                $byKind[$kind] ??= ['sampled' => 0, 'recovered' => 0, 'missing' => 0];
                $byKind[$kind]['recovered']++;
                $byScope[$scope]['recovered']++;
                $recoveredQueries++;
            }

            foreach ((array) ($recovery['missing'] ?? []) as $missing) {
                $kind = $this->kindFromQuery(is_array($missing) ? (string) ($missing['query'] ?? '') : '');
                $byKind[$kind] ??= ['sampled' => 0, 'recovered' => 0, 'missing' => 0];
                $byKind[$kind]['missing']++;
                $byScope[$scope]['missing']++;
                $missingQueries++;
            }
        }

        if ($sampledQueries === 0) {
            return $this->payload(
                status: 'insufficient_sample',
                passed: false,
                sampledReceipts: $receipts->count(),
                minimumSamples: $minimumSamples,
                since: $since,
                reason: 'no_recovery_queries_in_sample',
            );
        }

        $byKind = $this->withRates($byKind);
        $byScope = $this->withRates($byScope);
        $rate = round($recoveredQueries / max(1, $sampledQueries), 4);
        $threshold = max(0.0, min(1.0, (float) config('atlas.compaction.recovery_sample_min_rate', 0.95)));
        $passed = $rate >= $threshold;
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $passed ? 'ok' : 'attention',
            'passed' => $passed,
            'sampled_receipts' => $receipts->count(),
            'sampled_recovery_queries' => $sampledQueries,
            'recovered_queries' => $recoveredQueries,
            'missing_queries' => $missingQueries,
            'minimum_samples' => $minimumSamples,
            'recovery_rate' => $rate,
            'required_recovery_rate' => $threshold,
            'by_kind' => $byKind,
            'by_scope' => $byScope,
            'window' => ['days' => $days, 'since' => $since->toIso8601String()],
            'policy' => [
                'read_only' => true,
                'writes_recovered_content' => false,
                'evidence_excludes_recovered_items' => true,
            ],
            'generated_at' => now()->toIso8601String(),
        ];

        if ($recordEvidence) {
            $this->appendEvidence($payload);
        }

        return $payload;
    }

    /**
     * @param  array<string,array{sampled:int,recovered:int,missing:int}>  $groups
     * @return array<string,array{sampled:int,recovered:int,missing:int,recovery_rate:float|null}>
     */
    private function withRates(array $groups): array
    {
        ksort($groups);
        foreach ($groups as $name => $row) {
            $sampled = (int) ($row['sampled'] ?? 0);
            $groups[$name]['recovery_rate'] = $sampled > 0 ? round((int) ($row['recovered'] ?? 0) / $sampled, 4) : null;
        }

        return $groups;
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(string $status, bool $passed, int $sampledReceipts, int $minimumSamples, CarbonImmutable $since, string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'passed' => $passed,
            'sampled_receipts' => $sampledReceipts,
            'minimum_samples' => $minimumSamples,
            'recovery_rate' => null,
            'reason' => $reason,
            'window' => ['since' => $since->toIso8601String()],
            'policy' => [
                'read_only' => true,
                'writes_recovered_content' => false,
                'evidence_excludes_recovered_items' => true,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @param array<string,mixed> $payload */
    private function appendEvidence(array $payload): void
    {
        $path = (string) config('atlas.compaction.recovery_sample_evidence_path', 'atlas/evidence/compaction-recovery-samples.jsonl');
        if (trim($path) === '') {
            return;
        }

        $evidence = $payload;
        unset($evidence['items'], $evidence['recovered_items']);

        try {
            Storage::disk('local')->append($path, json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) ?: '{}');
        } catch (Throwable) {
            // Read-only sampler must not fail because evidence append is unavailable.
        }
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $entry): string => is_scalar($entry) ? trim((string) $entry) : '',
            (array) $value,
        ), static fn (string $entry): bool => $entry !== ''));
    }

    private function kindFromQuery(string $query): string
    {
        if (preg_match('/^rehydrate\s+([a-z_]+):/i', trim($query), $matches) === 1) {
            return mb_strtolower((string) $matches[1]);
        }

        return 'unknown';
    }

    private function kindFromKey(string $key): string
    {
        $pos = strpos($key, ':');

        return $pos === false ? 'unknown' : mb_strtolower(substr($key, 0, $pos));
    }
}
