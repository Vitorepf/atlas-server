<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AtlasRetrievalCostLatencyGovernorService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class RetrievalCostLatencyGovernorTest extends TestCase
{
    public function test_low_risk_budget_is_ready_with_receipt_and_no_external_work(): void
    {
        $payload = app(AtlasRetrievalCostLatencyGovernorService::class)->govern([
            'domain' => 'developer',
            'task_type' => 'debug',
            'risk_level' => 'low',
        ]);

        $this->assertSame(AtlasRetrievalCostLatencyGovernorService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('atlas.aucri.retrieval_budget_policy.v1', data_get($payload, 'budget_policy.schema_version'));
        $this->assertSame('atlas.aucri.retrieval_cost_latency_receipt.v1', data_get($payload, 'receipt.schema_version'));
        $this->assertSame('eligible', data_get($payload, 'cache_decision.status'));
        $this->assertSame('not_needed', data_get($payload, 'degraded_mode.status'));
        $this->assertFalse(data_get($payload, 'claims.providers_invoked'));
        $this->assertFalse(data_get($payload, 'claims.writes'));
        $this->assertFalse(data_get($payload, 'claims.rivals_run'));
        $this->assertFalse(data_get($payload, 'claims.required_source_removed_silently'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['governor_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'receipt.receipt_hash'));
    }

    public function test_budget_that_would_trim_required_source_blocks_instead_of_degrading_silently(): void
    {
        $payload = app(AtlasRetrievalCostLatencyGovernorService::class)->govern([
            'risk_level' => 'high',
            'max_refs' => 1,
            'required_sources' => ['evidence_replay', 'code_intelligence', 'memory_signals'],
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('blocked', data_get($payload, 'degraded_mode.status'));
        $this->assertTrue(data_get($payload, 'degraded_mode.blocks_execution'));
        $this->assertSame(['code_intelligence', 'memory_signals'], data_get($payload, 'receipt.removed_required_sources'));
        $this->assertContains('required_sources_would_be_trimmed', data_get($payload, 'receipt.blocked_reasons'));
    }

    public function test_latency_budget_exceeded_degrades_low_risk_with_receipt(): void
    {
        $payload = app(AtlasRetrievalCostLatencyGovernorService::class)->govern([
            'risk_level' => 'low',
            'budget_ms' => 100,
            'simulated_latency_ms' => 900,
        ]);

        $this->assertSame('degraded', $payload['status']);
        $this->assertSame('degraded_with_receipt', data_get($payload, 'degraded_mode.status'));
        $this->assertFalse(data_get($payload, 'degraded_mode.blocks_execution'));
        $this->assertContains('latency_budget_exceeded', data_get($payload, 'degraded_mode.reasons'));
        $this->assertSame(900, data_get($payload, 'receipt.observed_latency_ms'));
    }

    public function test_command_emits_json(): void
    {
        $exit = Artisan::call('atlas:context:retrieval-budget', [
            '--domain' => 'developer',
            '--task-type' => 'debug',
            '--risk' => 'low',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasRetrievalCostLatencyGovernorService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
    }
}
