<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the V4 meta-objective originator is live at the operator surface with a deterministic writer: outcomes
 * carrying a numeric origination fact (within the reachability ceiling) originate a grounded objective; outcomes
 * with no numeric fact (ungrounded) do not originate.
 */
final class AtlasLoopMetaObjectiveOriginateCommandTest extends TestCase
{
    private function originate(array $origination, array $delivery, array $capability): array
    {
        $exit = Artisan::call('atlas:loop:meta-objective-originate', [
            '--origination' => (string) json_encode($origination),
            '--delivery' => (string) json_encode($delivery),
            '--capability' => (string) json_encode($capability),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_grounded_numeric_fact_originates(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->originate(
            ['throughput' => 10],   // numeric origination fact ⇒ groundable
            [],
            ['slope' => 5],         // reachability ceiling = 2 * 5 = 10 (>= 0.5 target_delta)
        );

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.v4_meta_objective_originate.v1', $d['schema']);
        $this->assertTrue($d['originated'], (string) json_encode($d));
        $this->assertSame('origination', $d['target_metric']);
        $this->assertContains('origination.throughput=10', $d['cited_facts']);
        $this->assertEqualsWithDelta(0.5, $d['target_delta'], 1e-9);
    }

    public function test_ungrounded_outcomes_do_not_originate(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->originate(
            ['note' => 'no numbers here'], // no numeric fact
            [],
            ['slope' => 5],
        );

        $this->assertSame(0, $exit);
        $this->assertFalse($d['originated'], (string) json_encode($d));
    }

    public function test_missing_origination_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:meta-objective-originate', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
