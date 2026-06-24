<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\BehaviorDelta;

use App\Services\Ai\AutonomousEvolution\BehaviorDelta\AtlasLoopBehaviorDeltaComputer;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasLoopBehaviorDeltaComputer diffs two behavior snapshots into TYPED, deterministic FACTS, and that
 * net_behavior_delta is a plain COUNT of real structural changes (the honest replacement for the fan-in proxy)
 * — zero for identical snapshots.
 */
final class AtlasLoopBehaviorDeltaComputerTest extends TestCase
{
    private function computer(): AtlasLoopBehaviorDeltaComputer
    {
        return new AtlasLoopBehaviorDeltaComputer;
    }

    /** @param list<array<string,mixed>> $symbols */
    private function snap(array $symbols): array
    {
        return ['schema' => 'atlas.loop.behavior_delta_snapshot.v1', 'captured_at' => '2026-01-01T00:00:00Z', 'symbols' => $symbols, 'api_surface_hash' => 'h'];
    }

    /** @param list<string> $callers */
    private function sym(string $fqcn, string $sig, array $callers = []): array
    {
        return ['fqcn' => $fqcn, 'public_api_signature_hash' => $sig, 'caller_fqcns' => $callers];
    }

    public function test_compute_is_deterministic(): void
    {
        $before = $this->snap([$this->sym('Demo\\Foo', 'h1', ['Demo\\Bar'])]);
        $after = $this->snap([$this->sym('Demo\\Foo', 'h2', ['Demo\\Bar', 'Demo\\Baz'])]);

        $run1 = $this->computer()->compute($before, $after);
        $run2 = $this->computer()->compute($before, $after);

        $this->assertSame(json_encode($run1), json_encode($run2));
    }

    public function test_renamed_public_method_shows_signature_change(): void
    {
        $before = $this->snap([$this->sym('Demo\\Foo', 'sig_before')]);
        $after = $this->snap([$this->sym('Demo\\Foo', 'sig_after')]);

        $delta = $this->computer()->compute($before, $after);

        $this->assertSame([], $delta['symbols_added']);
        $this->assertSame([], $delta['symbols_removed']);
        $this->assertSame(
            [['fqcn' => 'Demo\\Foo', 'before_hash' => 'sig_before', 'after_hash' => 'sig_after']],
            $delta['api_signature_changed'],
        );
        $this->assertSame(1, $delta['net_behavior_delta']);
    }

    public function test_added_symbol_increments_delta_and_identical_is_zero(): void
    {
        $before = $this->snap([$this->sym('Demo\\Foo', 'h1')]);
        $after = $this->snap([$this->sym('Demo\\Foo', 'h1'), $this->sym('Demo\\Bar', 'h9')]);

        $delta = $this->computer()->compute($before, $after);
        $this->assertSame(['Demo\\Bar'], $delta['symbols_added']);
        $this->assertSame([], $delta['symbols_removed']);
        $this->assertGreaterThanOrEqual(1, $delta['net_behavior_delta']);

        // Identical snapshots ⇒ a pure zero delta.
        $zero = $this->computer()->compute($after, $after);
        $this->assertSame(0, $zero['net_behavior_delta']);
        $this->assertSame([], $zero['symbols_added']);
        $this->assertSame([], $zero['symbols_removed']);
        $this->assertSame([], $zero['api_signature_changed']);
        $this->assertSame(0, $zero['caller_edges_added']);
        $this->assertSame(0, $zero['caller_edges_removed']);
    }

    public function test_caller_edge_added_and_removed_are_counted(): void
    {
        $before = $this->snap([$this->sym('Demo\\Foo', 'h1', ['Demo\\A'])]);
        $after = $this->snap([$this->sym('Demo\\Foo', 'h1', ['Demo\\B'])]); // A removed, B added

        $delta = $this->computer()->compute($before, $after);

        $this->assertSame(1, $delta['caller_edges_added'], 'Foo->B is a new edge');
        $this->assertSame(1, $delta['caller_edges_removed'], 'Foo->A edge is gone');
        $this->assertSame([], $delta['api_signature_changed'], 'the signature itself did not change');
        $this->assertSame(2, $delta['net_behavior_delta'], '1 edge added + 1 edge removed');
    }
}
