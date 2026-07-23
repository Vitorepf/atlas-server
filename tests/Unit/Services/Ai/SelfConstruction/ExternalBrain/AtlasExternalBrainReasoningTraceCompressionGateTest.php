<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainReasoningTraceCompressionGate;
use Tests\TestCase;

final class AtlasExternalBrainReasoningTraceCompressionGateTest extends TestCase
{
    private function gate(): AtlasExternalBrainReasoningTraceCompressionGate
    {
        return new AtlasExternalBrainReasoningTraceCompressionGate;
    }

    // ── AC: raw traces are excluded ──

    public function test_raw_traces_excluded(): void
    {
        $result = $this->gate()->compress([
            'decision' => 'proceed',
            'rationale' => 'evidence supports it',
            'raw_prompt' => 'secret prompt data',
            'provider_trace' => 'trace data',
            'api_key' => 'sk-xxx',
        ]);

        $this->assertTrue($result['compressed']);
        $this->assertArrayNotHasKey('raw_prompt', $result['decision_facts']);
        $this->assertArrayNotHasKey('provider_trace', $result['decision_facts']);
        $this->assertArrayNotHasKey('api_key', $result['decision_facts']);
        $this->assertContains('raw_prompt', $result['excluded_keys']);
        $this->assertContains('provider_trace', $result['excluded_keys']);
        $this->assertContains('api_key', $result['excluded_keys']);
    }

    // ── AC: decision facts are preserved ──

    public function test_decision_facts_preserved(): void
    {
        $result = $this->gate()->compress([
            'decision' => 'proceed',
            'rationale' => 'evidence supports it',
            'evidence_refs' => ['ref1', 'ref2'],
        ]);

        $this->assertTrue($result['compressed']);
        $this->assertSame('proceed', $result['decision_facts']['decision']);
        $this->assertSame('evidence supports it', $result['decision_facts']['rationale']);
        $this->assertSame(['ref1', 'ref2'], $result['decision_facts']['evidence_refs']);
    }

    // ── AC: missing facts block compression ──

    public function test_missing_decision_blocks_compression(): void
    {
        $result = $this->gate()->compress([
            'rationale' => 'missing decision',
        ]);

        $this->assertFalse($result['compressed']);
        $this->assertContains('missing_decision_fact:decision', $result['failures']);
    }

    public function test_missing_rationale_blocks_compression(): void
    {
        $result = $this->gate()->compress([
            'decision' => 'proceed',
        ]);

        $this->assertFalse($result['compressed']);
        $this->assertContains('missing_decision_fact:rationale', $result['failures']);
    }

    // ── provider-safe ──

    public function test_result_is_provider_safe(): void
    {
        $result = $this->gate()->compress([
            'decision' => 'proceed',
            'rationale' => 'ok',
        ]);

        $this->assertTrue($result['provider_safe']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->gate()->compress([]);

        $this->assertSame(AtlasExternalBrainReasoningTraceCompressionGate::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('compressed', $result);
        $this->assertArrayHasKey('decision_facts', $result);
        $this->assertArrayHasKey('provider_safe', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $trace = ['decision' => 'proceed', 'rationale' => 'ok'];
        $a = $this->gate()->compress($trace);
        $b = $this->gate()->compress($trace);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
