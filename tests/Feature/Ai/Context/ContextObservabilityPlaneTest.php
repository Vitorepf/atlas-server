<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AtlasContextObservabilityPlaneService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ContextObservabilityPlaneTest extends TestCase
{
    public function test_snapshot_aggregates_context_traces_sources_metrics_and_claims(): void
    {
        $payload = app(AtlasContextObservabilityPlaneService::class)->snapshot([
            'domain' => 'developer',
            'task_type' => 'debug',
            'risk_level' => 'low',
        ]);

        $this->assertSame(AtlasContextObservabilityPlaneService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('healthy', $payload['status']);
        $this->assertSame('atlas.aucri.context_observability_snapshot.v1', data_get($payload, 'snapshot.schema_version'));
        $this->assertSame(3, data_get($payload, 'snapshot.trace_count'));
        $this->assertGreaterThanOrEqual(2, data_get($payload, 'snapshot.source_count'));
        $this->assertSame([], $payload['blockers']);
        $this->assertContains('areba', array_column($payload['traces'], 'retrieval_stage'));
        $this->assertContains('arclg', array_column($payload['traces'], 'retrieval_stage'));
        $this->assertContains('arfl', array_column($payload['traces'], 'retrieval_stage'));
        $this->assertFalse(data_get($payload, 'claims.providers_invoked'));
        $this->assertFalse(data_get($payload, 'claims.writes'));
        $this->assertFalse(data_get($payload, 'claims.raw_text_exposed'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['snapshot_hash']);
    }

    public function test_snapshot_redacts_raw_prompt_and_surfaces_blockers(): void
    {
        $secret = 'SEGREDO-ACOP-NAO-VAZAR';
        $payload = app(AtlasContextObservabilityPlaneService::class)->snapshot([
            'query' => $secret,
            'risk_level' => 'high',
            'max_refs' => 1,
            'required_sources' => ['evidence_replay', 'code_intelligence', 'memory_signals'],
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'snapshot.blocker_count'));
        $this->assertContains('arclg_budget', array_column($payload['blockers'], 'kind'));
        $this->assertStringNotContainsString($secret, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_snapshot_hash_is_deterministic(): void
    {
        $first = app(AtlasContextObservabilityPlaneService::class)->snapshot();
        $second = app(AtlasContextObservabilityPlaneService::class)->snapshot();

        $this->assertSame($first['snapshot_hash'], $second['snapshot_hash']);
    }

    public function test_command_emits_json(): void
    {
        $exit = Artisan::call('atlas:context:observability', [
            '--domain' => 'developer',
            '--task-type' => 'debug',
            '--risk' => 'low',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasContextObservabilityPlaneService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('healthy', $payload['status']);
    }
}
