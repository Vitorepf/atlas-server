<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopAttemptLedger;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProviderRollbackPolicy;
use DomainException;
use Tests\TestCase;

final class AtlasLoopProviderRollbackPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.provider_defaults.execution_runtime', 'minimax_m3');
        config()->set('atlas.provider_defaults.brain_default', 'codex');
    }

    public function test_same_signal_vectors_produce_the_same_decision(): void
    {
        $policy = new AtlasLoopProviderRollbackPolicy;
        $seen = [];

        for ($i = 0; $i < 20; $i++) {
            $signals = [
                'circuit_state' => ['closed', 'open', 'tripped'][$i % 3],
                'triangulator_verdict' => ['agree', 'dissent', 'split', 'insufficient_witnesses'][$i % 4],
                'last_stable_provider' => ['minimax_m3', 'glm', 'claude'][$i % 3],
                'current_provider' => ['minimax_m3', 'codex'][$i % 2],
                'phase' => ['grind', 'implementation', 'architect', 'review'][$i % 4],
            ];

            $first = $policy->decisionFor($signals);
            $second = $policy->decisionFor(array_reverse($signals, true));

            $this->assertSame($first, $second);
            $this->assertContains($first, $policy->decisions());
            $seen[] = $first;
        }

        $this->assertContains(AtlasLoopProviderRollbackPolicy::KEEP_CURRENT_PROVIDER, $seen);
        $this->assertContains(AtlasLoopProviderRollbackPolicy::ROLLBACK_TO_PREVIOUS_STABLE, $seen);
        $this->assertContains(AtlasLoopProviderRollbackPolicy::ESCALATE_TO_HARD_PROVIDER, $seen);
    }

    public function test_grind_or_implementation_dissent_rolls_back_never_escalates(): void
    {
        $policy = new AtlasLoopProviderRollbackPolicy;

        foreach (['grind', 'implementation'] as $phase) {
            $receipt = $policy->decide([
                'phase' => $phase,
                'triangulator_verdict' => 'dissent',
                'current_provider' => 'minimax_m3',
                'last_stable_provider' => 'glm',
            ]);

            $this->assertSame(AtlasLoopProviderRollbackPolicy::ROLLBACK_TO_PREVIOUS_STABLE, $receipt['decision']);
            $this->assertNotSame(AtlasLoopProviderRollbackPolicy::ESCALATE_TO_HARD_PROVIDER, $receipt['decision']);
            $this->assertSame('glm', $receipt['routing_intent']['provider']);
        }
    }

    public function test_architect_phase_dissent_uses_hard_provider_from_brain_default_config(): void
    {
        $policy = new AtlasLoopProviderRollbackPolicy;

        $receipt = $policy->decide([
            'phase' => 'architect',
            'triangulator_verdict' => 'dissent',
            'current_provider' => 'minimax_m3',
            'last_stable_provider' => 'minimax_m3',
        ]);

        $this->assertSame(AtlasLoopProviderRollbackPolicy::ESCALATE_TO_HARD_PROVIDER, $receipt['decision']);
        $this->assertSame('codex', $receipt['routing_intent']['provider']);
        $this->assertSame('gpt-5.5', $receipt['routing_intent']['model']);
    }

    public function test_hard_provider_follows_brain_default_config_value(): void
    {
        config()->set('atlas.provider_defaults.brain_default', 'codex-pro');
        $policy = new AtlasLoopProviderRollbackPolicy;

        $receipt = $policy->decide([
            'phase' => 'architect',
            'triangulator_verdict' => 'dissent',
            'current_provider' => 'minimax_m3',
            'last_stable_provider' => 'minimax_m3',
        ]);

        $this->assertSame('codex-pro', $receipt['routing_intent']['provider']);
    }

    public function test_default_implementation_provider_follows_execution_runtime_config(): void
    {
        config()->set('atlas.provider_defaults.execution_runtime', 'glm-runtime');
        $policy = new AtlasLoopProviderRollbackPolicy;

        // No current_provider in signals → fallback to default implementation provider.
        $receipt = $policy->decide([
            'circuit_state' => 'closed',
            'triangulator_verdict' => 'agree',
            'phase' => 'implementation',
        ]);

        $this->assertSame(AtlasLoopProviderRollbackPolicy::KEEP_CURRENT_PROVIDER, $receipt['decision']);
        $this->assertSame('glm-runtime', $receipt['routing_intent']['provider']);
    }

    public function test_missing_execution_runtime_config_fails_closed(): void
    {
        config()->set('atlas.provider_defaults.execution_runtime', null);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('atlas_provider_defaults_execution_runtime_missing');
        new AtlasLoopProviderRollbackPolicy;
    }

    public function test_missing_brain_default_config_fails_closed(): void
    {
        config()->set('atlas.provider_defaults.brain_default', null);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('atlas_provider_defaults_brain_default_missing');
        new AtlasLoopProviderRollbackPolicy;
    }

    public function test_circuit_breaker_trip_records_immutable_receipt_in_attempt_ledger(): void
    {
        $ledger = new AtlasLoopAttemptLedger;
        $policy = new AtlasLoopProviderRollbackPolicy(static fn (): string => '2026-06-24T16:40:00+00:00');

        $receipt = $policy->decide([
            'circuit_state' => 'open',
            'triangulator_verdict' => 'agree',
            'current_provider' => 'codex',
            'last_stable_provider' => 'minimax_m3',
            'last_stable_model' => 'MiniMax-M3',
        ], $ledger);

        $this->assertSame(AtlasLoopProviderRollbackPolicy::ROLLBACK_TO_PREVIOUS_STABLE, $receipt['decision']);
        $this->assertSame(AtlasLoopProviderRollbackPolicy::POLICY_VERSION, $receipt['policy_version']);
        $this->assertSame(64, strlen($receipt['input_signals_hash']));
        $this->assertSame('2026-06-24T16:40:00+00:00', $receipt['timestamp']);

        $attempts = $ledger->attempts();
        $this->assertCount(1, $attempts);
        $this->assertSame('provider_rollback_policy', $attempts[0]['strategy']);
        $this->assertTrue($attempts[0]['passed'], 'audit receipts must not become failed-approach guidance');
        $this->assertSame(AtlasLoopProviderRollbackPolicy::ROLLBACK_TO_PREVIOUS_STABLE, $attempts[0]['reason']);

        $ledgerReceipt = json_decode($attempts[0]['signature'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($receipt, $ledgerReceipt);
        $this->assertSame(64, strlen((string) $ledgerReceipt['receipt_hash']));
        $this->assertSame('minimax_m3', $ledgerReceipt['routing_intent']['provider']);
    }

    public function test_no_rollback_signal_keeps_current_provider(): void
    {
        $receipt = (new AtlasLoopProviderRollbackPolicy)->decide([
            'circuit_state' => 'closed',
            'triangulator_verdict' => 'agree',
            'current_provider' => 'minimax_m3',
            'current_model' => 'MiniMax-M3',
            'phase' => 'implementation',
        ]);

        $this->assertSame(AtlasLoopProviderRollbackPolicy::KEEP_CURRENT_PROVIDER, $receipt['decision']);
        $this->assertSame('minimax_m3', $receipt['routing_intent']['provider']);
        $this->assertSame('MiniMax-M3', $receipt['routing_intent']['model']);
    }
}
