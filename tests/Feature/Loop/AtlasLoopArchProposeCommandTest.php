<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the V4 self-architecture proposer is live at the operator surface with a deterministic architect: it
 * proposes the first non-forbidden topology node; it refuses (proposed=false) when the topology is empty or
 * every node is forbidden.
 */
final class AtlasLoopArchProposeCommandTest extends TestCase
{
    private function propose(array $topology, array $forbidden): array
    {
        $exit = Artisan::call('atlas:loop:arch-propose', [
            '--topology' => (string) json_encode($topology),
            '--forbidden' => (string) json_encode($forbidden),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_proposes_first_non_forbidden_node(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->propose(
            ['app/Services/Ai/Marketing/Foo.php', 'app/Services/Ai/Forge/Bar.php'],
            ['app/Services/Ai/Forge/Bar.php'], // Bar forbidden ⇒ Foo proposed
        );

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.arch_propose.v1', $d['schema']);
        $this->assertTrue($d['proposed'], (string) json_encode($d));
        $this->assertSame('app/Services/Ai/Marketing/Foo.php', $d['target_path']);
        $this->assertSame('add_seam', $d['kind']);
        $this->assertNull($d['refuse_reason']);
    }

    public function test_fully_forbidden_topology_refuses(): void
    {
        ['d' => $d] = $this->propose(
            ['app/Services/Ai/Forge/Bar.php'],
            ['app/Services/Ai/Forge/Bar.php'],
        );

        $this->assertFalse($d['proposed'], (string) json_encode($d));
        $this->assertNotNull($d['refuse_reason']);
    }

    public function test_empty_topology_refuses(): void
    {
        ['d' => $d] = $this->propose([], []);

        $this->assertFalse($d['proposed']);
        $this->assertNotNull($d['refuse_reason']);
    }

    public function test_missing_topology_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:arch-propose', ['--forbidden' => '[]', '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
