<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHealthScoreLedger;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * FROZEN proof of the brain snapshot command — computes L90 score and appends to L99 ledger.
 */
final class AtlasBrainSnapshotCommandTest extends TestCase
{
    public function test_snapshot_appends_score_row_to_ledger(): void
    {
        $base = sys_get_temp_dir().'/atlas-brain-snapshot-'.bin2hex(random_bytes(6));
        @mkdir($base.'/done-set', 0o775, true);
        config()->set('atlas.brain.done_set_root', $base.'/done-set');
        config()->set('atlas.brain.health_score_root', $base.'/score-ledger');
        config()->set('atlas.brain.default_scope', 'loop');
        config()->set('atlas.brain.scopes.loop', ['label' => 'test', 'roots' => [], 'docs_roots' => [], 'meta_harness' => true]);
        config()->set('atlas.brain.reflection_root', $base.'/refl.ndjson');

        $buf = new BufferedOutput;
        Artisan::call('atlas:brain:snapshot', [], $buf);
        $payload = json_decode(trim($buf->fetch()), true);

        self::assertSame('loop', $payload['scope']);
        self::assertGreaterThanOrEqual(0, $payload['score']);
        self::assertTrue($payload['appended']);

        $tail = (new AtlasBrainHealthScoreLedger($base.'/score-ledger'))->tail('loop');
        self::assertCount(1, $tail);
        self::assertSame($payload['score'], $tail[0]['score']);
    }

    public function test_snapshot_command_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit('app/Console/Commands/AtlasBrainSnapshotCommand.php', true);
        self::assertSame('forbidden', $verdict);
    }
}
