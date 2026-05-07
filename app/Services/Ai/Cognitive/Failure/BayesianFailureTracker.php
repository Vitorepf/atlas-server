<?php

namespace App\Services\Ai\Cognitive\Failure;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Slo\KernelSloProbe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BayesianFailureTracker
{
    public const SCHEMA_VERSION = 'atlas.cognitive.failure_diversity_metric.v1';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
        private readonly KernelSloProbe $slo,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function compute(?string $domain = null, int $days = 30): array
    {
        return $this->slo->measure('cognitive.failure.diversity', function () use ($domain, $days): array {
            if (! $this->tableReady()) {
                return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'table_missing'];
            }

            $windowStart = now()->subDays(max(1, $days));
            $windowEnd = now();
            $query = DB::table('failure_signatures')->whereBetween('recorded_at', [$windowStart->toJSON(), $windowEnd->toJSON()]);
            if ($domain !== null && $domain !== '') {
                $query->where('domain', $domain);
            }

            $rows = $query->get();
            $total = $rows->count();
            $unique = $rows->pluck('signature_key')->unique()->count();
            $diversity = $total > 0 ? round($unique / $total, 3) : 1.0;
            $domainId = $domain ?: 'all';
            $topRepeated = $rows
                ->groupBy('signature_key')
                ->map(fn ($group, string $key): array => [
                    'signature_key' => $key,
                    'count' => $group->count(),
                    'category' => (string) ($group->first()->category ?? ''),
                    'sub_cause' => (string) ($group->first()->sub_cause ?? ''),
                ])
                ->sortByDesc('count')
                ->take(5)
                ->values()
                ->all();
            $alerts = DB::table('failure_repetition_alerts')
                ->when($domain !== null && $domain !== '', fn ($query) => $query->where('domain', $domain))
                ->where('alert_status', 'open')
                ->count();

            $id = DB::table('failure_diversity_metrics')->insertGetId([
                'domain' => $domainId,
                'window_start' => $windowStart,
                'window_end' => $windowEnd,
                'total_failures' => $total,
                'unique_signatures' => $unique,
                'diversity_index' => $diversity,
                'repetition_alerts_triggered' => $alerts,
                'top_repeated_signatures' => json_encode($topRepeated, JSON_THROW_ON_ERROR),
                'computed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $metric = [
                'schema_version' => self::SCHEMA_VERSION,
                'id' => $id,
                'status' => 'computed',
                'domain' => $domainId,
                'window_start' => $windowStart->toJSON(),
                'window_end' => $windowEnd->toJSON(),
                'total_failures' => $total,
                'unique_signatures' => $unique,
                'diversity_index' => $diversity,
                'repetition_alerts_triggered' => $alerts,
                'top_repeated_signatures' => $topRepeated,
                'interpretation' => $this->interpretation($diversity, $alerts),
            ];

            $this->ledger->record(LedgerEventType::FailureDiversityIndexComputed, [
                'schema_version' => 'atlas.cognitive.failure_diversity_index_computed.v1',
                'metric' => $metric,
            ], [
                'tenant_id' => 'default',
                'operator_id' => 'atlas_failure_tracker',
                'envelope_id' => 'failure_diversity:'.$domainId,
                'correlation_id' => 'failure_diversity:'.$domainId,
                'emitter_stage' => 'atlas.cognitive.failure_diversity',
                'emitter_version' => self::SCHEMA_VERSION,
            ]);

            return $metric;
        }, ['domain' => (string) ($domain ?: 'all')]);
    }

    private function interpretation(float $diversity, int $alerts): string
    {
        if ($alerts > 0) {
            return 'repetition_attention_required';
        }

        if ($diversity >= 0.7) {
            return 'healthy_diverse_failures';
        }

        return 'low_diversity_possible_stagnation';
    }

    private function tableReady(): bool
    {
        return Schema::hasTable('failure_signatures')
            && Schema::hasTable('failure_diversity_metrics')
            && Schema::hasTable('failure_repetition_alerts');
    }
}
