<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AtlasContextFreshnessQualityGateService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ContextFreshnessQualityGateTest extends TestCase
{
    public function test_programming_debug_context_passes_with_fresh_required_sources(): void
    {
        $payload = app(AtlasContextFreshnessQualityGateService::class)->evaluate([
            'objective' => 'corrigir bug no repo com teste falhando e evidence replay',
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
            'max_refs' => 8,
        ]);

        $this->assertSame(AtlasContextFreshnessQualityGateService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('passed', $payload['status']);
        $this->assertSame(AtlasContextFreshnessQualityGateService::FRESHNESS_REPORT_SCHEMA, data_get($payload, 'freshness_report.schema_version'));
        $this->assertSame(AtlasContextFreshnessQualityGateService::QUALITY_GATE_SCHEMA, data_get($payload, 'context_quality_gate.schema_version'));
        $this->assertSame('allow_context', data_get($payload, 'context_quality_gate.action'));
        $this->assertSame([
            'memory_signals' => true,
            'vector_retrieval' => true,
            'code_intelligence' => true,
            'evidence_replay' => true,
        ], data_get($payload, 'context_quality_gate.required_source_coverage'));

        foreach (data_get($payload, 'freshness_report.items') as $item) {
            $this->assertArrayHasKey('source_ref_hash', $item);
            $this->assertArrayNotHasKey('source_ref', $item);
            $this->assertTrue($item['provider_safe']);
        }

        $this->assertFalse(data_get($payload, 'policy.providers_invoked'));
        $this->assertFalse(data_get($payload, 'policy.writes'));
        $this->assertFalse(data_get($payload, 'policy.raw_text_exposed'));
        $this->assertStringNotContainsString('corrigir bug no repo', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['freshness_quality_gate_hash']);
    }

    public function test_missing_required_source_coverage_blocks_even_when_ranking_only_degrades(): void
    {
        $payload = app(AtlasContextFreshnessQualityGateService::class)->evaluate([
            'objective' => 'corrigir bug no repo com teste falhando e evidence replay',
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
            'max_refs' => 2,
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('block_execution', data_get($payload, 'context_quality_gate.action'));
        $this->assertContains('missing_required_source_coverage', data_get($payload, 'context_quality_gate.blocking_reasons'));
        $this->assertSame('degraded', data_get($payload, 'ranking_ref.status'));
    }

    public function test_high_risk_context_blocks_when_agentic_sufficiency_blocks(): void
    {
        $payload = app(AtlasContextFreshnessQualityGateService::class)->evaluate([
            'objective' => 'decisao critica sobre arquitetura e impacto entre sistemas',
            'task_type' => 'decision',
            'domain' => 'strategy',
            'risk_level' => 'high',
            'max_refs' => 8,
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('ranking_blocked', data_get($payload, 'context_quality_gate.blocking_reasons'));
        $this->assertSame('blocked', data_get($payload, 'ranking_ref.status'));
        $this->assertTrue(data_get($payload, 'context_quality_gate.fail_closed'));
    }

    public function test_explicit_contradiction_signal_blocks_execution_without_leaking_raw_signal(): void
    {
        $payload = app(AtlasContextFreshnessQualityGateService::class)->evaluate([
            'objective' => 'debug repo with tests',
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
            'max_refs' => 8,
            'contradictions' => ['source A says shipped, source B says planned'],
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(1, data_get($payload, 'contradiction_report.signal_count'));
        $this->assertContains('contradiction_detected', data_get($payload, 'context_quality_gate.blocking_reasons'));
        $this->assertStringNotContainsString('source A says shipped', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_command_emits_json(): void
    {
        $exit = Artisan::call('atlas:context:freshness-quality', [
            '--query' => 'debug repo with tests',
            '--task-type' => 'debug',
            '--domain' => 'developer',
            '--max-refs' => 8,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasContextFreshnessQualityGateService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertArrayHasKey('freshness_quality_gate_hash', $payload);
    }
}
