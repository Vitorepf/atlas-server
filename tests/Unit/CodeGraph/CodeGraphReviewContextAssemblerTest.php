<?php

declare(strict_types=1);

namespace Tests\Unit\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphContextPackAssembler;
use App\Services\Engineering\CodeGraph\CodeGraphReviewContextAssembler;
use Tests\TestCase;

/**
 * AP-815 · I-3 — proves diff/PR → review-context: the changed nodes + their reverse-
 * reachability blast-radius (who depends on them) are assembled into a budgeted pack.
 */
final class CodeGraphReviewContextAssemblerTest extends TestCase
{
    private function svc(): CodeGraphReviewContextAssembler
    {
        return new CodeGraphReviewContextAssembler(new CodeGraphContextPackAssembler);
    }

    /** A depends on B and C; X depends on B. So changing B should blast to A and X (not C). */
    private function edges(): array
    {
        return [
            ['from_node_id' => 'sym:A', 'to_node_id' => 'sym:B', 'edge_type' => 'calls'],
            ['from_node_id' => 'sym:A', 'to_node_id' => 'sym:C', 'edge_type' => 'calls'],
            ['from_node_id' => 'sym:X', 'to_node_id' => 'sym:B', 'edge_type' => 'calls'],
        ];
    }

    private function meta(): array
    {
        return [
            'sym:A' => ['tokens' => 50, 'signature' => 'class A'],
            'sym:B' => ['tokens' => 50, 'signature' => 'class B'],
            'sym:C' => ['tokens' => 50, 'signature' => 'class C'],
            'sym:X' => ['tokens' => 50, 'signature' => 'class X'],
        ];
    }

    public function test_blast_radius_is_reverse_reachability(): void
    {
        $out = $this->svc()->assemble(['sym:B'], $this->edges(), $this->meta(), 4000);

        $this->assertSame(['sym:B'], $out['changed']);
        $this->assertContains('sym:A', $out['blast_radius'], 'A depends on B → in blast');
        $this->assertContains('sym:X', $out['blast_radius'], 'X depends on B → in blast');
        $this->assertNotContains('sym:C', $out['blast_radius'], 'C does NOT depend on B → not in blast');
        $this->assertNotContains('sym:B', $out['blast_radius'], 'the changed node itself is not its own blast');
    }

    public function test_pack_includes_changed_and_blast_within_budget(): void
    {
        $out = $this->svc()->assemble(['sym:B'], $this->edges(), $this->meta(), 4000);

        $ids = array_map(static fn (array $n): string => (string) ($n['id'] ?? ''), $out['pack']['included']);
        $this->assertContains('sym:B', $ids, 'the changed node is in the review pack');
        $this->assertContains('sym:A', $ids);
        $this->assertContains('sym:X', $ids);
        $this->assertSame(3, $out['stats']['included']);
        $this->assertLessThanOrEqual(4000, $out['pack']['estimated_tokens']);
    }

    public function test_tight_budget_truncates_to_changed_first(): void
    {
        // Budget for ~1 node: the changed node ranks first, so it survives truncation.
        $out = $this->svc()->assemble(['sym:B'], $this->edges(), $this->meta(), 60);

        $ids = array_map(static fn (array $n): string => (string) ($n['id'] ?? ''), $out['pack']['included']);
        $this->assertContains('sym:B', $ids, 'changed node is highest-ranked, kept under tight budget');
        $this->assertTrue($out['pack']['truncated']);
    }

    public function test_depth_zero_has_no_blast(): void
    {
        $out = $this->svc()->assemble(['sym:B'], $this->edges(), $this->meta(), 4000, ['blast_depth' => 0]);

        $this->assertSame([], $out['blast_radius']);
        $this->assertSame(1, $out['stats']['included'], 'only the changed node, no blast');
    }

    public function test_empty_diff_is_safe(): void
    {
        $out = $this->svc()->assemble([], $this->edges(), $this->meta(), 4000);

        $this->assertSame([], $out['changed']);
        $this->assertSame([], $out['blast_radius']);
        $this->assertSame(0, $out['stats']['included']);
    }
}
