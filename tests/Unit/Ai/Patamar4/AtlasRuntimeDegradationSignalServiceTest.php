<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Patamar4;

use App\Services\Ai\Patamar4\AtlasRuntimeDegradationSignalService;
use Tests\TestCase;

class AtlasRuntimeDegradationSignalServiceTest extends TestCase
{
    private string $log;

    private AtlasRuntimeDegradationSignalService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $u = uniqid('', true);
        $this->log = sys_get_temp_dir()."/atlas_runtime_degradation_{$u}.jsonl";
        $this->svc = $this->app->make(AtlasRuntimeDegradationSignalService::class);
        $this->svc->setLogPathForTesting($this->log);
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        parent::tearDown();
    }

    private function signal(string $severity, array $overrides = []): array
    {
        return array_merge([
            'source' => 'ACOP',
            'kind' => 'embedding_latency_spike',
            'severity' => $severity,
            'message' => 'latency p95 above 1500ms in ASEF',
            'metric_name' => 'asef_p95_latency_ms',
            'observed' => 2100,
            'threshold' => 1500,
        ], $overrides);
    }

    public function test_critical_signal_fires_auto_tick(): void
    {
        config(['atlas.patamar4.runtime_degradation_auto_tick_enabled' => true]);
        config(['atlas.patamar4.runtime_degradation_auto_tick_threshold' => 'high']);
        $env = $this->svc->record($this->signal('critical'));
        $this->assertTrue($env['auto_tick']['fired']);
        $this->assertNotNull($env['auto_tick']['tick_hash']);
    }

    public function test_low_signal_does_not_fire_tick(): void
    {
        config(['atlas.patamar4.runtime_degradation_auto_tick_threshold' => 'high']);
        $env = $this->svc->record($this->signal('low'));
        $this->assertFalse($env['auto_tick']['fired']);
        $this->assertSame('severity_below_threshold', $env['auto_tick']['reason']);
    }

    public function test_auto_tick_disabled_skips_tick(): void
    {
        config(['atlas.patamar4.runtime_degradation_auto_tick_enabled' => false]);
        $env = $this->svc->record($this->signal('critical'));
        $this->assertFalse($env['auto_tick']['fired']);
        $this->assertSame('auto_tick_disabled', $env['auto_tick']['reason']);
    }

    public function test_signal_canonical_envelope(): void
    {
        $env = $this->svc->record($this->signal('high'));
        $this->assertSame('atlas.runtime_degradation.signal.v1', $env['schema_version']);
        $this->assertStringStartsWith('sha256:', $env['signal_hash']);
        $this->assertSame('ACOP', $env['source']);
        $this->assertSame(3, $env['severity_rank']);
    }

    public function test_unknown_severity_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->record($this->signal('nope'));
    }

    public function test_missing_fields_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->record(['source' => '', 'kind' => '', 'severity' => 'high', 'message' => '']);
    }

    public function test_jsonl_persists_signals(): void
    {
        $this->svc->record($this->signal('low'));
        $this->svc->record($this->signal('high'));
        $this->assertCount(2, $this->svc->listSignals());
    }

    public function test_claim_policy_provider_safe(): void
    {
        $cp = $this->svc->claimPolicy();
        $this->assertFalse($cp['benchmark_claim_allowed']);
        $this->assertTrue($cp['cognitive_immune_law_enforced']);
        $this->assertTrue($cp['local_first_only']);
    }
}
