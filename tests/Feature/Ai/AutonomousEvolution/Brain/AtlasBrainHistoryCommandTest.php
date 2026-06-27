<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * FROZEN proof of the brain history command — operator-friendly tail of the reflection stream.
 */
final class AtlasBrainHistoryCommandTest extends TestCase
{
    private string $stream;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.brain.scopes.loop', [
            'label' => 'test', 'roots' => [], 'docs_roots' => [], 'meta_harness' => true,
        ]);
        config()->set('atlas.brain.default_scope', 'loop');
        $this->stream = sys_get_temp_dir().'/atlas-brain-history-'.bin2hex(random_bytes(4)).'.ndjson';
        config()->set('atlas.brain.reflection_root', $this->stream);
    }

    public function test_history_tail_emits_compact_rows(): void
    {
        // Seed 3 reflections oldest-first.
        foreach ([['c1', 'note', 'rotate_path'], ['c2', 'blocked', ''], ['c3', 'note', 'use_drafted_candidate']] as [$cid, $kind, $hint]) {
            $row = ['schema' => 'x', 'scope' => 'loop', 'cycle_id' => $cid, 'result_kind' => $kind, 'reflection' => "head text for {$cid}", 'signals' => ['action_hint' => $hint], 'recorded_at' => 100];
            file_put_contents($this->stream, json_encode($row).PHP_EOL, FILE_APPEND);
        }

        $buf = new BufferedOutput;
        Artisan::call('atlas:brain:history', ['--tail' => 2], $buf);
        $payload = json_decode(trim($buf->fetch()), true);

        self::assertSame(2, $payload['count']);
        self::assertSame('c2', $payload['rows'][0]['cycle_id']);
        self::assertSame('c3', $payload['rows'][1]['cycle_id']);
        self::assertSame('use_drafted_candidate', $payload['rows'][1]['action_hint']);
    }

    public function test_history_kind_filter_narrows_to_one_kind(): void
    {
        foreach ([['c1', 'note', ''], ['c2', 'blocked', ''], ['c3', 'note', ''], ['c4', 'blocked', '']] as [$cid, $kind, $hint]) {
            $row = ['schema' => 'x', 'scope' => 'loop', 'cycle_id' => $cid, 'result_kind' => $kind, 'reflection' => 'r', 'signals' => ['action_hint' => $hint], 'recorded_at' => 0];
            file_put_contents($this->stream, json_encode($row).PHP_EOL, FILE_APPEND);
        }
        $buf = new BufferedOutput;
        Artisan::call('atlas:brain:history', ['--kind' => 'blocked'], $buf);
        $payload = json_decode(trim($buf->fetch()), true);
        self::assertSame(2, $payload['count']);
        foreach ($payload['rows'] as $row) {
            self::assertSame('blocked', $row['result_kind']);
        }
    }

    public function test_history_command_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit('app/Console/Commands/AtlasBrainHistoryCommand.php', true);
        self::assertSame('forbidden', $verdict);
    }
}
