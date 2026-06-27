<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopOriginationPipeline;
use PHPUnit\Framework\TestCase;

/**
 * QUEUE-AWARE ORIGINATION — the brain considers CODE *and* the live TASK QUEUE so it stops re-proposing
 * targets that already have a live task packet. queueAwareDemote is the pure, keying-load-bearing seam:
 * if its path normalization is wrong the dedup silently no-ops (the exact "burning tokens on the wrong
 * thing" failure). These freeze: keying collapse, demote-not-exclude, byte-identical OFF, no dead-stall.
 */
final class AtlasLoopOriginationPipelineQueueDedupTest extends TestCase
{
    public function test_empty_queued_keys_returns_valid_unchanged_byte_identical_off(): void
    {
        $valid = [['wire A', 'app/a.php'], ['wire B', 'app/b.php']];
        self::assertSame($valid, AtlasLoopOriginationPipeline::queueAwareDemote($valid, []));
    }

    public function test_queued_target_is_demoted_below_fresh_keeping_order(): void
    {
        $valid = [['wire A', 'app/a.php'], ['wire B', 'app/b.php'], ['wire C', 'app/c.php']];
        // a + c already have live tasks; only b is fresh → b first, then a and c demoted in stable order.
        $out = AtlasLoopOriginationPipeline::queueAwareDemote($valid, ['app/a.php' => true, 'app/c.php' => true]);
        self::assertSame([['wire B', 'app/b.php'], ['wire A', 'app/a.php'], ['wire C', 'app/c.php']], $out);
    }

    public function test_keying_collapses_path_variants_to_the_canonical_queued_key(): void
    {
        // Keying is load-bearing: a candidate rel in ANY path shape (leading slash, backslash, ./ prefix,
        // surrounding whitespace) must still match a canonical queued key, or the dedup silently misses.
        foreach (['/app/x.php', 'app\\x.php', './app/x.php', '  app/x.php  ', 'app/x.php'] as $rel) {
            $out = AtlasLoopOriginationPipeline::queueAwareDemote(
                [['wire X', $rel], ['wire B', 'app/b.php']],
                ['app/x.php' => true],
            );
            // the variant matched → demoted to the back; the fresh 'b' rose to the front.
            self::assertSame('app/b.php', $out[0][1], "variant '{$rel}' should have been demoted (keying matched)");
            self::assertSame($rel, $out[1][1]);
        }
    }

    public function test_all_queued_still_returns_every_candidate_no_dead_stall(): void
    {
        $valid = [['wire A', 'app/a.php'], ['wire B', 'app/b.php']];
        $out = AtlasLoopOriginationPipeline::queueAwareDemote($valid, ['app/a.php' => true, 'app/b.php' => true]);
        // DEMOTE never EXCLUDE: the set stays non-empty (order preserved when all demoted) so the loop never
        // dead-stalls; isDone()/the dry-probe drive an all-queued scope to an honest stop downstream.
        self::assertSame($valid, $out);
    }
}
