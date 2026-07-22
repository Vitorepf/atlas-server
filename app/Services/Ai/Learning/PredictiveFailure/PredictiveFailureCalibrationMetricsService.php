<?php

namespace App\Services\Ai\Cognitive\PredictiveFailure;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\DB;

class PredictiveFailureCalibrationMetricsService
{
    public const SCHEMA_VERSION = 'atlas.cognitive.predictive_failure.calibration_metrics.v1';

    public function __construct(
        private readonly PredictiveFailureRepository $repository,
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function compute(?string $domain = null, int $days = 60): array
    {
        if (! $this->repository->tableReady()) {
            return ['schema_version' => self::SCHEMA_VERSION, 'status' => 'table_missing'];
        }

        $rows = collect($this->repository->history($domain, $days));
        $withOutcomes = $rows->filter(fn (array $row): bool => $row['prediction_calibration_error'] !== null);
        $distribution = $rows->groupBy('calibration_band')->map->count()->all();
        $avgError = $withOutcomes->isNotEmpty()
            ? round($withOutcomes->avg('prediction_calibration_error'), 3)
            : null;
        $brier = $withOutcomes->isNotEmpty()
            ? round($withOutcomes->avg(fn (array $row): float => $this->squaredCalibrationError($row)), 3)
            : null;
        $windowStart = now()->subDays(max(1, $days));
        $windowEnd = now();
        $domainId = $domain ?: 'all';

        $id = DB::table('predictive_failure_calibration_metrics')->insertGetId([
            'domain' => $domainId,
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'total_insertions' => $rows->count(),
            'outcomes_recorded' => $withOutcomes->count(),
            'avg_calibration_error' => $avgError,
            'brier_score' => $brier,
            'insertion_distribution_by_band' => json_encode($distribution, JSON_THROW_ON_ERROR),
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $metric = [
            'schema_version' => self::SCHEMA_VERSION,
            'id' => $id,
            'status' => 'computed',
            'domain' => $domainId,
            'window_days' => max(1, $days),
            'total_insertions' => $rows->count(),
            'outcomes_recorded' => $withOutcomes->count(),
            'avg_calibration_error' => $avgError,
            'brier_score' => $brier,
            'insertion_distribution_by_band' => $distribution,
        ];

        $this->ledger->record(LedgerEventType::PredictiveFailureCalibrationComputed, [
            'schema_version' => self::SCHEMA_VERSION,
            'metric' => $metric,
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'atlas_predictive_failure',
            'envelope_id' => 'predictive_failure_metrics:'.$domainId,
            'correlation_id' => 'predictive_failure_metrics:'.$domainId,
            'emitter_stage' => 'atlas.cognitive.predictive_failure',
            'emitter_version' => self::SCHEMA_VERSION,
        ]);

        return $metric;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function squaredCalibrationError(array $row): float
    {
        $outcome = $row['outcome'] ?? null;
        $actual = match ($outcome) {
            'success' => 0.0,
            'partial' => 0.5,
            'failure' => 1.0,
            default => null,
        };

        return $actual === null ? 0.0 : ((float) $row['predicted_failure_probability'] - $actual) ** 2;
    }
}
