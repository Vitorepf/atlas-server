<?php

namespace App\Services\Ai\Telemetry;

final class AiTraceMetricAggregatorVersions
{
    public const V1 = 'ai_trace_metric_aggregator_v1';

    public const V2 = 'ai_trace_metric_aggregator_v2';

    public const V3 = 'ai_trace_metric_aggregator_v3';

    public const CURRENT = self::V3;

    /**
     * Versions that expose router, diagnostics and tool score components.
     *
     * Trend windows still compare exact versions only; this list is for feature
     * gates that need to know whether a pure window has the modern diagnostics.
     */
    public const MODERN_DIAGNOSTIC_VERSIONS = [
        self::V2,
        self::V3,
    ];

    public static function supportsModernDiagnostics(string $version): bool
    {
        return in_array($version, self::MODERN_DIAGNOSTIC_VERSIONS, true);
    }
}
