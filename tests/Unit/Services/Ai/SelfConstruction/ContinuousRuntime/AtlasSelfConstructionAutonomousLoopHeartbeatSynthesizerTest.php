<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ContinuousRuntime;

use App\Services\Ai\SelfConstruction\ContinuousRuntime\AtlasSelfConstructionAutonomousLoopHeartbeatSynthesizer;
use Tests\TestCase;

final class AtlasSelfConstructionAutonomousLoopHeartbeatSynthesizerTest extends TestCase
{
    private function synthesizer(): AtlasSelfConstructionAutonomousLoopHeartbeatSynthesizer
    {
        return new AtlasSelfConstructionAutonomousLoopHeartbeatSynthesizer;
    }

    // ── AC: heartbeat includes liveness, risk, next_action and evidence freshness ──

    public function test_heartbeat_includes_liveness(): void
    {
        $result = $this->synthesizer()->synthesize([
            'loop_running' => true,
            'health_status' => 'healthy',
        ]);

        $this->assertSame('alive', $result['liveness']);
    }

    public function test_heartbeat_includes_risk(): void
    {
        $result = $this->synthesizer()->synthesize([
            'loop_running' => true,
            'health_status' => 'healthy',
            'risk_level' => 'high',
        ]);

        $this->assertSame('high', $result['risk_level']);
    }

    public function test_heartbeat_includes_next_action(): void
    {
        $result = $this->synthesizer()->synthesize([
            'loop_running' => true,
            'health_status' => 'healthy',
            'queue_depth' => 10,
        ]);

        $this->assertNotEmpty($result['next_action']);
    }

    public function test_heartbeat_includes_evidence_freshness(): void
    {
        $result = $this->synthesizer()->synthesize([
            'loop_running' => true,
            'health_status' => 'healthy',
            'evidence_age_seconds' => 100,
        ]);

        $this->assertSame('fresh', $result['evidence_freshness']);
    }

    public function test_stale_evidence_reported(): void
    {
        $result = $this->synthesizer()->synthesize([
            'loop_running' => true,
            'health_status' => 'healthy',
            'evidence_age_seconds' => 7200,
        ]);

        $this->assertSame('stale', $result['evidence_freshness']);
    }

    // ── AC: without leaking raw prompts or traces ──

    public function test_heartbeat_does_not_leak_raw_prompts_or_traces(): void
    {
        $result = $this->synthesizer()->synthesize([
            'loop_running' => true,
            'health_status' => 'healthy',
        ]);

        $json = (string) json_encode($result);
        $this->assertStringNotContainsString('raw_prompt', $json);
        $this->assertStringNotContainsString('provider_trace', $json);
        $this->assertStringNotContainsString('api_key', $json);
        $this->assertTrue($result['provider_safe']);
    }

    // ── next_action logic ──

    public function test_dry_queue_originate(): void
    {
        $result = $this->synthesizer()->synthesize([
            'loop_running' => true,
            'health_status' => 'healthy',
            'queue_depth' => 0,
        ]);

        $this->assertSame('originate', $result['next_action']);
    }

    public function test_give_back_pressure_repair_first(): void
    {
        $result = $this->synthesizer()->synthesize([
            'loop_running' => true,
            'health_status' => 'healthy',
            'queue_depth' => 5,
            'recent_success_count' => 1,
            'recent_give_back_count' => 3,
        ]);

        $this->assertSame('repair_first', $result['next_action']);
    }

    public function test_dead_loop_restart(): void
    {
        $result = $this->synthesizer()->synthesize([
            'loop_running' => false,
            'health_status' => 'down',
        ]);

        $this->assertSame('dead', $result['liveness']);
        $this->assertSame('restart_loop', $result['next_action']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->synthesizer()->synthesize([]);

        $this->assertSame(AtlasSelfConstructionAutonomousLoopHeartbeatSynthesizer::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('liveness', $result);
        $this->assertArrayHasKey('risk_level', $result);
        $this->assertArrayHasKey('next_action', $result);
        $this->assertArrayHasKey('evidence_freshness', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = ['loop_running' => true, 'health_status' => 'healthy', 'queue_depth' => 5];

        $a = $this->synthesizer()->synthesize($input);
        $b = $this->synthesizer()->synthesize($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
