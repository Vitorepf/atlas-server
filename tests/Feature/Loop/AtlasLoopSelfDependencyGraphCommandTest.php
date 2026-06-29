<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the self-dependency-graph reporter is live at the operator surface: the command emits the
 * deterministic structural facts (schema_version) over the real AutonomousEvolution tree with a non-empty
 * nodes list.
 */
final class AtlasLoopSelfDependencyGraphCommandTest extends TestCase
{
    public function test_command_emits_self_dependency_graph_facts_with_nodes(): void
    {
        $exit = Artisan::call('atlas:loop:self-dependency-graph', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($decoded);
        $this->assertSame('atlas.loop.self_dependency_graph_facts.v1', $decoded['schema_version']);
        $this->assertIsArray($decoded['nodes']);
        $this->assertNotEmpty($decoded['nodes']);
    }
}
