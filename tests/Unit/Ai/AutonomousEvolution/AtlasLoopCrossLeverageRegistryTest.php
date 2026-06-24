<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopCrossLeverageRegistry;
use Tests\TestCase;

/**
 * Proves AtlasLoopCrossLeverageRegistry is a flag-gated, read-only manifest of cross-leverage primitives: OFF
 * ⇒ empty (byte-identical no-op); ON ⇒ the named primitives with real, existing consumer paths under the Loop
 * area; every declared path resolves (no drift to dead symbols); zero side effects (runs offline + deterministic).
 */
final class AtlasLoopCrossLeverageRegistryTest extends TestCase
{
    private function registry(): AtlasLoopCrossLeverageRegistry
    {
        return new AtlasLoopCrossLeverageRegistry;
    }

    public function test_flag_off_returns_empty_manifest(): void
    {
        config(['atlas.loop.cross_leverage_registry_enabled' => false]);

        $this->assertSame([], $this->registry()->primitives(), 'flag OFF ⇒ byte-identical empty no-op');
    }

    public function test_flag_on_lists_the_named_primitives_with_consumer_paths(): void
    {
        config(['atlas.loop.cross_leverage_registry_enabled' => true]);

        $primitives = $this->registry()->primitives();
        $this->assertNotEmpty($primitives);

        $ids = array_column($primitives, 'primitive_id');
        $this->assertContains('atlas_loop_leverage_selector', $ids);
        $this->assertContains('atlas_loop_cross_type_leverage_selector', $ids);

        foreach ($primitives as $p) {
            $this->assertArrayHasKey('file_path', $p);
            $this->assertArrayHasKey('config_flag', $p);
            $this->assertContains($p['status'], ['declared', 'intended', 'wired', 'armed']);
            $this->assertNotEmpty($p['intended_consumer_paths'], 'each primitive names the consumers it lights');
            foreach ($p['intended_consumer_paths'] as $consumer) {
                $this->assertStringStartsWith('app/Services/Ai/AutonomousEvolution/', $consumer, 'consumers live in the Loop area');
            }
        }
    }

    public function test_every_declared_path_resolves_on_disk(): void
    {
        config(['atlas.loop.cross_leverage_registry_enabled' => true]);

        foreach ($this->registry()->primitives() as $p) {
            $this->assertFileExists(base_path($p['file_path']), "primitive file must exist: {$p['file_path']}");
            foreach ($p['intended_consumer_paths'] as $consumer) {
                $this->assertFileExists(base_path($consumer), "consumer file must exist: {$consumer}");
            }
        }
    }

    public function test_is_read_only_and_deterministic(): void
    {
        config(['atlas.loop.cross_leverage_registry_enabled' => true]);

        // Runs offline (no DB connection touched) and is a pure manifest — two calls are byte-identical.
        $run1 = $this->registry()->primitives();
        $run2 = $this->registry()->primitives();
        $this->assertSame(json_encode($run1), json_encode($run2));
    }
}
