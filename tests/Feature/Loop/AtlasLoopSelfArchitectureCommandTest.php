<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the self-architecture scanner is live at the operator surface: the command emits the deterministic
 * facts-only structure (schema_version) over the real AutonomousEvolution tree with a non-zero class_count.
 */
final class AtlasLoopSelfArchitectureCommandTest extends TestCase
{
    public function test_command_emits_self_architecture_facts_with_classes(): void
    {
        $exit = Artisan::call('atlas:loop:self-architecture', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($decoded);
        $this->assertSame('atlas.loop.self_architecture_facts.v1', $decoded['schema_version']);
        $this->assertGreaterThan(0, $decoded['class_count']);
    }
}
