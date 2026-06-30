<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Console\Commands\AtlasBrainQueuedTargetsCommand;
use PHPUnit\Framework\TestCase;

/**
 * BRAIN QUEUED-TARGETS — the session-as-brain (Mode B) reads this to avoid re-proposing already-queued work.
 * scopedTargets is the pure scope-keying seam: normalize + filter-to-scope-root + dedup + sort. If its keying
 * is wrong the brain either sees nothing (re-proposes everything) or everything (originates nothing).
 */
final class AtlasBrainQueuedTargetsCommandTest extends TestCase
{
    public function test_keeps_only_targets_under_a_scope_root(): void
    {
        $out = AtlasBrainQueuedTargetsCommand::scopedTargets(
            ['app/Services/Ai/AutonomousEvolution/Foo.php', 'app/Http/Controllers/Bar.php'],
            ['app/Services/Ai/AutonomousEvolution'],
        );
        self::assertSame(['app/Services/Ai/AutonomousEvolution/Foo.php'], $out);
    }

    public function test_empty_roots_keeps_all_targets(): void
    {
        $out = AtlasBrainQueuedTargetsCommand::scopedTargets(['app/A.php', 'app/B.php'], []);
        self::assertSame(['app/A.php', 'app/B.php'], $out);
    }

    public function test_normalizes_path_variants_on_both_sides_before_matching(): void
    {
        // leading slash / backslash / ./ / whitespace on targets AND a trailing-slash root must still match.
        $out = AtlasBrainQueuedTargetsCommand::scopedTargets(
            ['/app/X/Foo.php', 'app\\X\\Bar.php', ' ./app/X/Baz.php '],
            ['/app/X/'],
        );
        self::assertSame(['app/X/Bar.php', 'app/X/Baz.php', 'app/X/Foo.php'], $out);
    }

    public function test_dedups_and_sorts(): void
    {
        $out = AtlasBrainQueuedTargetsCommand::scopedTargets(
            ['app/Z.php', 'app/A.php', '/app/Z.php', 'app/A.php'],
            [],
        );
        self::assertSame(['app/A.php', 'app/Z.php'], $out);
    }

    public function test_collision_is_a_target_with_more_than_one_distinct_packet(): void
    {
        $collisions = AtlasBrainQueuedTargetsCommand::collisionsIn([
            'app/Inspector.php' => ['pkt-a', 'pkt-b'], // two live packets editing the same file → collision
            'app/Solo.php' => ['pkt-c'],               // one packet → fine
        ], []);
        self::assertSame(['app/Inspector.php' => ['pkt-a', 'pkt-b']], $collisions);
    }

    public function test_same_packet_listed_twice_is_not_a_collision(): void
    {
        // one packet whose allowed_files repeats a path must NOT register as a collision (distinct ids only).
        $collisions = AtlasBrainQueuedTargetsCommand::collisionsIn(['app/X.php' => ['pkt-a', 'pkt-a']], []);
        self::assertSame([], $collisions);
    }

    public function test_command_files_are_in_scope_even_when_service_roots_exclude_console_commands(): void
    {
        // Regression: scope roots = service dirs only, but AtlasTask*/AtlasBrain* commands and their tests
        // are part of the autonomous surface — effectiveRoots() appends SCOPE_SUPPORT_ROOTS so they stay visible.
        $serviceRoots = ['app/Services/Ai/AutonomousEvolution', 'app/Services/Ai/SelfConstruction'];
        $effectiveRoots = AtlasBrainQueuedTargetsCommand::effectiveRoots($serviceRoots);

        $targets = AtlasBrainQueuedTargetsCommand::scopedTargets([
            'app/Console/Commands/AtlasTaskMaestroMultiProviderCommand.php', // CLI surface — must be kept
            'tests/Feature/Ai/AtlasTaskMaestroMultiProviderCommandTest.php', // its test — must be kept
            'app/Http/Controllers/SomeController.php',                        // unrelated — must be dropped
        ], $effectiveRoots);

        self::assertContains('app/Console/Commands/AtlasTaskMaestroMultiProviderCommand.php', $targets);
        self::assertContains('tests/Feature/Ai/AtlasTaskMaestroMultiProviderCommandTest.php', $targets);
        self::assertNotContains('app/Http/Controllers/SomeController.php', $targets);
    }

    public function test_effective_roots_is_identity_when_roots_empty(): void
    {
        // empty roots = "all targets" mode — support roots must NOT be injected (they'd constrain the all-targets case)
        self::assertSame([], AtlasBrainQueuedTargetsCommand::effectiveRoots([]));
    }

    public function test_collision_respects_scope_roots(): void
    {
        $collisions = AtlasBrainQueuedTargetsCommand::collisionsIn([
            'app/InScope/Foo.php' => ['pkt-a', 'pkt-b'],
            'app/OutOfScope/Bar.php' => ['pkt-c', 'pkt-d'],
        ], ['app/InScope']);
        self::assertSame(['app/InScope/Foo.php' => ['pkt-a', 'pkt-b']], $collisions);
    }
}
