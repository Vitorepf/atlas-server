<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the behavior-delta computer is live at the operator surface: it diffs two snapshots into a typed delta
 * (added/removed symbols, signature changes, caller-edge churn) and a net change count; identical snapshots → 0.
 */
final class AtlasLoopBehaviorDeltaComputeCommandTest extends TestCase
{
    private string $input = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-bdelta-'.bin2hex(random_bytes(5)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    private function snapshot(array $symbols): array
    {
        return ['schema' => 'atlas.loop.behavior_delta_snapshot.v1', 'symbols' => $symbols];
    }

    private function compute(array $before, array $after): array
    {
        file_put_contents($this->input, (string) json_encode(['before' => $before, 'after' => $after]));
        $exit = Artisan::call('atlas:loop:behavior-delta-compute', ['--input' => $this->input, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_typed_delta_counts_real_structural_changes(): void
    {
        $before = $this->snapshot([
            ['fqcn' => 'A', 'public_api_signature_hash' => 'h1', 'caller_fqcns' => ['X']],
            ['fqcn' => 'B', 'public_api_signature_hash' => 'h2', 'caller_fqcns' => []],
        ]);
        $after = $this->snapshot([
            ['fqcn' => 'A', 'public_api_signature_hash' => 'h1-CHANGED', 'caller_fqcns' => ['X']],
            ['fqcn' => 'C', 'public_api_signature_hash' => 'h3', 'caller_fqcns' => ['Y']],
        ]);

        ['exit' => $exit, 'd' => $d] = $this->compute($before, $after);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.behavior_delta.v1', $d['schema']);
        $this->assertSame(['C'], $d['symbols_added']);
        $this->assertSame(['B'], $d['symbols_removed']);
        $this->assertSame('A', $d['api_signature_changed'][0]['fqcn']);
        $this->assertSame(1, $d['caller_edges_added']);   // C->Y
        $this->assertSame(0, $d['caller_edges_removed']); // A->X kept
        $this->assertSame(4, $d['net_behavior_delta'], (string) json_encode($d)); // 1 add +1 remove +1 sig +1 edge
    }

    public function test_identical_snapshots_are_zero_delta(): void
    {
        $snap = $this->snapshot([
            ['fqcn' => 'A', 'public_api_signature_hash' => 'h1', 'caller_fqcns' => ['X']],
        ]);

        ['exit' => $exit, 'd' => $d] = $this->compute($snap, $snap);

        $this->assertSame(0, $exit);
        $this->assertSame(0, $d['net_behavior_delta']);
        $this->assertSame([], $d['symbols_added']);
        $this->assertSame([], $d['api_signature_changed']);
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:behavior-delta-compute', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }

    public function test_missing_before_after_is_usage_error(): void
    {
        file_put_contents($this->input, (string) json_encode(['something' => 'else']));
        $exit = Artisan::call('atlas:loop:behavior-delta-compute', ['--input' => $this->input, '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
