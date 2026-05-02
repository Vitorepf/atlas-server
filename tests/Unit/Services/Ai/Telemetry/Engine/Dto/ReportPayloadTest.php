<?php

namespace Tests\Unit\Services\Ai\Telemetry\Engine\Dto;

use App\Services\Ai\Telemetry\Engine\Dto\ReportPayload;
use PHPUnit\Framework\TestCase;

/**
 * Engine F0 — pin the contract of ReportPayload.
 *
 * The mobile contract (mobile-inbox-item.tsx detailsForReport, line 602+) reads
 * exactly 6 frozen keys: decision, highlights, next_actions, risks, validation, full_text.
 *
 * This test guarantees that ReportPayload->toArray() always emits these 6 keys at
 * the top level so the mobile renderer never sees null where it expects a value.
 */
class ReportPayloadTest extends TestCase
{
    public function test_to_array_preserves_six_frozen_keys_at_top_level(): void
    {
        $payload = new ReportPayload(
            frozen: [
                'decision' => 'Atlas operacional, sem regressões.',
                'highlights' => ['quality 78', 'efficiency 82'],
                'next_actions' => [],
                'risks' => [],
                'validation' => ['basis' => 'trace_created_at', 'dedupe_key' => 'atlas-ai-performance:daily:2026-04-30'],
                'full_text' => 'Relatório completo aqui...',
            ],
            extended: [],
            meta: ['schema_version' => 1, 'report_type' => 'daily'],
        );

        $out = $payload->toArray();

        foreach (['decision', 'highlights', 'next_actions', 'risks', 'validation', 'full_text'] as $key) {
            $this->assertArrayHasKey($key, $out,
                "Mobile renderer expects '{$key}' at top level — must never be moved.");
        }
    }

    public function test_extended_keys_merge_alongside_frozen_keys_for_schema_v2_clients(): void
    {
        $payload = new ReportPayload(
            frozen: ['decision' => 'd', 'highlights' => [], 'next_actions' => [], 'risks' => [], 'validation' => [], 'full_text' => 't'],
            extended: [
                'trust_gate' => ['trust_score' => 0.78, 'trust_level' => 'sufficient'],
                'findings' => [['id' => 'f1', 'category' => 'quality']],
            ],
            meta: ['schema_version' => 2],
        );

        $out = $payload->toArray();

        $this->assertArrayHasKey('trust_gate', $out,
            'Schema v2 additive keys go to top level alongside frozen keys; old clients ignore unknown keys.');
        $this->assertArrayHasKey('findings', $out);
        $this->assertSame(0.78, $out['trust_gate']['trust_score']);
    }

    public function test_to_array_writes_schema_version_into_validation_block(): void
    {
        $payload = new ReportPayload(
            frozen: ['decision' => 'x', 'highlights' => [], 'next_actions' => [], 'risks' => [],
                'validation' => ['basis' => 'trace_created_at'], 'full_text' => ''],
            extended: [],
            meta: ['schema_version' => 2],
        );

        $out = $payload->toArray();

        $this->assertSame(2, $out['validation']['schema_version'],
            'schema_version is mobile-facing — lives inside validation block where the renderer reads it.');
        $this->assertSame('trace_created_at', $out['validation']['basis'],
            'Existing validation keys must be preserved when schema_version is appended.');
    }

    public function test_meta_appears_under_underscore_meta_key_for_internal_observability(): void
    {
        $payload = new ReportPayload(
            frozen: ['decision' => '', 'highlights' => [], 'next_actions' => [], 'risks' => [], 'validation' => [], 'full_text' => ''],
            extended: [],
            meta: ['schema_version' => 1, 'run_id' => 'run-uuid', 'generated_at' => '2026-05-01T07:00:00Z'],
        );

        $out = $payload->toArray();

        $this->assertArrayHasKey('_meta', $out,
            'Internal metadata under _meta keeps it out of the mobile-facing surface but available for debug.');
        $this->assertSame('run-uuid', $out['_meta']['run_id']);
    }

    public function test_schema_version_helper_defaults_to_1_when_meta_missing(): void
    {
        $payload = new ReportPayload(frozen: [], extended: [], meta: []);

        $this->assertSame(1, $payload->schemaVersion(),
            'Default to schema_v1 when meta is empty — backward compatible default.');
    }

    public function test_schema_version_helper_returns_meta_value(): void
    {
        $payload = new ReportPayload(frozen: [], extended: [], meta: ['schema_version' => 2]);

        $this->assertSame(2, $payload->schemaVersion());
    }
}
