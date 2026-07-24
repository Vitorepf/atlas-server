<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\EngineeringModeExecutionOrderFactory;
use PHPUnit\Framework\TestCase;

final class EngineeringModeExecutionOrderFactoryTest extends TestCase
{
    public function test_factory_keeps_shared_contract_and_allows_explicit_mode_fields_to_differ(): void
    {
        $factory = new EngineeringModeExecutionOrderFactory;
        $base = [
            'run_id' => 'run-1', 'delivery_id' => 'delivery-1', 'run_hash' => str_repeat('a', 64),
            'risk_class' => 'R3', 'complexity_band' => 'C2', 'product_intent_verdict_hash' => str_repeat('b', 64),
            'spec_hash' => str_repeat('c', 64), 'world_model_snapshot_hash' => str_repeat('d', 64),
            'workspace' => '/tmp/atlas', 'base_commit' => str_repeat('e', 40),
            'allowed_scope' => ['app/Example.php'], 'forbidden_scope' => ['.env'],
            'authority_envelope' => ['authority_hash' => str_repeat('f', 64)],
            'decision_event_id' => 'authoritative-decision-event-1',
        ];
        $orders = [];
        foreach ([
            ['mode' => 'dev', 'duration_regime' => 'interactive', 'work_topology' => 'single'],
            ['mode' => 'forge', 'duration_regime' => 'obra', 'work_topology' => 'DAG'],
            ['mode' => 'autonomos', 'duration_regime' => 'continuous', 'work_topology' => 'workcell'],
        ] as $variant) {
            $orders[$variant['mode']] = $factory->make($base + $variant);
        }
        self::assertSame($orders['dev']->riskClass, $orders['forge']->riskClass);
        self::assertSame($orders['dev']->specHash, $orders['autonomos']->specHash);
        self::assertSame($orders['dev']->roleRoster, $orders['forge']->roleRoster);
        self::assertSame(array_keys($orders['forge']->evidencePolicy['role_disposition_event_ids']), array_keys($orders['autonomos']->evidencePolicy['role_disposition_event_ids']));
        self::assertSame('interactive', $orders['dev']->durationRegime);
        self::assertSame('DAG', $orders['forge']->workTopology);
        self::assertSame('continuous', $orders['autonomos']->durationRegime);
    }

    public function test_factory_rejects_unknown_mode_before_an_order_can_be_created(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('engineering_order_mode_invalid');
        (new EngineeringModeExecutionOrderFactory)->make(['mode' => 'legacy']);
    }

    public function test_factory_refuses_to_synthesize_decision_event_id_fallback(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('engineering_order_decision_event_id_required');
        (new EngineeringModeExecutionOrderFactory)->make([
            'mode' => 'dev',
            'risk_class' => 'R1',
            'work_topology' => 'single',
            'run_hash' => str_repeat('a', 64),
            'run_id' => 'run',
            'delivery_id' => 'delivery',
            'base_commit' => str_repeat('b', 40),
            'allowed_scope' => ['app/X.php'],
            'forbidden_scope' => ['.env'],
            'authority_envelope' => ['authority_hash' => str_repeat('c', 64)],
            // intentionally omit decision_event_id
        ]);
    }
}
