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

    public function test_collision_respects_scope_roots(): void
    {
        $collisions = AtlasBrainQueuedTargetsCommand::collisionsIn([
            'app/InScope/Foo.php' => ['pkt-a', 'pkt-b'],
            'app/OutOfScope/Bar.php' => ['pkt-c', 'pkt-d'],
        ], ['app/InScope']);
        self::assertSame(['app/InScope/Foo.php' => ['pkt-a', 'pkt-b']], $collisions);
    }
}
