<?php

namespace App\Services\Ai\Telemetry\Engine\Dto;

/**
 * The assembled report payload — final output of Layer 6 ReportAssemblerService,
 * input to Layer 7 delivery (AtlasInboxService).
 *
 * Two-tier structure for backward-compatible mobile contract:
 *
 *   $frozen     The 6 keys consumed by mobile-inbox-item.tsx detailsForReport():
 *               decision, highlights, next_actions, risks, validation, full_text
 *               THESE NEVER CHANGE shape. New schema versions add to $extended.
 *
 *   $extended   New additive keys introduced by the engine (schema_version >= 2):
 *               trust_gate, findings, recommendations, recommendation_effectiveness,
 *               anomalies, trends, baselines, tools (already shipped via Fix 7c),
 *               router (already shipped via Fix 7a).
 *               Mobile clients on schema_version=1 ignore $extended; new clients
 *               read what they understand.
 *
 *   $meta       Runtime metadata: report_type, schema_version, generated_at,
 *               timezone, dedupe_key, confidence, run_id (links to ai_performance_report_runs).
 *
 * The compactReportPayload() pattern that AtlasInboxService consumes flattens
 * this structure into the final JSON sent over push.
 *
 * Decision #1 (extended for trust score in mobile): trust_gate goes inside $extended,
 * not $frozen. Cliente novo lê via schema_version=2; cliente antigo nunca vê null.
 */
final readonly class ReportPayload
{
    /**
     * @param  array<string,mixed>  $frozen    six keys: decision, highlights, next_actions, risks, validation, full_text
     * @param  array<string,mixed>  $extended  schema_v2 additive keys
     * @param  array<string,mixed>  $meta      report_type, schema_version, generated_at, timezone, dedupe_key, confidence, run_id
     */
    public function __construct(
        public array $frozen,
        public array $extended,
        public array $meta,
    ) {}

    /**
     * Flatten to the JSON shape consumed by AtlasInboxService::create()
     * payload['report'] field. Frozen keys are at the top level; extended
     * keys are merged below them so unknown extended keys are gracefully
     * ignored by older mobile renderers (they only iterate known frozen keys).
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array_merge(
            $this->frozen,
            $this->extended,
            ['validation' => array_merge(
                $this->frozen['validation'] ?? [],
                ['schema_version' => $this->meta['schema_version'] ?? 1]
            )],
            ['_meta' => $this->meta],
        );
    }

    public function schemaVersion(): int
    {
        return (int) ($this->meta['schema_version'] ?? 1);
    }
}
