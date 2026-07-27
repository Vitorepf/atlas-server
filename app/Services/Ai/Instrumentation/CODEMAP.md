# CODEMAP — Instrumentation

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AiTraceArtifactsProjection | `App\Services\Ai\Instrumentation\AiTraceArtifactsProjection::forTrace` |
| AiTraceEngineeringReviewProjection | `App\Services\Ai\Instrumentation\AiTraceEngineeringReviewProjection::forTrace` |
| AiWorkerLogger | `App\Services\Ai\Instrumentation\AiWorkerLogger::event` |
| AtlasProviderProjectionAuditPurgePolicy | `App\Services\Ai\Instrumentation\AtlasProviderProjectionAuditPurgePolicy::evaluate` |
| AtlasProviderProjectionAuditService | `App\Services\Ai\Instrumentation\AtlasProviderProjectionAuditService::recordApply` |
| AtlasProviderProjectionService | `App\Services\Ai\Instrumentation\AtlasProviderProjectionService::generate` |
| AtlasValueMetricsService | `App\Services\Ai\Instrumentation\AtlasValueMetricsService::metricsForTranscript` |

Façades: 7.
