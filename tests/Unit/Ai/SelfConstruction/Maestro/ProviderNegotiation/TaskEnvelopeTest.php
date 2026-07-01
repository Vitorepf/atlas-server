<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\ProviderNegotiation;

use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\TaskEnvelope;
use PHPUnit\Framework\TestCase;

final class TaskEnvelopeTest extends TestCase
{
    private function envelope(array $overrides = []): TaskEnvelope
    {
        $defaults = [
            'taskId' => 't-1',
            'kind' => 'maestro',
            'requiredCapabilities' => ['php', 'tests'],
            'deadline' => '2026-06-24T08:00:00Z',
            'localOnly' => false,
            'sensitivityClass' => 'public',
        ];

        return new TaskEnvelope(...array_merge($defaults, $overrides));
    }

    // ── AC: envelopes include risk_level, proof_floor, lane, expected_value and required_capability ──

    public function test_to_array_includes_the_new_negotiation_contract_fields(): void
    {
        $envelope = $this->envelope([
            'riskLevel' => 'low',
            'proofFloor' => 'lenient',
            'lane' => 'feature',
            'expectedValue' => 4.5,
        ]);
        $arr = $envelope->toArray();

        $this->assertSame('low', $arr['risk_level']);
        $this->assertSame('lenient', $arr['proof_floor']);
        $this->assertSame('feature', $arr['lane']);
        $this->assertSame(4.5, $arr['expected_value']);
        $this->assertSame(['php', 'tests'], $arr['required_capability']);
    }

    // ── AC: missing risk or proof floor defaults conservatively ────────────────

    public function test_missing_risk_level_defaults_to_high(): void
    {
        $envelope = $this->envelope();
        $this->assertSame('high', $envelope->toArray()['risk_level']);
    }

    public function test_missing_proof_floor_defaults_to_strict(): void
    {
        $envelope = $this->envelope();
        $this->assertSame('strict', $envelope->toArray()['proof_floor']);
    }

    public function test_explicit_risk_level_and_proof_floor_override_the_conservative_default(): void
    {
        $envelope = $this->envelope(['riskLevel' => 'low', 'proofFloor' => 'lenient']);
        $arr = $envelope->toArray();

        $this->assertSame('low', $arr['risk_level']);
        $this->assertSame('lenient', $arr['proof_floor']);
    }

    // ── AC: toArray omits raw task prompt or provider-sensitive content ────────

    public function test_to_array_never_includes_raw_prompt_or_provider_sensitive_keys(): void
    {
        $arr = $this->envelope()->toArray();

        foreach (['raw_prompt', 'prompt', 'provider_trace', 'conversation_text', 'secret', 'api_key'] as $sensitiveKey) {
            $this->assertArrayNotHasKey($sensitiveKey, $arr);
        }
    }

    public function test_to_array_default_lane_and_expected_value(): void
    {
        $arr = $this->envelope()->toArray();

        $this->assertSame('', $arr['lane']);
        $this->assertSame(0.0, $arr['expected_value']);
    }
}
