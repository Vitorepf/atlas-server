<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneCostEventNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Proves AgentControlPlaneCostEventNormalizer rejects a cost event whose
 * amount_minor is present but non-numeric ('abc', true, []) with a
 * 'malformed_amount' violation (blocking the batch), while an absent/null
 * amount_minor still normalizes to 0 with no violation.
 */
final class AgentControlPlaneCostEventNormalizerTest extends TestCase
{
    private AgentControlPlaneCostEventNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new AgentControlPlaneCostEventNormalizer;
    }

    private function validEvent(array $overrides = []): array
    {
        return array_merge([
            'idempotency_key' => 'evt-1',
            'task_packet_id' => 'pkt-1',
            'run_id' => 'run-1',
            'agent_id' => 'muscle-1',
            'amount_minor' => 100,
            'currency' => 'USD',
        ], $overrides);
    }

    public function test_valid_numeric_amount_is_normalized(): void
    {
        $result = $this->normalizer->normalize([$this->validEvent()]);

        $this->assertSame('normalized', $result['status']);
        $this->assertSame([], $result['violations']);
        $this->assertSame(100, $result['normalized_cost_events'][0]['amount_minor']);
    }

    public function test_absent_amount_minor_normalizes_to_zero(): void
    {
        $event = $this->validEvent();
        unset($event['amount_minor']);
        $result = $this->normalizer->normalize([$event]);

        $this->assertSame('normalized', $result['status']);
        $this->assertSame([], $result['violations']);
        $this->assertSame(0, $result['normalized_cost_events'][0]['amount_minor']);
    }

    public function test_null_amount_minor_normalizes_to_zero(): void
    {
        $result = $this->normalizer->normalize([$this->validEvent(['amount_minor' => null])]);

        $this->assertSame('normalized', $result['status']);
        $this->assertSame([], $result['violations']);
        $this->assertSame(0, $result['normalized_cost_events'][0]['amount_minor']);
    }

    public function test_malformed_string_amount_is_blocked(): void
    {
        $result = $this->normalizer->normalize([$this->validEvent(['amount_minor' => 'abc'])]);

        $this->assertSame('normalization_blocked', $result['status']);
        $this->assertCount(1, $result['violations']);
        $this->assertSame('malformed_amount', $result['violations'][0]['code']);
    }

    public function test_boolean_amount_is_blocked(): void
    {
        $result = $this->normalizer->normalize([$this->validEvent(['amount_minor' => true])]);

        $this->assertSame('normalization_blocked', $result['status']);
        $this->assertSame('malformed_amount', $result['violations'][0]['code']);
    }

    public function test_array_amount_is_blocked(): void
    {
        $result = $this->normalizer->normalize([$this->validEvent(['amount_minor' => [1, 2, 3]])]);

        $this->assertSame('normalization_blocked', $result['status']);
        $this->assertSame('malformed_amount', $result['violations'][0]['code']);
    }

    public function test_float_amount_is_normalized(): void
    {
        $result = $this->normalizer->normalize([$this->validEvent(['amount_minor' => 45.67])]);

        $this->assertSame('normalized', $result['status']);
        $this->assertSame([], $result['violations']);
        // Float is rounded to int.
        $this->assertSame(46, $result['normalized_cost_events'][0]['amount_minor']);
    }

    public function test_numeric_string_amount_is_normalized(): void
    {
        $result = $this->normalizer->normalize([$this->validEvent(['amount_minor' => '200'])]);

        $this->assertSame('normalized', $result['status']);
        $this->assertSame([], $result['violations']);
        $this->assertSame(200, $result['normalized_cost_events'][0]['amount_minor']);
    }
}
