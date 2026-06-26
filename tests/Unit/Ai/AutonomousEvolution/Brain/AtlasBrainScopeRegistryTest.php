<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeRegistry;
use Tests\TestCase;

/**
 * The scope authority: resolves a slug into the concrete reach (roots + docs + meta_harness). The brain's reach
 * is DATA (config), and fail-closed — an unconfigured scope never auto-arms meta_harness.
 */
final class AtlasBrainScopeRegistryTest extends TestCase
{
    public function test_resolves_the_autonomous_scope_to_both_halves_with_meta_on(): void
    {
        $def = (new AtlasBrainScopeRegistry)->resolve('autonomous');

        self::assertSame('autonomous', $def['slug']);
        self::assertContains('app/Services/Ai/AutonomousEvolution', $def['roots'], 'the engine half is in scope');
        self::assertContains('app/Services/Ai/SelfConstruction', $def['roots'], 'the muscle half is in scope');
        self::assertTrue($def['meta_harness'], 'recursive-total: the brain may evolve its own engine');
    }

    public function test_unknown_scope_falls_back_to_the_configured_default(): void
    {
        config()->set('atlas.brain.default_scope', 'autonomous');

        $def = (new AtlasBrainScopeRegistry)->resolve('does-not-exist');

        self::assertSame('autonomous', $def['slug'], 'an unknown slug resolves to the default scope');
    }

    public function test_empty_config_fails_closed_to_the_loop_substrate_with_meta_off(): void
    {
        config()->set('atlas.brain.scopes', []);
        config()->set('atlas.brain.default_scope', 'whatever');

        $def = (new AtlasBrainScopeRegistry)->resolve('');

        self::assertSame(['app/Services/Ai/AutonomousEvolution'], $def['roots'], 'fallback root is the loop substrate');
        self::assertFalse($def['meta_harness'], 'fail-closed: no config ⇒ never auto-arm meta_harness');
    }

    public function test_meta_harness_comes_from_the_named_scope(): void
    {
        config()->set('atlas.brain.scopes.locked', ['roots' => ['app/X'], 'meta_harness' => false]);
        config()->set('atlas.brain.scopes.armed', ['roots' => ['app/Y'], 'meta_harness' => true]);

        self::assertFalse((new AtlasBrainScopeRegistry)->resolve('locked')['meta_harness']);
        self::assertTrue((new AtlasBrainScopeRegistry)->resolve('armed')['meta_harness']);
    }

    public function test_default_scope_reads_config(): void
    {
        config()->set('atlas.brain.default_scope', 'cortex');

        self::assertSame('cortex', (new AtlasBrainScopeRegistry)->defaultScope());
    }
}
