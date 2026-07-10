<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Adapters\External\AbstractExternalSuiteAdapter;
use Tests\TestCase;

/** Fail-closed: adapters must never leave present=true with empty usage. */
class ExternalFieldPresenceReconcileTest extends TestCase
{
    public function test_zero_tokens_force_present_false_even_when_adapter_lied(): void
    {
        $adapter = $this->adapter();
        $out = $adapter->expose([
            'tokens_in' => 0,
            'tokens_out' => 0,
            'wall_ms' => 0,
            'cost_usd' => 0.0,
            'field_presence' => [
                'tokens_in' => ['present' => true, 'reason' => null],
                'tokens_out' => ['present' => true, 'reason' => null],
                'wall_ms' => ['present' => true, 'reason' => null],
                'cost_usd' => ['present' => true, 'reason' => null],
            ],
        ], 'openai');

        $this->assertFalse($out['field_presence']['tokens_in']['present']);
        $this->assertFalse($out['field_presence']['tokens_out']['present']);
        $this->assertFalse($out['field_presence']['wall_ms']['present']);
        $this->assertFalse($out['field_presence']['cost_usd']['present']);
        $this->assertSame('usage_not_reported', $out['field_presence']['tokens_in']['reason']);
    }

    public function test_hermes_with_usage_and_zero_cost_gets_verboo_basis(): void
    {
        $adapter = $this->adapter();
        $out = $adapter->expose([
            'tokens_in' => 12,
            'tokens_out' => 4,
            'wall_ms' => 1500,
            'cost_usd' => 0.0,
            'field_presence' => [
                'tokens_in' => ['present' => false, 'reason' => 'undeclared'],
                'tokens_out' => ['present' => false, 'reason' => 'undeclared'],
                'wall_ms' => ['present' => false, 'reason' => 'undeclared'],
                'cost_usd' => ['present' => false, 'reason' => 'undeclared'],
            ],
        ], 'hermes');

        $this->assertTrue($out['field_presence']['tokens_in']['present']);
        $this->assertTrue($out['field_presence']['tokens_out']['present']);
        $this->assertTrue($out['field_presence']['wall_ms']['present']);
        $this->assertTrue($out['field_presence']['cost_usd']['present']);
        $this->assertSame('verboo_subscription_marginal', $out['field_presence']['cost_usd']['reason']);
    }

    public function test_explicit_missing_usage_reason_is_preserved(): void
    {
        $adapter = $this->adapter();
        $out = $adapter->expose([
            'tokens_in' => 0,
            'tokens_out' => 0,
            'wall_ms' => 900,
            'cost_usd' => 0.0,
            'field_presence' => [
                'tokens_in' => ['present' => false, 'reason' => 'tb_agent_usage_not_reported'],
                'tokens_out' => ['present' => false, 'reason' => 'tb_agent_usage_not_reported'],
                'wall_ms' => ['present' => true, 'reason' => null],
                'cost_usd' => ['present' => false, 'reason' => 'tb_native_no_cost_field'],
            ],
        ], 'hermes');

        $this->assertFalse($out['field_presence']['tokens_in']['present']);
        $this->assertSame('tb_agent_usage_not_reported', $out['field_presence']['tokens_in']['reason']);
        $this->assertTrue($out['field_presence']['wall_ms']['present']);
        $this->assertFalse($out['field_presence']['cost_usd']['present']);
        $this->assertSame('tb_native_no_cost_field', $out['field_presence']['cost_usd']['reason']);
    }

    private function adapter(): object
    {
        return new class extends AbstractExternalSuiteAdapter
        {
            public function suiteId(): string
            {
                return 'tau2_bench';
            }

            protected function commandTemplate(): string
            {
                return 'true';
            }

            protected function mapResults(array $native): array
            {
                return [];
            }

            /**
             * @param  array<string, mixed>  $merged
             * @return array<string, mixed>
             */
            public function expose(array $merged, string $provider): array
            {
                return $this->reconcileFieldPresence($merged, $provider);
            }
        };
    }
}
