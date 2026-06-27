<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainReflectionStream;
use Tests\TestCase;

/**
 * Keystone substrate — the scope-keyed semantic reflection stream. Proves the no-op-when-OFF flag stance, the
 * fail-closed record contract, the NO-SCALAR storage invariant, the deterministic recall ordering
 * (relevance > failure-importance > recency) the next comprehension reads, and the pétreo floor (the brain can
 * never edit its own memory).
 */
final class AtlasBrainReflectionStreamTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-brain-reflection-'.uniqid('', true).'.ndjson';
        config()->set('atlas.brain.reflection_enabled', true);
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
        parent::tearDown();
    }

    private function stream(): AtlasBrainReflectionStream
    {
        return new AtlasBrainReflectionStream($this->path);
    }

    public function test_flag_off_is_a_byte_identical_no_op(): void
    {
        config()->set('atlas.brain.reflection_enabled', false);
        $stream = $this->stream();

        self::assertNull($stream->record(['scope' => 'loop', 'reflection' => 'x']));
        self::assertFileDoesNotExist($this->path);
        self::assertSame([], $stream->entries());
    }

    public function test_records_a_reflection_when_enabled(): void
    {
        $row = $this->stream()->record([
            'scope' => 'loop',
            'reflection' => 'authoring an acceptance against a pétreo file is doomed',
            'cycle_id' => 'c1',
            'result_kind' => AtlasBrainReflectionStream::KIND_BLOCKED,
            'signals' => ['target_path' => 'X.php', 'gate' => 'harness_guard'],
        ], 100);

        self::assertIsArray($row);
        self::assertSame('loop', $row['scope']);
        self::assertSame(AtlasBrainReflectionStream::KIND_BLOCKED, $row['result_kind']);
        self::assertSame(['target_path:X.php', 'gate:harness_guard'], $row['signals']);
        self::assertCount(1, $this->stream()->entries());
    }

    public function test_fail_closed_on_empty_scope_or_text(): void
    {
        $stream = $this->stream();

        self::assertNull($stream->record(['scope' => '', 'reflection' => 'x']));
        self::assertNull($stream->record(['scope' => 'loop', 'reflection' => '   ']));
        self::assertSame([], $stream->entries());
    }

    public function test_storage_is_no_scalar(): void
    {
        $row = $this->stream()->record(['scope' => 'loop', 'reflection' => 'note', 'result_kind' => 'success'], 1);

        self::assertIsArray($row);
        foreach (['score', 'rank', 'leverage', 'quality', 'weight', 'importance'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $row, "reflection row must not store a learning scalar ({$forbidden})");
        }
    }

    public function test_for_scope_filters_by_scope(): void
    {
        $s = $this->stream();
        $s->record(['scope' => 'loop', 'reflection' => 'a'], 1);
        $s->record(['scope' => 'muscle', 'reflection' => 'b'], 2);
        $s->record(['scope' => 'loop', 'reflection' => 'c'], 3);

        self::assertCount(2, $s->forScope('loop'));
        self::assertCount(1, $s->forScope('muscle'));
    }

    public function test_recall_ranks_relevance_then_failure_then_recency(): void
    {
        $s = $this->stream();
        $s->record(['scope' => 'loop', 'reflection' => 'success-A', 'result_kind' => 'success', 'signals' => ['target_path' => 'A.php']], 1);
        $s->record(['scope' => 'loop', 'reflection' => 'blocked-B', 'result_kind' => 'blocked', 'signals' => ['target_path' => 'B.php']], 2);
        $s->record(['scope' => 'loop', 'reflection' => 'blocked-A', 'result_kind' => 'blocked', 'signals' => ['target_path' => 'A.php']], 3);

        $top = $s->recall('loop', ['signals' => ['target_path' => 'A.php']], 3);

        self::assertCount(3, $top);
        self::assertSame('blocked-A', $top[0]['reflection'], 'relevant + failure ranks first');
        self::assertSame('success-A', $top[1]['reflection'], 'relevance dominates importance');
        self::assertSame('blocked-B', $top[2]['reflection'], 'irrelevant ranks last despite being a failure');
    }

    public function test_recall_recency_breaks_ties(): void
    {
        $s = $this->stream();
        $s->record(['scope' => 'loop', 'reflection' => 'older', 'result_kind' => 'note', 'signals' => ['k' => 'v']], 1);
        $s->record(['scope' => 'loop', 'reflection' => 'newer', 'result_kind' => 'note', 'signals' => ['k' => 'v']], 2);

        $top = $s->recall('loop', ['signals' => ['k' => 'v']], 1);

        self::assertSame('newer', $top[0]['reflection']);
    }

    public function test_recall_k_zero_returns_empty(): void
    {
        $s = $this->stream();
        $s->record(['scope' => 'loop', 'reflection' => 'a'], 1);

        self::assertSame([], $s->recall('loop', [], 0));
    }

    public function test_reflection_stream_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainReflectionStream.php',
            true // even with meta_harness ON, the brain's own memory is pétreo
        );

        self::assertSame('forbidden', $verdict);
    }

    public function test_recall_texts_is_empty_when_flag_off(): void
    {
        $s = $this->stream();
        // record a row while ON, then flip OFF — recallTexts must yield [] so the producer stays byte-identical.
        $s->record(['scope' => 'loop', 'reflection' => 'x', 'signals' => ['k' => 'v']], 1);
        config()->set('atlas.brain.reflection_enabled', false);

        self::assertSame([], $s->recallTexts('loop', ['signals' => ['k' => 'v']]));
    }

    public function test_recall_texts_returns_compact_rows_when_on(): void
    {
        $s = $this->stream();
        $s->record(['scope' => 'loop', 'reflection' => 'blocked: petreo target', 'result_kind' => 'blocked', 'signals' => ['target_path' => 'A.php']], 1);

        $out = $s->recallTexts('loop', ['signals' => ['target_path' => 'A.php']]);

        self::assertCount(1, $out);
        self::assertSame(['kind' => 'blocked', 'reflection' => 'blocked: petreo target'], $out[0]);
    }
}
