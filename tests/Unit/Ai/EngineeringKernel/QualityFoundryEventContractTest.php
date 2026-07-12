<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\Quality\QualityFoundryEventContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class QualityFoundryEventContractTest extends TestCase
{
    private function event(string $name, string $key, array $overrides = []): array
    {
        return array_merge([
            'schema' => QualityFoundryEventContract::SCHEMA,
            'event_name' => $name,
            'run_id' => 'run-1',
            'delivery_id' => 'delivery-1',
            'occurred_at' => '2026-07-12T00:00:00Z',
            'provenance' => 'quality-court',
            'idempotency_key' => $key,
            'correlated_hashes' => ['order' => hash('sha256', 'order')],
        ], $overrides);
    }

    public function test_validates_required_event_contract_and_replays_deterministically(): void
    {
        $events = [
            $this->event('execution.started', 'e1'),
            $this->event('acceptance.adjudicated', 'e2'),
            $this->event('outcome.observed', 'e3'),
        ];
        $contract = new QualityFoundryEventContract;

        $a = $contract->replay($events);
        $b = $contract->replay($events);

        $this->assertSame($a, $b);
        $this->assertSame(3, $a['event_count']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['replay_hash']);
    }

    public function test_same_idempotency_key_is_replay_safe_but_divergent_content_blocks(): void
    {
        $contract = new QualityFoundryEventContract;
        $event = $this->event('execution.started', 'same');
        $this->assertSame(1, $contract->replay([$event, $event])['event_count']);

        $this->expectException(InvalidArgumentException::class);
        $contract->replay([$event, $this->event('execution.started', 'same', ['run_id' => 'other'])]);
    }

    public function test_out_of_order_or_invalid_events_fail_closed_and_legacy_defaults_stay_honest(): void
    {
        $contract = new QualityFoundryEventContract;
        try {
            $contract->replay([$this->event('outcome.observed', 'o'), $this->event('execution.started', 'e')]);
            $this->fail('out-of-order event should block');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('quality_foundry_event_order_invalid', $exception->getMessage());
        }
        $this->assertSame('legacy_unproven', $contract->legacyStatus([]));
        $this->assertSame('unknown', $contract->observationStatus(null));
    }
}
