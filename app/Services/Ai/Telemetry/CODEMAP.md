# CODEMAP — Telemetry

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AiCostEstimator | `App\Services\Ai\Telemetry\AiCostEstimator::estimate` |
| AiMetricDailySnapshotService | `App\Services\Ai\Telemetry\AiMetricDailySnapshotService::refresh` |
| AiOutcomeAttributionService | `App\Services\Ai\Telemetry\AiOutcomeAttributionService::record` |
| AiProviderCostRateService | `App\Services\Ai\Telemetry\AiProviderCostRateService::upsert` |
| AiTelemetryCollector | `App\Services\Ai\Telemetry\AiTelemetryCollector::record` |
| AiTelemetryHealthService | `App\Services\Ai\Telemetry\AiTelemetryHealthService::evaluate` |
| AiTelemetryPerformanceReportService | `App\Services\Ai\Telemetry\AiTelemetryPerformanceReportService::buildDaily` |
| AiTelemetryScorecardService | `App\Services\Ai\Telemetry\AiTelemetryScorecardService::build` |
| AiTelemetryWindowInput | `App\Services\Ai\Telemetry\AiTelemetryWindowInput::hours` |
| AiTraceMetricAggregator | `App\Services\Ai\Telemetry\AiTraceMetricAggregator::recomputeTrace` |
| RecommendationLifecycleService | `App\Services\Ai\Telemetry\Engine\RecommendationLifecycleService::recommend` |
| RecommendationMeasurementService | `App\Services\Ai\Telemetry\Engine\RecommendationMeasurementService::measureDue` |
| TrustGateService | `App\Services\Ai\Telemetry\Engine\TrustGateService::evaluate` |

Façades: 13.
